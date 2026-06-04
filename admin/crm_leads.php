<?php
$page_title = 'Leads';
$page_key   = 'crm_leads';
require_once '../includes/header.php';
require_once '../includes/logger.php';

$conn = getDBConnection();
$current_user_id    = $_SESSION['user_id'];
$current_user_name  = $_SESSION['user_name'] ?? 'Usuário';
$is_admin           = isAdminGeral();
$estab_id           = getEstabelecimentoId();

$success = $error = '';

// ─── Helpers ──────────────────────────────────────────────────────────────────
function registrarInteracao($conn, $tipo_registro, $registro_id, $tipo, $descricao, $user_id,
                             $transf_de = null, $transf_para = null, $motivo_transf = null) {
    $stmt = $conn->prepare("
        INSERT INTO crm_interacoes
            (tipo_registro, registro_id, tipo, descricao, user_id,
             transferencia_de, transferencia_para, motivo_transferencia)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tipo_registro, $registro_id, $tipo, $descricao,
                    $user_id, $transf_de, $transf_para, $motivo_transf]);
}

function registrarTransferencia($conn, $tipo_registro, $registro_id,
                                 $de_user_id, $para_user_id, $motivo, $transferido_por) {
    $stmt = $conn->prepare("
        INSERT INTO crm_transferencias
            (tipo_registro, registro_id, de_user_id, para_user_id, motivo, transferido_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tipo_registro, $registro_id, $de_user_id, $para_user_id, $motivo, $transferido_por]);
}

