-- ============================================================
-- MIGRAÇÃO: Log de Auditoria de Acessos de Usuários
-- Versão: 1.0.0 | MySQL 5.7 Hostgator | Choppon ERP
-- Data: 2026-06-04
--
-- OBJETIVO:
--   Registrar todos os eventos de acesso do sistema para auditoria:
--   login bem-sucedido, login falho, logout, troca de estabelecimento,
--   acesso a páginas administrativas e ações críticas (criar/editar/excluir).
--
-- EXECUTE CADA BLOCO SEPARADAMENTE no phpMyAdmin
-- ⚠️  Verificar antes: DESCRIBE users;
-- ============================================================

-- ── BLOCO 1: Tabela principal de log de auditoria ─────────────────────────────
CREATE TABLE `audit_log` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NULL DEFAULT NULL
                        COMMENT 'ID do usuário (NULL para tentativas de login falhas)',
  `user_name`           VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'Nome do usuário no momento do evento (snapshot)',
  `user_email`          VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'E-mail do usuário no momento do evento (snapshot)',
  `user_type`           TINYINT(1) NULL DEFAULT NULL
                        COMMENT '1=Admin Geral, 2=Admin Estab, 3=Gerente, 4=Operador',
  `estabelecimento_id`  INT(11) UNSIGNED NULL DEFAULT NULL
                        COMMENT 'Estabelecimento ativo no momento do evento',
  `estabelecimento_nome` VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'Nome do estabelecimento (snapshot)',
  `evento`              ENUM(
                          'login_ok',
                          'login_falha',
                          'logout',
                          'troca_estabelecimento',
                          'acesso_pagina',
                          'criar',
                          'editar',
                          'excluir',
                          'exportar',
                          'visualizar_relatorio'
                        ) NOT NULL COMMENT 'Tipo do evento registrado',
  `pagina`              VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'URL/página acessada (ex: admin/pedidos.php)',
  `descricao`           VARCHAR(500) NULL DEFAULT NULL
                        COMMENT 'Descrição detalhada do evento',
  `ip`                  VARCHAR(45) NOT NULL DEFAULT ''
                        COMMENT 'Endereço IP do cliente (suporta IPv6)',
  `user_agent`          VARCHAR(500) NULL DEFAULT NULL
                        COMMENT 'User-Agent do navegador/dispositivo',
  `session_id`          VARCHAR(128) NULL DEFAULT NULL
                        COMMENT 'ID da sessão PHP para correlacionar eventos',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        COMMENT 'Data e hora do evento',
  PRIMARY KEY (`id`),
  KEY `idx_al_user_id`             (`user_id`),
  KEY `idx_al_evento`              (`evento`),
  KEY `idx_al_estabelecimento`     (`estabelecimento_id`),
  KEY `idx_al_created_at`          (`created_at`),
  KEY `idx_al_ip`                  (`ip`(20)),
  KEY `idx_al_user_evento`         (`user_id`, `evento`),
  KEY `idx_al_user_created`        (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Log de auditoria de todos os acessos e ações do sistema';

-- ── BLOCO 2: Registrar página de log na tabela de permissões ─────────────────
INSERT INTO `system_pages`
  (`page_key`, `page_name`, `page_url`, `page_icon`, `page_category`, `admin_only`)
VALUES
  ('audit_log', 'Log de Auditoria', 'admin/audit_log.php', 'fas fa-shield-alt', 'usuarios', 1);

-- ── BLOCO 3: Conceder acesso ao Admin Geral ───────────────────────────────────
INSERT INTO `user_permissions` (`user_id`, `page_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, p.id, 1, 0, 0, 0
FROM `users` u
CROSS JOIN `system_pages` p
WHERE p.page_key = 'audit_log'
  AND u.type = 1
  AND NOT EXISTS (
    SELECT 1 FROM `user_permissions` up2
    WHERE up2.user_id = u.id AND up2.page_id = p.id
  );

-- ============================================================
-- ROLLBACK (execute em ordem inversa se necessário)
-- ============================================================
-- DELETE FROM `user_permissions` WHERE page_id = (SELECT id FROM system_pages WHERE page_key = 'audit_log');
-- DELETE FROM `system_pages` WHERE page_key = 'audit_log';
-- DROP TABLE `audit_log`;

-- ============================================================
-- VALIDAÇÃO (execute após a migração para confirmar)
-- ============================================================
-- DESCRIBE `audit_log`;
-- SELECT COUNT(*) AS total FROM `audit_log`;
-- SELECT page_key, page_name FROM system_pages WHERE page_key = 'audit_log';
