<?php
/**
 * API - Finalizar Venda (Protocolo BLE Industrial v2.3)
 * POST /api/finish_sale.php
 *
 * Chamado APÓS receber DONE do ESP32.
 * Confirma a venda com o volume real dispensado.
 *
 * Campos POST obrigatórios:
 *   checkout_id   — ID do pedido
 *
 * Campos POST opcionais:
 *   command_id    — ID do comando BLE
 *   session_id    — SESSION_ID da venda
 *   ml_real       — Volume real dispensado (alias: ml_dispensado, qtd_ml)
 *   total_pulsos  — Total de pulsos QP: (auditoria)
 *   android_id    — ID do dispositivo Android
 *
 * Resposta de sucesso:
 *   { "success": true, "status": "FINISHED", "qtd_liberada": 300, "ml_real": 298 }
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
$checkout_id  = trim($_POST['checkout_id']  ?? '');
$command_id   = trim($_POST['command_id']   ?? '');
$session_id   = trim($_POST['session_id']   ?? '');
// Aceita ml_real, ml_dispensado ou qtd_ml
$ml_real      = intval($_POST['ml_real'] ?? $_POST['ml_dispensado'] ?? $_POST['qtd_ml'] ?? 0);
$total_pulsos = intval($_POST['total_pulsos'] ?? 0);
$android_id   = trim($_POST['android_id'] ?? '');

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Campo obrigatório: checkout_id']);
    exit;
}

$conn = getDBConnection();

// ── Buscar pedido ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM `order` WHERE checkout_id = ? LIMIT 1");
$stmt->execute([$checkout_id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'Pedido não encontrado']);
    exit;
}

// ── Atualizar ble_sales (se command_id disponível) ────────────────────────────
if (!empty($command_id)) {
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'DONE', ml_real = ?
            WHERE command_id = ? AND checkout_id = ?
        ");
        $stmt->execute([$ml_real, $command_id, $checkout_id]);
    } catch (\PDOException $e) {
        // Tabela pode não existir em ambiente legado — continuar
    }
} else {
    // Atualiza pela última venda STARTED deste checkout
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'DONE', ml_real = ?
            WHERE checkout_id = ? AND status = 'STARTED'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$ml_real, $checkout_id]);
    } catch (\PDOException $e) {
        // Ignorar
    }
}

// ── Calcular volume total liberado ────────────────────────────────────────────
$qtd_liberada    = $order['qtd_liberada'] + $ml_real;
$status_liberacao = ($qtd_liberada >= $order['quantidade']) ? 'FINISHED' : 'PROCESSING';

// ── Atualizar pedido ──────────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        UPDATE `order`
        SET qtd_liberada     = ?,
            status_liberacao = ?,
            total_pulsos     = ?
        WHERE id = ?
    ");
    $stmt->execute([$qtd_liberada, $status_liberacao, $total_pulsos, $order['id']]);
} catch (\PDOException $e) {
    // Coluna total_pulsos pode não existir — fallback sem ela
    $stmt = $conn->prepare("
        UPDATE `order`
        SET qtd_liberada     = ?,
            status_liberacao = ?
        WHERE id = ?
    ");
    $stmt->execute([$qtd_liberada, $status_liberacao, $order['id']]);
}

ob_clean();
echo json_encode([
    'success'      => true,
    'status'       => $status_liberacao,
    'qtd_liberada' => $qtd_liberada,
    'ml_real'      => $ml_real,
    'total_pulsos' => $total_pulsos
]);
