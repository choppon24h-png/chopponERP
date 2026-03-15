<?php
/**
 * API - Validar QR Code Master (chamado pelo Android)
 * POST /api/validate_master_qr.php
 *
 * Headers:
 *   Authorization: Bearer <jwt_token>
 *   Content-Type: application/json
 *
 * Body JSON:
 *   { "qr_token": "<64-char-hex>", "device_id": "<android_device_id>" }
 *
 * Retorna JSON:
 *   { success, user_id, user_name, user_type, message }
 *
 * Segurança:
 *   - JWT obrigatório (mesmo padrão do resto da API)
 *   - Token QR: 64 chars hex, não revogado, não expirado
 *   - Marca used_at e device_id na primeira validação
 *   - Após uso, o token permanece válido até expirar (permite reconexão do mesmo device)
 *   - Rate limiting: máximo 10 tentativas por IP por minuto
 */

require_once '../includes/config.php';
require_once '../includes/jwt.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

// ── Rate limiting simples por IP ─────────────────────────────────────────────
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl_key   = sys_get_temp_dir() . '/qr_rl_' . md5($ip) . '.json';
$rl_now   = time();
$rl_data  = [];

if (file_exists($rl_key)) {
    $rl_data = json_decode(file_get_contents($rl_key), true) ?? [];
}

// Manter apenas tentativas do último minuto
$rl_data = array_filter($rl_data, fn($t) => ($rl_now - $t) < 60);

if (count($rl_data) >= 10) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 minuto.']);
    exit;
}

$rl_data[] = $rl_now;
file_put_contents($rl_key, json_encode(array_values($rl_data)));

// ── Validar JWT ──────────────────────────────────────────────────────────────
// Android envia JWT no header 'token' (padrão ApiHelper.java)
$jwt_raw = $_SERVER['HTTP_TOKEN']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? '';

// Suportar tanto 'token: <jwt>' quanto 'Authorization: Bearer <jwt>'
$jwt_raw = preg_replace('/^Bearer\s+/i', '', $jwt_raw);

if (empty($jwt_raw)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT ausente.']);
    exit;
}

$jwt_payload = jwtValidate($jwt_raw);
if (!$jwt_payload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.']);
    exit;
}

// ── Ler parâmetros (suporta JSON e form-data) ────────────────────────────────────────
// ApiHelper.java usa FormBody (application/x-www-form-urlencoded)
// Mas também aceita JSON para flexibilidade
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'application/json') !== false) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $qr_token  = trim($body['qr_token']  ?? '');
    $device_id = trim($body['device_id'] ?? '');
} else {
    $qr_token  = trim($_POST['qr_token']  ?? '');
    $device_id = trim($_POST['device_id'] ?? '');
}

// Validar formato do token (64 chars hex)
if (!preg_match('/^[0-9a-f]{64}$/', $qr_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Formato de token inválido.']);
    exit;
}

// ── Consultar banco ──────────────────────────────────────────────────────────
$conn = getDBConnection();

// Garantir que a tabela existe (idempotente)
$conn->exec("
    CREATE TABLE IF NOT EXISTS master_qr_tokens (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        token         VARCHAR(64) NOT NULL UNIQUE,
        created_by    INT NOT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at    DATETIME NOT NULL,
        used_at       DATETIME NULL,
        revoked       TINYINT(1) NOT NULL DEFAULT 0,
        device_id     VARCHAR(64) NULL,
        INDEX idx_token   (token),
        INDEX idx_user_id (user_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$stmt = $conn->prepare("
    SELECT mqt.id, mqt.user_id, mqt.expires_at, mqt.revoked, mqt.used_at, mqt.device_id,
           u.name AS user_name, u.type AS user_type, u.email AS user_email
    FROM master_qr_tokens mqt
    JOIN users u ON u.id = mqt.user_id
    WHERE mqt.token = ?
    LIMIT 1
");
$stmt->execute([$qr_token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'QR Code não encontrado.']);
    exit;
}

if ($row['revoked']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code revogado pelo administrador.']);
    exit;
}

if (strtotime($row['expires_at']) < $rl_now) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code expirado.']);
    exit;
}

// Se já foi usado por outro device, rejeitar
if ($row['used_at'] && $row['device_id'] && $row['device_id'] !== $device_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code já utilizado em outro dispositivo.']);
    exit;
}

// Registrar uso (primeira vez) ou atualizar timestamp
if (!$row['used_at']) {
    $conn->prepare("UPDATE master_qr_tokens SET used_at = NOW(), device_id = ? WHERE id = ?")
         ->execute([$device_id ?: null, $row['id']]);
}

// ── Sucesso ──────────────────────────────────────────────────────────────────
echo json_encode([
    'success'    => true,
    'user_id'    => (int)$row['user_id'],
    'user_name'  => $row['user_name'],
    'user_type'  => (int)$row['user_type'],
    'user_email' => $row['user_email'],
    'expires_at' => $row['expires_at'],
    'message'    => 'Acesso master autorizado.',
]);
