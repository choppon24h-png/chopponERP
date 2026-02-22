-- ============================================
-- Migration: SumUp Cloud API v2.0
-- Versão: 2.0
-- Data: 2026-02-22
-- Descrição: Adiciona colunas para affiliate_key,
--            estabelecimento_id e merchant_code
-- ============================================

-- Adicionar affiliate_key e estabelecimento_id na tabela payment
ALTER TABLE `payment`
    ADD COLUMN IF NOT EXISTS `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Estabelecimento vinculado a esta configuração de pagamento'
        AFTER `debit`,
    ADD COLUMN IF NOT EXISTS `affiliate_key` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Chave de afiliado SumUp — obrigatória para Cloud API (leitoras Solo)'
        AFTER `estabelecimento_id`,
    ADD COLUMN IF NOT EXISTS `merchant_code` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Merchant Code SumUp (sobrescreve a constante do config.php se preenchido)'
        AFTER `affiliate_key`;

-- Adicionar estabelecimento_id na tabela sumup_readers
ALTER TABLE `sumup_readers`
    ADD COLUMN IF NOT EXISTS `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Estabelecimento ao qual esta leitora está vinculada'
        AFTER `last_activity`;

-- Índices para melhorar performance
ALTER TABLE `sumup_readers`
    ADD INDEX IF NOT EXISTS `idx_estabelecimento` (`estabelecimento_id`);

ALTER TABLE `payment`
    ADD INDEX IF NOT EXISTS `idx_payment_estab` (`estabelecimento_id`);

-- ============================================
-- Fim da Migration
-- ============================================
