<?php
/**
 * Webhook Mercado Pago — PIX de pedidos (create_order)
 *
 * Recebe notificações de pagamento do Mercado Pago e atualiza
 * o status do pedido na tabela `order`.
 *
 * External reference format: CO{order_id}  (ex: CO123)
 * O checkout_id na tabela `order` armazena o payment_id do Mercado Pago.
 */
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

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

    // Buscar o pedido pelo checkout_id (= payment_id armazenado em create_order)
    $stmt = $conn->prepare(
        "SELECT id, checkout_status FROM `order` WHERE checkout_id = ? LIMIT 1"
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

    // Consultar status real na API do Mercado Pago para não depender só do webhook
    $cfg_stmt = $conn->prepare(
        "SELECT access_token FROM mercadopago_config WHERE status = 1 LIMIT 1"
    );
    $cfg_stmt->execute();
    $cfg = $cfg_stmt->fetch(PDO::FETCH_ASSOC);

    $new_status = 'PENDING';

    if ($cfg) {
        $ch = curl_init("https://api.mercadopago.com/v1/payments/{$payment_id}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cfg['access_token'],
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
                'payment_id' => $payment_id,
                'mp_status'  => $payment['status'] ?? 'unknown',
                'new_status' => $new_status,
            ]);
        }
    } else {
        // Sem acesso à API, usar a ação do webhook como heurística
        $action = $data['action'] ?? '';
        if ($action === 'payment.updated') {
            $new_status = 'PAID';
        }
    }

    $conn->prepare("UPDATE `order` SET checkout_status = ? WHERE id = ?")
         ->execute([$new_status, $order['id']]);

    Logger::info('MercadoPago Webhook - pedido atualizado', [
        'order_id'   => $order['id'],
        'payment_id' => $payment_id,
        'new_status' => $new_status,
    ]);

} catch (\Exception $e) {
    Logger::error('MercadoPago Webhook - erro', ['error' => $e->getMessage()]);
}

http_response_code(200);
ob_clean();
echo json_encode(['status' => 'ok']);
ob_end_flush();
