-- =====================================================
-- Migration: Adicionar Integração Mercado Pago
-- Data: 2025-12-14
-- =====================================================

-- Tabela de configuração Mercado Pago
CREATE TABLE IF NOT EXISTS `mercadopago_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT(20) NOT NULL,
  `access_token` VARCHAR(500) NOT NULL COMMENT 'Access Token do Mercado Pago',
  `public_key` VARCHAR(500) NULL COMMENT 'Public Key (opcional)',
  `ambiente` ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox',
  `webhook_url` VARCHAR(500) NULL COMMENT 'URL para receber notificações',
  `webhook_secret` VARCHAR(255) NULL COMMENT 'Secret para validar webhook',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_estabelecimento` (`estabelecimento_id`),
  KEY `idx_estabelecimento` (`estabelecimento_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurações de integração com Mercado Pago por estabelecimento';

-- Verificar e adicionar colunas na tabela royalties
SET @dbname = DATABASE();
SET @tablename = 'royalties';

-- Adicionar coluna metodo_pagamento se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'metodo_pagamento');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `metodo_pagamento` ENUM(''stripe'', ''cora'', ''mercadopago'', ''manual'') NULL COMMENT ''Método de pagamento escolhido''', 
    'SELECT ''Column metodo_pagamento already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna payment_id se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'payment_id');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `payment_id` VARCHAR(255) NULL COMMENT ''ID do pagamento no gateway''', 
    'SELECT ''Column payment_id already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna payment_url se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'payment_url');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `payment_url` VARCHAR(500) NULL COMMENT ''URL de pagamento gerada''', 
    'SELECT ''Column payment_url already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna payment_status se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'payment_status');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `payment_status` ENUM(''pendente'', ''processando'', ''aprovado'', ''recusado'', ''cancelado'') DEFAULT ''pendente''', 
    'SELECT ''Column payment_status already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna payment_data se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'payment_data');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `payment_data` LONGTEXT NULL COMMENT ''Dados adicionais do pagamento''', 
    'SELECT ''Column payment_data already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna paid_at se não existir
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'paid_at');
SET @query = IF(@col_exists = 0, 
    'ALTER TABLE `royalties` ADD COLUMN `paid_at` TIMESTAMP NULL COMMENT ''Data/hora do pagamento''', 
    'SELECT ''Column paid_at already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar índices se não existirem
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'idx_payment_id');
SET @query = IF(@index_exists = 0, 
    'ALTER TABLE `royalties` ADD INDEX `idx_payment_id` (`payment_id`)', 
    'SELECT ''Index idx_payment_id already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'idx_payment_status');
SET @query = IF(@index_exists = 0, 
    'ALTER TABLE `royalties` ADD INDEX `idx_payment_status` (`payment_status`)', 
    'SELECT ''Index idx_payment_status already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'idx_metodo_pagamento');
SET @query = IF(@index_exists = 0, 
    'ALTER TABLE `royalties` ADD INDEX `idx_metodo_pagamento` (`metodo_pagamento`)', 
    'SELECT ''Index idx_metodo_pagamento already exists'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Tabela de log de transações de pagamento
CREATE TABLE IF NOT EXISTS `royalties_payment_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `royalty_id` INT(11) NOT NULL,
  `estabelecimento_id` BIGINT(20) NOT NULL,
  `metodo_pagamento` ENUM('stripe', 'cora', 'mercadopago', 'manual') NOT NULL,
  `acao` VARCHAR(100) NOT NULL COMMENT 'Ação realizada (criar_pagamento, webhook, etc)',
  `status` VARCHAR(50) NOT NULL,
  `request_data` LONGTEXT NULL COMMENT 'Dados enviados para API',
  `response_data` LONGTEXT NULL COMMENT 'Resposta da API',
  `erro_mensagem` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_royalty_id` (`royalty_id`),
  KEY `idx_estabelecimento_id` (`estabelecimento_id`),
  KEY `idx_metodo_pagamento` (`metodo_pagamento`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de todas as transações de pagamento de royalties';

-- =====================================================
-- PERMISSÕES (Adicionar manualmente se necessário)
-- =====================================================
-- Execute manualmente após importar este arquivo:

-- INSERT IGNORE INTO `pages` (`name`, `path`, `description`, `icon`, `category`, `display_order`) 
-- VALUES ('Mercado Pago', 'admin/mercadopago_config.php', 'Configuração de integração com Mercado Pago', 'fab fa-cc-mastercard', 'Integrações', 50);

-- INSERT IGNORE INTO `user_permissions` (`user_id`, `page_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
-- SELECT u.id, p.id, 1, 1, 1, 1
-- FROM `users` u
-- CROSS JOIN `pages` p
-- WHERE u.tipo = 'admin_geral' AND p.path = 'admin/mercadopago_config.php';
