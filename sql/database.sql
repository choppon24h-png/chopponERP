-- ============================================
-- CHOPP ON TAP - Database Structure v3.0
-- Database: inlaud99_choppontap
-- Novidades: Telegram Bot Integration + Alertas
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

-- Limpar tabelas existentes (cuidado: apaga todos os dados!)
DROP TABLE IF EXISTS `telegram_alerts`;
DROP TABLE IF EXISTS `telegram_config`;
DROP TABLE IF EXISTS `email_config`;
DROP TABLE IF EXISTS `order`;
DROP TABLE IF EXISTS `tap`;
DROP TABLE IF EXISTS `user_estabelecimento`;
DROP TABLE IF EXISTS `bebidas`;
DROP TABLE IF EXISTS `payment`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `estabelecimentos`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- Table: estabelecimentos
-- ============================================
CREATE TABLE IF NOT EXISTS `estabelecimentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `document` VARCHAR(255) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(255) NOT NULL,
  `email_alerta` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Email principal para alertas (redundância)',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: users
-- Tipos: 1=Admin Geral, 2=Gerente, 3=Operador, 4=Visualizador
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `type` INT NOT NULL DEFAULT 4,
  `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: user_estabelecimento
-- Vincula usuários a estabelecimentos
-- ============================================
CREATE TABLE IF NOT EXISTS `user_estabelecimento` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_estabelecimento_user_id_foreign` (`user_id`),
  KEY `user_estabelecimento_estabelecimento_id_foreign` (`estabelecimento_id`),
  CONSTRAINT `user_estabelecimento_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_estabelecimento_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: bebidas
-- Cada estabelecimento cria suas próprias bebidas
-- ============================================
CREATE TABLE IF NOT EXISTS `bebidas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `ibu` VARCHAR(255) NOT NULL,
  `alcool` DOUBLE NOT NULL,
  `brand` VARCHAR(255) NOT NULL,
  `type` VARCHAR(255) NOT NULL,
  `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `promotional_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `image` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bebidas_estabelecimento_id_foreign` (`estabelecimento_id`),
  CONSTRAINT `bebidas_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: tap
-- Apenas Admin Geral pode cadastrar TAPs
-- ============================================
CREATE TABLE IF NOT EXISTS `tap` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bebida_id` BIGINT UNSIGNED NOT NULL,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `volume` DOUBLE NOT NULL,
  `android_id` VARCHAR(255) NOT NULL,
  `pairing_code` VARCHAR(255) NULL DEFAULT NULL,
  `vencimento` DATE NOT NULL,
  `volume_consumido` DOUBLE NOT NULL DEFAULT 0,
  `volume_critico` DOUBLE NOT NULL,
  `reader_id` VARCHAR(255) NULL DEFAULT NULL,
  `alerta_critico_enviado` TINYINT(1) NOT NULL DEFAULT 0,
  `alerta_10dias_enviado` TINYINT(1) NOT NULL DEFAULT 0,
  `alerta_2dias_enviado` TINYINT(1) NOT NULL DEFAULT 0,
  `alerta_vencido_enviado` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tap_bebida_id_foreign` (`bebida_id`),
  KEY `tap_estabelecimento_id_foreign` (`estabelecimento_id`),
  CONSTRAINT `tap_bebida_id_foreign` FOREIGN KEY (`bebida_id`) REFERENCES `bebidas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tap_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: order
-- ============================================
CREATE TABLE IF NOT EXISTS `order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tap_id` BIGINT UNSIGNED NOT NULL,
  `bebida_id` BIGINT UNSIGNED NOT NULL,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(255) NOT NULL,
  `valor` DOUBLE(8,2) NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  `quantidade` INT NOT NULL,
  `status_liberacao` VARCHAR(255) NOT NULL DEFAULT 'PENDING',
  `qtd_liberada` INT NOT NULL DEFAULT 0,
  `cpf` VARCHAR(255) NOT NULL,
  `response` TEXT NULL DEFAULT NULL,
  `checkout_id` VARCHAR(255) NULL DEFAULT NULL,
  `checkout_status` VARCHAR(255) NULL DEFAULT NULL,
  `pix_code` TEXT NULL DEFAULT NULL,
  `telegram_notificado` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_tap_id_foreign` (`tap_id`),
  KEY `order_bebida_id_foreign` (`bebida_id`),
  KEY `order_estabelecimento_id_foreign` (`estabelecimento_id`),
  CONSTRAINT `order_tap_id_foreign` FOREIGN KEY (`tap_id`) REFERENCES `tap` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_bebida_id_foreign` FOREIGN KEY (`bebida_id`) REFERENCES `bebidas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: payment
-- ============================================
CREATE TABLE IF NOT EXISTS `payment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_sumup` VARCHAR(255) NOT NULL,
  `pix` TINYINT(1) NULL DEFAULT 1,
  `credit` TINYINT(1) NULL DEFAULT 1,
  `debit` TINYINT(1) NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: telegram_config
-- Configuração do Telegram Bot por estabelecimento
-- ============================================
CREATE TABLE IF NOT EXISTS `telegram_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `bot_token` VARCHAR(255) NOT NULL,
  `chat_id` VARCHAR(255) NOT NULL,
  `notificar_vendas` TINYINT(1) NOT NULL DEFAULT 1,
  `notificar_volume_critico` TINYINT(1) NOT NULL DEFAULT 1,
  `notificar_vencimento` TINYINT(1) NOT NULL DEFAULT 1,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_config_estabelecimento_unique` (`estabelecimento_id`),
  CONSTRAINT `telegram_config_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: email_config
