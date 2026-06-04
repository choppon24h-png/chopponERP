-- ============================================================
-- MIGRAÇÃO: Roteamento de Meios de Pagamento por Conta Bancária
-- Versão: 1.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- PRÉ-REQUISITO: migration_financeiro_contas_bancarias.sql
-- ============================================================

-- BLOCO 1: Adicionar coluna meios_pagamento_aceitos em contas_bancarias
-- (Execute apenas se não existir — verifique com: DESCRIBE contas_bancarias;)
ALTER TABLE `contas_bancarias`
  ADD COLUMN `meios_pagamento_aceitos` VARCHAR(255) NULL DEFAULT NULL
  AFTER `ativa`;

-- BLOCO 2: Registrar nova página no sistema de permissões
INSERT INTO `system_pages`
  (`page_key`, `page_name`, `page_url`, `page_icon`, `page_category`, `admin_only`)
VALUES
  ('meios_pagamento_contas', 'Roteamento por Conta', 'admin/meios_pagamento_contas.php', 'fas fa-route', 'pagamentos', 0);

-- BLOCO 3: Conceder acesso à nova página para todos os usuários admin
INSERT INTO `user_permissions` (`user_id`, `page_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, p.id, 1, 1, 1, 1
FROM `users` u
CROSS JOIN `system_pages` p
WHERE p.page_key = 'meios_pagamento_contas'
  AND u.tipo = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `user_permissions` up2
    WHERE up2.user_id = u.id AND up2.page_id = p.id
  );
