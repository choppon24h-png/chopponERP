<?php
/**
 * API - Falha na Venda (Protocolo BLE Industrial v2.3)
 * POST /api/fail_sale.php
 *
 * Chamado quando ocorre erro irrecuperável:
 *   - Timeout sem DONE em 15s
 *   - ERROR:WATCHDOG do ESP32
 *   - Máximo de retries atingido (QUEUE:ERROR)
 *
 * Campos POST obrigatórios:
 *   checkout_id   — ID do pedido
 *
 * Campos POST opcionais:
 *   command_id    — ID do comando BLE
 *   session_id    — SESSION_ID da venda
 *   error_msg     — Descrição do erro (alias: motivo)
 *   ml_parcial    — Volume parcialmente liberado (alias: ml_liberado)
 *   android_id    — ID do dispositivo Android
 *
 * Resposta:
 *   { "success": true, "status": "FAILED" }
 */
ob_start();
header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$headers = getallheaders();
$token   = $headers['token'] ?? $headers['Token'] ?? '';
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

// ── Validação de campos (com aliases para compatibilidade Android) ─────────────
$checkout_id = trim($_POST['checkout_id'] ?? '');
$command_id  = trim($_POST['command_id']  ?? '');
$session_id  = trim($_POST['session_id']  ?? '');
// Aceita error_msg ou motivo
$error_msg   = trim($_POST['error_msg'] ?? $_POST['motivo'] ?? 'Erro desconhecido');
// Aceita ml_parcial ou ml_liberado
$ml_parcial  = intval($_POST['ml_parcial'] ?? $_POST['ml_liberado'] ?? 0);
$android_id  = trim($_POST['android_id'] ?? '');

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Campo obrigatório: checkout_id']);
    exit;
}

$conn = getDBConnection();

// ── Atualizar ble_sales ───────────────────────────────────────────────────────
if (!empty($command_id)) {
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'FAILED', error_msg = ?, ml_real = ?
            WHERE command_id = ? AND checkout_id = ?
        ");
        $stmt->execute([$error_msg, $ml_parcial, $command_id, $checkout_id]);
    } catch (\PDOException $e) {
        // Tabela pode não existir em ambiente legado — continuar
    }
} else {
    // Atualiza pela última venda STARTED deste checkout
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'FAILED', error_msg = ?, ml_real = ?
            WHERE checkout_id = ? AND status = 'STARTED'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$error_msg, $ml_parcial, $checkout_id]);
    } catch (\PDOException $e) {
        // Ignorar
    }
}

// ── Atualizar pedido ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, qtd_liberada, quantidade FROM `order` WHERE checkout_id = ? LIMIT 1");
$stmt->execute([$checkout_id]);
$order = $stmt->fetch();

if ($order) {
    if ($ml_parcial > 0) {
        $qtd_liberada = $order['qtd_liberada'] + $ml_parcial;
        $status       = ($qtd_liberada >= $order['quantidade']) ? 'FINISHED' : 'FAILED';
        $conn->prepare("
            UPDATE `order` SET qtd_liberada = ?, status_liberacao = ? WHERE id = ?
        ")->execute([$qtd_liberada, $status, $order['id']]);
    } else {
        $conn->prepare("
            UPDATE `order` SET status_liberacao = 'FAILED' WHERE id = ?
        ")->execute([$order['id']]);
    }
}

ob_clean();
echo json_encode([
    'success'    => true,
    'status'     => 'FAILED',
    'error_msg'  => $error_msg,
    'ml_parcial' => $ml_parcial
]);
