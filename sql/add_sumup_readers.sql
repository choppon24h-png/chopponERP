-- Tabela para armazenar leitoras SumUp Solo cadastradas
CREATE TABLE IF NOT EXISTS `sumup_readers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reader_id` VARCHAR(60) NOT NULL COMMENT 'ID único do reader na SumUp (rdr_...)',
  `name` VARCHAR(255) NOT NULL COMMENT 'Nome/código de pareamento do reader',
  `serial` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Número de série do dispositivo físico',
  `model` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Modelo do dispositivo (solo)',
  `status` VARCHAR(30) NOT NULL DEFAULT 'processing' COMMENT 'Status: processing, paired, expired',
  `battery_level` INT NULL DEFAULT NULL COMMENT 'Nível de bateria em %',
  `connection_type` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Tipo de conexão (wifi, umts, etc)',
  `firmware_version` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Versão do firmware',
  `last_activity` DATETIME NULL DEFAULT NULL COMMENT 'Última atividade do dispositivo',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reader_id_unique` (`reader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
