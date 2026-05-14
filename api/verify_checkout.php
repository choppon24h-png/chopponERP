<?php
/**
 * API — Verificar Checkout
 * POST /api/verify_checkout.php
 *
 * v3.1.0 — Suporte a polling direto na API Mercado Pago para PIX
 *
 * MELHORIAS APLICADAS:
 *
 *   1. Consulta ao banco E à API SumUp/Mercado Pago em paralelo:
 *      - Se o banco já tiver status aprovado → retorna imediatamente (rápido)
 *      - Se o banco tiver PENDING → consulta a API correspondente (SumUp ou MP)
 *        para status atualizado e atualiza o banco se aprovado
 *      Isso resolve o caso em que o webhook ainda não chegou mas o pagamento
 *      já foi aprovado na API.
 *
 *   2. Status aceitos como aprovados expandidos:
 *      PAID, SUCCESSFUL, APPROVED, COMPLETED
 *      (o webhook pode gravar qualquer um desses dependendo do tipo de transação)
 *
 *   3. Logs de diagnóstico detalhados para rastrear o fluxo de polling.
 *
 *   4. Suporte a polling direto na API SumUp para cartão e Mercado Pago para PIX.
 *
 * FLUXO DE POLLING DO APP ANDROID:
 *   App → POST verify_checkout.php (checkout_id, android_id)
 *     → Verifica banco local
 *     → Se PENDING: consulta SumUp API (cartão) ou Mercado Pago API (PIX)
 *     → Atualiza banco se aprovado
 *   App ← { status: "success" }  ou  { status: "false", checkout_status: "PENDING" }
 */

// Buffer de saída: captura TUDO desde o início
ob_start();

header('Content-Type: application/json');

// Proteção global: garante JSON válido mesmo em erro fatal
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => 'Erro interno: ' . $error['message'],
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
    }
});

require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/logger.php';
require_once '../includes/sumup.php';
require_once '../includes/PaymentConfigManager.php';

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$headers = getallheaders();
$token   = $headers['token'] ?? $headers['Token'] ?? '';

if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Leitura do input ──────────────────────────────────────────────────────────
$input = $_POST;
if (empty($input)) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$android_id  = $input['android_id']  ?? '';
$checkout_id = $input['checkout_id'] ?? '';

