-- ============================================================
-- MIGRAÇÃO: Atualizar smtp_config para suporte a OAuth2 Gmail
-- Versão: 2.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- Sem COMMENT nas colunas (compatibilidade MySQL 5.7 Hostgator)
-- Se um bloco retornar "coluna duplicada", ignore e execute o próximo
-- ============================================================

-- BLOCO 1: Adicionar coluna modo (smtp_password ou gmail_oauth2)
ALTER TABLE `smtp_config`
  ADD COLUMN `modo` ENUM('smtp_password','gmail_oauth2') NOT NULL DEFAULT 'smtp_password' AFTER `id`;

-- BLOCO 2: Adicionar colunas OAuth2 Gmail
ALTER TABLE `smtp_config`
  ADD COLUMN `oauth_client_id`     VARCHAR(255) NULL DEFAULT NULL AFTER `smtp_password`,
  ADD COLUMN `oauth_client_secret` TEXT         NULL DEFAULT NULL AFTER `oauth_client_id`,
  ADD COLUMN `oauth_refresh_token` TEXT         NULL DEFAULT NULL AFTER `oauth_client_secret`,
  ADD COLUMN `oauth_access_token`  TEXT         NULL DEFAULT NULL AFTER `oauth_refresh_token`,
  ADD COLUMN `oauth_token_expiry`  DATETIME     NULL DEFAULT NULL AFTER `oauth_access_token`,
  ADD COLUMN `oauth_email`         VARCHAR(255) NULL DEFAULT NULL AFTER `oauth_token_expiry`;

-- BLOCO 3: Permitir smtp_password ser NULL e aumentar tamanho
-- (a versão antiga era VARCHAR(255) NOT NULL)
ALTER TABLE `smtp_config`
  MODIFY COLUMN `smtp_password` TEXT NULL DEFAULT NULL;

-- BLOCO 4: Tornar estabelecimento_id nullable (configuração global)
-- (a versão antiga era NOT NULL)
ALTER TABLE `smtp_config`
  MODIFY COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL;
