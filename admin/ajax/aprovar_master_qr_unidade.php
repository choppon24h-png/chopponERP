<?php
/**
 * aprovar_master_qr_unidade.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Endpoint AJAX para aprovação de QR Code Master com controle por unidade.
 *
 * v2.0.0 — Correção dos ALTER TABLE para MariaDB + CORS + robustez
 *
 * CORREÇÕES NESTA VERSÃO:
 *   - Removidos ALTER TABLE ... ADD COLUMN IF NOT EXISTS (não suportado no MariaDB)
 *   - A tabela master_qr_tokens agora é criada com schema completo pelo
 *     request_master_qr.php (API), então aqui apenas verificamos e inserimos
 *   - Adicionado CORS para requisições AJAX do browser
 *   - Melhorado tratamento de erros com mensagens claras
 *
 * FLUXO:
 *   1. Admin abre menu perfil → Acesso QR CODE
 *   2. Escaneia o QR Code exibido no tablet Android
 *   3. Este endpoint valida o token e marca como 'approved'
 *   4. O Android (polling a cada 3s) recebe status=approved e abre ServiceTools
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

// Exige login (qualquer tipo de usuário autenticado)
requireAuth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// CORS para AJAX do browser (ERP)
header('Access-Control-Allow-Origin: ' . (SITE_URL ?: '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Conexão ───────────────────────────────────────────────────────────────────
try {
    $conn = getDBConnection();
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Erro de conexão com o banco.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$current_uid  = (int)$_SESSION['user_id'];
$is_admin     = isAdminGeral();

$qr_data      = trim($_POST['qr_data']             ?? '');
$user_id      = (int)($_POST['user_id']            ?? 0);
$estab_id     = (int)($_POST['estabelecimento_id'] ?? 0);

// ── Validar formato do QR Code ────────────────────────────────────────────────
if (!preg_match('/^CHOPPON_MASTER:([0-9a-f]{64})$/', $qr_data, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QR Code inválido ou formato incorreto.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$token = $m[1];

// ── Determinar usuário alvo ───────────────────────────────────────────────────
if ($is_admin) {
    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selecione o usuário antes de aprovar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $target_user_id = $user_id;
} else {
    // Usuário de unidade: libera acesso para si mesmo
    $target_user_id = $current_uid;

    // Validar que o estabelecimento informado pertence ao usuário logado
    if ($estab_id > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM user_estabelecimento
                WHERE user_id = ? AND estabelecimento_id = ? AND status = 1
            ");
            $stmt->execute([$current_uid, $estab_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Você não tem permissão para liberar acesso neste estabelecimento.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Exception $e) {
            // Tabela user_estabelecimento pode não existir — ignorar validação
            Logger::warning('Tabela user_estabelecimento não encontrada', ['error' => $e->getMessage()]);
        }
    }
}

// ── Buscar dados do usuário alvo ──────────────────────────────────────────────
try {
    $stmt = $conn->prepare("SELECT id, name, type FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao buscar dados do usuário.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$target_user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Buscar token pendente ─────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        SELECT id, expires_at, status, device_id
        FROM master_qr_tokens
        WHERE token = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao buscar token QR. Verifique se a tabela master_qr_tokens existe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'QR Code não encontrado ou já utilizado. Gere um novo QR Code no tablet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar expiração
if (strtotime($row['expires_at']) < time()) {
    try {
        $conn->prepare("UPDATE master_qr_tokens SET status = 'expired' WHERE id = ?")
             ->execute([$row['id']]);
    } catch (Exception $e) { /* ignorar */ }
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'QR Code expirado. Solicite um novo no tablet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validação por unidade (usuário não-admin) ─────────────────────────────────
// Verificar se o tablet pertence ao estabelecimento do usuário (opcional)
if (!$is_admin && $estab_id > 0 && !empty($row['device_id'])) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM tablet_devices
            WHERE device_id = ? AND estabelecimento_id = ? AND status = 1
        ");
        $stmt->execute([$row['device_id'], $estab_id]);
        $tabletCount = (int)$stmt->fetchColumn();
        if ($tabletCount === 0) {
            // Verificar se a tabela tem algum registro para este device
            // (se não tiver nenhum registro, permitir — tablet ainda não cadastrado)
            $stmtAny = $conn->prepare("SELECT COUNT(*) FROM tablet_devices WHERE device_id = ?");
            $stmtAny->execute([$row['device_id']]);
            if ((int)$stmtAny->fetchColumn() > 0) {
                // Tablet está cadastrado mas em outro estabelecimento
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Este tablet não está vinculado ao seu estabelecimento.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Tablet não cadastrado — permitir acesso e registrar automaticamente
            try {
                $conn->prepare("
                    INSERT IGNORE INTO tablet_devices (device_id, estabelecimento_id, registered_by, device_name)
                    VALUES (?, ?, ?, 'Tablet auto-registrado via QR Master')
                ")->execute([$row['device_id'], $estab_id, $current_uid]);
            } catch (Exception $e) {
                // Tabela tablet_devices pode não existir — ignorar
            }
        }
    } catch (Exception $e) {
        // Tabela tablet_devices não existe — permitir (será criada pela migration)
        Logger::warning('Tabela tablet_devices não encontrada — validação de unidade ignorada', ['error' => $e->getMessage()]);
    }
}

// ── Aprovar ───────────────────────────────────────────────────────────────────
try {
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
} catch (Exception $e) {
    Logger::error('Erro ao aprovar token QR', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao aprovar acesso. Tente novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Registrar log da aprovação
Logger::auth('QR Master aprovado via menu de perfil', [
    'approved_by'        => $current_uid,
    'approved_user_id'   => $target_user['id'],
    'approved_name'      => $target_user['name'],
    'estabelecimento_id' => $estab_id ?: 'N/A',
    'token_id'           => $row['id'],
    'device_id'          => $row['device_id'],
]);

echo json_encode([
    'success'   => true,
    'message'   => 'Acesso master liberado para ' . $target_user['name'] . '. O tablet será desbloqueado em instantes.',
    'user_name' => $target_user['name'],
], JSON_UNESCAPED_UNICODE);
