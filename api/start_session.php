<?php
/**
 * API - Iniciar Sessão de Dispensação
 * POST /api/start_session.php
 *
 * Inicia uma sessão de dispensação de chopp após a aprovação do pagamento.
 * Atualiza o status do pedido para PROCESSING, indicando que a liberação foi iniciada.
 *
 * Campos POST obrigatórios:
 *   checkout_id   — ID do pedido aprovado
 *
 * Campos POST opcionais:
 *   android_id    — ID do dispositivo Android
 *   volume        — Volume solicitado em ml (aliases: qtd_ml, volume_ml)
 *
 * Resposta de sucesso:
 *   { "success": true }
 *
 * Resposta de erro:
 *   { "error": "mensagem descritiva" }
 */

// ── Buffer de saída: captura TUDO desde o início ──────────────────────────
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
            'error' => 'Erro interno: ' . $error['message'],
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
    }
});

require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/logger.php';

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

// ── Leitura e validação de campos ─────────────────────────────────────────────
$input       = $_POST;
if (empty($input)) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$checkout_id = trim($input['checkout_id'] ?? '');
$android_id  = trim($input['android_id']  ?? '');
// Aceita volume, qtd_ml ou volume_ml como aliases
$volume      = intval($input['volume'] ?? $input['qtd_ml'] ?? $input['volume_ml'] ?? 0);

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
        'error' => 'Campo obrigatório: checkout_id',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

try {
    $conn = getDBConnection();

    // ── Buscar pedido pelo checkout_id ────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT id, status_liberacao, quantidade, checkout_status
        FROM `order`
        WHERE checkout_id = ?
        LIMIT 1
    ");
    $stmt->execute([$checkout_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        Logger::warning('start_session - checkout_id não encontrado', [
            'checkout_id' => $checkout_id,
            'android_id'  => $android_id,
        ]);
        http_response_code(404);
        ob_clean();
        echo json_encode([
            'error' => 'Pedido não encontrado',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    Logger::info('start_session - iniciando sessão', [
        'checkout_id'     => $checkout_id,
        'android_id'      => $android_id,
        'order_id'        => $order['id'],
        'status_liberacao' => $order['status_liberacao'],
        'checkout_status' => $order['checkout_status'],
    ]);

    // ── Atualizar status do pedido para PROCESSING ────────────────────────────
    $stmt = $conn->prepare("
        UPDATE `order`
        SET status_liberacao = 'PROCESSING'
        WHERE checkout_id = ?
    ");
    $stmt->execute([$checkout_id]);

    Logger::info('start_session - status_liberacao atualizado para PROCESSING', [
        'checkout_id' => $checkout_id,
        'order_id'    => $order['id'],
    ]);

    http_response_code(200);
    ob_clean();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    ob_end_flush();

} catch (Throwable $e) {
    Logger::error('start_session - Exceção não tratada', [
        'message'     => $e->getMessage(),
        'checkout_id' => $checkout_id,
        'file'        => basename($e->getFile()),
        'line'        => $e->getLine(),
    ]);

    http_response_code(500);
    ob_clean();
    echo json_encode([
        'error' => 'Erro ao processar sessão: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
