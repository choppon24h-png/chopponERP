-- ============================================================
-- MIGRAÇÃO: contas_pagar — suporte a recorrência / parcelamento
-- Versão:   1.0.0
-- SGBD:     MySQL 5.7.44 | phpMyAdmin | Hostgator
-- Charset:  utf8 | utf8_unicode_ci
-- Data:     2026-06-02
--
-- ⚠️  ANTES DE EXECUTAR:
--   1. Faça backup do banco de dados
--   2. Execute: DESCRIBE contas_pagar;
--      → Confirme que as colunas abaixo NÃO existem ainda:
--        recorrencia_total, recorrencia_parcela, recorrencia_grupo
--   3. Execute: SHOW INDEX FROM contas_pagar;
--      → Confirme que idx_cp_recorrencia_grupo NÃO existe ainda
--   4. Execute em horário de baixo tráfego
-- ============================================================

-- ── SEÇÃO 1: Adicionar colunas de recorrência ────────────────
ALTER TABLE `contas_pagar`
  ADD COLUMN `recorrencia_total`   TINYINT(2)   NULL DEFAULT 1
    COMMENT 'Total de parcelas da recorrência (1 = sem recorrência, 2-24 = parcelado)',
  ADD COLUMN `recorrencia_parcela` TINYINT(2)   NULL DEFAULT 1
    COMMENT 'Número desta parcela dentro da recorrência (1, 2, 3...)',
  ADD COLUMN `recorrencia_grupo`   VARCHAR(36)  NULL DEFAULT NULL
    COMMENT 'UUID que agrupa todas as parcelas de uma mesma recorrência';

-- ── SEÇÃO 2: Preencher registros existentes ──────────────────
-- Todos os lançamentos já existentes são tratados como parcela única
UPDATE `contas_pagar`
  SET `recorrencia_total`   = 1,
      `recorrencia_parcela` = 1,
      `recorrencia_grupo`   = NULL
  WHERE `recorrencia_total` IS NULL;

-- ── SEÇÃO 3: Índice para agrupamento de parcelas ─────────────
ALTER TABLE `contas_pagar`
  ADD INDEX `idx_cp_recorrencia_grupo` (`recorrencia_grupo`);

-- ── SEÇÃO 4: Validação ────────────────────────────────────────
-- Total de registros
SELECT COUNT(*) AS total_registros FROM `contas_pagar`;

-- Verificar distribuição de recorrencia_total
SELECT `recorrencia_total`, COUNT(*) AS qtd
FROM `contas_pagar`
GROUP BY `recorrencia_total`
ORDER BY `recorrencia_total`;

-- Verificar se algum registro ficou NULL (deve ser 0)
SELECT COUNT(*) AS registros_sem_recorrencia
FROM `contas_pagar`
WHERE `recorrencia_total` IS NULL;

-- ============================================================
-- ROLLBACK — Execute APENAS se precisar desfazer
-- ============================================================
/*
ALTER TABLE `contas_pagar`
  DROP INDEX `idx_cp_recorrencia_grupo`;

ALTER TABLE `contas_pagar`
  DROP COLUMN `recorrencia_grupo`,
  DROP COLUMN `recorrencia_parcela`,
  DROP COLUMN `recorrencia_total`;
*/