// ─── Buscar colaboradores do estabelecimento ──────────────────────────────────
function getColaboradores($conn, $estab_id, $is_admin) {
    if ($is_admin) {
        $stmt = $conn->query("SELECT id, name FROM users WHERE type IN (1,2,3) ORDER BY name");
    } else {
        $stmt = $conn->prepare("
            SELECT u.id, u.name
            FROM users u
            INNER JOIN user_estabelecimento ue ON u.id = ue.user_id
            WHERE ue.estabelecimento_id = ? AND ue.status = 1
            ORDER BY u.name
        ");
        $stmt->execute([$estab_id]);
    }
    return $stmt->fetchAll();
}

// ─── POST: Ações ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Criar Lead ────────────────────────────────────────────
    if ($action === 'create') {
        $nome          = sanitize($_POST['nome'] ?? '');
        $email         = sanitize($_POST['email'] ?? '');
        $telefone      = sanitize($_POST['telefone'] ?? '');
        $empresa       = sanitize($_POST['empresa'] ?? '');
        $origem        = $_POST['origem'] ?? 'outro';
        $temperatura   = $_POST['temperatura'] ?? 'frio';
        $observacoes   = sanitize($_POST['observacoes'] ?? '');
        $responsavel   = intval($_POST['responsavel_id'] ?? $current_user_id);
        $eid           = $is_admin ? intval($_POST['estabelecimento_id'] ?? $estab_id) : $estab_id;

        if (empty($nome)) {
            $error = 'Nome é obrigatório.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO crm_leads
                    (estabelecimento_id, responsavel_id, nome, email, telefone, empresa,
                     origem, temperatura, observacoes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$eid, $responsavel, $nome, $email, $telefone, $empresa,
                                 $origem, $temperatura, $observacoes, $current_user_id])) {
                $lead_id = $conn->lastInsertId();
                registrarInteracao($conn, 'lead', $lead_id, 'criacao',
                    "Lead criado por {$current_user_name}.", $current_user_id);
                Logger::info('Lead criado', ['lead_id' => $lead_id, 'nome' => $nome]);
                $success = 'Lead criado com sucesso!';
            } else {
                $error = 'Erro ao criar lead.';
            }
        }
    }

    // ── Adicionar Interação ───────────────────────────────────
    if ($action === 'add_interacao') {
        $lead_id    = intval($_POST['lead_id'] ?? 0);
        $tipo       = $_POST['tipo_interacao'] ?? 'nota';
        $descricao  = sanitize($_POST['descricao'] ?? '');
        if ($lead_id && !empty($descricao)) {
            registrarInteracao($conn, 'lead', $lead_id, $tipo, $descricao, $current_user_id);
            $success = 'Interação registrada!';
        } else {
            $error = 'Preencha a descrição da interação.';
        }
    }

    // ── Transferir Lead ───────────────────────────────────────
    if ($action === 'transferir') {
        $lead_id        = intval($_POST['lead_id'] ?? 0);
        $para_user_id   = intval($_POST['para_user_id'] ?? 0);
        $motivo         = sanitize($_POST['motivo'] ?? '');

        if (!$lead_id || !$para_user_id || empty($motivo)) {
            $error = 'Preencha todos os campos da transferência.';
        } else {
            // Buscar responsável atual
            $stmt = $conn->prepare("SELECT responsavel_id, nome FROM crm_leads WHERE id = ?");
            $stmt->execute([$lead_id]);
            $lead = $stmt->fetch();

            if (!$lead) {
                $error = 'Lead não encontrado.';
            } elseif ($lead['responsavel_id'] == $para_user_id) {
                $error = 'O lead já está com este colaborador.';
            } else {
                $de_user_id = $lead['responsavel_id'];

                // Buscar nomes
                $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->execute([$de_user_id]);
                $nome_de = $stmt->fetchColumn();
                $stmt->execute([$para_user_id]);
                $nome_para = $stmt->fetchColumn();

                // Atualizar responsável
                $conn->prepare("UPDATE crm_leads SET responsavel_id = ? WHERE id = ?")
                     ->execute([$para_user_id, $lead_id]);

                // Registrar na tabela de transferências
                registrarTransferencia($conn, 'lead', $lead_id,
                    $de_user_id, $para_user_id, $motivo, $current_user_id);

                // Registrar no log de interações
                $descricao_log = "Lead transferido de <strong>{$nome_de}</strong> para <strong>{$nome_para}</strong>."
                               . " Motivo: {$motivo}";
                registrarInteracao($conn, 'lead', $lead_id, 'transferencia',
                    $descricao_log, $current_user_id,
                    $de_user_id, $para_user_id, $motivo);

                Logger::info('Lead transferido', [
                    'lead_id'   => $lead_id,
                    'de'        => $de_user_id,
                    'para'      => $para_user_id,
                    'por'       => $current_user_id,
                    'motivo'    => $motivo
                ]);
                $success = "Lead transferido para {$nome_para} com sucesso!";
            }
        }
    }

    // ── Atualizar Status ──────────────────────────────────────
    if ($action === 'update_status') {
        $lead_id    = intval($_POST['lead_id'] ?? 0);
        $novo_status = $_POST['novo_status'] ?? '';
        $statusOk   = ['novo','em_contato','qualificado','desqualificado','convertido'];
        if ($lead_id && in_array($novo_status, $statusOk)) {
            $stmt = $conn->prepare("SELECT status FROM crm_leads WHERE id = ?");
            $stmt->execute([$lead_id]);
            $old = $stmt->fetchColumn();
            $conn->prepare("UPDATE crm_leads SET status = ? WHERE id = ?")->execute([$novo_status, $lead_id]);
            registrarInteracao($conn, 'lead', $lead_id, 'status',
                "Status alterado de <strong>{$old}</strong> para <strong>{$novo_status}</strong>.",
                $current_user_id);
            $success = 'Status atualizado!';
        }
    }

    // ── Excluir Lead ──────────────────────────────────────────
    if ($action === 'delete' && $is_admin) {
        $lead_id = intval($_POST['lead_id'] ?? 0);
        if ($lead_id) {
            $conn->prepare("DELETE FROM crm_leads WHERE id = ?")->execute([$lead_id]);
            $success = 'Lead excluído.';
        }
    }
}

// ─── Buscar Leads ─────────────────────────────────────────────────────────────
$where  = $is_admin ? '' : 'WHERE l.estabelecimento_id = ?';
$params = $is_admin ? [] : [$estab_id];

// Filtros
$filtro_status = $_GET['status'] ?? '';
$filtro_temp   = $_GET['temperatura'] ?? '';
$filtro_resp   = intval($_GET['responsavel'] ?? 0);
$filtro_busca  = trim($_GET['busca'] ?? '');

