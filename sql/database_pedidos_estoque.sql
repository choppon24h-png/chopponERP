-- ============================================================
-- MÓDULO: Pedidos de Estoque
-- Versão: 1.0.0
-- Descrição: Pedidos de saída de estoque para estabelecimentos
--            ou clientes finais, com fluxo de status e baixa
--            automática no estoque ao faturar.
-- ============================================================

-- Tabela principal de pedidos
CREATE TABLE IF NOT EXISTS `estoque_pedidos` (
  `id`                INT(11) NOT NULL AUTO_INCREMENT,
  `numero_pedido`     VARCHAR(20) NOT NULL COMMENT 'Número sequencial ex: PED-0001',

  -- Destinatário
  `tipo_destinatario` ENUM('estabelecimento','cliente_final') NOT NULL DEFAULT 'estabelecimento',
  `estabelecimento_id` BIGINT UNSIGNED NULL COMMENT 'FK para estabelecimentos (se tipo = estabelecimento)',

  -- Dados do cliente final (se tipo = cliente_final)
  `cliente_nome`      VARCHAR(255) NULL,
  `cliente_cpf_cnpj`  VARCHAR(20)  NULL,
  `cliente_email`     VARCHAR(255) NULL,
  `cliente_telefone`  VARCHAR(20)  NULL,

  -- Endereço de entrega
  `entrega`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = tem entrega',
  `entrega_cep`       VARCHAR(10)  NULL,
  `entrega_logradouro` VARCHAR(255) NULL,
  `entrega_numero`    VARCHAR(20)  NULL,
  `entrega_complemento` VARCHAR(100) NULL,
  `entrega_bairro`    VARCHAR(100) NULL,
  `entrega_cidade`    VARCHAR(100) NULL,
  `entrega_estado`    VARCHAR(2)   NULL,
  `entrega_taxa`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Taxa de entrega',

  -- Valores
  `subtotal`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `desconto`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Status do pedido
  `status`            ENUM('aguardando','visualizado','faturado','cancelado') NOT NULL DEFAULT 'aguardando',
  `data_faturamento`  TIMESTAMP NULL COMMENT 'Data em que foi faturado e baixa no estoque foi feita',

  -- Observações
  `observacoes`       TEXT NULL,

  -- Controle
  `criado_por`        INT(11) NOT NULL,
  `faturado_por`      INT(11) NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero_pedido` (`numero_pedido`),
  INDEX `idx_status`            (`status`),
  INDEX `idx_tipo_dest`         (`tipo_destinatario`),
  INDEX `idx_estabelecimento`   (`estabelecimento_id`),
  INDEX `idx_created`           (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens do pedido
CREATE TABLE IF NOT EXISTS `estoque_pedido_itens` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `pedido_id`       INT(11)      NOT NULL,
  `produto_id`      INT(11)      NOT NULL,
  `quantidade`      INT(11)      NOT NULL DEFAULT 1,
  `preco_unitario`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Preço no momento do pedido',
  `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'quantidade * preco_unitario',
  `observacoes`     VARCHAR(255) NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_pedido`  (`pedido_id`),
  INDEX `idx_produto` (`produto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequência de numeração de pedidos
CREATE TABLE IF NOT EXISTS `estoque_pedidos_sequencia` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `ultimo_num` INT(11) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `estoque_pedidos_sequencia` (`id`, `ultimo_num`) VALUES (1, 0);
