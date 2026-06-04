<?php
$page_title = 'Oportunidades';
$page_key   = 'crm_oportunidades';
require_once '../includes/header.php';
require_once '../includes/logger.php';

$conn = getDBConnection();
$current_user_id   = $_SESSION['user_id'];
$current_user_name = $_SESSION['user_name'] ?? 'Usuário';
$is_admin          = isAdminGeral();
$estab_id          = getEstabelecimentoId();

$success = $error = '';

// ─── Helpers ──────────────────────────────────────────────────────────────────
function registrarInteracaoOp($conn, $tipo_registro, $registro_id, $tipo, $descricao, $user_id,
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

function registrarTransferenciaOp($conn, $tipo_registro, $registro_id,
                                   $de_user_id, $para_user_id, $motivo, $transferido_por) {
    $stmt = $conn->prepare("
        INSERT INTO crm_transferencias
            (tipo_registro, registro_id, de_user_id, para_user_id, motivo, transferido_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tipo_registro, $registro_id, $de_user_id, $para_user_id, $motivo, $transferido_por]);
}

function getColaboradoresOp($conn, $estab_id, $is_admin) {
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

    // ── Criar Oportunidade ────────────────────────────────────
    if ($action === 'create') {
        $titulo          = sanitize($_POST['titulo'] ?? '');
        $cliente_nome    = sanitize($_POST['cliente_nome'] ?? '');
        $cliente_email   = sanitize($_POST['cliente_email'] ?? '');
        $cliente_tel     = sanitize($_POST['cliente_telefone'] ?? '');
        $valor           = floatval(str_replace(['.', ','], ['', '.'], $_POST['valor_estimado'] ?? '0'));
        $etapa           = $_POST['etapa'] ?? 'prospeccao';
        $probabilidade   = intval($_POST['probabilidade'] ?? 50);
        $data_previsao   = $_POST['data_previsao'] ?: null;
        $observacoes     = sanitize($_POST['observacoes'] ?? '');
        $responsavel     = intval($_POST['responsavel_id'] ?? $current_user_id);
        $lead_id         = intval($_POST['lead_id'] ?? 0) ?: null;
        $eid             = $is_admin ? intval($_POST['estabelecimento_id'] ?? $estab_id) : $estab_id;

        if (empty($titulo) || empty($cliente_nome)) {
            $error = 'Título e nome do cliente são obrigatórios.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO crm_oportunidades
                    (estabelecimento_id, lead_id, responsavel_id, titulo, cliente_nome,
                     cliente_email, cliente_telefone, valor_estimado, etapa, probabilidade,
                     data_previsao, observacoes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$eid, $lead_id, $responsavel, $titulo, $cliente_nome,
                                 $cliente_email, $cliente_tel, $valor, $etapa, $probabilidade,
                                 $data_previsao, $observacoes, $current_user_id])) {
                $op_id = $conn->lastInsertId();
                registrarInteracaoOp($conn, 'oportunidade', $op_id, 'criacao',
                    "Oportunidade criada por {$current_user_name}.", $current_user_id);
                Logger::info('Oportunidade criada', ['op_id' => $op_id, 'titulo' => $titulo]);
                $success = 'Oportunidade criada com sucesso!';
            } else {
                $error = 'Erro ao criar oportunidade.';
            }
        }
    }

    // ── Adicionar Interação ───────────────────────────────────
    if ($action === 'add_interacao') {
        $op_id      = intval($_POST['op_id'] ?? 0);
        $tipo       = $_POST['tipo_interacao'] ?? 'nota';
        $descricao  = sanitize($_POST['descricao'] ?? '');
        if ($op_id && !empty($descricao)) {
            registrarInteracaoOp($conn, 'oportunidade', $op_id, $tipo, $descricao, $current_user_id);
            $success = 'Interação registrada!';
        } else {
            $error = 'Preencha a descrição da interação.';
        }
    }

    // ── Transferir Oportunidade ───────────────────────────────
    if ($action === 'transferir') {
        $op_id        = intval($_POST['op_id'] ?? 0);
        $para_user_id = intval($_POST['para_user_id'] ?? 0);
        $motivo       = sanitize($_POST['motivo'] ?? '');

        if (!$op_id || !$para_user_id || empty($motivo)) {
            $error = 'Preencha todos os campos da transferência.';
        } else {
            $stmt = $conn->prepare("SELECT responsavel_id, titulo FROM crm_oportunidades WHERE id = ?");
            $stmt->execute([$op_id]);
            $op = $stmt->fetch();

            if (!$op) {
                $error = 'Oportunidade não encontrada.';
            } elseif ($op['responsavel_id'] == $para_user_id) {
                $error = 'A oportunidade já está com este colaborador.';
            } else {
                $de_user_id = $op['responsavel_id'];

                $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->execute([$de_user_id]);
                $nome_de = $stmt->fetchColumn();
                $stmt->execute([$para_user_id]);
                $nome_para = $stmt->fetchColumn();

                $conn->prepare("UPDATE crm_oportunidades SET responsavel_id = ? WHERE id = ?")
                     ->execute([$para_user_id, $op_id]);

                registrarTransferenciaOp($conn, 'oportunidade', $op_id,
                    $de_user_id, $para_user_id, $motivo, $current_user_id);

                $descricao_log = "Oportunidade transferida de <strong>{$nome_de}</strong> para <strong>{$nome_para}</strong>."
                               . " Motivo: {$motivo}";
                registrarInteracaoOp($conn, 'oportunidade', $op_id, 'transferencia',
                    $descricao_log, $current_user_id,
                    $de_user_id, $para_user_id, $motivo);

                Logger::info('Oportunidade transferida', [
                    'op_id' => $op_id, 'de' => $de_user_id,
                    'para'  => $para_user_id, 'por' => $current_user_id, 'motivo' => $motivo
                ]);
                $success = "Oportunidade transferida para {$nome_para} com sucesso!";
            }
        }
    }

    // ── Atualizar Etapa ───────────────────────────────────────
    if ($action === 'update_etapa') {
        $op_id      = intval($_POST['op_id'] ?? 0);
        $nova_etapa = $_POST['nova_etapa'] ?? '';
        $etapasOk   = ['prospeccao','qualificacao','proposta','negociacao','fechado_ganho','fechado_perdido'];
        if ($op_id && in_array($nova_etapa, $etapasOk)) {
            $stmt = $conn->prepare("SELECT etapa FROM crm_oportunidades WHERE id = ?");
            $stmt->execute([$op_id]);
            $old = $stmt->fetchColumn();
            $conn->prepare("UPDATE crm_oportunidades SET etapa = ? WHERE id = ?")->execute([$nova_etapa, $op_id]);
            registrarInteracaoOp($conn, 'oportunidade', $op_id, 'status',
                "Etapa alterada de <strong>{$old}</strong> para <strong>{$nova_etapa}</strong>.",
                $current_user_id);
            $success = 'Etapa atualizada!';
        }
    }

    // ── Excluir ───────────────────────────────────────────────
    if ($action === 'delete' && $is_admin) {
        $op_id = intval($_POST['op_id'] ?? 0);
        if ($op_id) {
            $conn->prepare("DELETE FROM crm_oportunidades WHERE id = ?")->execute([$op_id]);
            $success = 'Oportunidade excluída.';
        }
    }
}

