-- ============================================================
-- MIGRAÇÃO: Estoque Multi-Estabelecimento
-- Versão: 1.2.0  (compatível com MariaDB 5.7 / MySQL 5.7)
-- Descrição: Adiciona estabelecimento_id nas tabelas de estoque
--            e inventário/patrimônio para separar os dados de
--            cada unidade franqueada.
-- NOTA: ADD COLUMN IF NOT EXISTS não existe no MariaDB 5.7.
--       Usamos PROCEDURE temporária + INFORMATION_SCHEMA.
-- ============================================================

DROP PROCEDURE IF EXISTS _choppon_add_col;

DELIMITER $$

CREATE PROCEDURE _choppon_add_col(
    IN p_table  VARCHAR(64),
    IN p_col    VARCHAR(64),
    IN p_def    VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM   INFORMATION_SCHEMA.COLUMNS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = p_table
          AND  COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- ── 1. estoque_produtos ──────────────────────────────────────
CALL _choppon_add_col('estoque_produtos',   'estabelecimento_id', 'INT(11) NULL COMMENT "Unidade dona deste produto de estoque"');

-- ── 2. estoque_movimentacoes ─────────────────────────────────
CALL _choppon_add_col('estoque_movimentacoes', 'estabelecimento_id', 'INT(11) NULL COMMENT "Unidade onde ocorreu a movimentacao"');

-- ── 3. estoque_pedidos (coluna de origem) ────────────────────
CALL _choppon_add_col('estoque_pedidos',    'estab_origem_id',    'INT(11) NULL COMMENT "Unidade que criou o pedido"');

-- ── 4. patrimônio (inventário) ───────────────────────────────
CALL _choppon_add_col('patrimonio',         'estabelecimento_id', 'BIGINT UNSIGNED NULL COMMENT "Unidade a que pertence este item patrimonial"');

-- ── Limpar procedure temporária ──────────────────────────────
DROP PROCEDURE IF EXISTS _choppon_add_col;

-- ── 5. Índices (ignorar erro se já existir) ──────────────────
-- MariaDB 5.7 não tem CREATE INDEX IF NOT EXISTS.
-- Execute cada bloco separadamente se algum índice já existir.

ALTER TABLE `estoque_produtos`
  ADD INDEX `idx_estoque_produtos_estab` (`estabelecimento_id`);

ALTER TABLE `estoque_movimentacoes`
  ADD INDEX `idx_estoque_mov_estab` (`estabelecimento_id`);

ALTER TABLE `estoque_pedidos`
  ADD INDEX `idx_estoque_ped_origem` (`estab_origem_id`);

ALTER TABLE `patrimonio`
  ADD INDEX `idx_patrimonio_estab` (`estabelecimento_id`);

-- ── 6. Preencher registros existentes com estab = 1 (matriz) ─
UPDATE `estoque_produtos`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

UPDATE `estoque_movimentacoes`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

UPDATE `estoque_pedidos`
  SET `estab_origem_id` = 1
  WHERE `estab_origem_id` IS NULL;

UPDATE `patrimonio`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

-- ── 7. Verificação final ─────────────────────────────────────
SELECT 'estoque_produtos' AS tabela,
       COUNT(*) AS total,
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END) AS sem_estab
FROM estoque_produtos
UNION ALL
SELECT 'estoque_movimentacoes',
       COUNT(*),
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END)
FROM estoque_movimentacoes
UNION ALL
SELECT 'estoque_pedidos (origem)',
       COUNT(*),
       SUM(CASE WHEN estab_origem_id IS NULL THEN 1 ELSE 0 END)
FROM estoque_pedidos
UNION ALL
SELECT 'patrimonio',
       COUNT(*),
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END)
FROM patrimonio;
