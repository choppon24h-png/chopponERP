-- ============================================================
-- MIGRAÇÃO: Correção dos tipos de colunas na tabela bebidas
-- Versão: v3.0.3
-- Data: 2026-03-15
-- Descrição: Altera os campos value e promotional_value de
--            DOUBLE para DECIMAL(10,2) para garantir precisão
--            monetária e evitar imprecisão de ponto flutuante.
--
-- ATENÇÃO: Execute este script UMA VEZ no banco de dados.
--          Faça backup antes de executar.
-- ============================================================

-- Corrigir tipo dos campos monetários da tabela bebidas
ALTER TABLE `bebidas`
    MODIFY COLUMN `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    MODIFY COLUMN `promotional_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- Verificar se há valores corrompidos (muito grandes) e corrigi-los
-- Valores acima de 99999999.99 são claramente resultado do bug
UPDATE `bebidas`
SET `value` = 0.00
WHERE `value` > 99999.99 OR `value` < 0;

UPDATE `bebidas`
SET `promotional_value` = 0.00
WHERE `promotional_value` > 99999.99 OR `promotional_value` < 0;

-- Confirmar alterações
SELECT 
    id,
    name,
    value,
    promotional_value
FROM bebidas
ORDER BY id;
