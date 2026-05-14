-- ============================================================
-- MIGRAÇÃO: Royalties v2 — Conciliação MP + Pagamento Manual
-- Versão: 2.0
-- Data: 2026-05-13
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

-- ── 2. Colunas para Mercado Pago (link de pagamento + conciliação) ─────────────
ALTER TABLE `royalties`
  ADD COLUMN IF NOT EXISTS `mp_preference_id`   VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'ID da preferência de pagamento no Mercado Pago',
  ADD COLUMN IF NOT EXISTS `mp_payment_id`       VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'ID do pagamento confirmado pelo webhook do MP',
  ADD COLUMN IF NOT EXISTS `mp_payment_status`   VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Status retornado pelo MP: approved, pending, rejected',
  ADD COLUMN IF NOT EXISTS `mp_payment_method`   VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Método de pagamento: pix, credit_card, boleto',
  ADD COLUMN IF NOT EXISTS `mp_payment_detail`   VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Detalhe do pagamento (banco, bandeira, etc)',
  ADD COLUMN IF NOT EXISTS `mp_link_pagamento`   TEXT NULL DEFAULT NULL
    COMMENT 'URL do link de pagamento gerado no MP',
  ADD COLUMN IF NOT EXISTS `mp_webhook_payload`  MEDIUMTEXT NULL DEFAULT NULL
    COMMENT 'Payload completo do último webhook recebido',
  ADD COLUMN IF NOT EXISTS `mp_conciliado_em`    DATETIME NULL DEFAULT NULL
    COMMENT 'Data/hora em que o pagamento foi conciliado via webhook';

-- ── 3. Colunas para pagamento manual ──────────────────────────────────────────
ALTER TABLE `royalties`
  ADD COLUMN IF NOT EXISTS `pagamento_manual_por`        BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'ID do usuário que marcou como pagamento manual',
  ADD COLUMN IF NOT EXISTS `pagamento_manual_em`         DATETIME NULL DEFAULT NULL
    COMMENT 'Data/hora em que foi marcado como pagamento manual',
  ADD COLUMN IF NOT EXISTS `pagamento_manual_obs`        TEXT NULL DEFAULT NULL
    COMMENT 'Observação do pagamento manual',
  ADD COLUMN IF NOT EXISTS `pagamento_manual_comprovante` VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Caminho do comprovante de pagamento manual';

-- ── 4. Colunas gerais de rastreio ─────────────────────────────────────────────
ALTER TABLE `royalties`
  ADD COLUMN IF NOT EXISTS `tipo_cobranca`      VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'mercadopago | stripe | cora | asaas | manual',
  ADD COLUMN IF NOT EXISTS `forma_pagamento`    VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'boleto_pix | cartao_pix | todos',
  ADD COLUMN IF NOT EXISTS `email_cobranca`     VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'E-mail principal para cobrança',
  ADD COLUMN IF NOT EXISTS `emails_adicionais`  TEXT NULL DEFAULT NULL
    COMMENT 'E-mails adicionais separados por vírgula',
  ADD COLUMN IF NOT EXISTS `data_vencimento`    DATE NULL DEFAULT NULL
    COMMENT 'Data de vencimento da cobrança',
  ADD COLUMN IF NOT EXISTS `boleto_url`         TEXT NULL DEFAULT NULL
    COMMENT 'URL do boleto (Cora/Asaas)',
  ADD COLUMN IF NOT EXISTS `link_gerado_em`     DATETIME NULL DEFAULT NULL
    COMMENT 'Data/hora em que o link de pagamento foi gerado',
  ADD COLUMN IF NOT EXISTS `enviado_em`         DATETIME NULL DEFAULT NULL
    COMMENT 'Data/hora em que o e-mail de cobrança foi enviado';

-- ── 5. Índices para performance nas consultas do webhook ──────────────────────
CREATE INDEX IF NOT EXISTS idx_royalties_mp_preference_id ON `royalties`(`mp_preference_id`);
CREATE INDEX IF NOT EXISTS idx_royalties_mp_payment_id    ON `royalties`(`mp_payment_id`);
CREATE INDEX IF NOT EXISTS idx_royalties_data_vencimento  ON `royalties`(`data_vencimento`);
CREATE INDEX IF NOT EXISTS idx_royalties_tipo_cobranca    ON `royalties`(`tipo_cobranca`);

-- ── 6. Tabela de log de webhooks de royalties ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `royalties_webhook_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `royalty_id`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID do royalty conciliado (NULL se não encontrado)',
  `gateway`       VARCHAR(50) NOT NULL DEFAULT 'mercadopago' COMMENT 'mercadopago | stripe | cora | asaas',
  `event_type`    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Tipo do evento: payment.updated, etc',
  `payment_id`    VARCHAR(255) NULL DEFAULT NULL COMMENT 'ID do pagamento no gateway',
  `status`        VARCHAR(100) NULL DEFAULT NULL COMMENT 'Status retornado pelo gateway',
  `valor`         DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Valor do pagamento',
  `processado`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=processado com sucesso, 0=ignorado/erro',
  `erro`          TEXT NULL DEFAULT NULL COMMENT 'Mensagem de erro se falhou',
  `payload`       MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Payload completo do webhook',
  `ip_origem`     VARCHAR(45) NULL DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rwl_royalty_id`  (`royalty_id`),
  KEY `idx_rwl_payment_id`  (`payment_id`),
  KEY `idx_rwl_processado`  (`processado`),
  KEY `idx_rwl_created_at`  (`created_at`),
  CONSTRAINT `fk_rwl_royalty` FOREIGN KEY (`royalty_id`) REFERENCES `royalties`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de todos os webhooks recebidos para royalties';

-- ── 7. Atualizar tabela royalties_historico para incluir novos tipos de ação ──
ALTER TABLE `royalties_historico`
  MODIFY COLUMN `acao` VARCHAR(100) NOT NULL
    COMMENT 'criacao|geracao_link_mp|geracao_boleto|envio_email|pagamento_webhook|pagamento_manual|edicao|cancelamento|reativacao';

-- ── 8. Diretório de comprovantes (comentário informativo) ─────────────────────
-- Criar no servidor: mkdir -p uploads/royalties/comprovantes && chmod 755 uploads/royalties/comprovantes

-- ============================================================
-- Fim da migração
-- ============================================================
