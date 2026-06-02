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

// ── Processar ações POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'criar_pedido') {
        // Reconstruir itens do POST
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
    // Filtro por estabelecimento: franqueado sempre usa o seu; admin pode filtrar
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

require_once '../includes/header.php';
?>

<style>
/* ── Tabs de navegação ─────────────────────────────────────────────────── */
.tabs-navigation {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #ddd;
}
.tab-link {
    padding: 12px 20px;
    text-decoration: none;
    color: #666;
    border-bottom: 3px solid transparent;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    white-space: nowrap;
}
.tab-link:hover { color: #333; background-color: #f5f5f5; border-radius: 4px 4px 0 0; }
.tab-link.active { color: #007bff; border-bottom-color: #007bff; font-weight: bold; }
/* stat-cards/stat-card/stat-icon/stats-grid: definidos em assets/css/style.css */
.stat-icon.yellow { background:#fff8e1; color:#f59e0b; }
.stat-icon.blue   { background:#e3f0ff; color:#007bff; }
.stat-icon.green  { background:#e6f9ee; color:#28a745; }
.stat-icon.red    { background:#fdecea; color:#dc3545; }
.stat-icon.teal   { background:#e0f7fa; color:#17a2b8; }
/* stat-number e stat-label — valores dentro de stat-card */
.stat-number { font-size:20px; font-weight:700; line-height:1; color:var(--gray-800); }
.stat-label  { font-size:11px; color:var(--gray-600); margin-top:3px; }
/* Responsivo: 3 colunas em telas médias, 2 em mobile */
@media (max-width: 992px) {
    .stats-grid[style*="repeat(5"] { grid-template-columns: repeat(3,1fr) !important; }
}
@media (max-width: 576px) {
    .stats-grid[style*="repeat(5"] { grid-template-columns: repeat(2,1fr) !important; }
}
/* ── Status badges ─────────────────────────────────────────────────────────────────── */
.badge-aguardando  { background:#fff8e1; color:#f59e0b; }
.badge-visualizado { background:#e3f0ff; color:#007bff; }
.badge-faturado    { background:#e6f9ee; color:#28a745; }
.badge-cancelado   { background:#fdecea; color:#dc3545; }
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }

/* ── Botões de ação ────────────────────────────────────────────────────── */
.btn-pedido { padding:8px 18px; border-radius:6px; border:none; cursor:pointer; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:all .2s; text-decoration:none; }
.btn-pedido.purple { background:#7c3aed; color:#fff; }
.btn-pedido.purple:hover { background:#6d28d9; }

/* ── Modal ─────────────────────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1050; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-box { background:#fff; border-radius:var(--border-radius); width:96%; max-width:860px; max-height:92vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.25); }
.modal-header { padding:16px 20px; background:#f8f9fa; border-bottom:1px solid var(--gray-300); display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:10; border-radius:var(--border-radius) var(--border-radius) 0 0; }
.modal-header h4 { margin:0; font-size:16px; }
.modal-body { padding:20px; }
.modal-footer { padding:14px 20px; background:#f8f9fa; border-top:1px solid var(--gray-300); text-align:right; border-radius:0 0 var(--border-radius) var(--border-radius); }
.btn-close-modal { background:none; border:none; font-size:24px; cursor:pointer; color:var(--gray-600); line-height:1; }

/* ── Formulário de pedido ──────────────────────────────────────────────── */
.item-row { background:#f8f9fa; border-radius:8px; padding:12px; margin-bottom:10px; position:relative; }
.item-row .btn-remove { position:absolute; top:8px; right:8px; background:#fdecea; color:#dc3545; border:none; border-radius:50%; width:26px; height:26px; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; }
.total-box { background:linear-gradient(135deg,#0066cc,#004499); color:#fff; border-radius:10px; padding:18px 22px; }
.total-box .total-line { display:flex; justify-content:space-between; padding:4px 0; font-size:14px; }
.total-box .total-final { display:flex; justify-content:space-between; padding-top:10px; margin-top:8px; border-top:1px solid rgba(255,255,255,.3); font-size:20px; font-weight:700; }

/* ── Visualização do pedido ────────────────────────────────────────────── */
.ped-section { margin-bottom:18px; }
.ped-section h6 { font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-600); margin-bottom:8px; border-bottom:1px solid var(--gray-300); padding-bottom:4px; }
.ped-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; }
.ped-info-item .label { font-size:11px; color:var(--gray-600); }
.ped-info-item .value { font-size:14px; font-weight:600; }
</style>

<div class="container-fluid">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1><i class="fas fa-shopping-bag"></i> Pedidos de Estoque</h1>
        <p class="text-muted">Pedidos de saída para estabelecimentos e clientes finais</p>
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

    <!-- Botão Novo Pedido -->
    <div style="margin-bottom:20px;">
        <button type="button" class="btn-pedido purple" onclick="abrirModalNovoPedido()">
            <i class="fas fa-plus"></i> Novo Pedido
        </button>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <input type="text" name="busca" class="form-control form-control-sm"
                           placeholder="Buscar por número, cliente, estabelecimento..."
                           value="<?= htmlspecialchars($filtros['busca']) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos os status</option>
                        <option value="aguardando"  <?= $filtros['status'] === 'aguardando'  ? 'selected' : '' ?>>Aguardando</option>
                        <option value="visualizado" <?= $filtros['status'] === 'visualizado' ? 'selected' : '' ?>>Visualizado</option>
                        <option value="faturado"    <?= $filtros['status'] === 'faturado'    ? 'selected' : '' ?>>Faturado</option>
                        <option value="cancelado"   <?= $filtros['status'] === 'cancelado'   ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrar</button>
                    <?php if (array_filter($filtros)): ?>
                    <a href="estoque_pedidos.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
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
                        <th>Itens</th>
                        <th>Entrega</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th style="width:160px;">Ações</th>
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
                    // Contar itens
                    $stmt_cnt = $conn->prepare("SELECT COUNT(*) FROM estoque_pedido_itens WHERE pedido_id = ?");
                    $stmt_cnt->execute([$ped['id']]);
                    $num_itens = $stmt_cnt->fetchColumn();
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
                        <!-- PDF -->
                        <a href="pedido_pdf.php?id=<?= $ped['id'] ?>" target="_blank"
                           class="btn btn-sm btn-secondary" title="Baixar PDF" style="padding:4px 8px;">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                        <?php if ($ped['status'] !== 'faturado' && $ped['status'] !== 'cancelado'): ?>
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
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fas fa-eye"></i> Pedido <?= htmlspecialchars($pedido_ver['numero_pedido']) ?></h4>
            <button class="btn-close-modal" onclick="document.getElementById('modalVer').classList.remove('active')">×</button>
        </div>
        <div class="modal-body">

            <!-- Cabeçalho do pedido (visual do PDF) -->
            <div style="background:linear-gradient(135deg,#0066cc,#004499);color:#fff;border-radius:10px;padding:20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <img src="<?= SITE_URL ?>/assets/images/logo.png" alt="Choppon" style="height:50px;background:#fff;border-radius:6px;padding:4px;">
                    <div>
                        <div style="font-size:20px;font-weight:700;">Chopp On Tap</div>
                        <div style="font-size:12px;opacity:.8;">Sistema de Gestão ERP</div>
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
                    <?php else: ?>
                    <div class="ped-info-item"><div class="label">Cliente</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_nome'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">CPF/CNPJ</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_cpf_cnpj'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">E-mail</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_email'] ?? '—') ?></div></div>
                    <div class="ped-info-item"><div class="label">Telefone</div><div class="value"><?= htmlspecialchars($pedido_ver['cliente_telefone'] ?? '—') ?></div></div>
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
        <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <a href="pedido_pdf.php?id=<?= $pedido_ver['id'] ?>" target="_blank" class="btn btn-danger">
                <i class="fas fa-file-pdf"></i> Baixar PDF
            </a>
            <?php if ($pedido_ver['status'] !== 'faturado' && $pedido_ver['status'] !== 'cancelado'): ?>
            <form method="POST" onsubmit="return confirm('Faturar e dar baixa no estoque?');">
                <input type="hidden" name="action" value="faturar">
                <input type="hidden" name="pedido_id" value="<?= $pedido_ver['id'] ?>">
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Faturar</button>
            </form>
            <?php endif; ?>
            <a href="estoque_pedidos.php" class="btn btn-secondary">Fechar</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal: Novo Pedido ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalNovoPedido">
    <div class="modal-box" style="max-width:900px;" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h4><i class="fas fa-plus-circle" style="color:#7c3aed"></i> Novo Pedido</h4>
            <button class="btn-close-modal" onclick="fecharModalNovoPedido()">×</button>
        </div>
        <form method="POST" id="formNovoPedido">
            <input type="hidden" name="action" value="criar_pedido">
            <div class="modal-body">
                <div class="row g-3">

                    <!-- Tipo de destinatário -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Tipo de Destinatário</label>
                        <div style="display:flex;gap:16px;">
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
                        <select name="estabelecimento_id" class="form-select">
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
                                <input type="text" name="cliente_nome" class="form-control" placeholder="Nome completo">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">CPF / CNPJ</label>
                                <input type="text" name="cliente_cpf_cnpj" class="form-control" placeholder="000.000.000-00">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="cliente_email" class="form-control" placeholder="email@exemplo.com">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="cliente_telefone" class="form-control" placeholder="(00) 00000-0000">
                            </div>
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
                                        <input type="text" name="entrega_cep" class="form-control form-control-sm" placeholder="00000-000" onblur="buscarCEP(this)">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Logradouro</label>
                                        <input type="text" name="entrega_logradouro" id="entrega_logradouro" class="form-control form-control-sm" placeholder="Rua, Av...">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Nº</label>
                                        <input type="text" name="entrega_numero" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Complemento</label>
                                        <input type="text" name="entrega_complemento" class="form-control form-control-sm" placeholder="Apto, Sala...">
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
                        <textarea name="observacoes" class="form-control" rows="2" placeholder="Observações do pedido..."></textarea>
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
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalNovoPedido()">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed;">
                    <i class="fas fa-save"></i> Salvar Pedido
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Dados de produtos para JS -->
<script>
const PRODUTOS = <?= json_encode(array_values($produtos), JSON_UNESCAPED_UNICODE) ?>;
let itemCount = 0;

// ── Abrir/fechar modal Novo Pedido ────────────────────────────────────────
function abrirModalNovoPedido() {
    document.getElementById('modalNovoPedido').classList.add('active');
    if (itemCount === 0) adicionarItem();
}
function fecharModalNovoPedido() {
    document.getElementById('modalNovoPedido').classList.remove('active');
}
document.getElementById('modalNovoPedido').addEventListener('click', function(e) {
    if (e.target === this) fecharModalNovoPedido();
});

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
    const idx = itemCount++;
    document.getElementById('semItens').style.display = 'none';

    const opts = PRODUTOS.map(p =>
        `<option value="${p.id}" data-preco="${p.preco_venda}" data-estoque="${p.estoque_atual}">
            ${p.nome} (${p.codigo}) — Estoque: ${p.estoque_atual}
        </option>`
    ).join('');

    const html = `
    <div class="item-row" id="item_${idx}">
        <button type="button" class="btn-remove" onclick="removerItem(${idx})">×</button>
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label form-label-sm">Produto</label>
                <select name="item_produto_id[]" class="form-select form-select-sm" onchange="preencherPreco(this, ${idx})" required>
                    <option value="">Selecione o produto...</option>
                    ${opts}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Quantidade</label>
                <input type="number" name="item_quantidade[]" id="item_qty_${idx}" class="form-control form-control-sm"
                       value="1" min="1" oninput="recalcularItemSubtotal(${idx});recalcularTotal()">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Preço Unit. (R$)</label>
                <input type="text" name="item_preco[]" id="item_preco_${idx}" class="form-control form-control-sm money-mask"
                       placeholder="0,00" oninput="recalcularItemSubtotal(${idx});recalcularTotal()">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">Subtotal</label>
                <input type="text" id="item_sub_${idx}" class="form-control form-control-sm" readonly
                       style="background:#f0f7ff;font-weight:600;" value="R$ 0,00">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm">Obs</label>
                <input type="text" name="item_obs[]" class="form-control form-control-sm" placeholder="...">
            </div>
        </div>
    </div>`;
    document.getElementById('listaItens').insertAdjacentHTML('beforeend', html);
    aplicarMascaras();
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
    const precoFmt = preco.toFixed(2).replace('.', ',');
    document.getElementById('item_preco_' + idx).value = precoFmt;
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
    document.querySelectorAll('.item-row').forEach((row, i) => {
        const rows = document.querySelectorAll('.item-row');
        const idx  = row.id.replace('item_', '');
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
    if (e.key === 'Escape') fecharModalNovoPedido();
});

// Auto-abrir modal de visualização se vier de redirect
<?php if ($pedido_ver): ?>
window.addEventListener('load', () => {
    document.getElementById('modalVer')?.classList.add('active');
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
