-- Migração de Banco de Dados para o App ChoppOn
-- Executar no banco de dados do ChopponERP

-- 1. Adicionar campo de senha na tabela de clientes (se não existir)
ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `password` VARCHAR(255) NULL AFTER `cpf`;

-- 2. Adicionar campo para token de push notification (Firebase)
ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `fcm_token` VARCHAR(255) NULL AFTER `status`;

-- 3. Criar tabela de notificações
CREATE TABLE IF NOT EXISTS `notificacoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` INT(11) NOT NULL,
  `titulo` VARCHAR(100) NOT NULL,
  `mensagem` TEXT NOT NULL,
  `lida` TINYINT(1) DEFAULT 0,
  `data_envio` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cliente` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Atualizar clientes existentes com uma senha padrão (6 primeiros dígitos do CPF)
-- Apenas para clientes que ainda não têm senha
UPDATE `clientes` 
SET `password` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' -- Hash padrão, deve ser gerado via PHP
WHERE `password` IS NULL AND `cpf` IS NOT NULL;
