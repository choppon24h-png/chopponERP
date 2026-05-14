-- ============================================
-- CHOPP ON TAP - Módulo de Royalties
-- Adiciona funcionalidades de cobrança de royalties
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- Table: royalties
-- Cadastro de cobranças de royalties
-- ============================================
CREATE TABLE IF NOT EXISTS `royalties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `periodo_inicial` DATE NOT NULL COMMENT 'Data inicial do período de cobrança',
  `periodo_final` DATE NOT NULL COMMENT 'Data final do período de cobrança',
  `descricao` VARCHAR(255) NOT NULL COMMENT 'Descrição da cobrança',
  `valor_faturamento_bruto` DECIMAL(10,2) NOT NULL COMMENT 'Valor bruto do faturamento',
  `percentual_royalties` DECIMAL(5,2) NOT NULL DEFAULT 7.00 COMMENT 'Percentual de royalties (padrão 7%)',
  `valor_royalties` DECIMAL(10,2) NOT NULL COMMENT 'Valor calculado dos royalties',
  `status` ENUM('pendente', 'boleto_gerado', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
  `boleto_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'ID do boleto gerado na API Cora',
  `boleto_linha_digitavel` TEXT NULL DEFAULT NULL COMMENT 'Linha digitável do boleto',
  `boleto_codigo_barras` TEXT NULL DEFAULT NULL COMMENT 'Código de barras do boleto',
  `boleto_qrcode_pix` TEXT NULL DEFAULT NULL COMMENT 'QR Code Pix do boleto',
  `boleto_data_vencimento` DATE NULL DEFAULT NULL COMMENT 'Data de vencimento do boleto',
  `conta_pagar_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID da conta a pagar gerada',
  `data_pagamento` DATE NULL DEFAULT NULL,
  `observacoes` TEXT NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL COMMENT 'ID do usuário que criou',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `royalties_estabelecimento_id_foreign` (`estabelecimento_id`),
  KEY `royalties_periodo_inicial_index` (`periodo_inicial`),
  KEY `royalties_periodo_final_index` (`periodo_final`),
  KEY `royalties_status_index` (`status`),
  KEY `royalties_conta_pagar_id_foreign` (`conta_pagar_id`),
  KEY `royalties_created_by_foreign` (`created_by`),
  CONSTRAINT `royalties_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `royalties_conta_pagar_id_foreign` FOREIGN KEY (`conta_pagar_id`) REFERENCES `contas_pagar` (`id`) ON DELETE SET NULL,
  CONSTRAINT `royalties_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: royalties_historico
-- Histórico de ações realizadas nos royalties
-- ============================================
CREATE TABLE IF NOT EXISTS `royalties_historico` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `royalty_id` BIGINT UNSIGNED NOT NULL,
  `acao` VARCHAR(100) NOT NULL COMMENT 'criacao, geracao_boleto, pagamento, cancelamento',
  `descricao` TEXT NOT NULL,
  `dados_json` TEXT NULL DEFAULT NULL COMMENT 'Dados adicionais em LONGTEXT',
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `royalties_historico_royalty_id_foreign` (`royalty_id`),
  KEY `royalties_historico_user_id_foreign` (`user_id`),
  CONSTRAINT `royalties_historico_royalty_id_foreign` FOREIGN KEY (`royalty_id`) REFERENCES `royalties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `royalties_historico_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Índices adicionais para performance
-- ============================================
CREATE INDEX idx_royalties_created_at ON `royalties`(`created_at`);
CREATE INDEX idx_royalties_boleto_id ON `royalties`(`boleto_id`);

-- ============================================
-- Comentários nas tabelas
-- ============================================
ALTER TABLE `royalties` COMMENT = 'Cobranças de royalties por estabelecimento';
ALTER TABLE `royalties_historico` COMMENT = 'Histórico de ações realizadas nos royalties';

-- ============================================
-- Fim do Script
-- ============================================
