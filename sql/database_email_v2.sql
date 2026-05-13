-- ============================================================
-- MÓDULO DE E-MAIL v2.0 — ChopponERP
-- OAuth2 Gmail, Alertas Personalizáveis e Logs
-- ============================================================

-- 1. Recriar smtp_config com suporte a OAuth2 Gmail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `smtp_config` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = configuração global',

  -- Modo de envio: smtp_password | gmail_oauth2
  `modo`                ENUM('smtp_password','gmail_oauth2') NOT NULL DEFAULT 'smtp_password',

  -- Campos SMTP tradicionais
  `smtp_host`           VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
  `smtp_port`           INT          NOT NULL DEFAULT 587,
  `smtp_secure`         ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `smtp_username`       VARCHAR(255) NOT NULL DEFAULT '',
  `smtp_password`       TEXT         NULL COMMENT 'Senha SMTP ou App Password (base64)',

  -- Campos OAuth2 Gmail
  `oauth_client_id`     VARCHAR(255) NULL COMMENT 'Google OAuth2 Client ID',
  `oauth_client_secret` TEXT         NULL COMMENT 'Google OAuth2 Client Secret (base64)',
  `oauth_refresh_token` TEXT         NULL COMMENT 'Google OAuth2 Refresh Token (base64)',
  `oauth_access_token`  TEXT         NULL COMMENT 'Access Token atual (cache)',
  `oauth_token_expiry`  DATETIME     NULL COMMENT 'Expiração do access token',
  `oauth_email`         VARCHAR(255) NULL COMMENT 'E-mail Gmail autorizado',

  -- Remetente
  `from_email`          VARCHAR(255) NOT NULL DEFAULT '',
  `from_name`           VARCHAR(255) NOT NULL DEFAULT 'Chopp ON',

  `status`              TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_smtp_estabelecimento` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuração de envio de e-mail (SMTP ou OAuth2 Gmail)';

-- Migrar dados existentes se a tabela já existia com estrutura antiga
-- (seguro executar mesmo que a tabela não existia antes)
ALTER TABLE `smtp_config`
  MODIFY COLUMN `smtp_host` VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
  MODIFY COLUMN `smtp_username` VARCHAR(255) NOT NULL DEFAULT '',
  MODIFY COLUMN `smtp_password` TEXT NULL;

-- 2. Alertas personalizáveis por estabelecimento
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_alertas_config` (
  `id`                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`         BIGINT UNSIGNED NOT NULL,

  -- Destinatários
  `email_principal`            VARCHAR(255) NOT NULL COMMENT 'E-mail principal para alertas',
  `email_copia`                VARCHAR(500) NULL COMMENT 'E-mails em cópia (separados por vírgula)',

  -- Alertas de vendas
  `alerta_nova_venda`          TINYINT(1) NOT NULL DEFAULT 0,
  `alerta_nova_venda_assunto`  VARCHAR(255) NOT NULL DEFAULT 'Nova venda realizada — {estabelecimento}',
  `alerta_nova_venda_corpo`    TEXT NULL COMMENT 'Template HTML personalizado (NULL = padrão)',

  -- Alertas de volume crítico
  `alerta_volume_critico`      TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_volume_assunto`      VARCHAR(255) NOT NULL DEFAULT 'ALERTA: Volume crítico no barril — {tap_id}',
  `alerta_volume_corpo`        TEXT NULL,

  -- Alertas de contas a pagar
  `alerta_contas_pagar`        TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_contas_assunto`      VARCHAR(255) NOT NULL DEFAULT 'Contas a pagar vencendo — {estabelecimento}',
  `alerta_contas_corpo`        TEXT NULL,
  `dias_antes_contas`          INT NOT NULL DEFAULT 3 COMMENT 'Dias antes do vencimento para alertar',
  `dias_apos_contas`           INT NOT NULL DEFAULT 2 COMMENT 'Dias após vencimento para alertar',

  -- Alertas de estoque mínimo
  `alerta_estoque_minimo`      TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_estoque_assunto`     VARCHAR(255) NOT NULL DEFAULT 'Estoque mínimo atingido — {produto}',
  `alerta_estoque_corpo`       TEXT NULL,

  -- Alertas de royalties
  `alerta_royalties`           TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_royalties_assunto`   VARCHAR(255) NOT NULL DEFAULT 'Royalties vencendo — {estabelecimento}',
  `alerta_royalties_corpo`     TEXT NULL,

  -- Alertas de TAP offline
  `alerta_tap_offline`         TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_tap_assunto`         VARCHAR(255) NOT NULL DEFAULT 'TAP offline detectada — {tap_id}',
  `alerta_tap_corpo`           TEXT NULL,

  -- Resumo diário
  `resumo_diario`              TINYINT(1) NOT NULL DEFAULT 0,
  `resumo_horario`             TIME NOT NULL DEFAULT '08:00:00' COMMENT 'Hora de envio do resumo diário',
  `resumo_assunto`             VARCHAR(255) NOT NULL DEFAULT 'Resumo diário — {estabelecimento} — {data}',
  `resumo_corpo`               TEXT NULL,

  `status`                     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`                 TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alertas_estabelecimento` (`estabelecimento_id`),
  CONSTRAINT `fk_alertas_estabelecimento` FOREIGN KEY (`estabelecimento_id`)
    REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuração de alertas de e-mail por estabelecimento';

-- 3. Log completo de e-mails enviados/com erro
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NULL,
  `tipo`                VARCHAR(50)  NOT NULL DEFAULT 'outro'
                          COMMENT 'nova_venda|volume_critico|contas_pagar|estoque_minimo|royalties|tap_offline|resumo_diario|teste|outro',
  `referencia_id`       BIGINT UNSIGNED NULL COMMENT 'ID do registro relacionado (pedido, tap, conta, etc.)',
  `destinatario`        VARCHAR(500) NOT NULL,
  `assunto`             VARCHAR(255) NOT NULL,
  `corpo_html`          MEDIUMTEXT   NULL COMMENT 'Corpo do e-mail (armazenado para reenvio)',
  `status`              ENUM('enviado','erro','pendente','ignorado') NOT NULL DEFAULT 'pendente',
  `erro_detalhe`        TEXT         NULL COMMENT 'Mensagem de erro completa do PHPMailer/OAuth2',
  `modo_envio`          ENUM('smtp_password','gmail_oauth2','nativo') NOT NULL DEFAULT 'smtp_password',
  `tentativas`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `enviado_em`          DATETIME     NULL,
  `created_at`          TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_log_estabelecimento` (`estabelecimento_id`),
  KEY `idx_log_tipo`            (`tipo`),
  KEY `idx_log_status`          (`status`),
  KEY `idx_log_created`         (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de todos os e-mails enviados pelo sistema';

-- 4. Controle de alertas já enviados (evita spam)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_alertas_enviados` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NOT NULL,
  `tipo`                VARCHAR(50)  NOT NULL,
  `referencia_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `data_envio`          DATE         NOT NULL,
  `created_at`          TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerta_enviado` (`estabelecimento_id`, `tipo`, `referencia_id`, `data_envio`),
  KEY `idx_alertas_enviados_estab` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controle de alertas já enviados para evitar duplicatas no mesmo dia';

-- ============================================================
-- FIM
-- ============================================================
