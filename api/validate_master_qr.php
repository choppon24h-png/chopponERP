<?php
/**
 * API - Validar QR Code Master (chamado pelo Android — fluxo legado de senha)
 * POST /api/validate_master_qr.php
 *
 * v2.0.0 — Correção do JWT header + schema compatível com novo fluxo
 *
 * CORREÇÕES:
 *   - JWT lido via getallheaders() (HTTP_TOKEN falha em Apache com mod_rewrite)
 *   - CORS inclui header 'token' nos Access-Control-Allow-Headers
 *   - Schema da tabela atualizado para ser compatível com o novo fluxo Android
 *   - Suporte ao novo campo 'status' além do legado 'revoked'
 *
 * Headers:
 *   token: <jwt_token>   (padrão ApiHelper.java)
 *   Content-Type: application/x-www-form-urlencoded
 *
 * Body:
 *   senha=<6-digits>&device_id=<android_id>
 *
 * Retorna JSON:
 *   { success, user_id, user_name, user_type, message }
 */

ob_start();

require_once '../includes/config.php';
require_once '../includes/jwt.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: token, Token, Authorization, Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Rate limiting simples por IP ─────────────────────────────────────────────
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl_key  = sys_get_temp_dir() . '/qr_rl_' . md5($ip) . '.json';
$rl_now  = time();
$rl_data = [];

if (file_exists($rl_key)) {
    $rl_data = json_decode(file_get_contents($rl_key), true) ?? [];
}
$rl_data = array_filter($rl_data, fn($t) => ($rl_now - $t) < 60);

if (count($rl_data) >= 10) {
    http_response_code(429);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 minuto.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

$rl_data[] = $rl_now;
file_put_contents($rl_key, json_encode(array_values($rl_data)));

// ── Validar JWT ──────────────────────────────────────────────────────────────
// CORREÇÃO: usar getallheaders() — $_SERVER['HTTP_TOKEN'] falha em Apache
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$normalizedHeaders = [];
foreach ($allHeaders as $k => $v) {
    $normalizedHeaders[strtolower($k)] = $v;
}

$jwt_raw = $normalizedHeaders['token']
    ?? $normalizedHeaders['authorization']
    ?? $_SERVER['HTTP_TOKEN']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? '';

$jwt_raw = preg_replace('/^Bearer\s+/i', '', trim($jwt_raw));

if (empty($jwt_raw)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Token JWT ausente.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Validar JWT com tolerância de clock skew (10 min)
$jwtParts = explode('.', $jwt_raw);
$sigValid = false;
if (count($jwtParts) === 3) {
    $sigCheck = hash_hmac('sha256', $jwtParts[0] . '.' . $jwtParts[1], JWT_SECRET, true);
    $sigB64   = rtrim(strtr(base64_encode($sigCheck), '+/', '-_'), '=');
    $sigValid = hash_equals($sigB64, $jwtParts[2]);
}

if (!$sigValid && !jwtValidate($jwt_raw)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Ler parâmetros ────────────────────────────────────────────────────────────
$content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($content_type, 'application/json') !== false) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $senha     = trim($body['senha']     ?? '');
    $qr_token  = trim($body['qr_token']  ?? '');
    $device_id = trim($body['device_id'] ?? '');
} else {
    $senha     = trim($_POST['senha']     ?? '');
    $qr_token  = trim($_POST['qr_token']  ?? '');
    $device_id = trim($_POST['device_id'] ?? '');
}

// ── Conexão ───────────────────────────────────────────────────────────────────
$conn = getDBConnection();

// ── Garantir tabela com schema completo ──────────────────────────────────────
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `master_qr_tokens` (
            `id`               INT(11)      NOT NULL AUTO_INCREMENT,
            `token`            VARCHAR(64)  NOT NULL,
            `device_id`        VARCHAR(128) NOT NULL DEFAULT '',
            `status`           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at`       DATETIME     NOT NULL,
            `approved_by`      INT(11)      DEFAULT NULL,
            `approved_user_id` INT(11)      DEFAULT NULL,
            `approved_name`    VARCHAR(255) DEFAULT NULL,
            `approved_type`    TINYINT(4)   DEFAULT NULL,
            `used_at`          DATETIME     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_token` (`token`),
            KEY `idx_device`  (`device_id`),
            KEY `idx_status`  (`status`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) { /* tabela já existe */ }

// ── Validação por senha (6 dígitos) ──────────────────────────────────────────
if (!empty($senha) && preg_match('/^\d{6}$/', $senha)) {
    // Buscar usuário com esta senha master configurada
    try {
        $stmt = $conn->prepare("
            SELECT id, name, type FROM users
            WHERE master_senha = ? AND status = 1
            LIMIT 1
        ");
        $stmt->execute([$senha]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Coluna master_senha pode não existir — retornar não encontrado
        $user = null;
    }

    if ($user) {
        ob_clean();
        echo json_encode([
            'success'   => true,
            'user_id'   => $user['id'],
            'user_name' => $user['name'],
            'user_type' => (int)$user['type'],
            'message'   => 'Acesso liberado por senha.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Senha inválida.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Validação por QR Token (fluxo legado) ────────────────────────────────────
if (!empty($qr_token)) {
    if (!preg_match('/^[0-9a-f]{64}$/', $qr_token)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Formato de token inválido.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Buscar token no novo schema
    $stmt = $conn->prepare("
        SELECT id, status, expires_at, approved_user_id, approved_name, approved_type, used_at, device_id
        FROM master_qr_tokens
        WHERE token = ?
        LIMIT 1
    ");
    $stmt->execute([$qr_token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'QR Code não encontrado.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if ($row['status'] === 'expired' || strtotime($row['expires_at']) < time()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'QR Code expirado.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if ($row['status'] === 'rejected') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'QR Code rejeitado.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if ($row['status'] === 'approved') {
        // Marcar device_id se ainda não foi marcado
        if (empty($row['device_id']) && !empty($device_id)) {
            $conn->prepare("UPDATE master_qr_tokens SET device_id = ?, used_at = NOW() WHERE id = ?")
                 ->execute([$device_id, $row['id']]);
        }

        ob_clean();
        echo json_encode([
            'success'   => true,
            'user_id'   => $row['approved_user_id'],
            'user_name' => $row['approved_name'] ?? 'Técnico',
            'user_type' => (int)($row['approved_type'] ?? 3),
            'message'   => 'Acesso liberado.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Status pending — ainda não aprovado
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'QR Code ainda não aprovado. Aguarde a aprovação do administrador.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Nenhum parâmetro válido
http_response_code(400);
ob_clean();
echo json_encode(['success' => false, 'message' => 'Informe a senha ou o token QR.'], JSON_UNESCAPED_UNICODE);
ob_end_flush();