if ($filtro_status) {
    $where .= ($where ? ' AND' : 'WHERE') . ' l.status = ?';
    $params[] = $filtro_status;
}
if ($filtro_temp) {
    $where .= ($where ? ' AND' : 'WHERE') . ' l.temperatura = ?';
    $params[] = $filtro_temp;
}
if ($filtro_resp) {
    $where .= ($where ? ' AND' : 'WHERE') . ' l.responsavel_id = ?';
    $params[] = $filtro_resp;
}
if ($filtro_busca) {
    $where .= ($where ? ' AND' : 'WHERE') . ' (l.nome LIKE ? OR l.email LIKE ? OR l.telefone LIKE ? OR l.empresa LIKE ?)';
    $like = "%{$filtro_busca}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$stmt = $conn->prepare("
    SELECT l.*,
           u.name  AS responsavel_nome,
           e.name  AS estab_nome,
           cb.name AS criado_por_nome
    FROM crm_leads l
    LEFT JOIN users u         ON l.responsavel_id = u.id
    LEFT JOIN estabelecimentos e ON l.estabelecimento_id = e.id
    LEFT JOIN users cb        ON l.created_by = cb.id
    {$where}
    ORDER BY l.created_at DESC
");
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Estatísticas
$stmt_stats = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'novo') AS novos,
        SUM(status = 'em_contato') AS em_contato,
        SUM(status = 'qualificado') AS qualificados,
        SUM(status = 'convertido') AS convertidos
    FROM crm_leads
    " . ($is_admin ? '' : 'WHERE estabelecimento_id = ?')
);
$stmt_stats->execute($is_admin ? [] : [$estab_id]);
$stats = $stmt_stats->fetch();

$colaboradores = getColaboradores($conn, $estab_id, $is_admin);
$estabelecimentos = $is_admin ? $conn->query("SELECT id, name FROM estabelecimentos ORDER BY name")->fetchAll() : [];

// Labels
$status_labels = [
    'novo'           => ['label' => 'Novo',           'class' => 'badge-info'],
    'em_contato'     => ['label' => 'Em Contato',     'class' => 'badge-warning'],
    'qualificado'    => ['label' => 'Qualificado',    'class' => 'badge-primary'],
    'desqualificado' => ['label' => 'Desqualificado', 'class' => 'badge-secondary'],
    'convertido'     => ['label' => 'Convertido',     'class' => 'badge-success'],
];
$temp_labels = [
    'frio'   => ['label' => 'Frio',   'icon' => 'fa-snowflake', 'color' => '#3b82f6'],
    'morno'  => ['label' => 'Morno',  'icon' => 'fa-sun',       'color' => '#f59e0b'],
    'quente' => ['label' => 'Quente', 'icon' => 'fa-fire',      'color' => '#ef4444'],
];
$tipo_interacao_labels = [
    'criacao'      => ['icon' => 'fa-plus-circle',  'color' => '#10b981', 'label' => 'Criação'],
    'nota'         => ['icon' => 'fa-sticky-note',  'color' => '#6b7280', 'label' => 'Nota'],
    'ligacao'      => ['icon' => 'fa-phone',         'color' => '#3b82f6', 'label' => 'Ligação'],
    'email'        => ['icon' => 'fa-envelope',      'color' => '#8b5cf6', 'label' => 'E-mail'],
    'reuniao'      => ['icon' => 'fa-calendar-alt',  'color' => '#f59e0b', 'label' => 'Reunião'],
    'whatsapp'     => ['icon' => 'fa-whatsapp fab',  'color' => '#25d366', 'label' => 'WhatsApp'],
    'transferencia'=> ['icon' => 'fa-exchange-alt',  'color' => '#ef4444', 'label' => 'Transferência'],
    'status'       => ['icon' => 'fa-tag',           'color' => '#0ea5e9', 'label' => 'Status'],
];
?>

<!-- Page Header -->
<div class="content-header">
    <h1><i class="fas fa-funnel-dollar" style="color:var(--primary-color)"></i> Leads</h1>
    <button class="btn btn-primary" onclick="openModal('modalNovoLead')">
        <i class="fas fa-plus"></i> Novo Lead
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Estatísticas -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-funnel-dollar"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0ea5e9"><i class="fas fa-star"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['novos']; ?></div><div class="stat-label">Novos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-warning"><i class="fas fa-phone"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['em_contato']; ?></div><div class="stat-label">Em Contato</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-info"><i class="fas fa-check"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['qualificados']; ?></div><div class="stat-label">Qualificados</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-success"><i class="fas fa-trophy"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['convertidos']; ?></div><div class="stat-label">Convertidos</div></div>
    </div>
