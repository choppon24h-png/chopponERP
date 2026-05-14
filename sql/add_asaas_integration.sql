-- =====================================================
-- Migration: Adicionar Integração Asaas
-- Data: 2026-01-12
-- Descrição: Estrutura completa para integração com gateway de pagamento Asaas
-- =====================================================

-- Tabela de configuração Asaas
CREATE TABLE IF NOT EXISTS `asaas_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT(20) NOT NULL,
  `asaas_api_key` VARCHAR(500) NOT NULL COMMENT 'API Key do Asaas (produção ou sandbox)',
  `asaas_webhook_token` VARCHAR(255) NULL COMMENT 'Token para autenticação de webhooks',
  `ambiente` ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_estabelecimento` (`estabelecimento_id`),
  KEY `idx_estabelecimento` (`estabelecimento_id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurações de integração com Asaas por estabelecimento';

-- Tabela de clientes Asaas (mapeamento)
CREATE TABLE IF NOT EXISTS `asaas_clientes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` BIGINT(20) NOT NULL COMMENT 'ID do cliente no sistema local',
  `estabelecimento_id` BIGINT(20) NOT NULL,
  `asaas_customer_id` VARCHAR(100) NOT NULL COMMENT 'ID do cliente no Asaas',
  `cpf_cnpj` VARCHAR(18) NULL,
  `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cliente_estabelecimento` (`cliente_id`, `estabelecimento_id`),
  UNIQUE KEY `unique_asaas_customer` (`asaas_customer_id`),
  KEY `idx_cliente_id` (`cliente_id`),
  KEY `idx_estabelecimento_id` (`estabelecimento_id`),
  KEY `idx_asaas_customer_id` (`asaas_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mapeamento de clientes locais para clientes Asaas';

-- Tabela de pagamentos Asaas
CREATE TABLE IF NOT EXISTS `asaas_pagamentos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conta_receber_id` INT(11) NULL COMMENT 'ID da conta a receber (se aplicável)',
  `estabelecimento_id` BIGINT(20) NOT NULL,
  `asaas_payment_id` VARCHAR(100) NOT NULL COMMENT 'ID do pagamento no Asaas',
  `asaas_customer_id` VARCHAR(100) NOT NULL COMMENT 'ID do cliente no Asaas',
  `tipo_cobranca` ENUM('BOLETO', 'CREDIT_CARD', 'PIX', 'UNDEFINED') NOT NULL,
  `valor` DECIMAL(10,2) NOT NULL,
  `data_vencimento` DATE NOT NULL,
  `status_asaas` VARCHAR(50) NOT NULL COMMENT 'Status retornado pelo Asaas',
  `url_boleto` VARCHAR(500) NULL,
  `linha_digitavel` VARCHAR(500) NULL,
  `qr_code_pix` TEXT NULL,
  `payload_pix` TEXT NULL,
  `nosso_numero` VARCHAR(100) NULL,
  `url_fatura` VARCHAR(500) NULL,
  `data_pagamento` TIMESTAMP NULL,
  `data_confirmacao` TIMESTAMP NULL,
  `data_credito` DATE NULL,
  `valor_liquido` DECIMAL(10,2) NULL,
  `payload_completo` LONGTEXT NULL COMMENT 'Payload completo retornado pelo Asaas',
  `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_asaas_payment` (`asaas_payment_id`),
  KEY `idx_conta_receber_id` (`conta_receber_id`),
  KEY `idx_estabelecimento_id` (`estabelecimento_id`),
  KEY `idx_asaas_payment_id` (`asaas_payment_id`),
  KEY `idx_asaas_customer_id` (`asaas_customer_id`),
  KEY `idx_status_asaas` (`status_asaas`),
  KEY `idx_data_vencimento` (`data_vencimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pagamentos criados no Asaas';

-- Tabela de webhooks Asaas
CREATE TABLE IF NOT EXISTS `asaas_webhooks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(255) NOT NULL COMMENT 'ID único do evento no Asaas',
  `event_type` VARCHAR(100) NOT NULL COMMENT 'Tipo do evento (PAYMENT_RECEIVED, etc)',
  `asaas_payment_id` VARCHAR(100) NULL COMMENT 'ID do pagamento no Asaas',
  `payload` LONGTEXT NOT NULL COMMENT 'Payload completo do webhook',
  `processado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=Pendente, 1=Processado',
  `data_recebimento` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_processamento` TIMESTAMP NULL,
  `erro_mensagem` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_event_id` (`event_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_asaas_payment_id` (`asaas_payment_id`),
  KEY `idx_processado` (`processado`),
  KEY `idx_data_recebimento` (`data_recebimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de webhooks recebidos do Asaas';

-- Tabela de logs de operações Asaas
CREATE TABLE IF NOT EXISTS `asaas_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `operacao` VARCHAR(100) NOT NULL COMMENT 'Tipo de operação (criar_cliente, criar_cobranca, etc)',
  `status` VARCHAR(50) NOT NULL COMMENT 'Status da operação (sucesso, erro)',
  `estabelecimento_id` BIGINT(20) NULL,
  `dados_requisicao` LONGTEXT NULL COMMENT 'Dados enviados para API',
  `dados_resposta` LONGTEXT NULL COMMENT 'Resposta da API',
  `mensagem_erro` TEXT NULL,
  `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operacao` (`operacao`),
  KEY `idx_status` (`status`),
  KEY `idx_estabelecimento_id` (`estabelecimento_id`),
  KEY `idx_data_criacao` (`data_criacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de todas as operações com API Asaas';

-- Verificar e adicionar colunas na tabela royalties (se não existirem)
SET @dbname = DATABASE();
SET @tablename = 'royalties';

-- Verificar se coluna metodo_pagamento existe e adicionar 'asaas' se necessário
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'metodo_pagamento');

-- Se a coluna já existe, alterar o ENUM para incluir 'asaas'
SET @query = IF(@col_exists > 0, 
    "ALTER TABLE `royalties` MODIFY COLUMN `metodo_pagamento` ENUM('stripe', 'cora', 'mercadopago', 'asaas', 'manual') NULL COMMENT 'Método de pagamento escolhido'", 
    "ALTER TABLE `royalties` ADD COLUMN `metodo_pagamento` ENUM('stripe', 'cora', 'mercadopago', 'asaas', 'manual') NULL COMMENT 'Método de pagamento escolhido'");
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar coluna payment_id se não existir (já deve existir de migrações anteriores)
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

-- Adicionar coluna payment_status se não existir (atualizar ENUM se existir)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'payment_status');
SET @query = IF(@col_exists = 0, 
    "ALTER TABLE `royalties` ADD COLUMN `payment_status` ENUM('pendente', 'processando', 'aprovado', 'recusado', 'cancelado', 'confirmado', 'recebido') DEFAULT 'pendente'", 
    "ALTER TABLE `royalties` MODIFY COLUMN `payment_status` ENUM('pendente', 'processando', 'aprovado', 'recusado', 'cancelado', 'confirmado', 'recebido') DEFAULT 'pendente'");
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

-- Atualizar tabela royalties_payment_log para incluir 'asaas'
SET @tablename = 'royalties_payment_log';
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'metodo_pagamento');

SET @query = IF(@col_exists > 0, 
    "ALTER TABLE `royalties_payment_log` MODIFY COLUMN `metodo_pagamento` ENUM('stripe', 'cora', 'mercadopago', 'asaas', 'manual') NOT NULL", 
    'SELECT ''Table royalties_payment_log not found'' AS msg');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- PERMISSÕES (Adicionar manualmente se necessário)
-- =====================================================
-- Execute manualmente após importar este arquivo:

-- INSERT IGNORE INTO `pages` (`name`, `path`, `description`, `icon`, `category`, `display_order`) 
-- VALUES ('Asaas', 'admin/asaas_config.php', 'Configuração de integração com Asaas', 'fas fa-dollar-sign', 'Integrações', 45);

-- INSERT IGNORE INTO `user_permissions` (`user_id`, `page_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
-- SELECT u.id, p.id, 1, 1, 1, 1
-- FROM `users` u
-- CROSS JOIN `pages` p
-- WHERE u.tipo = 'admin_geral' AND p.path = 'admin/asaas_config.php';
