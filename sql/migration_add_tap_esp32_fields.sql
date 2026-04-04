-- ============================================================
-- Migration: Adicionar campos esp32_mac, last_call e senha na tabela tap
-- Versão: 1.2
-- Data: 2026-04
-- Motivo:
--   O campo esp32_mac é necessário para o app Android sincronizar
--   o MAC do ESP32 ao inicializar, evitando o bug de loop infinito
--   de reconexão quando o tablet é trocado de máquina.
--   Os campos last_call e senha são usados pelo admin/taps.php mas
--   não estavam presentes no database.sql original.
-- ============================================================

-- Adicionar esp32_mac (MAC BLE do ESP32 vinculado à torneira)
ALTER TABLE `tap`
    ADD COLUMN IF NOT EXISTS `esp32_mac` VARCHAR(17) NULL DEFAULT NULL
        COMMENT 'MAC BLE do ESP32 (ex: DC:B4:D9:99:B8:E0) — sincronizado com app Android'
        AFTER `android_id`;

-- Adicionar last_call (última vez que o app consultou esta TAP)
ALTER TABLE `tap`
    ADD COLUMN IF NOT EXISTS `last_call` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Última consulta do app Android via verify_tap.php'
        AFTER `esp32_mac`;

-- Adicionar senha (hash bcrypt para autenticação da TAP no BLE)
ALTER TABLE `tap`
    ADD COLUMN IF NOT EXISTS `senha` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Hash bcrypt da senha de autenticação BLE'
        AFTER `last_call`;

-- Índice para busca rápida por esp32_mac (útil para verify_tap_mac.php)
ALTER TABLE `tap`
    ADD INDEX IF NOT EXISTS `idx_esp32_mac` (`esp32_mac`);

-- ============================================================
-- VERIFICAÇÃO: após rodar esta migration, confirme com:
--   SHOW COLUMNS FROM tap;
-- Você deve ver os campos: esp32_mac, last_call, senha
-- ============================================================
