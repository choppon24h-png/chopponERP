-- ============================================================
-- MIGRAÇÃO: Royalties — Estabelecimento Recebedor (Matriz)
-- Versão: 1.1 — Corrigido erro #1267 collation mismatch
-- Data: 2026-05-14
--
-- CORREÇÃO v1.1:
--   O banco usa utf8_general_ci e as strings literais usam
--   utf8_unicode_ci por padrão, causando #1267.
--   Solução: COLLATE utf8_general_ci em TODAS as comparações
--   de string dentro das procedures.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Procedure auxiliar para ADD COLUMN condicional ────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_col_recebedor;
DELIMITER $$
CREATE PROCEDURE choppon_add_col_recebedor(
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
        WHERE TABLE_SCHEMA COLLATE utf8_general_ci = v_db   COLLATE utf8_general_ci
          AND TABLE_NAME   COLLATE utf8_general_ci = p_table COLLATE utf8_general_ci
          AND COLUMN_NAME  COLLATE utf8_general_ci = p_column COLLATE utf8_general_ci
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ── 1. Coluna is_matriz em estabelecimentos ───────────────────────────────────
CALL choppon_add_col_recebedor(
    'estabelecimentos',
    'is_matriz',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Matriz (recebe royalties), 0=Franqueado'"
);

-- ── 2. Coluna estabelecimento_recebedor_id em royalties ───────────────────────
CALL choppon_add_col_recebedor(
    'royalties',
    'estabelecimento_recebedor_id',
    "BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Estabelecimento que recebe o royalty (normalmente a matriz)'"
);

-- ── 3. Limpeza da procedure ───────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS choppon_add_col_recebedor;

-- ── 4. Marcar Jaboticatubas como matriz ───────────────────────────────────────
-- Ajuste o WHERE se necessário para refletir o nome/ID correto no seu banco.
UPDATE estabelecimentos
SET is_matriz = 1
WHERE LOWER(name) LIKE '%jaboticatubas%'
   OR id = 1;

-- ── 5. Preencher recebedor nos royalties existentes ───────────────────────────
UPDATE royalties r
JOIN (
    SELECT id FROM estabelecimentos WHERE is_matriz = 1 LIMIT 1
) m ON 1=1
SET r.estabelecimento_recebedor_id = m.id
WHERE r.estabelecimento_recebedor_id IS NULL;

-- ── Verificação final ─────────────────────────────────────────────────────────
SELECT id, name, is_matriz, banco_padrao_royalties
FROM estabelecimentos
ORDER BY is_matriz DESC, id ASC;

SELECT
    r.id,
    ep.name AS pagador,
    er.name AS recebedor,
    r.status
FROM royalties r
JOIN  estabelecimentos ep ON ep.id = r.estabelecimento_id
LEFT JOIN estabelecimentos er ON er.id = r.estabelecimento_recebedor_id
ORDER BY r.id DESC
LIMIT 10;
