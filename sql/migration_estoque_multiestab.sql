-- ============================================================
-- MIGRAÇÃO: Estoque Multi-Estabelecimento
-- Versão: 1.1.0
-- Descrição: Adiciona estabelecimento_id nas tabelas de estoque
--            e inventário/patrimônio para separar os dados de
--            cada unidade franqueada.
-- Compatível: MariaDB 10.3+ / MySQL 8.0+
-- ============================================================

-- ── 1. Adicionar estabelecimento_id em estoque_produtos ──────
ALTER TABLE `estoque_produtos`
  ADD COLUMN IF NOT EXISTS `estabelecimento_id` INT(11) NULL
    COMMENT 'Unidade dona deste produto de estoque'
    AFTER `id`;

CREATE INDEX IF NOT EXISTS `idx_estoque_produtos_estab`
  ON `estoque_produtos` (`estabelecimento_id`);

-- ── 2. Adicionar estabelecimento_id em estoque_movimentacoes ─
ALTER TABLE `estoque_movimentacoes`
  ADD COLUMN IF NOT EXISTS `estabelecimento_id` INT(11) NULL
    COMMENT 'Unidade onde ocorreu a movimentação'
    AFTER `id`;

CREATE INDEX IF NOT EXISTS `idx_estoque_mov_estab`
  ON `estoque_movimentacoes` (`estabelecimento_id`);

-- ── 3. Adicionar estabelecimento_id em estoque_pedidos ───────
-- Nota: estoque_pedidos já tem estabelecimento_id como DESTINATÁRIO.
-- Adicionamos estab_origem_id para indicar qual unidade CRIOU o pedido.
ALTER TABLE `estoque_pedidos`
  ADD COLUMN IF NOT EXISTS `estab_origem_id` INT(11) NULL
    COMMENT 'Unidade que criou/originou o pedido'
    AFTER `id`;

CREATE INDEX IF NOT EXISTS `idx_estoque_ped_origem`
  ON `estoque_pedidos` (`estab_origem_id`);

-- ── 4. Adicionar estabelecimento_id em patrimônio (inventário) ─
ALTER TABLE `patrimonio`
  ADD COLUMN IF NOT EXISTS `estabelecimento_id` BIGINT UNSIGNED NULL
    COMMENT 'Unidade a que pertence este item patrimonial'
    AFTER `updated_at`;

CREATE INDEX IF NOT EXISTS `idx_patrimonio_estab`
  ON `patrimonio` (`estabelecimento_id`);

-- ── 5. Preencher estabelecimento_id nos registros existentes ─
-- Todos os registros sem estabelecimento_id são atribuídos ao
-- estabelecimento 1 (matriz/padrão).
UPDATE `estoque_produtos`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

UPDATE `estoque_movimentacoes`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

UPDATE `estoque_pedidos`
  SET `estab_origem_id` = 1
  WHERE `estab_origem_id` IS NULL;

UPDATE `patrimonio`
  SET `estabelecimento_id` = 1
  WHERE `estabelecimento_id` IS NULL;

-- ── 6. Verificação final ─────────────────────────────────────
SELECT 'estoque_produtos' AS tabela,
       COUNT(*) AS total,
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END) AS sem_estab
FROM estoque_produtos
UNION ALL
SELECT 'estoque_movimentacoes',
       COUNT(*),
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END)
FROM estoque_movimentacoes
UNION ALL
SELECT 'estoque_pedidos (origem)',
       COUNT(*),
       SUM(CASE WHEN estab_origem_id IS NULL THEN 1 ELSE 0 END)
FROM estoque_pedidos
UNION ALL
SELECT 'patrimonio',
       COUNT(*),
       SUM(CASE WHEN estabelecimento_id IS NULL THEN 1 ELSE 0 END)
FROM patrimonio;
