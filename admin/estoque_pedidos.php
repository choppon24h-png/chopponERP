<?php
/**
 * ESTOQUE - PEDIDOS
 * Pedidos de saída de estoque para estabelecimentos ou clientes finais.
 * Fluxo: Aguardando → Visualizado → Faturado (baixa no estoque)
 */
$page_title   = 'Estoque - Pedidos';
$current_page = 'estoque_movimentacoes';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/PedidoEstoqueManager.php';
requireAuth();

$conn          = getDBConnection();
$user_estab_id = isAdminGeral() ? null : getEstabelecimentoId();
$pm            = new PedidoEstoqueManager($conn, $user_estab_id);
$success = '';
$error   = '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── Buscar dados do estabelecimento do usuário logado (para PIX default) ──
$estab_logado = null;
if ($user_estab_id) {
    $stmt_estab = $conn->prepare("SELECT id, name, document, phone, address FROM estabelecimentos WHERE id = ? LIMIT 1");
    $stmt_estab->execute([$user_estab_id]);
    $estab_logado = $stmt_estab->fetch(PDO::FETCH_ASSOC);
} else {
    // Admin geral: buscar o primeiro estabelecimento ativo como referência
    $stmt_estab = $conn->query("SELECT id, name, document, phone, address FROM estabelecimentos WHERE status = 1 ORDER BY id ASC LIMIT 1");
    $estab_logado = $stmt_estab->fetch(PDO::FETCH_ASSOC);
}

// ── Processar ações POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'criar_pedido') {
        $itens = [];
        $produtos_ids  = $_POST['item_produto_id']    ?? [];
        $quantidades   = $_POST['item_quantidade']    ?? [];
        $precos        = $_POST['item_preco']         ?? [];
        $obs_itens     = $_POST['item_obs']           ?? [];

        foreach ($produtos_ids as $k => $pid) {
            if (!empty($pid) && (int)($quantidades[$k] ?? 0) > 0) {
                $itens[] = [
                    'produto_id'    => $pid,
                    'quantidade'    => $quantidades[$k],
                    'preco_unitario'=> $precos[$k] ?? '0',
                    'obs'           => $obs_itens[$k] ?? '',
                ];
            }
        }
        $_POST['itens'] = $itens;

        $resultado = $pm->criar($_POST, $user_id);
        if ($resultado['success']) {
            header("Location: estoque_pedidos.php?msg=" . urlencode($resultado['message']));
            exit;
        } else {
            $error = $resultado['message'];
        }
    }

    if ($action === 'editar_pedido') {
        $pedido_id_edit = (int)($_POST['pedido_id'] ?? 0);
        $itens = [];
        $produtos_ids  = $_POST['item_produto_id']    ?? [];
        $quantidades   = $_POST['item_quantidade']    ?? [];
        $precos        = $_POST['item_preco']         ?? [];
        $obs_itens     = $_POST['item_obs']           ?? [];

        foreach ($produtos_ids as $k => $pid) {
            if (!empty($pid) && (int)($quantidades[$k] ?? 0) > 0) {
                $itens[] = [
                    'produto_id'    => $pid,
                    'quantidade'    => $quantidades[$k],
                    'preco_unitario'=> $precos[$k] ?? '0',
                    'obs'           => $obs_itens[$k] ?? '',
                ];
            }
        }
        $_POST['itens'] = $itens;

        $resultado = $pm->editar($pedido_id_edit, $_POST, $user_id);
        if ($resultado['success']) {
            header("Location: estoque_pedidos.php?msg=" . urlencode($resultado['message']));
            exit;
        } else {
            $error = $resultado['message'];
        }
    }

    if ($action === 'faturar') {
        $resultado = $pm->faturar((int)$_POST['pedido_id'], $user_id);
        if ($resultado['success']) {
            header("Location: estoque_pedidos.php?msg=" . urlencode($resultado['message']));
            exit;
        } else {
            $error = $resultado['message'];
        }
    }

    if ($action === 'cancelar') {
        $resultado = $pm->cancelar((int)$_POST['pedido_id']);
        if ($resultado['success']) {
            header("Location: estoque_pedidos.php?msg=" . urlencode($resultado['message']));
            exit;
        } else {
            $error = $resultado['message'];
        }
    }

    if ($action === 'visualizar') {
        $pm->marcarVisualizado((int)$_POST['pedido_id']);
        header("Location: estoque_pedidos.php?ver=" . (int)$_POST['pedido_id']);
        exit;
    }
}

