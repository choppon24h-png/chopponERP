-- ============================================
-- MÓDULO DE GESTÃO DE ESTOQUE DE BARRIS
-- Sistema Chopp On Tap
-- Versão: 1.0
-- Data: 2025-12-05
-- ============================================

-- Tabela: Fornecedores
CREATE TABLE IF NOT EXISTS `fornecedores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(255) NOT NULL,
  `razao_social` VARCHAR(255) NULL,
  `cnpj` VARCHAR(18) NULL,
  `email` VARCHAR(255) NULL,
  `telefone` VARCHAR(20) NULL,
  `whatsapp` VARCHAR(20) NULL,
  `endereco` TEXT NULL,
  `cidade` VARCHAR(100) NULL,
  `estado` VARCHAR(2) NULL,
  `cep` VARCHAR(10) NULL,
  `contato_nome` VARCHAR(255) NULL COMMENT 'Nome do contato principal',
  `observacoes` TEXT NULL,
  `ativo` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_cnpj` (`cnpj`),
  INDEX `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Produtos (Barris)
CREATE TABLE IF NOT EXISTS `estoque_produtos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código único do produto',
  `codigo_barras` VARCHAR(100) NULL COMMENT 'Código de barras EAN',
  `qr_code` TEXT NULL COMMENT 'QR Code gerado',
  `nome` VARCHAR(255) NOT NULL,
  `descricao` TEXT NULL,
  `tamanho_litros` DECIMAL(10,2) NOT NULL COMMENT 'Tamanho em litros',
  `peso_kg` DECIMAL(10,2) NULL COMMENT 'Peso em kg',
  `fornecedor_id` INT(11) NULL,
  `estoque_minimo` INT(11) DEFAULT 0,
  `estoque_maximo` INT(11) DEFAULT 0,
  `estoque_atual` INT(11) DEFAULT 0,
  `custo_compra` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Último preço de compra',
  `preco_venda` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `markup_percentual` DECIMAL(10,2) NULL COMMENT 'Markup calculado',
  `markup_livre` TINYINT(1) DEFAULT 0 COMMENT 'Se 1, usuário define markup manualmente',
  `preco_100ml` DECIMAL(10,2) NULL COMMENT 'Preço por 100ml',
  `categoria` VARCHAR(100) NULL COMMENT 'Pilsen, IPA, Lager, etc',
  `lote` VARCHAR(50) NULL,
  `data_validade` DATE NULL,
  `ativo` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codigo` (`codigo`),
  INDEX `idx_codigo_barras` (`codigo_barras`),
  INDEX `idx_fornecedor` (`fornecedor_id`),
  INDEX `idx_ativo` (`ativo`),
  INDEX `idx_estoque_minimo` (`estoque_atual`, `estoque_minimo`),
  FOREIGN KEY (`fornecedor_id`) REFERENCES `fornecedores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Movimentações de Estoque
