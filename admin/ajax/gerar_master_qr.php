<?php
/**
 * AJAX - Gerar QR Code Master para um usuário
 * POST /admin/ajax/gerar_master_qr.php
 *
 * Parâmetros POST:
 *   user_id  → ID do usuário alvo
 *
 * Retorna JSON:
 *   { success, token, qr_url, expires_at, user_name }
 */
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/jwt.php';

// Apenas Admin Geral pode gerar QR Code master
requireAdminGeral();

header('Content-Type: application/json; charset=utf-8');

$conn = getDBConnection();

// ── Garantir que a tabela existe ─────────────────────────────────────────────
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

$target_user_id = (int)($_POST['user_id'] ?? 0);
$admin_id       = (int)$_SESSION['user_id'];

if ($target_user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id inválido.']);
    exit;
}

// Verificar se o usuário alvo existe
$stmt = $conn->prepare("SELECT id, name, type FROM users WHERE id = ?");
$stmt->execute([$target_user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Usuário não encontrado.']);
    exit;
}

// Revogar tokens anteriores não expirados deste usuário
$conn->prepare("UPDATE master_qr_tokens SET revoked = 1 WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()")
     ->execute([$target_user_id]);

// Gerar token seguro: 32 bytes hex = 64 chars
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 86400); // 24 horas

$stmt = $conn->prepare("
    INSERT INTO master_qr_tokens (user_id, token, created_by, expires_at)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$target_user_id, $token, $admin_id, $expires]);

// QR Code contém o prefixo CHOPPON_MASTER: para identificação no Android
$qr_data = urlencode('CHOPPON_MASTER:' . $token);
$qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&data={$qr_data}";

echo json_encode([
    'success'    => true,
    'token'      => $token,
    'qr_url'     => $qr_url,
    'expires_at' => $expires,
    'user_name'  => $target_user['name'],
]);
