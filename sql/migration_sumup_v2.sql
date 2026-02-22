-- ============================================
-- Migration: SumUp Cloud API v2.0
-- Versão: 2.0
-- Data: 2026-02-22
-- Compatível: MySQL 5.7+ / MariaDB 10.1+
-- Descrição: Adiciona colunas para affiliate_key,
--            estabelecimento_id e merchant_code
-- ============================================

-- Usar PROCEDURE temporária para simular "ADD COLUMN IF NOT EXISTS"
-- (MySQL não suporta ADD COLUMN IF NOT EXISTS, apenas MariaDB 10.3+)

DROP PROCEDURE IF EXISTS migration_sumup_v2;

DELIMITER $$

CREATE PROCEDURE migration_sumup_v2()
BEGIN

    -- ── Tabela: payment ──────────────────────────────────────────

    -- Coluna: estabelecimento_id
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payment'
          AND COLUMN_NAME  = 'estabelecimento_id'
    ) THEN
        ALTER TABLE `payment`
            ADD COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Estabelecimento vinculado a esta configuração de pagamento'
            AFTER `debit`;
    END IF;

    -- Coluna: affiliate_key
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payment'
          AND COLUMN_NAME  = 'affiliate_key'
    ) THEN
        ALTER TABLE `payment`
            ADD COLUMN `affiliate_key` VARCHAR(255) NULL DEFAULT NULL
            COMMENT 'Chave de afiliado SumUp — obrigatória para Cloud API (leitoras Solo)'
            AFTER `estabelecimento_id`;
    END IF;

    -- Coluna: merchant_code
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payment'
          AND COLUMN_NAME  = 'merchant_code'
    ) THEN
        ALTER TABLE `payment`
            ADD COLUMN `merchant_code` VARCHAR(50) NULL DEFAULT NULL
            COMMENT 'Merchant Code SumUp (sobrescreve constante do config.php se preenchido)'
            AFTER `affiliate_key`;
    END IF;

    -- ── Tabela: sumup_readers ────────────────────────────────────

    -- Criar tabela se não existir
    CREATE TABLE IF NOT EXISTS `sumup_readers` (
        `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `reader_id`          VARCHAR(60)     NOT NULL,
        `name`               VARCHAR(255)    NOT NULL,
        `serial`             VARCHAR(100)    NULL DEFAULT NULL,
        `model`              VARCHAR(50)     NULL DEFAULT NULL,
        `status`             VARCHAR(30)     NOT NULL DEFAULT 'processing',
        `battery_level`      INT             NULL DEFAULT NULL,
        `connection_type`    VARCHAR(30)     NULL DEFAULT NULL,
        `firmware_version`   VARCHAR(50)     NULL DEFAULT NULL,
        `last_activity`      DATETIME        NULL DEFAULT NULL,
        `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `created_at`         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `reader_id_unique` (`reader_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Coluna: estabelecimento_id (caso a tabela já existia sem ela)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'sumup_readers'
          AND COLUMN_NAME  = 'estabelecimento_id'
    ) THEN
        ALTER TABLE `sumup_readers`
            ADD COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Estabelecimento ao qual esta leitora está vinculada'
            AFTER `last_activity`;
    END IF;

    -- ── Índices ──────────────────────────────────────────────────

    -- Índice em sumup_readers.estabelecimento_id
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'sumup_readers'
          AND INDEX_NAME   = 'idx_estabelecimento'
    ) THEN
        ALTER TABLE `sumup_readers`
            ADD INDEX `idx_estabelecimento` (`estabelecimento_id`);
    END IF;

    -- Índice em payment.estabelecimento_id
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payment'
          AND INDEX_NAME   = 'idx_payment_estab'
    ) THEN
        ALTER TABLE `payment`
            ADD INDEX `idx_payment_estab` (`estabelecimento_id`);
    END IF;

END$$

DELIMITER ;

-- Executar a procedure
CALL migration_sumup_v2();

-- Remover a procedure após execução
DROP PROCEDURE IF EXISTS migration_sumup_v2;

-- ============================================
-- Fim da Migration
-- ============================================
