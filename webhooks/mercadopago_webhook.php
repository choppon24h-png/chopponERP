<?php
/**
 * Webhook Mercado Pago — PIX de pedidos (create_order)
 * v2.0 — Multi-estabelecimento
 *
 * URL ÚNICA para todos os estabelecimentos:
 *   https://ochoppoficial.com.br/webhooks/mercadopago_webhook.php
 *
 * O webhook identifica o estabelecimento pelo pedido (order.estabelecimento_id)
 * e busca o token correto na tabela payment_config (ou mercadopago_config legada).
 *
 * External reference format: CO{order_id}  (ex: CO123)
 * O checkout_id na tabela `order` armazena o payment_id do Mercado Pago.
 */
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
if (!class_exists('PaymentConfigManager')) {
    require_once __DIR__ . '/../includes/PaymentConfigManager.php';
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

Logger::info('MercadoPago Webhook recebido', [
    'input' => substr($input ?? '', 0, 500),
]);

// Ignorar notificações que não sejam de pagamento
if (!isset($data['type']) || $data['type'] !== 'payment') {
    http_response_code(200);
    ob_clean();
    echo json_encode(['status' => 'ignored']);
    ob_end_flush();
    exit;
}

$payment_id = $data['data']['id'] ?? null;

if (!$payment_id) {
    http_response_code(200);
    ob_clean();
    echo json_encode(['status' => 'no_payment_id']);
    ob_end_flush();
    exit;
}

try {
    $conn = getDBConnection();

    // ── 1. Buscar o pedido pelo checkout_id (= payment_id do Mercado Pago) ────
    $stmt = $conn->prepare(
        "SELECT id, checkout_status, estabelecimento_id
         FROM `order`
         WHERE checkout_id = ?
         LIMIT 1"
    );
    $stmt->execute([(string) $payment_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        Logger::info('MercadoPago Webhook - pedido nao encontrado', [
            'payment_id' => $payment_id,
        ]);
        http_response_code(200);
        ob_clean();
        echo json_encode(['status' => 'order_not_found']);
        ob_end_flush();
        exit;
    }

    $estabelecimento_id = (int) ($order['estabelecimento_id'] ?? 0);

    // ── 2. Buscar access_token do estabelecimento correto ─────────────────────
    //    Prioridade: payment_config → mercadopago_config → qualquer ativo
    $access_token = null;

    // 2a. Tentar PaymentConfigManager (payment_config multi-estabelecimento)
    if ($estabelecimento_id) {
        $cfg_mgr = PaymentConfigManager::getConfig($estabelecimento_id);
        if (!empty($cfg_mgr['mp_access_token'])) {
            $access_token = $cfg_mgr['mp_access_token'];
            Logger::info('MercadoPago Webhook - token via PaymentConfigManager', [
                'estabelecimento_id' => $estabelecimento_id,
            ]);
        }
    }

    // 2b. Fallback: tabela mercadopago_config pelo estabelecimento do pedido
    if (!$access_token && $estabelecimento_id) {
        try {
            $cfg_stmt = $conn->prepare(
                "SELECT access_token FROM mercadopago_config
                 WHERE estabelecimento_id = ? AND status = 1
                 LIMIT 1"
            );
            $cfg_stmt->execute([$estabelecimento_id]);
            $cfg = $cfg_stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg && !empty($cfg['access_token'])) {
                $access_token = $cfg['access_token'];
                Logger::info('MercadoPago Webhook - token via mercadopago_config (estab)', [
                    'estabelecimento_id' => $estabelecimento_id,
                ]);
            }
        } catch (\Exception $e) { /* tabela pode não existir */ }
    }

    // 2c. Fallback final: qualquer config ativa (compatibilidade com instalações
    //     que ainda têm apenas um estabelecimento)
    if (!$access_token) {
        try {
            $cfg_stmt = $conn->query(
                "SELECT access_token FROM mercadopago_config WHERE status = 1 LIMIT 1"
            );
            $cfg = $cfg_stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg && !empty($cfg['access_token'])) {
                $access_token = $cfg['access_token'];
                Logger::warning('MercadoPago Webhook - token via fallback generico (sem estab)', [
                    'payment_id'         => $payment_id,
                    'estabelecimento_id' => $estabelecimento_id,
                ]);
            }
        } catch (\Exception $e) { /* ignora */ }
    }

    // ── 3. Consultar status real na API do Mercado Pago ───────────────────────
    $new_status = 'PENDING';

    if ($access_token) {
        $ch = curl_init("https://api.mercadopago.com/v1/payments/{$payment_id}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
        ]);
        $mp_response = curl_exec($ch);
        $mp_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($mp_code === 200) {
            $payment = json_decode($mp_response, true);
            $mp_status_map = [
                'approved'    => 'PAID',
                'pending'     => 'PENDING',
                'in_process'  => 'PENDING',
                'rejected'    => 'FAILED',
                'cancelled'   => 'FAILED',
                'refunded'    => 'FAILED',
                'charged_back'=> 'FAILED',
            ];
            $new_status = $mp_status_map[$payment['status'] ?? ''] ?? 'PENDING';

            Logger::info('MercadoPago Webhook - status consultado na API', [
                'payment_id'         => $payment_id,
                'estabelecimento_id' => $estabelecimento_id,
                'mp_status'          => $payment['status'] ?? 'unknown',
                'new_status'         => $new_status,
            ]);
        } else {
            Logger::warning('MercadoPago Webhook - API retornou HTTP ' . $mp_code, [
                'payment_id'         => $payment_id,
                'estabelecimento_id' => $estabelecimento_id,
            ]);
        }
    } else {
        // Sem token: usar a ação do webhook como heurística
        $action = $data['action'] ?? '';
        if ($action === 'payment.updated') {
            $new_status = 'PAID';
        }
        Logger::warning('MercadoPago Webhook - sem access_token, usando heuristica', [
            'payment_id'         => $payment_id,
            'estabelecimento_id' => $estabelecimento_id,
            'action'             => $action,
            'new_status'         => $new_status,
        ]);
    }

    // ── 4. Atualizar status do pedido ─────────────────────────────────────────
    $conn->prepare("UPDATE `order` SET checkout_status = ? WHERE id = ?")
         ->execute([$new_status, $order['id']]);

    Logger::info('MercadoPago Webhook - pedido atualizado', [
        'order_id'           => $order['id'],
        'estabelecimento_id' => $estabelecimento_id,
        'payment_id'         => $payment_id,
        'new_status'         => $new_status,
    ]);

} catch (\Exception $e) {
    Logger::error('MercadoPago Webhook - erro', ['error' => $e->getMessage()]);
}

http_response_code(200);
ob_clean();
echo json_encode(['status' => 'ok']);
ob_end_flush();