if (empty($android_id) || empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
        'error' => 'android_id e checkout_id são obrigatórios',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Status que indicam pagamento aprovado
// O webhook da SumUp pode gravar qualquer um desses valores:
//   - PIX:    geralmente PAID
//   - Cartão: geralmente SUCCESSFUL ou APPROVED
$status_aprovados = ['PAID', 'SUCCESSFUL', 'APPROVED', 'COMPLETED'];

try {
    $conn = getDBConnection();

    // ── Buscar pedido pelo checkout_id ────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT id, checkout_status, method, estabelecimento_id
        FROM `order`
        WHERE checkout_id = ?
        LIMIT 1
    ");
    $stmt->execute([$checkout_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        Logger::warning('verify_checkout - checkout_id não encontrado no banco', [
            'checkout_id' => $checkout_id,
            'android_id'  => $android_id,
        ]);
        http_response_code(200);
        ob_clean();
        echo json_encode([
            'status' => 'false',
            'debug'  => 'checkout_id not in database',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    $status_banco = strtoupper($order['checkout_status'] ?? '');
    $method = strtolower($order['method'] ?? '');
    $estabelecimento_id = $order['estabelecimento_id'];

    Logger::info('verify_checkout - consultado', [
        'checkout_id'     => $checkout_id,
        'android_id'      => $android_id,
        'checkout_status' => $status_banco,
        'order_id'        => $order['id'],
        'payment_method'  => $method,
    ]);

    // ── Verificação 1: Status já aprovado no banco ────────────────────────────
    if (in_array($status_banco, $status_aprovados)) {
        Logger::info('verify_checkout - APROVADO (banco local)', [
            'checkout_id' => $checkout_id,
            'status'      => $status_banco,
        ]);
        http_response_code(200);
        ob_clean();
        echo json_encode(['status' => 'success', 'checkout_status' => $status_banco], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // ── Verificação 2: Status PENDING — consultar API correspondente ──────────
    // Só consulta a API se o status local for PENDING (não FAILED/EXPIRED)
    if ($status_banco === 'PENDING') {
        Logger::info('verify_checkout - Status PENDING, consultando API', [
            'checkout_id' => $checkout_id,
            'method'      => $method,
        ]);

        $status_api = null;

        if ($method === 'pix') {
            // Consultar Mercado Pago
            $status_api = consultarStatusMercadoPago($conn, $checkout_id, $estabelecimento_id);
        } else {
            // Consultar SumUp (credit/debit)
            $status_api = consultarStatusSumUp($checkout_id, $estabelecimento_id);
        }

        Logger::info('verify_checkout - Status retornado pela API', [
            'checkout_id' => $checkout_id,
            'status_api'  => $status_api,
            'method'      => $method,
        ]);

        if ($status_api && in_array(strtoupper($status_api), $status_aprovados)) {
            // Atualizar banco com o status aprovado
            $conn->prepare("
                UPDATE `order`
                SET checkout_status = ?
                WHERE checkout_id = ?
            ")->execute([strtoupper($status_api), $checkout_id]);

            Logger::info('verify_checkout - APROVADO (API) — banco atualizado', [
                'checkout_id' => $checkout_id,
                'status_api'  => $status_api,
            ]);

            http_response_code(200);
            ob_clean();
            echo json_encode(['status' => 'success', 'checkout_status' => strtoupper($status_api)], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }
    }

    // ── Ainda pendente ou falhou ──────────────────────────────────────────────
    http_response_code(200);
    ob_clean();
    echo json_encode([
        'status'          => 'false',
        'checkout_status' => $status_banco,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    Logger::error('verify_checkout - Exceção não tratada', [
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);

    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error'      => 'Erro interno: ' . $e->getMessage(),
        'error_type' => 'EXCEPTION',
    ], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();

// ─────────────────────────────────────────────────────────────────────────────
// FUNÇÕES AUXILIARES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Consulta o status de um checkout diretamente na API SumUp.
 * Endpoint: GET /v0.1/checkouts/{checkout_id}
 *
 * @param string $checkout_id
 * @return string|null  Status retornado pela SumUp (ex: "PAID", "PENDING") ou null em falha
 */
function consultarStatusSumUp($checkout_id, $estabelecimento_id = null) {
    // Buscar token do estabelecimento via PaymentConfigManager
    $sumup_token = SUMUP_TOKEN;
    if ($estabelecimento_id && class_exists('PaymentConfigManager')) {
        $cfg = PaymentConfigManager::getConfig((int)$estabelecimento_id);
        if (!empty($cfg['sumup_token'])) {
            $sumup_token = $cfg['sumup_token'];
        }
    }

    $url = SUMUP_CHECKOUT_URL . $checkout_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $sumup_token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        Logger::warning('verify_checkout consultarStatusSumUp - cURL error', [
            'error'       => $curl_error,
            'checkout_id' => $checkout_id,
        ]);
        return null;
    }

    if ($http_code !== 200) {
        Logger::warning('verify_checkout consultarStatusSumUp - HTTP não-200', [
            'http_code'   => $http_code,
            'checkout_id' => $checkout_id,
        ]);
        return null;
    }

    $data = json_decode($response);

    if (!$data || !isset($data->status)) {
        Logger::warning('verify_checkout consultarStatusSumUp - resposta sem campo status', [
            'checkout_id'  => $checkout_id,
            'raw_response' => substr($response, 0, 200),
        ]);
        return null;
    }

    return $data->status;
}

/**
 * Consulta o status de um pagamento diretamente na API Mercado Pago.
 * Endpoint: GET /v1/payments/{payment_id}
 *
 * @param PDO $conn
 * @param string $payment_id (checkout_id)
 * @param int $estabelecimento_id
 * @return string|null  Status mapeado (ex: "PAID", "PENDING") ou null em falha
 */
function consultarStatusMercadoPago($conn, $payment_id, $estabelecimento_id) {
    // Buscar token do Mercado Pago via PaymentConfigManager (payment_config)
    $mp_token = null;
    if (class_exists('PaymentConfigManager')) {
        $cfg = PaymentConfigManager::getConfig((int)$estabelecimento_id);
        if (!empty($cfg['mp_access_token'])) {
            $mp_token = $cfg['mp_access_token'];
        }
    }
    // Fallback: buscar na tabela mercadopago_config legada
    if (!$mp_token) {
        $stmt = $conn->prepare("
            SELECT access_token
            FROM mercadopago_config
            WHERE estabelecimento_id = ? AND status = 1
            LIMIT 1
        ");
        $stmt->execute([$estabelecimento_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        $mp_token = $config['access_token'] ?? null;
    }
    if (!$mp_token) {
        Logger::warning('verify_checkout consultarStatusMercadoPago - config não encontrada', [
            'estabelecimento_id' => $estabelecimento_id,
            'payment_id'         => $payment_id,
        ]);
        return null;
    }

    $url = "https://api.mercadopago.com/v1/payments/" . $payment_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mp_token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        Logger::warning('verify_checkout consultarStatusMercadoPago - cURL error', [
            'error'      => $curl_error,
            'payment_id' => $payment_id,
        ]);
        return null;
    }

    if ($http_code !== 200) {
        Logger::warning('verify_checkout consultarStatusMercadoPago - HTTP não-200', [
            'http_code'  => $http_code,
            'payment_id' => $payment_id,
        ]);
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['status'])) {
        Logger::warning('verify_checkout consultarStatusMercadoPago - resposta sem campo status', [
            'payment_id'   => $payment_id,
            'raw_response' => substr($response, 0, 200),
        ]);
        return null;
    }

    // Mapear status do Mercado Pago para o padrão do sistema
    $mp_status_map = [
        'approved'    => 'PAID',
        'pending'     => 'PENDING',
        'in_process'  => 'PENDING',
        'authorized'  => 'PENDING',
        'rejected'    => 'FAILED',
        'cancelled'   => 'FAILED',
        'refunded'    => 'FAILED',
        'charged_back'=> 'FAILED',
    ];

    $mapped_status = $mp_status_map[$data['status']] ?? 'PENDING';

    Logger::info('verify_checkout consultarStatusMercadoPago - status mapeado', [
        'payment_id'    => $payment_id,
        'mp_status'     => $data['status'],
        'mapped_status' => $mapped_status,
    ]);

    return $mapped_status;
}
