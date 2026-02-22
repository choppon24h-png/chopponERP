-- ============================================
-- Migration: SumUp Cloud API v2.0
-- Versão: 2.0 (phpMyAdmin compatible)
-- Data: 2026-02-22
-- Compatível: MySQL 5.7+ / phpMyAdmin (sem DELIMITER / sem PROCEDURE)
-- Descrição: Adiciona colunas para affiliate_key,
--            estabelecimento_id e merchant_code
-- INSTRUÇÃO: Execute cada bloco separadamente se necessário,
--            ou importe este arquivo normalmente no phpMyAdmin.
-- ============================================

-- ── 1. Criar tabela sumup_readers (se não existir) ───────────────────────────
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
    `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Estabelecimento vinculado a esta leitora',
    `created_at`         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reader_id_unique` (`reader_id`),
    KEY `idx_estabelecimento` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Adicionar colunas na tabela payment ───────────────────────────────────
-- ATENÇÃO: Se alguma coluna já existir, o MySQL retornará erro #1060 (Duplicate column).
-- Nesse caso, comente ou remova a linha correspondente e execute novamente.

ALTER TABLE `payment`
    ADD COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Estabelecimento vinculado a esta configuração de pagamento';

ALTER TABLE `payment`
    ADD COLUMN `affiliate_key` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Chave de afiliado SumUp — obrigatória para Cloud API (leitoras Solo)';

ALTER TABLE `payment`
    ADD COLUMN `merchant_code` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Merchant Code SumUp (sobrescreve constante do config.php se preenchido)';

-- ── 3. Adicionar coluna estabelecimento_id em sumup_readers (se já existia) ──
-- (Só necessário se a tabela sumup_readers já existia antes desta migration)
-- Se a tabela foi criada no passo 1 acima, este ALTER pode ser ignorado com segurança.

ALTER TABLE `sumup_readers`
    ADD COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Estabelecimento ao qual esta leitora está vinculada';

-- ── 4. Índices ────────────────────────────────────────────────────────────────

ALTER TABLE `payment`
    ADD INDEX `idx_payment_estab` (`estabelecimento_id`);

-- ============================================
-- Fim da Migration
-- ============================================
