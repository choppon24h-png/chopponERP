<?php
$page_title = 'Gerenciar Permissões';
$current_page = 'permissoes';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';

// Apenas Admin Geral pode acessar
requireAdminGeral();

$conn = getDBConnection();

// ── Garantir tabela de tokens QR ─────────────────────────────────────────
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

// Processar salvamento de permissões
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_permissions') {
    $user_id     = $_POST['user_id'];
    $permissions = $_POST['permissions'] ?? [];

    if (saveUserPermissions($user_id, $permissions)) {
        $success = 'Permissões atualizadas com sucesso!';
    } else {
        $error = 'Erro ao atualizar permissões.';
    }
}

// Listar todos os usuários (exceto o próprio admin logado) + status QR ativo
$stmt = $conn->prepare("
    SELECT u.*,
           GROUP_CONCAT(e.name SEPARATOR ', ') AS estabelecimentos,
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

// Obter páginas agrupadas por categoria
$pages_by_category = getPagesByCategory();

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Gerenciar Permissões de Usuários</h1>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="row">
            <!-- Lista de Usuários -->
            <div class="col-md-4">
                <h3>Selecione um Usuário</h3>
                <div class="list-group" id="userList">
                    <?php foreach ($usuarios as $usuario): ?>
                    <a href="#"
                       class="list-group-item list-group-item-action user-item"
                       data-user-id="<?php echo $usuario['id']; ?>"
                       data-user-name="<?php echo htmlspecialchars($usuario['name']); ?>"
                       data-user-type="<?php echo $usuario['type']; ?>"
                       data-qr-ativo="<?php echo $usuario['qr_ativo'] > 0 ? '1' : '0'; ?>">
                        <div class="d-flex w-100 justify-content-between align-items-center">
                            <h5 class="mb-1"><?php echo htmlspecialchars($usuario['name']); ?></h5>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <?php if ($usuario['qr_ativo'] > 0): ?>
                                    <span class="badge badge-qr-ativo" title="QR Code master ativo">
                                        <i class="fas fa-qrcode"></i> QR
                                    </span>
                                <?php endif; ?>
                                <span class="badge badge-<?php echo $usuario['type'] == 1 ? 'danger' : ($usuario['type'] == 2 ? 'primary' : ($usuario['type'] == 3 ? 'info' : 'secondary')); ?>">
                                    <?php echo getUserType($usuario['type']); ?>
                                </span>
                            </div>
                        </div>
                        <p class="mb-1"><small><?php echo htmlspecialchars($usuario['email']); ?></small></p>
                        <?php if ($usuario['estabelecimentos']): ?>
                        <p class="mb-0"><small class="text-muted">🏪 <?php echo htmlspecialchars($usuario['estabelecimentos']); ?></small></p>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Painel de Permissões -->
            <div class="col-md-8">

                <!-- Mensagem padrão (sem seleção) -->
                <div id="noSelectionMessage" class="text-center" style="padding: 60px 20px;">
                    <i class="fas fa-user-lock" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                    <h4 style="color: #999;">Selecione um usuário para gerenciar permissões</h4>
                </div>

                <!-- Painel principal (visível após seleção) -->
                <div id="permissionsPanel" style="display: none;">

                    <!-- Cabeçalho do usuário selecionado -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e9ecef;">
                        <div>
                            <h3 id="selectedUserName" style="margin:0;"></h3>
                            <p class="text-muted mb-0">Tipo: <strong id="selectedUserType"></strong></p>
                        </div>
                    </div>

                    <!-- ── Seção QR Code Master ──────────────────────── -->
                    <div class="qr-master-section">
                        <div class="qr-master-header">
                            <div class="qr-master-title">
                                <i class="fas fa-qrcode"></i>
                                <span>Acesso Master via QR Code</span>
                            </div>
                            <div id="qrStatusBadge"></div>
                        </div>

                        <p class="qr-master-desc">
                            Gere um QR Code seguro para que este usuário acesse o modo técnico
                            (ServiceTools) no tablet Android sem digitar senha. O código expira em
                            <strong>24 horas</strong> e pode ser revogado a qualquer momento.
                        </p>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                            <button class="btn-qr-gerar" id="btnGerarQr" onclick="gerarQrMaster()">
                                <i class="fas fa-qrcode"></i> Gerar QR Code
                            </button>
                            <button class="btn-qr-revogar" id="btnRevogarQr" onclick="revogarQrMaster()" style="display:none;">
                                <i class="fas fa-ban"></i> Revogar Acesso
                            </button>
                        </div>

                        <!-- Área do QR Code gerado -->
                        <div id="qrCodeArea" style="display:none;" class="qr-code-area">
                            <div class="qr-code-container">
                                <img id="qrCodeImg" src="" alt="QR Code Master" />
                            </div>
                            <div class="qr-code-info">
                                <div class="qr-info-row">
                                    <i class="fas fa-clock"></i>
                                    <span>Expira em: <strong id="qrExpiresAt"></strong></span>
                                </div>
                                <div class="qr-info-row">
                                    <i class="fas fa-shield-alt"></i>
                                    <span>Token único de 64 caracteres — válido por 24 horas</span>
                                </div>
                                <div class="qr-info-row">
                                    <i class="fas fa-mobile-alt"></i>
                                    <span>Aponte a câmera do tablet para este QR Code na tela de Acesso Master</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ── Fim Seção QR Code Master ──────────────────── -->

                    <!-- Permissões de módulos -->
                    <form method="POST" id="permissionsForm">
                        <input type="hidden" name="action" value="save_permissions">
                        <input type="hidden" name="user_id" id="selectedUserId">

                        <div id="permissionsContent"></div>

                        <div class="form-actions" style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Permissões
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="cancelSelection()">
                                <i class="fas fa-times"></i> Cancelar
                            </button>
                        </div>
                    </form>
                </div>
                <!-- fim #permissionsPanel -->

            </div>
        </div>
    </div>
</div>

<style>
/* ── Lista de usuários ──────────────────────────────────────────────────── */
.list-group-item {
    cursor: pointer;
    transition: all 0.3s ease;
}
.list-group-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}
.list-group-item.active {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}
.list-group-item.active .text-muted { color: rgba(255,255,255,0.75) !important; }

