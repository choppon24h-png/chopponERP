-- ============================================================
-- MIGRAÇÃO: Pedidos de Estoque — Campo Pagamento + Edição
-- Versão: 1.0.0  (compatível com MariaDB 5.7 / MySQL 5.7)
-- Descrição: Adiciona coluna `pagamento` em estoque_pedidos
--            para registrar a forma de pagamento do pedido.
-- NOTA: ADD COLUMN IF NOT EXISTS não existe no MariaDB 5.7.
--       Usamos PROCEDURE temporária + INFORMATION_SCHEMA.
-- ============================================================

DROP PROCEDURE IF EXISTS _choppon_add_col_pag;

DELIMITER $$

CREATE PROCEDURE _choppon_add_col_pag(
    IN p_table  VARCHAR(64),
    IN p_col    VARCHAR(64),
    IN p_def    TEXT
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

-- ── 1. Coluna de forma de pagamento ─────────────────────────
CALL _choppon_add_col_pag(
    'estoque_pedidos',
    'pagamento',
    "VARCHAR(60) NULL DEFAULT 'pix' COMMENT 'Forma de pagamento: pix, debito, credito, entrada_50_50'"
);

-- ── 2. Limpar procedure temporária ──────────────────────────
DROP PROCEDURE IF EXISTS _choppon_add_col_pag;

-- ── 3. Índice (ignorar erro se já existir) ───────────────────
-- Execute separadamente se o índice já existir.
ALTER TABLE `estoque_pedidos`
  ADD INDEX `idx_estoque_ped_pagamento` (`pagamento`);

-- ── 4. Preencher registros existentes com 'pix' ──────────────
UPDATE `estoque_pedidos`
  SET `pagamento` = 'pix'
  WHERE `pagamento` IS NULL;

-- ── 5. Verificação final ─────────────────────────────────────
SELECT
    pagamento,
    COUNT(*) AS total
FROM estoque_pedidos
GROUP BY pagamento
ORDER BY total DESC;
