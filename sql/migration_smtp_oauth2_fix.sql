-- ============================================================
-- MIGRAÇÃO: smtp_config — suporte completo a OAuth2 Gmail
-- Versão: 1.0.0 | MySQL 5.7 | Choppon ERP
-- Data: 2026-06-08
--
-- PROBLEMA: tabela smtp_config foi criada pela migração antiga
-- (add_email_smtp_alerts.sql) sem as colunas OAuth2 e sem a
-- coluna `modo`. O sistema tenta salvar essas colunas e gera:
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'smtp_host'
--   (ou qualquer outra coluna ausente)
--
-- ⚠️  ANTES DE EXECUTAR:
--   1. Faça backup do banco
--   2. Execute DESCRIBE smtp_config; no phpMyAdmin para ver
--      quais colunas já existem
--   3. Execute cada BLOCO separadamente
--   4. Se um bloco retornar "Duplicate column name", ignore e
--      execute o próximo — a coluna já existe
-- ============================================================

-- BLOCO 1: Adicionar coluna `modo` (tipo de autenticação)
-- Pular se já existir
ALTER TABLE `smtp_config`
  ADD COLUMN `modo` ENUM('smtp_password','gmail_oauth2') NOT NULL DEFAULT 'smtp_password'
  AFTER `estabelecimento_id`;

-- BLOCO 2: Garantir que smtp_host existe (pode estar ausente se
-- a tabela foi recriada por outro script)
ALTER TABLE `smtp_config`
  ADD COLUMN `smtp_host` VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com'
  AFTER `modo`;

-- BLOCO 3: Garantir que smtp_port existe
ALTER TABLE `smtp_config`
  ADD COLUMN `smtp_port` INT NOT NULL DEFAULT 587
  AFTER `smtp_host`;

-- BLOCO 4: Garantir que smtp_secure existe
ALTER TABLE `smtp_config`
  ADD COLUMN `smtp_secure` ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls'
  AFTER `smtp_port`;

-- BLOCO 5: Garantir que smtp_username existe
ALTER TABLE `smtp_config`
  ADD COLUMN `smtp_username` VARCHAR(255) NOT NULL DEFAULT ''
  AFTER `smtp_secure`;

-- BLOCO 6: Garantir que smtp_password existe e aceita NULL
ALTER TABLE `smtp_config`
  ADD COLUMN `smtp_password` TEXT NULL DEFAULT NULL
  AFTER `smtp_username`;

-- BLOCO 7: Adicionar colunas OAuth2 Gmail
ALTER TABLE `smtp_config`
  ADD COLUMN `oauth_client_id`     VARCHAR(255) NULL DEFAULT NULL AFTER `smtp_password`,
  ADD COLUMN `oauth_client_secret` TEXT         NULL DEFAULT NULL AFTER `oauth_client_id`,
  ADD COLUMN `oauth_refresh_token` TEXT         NULL DEFAULT NULL AFTER `oauth_client_secret`,
  ADD COLUMN `oauth_access_token`  TEXT         NULL DEFAULT NULL AFTER `oauth_refresh_token`,
  ADD COLUMN `oauth_token_expiry`  DATETIME     NULL DEFAULT NULL AFTER `oauth_access_token`,
  ADD COLUMN `oauth_email`         VARCHAR(255) NULL DEFAULT NULL AFTER `oauth_token_expiry`;

-- BLOCO 8: Garantir que from_email e from_name existem
ALTER TABLE `smtp_config`
  ADD COLUMN `from_email` VARCHAR(255) NOT NULL DEFAULT ''
  AFTER `oauth_email`;

ALTER TABLE `smtp_config`
  ADD COLUMN `from_name` VARCHAR(255) NOT NULL DEFAULT 'Chopp ON'
  AFTER `from_email`;

-- BLOCO 9: Tornar smtp_password nullable (versão antiga era NOT NULL)
ALTER TABLE `smtp_config`
  MODIFY COLUMN `smtp_password` TEXT NULL DEFAULT NULL;

-- BLOCO 10: Tornar estabelecimento_id nullable (configuração global)
ALTER TABLE `smtp_config`
  MODIFY COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL;

-- ============================================================
-- VALIDAÇÃO — execute após os blocos acima
-- ============================================================
-- DESCRIBE smtp_config;
-- Deve mostrar as colunas: id, estabelecimento_id, modo,
-- smtp_host, smtp_port, smtp_secure, smtp_username,
-- smtp_password, oauth_client_id, oauth_client_secret,
-- oauth_refresh_token, oauth_access_token, oauth_token_expiry,
-- oauth_email, from_email, from_name, status,
-- created_at, updated_at

-- ============================================================
-- ROLLBACK (caso precise desfazer)
-- ============================================================
-- ALTER TABLE `smtp_config` DROP COLUMN `modo`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_client_id`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_client_secret`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_refresh_token`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_access_token`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_token_expiry`;
-- ALTER TABLE `smtp_config` DROP COLUMN `oauth_email`;
-- ============================================================