</div>

<!-- Filtros -->
<div class="content-card" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="flex:1;min-width:160px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Buscar</label>
            <input type="text" name="busca" class="form-control" placeholder="Nome, e-mail, empresa..." value="<?php echo htmlspecialchars($filtro_busca); ?>">
        </div>
        <div class="form-group" style="min-width:140px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Status</label>
            <select name="status" class="form-control">
                <option value="">Todos</option>
                <?php foreach ($status_labels as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filtro_status === $k ? 'selected' : ''; ?>><?php echo $v['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="min-width:120px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Temperatura</label>
            <select name="temperatura" class="form-control">
                <option value="">Todas</option>
                <?php foreach ($temp_labels as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filtro_temp === $k ? 'selected' : ''; ?>><?php echo $v['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="min-width:160px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Responsável</label>
            <select name="responsavel" class="form-control">
                <option value="">Todos</option>
                <?php foreach ($colaboradores as $col): ?>
                <option value="<?php echo $col['id']; ?>" <?php echo $filtro_resp == $col['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($col['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:1px">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
            <a href="crm_leads.php" class="btn btn-secondary"><i class="fas fa-times"></i> Limpar</a>
        </div>
    </form>
</div>

<!-- Tabela de Leads -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome / Empresa</th>
                    <th>Contato</th>
                    <th>Temperatura</th>
                    <th>Status</th>
                    <th>Responsável</th>
                    <?php if ($is_admin): ?><th>Estabelecimento</th><?php endif; ?>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($leads)): ?>
                <tr><td colspan="<?php echo $is_admin ? 8 : 7; ?>" style="text-align:center;color:#9ca3af;padding:32px">
                    <i class="fas fa-funnel-dollar" style="font-size:32px;display:block;margin-bottom:8px"></i>
                    Nenhum lead encontrado.
                </td></tr>
            <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                <?php $sl = $status_labels[$lead['status']] ?? ['label'=>$lead['status'],'class'=>'badge-secondary']; ?>
                <?php $tl = $temp_labels[$lead['temperatura']] ?? ['icon'=>'fa-circle','color'=>'#9ca3af','label'=>$lead['temperatura']]; ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($lead['nome']); ?></strong>
                        <?php if ($lead['empresa']): ?>
                        <div style="font-size:12px;color:#6b7280"><i class="fas fa-building"></i> <?php echo htmlspecialchars($lead['empresa']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lead['telefone']): ?><div style="font-size:13px"><i class="fas fa-phone" style="color:#6b7280"></i> <?php echo htmlspecialchars($lead['telefone']); ?></div><?php endif; ?>
                        <?php if ($lead['email']): ?><div style="font-size:12px;color:#6b7280"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($lead['email']); ?></div><?php endif; ?>
                    </td>
                    <td>
                        <span style="color:<?php echo $tl['color']; ?>;font-weight:600;font-size:13px">
                            <i class="fas <?php echo $tl['icon']; ?>"></i> <?php echo $tl['label']; ?>
                        </span>
                    </td>
                    <td><span class="badge <?php echo $sl['class']; ?>"><?php echo $sl['label']; ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary-color);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">
                                <?php echo strtoupper(substr($lead['responsavel_nome'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span style="font-size:13px"><?php echo htmlspecialchars($lead['responsavel_nome'] ?? '-'); ?></span>
                        </div>
                    </td>
                    <?php if ($is_admin): ?>
                    <td style="font-size:12px;color:#6b7280"><?php echo htmlspecialchars($lead['estab_nome'] ?? '-'); ?></td>
                    <?php endif; ?>
                    <td style="font-size:12px;color:#6b7280"><?php echo date('d/m/Y H:i', strtotime($lead['created_at'])); ?></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <button class="btn-icon btn-info" title="Ver Histórico" onclick="verHistorico(<?php echo $lead['id']; ?>, 'lead', '<?php echo htmlspecialchars(addslashes($lead['nome'])); ?>')">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn-icon btn-warning" title="Adicionar Interação" onclick="abrirInteracao(<?php echo $lead['id']; ?>, 'lead')">
                                <i class="fas fa-comment-alt"></i>
                            </button>
                            <button class="btn-icon" style="background:#8b5cf6;color:#fff" title="Transferir" onclick="abrirTransferencia(<?php echo $lead['id']; ?>, 'lead', '<?php echo htmlspecialchars(addslashes($lead['nome'])); ?>', <?php echo $lead['responsavel_id']; ?>)">
                                <i class="fas fa-exchange-alt"></i>
                            </button>
                            <button class="btn-icon btn-primary" title="Alterar Status" onclick="abrirStatus(<?php echo $lead['id']; ?>, 'lead', '<?php echo $lead['status']; ?>')">
                                <i class="fas fa-tag"></i>
                            </button>
                            <?php if ($is_admin): ?>
                            <button class="btn-icon btn-danger" title="Excluir" onclick="excluirLead(<?php echo $lead['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAIS
═══════════════════════════════════════════════════════════ -->

<!-- Modal: Novo Lead -->
<div id="modalNovoLead" class="modal-overlay" onclick="if(event.target===this)closeModal('modalNovoLead')">
    <div class="modal-box" style="max-width:640px">
        <div class="modal-box-header">
            <h4><i class="fas fa-plus-circle" style="color:var(--primary-color);margin-right:8px"></i>Novo Lead</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalNovoLead')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-box-body">
                <?php if ($is_admin): ?>
                <div class="form-section">
                    <div class="form-group">
                        <label><i class="fas fa-store"></i> Estabelecimento <span class="text-danger">*</span></label>
                        <select name="estabelecimento_id" class="form-control" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <h5 class="form-section-title"><i class="fas fa-user"></i> Dados do Lead</h5>
                    <div class="form-row">
                        <div class="form-group" style="flex:2">
                            <label>Nome Completo <span class="text-danger">*</span></label>
                            <input type="text" name="nome" class="form-control" placeholder="Nome do lead" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Empresa</label>
                            <input type="text" name="empresa" class="form-control" placeholder="Empresa">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label>E-mail</label>
                            <input type="email" name="email" class="form-control" placeholder="email@empresa.com">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Telefone</label>
                            <input type="text" name="telefone" class="form-control" placeholder="(00) 00000-0000">
                        </div>
                    </div>
                </div>

                <div class="form-section" style="margin-top:16px">
                    <h5 class="form-section-title"><i class="fas fa-sliders-h"></i> Classificação</h5>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label>Origem</label>
                            <select name="origem" class="form-control">
                                <option value="indicacao">Indicação</option>
                                <option value="site">Site</option>
                                <option value="redes_sociais">Redes Sociais</option>
                                <option value="evento">Evento</option>
                                <option value="cold_call">Cold Call</option>
                                <option value="outro" selected>Outro</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Temperatura</label>
                            <select name="temperatura" class="form-control">
                                <option value="frio">❄️ Frio</option>
                                <option value="morno" selected>🌤️ Morno</option>
                                <option value="quente">🔥 Quente</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Responsável</label>
                            <select name="responsavel_id" class="form-control">
                                <?php foreach ($colaboradores as $col): ?>
                                <option value="<?php echo $col['id']; ?>" <?php echo $col['id'] == $current_user_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($col['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais sobre o lead..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalNovoLead')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Adicionar Interação -->
<div id="modalInteracao" class="modal-overlay" onclick="if(event.target===this)closeModal('modalInteracao')">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-box-header">
            <h4><i class="fas fa-comment-alt" style="color:#f59e0b;margin-right:8px"></i>Registrar Interação</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalInteracao')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_interacao">
            <input type="hidden" name="lead_id" id="interacao_lead_id">
            <div class="modal-box-body">
                <div class="form-group">
                    <label>Tipo de Interação</label>
                    <select name="tipo_interacao" class="form-control">
                        <option value="nota">📝 Nota</option>
                        <option value="ligacao">📞 Ligação</option>
                        <option value="email">📧 E-mail</option>
                        <option value="reuniao">📅 Reunião</option>
                        <option value="whatsapp">💬 WhatsApp</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Descrição <span class="text-danger">*</span></label>
                    <textarea name="descricao" class="form-control" rows="4" placeholder="Descreva o que aconteceu nesta interação..." required></textarea>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalInteracao')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-warning" style="color:#fff"><i class="fas fa-save"></i> Registrar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Transferência -->
<div id="modalTransferencia" class="modal-overlay" onclick="if(event.target===this)closeModal('modalTransferencia')">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-box-header">
            <h4><i class="fas fa-exchange-alt" style="color:#8b5cf6;margin-right:8px"></i>Transferir Lead</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalTransferencia')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="transferir">
            <input type="hidden" name="lead_id" id="transf_lead_id">
            <div class="modal-box-body">
                <div style="background:#f3f4f6;border-radius:8px;padding:12px;margin-bottom:16px">
                    <div style="font-size:12px;color:#6b7280;margin-bottom:4px">Lead sendo transferido:</div>
                    <div style="font-weight:700;color:#111827" id="transf_lead_nome"></div>
                    <div style="font-size:12px;color:#6b7280;margin-top:4px">Responsável atual: <strong id="transf_resp_atual"></strong></div>
                </div>
                <div class="form-group">
                    <label>Transferir para <span class="text-danger">*</span></label>
                    <select name="para_user_id" class="form-control" id="transf_para_select" required>
                        <option value="">Selecione o colaborador...</option>
                        <?php foreach ($colaboradores as $col): ?>
                        <option value="<?php echo $col['id']; ?>" data-name="<?php echo htmlspecialchars($col['name']); ?>">
                            <?php echo htmlspecialchars($col['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Motivo da Transferência <span class="text-danger">*</span></label>
                    <textarea name="motivo" class="form-control" rows="3"
                        placeholder="Explique o motivo da transferência (ex: colaborador de férias, redistribuição de carteira, especialidade do novo responsável...)"
                        required></textarea>
                    <small style="color:#6b7280">Este motivo ficará registrado no histórico de interações.</small>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTransferencia')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn" style="background:#8b5cf6;color:#fff"><i class="fas fa-exchange-alt"></i> Confirmar Transferência</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Alterar Status -->
<div id="modalStatus" class="modal-overlay" onclick="if(event.target===this)closeModal('modalStatus')">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-box-header">
            <h4><i class="fas fa-tag" style="color:#0ea5e9;margin-right:8px"></i>Alterar Status</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalStatus')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="lead_id" id="status_lead_id">
            <div class="modal-box-body">
                <div class="form-group">
                    <label>Novo Status</label>
                    <select name="novo_status" class="form-control" id="status_select">
                        <option value="novo">Novo</option>
                        <option value="em_contato">Em Contato</option>
                        <option value="qualificado">Qualificado</option>
                        <option value="desqualificado">Desqualificado</option>
                        <option value="convertido">Convertido</option>
                    </select>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalStatus')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Histórico de Interações -->
<div id="modalHistorico" class="modal-overlay" onclick="if(event.target===this)closeModal('modalHistorico')">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-box-header">
            <h4><i class="fas fa-history" style="color:#0ea5e9;margin-right:8px"></i>Histórico — <span id="hist_titulo"></span></h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalHistorico')">&times;</button>
        </div>
        <div class="modal-box-body" style="max-height:520px;overflow-y:auto">
            <div id="hist_loading" style="text-align:center;padding:32px;color:#9ca3af">
                <i class="fas fa-spinner fa-spin" style="font-size:24px"></i><br>Carregando...
            </div>
            <div id="hist_conteudo" style="display:none"></div>
        </div>
        <div class="modal-box-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalHistorico')"><i class="fas fa-times"></i> Fechar</button>
        </div>
    </div>
</div>

<!-- Modal: Excluir Lead -->
<div id="modalExcluir" class="modal-overlay" onclick="if(event.target===this)closeModal('modalExcluir')">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-box-header">
            <h4><i class="fas fa-trash" style="color:#ef4444;margin-right:8px"></i>Excluir Lead</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalExcluir')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="lead_id" id="excluir_lead_id">
            <div class="modal-box-body">
                <p style="color:#374151">Tem certeza que deseja excluir este lead? Todo o histórico de interações e transferências também será removido.</p>
                <p style="color:#ef4444;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalExcluir')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Excluir</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Funções de modal ──────────────────────────────────────────
function openModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('active'); document.body.style.overflow = ''; }
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        document.body.style.overflow = '';
    }
});

// ── Interação ─────────────────────────────────────────────────
function abrirInteracao(leadId, tipo) {
    document.getElementById('interacao_lead_id').value = leadId;
    openModal('modalInteracao');
}

// ── Transferência ─────────────────────────────────────────────
function abrirTransferencia(leadId, tipo, nome, respAtualId) {
    document.getElementById('transf_lead_id').value = leadId;
    document.getElementById('transf_lead_nome').textContent = nome;

    // Buscar nome do responsável atual
    const sel = document.getElementById('transf_para_select');
    let nomeAtual = '—';
    Array.from(sel.options).forEach(opt => {
        if (parseInt(opt.value) === respAtualId) nomeAtual = opt.dataset.name || opt.text;
        opt.disabled = (parseInt(opt.value) === respAtualId);
    });
    document.getElementById('transf_resp_atual').textContent = nomeAtual;
    sel.value = '';
    openModal('modalTransferencia');
}

// ── Alterar Status ────────────────────────────────────────────
function abrirStatus(leadId, tipo, statusAtual) {
    document.getElementById('status_lead_id').value = leadId;
    document.getElementById('status_select').value = statusAtual;
    openModal('modalStatus');
}

// ── Excluir ───────────────────────────────────────────────────
function excluirLead(leadId) {
    document.getElementById('excluir_lead_id').value = leadId;
    openModal('modalExcluir');
}

// ── Histórico ─────────────────────────────────────────────────
function verHistorico(registroId, tipo, nome) {
    document.getElementById('hist_titulo').textContent = nome;
    document.getElementById('hist_loading').style.display = 'block';
    document.getElementById('hist_conteudo').style.display = 'none';
    openModal('modalHistorico');

    fetch(`../api/crm_interacoes.php?tipo=${tipo}&id=${registroId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('hist_loading').style.display = 'none';
            const cont = document.getElementById('hist_conteudo');
            if (!data.length) {
                cont.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:32px"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:8px"></i>Nenhuma interação registrada.</div>';
            } else {
                cont.innerHTML = data.map(i => renderInteracao(i)).join('');
            }
            cont.style.display = 'block';
        })
        .catch(() => {
            document.getElementById('hist_loading').style.display = 'none';
            document.getElementById('hist_conteudo').innerHTML = '<div style="color:#ef4444;padding:16px">Erro ao carregar histórico.</div>';
            document.getElementById('hist_conteudo').style.display = 'block';
        });
}

function renderInteracao(i) {
    const iconMap = {
        criacao:       { icon: 'fa-plus-circle',  color: '#10b981' },
        nota:          { icon: 'fa-sticky-note',  color: '#6b7280' },
        ligacao:       { icon: 'fa-phone',         color: '#3b82f6' },
        email:         { icon: 'fa-envelope',      color: '#8b5cf6' },
        reuniao:       { icon: 'fa-calendar-alt',  color: '#f59e0b' },
        whatsapp:      { icon: 'fa-comment',       color: '#25d366' },
        transferencia: { icon: 'fa-exchange-alt',  color: '#ef4444' },
        status:        { icon: 'fa-tag',           color: '#0ea5e9' },
    };
    const m = iconMap[i.tipo] || { icon: 'fa-circle', color: '#9ca3af' };
    const isTransf = i.tipo === 'transferencia';

    let extra = '';
    if (isTransf && i.motivo_transferencia) {
        extra = `<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;margin-top:8px;font-size:13px">
            <i class="fas fa-quote-left" style="color:#f59e0b;margin-right:6px"></i>
            <em>${escHtml(i.motivo_transferencia)}</em>
        </div>`;
    }

    return `<div style="display:flex;gap:12px;padding:14px 0;border-bottom:1px solid #f3f4f6">
        <div style="width:36px;height:36px;border-radius:50%;background:${m.color}20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
            <i class="fas ${m.icon}" style="color:${m.color};font-size:14px"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px">
                <span style="font-weight:600;font-size:13px;color:#374151">${escHtml(i.user_nome)}</span>
                <span style="font-size:11px;color:#9ca3af;white-space:nowrap">${formatDate(i.created_at)}</span>
            </div>
            <div style="font-size:13px;color:#4b5563;line-height:1.5">${i.descricao}</div>
            ${extra}
        </div>
    </div>`;
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function formatDate(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
}
</script>

<?php require_once '../includes/footer.php'; ?>
