-- ============================================================
-- MIGRAÇÃO: Royalties v2 — Conciliação MP + Pagamento Manual
-- Versão: 2.1
-- Data: 2026-05-13
-- Compatibilidade: MariaDB 5.7 / MySQL 5.7 (utf8_general_ci)
-- ============================================================
-- CORREÇÕES APLICADAS:
--   v2.0: ADD COLUMN IF NOT EXISTS → STORED PROCEDURE
--   v2.0: CREATE INDEX IF NOT EXISTS → STORED PROCEDURE
--   v2.1: #1267 Collation mismatch (utf8_general_ci vs utf8mb4_unicode_ci)
--         → Adicionado COLLATE utf8_general_ci nas comparações de string
--           dentro das procedures para forçar a mesma collation do banco
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── 1. Ampliar ENUM de status para incluir novos estados ──────────────────────
ALTER TABLE `royalties`
  MODIFY COLUMN `status` ENUM(
    'pendente',
    'link_gerado',
    'enviado',
    'pago',
    'cancelado',
    'conciliado',
    'pagamento_manual'
  ) NOT NULL DEFAULT 'pendente'
  COMMENT 'pendente=aguardando | link_gerado=link MP gerado | enviado=email enviado | pago=pago via webhook | conciliado=confirmado MP | pagamento_manual=marcado manualmente';

-- ── 2. Procedure auxiliar para ADD COLUMN condicional ─────────────────────────
-- NOTA: O COLLATE utf8_general_ci nas comparações resolve o erro #1267
--       causado pelo conflito entre a collation do banco (utf8_general_ci)
--       e a collation padrão das strings literais (utf8mb4_unicode_ci)
DROP PROCEDURE IF EXISTS choppon_add_column;

DELIMITER $$
CREATE PROCEDURE choppon_add_column(
    IN p_table  VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    IN p_column VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    IN p_def    TEXT        CHARACTER SET utf8 COLLATE utf8_general_ci
)
BEGIN
    DECLARE v_db VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci;
    SET v_db = DATABASE();

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA COLLATE utf8_general_ci = v_db
          AND TABLE_NAME   COLLATE utf8_general_ci = p_table
          AND COLUMN_NAME  COLLATE utf8_general_ci = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ── 3. Colunas Mercado Pago ───────────────────────────────────────────────────
CALL choppon_add_column('royalties', 'mp_preference_id',
    "VARCHAR(255) NULL DEFAULT NULL COMMENT 'ID da preferencia de pagamento no Mercado Pago'");

CALL choppon_add_column('royalties', 'mp_payment_id',
    "VARCHAR(255) NULL DEFAULT NULL COMMENT 'ID do pagamento confirmado pelo webhook do MP'");

CALL choppon_add_column('royalties', 'mp_payment_status',
    "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Status retornado pelo MP: approved, pending, rejected'");

CALL choppon_add_column('royalties', 'mp_payment_method',
    "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Metodo de pagamento: pix, credit_card, boleto'");

CALL choppon_add_column('royalties', 'mp_payment_detail',
    "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Detalhe do pagamento (banco, bandeira, etc)'");

CALL choppon_add_column('royalties', 'mp_link_pagamento',
    "TEXT NULL DEFAULT NULL COMMENT 'URL do link de pagamento gerado no MP'");

CALL choppon_add_column('royalties', 'mp_webhook_payload',
    "MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Payload completo do ultimo webhook recebido (JSON como texto)'");

CALL choppon_add_column('royalties', 'mp_conciliado_em',
    "DATETIME NULL DEFAULT NULL COMMENT 'Data/hora em que o pagamento foi conciliado via webhook'");

-- ── 4. Colunas pagamento manual ───────────────────────────────────────────────
CALL choppon_add_column('royalties', 'pagamento_manual_por',
    "BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID do usuario que marcou como pagamento manual'");

CALL choppon_add_column('royalties', 'pagamento_manual_em',
    "DATETIME NULL DEFAULT NULL COMMENT 'Data/hora em que foi marcado como pagamento manual'");

CALL choppon_add_column('royalties', 'pagamento_manual_obs',
    "TEXT NULL DEFAULT NULL COMMENT 'Observacao do pagamento manual'");

CALL choppon_add_column('royalties', 'pagamento_manual_comprovante',
    "VARCHAR(500) NULL DEFAULT NULL COMMENT 'Caminho do comprovante de pagamento manual'");

-- ── 5. Colunas gerais de rastreio ─────────────────────────────────────────────
CALL choppon_add_column('royalties', 'tipo_cobranca',
    "VARCHAR(50) NULL DEFAULT NULL COMMENT 'mercadopago | stripe | cora | asaas | manual'");

CALL choppon_add_column('royalties', 'forma_pagamento',
    "VARCHAR(50) NULL DEFAULT NULL COMMENT 'boleto_pix | cartao_pix | todos'");

CALL choppon_add_column('royalties', 'email_cobranca',
    "VARCHAR(255) NULL DEFAULT NULL COMMENT 'E-mail principal para cobranca'");

