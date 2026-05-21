<?php
/**
 * AJAX - Gerar QR Code Master para um usuário (fluxo legado — Admin Geral)
 * POST /admin/ajax/gerar_master_qr.php
 *
 * v2.0.0 — Schema atualizado para ser compatível com o novo fluxo Android
 *
 * ATENÇÃO: Este endpoint é o fluxo LEGADO (ERP gera QR para o Android ler).
 * O fluxo NOVO (Android gera QR para o ERP ler) usa request_master_qr.php.
 * Ambos coexistem e compartilham a mesma tabela master_qr_tokens.
 *
 * CORREÇÃO: Schema da tabela atualizado para incluir coluna 'status' e
 * remover colunas legadas incompatíveis com o novo fluxo.
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

// ── Garantir tabela com schema COMPLETO (compatível com novo fluxo Android) ──
// CORREÇÃO CRÍTICA: O schema antigo usava user_id/created_by/revoked.
// O novo fluxo usa device_id/status/approved_by/approved_user_id/approved_name/approved_type.
// Criamos com o schema novo; as colunas legadas são adicionadas apenas se necessário.
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
} catch (Exception $e) {
    // Tabela já existe — continuar
}

// Adicionar colunas legadas se não existirem (para compatibilidade com código antigo)
$legacyCols = [
    'user_id'    => "ALTER TABLE `master_qr_tokens` ADD COLUMN `user_id` INT(11) DEFAULT NULL AFTER `id`",
    'created_by' => "ALTER TABLE `master_qr_tokens` ADD COLUMN `created_by` INT(11) DEFAULT NULL AFTER `user_id`",
    'revoked'    => "ALTER TABLE `master_qr_tokens` ADD COLUMN `revoked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `used_at`",
];
foreach ($legacyCols as $col => $sql) {
    try {
        $chk = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'master_qr_tokens'
              AND COLUMN_NAME  = ?
        ");
        $chk->execute([$col]);
        if ((int)$chk->fetchColumn() === 0) {
            $conn->exec($sql);
        }
    } catch (Exception $e) { /* ignorar */ }
}

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

// Revogar/expirar tokens anteriores não expirados deste usuário (ambos os fluxos)
try {
    $conn->prepare("
        UPDATE `master_qr_tokens`
        SET `status` = 'expired'
        WHERE `approved_user_id` = ? AND `status` = 'pending' AND `expires_at` > NOW()
    ")->execute([$target_user_id]);
} catch (Exception $e) { /* ignorar */ }

// Compatibilidade com schema legado (coluna revoked)
try {
    $conn->prepare("
        UPDATE `master_qr_tokens`
        SET `revoked` = 1
        WHERE `user_id` = ? AND `revoked` = 0 AND `expires_at` > NOW()
    ")->execute([$target_user_id]);
} catch (Exception $e) { /* coluna revoked pode não existir */ }

// Gerar token seguro: 32 bytes hex = 64 chars
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 86400); // 24 horas

// Inserir com schema novo (device_id vazio = gerado pelo ERP, não pelo Android)
try {
    $stmt = $conn->prepare("
        INSERT INTO `master_qr_tokens`
            (`token`, `device_id`, `status`, `expires_at`, `approved_by`, `approved_user_id`, `approved_name`, `approved_type`)
        VALUES (?, '', 'approved', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $token,
        $expires,
        $admin_id,
        $target_user['id'],
        $target_user['name'],
        $target_user['type'],
    ]);
} catch (Exception $e) {
    // Fallback: inserir apenas campos básicos
    $stmt = $conn->prepare("
        INSERT INTO `master_qr_tokens` (`token`, `device_id`, `status`, `expires_at`)
        VALUES (?, '', 'approved', ?)
    ");
    $stmt->execute([$token, $expires]);
}

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