CREATE TABLE IF NOT EXISTS `estoque_movimentacoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `tipo` ENUM('entrada', 'saida', 'ajuste', 'inventario', 'consumo_tap') NOT NULL,
  `quantidade` INT(11) NOT NULL,
  `quantidade_anterior` INT(11) NOT NULL COMMENT 'Estoque antes da movimentação',
  `quantidade_nova` INT(11) NOT NULL COMMENT 'Estoque após movimentação',
  `custo_unitario` DECIMAL(10,2) NULL COMMENT 'Custo no momento da movimentação',
  `custo_anterior` DECIMAL(10,2) NULL COMMENT 'Custo anterior do produto',
  `markup_anterior` DECIMAL(10,2) NULL,
  `markup_novo` DECIMAL(10,2) NULL,
  `valor_total` DECIMAL(10,2) NULL COMMENT 'Quantidade * Custo',
  `lote` VARCHAR(50) NULL,
  `data_validade` DATE NULL,
  `fornecedor_id` INT(11) NULL,
  `nota_fiscal` VARCHAR(100) NULL,
  `tap_id` INT(11) NULL COMMENT 'Se consumo_tap, qual TAP consumiu',
  `motivo` TEXT NULL COMMENT 'Motivo da movimentação',
  `observacoes` TEXT NULL,
  `usuario_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_produto` (`produto_id`),
  INDEX `idx_tipo` (`tipo`),
  INDEX `idx_data` (`created_at`),
  INDEX `idx_usuario` (`usuario_id`),
  INDEX `idx_fornecedor` (`fornecedor_id`),
  INDEX `idx_tap` (`tap_id`),
  FOREIGN KEY (`produto_id`) REFERENCES `estoque_produtos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`fornecedor_id`) REFERENCES `fornecedores`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`usuario_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
  -- FOREIGN KEY (`tap_id`) REFERENCES `taps`(`id`) ON DELETE SET NULL -- Descomentar quando tabela taps existir
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Histórico de Preços
CREATE TABLE IF NOT EXISTS `estoque_historico_precos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `custo_anterior` DECIMAL(10,2) NOT NULL,
  `custo_novo` DECIMAL(10,2) NOT NULL,
  `variacao_percentual` DECIMAL(10,2) NULL,
  `preco_venda_anterior` DECIMAL(10,2) NULL,
  `preco_venda_novo` DECIMAL(10,2) NULL,
  `markup_anterior` DECIMAL(10,2) NULL,
  `markup_novo` DECIMAL(10,2) NULL,
  `motivo` TEXT NULL,
  `usuario_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_produto` (`produto_id`),
  INDEX `idx_data` (`created_at`),
  FOREIGN KEY (`produto_id`) REFERENCES `estoque_produtos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Alertas de Estoque
CREATE TABLE IF NOT EXISTS `estoque_alertas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `produto_id` INT(11) NOT NULL,
  `tipo_alerta` ENUM('estoque_minimo', 'estoque_maximo', 'validade_proxima', 'ruptura') NOT NULL,
  `mensagem` TEXT NOT NULL,
  `nivel` ENUM('info', 'warning', 'critical') DEFAULT 'warning',
  `lido` TINYINT(1) DEFAULT 0,
  `data_leitura` TIMESTAMP NULL,
  `usuario_leitura` INT(11) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_produto` (`produto_id`),
  INDEX `idx_tipo` (`tipo_alerta`),
  INDEX `idx_lido` (`lido`),
  INDEX `idx_data` (`created_at`),
  FOREIGN KEY (`produto_id`) REFERENCES `estoque_produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Inventário (Contagem Física)
CREATE TABLE IF NOT EXISTS `estoque_inventarios` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `titulo` VARCHAR(255) NOT NULL,
  `descricao` TEXT NULL,
  `data_inicio` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `data_conclusao` TIMESTAMP NULL,
  `status` ENUM('em_andamento', 'concluido', 'cancelado') DEFAULT 'em_andamento',
  `usuario_responsavel` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_data` (`data_inicio`),
  FOREIGN KEY (`usuario_responsavel`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Itens do Inventário
CREATE TABLE IF NOT EXISTS `estoque_inventario_itens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `inventario_id` INT(11) NOT NULL,
  `produto_id` INT(11) NOT NULL,
  `quantidade_sistema` INT(11) NOT NULL COMMENT 'Quantidade no sistema',
  `quantidade_fisica` INT(11) NULL COMMENT 'Quantidade contada fisicamente',
  `diferenca` INT(11) NULL COMMENT 'Diferença encontrada',
  `observacoes` TEXT NULL,
  `contado_por` INT(11) NULL,
  `data_contagem` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_inventario` (`inventario_id`),
  INDEX `idx_produto` (`produto_id`),
  FOREIGN KEY (`inventario_id`) REFERENCES `estoque_inventarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`produto_id`) REFERENCES `estoque_produtos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contado_por`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela: Log de Ações
CREATE TABLE IF NOT EXISTS `estoque_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tabela` VARCHAR(100) NOT NULL COMMENT 'Tabela afetada',
  `registro_id` INT(11) NOT NULL COMMENT 'ID do registro',
  `acao` ENUM('create', 'update', 'delete') NOT NULL,
  `dados_anteriores` LONGTEXT NULL COMMENT 'Dados antes da alteração',
  `dados_novos` LONGTEXT NULL COMMENT 'Dados após alteração',
  `usuario_id` INT(11) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tabela` (`tabela`),
  INDEX `idx_registro` (`registro_id`),
  INDEX `idx_usuario` (`usuario_id`),
  INDEX `idx_data` (`created_at`),
  FOREIGN KEY (`usuario_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERIR DADOS DE EXEMPLO
-- ============================================

