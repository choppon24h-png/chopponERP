-- ============================================================
-- MIGRAÇÃO: Novas notificações Telegram
-- Versão: 1.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- ============================================================

-- BLOCO 1: Adicionar coluna notif_acesso_master na telegram_config
-- (Execute apenas se não existir — verifique com: DESCRIBE telegram_config;)
ALTER TABLE `telegram_config`
  ADD COLUMN `notif_acesso_master` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notificar_vencimento`;

-- BLOCO 2: Adicionar coluna telegram_notificado na tabela order
-- (Evita envio duplicado de notificação por pedido)
ALTER TABLE `order`
  ADD COLUMN `telegram_notificado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lancamento_bancario_id`;

-- BLOCO 3: Adicionar coluna telegram_notificado na tabela order (índice para performance)
ALTER TABLE `order`
  ADD INDEX `idx_telegram_notificado` (`telegram_notificado`);
