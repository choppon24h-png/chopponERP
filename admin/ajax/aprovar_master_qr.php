<?php
/**
 * aprovar_master_qr.php — Admin do ERP aprova o QR Code escaneado do tablet
 *
 * POST /admin/ajax/aprovar_master_qr.php
 *   qr_data   → conteúdo lido do QR Code: "CHOPPON_MASTER:<64-char-hex>"
 *   user_id   → ID do usuário que será liberado (selecionado no painel)
 *
 * Retorna JSON: { success, message, user_name }
 */

require_once '../../includes/config.php';
require_once '../../includes/auth.php';

// Apenas Admin Geral pode aprovar
requireAdminGeral();

header('Content-Type: application/json; charset=utf-8');

$conn      = getDBConnection();
$admin_id  = (int)$_SESSION['user_id'];
$qr_data   = trim($_POST['qr_data']  ?? '');
$user_id   = (int)($_POST['user_id'] ?? 0);

// ── Validar formato do QR Code ────────────────────────────────────────────────
if (!preg_match('/^CHOPPON_MASTER:([0-9a-f]{64})$/', $qr_data, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QR Code inválido ou formato incorreto.']);
    exit;
}
$token = $m[1];

// ── Validar usuário alvo ──────────────────────────────────────────────────────
if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Selecione o usuário antes de aprovar.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, name, type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
    exit;
}

// ── Buscar token pendente ─────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, expires_at, status, used_at
    FROM master_qr_tokens
    WHERE token = ? AND status = 'pending'
    LIMIT 1
");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'QR Code não encontrado ou já utilizado.']);
    exit;
}

if (strtotime($row['expires_at']) < time()) {
    $conn->prepare("UPDATE master_qr_tokens SET status = 'expired' WHERE id = ?")
         ->execute([$row['id']]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code expirado.']);
    exit;
}

// ── Aprovar ───────────────────────────────────────────────────────────────────
$conn->prepare("
    UPDATE master_qr_tokens
    SET status           = 'approved',
        approved_by      = ?,
        approved_user_id = ?,
        approved_name    = ?,
        approved_type    = ?,
        used_at          = NOW()
    WHERE id = ?
")->execute([
    $admin_id,
    $target_user['id'],
    $target_user['name'],
    $target_user['type'],
    $row['id'],
]);

echo json_encode([
    'success'   => true,
    'message'   => 'Acesso master liberado para ' . $target_user['name'] . '.',
    'user_name' => $target_user['name'],
]);
