<?php
/**
 * API - Verificar Checkout
 * POST /api/verify_checkout.php
 *
 * Verifica se o pagamento foi aprovado.
 * CORRECAO: aceita todos os status de sucesso: PAID, SUCCESSFUL, APPROVED
 * (o webhook pode gravar qualquer um desses dependendo do tipo de transacao)
 */
header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/logger.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

if (!jwtValidate($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$input       = $_POST;
$android_id  = $input['android_id']  ?? '';
$checkout_id = $input['checkout_id'] ?? '';

if (empty($android_id) || empty($checkout_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'android_id e checkout_id são obrigatórios']);
    exit;
}

$conn = getDBConnection();

// Buscar o pedido pelo checkout_id (independente do status atual)
$stmt = $conn->prepare("
    SELECT id, checkout_status, payment_method
    FROM `order`
    WHERE checkout_id = ?
    LIMIT 1
");
$stmt->execute([$checkout_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    Logger::warning("verify_checkout - checkout_id nao encontrado no banco", [
        'checkout_id' => $checkout_id,
        'android_id'  => $android_id
    ]);
    http_response_code(200);
    echo json_encode(['status' => 'false', 'debug' => 'checkout_id not in database']);
    exit;
}

$status = strtoupper($order['checkout_status'] ?? '');

Logger::info("verify_checkout - consultado", [
    'checkout_id'     => $checkout_id,
    'android_id'      => $android_id,
    'checkout_status' => $status,
    'order_id'        => $order['id'],
    'payment_method'  => $order['payment_method'] ?? 'unknown'
]);

// CORRECAO: Status que indicam pagamento aprovado
// O webhook da SumUp pode gravar qualquer um desses valores:
// - PIX: geralmente PAID
// - Cartao fisico (reader): geralmente SUCCESSFUL ou APPROVED
$status_aprovados = ['PAID', 'SUCCESSFUL', 'APPROVED', 'COMPLETED'];

if (in_array($status, $status_aprovados)) {
    Logger::info("verify_checkout - APROVADO", [
        'checkout_id' => $checkout_id,
        'status'      => $status
    ]);
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    // Ainda pendente ou falhou
    http_response_code(200);
    echo json_encode([
        'status'          => 'false',
        'checkout_status' => $status
    ]);
}