.badge {
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.badge-danger    { background-color: #dc3545; color: white; }
.badge-primary   { background-color: #0066CC; color: white; }
.badge-info      { background-color: #17a2b8; color: white; }
.badge-secondary { background-color: #6c757d; color: white; }
.badge-qr-ativo  { background-color: #28a745; color: white; font-size: 11px; }

/* ── Seção QR Code Master ───────────────────────────────────────────────── */
.qr-master-section {
    background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
    border: 1px solid #b8d9f5;
    border-left: 4px solid var(--primary-color);
    border-radius: var(--border-radius);
    padding: 20px;
    margin-bottom: 24px;
}
.qr-master-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.qr-master-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 700;
    color: var(--primary-color);
}
.qr-master-title i { font-size: 20px; }
.qr-master-desc {
    color: #555;
    font-size: 13px;
    margin-bottom: 16px;
    line-height: 1.6;
}

.btn-qr-gerar {
    background-color: var(--primary-color);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: var(--border-radius);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-qr-gerar:hover { background-color: var(--primary-dark); transform: translateY(-1px); }
.btn-qr-revogar {
    background-color: transparent;
    color: #dc3545;
    border: 1px solid #dc3545;
    padding: 10px 20px;
    border-radius: var(--border-radius);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-qr-revogar:hover { background-color: #dc3545; color: white; }

/* ── Área do QR Code ────────────────────────────────────────────────────── */
.qr-code-area {
    background: white;
    border-radius: var(--border-radius);
    border: 1px solid #d0e8f8;
    padding: 20px;
    display: flex;
    gap: 24px;
    align-items: flex-start;
    flex-wrap: wrap;
    animation: fadeInQr 0.4s ease;
}
@keyframes fadeInQr {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.qr-code-container {
    flex-shrink: 0;
    background: white;
    border: 2px solid var(--primary-color);
    border-radius: 8px;
    padding: 8px;
    box-shadow: 0 4px 12px rgba(0,102,204,0.15);
}
.qr-code-container img { width: 200px; height: 200px; display: block; }
.qr-code-info { flex: 1; min-width: 200px; }
.qr-info-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    color: #444;
}
.qr-info-row:last-child { border-bottom: none; }
.qr-info-row i { color: var(--primary-color); margin-top: 2px; flex-shrink: 0; width: 16px; }

/* ── Badge de status QR ─────────────────────────────────────────────────── */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-ativo   { background: #d4edda; color: #155724; }
.status-inativo { background: #f8d7da; color: #721c24; }

/* ── Permissões de módulos ──────────────────────────────────────────────── */
.permission-category {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.permission-category h4 {
    margin-bottom: 15px;
    color: var(--primary-color);
    font-size: 18px;
}
.permission-row {
    display: flex;
    align-items: center;
    padding: 12px;
    background-color: white;
    border-radius: 6px;
    margin-bottom: 10px;
    border: 1px solid #e0e0e0;
}
.permission-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.permission-name { flex: 1; font-weight: 500; }
.permission-name i { margin-right: 8px; color: var(--primary-color); }
.permission-actions { display: flex; gap: 15px; }
.permission-checkbox { display: flex; align-items: center; }
.permission-checkbox input[type="checkbox"] {
    width: 18px; height: 18px; margin-right: 5px; cursor: pointer;
}
.permission-checkbox label { margin: 0; cursor: pointer; font-size: 13px; }
.admin-only-badge {
    background-color: #dc3545; color: white;
    padding: 4px 8px; border-radius: 4px; font-size: 11px; margin-left: 10px;
}

/* ── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 9999; display: flex; align-items: center; justify-content: center;
}
.modal-box {
    background: white; border-radius: 12px; padding: 32px;
    max-width: 420px; width: 90%; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-icon {
    width: 64px; height: 64px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 28px;
}
.modal-icon-danger { background: #fde8e8; color: #dc3545; }
.modal-box h4 { margin-bottom: 12px; font-size: 20px; }
.modal-box p  { color: #666; margin-bottom: 24px; font-size: 14px; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }

@media (max-width: 768px) {
    .qr-code-area { flex-direction: column; align-items: center; }
    .qr-code-container img { width: 180px; height: 180px; }
}
</style>

<!-- Modal de confirmação de revogação -->
<div id="modalRevogar" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-icon modal-icon-danger"><i class="fas fa-ban"></i></div>
        <h4>Revogar Acesso QR Code?</h4>
        <p>O QR Code ativo de <strong id="modalUserName"></strong> será invalidado imediatamente.</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
            <button class="btn btn-danger" onclick="confirmarRevogacao()">Revogar</button>
        </div>
    </div>
</div>

<script>
const pagesByCategory = <?php echo json_encode($pages_by_category); ?>;

let currentUserId   = null;
let currentUserName = '';
let qrAtivoAtual    = false;

// ── Selecionar usuário ────────────────────────────────────────────────────
document.querySelectorAll('.user-item').forEach(item => {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        currentUserId   = this.dataset.userId;
        currentUserName = this.dataset.userName;
        qrAtivoAtual    = this.dataset.qrAtivo === '1';
        loadUserPermissions(currentUserId, currentUserName, this.dataset.userType);
        atualizarPainelQr(qrAtivoAtual);
    });
});

// ── Carregar permissões ───────────────────────────────────────────────────
function loadUserPermissions(userId, userName, userType) {
    document.getElementById('selectedUserId').value = userId;
    document.getElementById('selectedUserName').textContent = userName;
    document.getElementById('selectedUserType').textContent = getUserTypeName(userType);
    fetch('ajax/get_user_permissions.php?user_id=' + userId)
        .then(r => r.json())
        .then(permissions => {
            renderPermissions(permissions);
            document.getElementById('noSelectionMessage').style.display = 'none';
            document.getElementById('permissionsPanel').style.display  = 'block';
            document.getElementById('qrCodeArea').style.display = 'none';
        })
        .catch(err => { console.error(err); alert('Erro ao carregar permissões do usuário'); });
}

// ── Painel QR ─────────────────────────────────────────────────────────────
function atualizarPainelQr(ativo) {
    const badge      = document.getElementById('qrStatusBadge');
    const btnRevogar = document.getElementById('btnRevogarQr');
    const btnGerar   = document.getElementById('btnGerarQr');
    if (ativo) {
        badge.innerHTML = '<span class="status-badge status-ativo"><i class="fas fa-check-circle"></i> QR Code Ativo</span>';
        btnRevogar.style.display = 'inline-flex';
        btnGerar.innerHTML = '<i class="fas fa-sync-alt"></i> Regenerar QR Code';
    } else {
        badge.innerHTML = '<span class="status-badge status-inativo"><i class="fas fa-times-circle"></i> Sem QR Code Ativo</span>';
        btnRevogar.style.display = 'none';
        btnGerar.innerHTML = '<i class="fas fa-qrcode"></i> Gerar QR Code';
    }
    document.getElementById('qrCodeArea').style.display = 'none';
}

// ── Gerar QR Code Master ──────────────────────────────────────────────────
function gerarQrMaster() {
    if (!currentUserId) return;
    const btn = document.getElementById('btnGerarQr');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';
    const fd = new FormData();
    fd.append('user_id', currentUserId);
    fetch('ajax/gerar_master_qr.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                const img = document.getElementById('qrCodeImg');
                img.src = data.qr_url;
                img.onload = () => { document.getElementById('qrCodeArea').style.display = 'flex'; };
                const exp = new Date(data.expires_at.replace(' ', 'T'));
                document.getElementById('qrExpiresAt').textContent =
                    exp.toLocaleDateString('pt-BR') + ' às ' +
                    exp.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                qrAtivoAtual = true;
                atualizarPainelQr(true);
                document.getElementById('qrCodeArea').style.display = 'flex';
                const userItem = document.querySelector(`.user-item[data-user-id="${currentUserId}"]`);
                if (userItem) {
                    userItem.dataset.qrAtivo = '1';
                    if (!userItem.querySelector('.badge-qr-ativo')) {
                        const b = document.createElement('span');
                        b.className = 'badge badge-qr-ativo';
                        b.title = 'QR Code master ativo';
                        b.innerHTML = '<i class="fas fa-qrcode"></i> QR';
                        userItem.querySelector('[style*="display:flex"]')?.prepend(b);
                    }
                }
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Regenerar QR Code';
            } else {
                alert('Erro: ' + (data.error || 'Falha ao gerar QR Code'));
                btn.innerHTML = '<i class="fas fa-qrcode"></i> Gerar QR Code';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-qrcode"></i> Gerar QR Code';
            console.error(err); alert('Erro de conexão ao gerar QR Code');
        });
}

// ── Revogar ───────────────────────────────────────────────────────────────
function revogarQrMaster() {
    document.getElementById('modalUserName').textContent = currentUserName;
    document.getElementById('modalRevogar').style.display = 'flex';
}
function fecharModal() { document.getElementById('modalRevogar').style.display = 'none'; }
function confirmarRevogacao() {
    fecharModal();
    const fd = new FormData();
    fd.append('user_id', currentUserId);
    fetch('ajax/revogar_master_qr.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                qrAtivoAtual = false;
                atualizarPainelQr(false);
                const userItem = document.querySelector(`.user-item[data-user-id="${currentUserId}"]`);
                if (userItem) {
                    userItem.dataset.qrAtivo = '0';
                    userItem.querySelector('.badge-qr-ativo')?.remove();
                }
            } else { alert('Erro ao revogar: ' + (data.error || 'Falha')); }
        })
        .catch(err => { console.error(err); alert('Erro de conexão'); });
}
document.getElementById('modalRevogar').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});

// ── Renderizar permissões ─────────────────────────────────────────────────
function renderPermissions(permissions) {
    const content = document.getElementById('permissionsContent');
    content.innerHTML = '';
    const grouped = {};
    permissions.forEach(perm => {
        const category = perm.page_category || 'Outros';
        if (!grouped[category]) grouped[category] = [];
        grouped[category].push(perm);
    });
    Object.keys(grouped).forEach(category => {
        const categoryDiv = document.createElement('div');
        categoryDiv.className = 'permission-category';
        const categoryTitle = document.createElement('h4');
        categoryTitle.innerHTML = `<i class="fas fa-folder"></i> ${category}`;
        categoryDiv.appendChild(categoryTitle);
        grouped[category].forEach(perm => {
            const row = document.createElement('div');
            row.className = 'permission-row';
            const isAdminOnly = perm.admin_only == 1;
            const disabled = isAdminOnly ? 'disabled' : '';
            row.innerHTML = `
                <div class="permission-name">
                    <i class="${perm.page_icon}"></i> ${perm.page_name}
                    ${isAdminOnly ? '<span class="admin-only-badge">ADMIN ONLY</span>' : ''}
                </div>
                <div class="permission-actions">
                    <div class="permission-checkbox">
                        <input type="checkbox" id="view_${perm.id}" name="permissions[${perm.id}][view]"
                               ${perm.can_view == 1 ? 'checked' : ''} ${disabled}
                               onchange="updateDependentPermissions(${perm.id})">
                        <label for="view_${perm.id}">Ver</label>
                    </div>
                    <div class="permission-checkbox">
                        <input type="checkbox" id="create_${perm.id}" name="permissions[${perm.id}][create]"
                               ${perm.can_create == 1 ? 'checked' : ''} ${disabled}>
                        <label for="create_${perm.id}">Criar</label>
                    </div>
                    <div class="permission-checkbox">
                        <input type="checkbox" id="edit_${perm.id}" name="permissions[${perm.id}][edit]"
                               ${perm.can_edit == 1 ? 'checked' : ''} ${disabled}>
                        <label for="edit_${perm.id}">Editar</label>
                    </div>
                    <div class="permission-checkbox">
                        <input type="checkbox" id="delete_${perm.id}" name="permissions[${perm.id}][delete]"
                               ${perm.can_delete == 1 ? 'checked' : ''} ${disabled}>
                        <label for="delete_${perm.id}">Excluir</label>
                    </div>
                </div>`;
            categoryDiv.appendChild(row);
        });
        content.appendChild(categoryDiv);
    });
}
function updateDependentPermissions(pageId) {
    const view = document.getElementById(`view_${pageId}`);
    if (!view.checked) {
        ['create','edit','delete'].forEach(a => {
            const el = document.getElementById(`${a}_${pageId}`);
            if (el) el.checked = false;
        });
    }
}
function getUserTypeName(type) {
    return { '1':'Administrador Geral','2':'Gerente','3':'Operador','4':'Visualizador' }[type] || 'Desconhecido';
}
function cancelSelection() {
    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
    document.getElementById('permissionsPanel').style.display  = 'none';
    document.getElementById('noSelectionMessage').style.display = 'block';
    currentUserId = null;
}
</script>

<?php require_once '../includes/footer.php'; ?>
