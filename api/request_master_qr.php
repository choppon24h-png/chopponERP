<?php
/**
 * request_master_qr.php — Endpoint para o Android gerenciar tokens QR Master
 *
 * NOVA LÓGICA (lógica invertida):
 *   - Android GERA o QR Code e exibe na tela
 *   - Admin no ERP escaneia o QR Code do tablet e aprova
 *   - Android faz polling para saber se foi aprovado
 *
 * Ações:
 *   action=generate  → Android solicita um novo token e recebe o QR data
 *   action=poll      → Android verifica se o token foi aprovado
 *
 * Autenticação: JWT no header 'token' (padrão ApiHelper.java)
 */

require_once '../includes/config.php';
require_once '../includes/jwt.php';

header('Content-Type: application/json; charset=utf-8');

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$jwt_token = $_SERVER['HTTP_TOKEN'] ?? '';
if (empty($jwt_token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT ausente.']);
    exit;
}

$payload = jwtValidate($jwt_token);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.']);
    exit;
}

// ── Garantir tabela master_qr_tokens com schema completo ─────────────────────
$conn = getDBConnection();
$conn->exec("
    CREATE TABLE IF NOT EXISTS master_qr_tokens (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        token            VARCHAR(64) NOT NULL UNIQUE,
        device_id        VARCHAR(128) NOT NULL,
        status           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at       DATETIME NOT NULL,
        approved_by      INT NULL,
        approved_user_id INT NULL,
        approved_name    VARCHAR(255) NULL,
        approved_type    TINYINT NULL,
        used_at          DATETIME NULL,
        INDEX idx_token     (token),
        INDEX idx_device    (device_id),
        INDEX idx_status    (status),
        INDEX idx_expires   (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Adicionar colunas novas caso a tabela já exista com schema antigo
$alterations = [
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending' AFTER device_id",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER expires_at",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_user_id INT NULL AFTER approved_by",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_name VARCHAR(255) NULL AFTER approved_user_id",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_type TINYINT NULL AFTER approved_name",
];
foreach ($alterations as $sql) {
    try { $conn->exec($sql); } catch (Exception $e) { /* coluna já existe */ }
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$action    = trim($_POST['action']    ?? $_GET['action']    ?? '');
$device_id = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');

if (empty($device_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'device_id obrigatório.']);
    exit;
}

// ── Rate limiting: máx 5 tokens gerados por device por minuto ────────────────
if ($action === 'generate') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM master_qr_tokens
        WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$device_id]);
    if ((int)$stmt->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 minuto.']);
        exit;
    }
}

// ── ACTION: generate ──────────────────────────────────────────────────────────
if ($action === 'generate') {
    // Expirar tokens antigos deste device
    $conn->prepare("
        UPDATE master_qr_tokens
        SET status = 'expired'
        WHERE device_id = ? AND status = 'pending' AND expires_at <= NOW()
    ")->execute([$device_id]);

    // Gerar token de 64 chars hex (256 bits de entropia)
    $raw_token = bin2hex(random_bytes(32));
    $qr_data   = 'CHOPPON_MASTER:' . $raw_token;
    $expires   = date('Y-m-d H:i:s', time() + 300); // 5 minutos

    $stmt = $conn->prepare("
        INSERT INTO master_qr_tokens (token, device_id, status, expires_at)
        VALUES (?, ?, 'pending', ?)
    ");
    $stmt->execute([$raw_token, $device_id, $expires]);
    $token_id = (int)$conn->lastInsertId();

    echo json_encode([
        'success'    => true,
        'token_id'   => $token_id,
        'qr_data'    => $qr_data,
        'expires_at' => $expires,
    ]);
    exit;
}

// ── ACTION: poll ──────────────────────────────────────────────────────────────
if ($action === 'poll') {
    $token_id = (int)($_POST['token_id'] ?? $_GET['token_id'] ?? 0);
    if ($token_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'token_id inválido.']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT status, expires_at, approved_name, approved_type
        FROM master_qr_tokens
        WHERE id = ? AND device_id = ?
        LIMIT 1
    ");
    $stmt->execute([$token_id, $device_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'status' => 'not_found']);
        exit;
    }

    // Verificar expiração
    if ($row['status'] === 'pending' && strtotime($row['expires_at']) < time()) {
        $conn->prepare("UPDATE master_qr_tokens SET status = 'expired' WHERE id = ?")
             ->execute([$token_id]);
        echo json_encode(['success' => true, 'status' => 'expired']);
        exit;
    }

    $response = ['success' => true, 'status' => $row['status']];

    if ($row['status'] === 'approved') {
        $response['user_name'] = $row['approved_name'] ?? 'Técnico';
        $response['user_type'] = (int)($row['approved_type'] ?? 3);
        // Marcar como usado para evitar reuso
        $conn->prepare("UPDATE master_qr_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL")
             ->execute([$token_id]);
    }

    echo json_encode($response);
    exit;
}

// ── Ação desconhecida ─────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['success' => false, 'message' => "Ação '$action' desconhecida. Use 'generate' ou 'poll'."]);