-- Configuração de alertas por e-mail por estabelecimento
-- ============================================
CREATE TABLE IF NOT EXISTS `email_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `email_alerta` VARCHAR(255) NOT NULL COMMENT 'Email para onde os alertas serão enviados',
  `notificar_vendas` TINYINT(1) NOT NULL DEFAULT 0,
  `notificar_volume_critico` TINYINT(1) NOT NULL DEFAULT 0,
  `notificar_contas_pagar` TINYINT(1) NOT NULL DEFAULT 0,
  `dias_antes_contas_pagar` INT NOT NULL DEFAULT 3,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_config_estabelecimento_unique` (`estabelecimento_id`),
  CONSTRAINT `email_config_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: telegram_alerts
-- Histórico de alertas enviados
-- ============================================
CREATE TABLE IF NOT EXISTS `telegram_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL COMMENT 'venda, volume_critico, vencimento_10d, vencimento_2d, vencido',
  `reference_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID do order ou tap relacionado',
  `message` TEXT NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'sent' COMMENT 'sent, failed',
  `response` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `telegram_alerts_estabelecimento_id_foreign` (`estabelecimento_id`),
  KEY `telegram_alerts_type_index` (`type`),
  CONSTRAINT `telegram_alerts_estabelecimento_id_foreign` FOREIGN KEY (`estabelecimento_id`) REFERENCES `estabelecimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Initial Data
-- ============================================

-- Admin User
-- Senha: Admin259087@
-- Hash gerado com bcrypt custo 12, compatível com PHP password_verify()
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `type`, `created_at`, `updated_at`) VALUES
(1, 'Administrador Geral', 'choppon24h@gmail.com', '$2y$12$0WtTRckkCnL3IiFtG8qKH.h7wqCPYQkfktIlJC6Ry2iYNKz1K7Lty', 1, NOW(), NOW());

-- Default Payment Configuration
INSERT IGNORE INTO `payment` (`id`, `token_sumup`, `pix`, `credit`, `debit`, `created_at`, `updated_at`) VALUES
(1, 'sup_sk_8vNpSEJPVudqJrWPdUlomuE3EfVofw1bL', 1, 1, 1, NOW(), NOW());

-- Default Estabelecimento
INSERT IGNORE INTO `estabelecimentos` (`id`, `name`, `document`, `address`, `phone`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Chopp On Tap - Matriz', '00000000000000', 'Endereço Principal', '(00) 00000-0000', 1, NOW(), NOW());

-- Vincular Admin ao Estabelecimento Default
INSERT IGNORE INTO `user_estabelecimento` (`id`, `user_id`, `estabelecimento_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, NOW(), NOW());

-- ============================================
-- Views e Procedures Úteis
-- ============================================

-- View para TAPs com informações completas
CREATE OR REPLACE VIEW `view_taps_completo` AS
SELECT 
    t.id,
    t.android_id,
    t.pairing_code,
    t.status,
    t.volume,
    t.volume_consumido,
    t.volume_critico,
    (t.volume - t.volume_consumido) as volume_restante,
    CASE 
        WHEN (t.volume - t.volume_consumido) <= t.volume_critico THEN 1
        ELSE 0
    END as is_critico,
    t.vencimento,
    DATEDIFF(t.vencimento, CURDATE()) as dias_para_vencer,
    CASE
        WHEN CURDATE() > t.vencimento THEN 'vencido'
        WHEN DATEDIFF(t.vencimento, CURDATE()) <= 2 THEN '2_dias'
        WHEN DATEDIFF(t.vencimento, CURDATE()) <= 10 THEN '10_dias'
        ELSE 'ok'
    END as status_vencimento,
    t.alerta_critico_enviado,
    t.alerta_10dias_enviado,
    t.alerta_2dias_enviado,
    t.alerta_vencido_enviado,
    b.name as bebida_nome,
    b.brand as bebida_marca,
    e.id as estabelecimento_id,
    e.name as estabelecimento_nome,
    t.created_at,
    t.updated_at
FROM tap t
INNER JOIN bebidas b ON t.bebida_id = b.id
INNER JOIN estabelecimentos e ON t.estabelecimento_id = e.id;

-- ============================================
-- Índices adicionais para performance
-- ============================================

CREATE INDEX idx_order_checkout_status ON `order`(`checkout_status`);
CREATE INDEX idx_order_created_at ON `order`(`created_at`);
CREATE INDEX idx_tap_vencimento ON `tap`(`vencimento`);
CREATE INDEX idx_tap_volume_critico ON `tap`(`volume_critico`);
CREATE INDEX idx_telegram_alerts_created_at ON `telegram_alerts`(`created_at`);

-- ============================================
-- Comentários nas tabelas
-- ============================================

ALTER TABLE `estabelecimentos` COMMENT = 'Estabelecimentos/Choperias cadastradas';
ALTER TABLE `users` COMMENT = 'Usuários do sistema (1=Admin, 2=Gerente, 3=Operador, 4=Visualizador)';
ALTER TABLE `user_estabelecimento` COMMENT = 'Vínculo entre usuários e estabelecimentos';
ALTER TABLE `bebidas` COMMENT = 'Bebidas cadastradas por estabelecimento';
ALTER TABLE `tap` COMMENT = 'TAPs/Torneiras (apenas Admin pode cadastrar)';
ALTER TABLE `order` COMMENT = 'Pedidos/Vendas realizados';
ALTER TABLE `payment` COMMENT = 'Configuração de pagamento SumUp';
ALTER TABLE `telegram_config` COMMENT = 'Configuração do Telegram Bot por estabelecimento';
ALTER TABLE `telegram_alerts` COMMENT = 'Histórico de alertas enviados via Telegram';
ALTER TABLE `email_config` COMMENT = 'Configuração de alertas por e-mail por estabelecimento';

-- ============================================
-- Fim do Script
-- ============================================
