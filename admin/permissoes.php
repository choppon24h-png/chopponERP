<?php
$page_title   = 'Gerenciar Permissões';
$current_page = 'permissoes';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';

requireAdminGeral();

$conn = getDBConnection();

// ── Garantir tabela de tokens QR ──────────────────────────────────────────────
$conn->exec("
    CREATE TABLE IF NOT EXISTS master_qr_tokens (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        token         VARCHAR(64) NOT NULL UNIQUE,
        created_by    INT NOT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at    DATETIME NOT NULL,
        used_at       DATETIME NULL,
        revoked       TINYINT(1) NOT NULL DEFAULT 0,
        device_id     VARCHAR(64) NULL,
        INDEX idx_token   (token),
        INDEX idx_user_id (user_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$success = '';
$error   = '';

// ── Salvar permissões ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    $user_id     = $_POST['user_id'];
    $permissions = $_POST['permissions'] ?? [];
    if (saveUserPermissions($user_id, $permissions)) {
        $success = 'Permissões atualizadas com sucesso!';
    } else {
        $error = 'Erro ao atualizar permissões.';
    }
}

// ── Listar usuários com QR status ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.*,
           GROUP_CONCAT(e.name ORDER BY e.name SEPARATOR ', ') AS estabelecimentos,
           (
               SELECT COUNT(*) FROM master_qr_tokens mqt
               WHERE mqt.user_id = u.id
                 AND mqt.revoked = 0
                 AND mqt.expires_at > NOW()
           ) AS qr_ativo
    FROM users u
    LEFT JOIN user_estabelecimento ue ON u.id = ue.user_id AND ue.status = 1
    LEFT JOIN estabelecimentos e ON ue.estabelecimento_id = e.id
    WHERE u.id != ?
    GROUP BY u.id
    ORDER BY u.type ASC, u.name ASC
");
$stmt->execute([$_SESSION['user_id']]);
$usuarios = $stmt->fetchAll();

// ── Contadores por tipo ───────────────────────────────────────────────────────
$total_usuarios = count($usuarios);
$total_gerentes = count(array_filter($usuarios, fn($u) => $u['type'] == 2));
$total_operadores = count(array_filter($usuarios, fn($u) => $u['type'] == 3));
$total_qr_ativo = count(array_filter($usuarios, fn($u) => $u['qr_ativo'] > 0));

// ── Páginas por categoria ─────────────────────────────────────────────────────
$pages_by_category = getPagesByCategory();

require_once '../includes/header.php';
?>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="page-header">
    <h1><i class="fas fa-user-shield"></i> Gerenciar Permissões</h1>
    <div class="page-header-actions">
        <span class="text-muted" style="font-size:13px;">
            <i class="fas fa-users"></i> <?= $total_usuarios ?> usuário<?= $total_usuarios != 1 ? 's' : '' ?> cadastrado<?= $total_usuarios != 1 ? 's' : '' ?>
        </span>
    </div>
</div>

<!-- ── Alertas ───────────────────────────────────────────────────────────────── -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    <button type="button" class="close-alert" onclick="this.parentElement.remove()">&times;</button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="close-alert" onclick="this.parentElement.remove()">&times;</button>
</div>
<?php endif; ?>

<!-- ── Cards de Resumo ───────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
    <div class="stats-card">
        <div class="stats-icon" style="background:rgba(0,102,204,0.12);color:var(--primary-color);">
            <i class="fas fa-users"></i>
        </div>
        <div class="stats-info">
            <h3><?= $total_usuarios ?></h3>
            <p>Total de Usuários</p>
        </div>
    </div>
    <div class="stats-card">
        <div class="stats-icon" style="background:rgba(40,167,69,0.12);color:#28a745;">
            <i class="fas fa-user-tie"></i>
        </div>
        <div class="stats-info">
            <h3><?= $total_gerentes ?></h3>
            <p>Gerentes</p>
        </div>
    </div>
    <div class="stats-card">
        <div class="stats-icon" style="background:rgba(23,162,184,0.12);color:#17a2b8;">
            <i class="fas fa-user-cog"></i>
        </div>
        <div class="stats-info">
            <h3><?= $total_operadores ?></h3>
            <p>Operadores</p>
        </div>
    </div>
    <div class="stats-card">
        <div class="stats-icon" style="background:rgba(40,167,69,0.12);color:#28a745;">
            <i class="fas fa-qrcode"></i>
        </div>
        <div class="stats-info">
            <h3><?= $total_qr_ativo ?></h3>
            <p>QR Master Ativo</p>
        </div>
    </div>
</div>

<!-- ── Layout principal: lista + painel ─────────────────────────────────────── -->
<div class="row" style="align-items:flex-start;">

    <!-- ── Coluna Esquerda: Lista de Usuários ──────────────────────────────── -->
    <div class="col-md-4" style="position:sticky;top:20px;">
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title mb-0">
                    <i class="fas fa-users" style="color:var(--primary-color);margin-right:8px;"></i>
                    Usuários
                </h3>
                <span class="badge-count"><?= $total_usuarios ?></span>
            </div>
            <div class="card-body" style="padding:0;">

                <!-- Busca rápida -->
                <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">
                    <div style="position:relative;">
                        <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:13px;"></i>
                        <input type="text" id="userSearch" placeholder="Buscar usuário..."
                               oninput="filtrarUsuarios(this.value)"
                               style="width:100%;padding:8px 10px 8px 32px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;outline:none;">
                    </div>
                </div>

                <!-- Lista -->
                <div id="userList" style="max-height:520px;overflow-y:auto;">
                    <?php foreach ($usuarios as $u):
                        $type_label = ['1'=>'Admin Geral','2'=>'Gerente','3'=>'Operador','4'=>'Visualizador'][$u['type']] ?? 'Usuário';
                        $type_color = ['1'=>'#dc3545','2'=>'#0066CC','3'=>'#17a2b8','4'=>'#6c757d'][$u['type']] ?? '#6c757d';
                        $initials   = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', $u['name']), 0, 2))));
                    ?>
                    <div class="user-item"
                         data-user-id="<?= $u['id'] ?>"
                         data-user-name="<?= htmlspecialchars($u['name']) ?>"
                         data-user-type="<?= $u['type'] ?>"
                         data-user-email="<?= htmlspecialchars($u['email']) ?>"
                         data-qr-ativo="<?= $u['qr_ativo'] > 0 ? '1' : '0' ?>"
                         data-search="<?= strtolower(htmlspecialchars($u['name'] . ' ' . $u['email'] . ' ' . ($u['estabelecimentos'] ?? ''))) ?>"
                         onclick="selecionarUsuario(this)">

                        <div class="user-item-avatar" style="background:<?= $type_color ?>20;color:<?= $type_color ?>;">
                            <?= $initials ?>
                        </div>

                        <div class="user-item-info">
                            <div class="user-item-name"><?= htmlspecialchars($u['name']) ?></div>
                            <div class="user-item-email"><?= htmlspecialchars($u['email']) ?></div>
                            <?php if ($u['estabelecimentos']): ?>
                            <div class="user-item-estab">
                                <i class="fas fa-store"></i> <?= htmlspecialchars($u['estabelecimentos']) ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="user-item-badges">
                            <span class="user-type-badge" style="background:<?= $type_color ?>20;color:<?= $type_color ?>;border:1px solid <?= $type_color ?>40;">
                                <?= $type_label ?>
                            </span>
                            <?php if ($u['qr_ativo'] > 0): ?>
                            <span class="user-qr-badge" title="QR Code Master ativo">
                                <i class="fas fa-qrcode"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div id="userListEmpty" style="display:none;padding:32px 16px;text-align:center;color:#aaa;">
                        <i class="fas fa-search" style="font-size:24px;margin-bottom:8px;display:block;"></i>
                        Nenhum usuário encontrado
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Coluna Direita: Painel de Permissões ────────────────────────────── -->
    <div class="col-md-8">

        <!-- Estado vazio -->
        <div id="noSelectionMessage" class="card">
            <div class="card-body" style="padding:64px 32px;text-align:center;">
                <div style="width:80px;height:80px;border-radius:50%;background:#f0f4ff;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                    <i class="fas fa-user-lock" style="font-size:32px;color:#c0cce0;"></i>
                </div>
                <h4 style="color:#aab;margin-bottom:8px;">Selecione um usuário</h4>
                <p class="text-muted" style="font-size:14px;">Clique em um usuário na lista para gerenciar suas permissões de acesso.</p>
            </div>
        </div>

        <!-- Painel principal -->
        <div id="permissionsPanel" style="display:none;">

            <!-- Cabeçalho do usuário selecionado -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-body" style="padding:16px 20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div id="selectedAvatar" class="selected-avatar"></div>
                            <div>
                                <h3 id="selectedUserName" style="margin:0;font-size:18px;"></h3>
                                <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                                    <span id="selectedUserTypeBadge" class="user-type-badge"></span>
                                    <span id="selectedUserEmail" style="font-size:12px;color:#888;"></span>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <div id="qrStatusBadge"></div>
                            <button class="btn btn-secondary btn-sm" onclick="cancelSelection()">
                                <i class="fas fa-times"></i> Fechar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Seção QR Code Master -->
            <div class="card" style="margin-bottom:16px;border-left:4px solid var(--primary-color);">
                <div class="card-header" style="background:linear-gradient(135deg,#f0f7ff,#e8f4fd);">
                    <h3 class="card-title mb-0" style="color:var(--primary-color);">
                        <i class="fas fa-qrcode"></i> Acesso Master via QR Code
                    </h3>
                </div>
                <div class="card-body">
                    <p style="color:#555;font-size:13px;margin-bottom:16px;line-height:1.6;">
                        Clique em <strong>Habilitar</strong> para escanear o QR Code exibido no tablet Android
                        (tela de Acesso Master). O sistema validará o código e liberará o acesso ao
                        ServiceTools automaticamente.
                    </p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button class="btn btn-primary" id="btnHabilitarQr" onclick="abrirScannerQr()">
                            <i class="fas fa-camera"></i> Habilitar (Escanear QR Code do Tablet)
                        </button>
                    </div>
                    <div id="qrResultArea" style="display:none;margin-top:12px;"></div>
                </div>
            </div>

            <!-- Permissões de módulos -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-key" style="color:var(--primary-color);margin-right:8px;"></i>
                        Permissões de Acesso
                    </h3>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="btn btn-sm" style="background:#f0f4ff;color:var(--primary-color);border:1px solid #c0d4f0;" onclick="toggleAllPermissions(true)">
                            <i class="fas fa-check-double"></i> Marcar Todos
                        </button>
                        <button type="button" class="btn btn-sm" style="background:#fff5f5;color:#dc3545;border:1px solid #f5c6cb;" onclick="toggleAllPermissions(false)">
                            <i class="fas fa-times"></i> Desmarcar Todos
                        </button>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <form method="POST" id="permissionsForm">
                        <input type="hidden" name="action" value="save_permissions">
                        <input type="hidden" name="user_id" id="selectedUserId">

                        <div id="permissionsContent" style="padding:16px;"></div>

                        <div style="padding:16px;border-top:1px solid #f0f0f0;display:flex;gap:10px;background:#fafafa;border-radius:0 0 8px 8px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Permissões
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="cancelSelection()">
                                <i class="fas fa-times"></i> Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
        <!-- fim #permissionsPanel -->

    </div>
</div>

<!-- ── Modal de confirmação de revogação ─────────────────────────────────────── -->
<div id="modalRevogar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:none;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:12px;padding:32px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="width:64px;height:64px;border-radius:50%;background:#fde8e8;color:#dc3545;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 16px;">
            <i class="fas fa-ban"></i>
        </div>
        <h4 style="margin-bottom:12px;">Revogar Acesso QR Code?</h4>
        <p style="color:#666;margin-bottom:24px;font-size:14px;">O QR Code ativo de <strong id="modalUserName"></strong> será invalidado imediatamente.</p>
        <div style="display:flex;gap:12px;justify-content:center;">
            <button class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
            <button class="btn btn-danger" onclick="confirmarRevogacao()">Revogar</button>
        </div>
    </div>
</div>

<!-- ── Modal Scanner QR ───────────────────────────────────────────────────────── -->
<!-- Criado dinamicamente via JS -->

<style>
/* ── Usuário item ────────────────────────────────────────────────────────────── */
.user-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f5f5f5;
    transition: background 0.15s;
    position: relative;
}
.user-item:last-child { border-bottom: none; }
.user-item:hover  { background: #f8faff; }
.user-item.active { background: #f0f6ff; border-left: 3px solid var(--primary-color); }

.user-item-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; flex-shrink: 0;
}
.user-item-info { flex: 1; min-width: 0; }
.user-item-name  { font-weight: 600; font-size: 14px; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-item-email { font-size: 12px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-item-estab { font-size: 11px; color: #aaa; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-item-estab i { margin-right: 3px; }

.user-item-badges { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.user-type-badge {
    font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 20px;
    white-space: nowrap;
}
.user-qr-badge {
    background: #d4edda; color: #28a745; font-size: 11px;
    padding: 3px 7px; border-radius: 20px; border: 1px solid #c3e6cb;
}

.badge-count {
    background: var(--primary-color); color: white;
    font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 20px;
}

/* ── Avatar selecionado ─────────────────────────────────────────────────────── */
.selected-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700; flex-shrink: 0;
}

/* ── Permissões ─────────────────────────────────────────────────────────────── */
.perm-category {
    margin-bottom: 20px;
}
.perm-category-header {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px;
    background: #f0f4ff;
    border-radius: 8px 8px 0 0;
    border: 1px solid #d8e4f8;
    font-weight: 700; font-size: 14px; color: var(--primary-color);
}
.perm-category-header i { font-size: 15px; }

.perm-table {
    width: 100%; border-collapse: collapse;
    border: 1px solid #e8eef8; border-top: none;
    border-radius: 0 0 8px 8px; overflow: hidden;
}
.perm-table th {
    background: #f8f9fa; font-size: 12px; font-weight: 600; color: #666;
    padding: 8px 12px; text-align: center; border-bottom: 1px solid #e8eef8;
}
.perm-table th:first-child { text-align: left; }
.perm-table td {
    padding: 10px 12px; border-bottom: 1px solid #f5f5f5;
    font-size: 13px; vertical-align: middle;
}
.perm-table tr:last-child td { border-bottom: none; }
.perm-table tr:hover td { background: #fafcff; }
.perm-table td:not(:first-child) { text-align: center; }

.perm-name { display: flex; align-items: center; gap: 8px; font-weight: 500; }
.perm-name i { color: var(--primary-color); width: 16px; text-align: center; }
.admin-only-badge {
    background: #fde8e8; color: #dc3545; border: 1px solid #f5c6cb;
    font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;
    text-transform: uppercase; letter-spacing: 0.5px;
}

.perm-checkbox {
    width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary-color);
}
.perm-checkbox:disabled { opacity: 0.4; cursor: not-allowed; }

/* ── QR result ──────────────────────────────────────────────────────────────── */
.qr-result-success, .qr-result-error {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-radius: 8px; font-size: 14px;
}
.qr-result-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.qr-result-success i { font-size: 20px; color: #28a745; flex-shrink: 0; }
.qr-result-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.qr-result-error i { font-size: 20px; color: #dc3545; flex-shrink: 0; }

/* ── Scanner modal ──────────────────────────────────────────────────────────── */
.qr-scanner-box {
    background: white; border-radius: 16px; padding: 28px;
    max-width: 520px; width: 92%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}
.qr-video-wrapper {
    position: relative; border-radius: 10px; overflow: hidden;
    background: #000; min-height: 280px;
    display: flex; align-items: center; justify-content: center;
}
.qr-scanner-frame {
    width: 200px; height: 200px;
    border: 3px solid #E87722; border-radius: 12px;
    box-shadow: 0 0 0 9999px rgba(0,0,0,0.35);
    animation: scanPulse 1.5s ease-in-out infinite;
}
@keyframes scanPulse {
    0%,100% { border-color:#E87722; box-shadow:0 0 0 9999px rgba(0,0,0,0.35),0 0 0 0 rgba(232,119,34,0.4); }
    50%      { border-color:#ff9a3c; box-shadow:0 0 0 9999px rgba(0,0,0,0.35),0 0 8px 4px rgba(232,119,34,0.6); }
}

/* ── Responsivo ─────────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .stats-grid { grid-template-columns: repeat(2,1fr) !important; }
    .perm-table th:nth-child(3),
    .perm-table td:nth-child(3),
    .perm-table th:nth-child(4),
    .perm-table td:nth-child(4) { display: none; }
}

/* ── Alert dismissível ──────────────────────────────────────────────────────── */
.alert-dismissible { position: relative; padding-right: 40px; }
.close-alert {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; font-size: 18px; cursor: pointer;
    color: inherit; opacity: 0.6; line-height: 1;
}
.close-alert:hover { opacity: 1; }
</style>

<script>
const pagesByCategory = <?= json_encode($pages_by_category) ?>;

let currentUserId   = null;
let currentUserName = '';
let qrAtivoAtual    = false;

// ── Filtro de busca ───────────────────────────────────────────────────────────
function filtrarUsuarios(q) {
    const term  = q.toLowerCase().trim();
    const items = document.querySelectorAll('.user-item');
    let visible = 0;
    items.forEach(item => {
        const match = !term || item.dataset.search.includes(term);
        item.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('userListEmpty').style.display = visible === 0 ? 'block' : 'none';
}

// ── Selecionar usuário ────────────────────────────────────────────────────────
function selecionarUsuario(el) {
    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    currentUserId   = el.dataset.userId;
    currentUserName = el.dataset.userName;
    qrAtivoAtual    = el.dataset.qrAtivo === '1';

    // Atualizar cabeçalho
    document.getElementById('selectedUserId').value = currentUserId;
    document.getElementById('selectedUserName').textContent = currentUserName;
    document.getElementById('selectedUserEmail').textContent = el.dataset.userEmail;

    const typeLabels = {'1':'Admin Geral','2':'Gerente','3':'Operador','4':'Visualizador'};
    const typeColors = {'1':'#dc3545','2':'#0066CC','3':'#17a2b8','4':'#6c757d'};
    const t = el.dataset.userType;
    const badge = document.getElementById('selectedUserTypeBadge');
    badge.textContent = typeLabels[t] || 'Usuário';
    badge.style.background = (typeColors[t] || '#6c757d') + '20';
    badge.style.color = typeColors[t] || '#6c757d';
    badge.style.border = '1px solid ' + (typeColors[t] || '#6c757d') + '40';

    // Avatar
    const initials = currentUserName.split(' ').slice(0,2).map(w => w[0]).join('').toUpperCase();
    const av = document.getElementById('selectedAvatar');
    av.textContent = initials;
    av.style.background = (typeColors[t] || '#6c757d') + '20';
    av.style.color = typeColors[t] || '#6c757d';

    // Badge QR
    const qrBadge = document.getElementById('qrStatusBadge');
    if (qrAtivoAtual) {
        qrBadge.innerHTML = '<span style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;"><i class="fas fa-qrcode"></i> QR Ativo</span>';
    } else {
        qrBadge.innerHTML = '<span style="background:#f8f9fa;color:#aaa;border:1px solid #e0e0e0;padding:4px 10px;border-radius:20px;font-size:12px;"><i class="fas fa-qrcode"></i> Sem QR</span>';
    }

    // Carregar permissões
    fetch('ajax/get_user_permissions.php?user_id=' + currentUserId)
        .then(r => r.json())
        .then(permissions => {
            renderPermissions(permissions);
            document.getElementById('noSelectionMessage').style.display = 'none';
            document.getElementById('permissionsPanel').style.display   = 'block';
        })
        .catch(() => alert('Erro ao carregar permissões do usuário'));
}

// ── Renderizar permissões em tabela por categoria ─────────────────────────────
function renderPermissions(permissions) {
    const content = document.getElementById('permissionsContent');
    content.innerHTML = '';

    const grouped = {};
    permissions.forEach(p => {
        const cat = p.page_category || 'Outros';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(p);
    });

    const catIcons = {
        'Principal':'fas fa-home','Bebidas':'fas fa-beer','TAPs':'fas fa-faucet',
        'Pedidos':'fas fa-shopping-cart','Usuários':'fas fa-users',
        'Estabelecimentos':'fas fa-store','Financeiro':'fas fa-dollar-sign',
        'Estoque':'fas fa-boxes','Relatórios':'fas fa-chart-bar',
        'Configurações':'fas fa-cog','Outros':'fas fa-folder'
    };

    Object.keys(grouped).forEach(cat => {
        const wrap = document.createElement('div');
        wrap.className = 'perm-category';

        const icon = catIcons[cat] || 'fas fa-folder';
        wrap.innerHTML = `
            <div class="perm-category-header">
                <i class="${icon}"></i> ${cat}
                <span style="margin-left:auto;font-size:11px;font-weight:400;color:#888;">${grouped[cat].length} módulo${grouped[cat].length != 1 ? 's' : ''}</span>
            </div>
            <table class="perm-table">
                <thead>
                    <tr>
                        <th style="width:45%;">Módulo</th>
                        <th>Ver</th>
                        <th>Criar</th>
                        <th>Editar</th>
                        <th>Excluir</th>
                    </tr>
                </thead>
                <tbody id="tbody_${cat.replace(/\s/g,'_')}"></tbody>
            </table>`;

        const tbody = wrap.querySelector('tbody');
        grouped[cat].forEach(p => {
            const isAdminOnly = p.admin_only == 1;
            const d = isAdminOnly ? 'disabled' : '';
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="perm-name">
                        <i class="${p.page_icon || 'fas fa-circle'}"></i>
                        ${p.page_name}
                        ${isAdminOnly ? '<span class="admin-only-badge">Admin Only</span>' : ''}
                    </div>
                </td>
                <td><input type="checkbox" class="perm-checkbox" id="view_${p.id}"
                    name="permissions[${p.id}][view]"
                    ${p.can_view == 1 ? 'checked' : ''} ${d}
                    onchange="updateDeps(${p.id})"></td>
                <td><input type="checkbox" class="perm-checkbox" id="create_${p.id}"
                    name="permissions[${p.id}][create]"
                    ${p.can_create == 1 ? 'checked' : ''} ${d}></td>
                <td><input type="checkbox" class="perm-checkbox" id="edit_${p.id}"
                    name="permissions[${p.id}][edit]"
                    ${p.can_edit == 1 ? 'checked' : ''} ${d}></td>
                <td><input type="checkbox" class="perm-checkbox" id="delete_${p.id}"
                    name="permissions[${p.id}][delete]"
                    ${p.can_delete == 1 ? 'checked' : ''} ${d}></td>`;
            tbody.appendChild(row);
        });

        content.appendChild(wrap);
    });
}

function updateDeps(pageId) {
    const view = document.getElementById(`view_${pageId}`);
    if (!view.checked) {
        ['create','edit','delete'].forEach(a => {
            const el = document.getElementById(`${a}_${pageId}`);
            if (el && !el.disabled) el.checked = false;
        });
    }
}

function toggleAllPermissions(state) {
    document.querySelectorAll('.perm-checkbox:not(:disabled)').forEach(cb => cb.checked = state);
}

function cancelSelection() {
    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
    document.getElementById('permissionsPanel').style.display   = 'none';
    document.getElementById('noSelectionMessage').style.display = 'block';
    currentUserId = null;
}

// ── Scanner QR Code ───────────────────────────────────────────────────────────
let scannerStream = null, scannerInterval = null, scannerAtivo = false;

function abrirScannerQr() {
    if (!currentUserId) { alert('Selecione um usuário antes de habilitar.'); return; }
    const existing = document.getElementById('modalScanner');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'modalScanner';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
        <div class="qr-scanner-box">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #f0f0f0;">
                <h4 style="margin:0;font-size:17px;color:var(--primary-color);display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-camera"></i> Escanear QR Code do Tablet
                </h4>
                <button onclick="fecharScanner()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999;line-height:1;">&times;</button>
            </div>
            <p style="color:#666;font-size:13px;margin-bottom:12px;">
                Aponte a câmera deste computador para o QR Code exibido na tela do tablet Android.
            </p>
            <div class="qr-video-wrapper">
                <video id="qrVideo" autoplay playsinline muted style="width:100%;border-radius:8px;"></video>
                <canvas id="qrCanvas" style="display:none;"></canvas>
                <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
                    <div class="qr-scanner-frame"></div>
                </div>
            </div>
            <div id="scannerStatus" style="margin-top:12px;text-align:center;color:#E87722;font-weight:600;font-size:13px;">
                Iniciando câmera...
            </div>
        </div>`;
    modal.addEventListener('click', e => { if (e.target === modal) fecharScanner(); });
    document.body.appendChild(modal);

    if (typeof jsQR === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
        s.onload = iniciarCamera;
        s.onerror = () => { const el = document.getElementById('scannerStatus'); if (el) el.textContent = 'Erro ao carregar biblioteca de scanner.'; };
        document.head.appendChild(s);
    } else { iniciarCamera(); }
}

function iniciarCamera() {
    navigator.mediaDevices.getUserMedia({ video: { facingMode:'environment', width:640, height:480 } })
        .then(stream => {
            scannerStream = stream; scannerAtivo = true;
            const v = document.getElementById('qrVideo');
            v.srcObject = stream; v.play();
            const el = document.getElementById('scannerStatus');
            if (el) el.textContent = 'Câmera ativa. Aponte para o QR Code do tablet...';
            iniciarLeitura();
        })
        .catch(err => {
            const el = document.getElementById('scannerStatus');
            if (el) el.textContent = 'Erro ao acessar câmera: ' + err.message;
        });
}

function iniciarLeitura() {
    const video = document.getElementById('qrVideo');
    const canvas = document.getElementById('qrCanvas');
    const ctx = canvas.getContext('2d');
    scannerInterval = setInterval(() => {
        if (!scannerAtivo || !video || video.readyState !== video.HAVE_ENOUGH_DATA) return;
        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts:'dontInvert' });
        if (code && code.data) {
            if (/^CHOPPON_MASTER:[0-9a-f]{64}$/.test(code.data)) {
                scannerAtivo = false; clearInterval(scannerInterval);
                const el = document.getElementById('scannerStatus');
                if (el) el.innerHTML = '<i class="fas fa-check-circle" style="color:#28a745"></i> QR Code lido! Validando...';
                aprovarQrCode(code.data);
            } else {
                const el = document.getElementById('scannerStatus');
                if (el) el.textContent = 'QR Code inválido. Aponte para o QR Code do tablet.';
            }
        }
    }, 200);
}

function fecharScanner() {
    scannerAtivo = false; clearInterval(scannerInterval);
    if (scannerStream) { scannerStream.getTracks().forEach(t => t.stop()); scannerStream = null; }
    const m = document.getElementById('modalScanner'); if (m) m.remove();
}

function aprovarQrCode(qrData) {
    const fd = new FormData();
    fd.append('qr_data', qrData);
    fd.append('user_id', currentUserId);
    fetch('ajax/aprovar_master_qr.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            fecharScanner();
            const ra = document.getElementById('qrResultArea');
            ra.style.display = 'block';
            if (data.success) {
                ra.innerHTML = `<div class="qr-result-success"><i class="fas fa-check-circle"></i><div><strong>Acesso Liberado!</strong> ${data.message}</div></div>`;
                setTimeout(() => ra.style.display = 'none', 8000);
            } else {
                ra.innerHTML = `<div class="qr-result-error"><i class="fas fa-times-circle"></i><div><strong>Falha na validação</strong> ${data.message || 'Erro desconhecido.'}</div></div>`;
                setTimeout(() => ra.style.display = 'none', 6000);
            }
        })
        .catch(() => {
            fecharScanner();
            const ra = document.getElementById('qrResultArea');
            ra.style.display = 'block';
            ra.innerHTML = `<div class="qr-result-error"><i class="fas fa-times-circle"></i><div><strong>Erro de conexão</strong> Verifique a conexão e tente novamente.</div></div>`;
        });
}

function fecharModal() { document.getElementById('modalRevogar').style.display = 'none'; }
function confirmarRevogacao() { fecharModal(); /* lógica de revogação via AJAX se necessário */ }
</script>

<?php require_once '../includes/footer.php'; ?>
