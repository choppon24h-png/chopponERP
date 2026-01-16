-- =====================================================
-- FIX: Adicionar coluna estabelecimento_id na tabela asaas_logs
-- Data: 2026-01-15
-- Descrição: Corrige erro "Unknown column 'estabelecimento_id'"
-- =====================================================

-- Verificar se a coluna já existe antes de adicionar
SET @dbname = DATABASE();
SET @tablename = 'asaas_logs';
SET @columnname = 'estabelecimento_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1;', -- Coluna já existe, não fazer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT(20) NULL AFTER status;')
));

PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Adicionar índice se não existir
SET @indexname = 'idx_estabelecimento_id';
SET @preparedStatement2 = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT 1;', -- Índice já existe
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (', @columnname, ');')
));

PREPARE addIndexIfNotExists FROM @preparedStatement2;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;

-- Verificar resultado
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'asaas_logs'
    AND COLUMN_NAME = 'estabelecimento_id';

-- Se retornar 1 linha, a coluna foi criada com sucesso!
