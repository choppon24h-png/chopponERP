-- ============================================================
-- migration_tablet_devices.sql
-- Tabela para vincular tablets (device_id) a estabelecimentos
-- Permite que cada unidade controle quais tablets têm acesso
-- ============================================================

CREATE TABLE IF NOT EXISTS tablet_devices (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    device_id           VARCHAR(128) NOT NULL,
    estabelecimento_id  INT NOT NULL,
    device_name         VARCHAR(255) NULL COMMENT 'Nome amigável do tablet',
    status              TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=ativo, 0=inativo',
    registered_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        DATETIME NULL,
    registered_by       INT NULL COMMENT 'user_id que registrou o tablet',
    notes               TEXT NULL,
    UNIQUE KEY uk_device_estab (device_id, estabelecimento_id),
    INDEX idx_device_id         (device_id),
    INDEX idx_estabelecimento   (estabelecimento_id),
    INDEX idx_status            (status),
    CONSTRAINT fk_td_estab FOREIGN KEY (estabelecimento_id)
        REFERENCES estabelecimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tablets vinculados a estabelecimentos para controle de acesso QR Master';

-- ============================================================
-- Atualizar schema da tabela master_qr_tokens (se necessário)
-- ============================================================

-- Adicionar coluna status se não existir
ALTER TABLE master_qr_tokens
    ADD COLUMN IF NOT EXISTS status
        ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'
        AFTER device_id;

-- Adicionar colunas de aprovação se não existirem
ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_by       INT NULL AFTER expires_at;
ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_user_id  INT NULL AFTER approved_by;
ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_name     VARCHAR(255) NULL AFTER approved_user_id;
ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_type     TINYINT NULL AFTER approved_name;

-- Índice de status para queries de polling
ALTER TABLE master_qr_tokens ADD INDEX IF NOT EXISTS idx_status (status);
