-- ============================================================
-- MIGRAÇÃO: Módulo Financeiro — Contas Bancárias + Centro de Custo
-- Versão: 1.0.0 | MySQL 5.7 | Choppon ERP
-- Data: 2026-06-02
-- ⚠️  ANTES DE EXECUTAR:
--     1. Faça backup do banco de dados
--     2. Execute: DESCRIBE contas_pagar;
--        Confirme que NÃO existem: centro_custo, classificacao_financeira, conta_bancaria_id
--     3. Execute: SHOW TABLES LIKE 'contas_bancarias';
--        Confirme que a tabela NÃO existe
--     4. Execute em horário de baixo tráfego (ALTER TABLE bloqueia a tabela)
-- ============================================================

-- ============================================================
-- BLOCO 1: Tabela contas_bancarias
-- Cadastro de contas bancárias por estabelecimento
-- ============================================================

CREATE TABLE `contas_bancarias` (
  `id`                  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id`  INT(11) UNSIGNED NOT NULL COMMENT 'Unidade franqueada dona da conta',
  `nome`                VARCHAR(120) NOT NULL COMMENT 'Nome/apelido da conta (ex: Caixa, Bradesco PJ)',
  `banco`               VARCHAR(100) NULL DEFAULT NULL COMMENT 'Nome do banco (ex: Bradesco, Nubank)',
  `agencia`             VARCHAR(20)  NULL DEFAULT NULL COMMENT 'Número da agência',
  `conta`               VARCHAR(30)  NULL DEFAULT NULL COMMENT 'Número da conta com dígito',
  `tipo`                ENUM('corrente','poupanca','caixa','pix','investimento','outro') NOT NULL DEFAULT 'corrente' COMMENT 'Tipo da conta',
  `saldo_inicial`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo inicial ao cadastrar',
  `saldo_atual`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo calculado automaticamente',
  `ativa`               TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=ativa, 0=inativa',
  `observacoes`         TEXT NULL DEFAULT NULL,
  `created_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cb_estabelecimento` (`estabelecimento_id`),
  KEY `idx_cb_ativa` (`ativa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Contas bancárias por estabelecimento';

-- ============================================================
-- BLOCO 2: Tabela movimentacoes_bancarias
-- Lançamentos de entrada/saída por conta bancária
-- ============================================================

CREATE TABLE `movimentacoes_bancarias` (
  `id`                  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conta_bancaria_id`   INT(11) UNSIGNED NOT NULL COMMENT 'Conta bancária vinculada',
  `estabelecimento_id`  INT(11) UNSIGNED NOT NULL COMMENT 'Unidade franqueada',
  `tipo`                ENUM('entrada','saida','transferencia') NOT NULL COMMENT 'Tipo da movimentação',
  `descricao`           VARCHAR(255) NOT NULL COMMENT 'Descrição da movimentação',
  `valor`               DECIMAL(12,2) NOT NULL COMMENT 'Valor da movimentação',
  `data_movimentacao`   DATE NOT NULL COMMENT 'Data da movimentação',
  `categoria`           VARCHAR(100) NULL DEFAULT NULL COMMENT 'Categoria (ex: Receita Operacional, Despesa Fixa)',
  `centro_custo`        VARCHAR(100) NULL DEFAULT NULL COMMENT 'Centro de custo',
  `classificacao`       VARCHAR(100) NULL DEFAULT NULL COMMENT 'Classificação financeira (ex: Despesa Fixa, Receita)',
  `conta_destino_id`    INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'Conta destino (apenas para transferências)',
  `conta_pagar_id`      INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'Vínculo com contas_pagar (opcional)',
  `comprovante`         VARCHAR(255) NULL DEFAULT NULL COMMENT 'Caminho do comprovante (upload)',
  `observacoes`         TEXT NULL DEFAULT NULL,
  `created_by`          INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'Usuário que lançou',
  `created_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mb_conta` (`conta_bancaria_id`),
  KEY `idx_mb_estabelecimento` (`estabelecimento_id`),
  KEY `idx_mb_data` (`data_movimentacao`),
  KEY `idx_mb_tipo` (`tipo`),
  KEY `idx_mb_centro_custo` (`centro_custo`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Movimentações financeiras por conta bancária';

-- ============================================================
-- BLOCO 3: Adicionar campos em contas_pagar
-- centro_custo, classificacao_financeira, conta_bancaria_id
-- ============================================================

ALTER TABLE `contas_pagar`
  ADD COLUMN `centro_custo`           VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Centro de custo (ex: Operacional, Administrativo, Marketing)'
    AFTER `observacoes`;

ALTER TABLE `contas_pagar`
  ADD COLUMN `classificacao_financeira` VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Classificação financeira (ex: Despesa Fixa, Despesa Variável, Investimento)'
    AFTER `centro_custo`;

ALTER TABLE `contas_pagar`
  ADD COLUMN `conta_bancaria_id`      INT(11) UNSIGNED NULL DEFAULT NULL
    COMMENT 'Conta bancária vinculada ao pagamento'
    AFTER `classificacao_financeira`;

-- ============================================================
-- BLOCO 4: Índices nos novos campos de contas_pagar
-- ============================================================

ALTER TABLE `contas_pagar`
  ADD INDEX `idx_cp_centro_custo` (`centro_custo`(50));

ALTER TABLE `contas_pagar`
  ADD INDEX `idx_cp_classificacao` (`classificacao_financeira`(50));

ALTER TABLE `contas_pagar`
  ADD INDEX `idx_cp_conta_bancaria` (`conta_bancaria_id`);

-- ============================================================
-- BLOCO 5: Preencher registros existentes com valores padrão
-- ============================================================

UPDATE `contas_pagar`
  SET `centro_custo`            = 'Operacional',
      `classificacao_financeira` = 'Despesa Fixa'
  WHERE `centro_custo` IS NULL;

-- ============================================================
-- BLOCO 6: Validação — execute após a migração
-- ============================================================

-- Verificar novas colunas em contas_pagar
SELECT COUNT(*) AS total_contas FROM `contas_pagar`;
SELECT `centro_custo`, `classificacao_financeira`, COUNT(*) AS qtd
FROM `contas_pagar`
GROUP BY `centro_custo`, `classificacao_financeira`
ORDER BY qtd DESC;

-- Verificar tabelas criadas
SELECT COUNT(*) AS contas_bancarias_criadas FROM `contas_bancarias`;
SELECT COUNT(*) AS movimentacoes_criadas FROM `movimentacoes_bancarias`;

-- ============================================================
-- ROLLBACK — descomente para reverter
-- ============================================================

-- ALTER TABLE `contas_pagar` DROP INDEX `idx_cp_conta_bancaria`;
-- ALTER TABLE `contas_pagar` DROP INDEX `idx_cp_classificacao`;
-- ALTER TABLE `contas_pagar` DROP INDEX `idx_cp_centro_custo`;
-- ALTER TABLE `contas_pagar` DROP COLUMN `conta_bancaria_id`;
-- ALTER TABLE `contas_pagar` DROP COLUMN `classificacao_financeira`;
-- ALTER TABLE `contas_pagar` DROP COLUMN `centro_custo`;
-- DROP TABLE IF EXISTS `movimentacoes_bancarias`;
-- DROP TABLE IF EXISTS `contas_bancarias`;
