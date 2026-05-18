-- ============================================================
-- MIGRAÇÃO: Módulo CRM — Leads, Oportunidades e Transferências
-- Compatível com MariaDB 5.7+
-- ============================================================

-- ── 1. Tabela de Leads ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crm_leads` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NOT NULL,
  `responsavel_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Usuário responsável atual',
  `nome`                VARCHAR(255) NOT NULL,
  `email`               VARCHAR(255) DEFAULT NULL,
  `telefone`            VARCHAR(30)  DEFAULT NULL,
  `empresa`             VARCHAR(255) DEFAULT NULL,
  `origem`              ENUM('indicacao','site','redes_sociais','evento','cold_call','outro') NOT NULL DEFAULT 'outro',
  `status`              ENUM('novo','em_contato','qualificado','desqualificado','convertido') NOT NULL DEFAULT 'novo',
  `temperatura`         ENUM('frio','morno','quente') NOT NULL DEFAULT 'frio',
  `observacoes`         TEXT DEFAULT NULL,
  `created_by`          BIGINT UNSIGNED NOT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_estab`        (`estabelecimento_id`),
  KEY `idx_leads_responsavel`  (`responsavel_id`),
  KEY `idx_leads_status`       (`status`),
  CONSTRAINT `fk_leads_estab`        FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leads_responsavel`  FOREIGN KEY (`responsavel_id`)     REFERENCES `users` (`id`),
  CONSTRAINT `fk_leads_created_by`   FOREIGN KEY (`created_by`)         REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Tabela de Oportunidades ────────────────────────────────
CREATE TABLE IF NOT EXISTS `crm_oportunidades` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  BIGINT UNSIGNED NOT NULL,
  `lead_id`             BIGINT UNSIGNED DEFAULT NULL COMMENT 'Lead de origem (opcional)',
  `responsavel_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Usuário responsável atual',
  `titulo`              VARCHAR(255) NOT NULL,
  `cliente_nome`        VARCHAR(255) NOT NULL,
  `cliente_email`       VARCHAR(255) DEFAULT NULL,
  `cliente_telefone`    VARCHAR(30)  DEFAULT NULL,
  `valor_estimado`      DECIMAL(12,2) DEFAULT 0.00,
  `etapa`               ENUM('prospeccao','qualificacao','proposta','negociacao','fechado_ganho','fechado_perdido') NOT NULL DEFAULT 'prospeccao',
  `probabilidade`       TINYINT UNSIGNED DEFAULT 50 COMMENT 'Probabilidade de fechamento 0-100%',
  `data_previsao`       DATE DEFAULT NULL,
  `motivo_perda`        TEXT DEFAULT NULL,
  `observacoes`         TEXT DEFAULT NULL,
  `created_by`          BIGINT UNSIGNED NOT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_opor_estab`        (`estabelecimento_id`),
  KEY `idx_opor_responsavel`  (`responsavel_id`),
  KEY `idx_opor_lead`         (`lead_id`),
  KEY `idx_opor_etapa`        (`etapa`),
  CONSTRAINT `fk_opor_estab`        FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_opor_responsavel`  FOREIGN KEY (`responsavel_id`)     REFERENCES `users` (`id`),
  CONSTRAINT `fk_opor_lead`         FOREIGN KEY (`lead_id`)            REFERENCES `crm_leads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_opor_created_by`   FOREIGN KEY (`created_by`)         REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Log de Interações (unificado para Leads e Oportunidades) ──
CREATE TABLE IF NOT EXISTS `crm_interacoes` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_registro`   ENUM('lead','oportunidade') NOT NULL,
  `registro_id`     BIGINT UNSIGNED NOT NULL COMMENT 'ID do lead ou oportunidade',
  `tipo`            ENUM('nota','ligacao','email','reuniao','whatsapp','transferencia','status','criacao') NOT NULL DEFAULT 'nota',
  `descricao`       TEXT NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL COMMENT 'Quem registrou a interação',
  -- Campos específicos de transferência
  `transferencia_de`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id anterior (só em tipo=transferencia)',
  `transferencia_para` BIGINT UNSIGNED DEFAULT NULL COMMENT 'user_id novo (só em tipo=transferencia)',
  `motivo_transferencia` TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inter_registro`  (`tipo_registro`, `registro_id`),
  KEY `idx_inter_user`      (`user_id`),
  KEY `idx_inter_tipo`      (`tipo`),
  CONSTRAINT `fk_inter_user`      FOREIGN KEY (`user_id`)          REFERENCES `users` (`id`),
  CONSTRAINT `fk_inter_de`        FOREIGN KEY (`transferencia_de`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inter_para`      FOREIGN KEY (`transferencia_para`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Tabela de Transferências (histórico detalhado) ─────────
CREATE TABLE IF NOT EXISTS `crm_transferencias` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_registro`   ENUM('lead','oportunidade') NOT NULL,
  `registro_id`     BIGINT UNSIGNED NOT NULL,
  `de_user_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Responsável anterior',
  `para_user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'Novo responsável',
  `motivo`          TEXT NOT NULL,
  `transferido_por` BIGINT UNSIGNED NOT NULL COMMENT 'Quem executou a transferência',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transf_registro`  (`tipo_registro`, `registro_id`),
  KEY `idx_transf_de`        (`de_user_id`),
  KEY `idx_transf_para`      (`para_user_id`),
  CONSTRAINT `fk_transf_de`   FOREIGN KEY (`de_user_id`)      REFERENCES `users` (`id`),
  CONSTRAINT `fk_transf_para` FOREIGN KEY (`para_user_id`)    REFERENCES `users` (`id`),
  CONSTRAINT `fk_transf_por`  FOREIGN KEY (`transferido_por`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Verificação final ─────────────────────────────────────────
SELECT 'crm_leads'          AS tabela, COUNT(*) AS registros FROM crm_leads
UNION ALL
SELECT 'crm_oportunidades'  AS tabela, COUNT(*) AS registros FROM crm_oportunidades
UNION ALL
SELECT 'crm_interacoes'     AS tabela, COUNT(*) AS registros FROM crm_interacoes
UNION ALL
SELECT 'crm_transferencias' AS tabela, COUNT(*) AS registros FROM crm_transferencias;
