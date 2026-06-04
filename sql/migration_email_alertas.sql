-- ============================================================
-- MIGRAÇÃO: Tabelas de E-mail e Alertas
-- Versão: 1.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- Sem FOREIGN KEY, sem COMMENT em colunas (compatibilidade MySQL 5.7)
-- ============================================================

-- BLOCO 1: Configuração de alertas por estabelecimento
CREATE TABLE IF NOT EXISTS `email_alertas_config` (
  `id`                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`         BIGINT UNSIGNED NOT NULL,
  `email_principal`            VARCHAR(255) NOT NULL DEFAULT '',
  `email_copia`                VARCHAR(500) NULL DEFAULT NULL,
  `alerta_nova_venda`          TINYINT(1) NOT NULL DEFAULT 0,
  `alerta_nova_venda_assunto`  VARCHAR(255) NOT NULL DEFAULT 'Nova venda realizada',
  `alerta_nova_venda_corpo`    TEXT NULL,
  `alerta_volume_critico`      TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_volume_assunto`      VARCHAR(255) NOT NULL DEFAULT 'ALERTA: Volume critico no barril',
  `alerta_volume_corpo`        TEXT NULL,
  `alerta_contas_pagar`        TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_contas_assunto`      VARCHAR(255) NOT NULL DEFAULT 'Contas a pagar vencendo',
  `alerta_contas_corpo`        TEXT NULL,
  `dias_antes_contas`          INT NOT NULL DEFAULT 3,
  `dias_apos_contas`           INT NOT NULL DEFAULT 2,
  `alerta_estoque_minimo`      TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_estoque_assunto`     VARCHAR(255) NOT NULL DEFAULT 'Estoque minimo atingido',
  `alerta_estoque_corpo`       TEXT NULL,
  `alerta_royalties`           TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_royalties_assunto`   VARCHAR(255) NOT NULL DEFAULT 'Royalties vencendo',
  `alerta_royalties_corpo`     TEXT NULL,
  `alerta_tap_offline`         TINYINT(1) NOT NULL DEFAULT 1,
  `alerta_tap_assunto`         VARCHAR(255) NOT NULL DEFAULT 'TAP offline detectada',
  `alerta_tap_corpo`           TEXT NULL,
  `resumo_diario`              TINYINT(1) NOT NULL DEFAULT 0,
  `resumo_horario`             TIME NOT NULL DEFAULT '08:00:00',
  `resumo_assunto`             VARCHAR(255) NOT NULL DEFAULT 'Resumo diario',
  `resumo_corpo`               TEXT NULL,
  `status`                     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`                 TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alertas_estabelecimento` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BLOCO 2: Log de e-mails enviados
CREATE TABLE IF NOT EXISTS `email_log` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NULL,
  `tipo`                VARCHAR(50)  NOT NULL DEFAULT 'outro',
  `referencia_id`       BIGINT UNSIGNED NULL,
  `destinatario`        VARCHAR(500) NOT NULL,
  `assunto`             VARCHAR(255) NOT NULL,
  `corpo_html`          MEDIUMTEXT   NULL,
  `status`              ENUM('enviado','erro','pendente','ignorado') NOT NULL DEFAULT 'pendente',
  `erro_detalhe`        TEXT NULL,
  `modo_envio`          ENUM('smtp_password','gmail_oauth2','nativo') NOT NULL DEFAULT 'smtp_password',
  `tentativas`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `enviado_em`          DATETIME NULL,
  `created_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_estabelecimento` (`estabelecimento_id`),
  KEY `idx_log_tipo`            (`tipo`),
  KEY `idx_log_status`          (`status`),
  KEY `idx_log_created`         (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BLOCO 3: Controle de alertas ja enviados (evita spam)
CREATE TABLE IF NOT EXISTS `email_alertas_enviados` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NOT NULL,
  `tipo`                VARCHAR(50)  NOT NULL,
  `referencia_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `data_envio`          DATE NOT NULL,
  `created_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerta_enviado` (`estabelecimento_id`, `tipo`, `referencia_id`, `data_envio`),
  KEY `idx_alertas_enviados_estab` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