-- Fornecedores de exemplo
INSERT INTO `fornecedores` (`nome`, `razao_social`, `cnpj`, `email`, `telefone`, `cidade`, `estado`, `ativo`) VALUES
('Cervejaria Artesanal Mineira', 'Cervejaria Artesanal Mineira LTDA', '12.345.678/0001-90', 'contato@cervejariamineira.com.br', '(31) 3333-4444', 'Belo Horizonte', 'MG', 1),
('Distribuidora de Bebidas BH', 'Distribuidora BH Bebidas LTDA', '98.765.432/0001-10', 'vendas@distribuidorabh.com.br', '(31) 2222-3333', 'Contagem', 'MG', 1),
('Chopp Premium Importados', 'Premium Importados S.A.', '11.222.333/0001-44', 'importados@premium.com.br', '(31) 4444-5555', 'Nova Lima', 'MG', 1);

-- ============================================
-- TRIGGERS PARA AUTOMAÇÃO
-- ============================================

-- Trigger: Atualizar estoque após movimentação
DELIMITER $$
CREATE TRIGGER `trg_atualizar_estoque_after_insert`
AFTER INSERT ON `estoque_movimentacoes`
FOR EACH ROW
BEGIN
    DECLARE novo_estoque INT;
    
    -- Calcular novo estoque
    IF NEW.tipo IN ('entrada', 'ajuste') THEN
        SET novo_estoque = NEW.quantidade_anterior + NEW.quantidade;
    ELSE
        SET novo_estoque = NEW.quantidade_anterior - NEW.quantidade;
    END IF;
    
    -- Atualizar estoque do produto
    UPDATE estoque_produtos 
    SET estoque_atual = novo_estoque,
        custo_compra = COALESCE(NEW.custo_unitario, custo_compra)
    WHERE id = NEW.produto_id;
    
    -- Verificar alertas de estoque mínimo
    INSERT INTO estoque_alertas (produto_id, tipo_alerta, mensagem, nivel)
    SELECT 
        NEW.produto_id,
        'estoque_minimo',
        CONCAT('Produto com estoque baixo: ', (SELECT nome FROM estoque_produtos WHERE id = NEW.produto_id)),
        'warning'
    FROM estoque_produtos
    WHERE id = NEW.produto_id 
      AND novo_estoque <= estoque_minimo
      AND estoque_minimo > 0
      AND NOT EXISTS (
          SELECT 1 FROM estoque_alertas 
          WHERE produto_id = NEW.produto_id 
            AND tipo_alerta = 'estoque_minimo' 
            AND lido = 0
      );
END$$
DELIMITER ;

-- ============================================
-- VIEWS ÚTEIS
-- ============================================

-- View: Produtos com estoque crítico
CREATE OR REPLACE VIEW `vw_estoque_critico` AS
SELECT 
    p.id,
    p.codigo,
    p.nome,
    p.tamanho_litros,
    p.estoque_atual,
    p.estoque_minimo,
    p.estoque_maximo,
    f.nome as fornecedor_nome,
    (p.estoque_minimo - p.estoque_atual) as quantidade_repor
FROM estoque_produtos p
LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
WHERE p.estoque_atual <= p.estoque_minimo
  AND p.ativo = 1
ORDER BY p.estoque_atual ASC;

-- View: Valor total do estoque
CREATE OR REPLACE VIEW `vw_valor_estoque` AS
SELECT 
    p.id,
    p.codigo,
    p.nome,
    p.estoque_atual,
    p.custo_compra,
    p.preco_venda,
    (p.estoque_atual * p.custo_compra) as valor_custo_total,
    (p.estoque_atual * p.preco_venda) as valor_venda_total,
    ((p.estoque_atual * p.preco_venda) - (p.estoque_atual * p.custo_compra)) as lucro_potencial
FROM estoque_produtos p
WHERE p.ativo = 1;

-- ============================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- ============================================

CREATE INDEX idx_estoque_produtos_tamanho ON estoque_produtos(tamanho_litros);
CREATE INDEX idx_estoque_produtos_categoria ON estoque_produtos(categoria);
CREATE INDEX idx_movimentacoes_tipo_data ON estoque_movimentacoes(tipo, created_at);

-- ============================================
-- FIM DO SCRIPT
-- ============================================

SELECT 'Módulo de Estoque criado com sucesso!' AS resultado;
