-- ============================================================
-- Migration: Adicionar coluna affiliate_app_id na tabela payment
-- Versão: 1.0
-- Data: 2026-02-24
-- Compatível: MySQL 5.7+ / phpMyAdmin
--
-- INSTRUÇÃO:
--   Execute este script no phpMyAdmin do seu banco de dados.
--   Se a coluna já existir, o MySQL retornará erro #1060 (Duplicate column).
--   Nesse caso, ignore o erro — a coluna já está presente.
--
-- CONTEXTO:
--   A SumUp Cloud API exige o campo affiliate.app_id em TODOS os checkouts
--   via Cloud API (leitoras Solo). Sem este campo, a API retorna HTTP 422:
--   {"errors":{"affiliate":{"app_id":["can't be blank"]}}}
--   Referência: https://developer.sumup.com/tools/authorization/affiliate-keys/
-- ============================================================

-- ── 1. Adicionar coluna affiliate_app_id na tabela payment ───────────────────
ALTER TABLE `payment`
    ADD COLUMN `affiliate_app_id` VARCHAR(120) NULL DEFAULT NULL
        COMMENT 'App Identifier cadastrado na Affiliate Key SumUp (ex: CHOPPONALMEIDA)';

-- ── 2. Adicionar coluna merchant_code (se ainda não existir) ─────────────────
-- (Necessário para versões antigas do sistema que não tinham esta coluna)
ALTER TABLE `payment`
    ADD COLUMN `merchant_code` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Merchant Code SumUp (sobrescreve constante do config.php se preenchido)';

-- ── 3. Verificar o resultado ─────────────────────────────────────────────────
-- Execute esta query para confirmar que as colunas foram adicionadas:
-- DESCRIBE payment;

-- ── 4. Atualizar os valores (opcional — pode fazer pela tela de Pagamentos) ──
-- Substitua os valores abaixo pelos seus dados reais antes de executar:
--
-- UPDATE `payment` SET
--     `affiliate_key`    = 'sup_afk_bULTbTDP0leInwIXud28LYYVmYiZiKYy',
--     `affiliate_app_id` = 'CHOPPONALMEIDA'
-- WHERE id = (SELECT MIN(id) FROM payment LIMIT 1);
--
-- OU use a tela de configuração em:
-- https://ochoppoficial.com.br/admin/pagamentos.php

-- ============================================================
-- Fim da Migration
-- ============================================================