// ─── Buscar Oportunidades ─────────────────────────────────────────────────────
$where  = $is_admin ? '' : 'WHERE o.estabelecimento_id = ?';
$params = $is_admin ? [] : [$estab_id];

$filtro_etapa  = $_GET['etapa'] ?? '';
$filtro_resp   = intval($_GET['responsavel'] ?? 0);
$filtro_busca  = trim($_GET['busca'] ?? '');

if ($filtro_etapa) {
    $where .= ($where ? ' AND' : 'WHERE') . ' o.etapa = ?';
    $params[] = $filtro_etapa;
}
if ($filtro_resp) {
    $where .= ($where ? ' AND' : 'WHERE') . ' o.responsavel_id = ?';
    $params[] = $filtro_resp;
}
if ($filtro_busca) {
    $where .= ($where ? ' AND' : 'WHERE') . ' (o.titulo LIKE ? OR o.cliente_nome LIKE ? OR o.cliente_email LIKE ?)';
    $like = "%{$filtro_busca}%";
    $params = array_merge($params, [$like, $like, $like]);
}

$stmt = $conn->prepare("
    SELECT o.*,
           u.name  AS responsavel_nome,
           e.name  AS estab_nome,
           l.nome  AS lead_nome
    FROM crm_oportunidades o
    LEFT JOIN users u              ON o.responsavel_id = u.id
    LEFT JOIN estabelecimentos e   ON o.estabelecimento_id = e.id
    LEFT JOIN crm_leads l          ON o.lead_id = l.id
    {$where}
    ORDER BY o.created_at DESC
");
$stmt->execute($params);
$oportunidades = $stmt->fetchAll();

// Estatísticas
$stmt_stats = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(etapa NOT IN ('fechado_ganho','fechado_perdido')) AS abertas,
        SUM(etapa = 'fechado_ganho') AS ganhas,
        SUM(etapa = 'fechado_perdido') AS perdidas,
        COALESCE(SUM(CASE WHEN etapa NOT IN ('fechado_ganho','fechado_perdido') THEN valor_estimado ELSE 0 END), 0) AS pipeline
    FROM crm_oportunidades
    " . ($is_admin ? '' : 'WHERE estabelecimento_id = ?')
);
$stmt_stats->execute($is_admin ? [] : [$estab_id]);
$stats = $stmt_stats->fetch();

