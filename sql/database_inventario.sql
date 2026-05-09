-- ============================================
-- CHOPP ON TAP - Módulo Inventário/Patrimônio
-- Versão: 1.0.0
-- ============================================

-- ============================================
-- Tabela: patrimonio
-- Registro de cada bem patrimonial
-- ============================================
CREATE TABLE IF NOT EXISTS `patrimonio` (
  `id`               INT(11) NOT NULL AUTO_INCREMENT,
  `numero_pat`       VARCHAR(20)  NOT NULL UNIQUE COMMENT 'Ex: PAT-0001',
  `descricao`        VARCHAR(255) NOT NULL COMMENT 'Nome/descrição do bem',
  `classificacao`    ENUM('imobilizado','ativo') NOT NULL DEFAULT 'ativo' COMMENT 'imobilizado = bem fixo; ativo = bem circulante',
  `categoria`        VARCHAR(100) NULL COMMENT 'Ex: Equipamento, Móvel, Veículo, Informática',
  `marca`            VARCHAR(100) NULL,
  `modelo`           VARCHAR(100) NULL,
  `numero_serie`     VARCHAR(100) NULL,
  `valor_compra`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `data_compra`      DATE         NULL,
  `fornecedor`       VARCHAR(255) NULL,
  `numero_nf`        VARCHAR(50)  NULL COMMENT 'Número da Nota Fiscal',
  `arquivo_nf`       VARCHAR(500) NULL COMMENT 'Caminho do arquivo da NF',
  `foto`             VARCHAR(500) NULL COMMENT 'Caminho da foto do patrimônio',
  `localizacao`      VARCHAR(255) NULL COMMENT 'Local onde o bem está alocado',
  `responsavel`      VARCHAR(255) NULL COMMENT 'Responsável pelo bem',
  `observacoes`      TEXT         NULL,
  `status`           ENUM('ativo','inativo','em_manutencao','baixado') NOT NULL DEFAULT 'ativo',
  `grupo_pat`        VARCHAR(50)  NULL COMMENT 'Identificador do grupo quando gerado em lote (mesmo item, múltiplas unidades)',
  `quantidade_lote`  INT(11)      NOT NULL DEFAULT 1 COMMENT 'Quantidade do lote de origem',
  `sequencia_lote`   INT(11)      NOT NULL DEFAULT 1 COMMENT 'Número sequencial dentro do lote (1, 2, 3...)',
  `tem_preventiva`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = possui manutenção preventiva programada',
  `periodicidade_preventiva` ENUM('mensal','bimestral','trimestral','semestral','anual') NULL,
  `proxima_preventiva` DATE       NULL,
  `ultima_preventiva`  DATE       NULL,
  `criado_por`       INT(11)      NULL,
  `atualizado_por`   INT(11)      NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_numero_pat`    (`numero_pat`),
  INDEX `idx_classificacao` (`classificacao`),
  INDEX `idx_status`        (`status`),
  INDEX `idx_grupo_pat`     (`grupo_pat`),
  INDEX `idx_tem_preventiva`(`tem_preventiva`),
  INDEX `idx_proxima_prev`  (`proxima_preventiva`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabela de patrimônio e inventário de bens';

-- ============================================
-- Tabela: patrimonio_preventivas
-- Histórico de manutenções preventivas
-- ============================================
CREATE TABLE IF NOT EXISTS `patrimonio_preventivas` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `patrimonio_id`   INT(11)      NOT NULL,
  `data_realizada`  DATE         NOT NULL,
  `descricao`       TEXT         NULL COMMENT 'O que foi feito na manutenção',
  `tecnico`         VARCHAR(255) NULL,
  `custo`           DECIMAL(10,2) NULL DEFAULT 0.00,
  `observacoes`     TEXT         NULL,
  `proxima_data`    DATE         NULL,
  `registrado_por`  INT(11)      NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_patrimonio` (`patrimonio_id`),
  INDEX `idx_data`       (`data_realizada`),
  FOREIGN KEY (`patrimonio_id`) REFERENCES `patrimonio`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de manutenções preventivas dos patrimônios';

-- ============================================
-- Tabela: patrimonio_sequencia
-- Controle do número sequencial do PAT
-- ============================================
CREATE TABLE IF NOT EXISTS `patrimonio_sequencia` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `ultimo_num`  INT(11) NOT NULL DEFAULT 0 COMMENT 'Último número PAT gerado',
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir registro inicial da sequência
INSERT IGNORE INTO `patrimonio_sequencia` (`id`, `ultimo_num`) VALUES (1, 0);

-- ============================================
-- FIM DO SCRIPT
-- ============================================
SELECT 'Módulo de Inventário/Patrimônio criado com sucesso!' AS resultado;
