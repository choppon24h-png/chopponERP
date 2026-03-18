<?php
/**
 * API - Iniciar Venda (Protocolo BLE Industrial v2.3)
 * POST /api/start_sale.php
 *
 * Chamado ANTES do comando SERVE ser enviado ao ESP32.
 * Registra o início da dispensação com session_id e command_id únicos.
 *
 * Campos POST obrigatórios:
 *   checkout_id   — ID do pedido aprovado
 *
 * Campos POST opcionais (mas recomendados):
 *   command_id    — ID único do comando BLE (ex: "a1b2c3d4")
 *   session_id    — SESSION_ID da venda (ex: "SES_8472ABCD")
 *   qtd_ml        — Volume solicitado em ml (aliases: volume_ml)
 *   device_id     — android_id do tablet (alias: android_id)
 *
 * Resposta de sucesso:
 *   { "success": true, "session_id": "SES_8472ABCD", "command_id": "A1B2C3D4" }
 *
 * Resposta de erro:
 *   { "error": "mensagem" }
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
// Aceita qtd_ml ou volume_ml (Android envia volume_ml)
$qtd_ml      = intval($_POST['qtd_ml'] ?? $_POST['volume_ml'] ?? 0);
// Aceita device_id ou android_id
$device_id   = trim($_POST['device_id'] ?? $_POST['android_id'] ?? '');

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Campo obrigatório: checkout_id']);
    exit;
}

// Gera command_id e session_id se não fornecidos
if (empty($command_id)) {
    $command_id = strtoupper(substr(md5(uniqid($checkout_id, true)), 0, 8));
}
if (empty($session_id)) {
    $session_id = 'SES_' . strtoupper(substr(md5(uniqid($checkout_id . $command_id, true)), 0, 8));
}

$conn = getDBConnection();

// ── Verificar se o pedido existe e está pago ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, status_liberacao, quantidade, qtd_liberada
    FROM `order`
    WHERE checkout_id = ?
    LIMIT 1
");
$stmt->execute([$checkout_id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'Pedido não encontrado']);
    exit;
}

if (!in_array($order['status_liberacao'], ['PENDING', 'PROCESSING', 'PAID'])) {
    http_response_code(409);
    ob_clean();
    echo json_encode([
        'error'  => 'Pedido não está em estado válido para liberação',
        'status' => $order['status_liberacao']
    ]);
    exit;
}

// Usa quantidade do pedido se qtd_ml não foi fornecido
if ($qtd_ml <= 0) {
    $qtd_ml = intval($order['quantidade']);
}

// ── Criar/atualizar tabela ble_sales se não existir (auto-migration) ───────────
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `ble_sales` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`     BIGINT UNSIGNED NOT NULL,
            `checkout_id`  VARCHAR(255)    NOT NULL,
            `command_id`   VARCHAR(32)     NOT NULL,
            `session_id`   VARCHAR(32)     NOT NULL,
            `device_id`    VARCHAR(64)     NULL,
            `qtd_ml`       INT             NOT NULL DEFAULT 0,
            `ml_real`      INT             NOT NULL DEFAULT 0,
            `status`       ENUM('STARTED','DONE','FAILED') NOT NULL DEFAULT 'STARTED',
            `error_msg`    TEXT            NULL,
            `created_at`   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_command_id` (`command_id`),
            KEY `idx_session_id`  (`session_id`),
            KEY `idx_checkout_id` (`checkout_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\PDOException $e) {
    // Tabela já existe — ignorar
}

// ── Deduplicação: se command_id já existe, retornar sucesso (idempotente) ──────
$stmt = $conn->prepare("SELECT id, status FROM ble_sales WHERE command_id = ? LIMIT 1");
$stmt->execute([$command_id]);
$existing = $stmt->fetch();
if ($existing) {
    ob_clean();
    echo json_encode([
        'success'    => true,
        'session_id' => $session_id,
        'command_id' => $command_id,
        'duplicate'  => true,
        'status'     => $existing['status']
    ]);
    exit;
}

// ── Inserir registro de início de venda ───────────────────────────────────────
try {
    $stmt = $conn->prepare("
        INSERT INTO ble_sales (order_id, checkout_id, command_id, session_id, device_id, qtd_ml, status)
        VALUES (?, ?, ?, ?, ?, ?, 'STARTED')
    ");
    $stmt->execute([$order['id'], $checkout_id, $command_id, $session_id, $device_id, $qtd_ml]);
} catch (\PDOException $e) {
    // Fallback sem device_id se a coluna não existir
    $stmt = $conn->prepare("
        INSERT INTO ble_sales (order_id, checkout_id, command_id, session_id, qtd_ml, status)
        VALUES (?, ?, ?, ?, ?, 'STARTED')
    ");
    $stmt->execute([$order['id'], $checkout_id, $command_id, $session_id, $qtd_ml]);
}

// ── Atualizar status do pedido para PROCESSING ────────────────────────────────
$conn->prepare("
    UPDATE `order` SET status_liberacao = 'PROCESSING' WHERE checkout_id = ?
")->execute([$checkout_id]);

ob_clean();
echo json_encode([
    'success'    => true,
    'session_id' => $session_id,
    'command_id' => $command_id
]);