$colaboradores    = getColaboradoresOp($conn, $estab_id, $is_admin);
$estabelecimentos = $is_admin ? $conn->query("SELECT id, name FROM estabelecimentos ORDER BY name")->fetchAll() : [];
$leads_disponiveis = $conn->prepare("
    SELECT id, nome FROM crm_leads
    WHERE " . ($is_admin ? '1=1' : 'estabelecimento_id = ?') . " AND status != 'desqualificado'
    ORDER BY nome
");
$leads_disponiveis->execute($is_admin ? [] : [$estab_id]);
$leads_list = $leads_disponiveis->fetchAll();

// Labels
$etapa_labels = [
    'prospeccao'      => ['label' => 'Prospecção',    'class' => 'badge-info',      'order' => 1],
    'qualificacao'    => ['label' => 'Qualificação',  'class' => 'badge-warning',   'order' => 2],
    'proposta'        => ['label' => 'Proposta',      'class' => 'badge-primary',   'order' => 3],
    'negociacao'      => ['label' => 'Negociação',    'class' => 'badge-secondary', 'order' => 4],
    'fechado_ganho'   => ['label' => 'Ganho ✓',       'class' => 'badge-success',   'order' => 5],
    'fechado_perdido' => ['label' => 'Perdido ✗',     'class' => 'badge-danger',    'order' => 6],
];
?>

<!-- Page Header -->
<div class="content-header">
    <h1><i class="fas fa-chart-line" style="color:var(--primary-color)"></i> Oportunidades</h1>
    <button class="btn btn-primary" onclick="openModal('modalNovaOp')">
        <i class="fas fa-plus"></i> Nova Oportunidade
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
        <div class="stat-icon bg-primary"><i class="fas fa-chart-line"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-info"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['abertas']; ?></div><div class="stat-label">Abertas</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-success"><i class="fas fa-trophy"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['ganhas']; ?></div><div class="stat-label">Ganhas</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-danger"><i class="fas fa-times-circle"></i></div>
        <div class="stat-info"><div class="stat-number"><?php echo $stats['perdidas']; ?></div><div class="stat-label">Perdidas</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#8b5cf6"><i class="fas fa-dollar-sign"></i></div>
        <div class="stat-info">
            <div class="stat-number" style="font-size:16px">R$ <?php echo number_format($stats['pipeline'], 2, ',', '.'); ?></div>
            <div class="stat-label">Pipeline</div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="content-card" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="flex:1;min-width:160px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Buscar</label>
            <input type="text" name="busca" class="form-control" placeholder="Título, cliente..." value="<?php echo htmlspecialchars($filtro_busca); ?>">
        </div>
        <div class="form-group" style="min-width:140px;margin:0">
            <label style="font-size:12px;font-weight:600;color:#6b7280">Etapa</label>
            <select name="etapa" class="form-control">
                <option value="">Todas</option>
                <?php foreach ($etapa_labels as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filtro_etapa === $k ? 'selected' : ''; ?>><?php echo $v['label']; ?></option>
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
            <a href="crm_oportunidades.php" class="btn btn-secondary"><i class="fas fa-times"></i> Limpar</a>
        </div>
    </form>
</div>

<!-- Tabela de Oportunidades -->
<div class="content-card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Título / Cliente</th>
                    <th>Valor</th>
                    <th>Etapa</th>
                    <th>Prob.</th>
                    <th>Previsão</th>
                    <th>Responsável</th>
                    <?php if ($is_admin): ?><th>Estabelecimento</th><?php endif; ?>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($oportunidades)): ?>
                <tr><td colspan="<?php echo $is_admin ? 9 : 8; ?>" style="text-align:center;color:#9ca3af;padding:32px">
                    <i class="fas fa-chart-line" style="font-size:32px;display:block;margin-bottom:8px"></i>
                    Nenhuma oportunidade encontrada.
                </td></tr>
            <?php else: ?>
                <?php foreach ($oportunidades as $op): ?>
                <?php $el = $etapa_labels[$op['etapa']] ?? ['label'=>$op['etapa'],'class'=>'badge-secondary']; ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($op['titulo']); ?></strong>
                        <div style="font-size:12px;color:#6b7280">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($op['cliente_nome']); ?>
                        </div>
                        <?php if ($op['lead_nome']): ?>
                        <div style="font-size:11px;color:#8b5cf6"><i class="fas fa-funnel-dollar"></i> Lead: <?php echo htmlspecialchars($op['lead_nome']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;color:#059669">
                        R$ <?php echo number_format($op['valor_estimado'], 2, ',', '.'); ?>
                    </td>
                    <td><span class="badge <?php echo $el['class']; ?>"><?php echo $el['label']; ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="width:36px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">
                                <div style="width:<?php echo $op['probabilidade']; ?>%;height:100%;background:<?php echo $op['probabilidade']>=70?'#10b981':($op['probabilidade']>=40?'#f59e0b':'#ef4444'); ?>;border-radius:3px"></div>
                            </div>
                            <span style="font-size:12px;font-weight:600"><?php echo $op['probabilidade']; ?>%</span>
                        </div>
                    </td>
                    <td style="font-size:12px;color:#6b7280">
                        <?php echo $op['data_previsao'] ? date('d/m/Y', strtotime($op['data_previsao'])) : '—'; ?>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary-color);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">
                                <?php echo strtoupper(substr($op['responsavel_nome'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span style="font-size:13px"><?php echo htmlspecialchars($op['responsavel_nome'] ?? '-'); ?></span>
                        </div>
                    </td>
                    <?php if ($is_admin): ?>
                    <td style="font-size:12px;color:#6b7280"><?php echo htmlspecialchars($op['estab_nome'] ?? '-'); ?></td>
                    <?php endif; ?>
                    <td style="font-size:12px;color:#6b7280"><?php echo date('d/m/Y', strtotime($op['created_at'])); ?></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <button class="btn-icon btn-info" title="Ver Histórico" onclick="verHistorico(<?php echo $op['id']; ?>, 'oportunidade', '<?php echo htmlspecialchars(addslashes($op['titulo'])); ?>')">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn-icon btn-warning" title="Adicionar Interação" onclick="abrirInteracao(<?php echo $op['id']; ?>)">
                                <i class="fas fa-comment-alt"></i>
                            </button>
                            <button class="btn-icon" style="background:#8b5cf6;color:#fff" title="Transferir" onclick="abrirTransferencia(<?php echo $op['id']; ?>, '<?php echo htmlspecialchars(addslashes($op['titulo'])); ?>', <?php echo $op['responsavel_id']; ?>)">
                                <i class="fas fa-exchange-alt"></i>
                            </button>
                            <button class="btn-icon btn-primary" title="Alterar Etapa" onclick="abrirEtapa(<?php echo $op['id']; ?>, '<?php echo $op['etapa']; ?>')">
                                <i class="fas fa-tag"></i>
                            </button>
                            <?php if ($is_admin): ?>
                            <button class="btn-icon btn-danger" title="Excluir" onclick="excluirOp(<?php echo $op['id']; ?>)">
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

<!-- Modal: Nova Oportunidade -->
<div id="modalNovaOp" class="modal-overlay" onclick="if(event.target===this)closeModal('modalNovaOp')">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-box-header">
            <h4><i class="fas fa-plus-circle" style="color:var(--primary-color);margin-right:8px"></i>Nova Oportunidade</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalNovaOp')">&times;</button>
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
                    <h5 class="form-section-title"><i class="fas fa-chart-line"></i> Dados da Oportunidade</h5>
                    <div class="form-group">
                        <label>Título <span class="text-danger">*</span></label>
                        <input type="text" name="titulo" class="form-control" placeholder="Ex: Contrato de fornecimento de chopp para evento" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label>Valor Estimado (R$)</label>
                            <input type="text" name="valor_estimado" class="form-control" placeholder="0,00">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Probabilidade (%)</label>
                            <input type="number" name="probabilidade" class="form-control" min="0" max="100" value="50">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Previsão de Fechamento</label>
                            <input type="date" name="data_previsao" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label>Etapa</label>
                            <select name="etapa" class="form-control">
                                <?php foreach ($etapa_labels as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v['label']; ?></option>
                                <?php endforeach; ?>
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
                        <div class="form-group" style="flex:1">
                            <label>Lead de Origem</label>
                            <select name="lead_id" class="form-control">
                                <option value="">Nenhum</option>
                                <?php foreach ($leads_list as $l): ?>
                                <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section" style="margin-top:16px">
                    <h5 class="form-section-title"><i class="fas fa-user"></i> Dados do Cliente</h5>
                    <div class="form-row">
                        <div class="form-group" style="flex:2">
                            <label>Nome do Cliente <span class="text-danger">*</span></label>
                            <input type="text" name="cliente_nome" class="form-control" placeholder="Nome completo" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Telefone</label>
                            <input type="text" name="cliente_telefone" class="form-control" placeholder="(00) 00000-0000">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="cliente_email" class="form-control" placeholder="email@empresa.com">
                    </div>
                    <div class="form-group">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalNovaOp')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Oportunidade</button>
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
            <input type="hidden" name="op_id" id="interacao_op_id">
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
            <h4><i class="fas fa-exchange-alt" style="color:#8b5cf6;margin-right:8px"></i>Transferir Oportunidade</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalTransferencia')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="transferir">
            <input type="hidden" name="op_id" id="transf_op_id">
            <div class="modal-box-body">
                <div style="background:#f3f4f6;border-radius:8px;padding:12px;margin-bottom:16px">
                    <div style="font-size:12px;color:#6b7280;margin-bottom:4px">Oportunidade sendo transferida:</div>
                    <div style="font-weight:700;color:#111827" id="transf_op_titulo"></div>
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
                        placeholder="Explique o motivo da transferência..."
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

<!-- Modal: Alterar Etapa -->
<div id="modalEtapa" class="modal-overlay" onclick="if(event.target===this)closeModal('modalEtapa')">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-box-header">
            <h4><i class="fas fa-tag" style="color:#0ea5e9;margin-right:8px"></i>Alterar Etapa</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalEtapa')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_etapa">
            <input type="hidden" name="op_id" id="etapa_op_id">
            <div class="modal-box-body">
                <div class="form-group">
                    <label>Nova Etapa</label>
                    <select name="nova_etapa" class="form-control" id="etapa_select">
                        <?php foreach ($etapa_labels as $k => $v): ?>
                        <option value="<?php echo $k; ?>"><?php echo $v['label']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEtapa')"><i class="fas fa-times"></i> Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Histórico -->
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

<!-- Modal: Excluir -->
<div id="modalExcluir" class="modal-overlay" onclick="if(event.target===this)closeModal('modalExcluir')">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-box-header">
            <h4><i class="fas fa-trash" style="color:#ef4444;margin-right:8px"></i>Excluir Oportunidade</h4>
            <button type="button" class="btn-close-modal" onclick="closeModal('modalExcluir')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="op_id" id="excluir_op_id">
            <div class="modal-box-body">
                <p style="color:#374151">Tem certeza que deseja excluir esta oportunidade? Todo o histórico será removido.</p>
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

function abrirInteracao(opId) {
    document.getElementById('interacao_op_id').value = opId;
    openModal('modalInteracao');
}

function abrirTransferencia(opId, titulo, respAtualId) {
    document.getElementById('transf_op_id').value = opId;
    document.getElementById('transf_op_titulo').textContent = titulo;
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

function abrirEtapa(opId, etapaAtual) {
    document.getElementById('etapa_op_id').value = opId;
    document.getElementById('etapa_select').value = etapaAtual;
    openModal('modalEtapa');
}

function excluirOp(opId) {
    document.getElementById('excluir_op_id').value = opId;
    openModal('modalExcluir');
}

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
    let extra = '';
    if (i.tipo === 'transferencia' && i.motivo_transferencia) {
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
