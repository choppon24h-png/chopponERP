<?php
/**
 * AJAX - Revogar QR Code Master de um usuário
 * POST /admin/ajax/revogar_master_qr.php
 *
 * Parâmetros POST:
 *   user_id  → ID do usuário alvo
 */
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

requireAdminGeral();

header('Content-Type: application/json; charset=utf-8');

$conn           = getDBConnection();
$target_user_id = (int)($_POST['user_id'] ?? 0);

if ($target_user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id inválido.']);
    exit;
}

$stmt = $conn->prepare("UPDATE master_qr_tokens SET revoked = 1 WHERE user_id = ? AND revoked = 0");
$stmt->execute([$target_user_id]);

echo json_encode(['success' => true, 'revoked' => $stmt->rowCount()]);
