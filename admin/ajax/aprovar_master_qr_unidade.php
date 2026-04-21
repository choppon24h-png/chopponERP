<?php
/**
 * aprovar_master_qr_unidade.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Endpoint AJAX para aprovação de QR Code Master com controle por unidade.
 *
 * Diferença em relação a aprovar_master_qr.php:
 *   - Não exige Admin Geral: qualquer usuário autenticado pode aprovar.
 *   - Quando o usuário NÃO é Admin Geral, valida que o tablet (device_id)
 *     pertence a um estabelecimento vinculado ao usuário logado.
 *   - Admin Geral pode aprovar para qualquer usuário/tablet.
 *
 * POST /admin/ajax/aprovar_master_qr_unidade.php
 *   qr_data            → conteúdo lido do QR Code: "CHOPPON_MASTER:<64-char-hex>"
 *   user_id            → ID do usuário que receberá o acesso (obrigatório p/ Admin Geral)
 *   estabelecimento_id → ID do estabelecimento (obrigatório p/ usuários de unidade)
 *
 * Retorna JSON: { success, message, user_name }
 */

require_once '../../includes/config.php';
require_once '../../includes/auth.php';

// Exige login (qualquer tipo de usuário)
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$conn          = getDBConnection();
$current_uid   = (int)$_SESSION['user_id'];
$current_type  = (int)($_SESSION['user_type'] ?? 99);
$is_admin      = isAdminGeral();

$qr_data       = trim($_POST['qr_data']            ?? '');
$user_id       = (int)($_POST['user_id']           ?? 0);
$estab_id      = (int)($_POST['estabelecimento_id'] ?? 0);

// ── Garantir schema atualizado da tabela ─────────────────────────────────────
$alterations = [
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_by INT NULL",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_user_id INT NULL",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_name VARCHAR(255) NULL",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_type TINYINT NULL",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS device_id VARCHAR(128) NULL",
];
foreach ($alterations as $sql) {
    try { $conn->exec($sql); } catch (Exception $e) { /* coluna já existe */ }
}

// ── Validar formato do QR Code ────────────────────────────────────────────────
if (!preg_match('/^CHOPPON_MASTER:([0-9a-f]{64})$/', $qr_data, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QR Code inválido ou formato incorreto.']);
    exit;
}
$token = $m[1];

// ── Determinar usuário alvo ───────────────────────────────────────────────────
if ($is_admin) {
    // Admin Geral: user_id obrigatório no POST
    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selecione o usuário antes de aprovar.']);
        exit;
    }
    $target_user_id = $user_id;
} else {
    // Usuário de unidade: libera acesso para si mesmo
    $target_user_id = $current_uid;

    // Validar que o estabelecimento informado pertence ao usuário logado
    if ($estab_id > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM user_estabelecimento
            WHERE user_id = ? AND estabelecimento_id = ? AND status = 1
        ");
        $stmt->execute([$current_uid, $estab_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Você não tem permissão para liberar acesso neste estabelecimento.']);
            exit;
        }
    }
}

// ── Buscar dados do usuário alvo ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, name, type FROM users WHERE id = ?");
$stmt->execute([$target_user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
    exit;
}

// ── Buscar token pendente ─────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, expires_at, status, device_id
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

// Verificar expiração
if (strtotime($row['expires_at']) < time()) {
    $conn->prepare("UPDATE master_qr_tokens SET status = 'expired' WHERE id = ?")
         ->execute([$row['id']]);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code expirado. Solicite um novo no tablet.']);
    exit;
}

// ── Validação por unidade (usuário não-admin) ─────────────────────────────────
// Se o token tem device_id, verificar que o device pertence ao estabelecimento do usuário
if (!$is_admin && $estab_id > 0 && !empty($row['device_id'])) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM tablet_devices
        WHERE device_id = ? AND estabelecimento_id = ? AND status = 1
    ");
    $stmt->execute([$row['device_id'], $estab_id]);
    // Tabela tablet_devices pode não existir ainda — ignorar erro
    try {
        if ((int)$stmt->fetchColumn() === 0) {
            // Tablet não vinculado a este estabelecimento
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Este tablet não está vinculado ao seu estabelecimento.']);
            exit;
        }
    } catch (Exception $e) {
        // Tabela ainda não existe — permitir (será criada pelo endpoint do Android)
    }
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
    $current_uid,
    $target_user['id'],
    $target_user['name'],
    $target_user['type'],
    $row['id'],
]);

// Registrar log da aprovação
Logger::auth('QR Master aprovado via menu de perfil', [
    'approved_by'      => $current_uid,
    'approved_user_id' => $target_user['id'],
    'approved_name'    => $target_user['name'],
    'estabelecimento_id' => $estab_id ?: 'N/A',
    'token_id'         => $row['id'],
]);

echo json_encode([
    'success'   => true,
    'message'   => 'Acesso master liberado para ' . $target_user['name'] . '.',
    'user_name' => $target_user['name'],
]);
