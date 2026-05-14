-- ============================================================
-- MIGRAÇÃO: payment_config multi-estabelecimento
-- Compatível com MariaDB 5.7 / MySQL 5.7
-- Regras: sem JSON, sem IF NOT EXISTS em ALTER TABLE,
--         sem DEFAULT com expressão, sem utf8mb4 (usar utf8)
-- ============================================================

SET NAMES utf8;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. Criar tabela payment_config (substitui a tabela payment
--    para suporte multi-estabelecimento)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_config` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `estabelecimento_id`  BIGINT UNSIGNED NOT NULL COMMENT 'FK para estabelecimentos',
    -- SumUp
    `sumup_token`         VARCHAR(255)    NULL DEFAULT NULL COMMENT 'API Key SumUp (sup_sk_...)',
    `sumup_affiliate_key` VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Affiliate Key SumUp (sup_afk_...)',
    `sumup_affiliate_app_id` VARCHAR(120) NULL DEFAULT NULL COMMENT 'Application Identifier da Affiliate Key',
    `sumup_merchant_code` VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Merchant Code SumUp',
    `sumup_webhook_secret` VARCHAR(255)   NULL DEFAULT NULL COMMENT 'Secret para validar webhooks SumUp',
    -- Métodos habilitados (SumUp)
    `pix`                 TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=PIX habilitado',
    `credit`              TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=Crédito habilitado',
    `debit`               TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=Débito habilitado',
    -- Mercado Pago
    `mp_access_token`     VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Access Token Mercado Pago',
    `mp_public_key`       VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Public Key Mercado Pago (opcional)',
    `mp_ambiente`         ENUM('sandbox','production') NOT NULL DEFAULT 'production',
    `mp_webhook_url`      VARCHAR(500)    NULL DEFAULT NULL COMMENT 'URL do webhook MP',
    `mp_webhook_secret`   VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Secret do webhook MP',
    -- Status
    `status`              TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=Ativo, 0=Inativo',
    `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_config_estab` (`estabelecimento_id`),
    KEY `idx_payment_config_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
  COMMENT='Configurações de pagamento SumUp e Mercado Pago por estabelecimento';

-- ============================================================
-- 2. Migrar dados existentes da tabela payment para payment_config
--    Apenas se payment_config estiver vazia e payment tiver dados
-- ============================================================
DROP PROCEDURE IF EXISTS choppon_migrate_payment;

DELIMITER $$
CREATE PROCEDURE choppon_migrate_payment()
BEGIN
    DECLARE v_count_dest INT DEFAULT 0;
    DECLARE v_count_src  INT DEFAULT 0;

    SELECT COUNT(*) INTO v_count_dest FROM payment_config;
    SELECT COUNT(*) INTO v_count_src  FROM payment;

    IF v_count_dest = 0 AND v_count_src > 0 THEN
        -- Migrar cada linha da tabela payment para payment_config
        -- Se estabelecimento_id for NULL, vincula ao estabelecimento 1 (padrão)
        INSERT INTO payment_config (
            estabelecimento_id,
            sumup_token,
            sumup_affiliate_key,
            sumup_affiliate_app_id,
            sumup_merchant_code,
            pix,
            credit,
            debit,
            status
        )
        SELECT
            COALESCE(estabelecimento_id, 1),
            token_sumup,
            affiliate_key,
            affiliate_app_id,
            merchant_code,
            COALESCE(pix, 1),
            COALESCE(credit, 1),
            COALESCE(debit, 1),
            1
        FROM payment
        WHERE token_sumup IS NOT NULL AND token_sumup != ''
        ON DUPLICATE KEY UPDATE
            sumup_token            = VALUES(sumup_token),
            sumup_affiliate_key    = VALUES(sumup_affiliate_key),
            sumup_affiliate_app_id = VALUES(sumup_affiliate_app_id),
            sumup_merchant_code    = VALUES(sumup_merchant_code),
            pix                    = VALUES(pix),
            credit                 = VALUES(credit),
            debit                  = VALUES(debit),
            updated_at             = CURRENT_TIMESTAMP;
    END IF;
END$$
DELIMITER ;

CALL choppon_migrate_payment();
DROP PROCEDURE IF EXISTS choppon_migrate_payment;

-- ============================================================
-- 3. Migrar dados do mercadopago_config para payment_config
--    (unifica as duas tabelas em uma só)
-- ============================================================
DROP PROCEDURE IF EXISTS choppon_migrate_mp;

DELIMITER $$
CREATE PROCEDURE choppon_migrate_mp()
BEGIN
    DECLARE v_exists INT DEFAULT 0;

    -- Verificar se tabela mercadopago_config existe
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA COLLATE utf8_general_ci = DATABASE()
      AND TABLE_NAME   COLLATE utf8_general_ci = 'mercadopago_config';

    IF v_exists > 0 THEN
        -- Atualizar payment_config com dados do MP onde o estabelecimento já existe
        UPDATE payment_config pc
        INNER JOIN mercadopago_config mc ON mc.estabelecimento_id = pc.estabelecimento_id
        SET
            pc.mp_access_token   = mc.access_token,
            pc.mp_public_key     = mc.public_key,
            pc.mp_ambiente       = CASE WHEN mc.ambiente = 'sandbox' THEN 'sandbox' ELSE 'production' END,
            pc.mp_webhook_url    = mc.webhook_url,
            pc.mp_webhook_secret = mc.webhook_secret,
            pc.updated_at        = CURRENT_TIMESTAMP
        WHERE mc.status = 1;

        -- Inserir estabelecimentos que só têm MP (sem SumUp ainda)
        INSERT INTO payment_config (
            estabelecimento_id,
            mp_access_token,
            mp_public_key,
            mp_ambiente,
            mp_webhook_url,
            mp_webhook_secret,
            status
        )
        SELECT
            mc.estabelecimento_id,
            mc.access_token,
            mc.public_key,
            CASE WHEN mc.ambiente = 'sandbox' THEN 'sandbox' ELSE 'production' END,
            mc.webhook_url,
            mc.webhook_secret,
            1
        FROM mercadopago_config mc
        LEFT JOIN payment_config pc ON pc.estabelecimento_id = mc.estabelecimento_id
        WHERE pc.id IS NULL AND mc.status = 1
        ON DUPLICATE KEY UPDATE
            mp_access_token   = VALUES(mp_access_token),
            mp_public_key     = VALUES(mp_public_key),
            mp_ambiente       = VALUES(mp_ambiente),
            mp_webhook_url    = VALUES(mp_webhook_url),
            mp_webhook_secret = VALUES(mp_webhook_secret),
            updated_at        = CURRENT_TIMESTAMP;
    END IF;
END$$
DELIMITER ;

CALL choppon_migrate_mp();
DROP PROCEDURE IF EXISTS choppon_migrate_mp;

SET foreign_key_checks = 1;

-- ============================================================
-- FIM DA MIGRAÇÃO
-- Após executar, verifique:
--   SELECT * FROM payment_config;
-- Cada estabelecimento deve ter sua linha com suas credenciais.
-- ============================================================
