-- ============================================================
-- MIGRAÇÃO: Campos de Lançamento Bancário Automático
-- Versão: 1.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- OBJETIVO:
--   Quando o pedido atinge status PAID/SUCCESSFUL/APPROVED,
--   o sistema lança automaticamente o valor líquido (bruto - taxa)
--   na conta bancária vinculada ao meio de pagamento.
--
--   Esta migração adiciona:
--   1. `taxa_aplicada` na tabela `order` — registra o valor da taxa
--      descontada no momento do lançamento
--   2. `referencia_pedido_id` na tabela `movimentacoes_bancarias` —
--      vincula a movimentação ao pedido de origem
--
-- PRÉ-REQUISITOS:
--   - migration_financeiro_contas_bancarias.sql (cria movimentacoes_bancarias)
--   - migration_formas_pagamento_conta_bancaria.sql (cria lancamento_bancario_id em order)
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- ============================================================

-- ── BLOCO 1: taxa_aplicada na tabela order ────────────────────────────────────
-- Armazena o valor (R$) da taxa descontada no momento do lançamento bancário.
-- Preenchido automaticamente pelo LancamentoBancarioHelper quando PAID.
ALTER TABLE `order`
  ADD COLUMN `taxa_aplicada` DECIMAL(10,2) NULL DEFAULT NULL
  COMMENT 'Valor da taxa descontada no lançamento bancário (R$)'
  AFTER `lancamento_bancario_id`;

-- ── BLOCO 2: referencia_pedido_id na tabela movimentacoes_bancarias ───────────
-- Vincula a movimentação bancária ao pedido de origem para rastreabilidade.
ALTER TABLE `movimentacoes_bancarias`
  ADD COLUMN `referencia_pedido_id` INT(11) UNSIGNED NULL DEFAULT NULL
  COMMENT 'ID do pedido (order) que gerou esta movimentação'
  AFTER `created_by`;

-- ── BLOCO 3: índice para busca por pedido ─────────────────────────────────────
ALTER TABLE `movimentacoes_bancarias`
  ADD INDEX `idx_mb_referencia_pedido` (`referencia_pedido_id`);

-- ============================================================
-- ROLLBACK (desfaz tudo, execute em ordem inversa se necessário)
-- ============================================================
-- ALTER TABLE `movimentacoes_bancarias` DROP INDEX `idx_mb_referencia_pedido`;
-- ALTER TABLE `movimentacoes_bancarias` DROP COLUMN `referencia_pedido_id`;
-- ALTER TABLE `order` DROP COLUMN `taxa_aplicada`;

-- ============================================================
-- VALIDAÇÃO (execute após a migração para confirmar)
-- ============================================================
-- DESCRIBE `order`;
-- DESCRIBE `movimentacoes_bancarias`;
-- SELECT COUNT(*) AS total_pedidos_com_lancamento FROM `order` WHERE lancamento_bancario_id IS NOT NULL;
-- SELECT COUNT(*) AS total_movimentacoes_com_pedido FROM `movimentacoes_bancarias` WHERE referencia_pedido_id IS NOT NULL;
