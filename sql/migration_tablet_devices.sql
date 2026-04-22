-- ============================================================
-- migration_tablet_devices.sql
-- Compatível com MariaDB 10.2+
--
-- Tabela para vincular tablets (device_id) a estabelecimentos.
-- Permite que cada unidade controle quais tablets têm acesso
-- ao Acesso Master via QR Code.
-- ============================================================

-- Tabela de tablets vinculados a estabelecimentos
CREATE TABLE IF NOT EXISTS `tablet_devices` (
    `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
    `device_id`           VARCHAR(128) NOT NULL,
    `estabelecimento_id`  INT(11)      NOT NULL,
    `device_name`         VARCHAR(255) DEFAULT NULL COMMENT 'Nome amigável do tablet',
    `status`              TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=ativo, 0=inativo',
    `registered_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`        DATETIME     DEFAULT NULL,
    `registered_by`       INT(11)      DEFAULT NULL COMMENT 'user_id que registrou o tablet',
    `notes`               TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_device_estab` (`device_id`, `estabelecimento_id`),
    KEY `idx_device_id`       (`device_id`),
    KEY `idx_estabelecimento`  (`estabelecimento_id`),
    KEY `idx_status`           (`status`),
    CONSTRAINT `fk_td_estab` FOREIGN KEY (`estabelecimento_id`)
        REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tablets vinculados a estabelecimentos para controle de acesso QR Master';

-- ============================================================
-- Atualizar schema da tabela master_qr_tokens
-- MariaDB não suporta ADD COLUMN IF NOT EXISTS antes da 10.2.
-- Usamos blocos condicionais via PROCEDURE para compatibilidade.
-- ============================================================

-- Adicionar coluna `status` se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND COLUMN_NAME  = 'status'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE `master_qr_tokens`
        ADD COLUMN `status` ENUM('pending','approved','rejected','expired')
        NOT NULL DEFAULT 'pending'
        AFTER `device_id`",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adicionar coluna `approved_by` se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND COLUMN_NAME  = 'approved_by'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_by` INT(11) DEFAULT NULL AFTER `expires_at`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adicionar coluna `approved_user_id` se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND COLUMN_NAME  = 'approved_user_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_user_id` INT(11) DEFAULT NULL AFTER `approved_by`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adicionar coluna `approved_name` se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND COLUMN_NAME  = 'approved_name'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_name` VARCHAR(255) DEFAULT NULL AFTER `approved_user_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adicionar coluna `approved_type` se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND COLUMN_NAME  = 'approved_type'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_type` TINYINT(4) DEFAULT NULL AFTER `approved_name`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adicionar índice `idx_status` se não existir
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'master_qr_tokens'
      AND INDEX_NAME   = 'idx_status'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE `master_qr_tokens` ADD INDEX `idx_status` (`status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