if (!empty($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
}

// ── Carregar dados ────────────────────────────────────────────
$filtros = [
    'status'             => $_GET['status']      ?? '',
    'busca'              => $_GET['busca']       ?? '',
    'data_inicio'        => $_GET['data_inicio'] ?? '',
    'data_fim'           => $_GET['data_fim']    ?? '',
    'estabelecimento_id' => !isAdminGeral()
        ? $user_estab_id
        : (isset($_GET['estab']) && $_GET['estab'] !== '' ? (int)$_GET['estab'] : null),
];
$pedidos          = $pm->listar($filtros);
$estabelecimentos = $pm->listarEstabelecimentos();
$produtos         = $pm->listarProdutos();
$stats            = $pm->estatisticas($user_estab_id);

// Pedido para visualização
$pedido_ver   = null;
$itens_ver    = [];
if (!empty($_GET['ver'])) {
    $pedido_ver = $pm->buscarPorId((int)$_GET['ver']);
    if ($pedido_ver) {
        $itens_ver = $pm->buscarItens((int)$_GET['ver']);
    }
}

// Pedido para edição (carregado via JS/AJAX inline)
$pedido_editar = null;
$itens_editar  = [];
if (!empty($_GET['editar'])) {
    $pedido_editar = $pm->buscarPorId((int)$_GET['editar']);
    if ($pedido_editar && in_array($pedido_editar['status'], ['aguardando', 'visualizado'])) {
        $itens_editar = $pm->buscarItens((int)$_GET['editar']);
    } else {
        $pedido_editar = null;
    }
}

// Labels de pagamento
$pagamento_labels = [
    'pix'           => 'PIX',
    'debito'        => 'Cartão de Débito',
    'credito'       => 'Cartão de Crédito',
    'entrada_50_50' => 'Entrada 50% + 50% na Entrega',
];

require_once '../includes/header.php';
?>

<style>
/* stat-icon colors */
.stat-icon.yellow { background:#fff8e1; color:#f59e0b; }
.stat-icon.blue   { background:#e3f0ff; color:#007bff; }
.stat-icon.green  { background:#e6f9ee; color:#28a745; }
.stat-icon.red    { background:#fdecea; color:#dc3545; }
.stat-icon.teal   { background:#e0f7fa; color:#17a2b8; }
.stat-number { font-size:20px; font-weight:700; line-height:1; color:var(--gray-800); }
.stat-label  { font-size:11px; color:var(--gray-600); margin-top:3px; }
@media (max-width: 992px) {
    .stats-grid[style*="repeat(5"] { grid-template-columns: repeat(3,1fr) !important; }
}
@media (max-width: 576px) {
    .stats-grid[style*="repeat(5"] { grid-template-columns: repeat(2,1fr) !important; }
}
/* Status badges */
.badge-aguardando  { background:#fff8e1; color:#f59e0b; }
.badge-visualizado { background:#e3f0ff; color:#007bff; }
.badge-faturado    { background:#e6f9ee; color:#28a745; }
.badge-cancelado   { background:#fdecea; color:#dc3545; }
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
/* Botões de ação */
.btn-pedido { padding:8px 18px; border-radius:6px; border:none; cursor:pointer; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:all .2s; text-decoration:none; }
.btn-pedido.purple { background:#7c3aed; color:#fff; }
.btn-pedido.purple:hover { background:#6d28d9; }
/* Formulário de pedido */
.item-row { background:#f8f9fa; border-radius:8px; padding:12px; margin-bottom:10px; position:relative; }
.item-row .btn-remove { position:absolute; top:8px; right:8px; background:#fdecea; color:#dc3545; border:none; border-radius:50%; width:26px; height:26px; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; }
.total-box { background:linear-gradient(135deg,#0066cc,#004499); color:#fff; border-radius:10px; padding:18px 22px; }
.total-box .total-line { display:flex; justify-content:space-between; padding:4px 0; font-size:14px; }
.total-box .total-final { display:flex; justify-content:space-between; padding-top:10px; margin-top:8px; border-top:1px solid rgba(255,255,255,.3); font-size:20px; font-weight:700; }
/* Visualização do pedido */
.ped-section { margin-bottom:18px; }
.ped-section h6 { font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-600); margin-bottom:8px; border-bottom:1px solid var(--gray-300); padding-bottom:4px; }
.ped-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; }
.ped-info-item .label { font-size:11px; color:var(--gray-600); }
.ped-info-item .value { font-size:14px; font-weight:600; }
/* Aviso operacional */
.aviso-operacional { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:12px 16px; font-size:12px; color:#856404; margin-top:12px; }
.aviso-operacional strong { display:block; margin-bottom:4px; font-size:13px; }
/* Pagamento badge */
.badge-pix           { background:#e8f5e9; color:#2e7d32; }
.badge-debito        { background:#e3f2fd; color:#1565c0; }
.badge-credito       { background:#f3e5f5; color:#6a1b9a; }
.badge-entrada_50_50 { background:#fff8e1; color:#e65100; }
</style>

<div class="container-fluid">

    <!-- Cabeçalho -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-shopping-bag"></i> Pedidos de Estoque</h1>
            <p class="text-muted">Pedidos de saída para estabelecimentos e clientes finais</p>
        </div>
        <button type="button" class="btn-pedido purple" onclick="abrirModalPedido()">
            <i class="fas fa-plus"></i> Novo Pedido
        </button>
    </div>

    <!-- Tabs de navegação -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php"      class="tab-link"><i class="fas fa-box"></i> Cadastro</a>
        <a href="estoque_visao.php"         class="tab-link"><i class="fas fa-warehouse"></i> Estoque</a>
        <a href="estoque_movimentacoes.php" class="tab-link"><i class="fas fa-exchange-alt"></i> Movimentações</a>
        <a href="estoque_relatorios.php"    class="tab-link"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="estoque_inventario.php"    class="tab-link"><i class="fas fa-archive"></i> Inventário</a>
        <a href="estoque_pedidos.php"       class="tab-link active"><i class="fas fa-shopping-bag"></i> Pedidos</a>
    </div>

    <!-- Alertas -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="fas fa-check-circle"></i> <?= $success ?>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    </div>
    <?php endif; ?>

    <!-- Cards de estatísticas -->
    <div class="stats-grid" style="grid-template-columns:repeat(5,1fr);">
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
            <div class="stat-info"><div class="stat-number"><?= $stats['aguardando'] ?? 0 ?></div><div class="stat-label">Aguardando</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-eye"></i></div>
            <div class="stat-info"><div class="stat-number"><?= $stats['visualizado'] ?? 0 ?></div><div class="stat-label">Visualizados</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><div class="stat-number"><?= $stats['faturado'] ?? 0 ?></div><div class="stat-label">Faturados</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info"><div class="stat-number"><?= $stats['cancelado'] ?? 0 ?></div><div class="stat-label">Cancelados</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon teal"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-info"><div class="stat-number">R$ <?= number_format($stats['valor_faturado'] ?? 0, 0, ',', '.') ?></div><div class="stat-label">Total Faturado</div></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" id="formFiltrosPedidos">
                <div class="filter-grid">
                    <div class="filter-item filter-item-wide">
                        <label class="filter-label">Busca</label>
                        <input type="text" name="busca" class="form-control"
                               placeholder="Buscar por número, cliente, estabelecimento..."
                               value="<?= htmlspecialchars($filtros['busca']) ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="aguardando"  <?= $filtros['status'] === 'aguardando'  ? 'selected' : '' ?>>Aguardando</option>
                            <option value="visualizado" <?= $filtros['status'] === 'visualizado' ? 'selected' : '' ?>>Visualizado</option>
                            <option value="faturado"    <?= $filtros['status'] === 'faturado'    ? 'selected' : '' ?>>Faturado</option>
                            <option value="cancelado"   <?= $filtros['status'] === 'cancelado'   ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                    </div>
                    <div class="filter-item filter-item-btn">
                        <label class="filter-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                            <?php if (array_filter($filtros)): ?>
                            <a href="estoque_pedidos.php" class="btn btn-outline-secondary" title="Limpar"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de pedidos -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h5 style="margin:0;"><i class="fas fa-list"></i> Pedidos</h5>
            <span style="font-size:13px;color:var(--gray-600);"><?= count($pedidos) ?> registro(s)</span>
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <?php if (empty($pedidos)): ?>
            <div style="text-align:center;padding:48px;color:var(--gray-600);">
                <i class="fas fa-shopping-bag" style="font-size:48px;opacity:.3;"></i>
                <p style="margin-top:16px;">Nenhum pedido encontrado.</p>
            </div>
            <?php else: ?>
            <table class="table table-hover" style="margin:0;font-size:13px;">
                <thead style="background:var(--gray-100);">
                    <tr>
                        <th>Nº Pedido</th>
                        <th>Data</th>
                        <th>Destinatário</th>
                        <th>Pagamento</th>
                        <th>Itens</th>
                        <th>Entrega</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th style="width:200px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pedidos as $ped): ?>
                <?php
                    $badge_class = match($ped['status']) {
                        'aguardando'  => 'badge-aguardando',
                        'visualizado' => 'badge-visualizado',
                        'faturado'    => 'badge-faturado',
                        'cancelado'   => 'badge-cancelado',
                        default       => 'badge-aguardando',
                    };
                    $badge_label = match($ped['status']) {
                        'aguardando'  => 'Aguardando',
                        'visualizado' => 'Visualizado',
                        'faturado'    => 'Faturado',
                        'cancelado'   => 'Cancelado',
                        default       => $ped['status'],
                    };
                    $pag_key   = $ped['pagamento'] ?? 'pix';
                    $pag_label = $pagamento_labels[$pag_key] ?? $pag_key;
                    $pag_badge = 'badge-' . $pag_key;
                    $stmt_cnt = $conn->prepare("SELECT COUNT(*) FROM estoque_pedido_itens WHERE pedido_id = ?");
                    $stmt_cnt->execute([$ped['id']]);
                    $num_itens = $stmt_cnt->fetchColumn();
                    $pode_editar = in_array($ped['status'], ['aguardando', 'visualizado']);
                ?>
                <tr>
                    <td><strong style="color:var(--primary-color);font-family:monospace;"><?= htmlspecialchars($ped['numero_pedido']) ?></strong></td>
                    <td><?= date('d/m/Y H:i', strtotime($ped['created_at'])) ?></td>
                    <td>
                        <?php if ($ped['tipo_destinatario'] === 'estabelecimento'): ?>
                        <i class="fas fa-store" style="color:var(--primary-color)"></i>
                        <?= htmlspecialchars($ped['estabelecimento_nome'] ?? '—') ?>
                        <?php else: ?>
                        <i class="fas fa-user" style="color:var(--success-color)"></i>
                        <?= htmlspecialchars($ped['cliente_nome'] ?? '—') ?>
                        <?php if ($ped['cliente_cpf_cnpj']): ?>
                        <br><small style="color:var(--gray-600);"><?= htmlspecialchars($ped['cliente_cpf_cnpj']) ?></small>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $pag_badge ?>"><?= htmlspecialchars($pag_label) ?></span></td>
                    <td><span style="background:var(--gray-200);padding:2px 8px;border-radius:20px;"><?= $num_itens ?> item(ns)</span></td>
                    <td>
                        <?php if ($ped['entrega']): ?>
                        <span style="color:var(--success-color);"><i class="fas fa-truck"></i> R$ <?= number_format($ped['entrega_taxa'], 2, ',', '.') ?></span>
                        <?php else: ?>
                        <span style="color:var(--gray-600);">Retirada</span>
                        <?php endif; ?>
                    </td>
                    <td><strong>R$ <?= number_format($ped['total'], 2, ',', '.') ?></strong></td>
                    <td><span class="badge <?= $badge_class ?>"><?= $badge_label ?></span></td>
                    <td>
                        <!-- Visualizar -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="visualizar">
                            <input type="hidden" name="pedido_id" value="<?= $ped['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-info" title="Visualizar pedido" style="padding:4px 8px;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </form>
                        <!-- PDF/Imprimir -->
                        <a href="pedido_pdf.php?id=<?= $ped['id'] ?>" target="_blank"
                           class="btn btn-sm btn-secondary" title="Imprimir / PDF" style="padding:4px 8px;">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php if ($pode_editar): ?>
                        <!-- Editar -->
                        <button type="button" class="btn btn-sm btn-warning" title="Editar pedido"
                                style="padding:4px 8px;"
                                onclick="carregarEdicao(<?= $ped['id'] ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <!-- Faturar -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Faturar pedido <?= htmlspecialchars($ped['numero_pedido']) ?>? Isso dará baixa no estoque.');">
                            <input type="hidden" name="action" value="faturar">
                            <input type="hidden" name="pedido_id" value="<?= $ped['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Faturar" style="padding:4px 8px;">
                                <i class="fas fa-check"></i>
                            </button>
                        </form>
                        <!-- Cancelar -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancelar pedido <?= htmlspecialchars($ped['numero_pedido']) ?>?');">
                            <input type="hidden" name="action" value="cancelar">
                            <input type="hidden" name="pedido_id" value="<?= $ped['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Cancelar" style="padding:4px 8px;">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /container-fluid -->

<!-- ── Modal: Visualizar Pedido ──────────────────────────────────────────── -->
<?php if ($pedido_ver): ?>
<div class="modal-overlay active" id="modalVer">
    <div class="modal-box" style="max-width:860px;" onclick="event.stopPropagation()">
        <div class="modal-box-header">
            <h4><i class="fas fa-eye"></i> Pedido <?= htmlspecialchars($pedido_ver['numero_pedido']) ?></h4>
            <button class="btn-close-modal" onclick="document.getElementById('modalVer').classList.remove('active')">×</button>
        </div>
        <div class="modal-box-body">

            <!-- Cabeçalho do estabelecimento emissor -->
            <?php
            $emissor_nome    = $pedido_ver['origem_nome']     ?? ($pedido_ver['estabelecimento_nome'] ?? 'Chopp On Tap');
            $emissor_cnpj    = $pedido_ver['origem_document'] ?? '';
            $emissor_tel     = $pedido_ver['origem_phone']    ?? '';
            $emissor_end     = $pedido_ver['origem_address']  ?? '';
            ?>
            <div style="background:linear-gradient(135deg,#0066cc,#004499);color:#fff;border-radius:10px;padding:20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <img src="<?= SITE_URL ?>/assets/images/logo.png" alt="Choppon" style="height:50px;background:#fff;border-radius:6px;padding:4px;">
                    <div>
                        <div style="font-size:18px;font-weight:700;"><?= htmlspecialchars($emissor_nome) ?></div>
                        <?php if ($emissor_cnpj): ?><div style="font-size:12px;opacity:.85;">CNPJ: <?= htmlspecialchars($emissor_cnpj) ?></div><?php endif; ?>
                        <?php if ($emissor_tel): ?><div style="font-size:12px;opacity:.85;"><i class="fas fa-phone"></i> <?= htmlspecialchars($emissor_tel) ?></div><?php endif; ?>
                        <?php if ($emissor_end): ?><div style="font-size:11px;opacity:.75;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($emissor_end) ?></div><?php endif; ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:22px;font-weight:700;"><?= htmlspecialchars($pedido_ver['numero_pedido']) ?></div>
                    <div style="font-size:12px;opacity:.8;"><?= date('d/m/Y H:i', strtotime($pedido_ver['created_at'])) ?></div>
                    <span style="background:rgba(255,255,255,.2);padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;">
                        <?= match($pedido_ver['status']) { 'aguardando'=>'AGUARDANDO', 'visualizado'=>'VISUALIZADO', 'faturado'=>'FATURADO', 'cancelado'=>'CANCELADO', default=>strtoupper($pedido_ver['status']) } ?>
                    </span>
                </div>
            </div>

            <!-- Dados do destinatário -->
            <div class="ped-section">
                <h6><i class="fas fa-user"></i> Dados do Destinatário</h6>
                <div class="ped-info-grid">
                    <?php if ($pedido_ver['tipo_destinatario'] === 'estabelecimento'): ?>
                    <div class="ped-info-item"><div class="label">Estabelecimento</div><div class="value"><?= htmlspecialchars($pedido_ver['estabelecimento_nome'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">CNPJ/Documento</div><div class="value"><?= htmlspecialchars($pedido_ver['estabelecimento_document'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">Endereço</div><div class="value"><?= htmlspecialchars($pedido_ver['estabelecimento_address'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">Telefone</div><div class="value"><?= htmlspecialchars($pedido_ver['estabelecimento_phone'] ?? '—') ?></div></div>
                    <?php else: ?>
                    <div class="ped-info-item"><div class="label">Cliente</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_nome'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">CPF/CNPJ</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_cpf_cnpj'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">E-mail</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_email'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">Telefone</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_telefone'] ?? '—') ?></div></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagamento -->
            <div class="ped-section">
                <h6><i class="fas fa-credit-card"></i> Forma de Pagamento</h6>
                <?php
                $pag_ver = $pedido_ver['pagamento'] ?? 'pix';
                $pag_ver_label = $pagamento_labels[$pag_ver] ?? $pag_ver;
                $cnpj_pix = $emissor_cnpj ?: '—';
                ?>
                <div class="ped-info-grid">
                    <div class="ped-info-item">
                        <div class="label">Forma</div>
                        <div class="value"><span class="badge badge-<?= $pag_ver ?>"><?= htmlspecialchars($pag_ver_label) ?></span></div>
                    </div>
                    <?php if ($pag_ver === 'pix'): ?>
                    <div class="ped-info-item">
                        <div class="label">Chave PIX (CNPJ)</div>
                        <div class="value" style="font-family:monospace;font-size:16px;"><?= htmlspecialchars($cnpj_pix) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($pedido_ver['entrega']): ?>
            <div class="ped-section">
                <h6><i class="fas fa-truck"></i> Endereço de Entrega</h6>
                <div class="ped-info-grid">
                    <div class="ped-info-item"><div class="label">Logradouro</div><div class="value"><?= htmlspecialchars($pedido_ver['entrega_logradouro'] ?? '') ?>, <?= htmlspecialchars($pedido_ver['entrega_numero'] ?? '') ?> <?= htmlspecialchars($pedido_ver['entrega_complemento'] ?? '') ?></div></div>
                    <div class="ped-info-item"><div class="label">Bairro</div><div class="value"><?= htmlspecialchars($pedido_ver['entrega_bairro'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">Cidade/UF</div><div class="value"><?= htmlspecialchars($pedido_ver['entrega_cidade'] ?? '') ?>/<?= htmlspecialchars($pedido_ver['entrega_estado'] ?? '') ?></div></div>
                    <div class="ped-info-item"><div class="label">CEP</div><div class="value"><?= htmlspecialchars($pedido_ver['entrega_cep'] ?? '—') ?></div></div>
                </div>
                <!-- Aviso operacional de entrega -->
                <div class="aviso-operacional">
                    <strong><i class="fas fa-exclamation-triangle"></i> Condições de Entrega</strong>
                    Não subimos escadas para entrega. O ponto deverá ser de 127 volts, tomada única, sem extensão ou adaptador.
                </div>
            </div>
            <?php endif; ?>

            <!-- Itens do pedido -->
            <div class="ped-section">
                <h6><i class="fas fa-boxes"></i> Itens do Pedido</h6>
                <table class="table table-sm" style="font-size:13px;">
                    <thead style="background:var(--gray-100);">
                        <tr>
                            <th>Produto</th>
                            <th>Código</th>
                            <th>Qtd</th>
                            <th>Preço Unit.</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itens_ver as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['produto_nome']) ?></td>
                        <td><code><?= htmlspecialchars($item['produto_codigo']) ?></code></td>
                        <td><?= $item['quantidade'] ?></td>
                        <td>R$ <?= number_format($item['preco_unitario'], 2, ',', '.') ?></td>
                        <td><strong>R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Totais -->
            <div style="max-width:320px;margin-left:auto;">
                <div class="total-box">
                    <div class="total-line"><span>Subtotal</span><span>R$ <?= number_format($pedido_ver['subtotal'], 2, ',', '.') ?></span></div>
                    <?php if ($pedido_ver['entrega']): ?>
                    <div class="total-line"><span><i class="fas fa-truck"></i> Taxa de Entrega</span><span>R$ <?= number_format($pedido_ver['entrega_taxa'], 2, ',', '.') ?></span></div>
                    <?php endif; ?>
                    <?php if ($pedido_ver['desconto'] > 0): ?>
                    <div class="total-line"><span>Desconto</span><span>- R$ <?= number_format($pedido_ver['desconto'], 2, ',', '.') ?></span></div>
                    <?php endif; ?>
                    <div class="total-final"><span>TOTAL</span><span>R$ <?= number_format($pedido_ver['total'], 2, ',', '.') ?></span></div>
                </div>
            </div>

            <?php if ($pedido_ver['observacoes']): ?>
            <div class="ped-section" style="margin-top:16px;">
                <h6><i class="fas fa-comment"></i> Observações</h6>
                <p style="font-size:13px;"><?= nl2br(htmlspecialchars($pedido_ver['observacoes'])) ?></p>
            </div>
            <?php endif; ?>

        </div>
        <div class="modal-box-footer">
            <a href="pedido_pdf.php?id=<?= $pedido_ver['id'] ?>&print=1" target="_blank" class="btn btn-danger">
                <i class="fas fa-print"></i> Imprimir
            </a>
            <a href="pedido_pdf.php?id=<?= $pedido_ver['id'] ?>" target="_blank" class="btn btn-secondary">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <?php if (in_array($pedido_ver['status'], ['aguardando', 'visualizado'])): ?>
            <button type="button" class="btn btn-warning" onclick="document.getElementById('modalVer').classList.remove('active');carregarEdicao(<?= $pedido_ver['id'] ?>)">
                <i class="fas fa-edit"></i> Editar
            </button>
            <form method="POST" onsubmit="return confirm('Faturar e dar baixa no estoque?');" style="display:inline;">
                <input type="hidden" name="action" value="faturar">
                <input type="hidden" name="pedido_id" value="<?= $pedido_ver['id'] ?>">
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Faturar</button>
            </form>
            <?php endif; ?>
            <a href="estoque_pedidos.php" class="btn btn-outline-secondary">Fechar</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal: Criar / Editar Pedido ──────────────────────────────────────── -->
<div class="modal-overlay" id="modalPedido">
    <div class="modal-box" style="max-width:960px;" onclick="event.stopPropagation()">
        <div class="modal-box-header">
            <h4 id="modalPedidoTitulo"><i class="fas fa-plus-circle" style="color:#7c3aed"></i> Novo Pedido</h4>
            <button class="btn-close-modal" onclick="fecharModalPedido()">×</button>
        </div>
        <form method="POST" id="formPedido">
            <input type="hidden" name="action" value="criar_pedido" id="formPedidoAction">
            <input type="hidden" name="pedido_id" value="" id="formPedidoId">
            <div class="modal-box-body">
                <div class="row g-3">

                    <!-- Tipo de destinatário -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Tipo de Destinatário</label>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border:2px solid var(--gray-300);border-radius:8px;transition:all .2s;" id="labelEstab">
                                <input type="radio" name="tipo_destinatario" value="estabelecimento" checked onchange="toggleTipoDestinatario(this)">
                                <i class="fas fa-store" style="color:var(--primary-color)"></i> Estabelecimento
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border:2px solid var(--gray-300);border-radius:8px;transition:all .2s;" id="labelCliente">
                                <input type="radio" name="tipo_destinatario" value="cliente_final" onchange="toggleTipoDestinatario(this)">
                                <i class="fas fa-user" style="color:var(--success-color)"></i> Cliente Final
                            </label>
                        </div>
                    </div>

                    <!-- Campos: Estabelecimento -->
                    <div class="col-md-6" id="campoEstabelecimento">
                        <label class="form-label required">Estabelecimento</label>
                        <select name="estabelecimento_id" id="selectEstabelecimento" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($estabelecimentos as $est): ?>
                            <option value="<?= $est['id'] ?>"><?= htmlspecialchars($est['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Campos: Cliente Final -->
                    <div id="camposClienteFinal" style="display:none;width:100%;">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label required">Nome do Cliente</label>
                                <input type="text" name="cliente_nome" id="inputClienteNome" class="form-control" placeholder="Nome completo">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">CPF / CNPJ</label>
                                <input type="text" name="cliente_cpf_cnpj" id="inputClienteCpf" class="form-control" placeholder="000.000.000-00">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="cliente_email" id="inputClienteEmail" class="form-control" placeholder="email@exemplo.com">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="cliente_telefone" id="inputClienteTel" class="form-control" placeholder="(00) 00000-0000">
                            </div>
                        </div>
                    </div>

                    <!-- Forma de Pagamento -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold required"><i class="fas fa-credit-card"></i> Forma de Pagamento</label>
                        <select name="pagamento" id="selectPagamento" class="form-control" onchange="atualizarInfoPix()">
                            <option value="pix">PIX</option>
                            <option value="debito">Cartão de Débito</option>
                            <option value="credito">Cartão de Crédito</option>
                            <option value="entrada_50_50">Entrada 50% + 50% na Entrega</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="infoPix">
                        <label class="form-label"><i class="fas fa-qrcode"></i> Chave PIX (CNPJ do estabelecimento)</label>
                        <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:16px;font-weight:700;color:#2e7d32;letter-spacing:1px;">
                            <?= htmlspecialchars($estab_logado['document'] ?? '—') ?>
                        </div>
                    </div>

                    <!-- Entrega -->
                    <div class="col-12">
                        <div style="background:#f0f7ff;border-radius:8px;padding:14px;">
                            <div class="form-check form-switch" style="font-size:15px;margin-bottom:0;">
                                <input class="form-check-input" type="checkbox" id="checkEntrega" name="entrega" value="1" onchange="toggleEntrega(this)">
                                <label class="form-check-label fw-semibold" for="checkEntrega">
                                    <i class="fas fa-truck"></i> Incluir entrega
                                </label>
                            </div>
                            <div id="camposEntrega" style="display:none;margin-top:14px;">
                                <div class="row g-2">
                                    <div class="col-md-2">
                                        <label class="form-label">CEP</label>
                                        <input type="text" name="entrega_cep" id="entrega_cep" class="form-control form-control-sm" placeholder="00000-000" onblur="buscarCEP(this)">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Logradouro</label>
                                        <input type="text" name="entrega_logradouro" id="entrega_logradouro" class="form-control form-control-sm" placeholder="Rua, Av...">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Nº</label>
                                        <input type="text" name="entrega_numero" id="entrega_numero" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Complemento</label>
                                        <input type="text" name="entrega_complemento" id="entrega_complemento" class="form-control form-control-sm" placeholder="Apto, Sala...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Bairro</label>
                                        <input type="text" name="entrega_bairro" id="entrega_bairro" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Cidade</label>
                                        <input type="text" name="entrega_cidade" id="entrega_cidade" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">UF</label>
                                        <input type="text" name="entrega_estado" id="entrega_estado" class="form-control form-control-sm" maxlength="2">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Taxa de Entrega (R$)</label>
                                        <input type="text" name="entrega_taxa" id="entrega_taxa" class="form-control form-control-sm money-mask" placeholder="0,00" oninput="recalcularTotal()">
                                    </div>
                                </div>
                                <!-- Aviso operacional fixo -->
                                <div class="aviso-operacional" style="margin-top:10px;">
                                    <strong><i class="fas fa-exclamation-triangle"></i> Condições de Entrega</strong>
                                    Não subimos escadas para entrega. O ponto deverá ser de 127 volts, tomada única, sem extensão ou adaptador.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Produtos do pedido -->
                    <div class="col-12">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                            <label class="form-label fw-semibold mb-0"><i class="fas fa-boxes"></i> Produtos do Pedido</label>
                            <button type="button" class="btn btn-sm btn-primary" onclick="adicionarItem()">
                                <i class="fas fa-plus"></i> Adicionar Produto
                            </button>
                        </div>
                        <div id="listaItens">
                            <!-- itens adicionados dinamicamente -->
                        </div>
                        <div id="semItens" style="text-align:center;padding:20px;color:var(--gray-600);background:var(--gray-100);border-radius:8px;">
                            <i class="fas fa-box-open" style="font-size:28px;opacity:.4;"></i>
                            <p style="margin-top:8px;font-size:13px;">Clique em "Adicionar Produto" para incluir itens no pedido.</p>
                        </div>
                    </div>

                    <!-- Desconto e observações -->
                    <div class="col-md-3">
                        <label class="form-label">Desconto (R$)</label>
                        <input type="text" name="desconto" id="inputDesconto" class="form-control money-mask" placeholder="0,00" oninput="recalcularTotal()">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" id="inputObservacoes" class="form-control" rows="2" placeholder="Observações do pedido..."></textarea>
                    </div>

                    <!-- Resumo de totais -->
                    <div class="col-12">
                        <div class="total-box">
                            <div class="total-line"><span>Subtotal dos Produtos</span><span id="resumoSubtotal">R$ 0,00</span></div>
                            <div class="total-line"><span><i class="fas fa-truck"></i> Taxa de Entrega</span><span id="resumoEntrega">R$ 0,00</span></div>
                            <div class="total-line"><span>Desconto</span><span id="resumoDesconto">- R$ 0,00</span></div>
                            <div class="total-final"><span>TOTAL DO PEDIDO</span><span id="resumoTotal">R$ 0,00</span></div>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalPedido()">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed;" id="btnSalvarPedido">
                    <i class="fas fa-save"></i> Salvar Pedido
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Dados de produtos e pedidos para JS -->
<script>
const PRODUTOS = <?= json_encode(array_values($produtos), JSON_UNESCAPED_UNICODE) ?>;
<?php if ($pedido_editar): ?>
const PEDIDO_EDITAR = <?= json_encode($pedido_editar, JSON_UNESCAPED_UNICODE) ?>;
const ITENS_EDITAR  = <?= json_encode($itens_editar,  JSON_UNESCAPED_UNICODE) ?>;
<?php else: ?>
const PEDIDO_EDITAR = null;
const ITENS_EDITAR  = [];
<?php endif; ?>
let itemCount = 0;

// ── Abrir/fechar modal Pedido ─────────────────────────────────────────────
function abrirModalPedido(modoEdicao = false) {
    if (!modoEdicao) {
        // Modo criação: limpar formulário
        document.getElementById('formPedidoAction').value = 'criar_pedido';
        document.getElementById('formPedidoId').value     = '';
        document.getElementById('modalPedidoTitulo').innerHTML = '<i class="fas fa-plus-circle" style="color:#7c3aed"></i> Novo Pedido';
        document.getElementById('btnSalvarPedido').textContent = ' Salvar Pedido';
        document.getElementById('listaItens').innerHTML = '';
        document.getElementById('semItens').style.display = '';
        document.getElementById('inputDesconto').value   = '';
        document.getElementById('inputObservacoes').value = '';
        document.getElementById('selectPagamento').value = 'pix';
        document.getElementById('checkEntrega').checked  = false;
        document.getElementById('camposEntrega').style.display = 'none';
        document.getElementById('infoPix').style.display = '';
        itemCount = 0;
        // Selecionar tipo estabelecimento por padrão
        document.querySelector('input[name="tipo_destinatario"][value="estabelecimento"]').checked = true;
        toggleTipoDestinatario({value:'estabelecimento'});
        adicionarItem();
    }
    document.getElementById('modalPedido').classList.add('active');
}

function fecharModalPedido() {
    document.getElementById('modalPedido').classList.remove('active');
}

document.getElementById('modalPedido').addEventListener('click', function(e) {
    if (e.target === this) fecharModalPedido();
});

// ── Carregar pedido para edição ───────────────────────────────────────────
function carregarEdicao(pedidoId) {
    // Redirecionar para a mesma página com ?editar=ID para carregar os dados via PHP
    window.location.href = 'estoque_pedidos.php?editar=' + pedidoId;
}

// ── Preencher modal com dados do pedido para edição ───────────────────────
function preencherModalEdicao(pedido, itens) {
    document.getElementById('formPedidoAction').value = 'editar_pedido';
    document.getElementById('formPedidoId').value     = pedido.id;
    document.getElementById('modalPedidoTitulo').innerHTML = '<i class="fas fa-edit" style="color:#f59e0b"></i> Editar Pedido ' + pedido.numero_pedido;
    document.getElementById('btnSalvarPedido').innerHTML   = '<i class="fas fa-save"></i> Atualizar Pedido';

    // Tipo destinatário
    const tipoRadio = document.querySelector('input[name="tipo_destinatario"][value="' + pedido.tipo_destinatario + '"]');
    if (tipoRadio) {
        tipoRadio.checked = true;
        toggleTipoDestinatario(tipoRadio);
    }

    // Estabelecimento ou cliente
    if (pedido.tipo_destinatario === 'estabelecimento') {
        document.getElementById('selectEstabelecimento').value = pedido.estabelecimento_id || '';
    } else {
        document.getElementById('inputClienteNome').value  = pedido.cliente_nome  || '';
        document.getElementById('inputClienteCpf').value   = pedido.cliente_cpf_cnpj || '';
        document.getElementById('inputClienteEmail').value = pedido.cliente_email || '';
        document.getElementById('inputClienteTel').value   = pedido.cliente_telefone || '';
    }

    // Pagamento
    document.getElementById('selectPagamento').value = pedido.pagamento || 'pix';
    atualizarInfoPix();

    // Entrega
    const cbEntrega = document.getElementById('checkEntrega');
    cbEntrega.checked = pedido.entrega == 1;
    toggleEntrega(cbEntrega);
    if (pedido.entrega == 1) {
        document.getElementById('entrega_cep').value         = pedido.entrega_cep         || '';
        document.getElementById('entrega_logradouro').value  = pedido.entrega_logradouro  || '';
        document.getElementById('entrega_numero').value      = pedido.entrega_numero      || '';
        document.getElementById('entrega_complemento').value = pedido.entrega_complemento || '';
        document.getElementById('entrega_bairro').value      = pedido.entrega_bairro      || '';
        document.getElementById('entrega_cidade').value      = pedido.entrega_cidade      || '';
        document.getElementById('entrega_estado').value      = pedido.entrega_estado      || '';
        document.getElementById('entrega_taxa').value        = formatMoney(parseFloat(pedido.entrega_taxa || 0));
    }

    // Desconto e observações
    document.getElementById('inputDesconto').value    = pedido.desconto > 0 ? formatMoney(parseFloat(pedido.desconto)) : '';
    document.getElementById('inputObservacoes').value = pedido.observacoes || '';

    // Itens
    document.getElementById('listaItens').innerHTML = '';
    document.getElementById('semItens').style.display = 'none';
    itemCount = 0;
    itens.forEach(item => {
        adicionarItemComDados(item.produto_id, item.quantidade, item.preco_unitario, item.observacoes || '');
    });

    recalcularTotal();
    abrirModalPedido(true);
}

// ── Toggle tipo destinatário ──────────────────────────────────────────────
function toggleTipoDestinatario(radio) {
    const isEstab = radio.value === 'estabelecimento';
    document.getElementById('campoEstabelecimento').style.display  = isEstab ? '' : 'none';
    document.getElementById('camposClienteFinal').style.display    = isEstab ? 'none' : '';
    document.getElementById('labelEstab').style.borderColor    = isEstab ? 'var(--primary-color)' : 'var(--gray-300)';
    document.getElementById('labelCliente').style.borderColor  = isEstab ? 'var(--gray-300)' : 'var(--success-color)';
}

// ── Toggle entrega ────────────────────────────────────────────────────────
function toggleEntrega(cb) {
    document.getElementById('camposEntrega').style.display = cb.checked ? '' : 'none';
    recalcularTotal();
}

// ── Info PIX ──────────────────────────────────────────────────────────────
function atualizarInfoPix() {
    const pag = document.getElementById('selectPagamento').value;
    document.getElementById('infoPix').style.display = pag === 'pix' ? '' : 'none';
}

// ── Buscar CEP via ViaCEP ─────────────────────────────────────────────────
function buscarCEP(input) {
    const cep = input.value.replace(/\D/g, '');
    if (cep.length !== 8) return;
    fetch(`https://viacep.com.br/ws/${cep}/json/`)
        .then(r => r.json())
        .then(d => {
            if (!d.erro) {
                document.getElementById('entrega_logradouro').value = d.logradouro || '';
                document.getElementById('entrega_bairro').value     = d.bairro     || '';
                document.getElementById('entrega_cidade').value     = d.localidade || '';
                document.getElementById('entrega_estado').value     = d.uf         || '';
            }
        }).catch(() => {});
}

// ── Adicionar item ao pedido ──────────────────────────────────────────────
function adicionarItem() {
    adicionarItemComDados('', 1, '', '');
}

function adicionarItemComDados(produtoId, quantidade, preco, obs) {
    const idx = itemCount++;
    document.getElementById('semItens').style.display = 'none';

    const opts = PRODUTOS.map(p =>
        `<option value="${p.id}" data-preco="${p.preco_venda}" data-estoque="${p.estoque_atual}" ${p.id == produtoId ? 'selected' : ''}>
            ${p.nome} (${p.codigo}) — Estoque: ${p.estoque_atual}
        </option>`
    ).join('');

    const precoFmt = preco ? formatMoney(parseFloat(preco)) : '';

    const html = `
    <div class="item-row" id="item_${idx}">
        <button type="button" class="btn-remove" onclick="removerItem(${idx})">×</button>
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label form-label-sm">Produto</label>
                <select name="item_produto_id[]" class="form-control form-control-sm" onchange="preencherPreco(this, ${idx})" required>
                    <option value="">Selecione o produto...</option>
                    ${opts}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Quantidade</label>
                <input type="number" name="item_quantidade[]" id="item_qty_${idx}" class="form-control form-control-sm"
                       value="${quantidade || 1}" min="1" oninput="recalcularItemSubtotal(${idx});recalcularTotal()">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Preço Unit. (R$)</label>
                <input type="text" name="item_preco[]" id="item_preco_${idx}" class="form-control form-control-sm money-mask"
                       value="${precoFmt}" placeholder="0,00" oninput="recalcularItemSubtotal(${idx});recalcularTotal()">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Subtotal</label>
                <input type="text" id="item_sub_${idx}" class="form-control form-control-sm" readonly
                       style="background:#f0f7ff;font-weight:600;" value="R$ 0,00">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm">Obs</label>
                <input type="text" name="item_obs[]" class="form-control form-control-sm" value="${obs || ''}" placeholder="...">
            </div>
        </div>
    </div>`;
    document.getElementById('listaItens').insertAdjacentHTML('beforeend', html);
    aplicarMascaras();
    if (preco) {
        recalcularItemSubtotal(idx);
        recalcularTotal();
    }
}

function removerItem(idx) {
    const el = document.getElementById('item_' + idx);
    if (el) el.remove();
    if (document.querySelectorAll('.item-row').length === 0) {
        document.getElementById('semItens').style.display = '';
    }
    recalcularTotal();
}

function preencherPreco(select, idx) {
    const opt = select.options[select.selectedIndex];
    const preco = parseFloat(opt.dataset.preco || 0);
    document.getElementById('item_preco_' + idx).value = formatMoney(preco);
    recalcularItemSubtotal(idx);
    recalcularTotal();
}

function recalcularItemSubtotal(idx) {
    const qty   = parseInt(document.getElementById('item_qty_' + idx)?.value || 0);
    const preco = parseMoney(document.getElementById('item_preco_' + idx)?.value || '0');
    const sub   = qty * preco;
    const el    = document.getElementById('item_sub_' + idx);
    if (el) el.value = 'R$ ' + sub.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// ── Recalcular totais ─────────────────────────────────────────────────────
function recalcularTotal() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const idx   = row.id.replace('item_', '');
        const qty   = parseInt(document.getElementById('item_qty_' + idx)?.value || 0);
        const preco = parseMoney(document.getElementById('item_preco_' + idx)?.value || '0');
        subtotal += qty * preco;
    });

    const entregaEl  = document.getElementById('entrega_taxa');
    const descontoEl = document.getElementById('inputDesconto');
    const cbEntrega  = document.getElementById('checkEntrega');

    const entrega  = cbEntrega && cbEntrega.checked ? parseMoney(entregaEl?.value || '0') : 0;
    const desconto = parseMoney(descontoEl?.value || '0');
    const total    = subtotal + entrega - desconto;

    const fmt = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    document.getElementById('resumoSubtotal').textContent = fmt(subtotal);
    document.getElementById('resumoEntrega').textContent  = fmt(entrega);
    document.getElementById('resumoDesconto').textContent = '- ' + fmt(desconto);
    document.getElementById('resumoTotal').textContent    = fmt(total);
}

// ── Utilitários ───────────────────────────────────────────────────────────
function parseMoney(str) {
    return parseFloat(String(str).replace(/\./g, '').replace(',', '.')) || 0;
}

function formatMoney(v) {
    return v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function aplicarMascaras() {
    document.querySelectorAll('.money-mask').forEach(el => {
        if (el.dataset.masked) return;
        el.dataset.masked = '1';
        el.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '');
            if (!v) { this.value = ''; return; }
            v = (parseInt(v, 10) / 100).toFixed(2);
            this.value = v.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        });
    });
}
aplicarMascaras();

// Fechar modal com ESC
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        fecharModalPedido();
        const mv = document.getElementById('modalVer');
        if (mv) mv.classList.remove('active');
    }
});

// Auto-abrir modal de visualização se vier de redirect
<?php if ($pedido_ver): ?>
window.addEventListener('load', () => {
    document.getElementById('modalVer')?.classList.add('active');
});
<?php endif; ?>

// Auto-abrir modal de edição se vier com ?editar=
<?php if ($pedido_editar): ?>
window.addEventListener('load', () => {
    preencherModalEdicao(PEDIDO_EDITAR, ITENS_EDITAR);
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
