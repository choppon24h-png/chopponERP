-- ============================================================
-- MIGRAÇÃO: Vincular Conta Bancária às Formas de Pagamento
-- Versão: 1.1.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- PRÉ-REQUISITO: migration_financeiro_contas_bancarias.sql
-- ============================================================

-- BLOCO 1: conta_bancaria_id em formas_pagamento
ALTER TABLE `formas_pagamento`
  ADD COLUMN `conta_bancaria_id` INT(11) UNSIGNED NULL DEFAULT NULL
  AFTER `ativo`;

-- BLOCO 2: metodos_aceitos em formas_pagamento
ALTER TABLE `formas_pagamento`
  ADD COLUMN `metodos_aceitos` VARCHAR(255) NULL DEFAULT NULL
  AFTER `conta_bancaria_id`;

-- BLOCO 3: nome em formas_pagamento
ALTER TABLE `formas_pagamento`
  ADD COLUMN `nome` VARCHAR(120) NULL DEFAULT NULL
  AFTER `estabelecimento_id`;

-- BLOCO 4: lancamento_bancario_id em order
ALTER TABLE `order`
  ADD COLUMN `lancamento_bancario_id` INT(11) UNSIGNED NULL DEFAULT NULL
  AFTER `forma_pagamento_id`;
