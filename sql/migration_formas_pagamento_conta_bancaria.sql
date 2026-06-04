-- ============================================================
-- MIGRAÇÃO: Vincular Conta Bancária às Formas de Pagamento
-- Versão: 1.0.0 | MySQL 5.7 / MariaDB | Choppon ERP
-- Data: 2026-06-04
--
-- OBJETIVO:
--   1. Adicionar conta_bancaria_id em formas_pagamento
--   2. Adicionar metodos_aceitos (JSON) em formas_pagamento
--   3. Adicionar coluna lancamento_bancario_id em `order`
--      para rastrear qual movimentação foi gerada
--
-- PRÉ-REQUISITO:
--   Execute migration_financeiro_contas_bancarias.sql antes
--   para garantir que a tabela contas_bancarias existe.
--
-- ⚠️  COMPATIBILIDADE MySQL 5.7:
--   Não usa IF NOT EXISTS em ALTER TABLE.
--   Verifique antes: DESCRIBE formas_pagamento;
-- ============================================================

-- ── 1. Adicionar conta_bancaria_id em formas_pagamento ────────────────────────
ALTER TABLE `formas_pagamento`
  ADD COLUMN `conta_bancaria_id` INT(11) UNSIGNED NULL DEFAULT NULL
    COMMENT 'Conta bancária que recebe os valores deste meio de pagamento'
    AFTER `ativo`;

-- ── 2. Adicionar metodos_aceitos em formas_pagamento ─────────────────────────
-- Armazena lista separada por vírgula: pix,credit,debit,cash
ALTER TABLE `formas_pagamento`
  ADD COLUMN `metodos_aceitos` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Métodos de pagamento aceitos: pix,credit,debit,cash (separados por vírgula)'
    AFTER `conta_bancaria_id`;

-- ── 3. Adicionar nome/apelido à forma de pagamento ───────────────────────────
ALTER TABLE `formas_pagamento`
  ADD COLUMN `nome` VARCHAR(120) NULL DEFAULT NULL
    COMMENT 'Nome/apelido da forma de pagamento (ex: PIX Principal, Cartão Bradesco)'
    AFTER `estabelecimento_id`;

-- ── 4. Adicionar lancamento_bancario_id em order ──────────────────────────────
-- Rastreia qual movimentação bancária foi gerada para cada pedido finalizado
ALTER TABLE `order`
  ADD COLUMN `lancamento_bancario_id` INT(11) UNSIGNED NULL DEFAULT NULL
    COMMENT 'ID da movimentação bancária gerada ao finalizar o pedido'
    AFTER `forma_pagamento_id`;

-- ── Verificação final ─────────────────────────────────────────────────────────
SELECT 'formas_pagamento' AS tabela, COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'formas_pagamento'
  AND COLUMN_NAME IN ('conta_bancaria_id','metodos_aceitos','nome')
ORDER BY ORDINAL_POSITION;
