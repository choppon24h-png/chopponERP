-- ============================================================
-- Migration: adiciona coluna total_pulsos na tabela `order`
-- Finalidade: armazenar o total de pulsos QP: reportado pelo
--             ESP32 ao encerrar a dosagem (auditoria de fluxo).
-- ============================================================

ALTER TABLE `order`
    ADD COLUMN IF NOT EXISTS `total_pulsos` INT UNSIGNED DEFAULT 0
        COMMENT 'Total de pulsos contados pelo sensor de fluxo (QP: do ESP32)'
        AFTER `qtd_liberada`;
