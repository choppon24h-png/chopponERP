<?php
/**
 * API — Verificar Checkout
 * POST /api/verify_checkout.php
 *
 * v3.0.0 — Correção completa do polling de status
 *
 * MELHORIAS APLICADAS:
 *
 *   1. Consulta ao banco E à API SumUp em paralelo:
 *      - Se o banco já tiver status aprovado → retorna imediatamente (rápido)
 *      - Se o banco tiver PENDING → consulta a API SumUp para status atualizado
 *        e atualiza o banco se aprovado
 *      Isso resolve o caso em que o webhook ainda não chegou mas o pagamento
 *      já foi aprovado na SumUp.
 *
 *   2. Status aceitos como aprovados expandidos:
 *      PAID, SUCCESSFUL, APPROVED, COMPLETED
 *      (o webhook pode gravar qualquer um desses dependendo do tipo de transação)
 *
 *   3. Logs de diagnóstico detalhados para rastrear o fluxo de polling.
 *
 *   4. Suporte a polling direto na API SumUp para PIX:
 *      GET /v0.1/checkouts/{checkout_id} → campo status
 *
 * FLUXO DE POLLING DO APP ANDROID:
 *   App → POST verify_checkout.php (checkout_id, android_id)
 *     → Verifica banco local
 *     → Se PENDING: consulta SumUp API
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
        SELECT id, checkout_status, method
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

    Logger::info('verify_checkout - consultado', [
        'checkout_id'     => $checkout_id,
        'android_id'      => $android_id,
        'checkout_status' => $status_banco,
        'order_id'        => $order['id'],
        'payment_method'  => $order['method'] ?? 'unknown',
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

    // ── Verificação 2: Status PENDING — consultar API SumUp ──────────────────
    // Só consulta a API se o status local for PENDING (não FAILED/EXPIRED)
    if ($status_banco === 'PENDING') {
        Logger::info('verify_checkout - Status PENDING, consultando API SumUp', [
            'checkout_id' => $checkout_id,
        ]);

        $status_sumup = consultarStatusSumUp($checkout_id);

        Logger::info('verify_checkout - Status retornado pela SumUp', [
            'checkout_id'  => $checkout_id,
            'status_sumup' => $status_sumup,
        ]);

        if ($status_sumup && in_array(strtoupper($status_sumup), $status_aprovados)) {
            // Atualizar banco com o status aprovado
            $conn->prepare("
                UPDATE `order`
                SET checkout_status = ?
                WHERE checkout_id = ?
            ")->execute([strtoupper($status_sumup), $checkout_id]);

            Logger::info('verify_checkout - APROVADO (API SumUp) — banco atualizado', [
                'checkout_id'  => $checkout_id,
                'status_sumup' => $status_sumup,
            ]);

            http_response_code(200);
            ob_clean();
            echo json_encode(['status' => 'success', 'checkout_status' => strtoupper($status_sumup)], JSON_UNESCAPED_UNICODE);
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
// FUNÇÃO AUXILIAR: Consultar status do checkout diretamente na API SumUp
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Consulta o status de um checkout diretamente na API SumUp.
 * Endpoint: GET /v0.1/checkouts/{checkout_id}
 *
 * @param string $checkout_id
 * @return string|null  Status retornado pela SumUp (ex: "PAID", "PENDING") ou null em falha
 */
function consultarStatusSumUp($checkout_id) {
    $url = SUMUP_CHECKOUT_URL . $checkout_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SUMUP_TOKEN,
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
