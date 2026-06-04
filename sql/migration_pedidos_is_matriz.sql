-- ============================================================
-- MIGRAÇÃO: Adicionar coluna is_matriz em estabelecimentos
-- (se ainda não existir — compatível com MySQL 5.7 / Hostgator)
-- ============================================================
-- Execute este arquivo no phpMyAdmin antes de usar o módulo de Pedidos
-- com filtro por tipo de estabelecimento.

-- Adicionar coluna is_matriz (0=Franqueado, 1=Matriz)
ALTER TABLE `estabelecimentos`
ADD COLUMN `is_matriz` TINYINT(1) NOT NULL DEFAULT 0
COMMENT '1=Matriz (recebe royalties), 0=Franqueado';

-- Marcar o primeiro estabelecimento como Matriz (ajuste o WHERE conforme necessário)
-- UPDATE `estabelecimentos` SET is_matriz = 1 WHERE id = 1;
-- UPDATE `estabelecimentos` SET is_matriz = 1 WHERE LOWER(name) LIKE '%matriz%' OR LOWER(name) LIKE '%jaboticatubas%';
