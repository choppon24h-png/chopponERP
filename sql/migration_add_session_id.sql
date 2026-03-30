-- ============================================================
-- Migration: adiciona coluna session_id na tabela `order`
-- Finalidade: armazenar o ID da sessão de dispensação único
--             para rastrear cada tentativa de liberação de chopp
-- ============================================================

ALTER TABLE `order`
    ADD COLUMN IF NOT EXISTS `session_id` VARCHAR(36) NULL DEFAULT NULL
        COMMENT 'ID único da sessão de dispensação (UUID format)'
        AFTER `checkout_id`;
