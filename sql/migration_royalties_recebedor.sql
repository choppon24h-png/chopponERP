-- ============================================================
-- MIGRAÇÃO: Royalties — Estabelecimento Recebedor (Matriz)
-- Versão: 1.0
-- Data: 2026-05-14
--
-- OBJETIVO:
--   Implementar regra de pagamento de royalties direcionado
--   sempre para o estabelecimento MATRIZ (Jaboticatubas).
--   Franqueados pagam PARA a matriz, usando o gateway da matriz.
--
-- MUDANÇAS:
--   1. estabelecimentos.is_matriz  — marca qual é a matriz
--   2. royalties.estabelecimento_recebedor_id — quem RECEBE o royalty
--      (padrão: a matriz, mas pode ser outro em casos especiais)
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Procedure auxiliar para ADD COLUMN condicional ────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_col_recebedor;
DELIMITER $$
CREATE PROCEDURE choppon_add_col_recebedor(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ── 1. Coluna is_matriz em estabelecimentos ───────────────────────────────────
-- 1 = este estabelecimento é a MATRIZ (recebe royalties)
-- 0 = franqueado (paga royalties para a matriz)
CALL choppon_add_col_recebedor(
    'estabelecimentos',
    'is_matriz',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Matriz (recebe royalties), 0=Franqueado (paga royalties)'"
);

-- ── 2. Coluna estabelecimento_recebedor_id em royalties ───────────────────────
-- Aponta para o estabelecimento que RECEBE o pagamento (normalmente a matriz).
-- NULL = usar a matriz padrão (is_matriz = 1).
CALL choppon_add_col_recebedor(
    'royalties',
    'estabelecimento_recebedor_id',
    "BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Estabelecimento que recebe o royalty (normalmente a matriz). NULL = usar is_matriz=1'"
);

-- ── 3. Marcar Jaboticatubas como matriz ───────────────────────────────────────
-- Ajuste o id=1 se necessário para refletir o ID real da unidade Jaboticatubas.
-- O script detecta automaticamente pelo nome para evitar erro de ID errado.
UPDATE estabelecimentos
SET is_matriz = 1
WHERE LOWER(name) LIKE '%jaboticatubas%'
   OR id = 1;

-- ── 4. Preencher estabelecimento_recebedor_id nos royalties existentes ─────────
-- Todos os royalties existentes passam a ter o recebedor = matriz.
UPDATE royalties r
JOIN (
    SELECT id FROM estabelecimentos WHERE is_matriz = 1 LIMIT 1
) m ON 1=1
SET r.estabelecimento_recebedor_id = m.id
WHERE r.estabelecimento_recebedor_id IS NULL;

-- ── 5. Limpeza ────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_col_recebedor;

-- ── Verificação final ─────────────────────────────────────────────────────────
SELECT
    e.id,
    e.name,
    e.is_matriz,
    e.banco_padrao_royalties
FROM estabelecimentos e
ORDER BY e.is_matriz DESC, e.id ASC;

SELECT
    r.id,
    r.estabelecimento_id,
    ep.name AS pagador,
    r.estabelecimento_recebedor_id,
    er.name AS recebedor,
    r.status
FROM royalties r
JOIN estabelecimentos ep ON ep.id = r.estabelecimento_id
LEFT JOIN estabelecimentos er ON er.id = r.estabelecimento_recebedor_id
ORDER BY r.id DESC
LIMIT 10;
