-- ============================================
-- Configuração de Gateways de Pagamento
-- Suporta múltiplos gateways por estabelecimento
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- Table: payment_gateway_config
-- Armazena credenciais de gateways de pagamento
-- ============================================
CREATE TABLE IF NOT EXISTS `payment_gateway_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `gateway_type` ENUM('stripe', 'cora', 'sumup') NOT NULL COMMENT 'Tipo de gateway',
  `environment` ENUM('test', 'production') NOT NULL DEFAULT 'test' COMMENT 'Ambiente de operação',
  `ativo` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Se este gateway está ativo para o estabelecimento',
  `config_data` LONGTEXT NOT NULL COMMENT 'Dados de configuração em LONGTEXT',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Usuário que atualizou',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_estabelecimento_gateway` (`estabelecimento_id`, `gateway_type`),
  KEY `payment_gateway_config_estabelecimento_id_foreign` (`estabelecimento_id`),
  KEY `payment_gateway_config_gateway_type_index` (`gateway_type`),
  KEY `payment_gateway_config_ativo_index` (`ativo`),
  CONSTRAINT `payment_gateway_config_estabelecimento_id_foreign` 
    FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: faturamentos
-- Registro unificado de faturas (Stripe e Cora)
-- ============================================
CREATE TABLE IF NOT EXISTS `faturamentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `gateway_type` ENUM('stripe', 'cora') NOT NULL COMMENT 'Gateway utilizado',
  `gateway_id` VARCHAR(255) NOT NULL COMMENT 'ID único no gateway (invoice_id ou boleto_id)',
  `royalty_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID do royalty associado',
  `tipo_faturamento` ENUM('royalty', 'taxa', 'servico') NOT NULL DEFAULT 'royalty',
  `descricao` VARCHAR(255) NOT NULL,
  `valor` DECIMAL(10,2) NOT NULL,
  `moeda` VARCHAR(3) NOT NULL DEFAULT 'BRL',
  `status` VARCHAR(50) NOT NULL COMMENT 'pending, awaiting_payment, paid, overdue, canceled, rejected',
  `data_criacao` DATETIME NOT NULL,
  `data_vencimento` DATE NULL DEFAULT NULL,
  `data_pagamento` DATETIME NULL DEFAULT NULL,
  `valor_pago` DECIMAL(10,2) NULL DEFAULT NULL,
  `metadados` LONGTEXT NULL DEFAULT NULL COMMENT 'Dados adicionais específicos do gateway',
  `ultima_verificacao` DATETIME NULL DEFAULT NULL COMMENT 'Última vez que status foi verificado',
  `proxima_verificacao` DATETIME NULL DEFAULT NULL COMMENT 'Próxima verificação agendada',
  `tentativas_verificacao` INT NOT NULL DEFAULT 0,
  `observacoes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_gateway_id` (`gateway_type`, `gateway_id`),
  KEY `faturamentos_estabelecimento_id_foreign` (`estabelecimento_id`),
  KEY `faturamentos_royalty_id_foreign` (`royalty_id`),
  KEY `faturamentos_gateway_type_index` (`gateway_type`),
  KEY `faturamentos_status_index` (`status`),
  KEY `faturamentos_data_vencimento_index` (`data_vencimento`),
  KEY `faturamentos_proxima_verificacao_index` (`proxima_verificacao`),
  CONSTRAINT `faturamentos_estabelecimento_id_foreign` 
    FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `faturamentos_royalty_id_foreign` 
    FOREIGN KEY (`royalty_id`) REFERENCES `royalties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: faturamentos_historico
-- Histórico de alterações de status de faturamentos
-- ============================================
CREATE TABLE IF NOT EXISTS `faturamentos_historico` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `faturamento_id` BIGINT UNSIGNED NOT NULL,
  `status_anterior` VARCHAR(50) NULL DEFAULT NULL,
  `status_novo` VARCHAR(50) NOT NULL,
  `motivo` TEXT NULL DEFAULT NULL,
  `dados_verificacao` LONGTEXT NULL DEFAULT NULL COMMENT 'Resposta da API na verificação',
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Usuário que fez a alteração (NULL se automático)',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `faturamentos_historico_faturamento_id_foreign` (`faturamento_id`),
  KEY `faturamentos_historico_user_id_foreign` (`user_id`),
  CONSTRAINT `faturamentos_historico_faturamento_id_foreign` 
    FOREIGN KEY (`faturamento_id`) REFERENCES `faturamentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `faturamentos_historico_user_id_foreign` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Índices adicionais para performance
-- ============================================
CREATE INDEX idx_faturamentos_created_at ON `faturamentos`(`created_at`);
CREATE INDEX idx_faturamentos_gateway_id ON `faturamentos`(`gateway_id`);
CREATE INDEX idx_payment_gateway_config_updated_at ON `payment_gateway_config`(`updated_at`);

-- ============================================
-- Comentários nas tabelas
-- ============================================
ALTER TABLE `payment_gateway_config` COMMENT = 'Configuração de gateways de pagamento por estabelecimento';
ALTER TABLE `faturamentos` COMMENT = 'Registro unificado de faturas (Stripe e Cora)';
ALTER TABLE `faturamentos_historico` COMMENT = 'Histórico de alterações de status de faturamentos';

-- ============================================
-- Fim do Script
-- ============================================