CALL choppon_add_column('royalties', 'emails_adicionais',
    "TEXT NULL DEFAULT NULL COMMENT 'E-mails adicionais separados por virgula'");

CALL choppon_add_column('royalties', 'data_vencimento',
    "DATE NULL DEFAULT NULL COMMENT 'Data de vencimento da cobranca'");

CALL choppon_add_column('royalties', 'boleto_url',
    "TEXT NULL DEFAULT NULL COMMENT 'URL do boleto (Cora/Asaas)'");

CALL choppon_add_column('royalties', 'link_gerado_em',
    "DATETIME NULL DEFAULT NULL COMMENT 'Data/hora em que o link de pagamento foi gerado'");

CALL choppon_add_column('royalties', 'enviado_em',
    "DATETIME NULL DEFAULT NULL COMMENT 'Data/hora em que o e-mail de cobranca foi enviado'");

-- ── 6. Colunas de pagamento (usadas pelo royalties_actions.php) ───────────────
CALL choppon_add_column('royalties', 'data_pagamento',
    "DATE NULL DEFAULT NULL COMMENT 'Data efetiva do pagamento'");

CALL choppon_add_column('royalties', 'valor_pago',
    "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Valor efetivamente pago'");

CALL choppon_add_column('royalties', 'observacoes_pagamento',
    "TEXT NULL DEFAULT NULL COMMENT 'Observacoes do pagamento'");

-- ── Remover procedure auxiliar de colunas ─────────────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_column;

-- ── 7. Procedure auxiliar para CREATE INDEX condicional ───────────────────────
DROP PROCEDURE IF EXISTS choppon_add_index;

DELIMITER $$
CREATE PROCEDURE choppon_add_index(
    IN p_table VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    IN p_index VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    IN p_cols  VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci
)
BEGIN
    DECLARE v_db VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci;
    SET v_db = DATABASE();

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA COLLATE utf8_general_ci = v_db
          AND TABLE_NAME   COLLATE utf8_general_ci = p_table
          AND INDEX_NAME   COLLATE utf8_general_ci = p_index
    ) THEN
        SET @ddl = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '`(', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ── 8. Índices para performance nas consultas do webhook ──────────────────────
CALL choppon_add_index('royalties', 'idx_royalties_mp_preference_id', '`mp_preference_id`');
CALL choppon_add_index('royalties', 'idx_royalties_mp_payment_id',    '`mp_payment_id`');
CALL choppon_add_index('royalties', 'idx_royalties_data_vencimento',  '`data_vencimento`');
CALL choppon_add_index('royalties', 'idx_royalties_tipo_cobranca',    '`tipo_cobranca`');

-- ── Remover procedure auxiliar de índices ─────────────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_index;

-- ── 9. Tabela de log de webhooks de royalties ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `royalties_webhook_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `royalty_id`    BIGINT UNSIGNED NULL DEFAULT NULL
                  COMMENT 'ID do royalty conciliado (NULL se nao encontrado)',
  `gateway`       VARCHAR(50) NOT NULL DEFAULT 'mercadopago'
                  COMMENT 'mercadopago | stripe | cora | asaas',
  `event_type`    VARCHAR(100) NULL DEFAULT NULL
                  COMMENT 'Tipo do evento: payment.updated, etc',
  `payment_id`    VARCHAR(255) NULL DEFAULT NULL
                  COMMENT 'ID do pagamento no gateway',
  `status`        VARCHAR(100) NULL DEFAULT NULL
                  COMMENT 'Status retornado pelo gateway',
  `valor`         DECIMAL(10,2) NULL DEFAULT NULL
                  COMMENT 'Valor do pagamento',
  `processado`    TINYINT(1) NOT NULL DEFAULT 0
                  COMMENT '1=processado com sucesso, 0=ignorado/erro',
  `erro`          TEXT NULL DEFAULT NULL
                  COMMENT 'Mensagem de erro se falhou',
  `payload`       MEDIUMTEXT NULL DEFAULT NULL
                  COMMENT 'Payload completo do webhook (JSON armazenado como texto)',
  `ip_origem`     VARCHAR(45) NULL DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rwl_royalty_id`  (`royalty_id`),
  KEY `idx_rwl_payment_id`  (`payment_id`),
  KEY `idx_rwl_processado`  (`processado`),
  KEY `idx_rwl_created_at`  (`created_at`),
  CONSTRAINT `fk_rwl_royalty` FOREIGN KEY (`royalty_id`)
    REFERENCES `royalties`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
  COMMENT='Log de todos os webhooks recebidos para royalties';

-- ── 10. Atualizar royalties_historico para incluir novos tipos de acao ────────
ALTER TABLE `royalties_historico`
  MODIFY COLUMN `acao` VARCHAR(100) NOT NULL
    COMMENT 'criacao|geracao_link_mp|geracao_boleto|envio_email|pagamento_webhook|pagamento_manual|edicao|cancelamento|reativacao';

-- ── 11. Diretorio de comprovantes (comentario informativo) ────────────────────
-- Criar no servidor: mkdir -p uploads/royalties/comprovantes && chmod 755 uploads/royalties/comprovantes

-- ============================================================
-- Fim da migracao
-- ============================================================
