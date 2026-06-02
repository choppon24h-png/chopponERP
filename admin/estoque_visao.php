<?php
/**
 * ESTOQUE - VISÃO GERAL
 * Aba 2: Visualização de estoque com totalizadores
 *
 * MULTI-TENANT: franqueado vê apenas seu estabelecimento;
 *               Admin Geral vê todos (com filtro opcional).
 */

$page_title    = 'Estoque - Visão Geral';
$current_page  = 'estoque_visao';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/EstoqueManager.php';

requireAuth();

$conn = getDBConnection();

// ── Multi-tenant ──────────────────────────────────────────────────────────────
$user_estab_id  = isAdminGeral() ? null : getEstabelecimentoId();
$estoqueManager = new EstoqueManager($conn, $user_estab_id);

// Filtro de estabelecimento para Admin Geral via GET
$filtro_estab_id = null;
if (isAdminGeral() && !empty($_GET['estab'])) {
    $filtro_estab_id = (int)$_GET['estab'];
}

// Lista de estabelecimentos para o select de filtro (apenas admin)
$estabelecimentos_lista = [];
if (isAdminGeral()) {
    $stmt_estabs = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos_lista = $stmt_estabs->fetchAll();
}

// Montar filtros para listarProdutos
$filtros_visao = [];
if (!empty($_GET['busca']))         $filtros_visao['busca']         = $_GET['busca'];
if (!empty($_GET['status_estoque'])) $filtros_visao['status_estoque'] = $_GET['status_estoque'];
if ($filtro_estab_id)               $filtros_visao['estabelecimento_id'] = $filtro_estab_id;

// Buscar produtos com estoque
$produtos = $estoqueManager->listarProdutos($filtros_visao);

// Calcular totalizadores
$total_produtos          = count($produtos);
$total_estoque_unidades  = 0;
$total_valor_custo       = 0;
$total_valor_venda       = 0;
$produtos_criticos       = 0;
$produtos_zerados        = 0;

foreach ($produtos as $p) {
    $total_estoque_unidades += $p['estoque_atual'];
    $total_valor_custo      += ($p['estoque_atual'] * $p['custo_compra']);
    $total_valor_venda      += ($p['estoque_atual'] * $p['preco_venda']);

    if ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0) {
        $produtos_criticos++;
    }
    if ($p['estoque_atual'] == 0) {
        $produtos_zerados++;
    }
}

$lucro_potencial = $total_valor_venda - $total_valor_custo;

// Buscar alertas ativos — filtrado por estabelecimento
if ($user_estab_id) {
    $stmt_al = $conn->prepare("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0 AND estabelecimento_id = ?");
    $stmt_al->execute([$user_estab_id]);
} else {
    // Admin Geral: alertas do estabelecimento filtrado ou todos
    if ($filtro_estab_id) {
        $stmt_al = $conn->prepare("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0 AND estabelecimento_id = ?");
        $stmt_al->execute([$filtro_estab_id]);
    } else {
        $stmt_al = $conn->query("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0");
    }
}
$alertas_ativos = $stmt_al->fetch()['total'];

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-warehouse"></i> Gestão de Estoque - Visão Geral</h1>
            <p class="text-muted">Acompanhamento de estoque e totalizadores</p>
        </div>
        <?php if (!isAdminGeral() && $user_estab_id): ?>
        <div class="page-header-meta">
            <?php
            $stmt_en = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ?");
            $stmt_en->execute([$user_estab_id]);
            $estab_nome = $stmt_en->fetchColumn();
            ?>
            <span class="badge bg-primary fs-6"><i class="fas fa-store"></i> <?= htmlspecialchars($estab_nome) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Abas de Navegação -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php" class="tab-link"><i class="fas fa-box"></i> Cadastro</a>
        <a href="estoque_visao.php" class="tab-link active"><i class="fas fa-warehouse"></i> Estoque</a>
        <a href="estoque_movimentacoes.php" class="tab-link"><i class="fas fa-exchange-alt"></i> Movimentações</a>
        <a href="estoque_relatorios.php" class="tab-link"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="estoque_inventario.php" class="tab-link"><i class="fas fa-archive"></i> Inventário</a>
        <a href="estoque_pedidos.php" class="tab-link"><i class="fas fa-shopping-bag"></i> Pedidos</a>
    </div>

    <!-- Cards de Totalizadores -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-stat bg-primary text-white">
                <div class="card-body">
                    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                    <h3><?= $total_produtos ?></h3>
                    <p>Produtos Cadastrados</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-success text-white">
                <div class="card-body">
                    <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                    <h3><?= $total_estoque_unidades ?></h3>
                    <p>Unidades em Estoque</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-warning text-white">
                <div class="card-body">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3><?= $produtos_criticos ?></h3>
                    <p>Produtos Críticos</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-danger text-white">
                <div class="card-body">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <h3><?= $produtos_zerados ?></h3>
                    <p>Produtos Zerados</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Cards de Valores -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card card-value">
                <div class="card-body">
                    <h5><i class="fas fa-dollar-sign text-info"></i> Valor Total (Custo)</h5>
                    <h2 class="text-info">R$ <?= number_format($total_valor_custo, 2, ',', '.') ?></h2>
                    <small class="text-muted">Investimento em estoque</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-value">
                <div class="card-body">
                    <h5><i class="fas fa-dollar-sign text-success"></i> Valor Total (Venda)</h5>
                    <h2 class="text-success">R$ <?= number_format($total_valor_venda, 2, ',', '.') ?></h2>
                    <small class="text-muted">Potencial de venda</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-value">
                <div class="card-body">
                    <h5><i class="fas fa-chart-line text-primary"></i> Lucro Potencial</h5>
                    <h2 class="text-primary">R$ <?= number_format($lucro_potencial, 2, ',', '.') ?></h2>
                    <small class="text-muted">Margem total</small>
                </div>
            </div>
        </div>
    </div>

    <?php if ($alertas_ativos > 0): ?>
    <div class="alert alert-warning">
        <i class="fas fa-bell"></i> Você tem <strong><?= $alertas_ativos ?></strong> alerta(s) de estoque não lido(s).
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <?php if (isAdminGeral()): ?>
                <div class="col-md-3">
                    <label class="form-label">Estabelecimento</label>
                    <select name="estab" class="form-select">
                        <option value="">Todos os estabelecimentos</option>
                        <?php foreach ($estabelecimentos_lista as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filtro_estab_id == $e['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?= isAdminGeral() ? '3' : '5' ?>">
                    <label class="form-label">Busca</label>
                    <input type="text" name="busca" class="form-control"
                           placeholder="Buscar produto..."
                           value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status_estoque" class="form-select">
                        <option value="">Todos os status</option>
                        <option value="critico"  <?= ($_GET['status_estoque'] ?? '') == 'critico'  ? 'selected' : '' ?>>Estoque Crítico</option>
                        <option value="zerado"   <?= ($_GET['status_estoque'] ?? '') == 'zerado'   ? 'selected' : '' ?>>Estoque Zerado</option>
                        <option value="normal"   <?= ($_GET['status_estoque'] ?? '') == 'normal'   ? 'selected' : '' ?>>Estoque Normal</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de Estoque -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="mb-0">Produtos em Estoque</h3>
            <span class="badge bg-secondary"><?= $total_produtos ?> produto(s)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <?php if (isAdminGeral()): ?><th>Estabelecimento</th><?php endif; ?>
                            <th>Tamanho</th>
                            <th>Estoque Atual</th>
                            <th>Estoque Mín/Máx</th>
                            <th>Status</th>
                            <th>Valor Custo</th>
                            <th>Valor Venda</th>
                            <th>Valor Total</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produtos)): ?>
                        <tr>
                            <td colspan="<?= isAdminGeral() ? '10' : '9' ?>" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                Nenhum produto encontrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($produtos as $p):
                            $valor_total_custo  = $p['estoque_atual'] * $p['custo_compra'];
                            $valor_total_venda  = $p['estoque_atual'] * $p['preco_venda'];
                            $percentual_estoque = 0;
                            if ($p['estoque_maximo'] > 0) {
                                $percentual_estoque = ($p['estoque_atual'] / $p['estoque_maximo']) * 100;
                            }
                            // Aplicar filtro de status_estoque no PHP (EstoqueManager não filtra por status)
                            $status_est = $_GET['status_estoque'] ?? '';
                            if ($status_est === 'critico'  && !($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0)) continue;
                            if ($status_est === 'zerado'   && $p['estoque_atual'] != 0) continue;
                            if ($status_est === 'normal'   && ($p['estoque_atual'] == 0 || ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0))) continue;
                        ?>
                        <tr class="<?= $p['estoque_atual'] == 0 ? 'table-danger' : ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0 ? 'table-warning' : '') ?>">
                            <td>
                                <strong><?= htmlspecialchars($p['nome']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($p['codigo']) ?></small>
                            </td>
                            <?php if (isAdminGeral()): ?>
                            <td><small><?= htmlspecialchars($p['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td><?= number_format($p['tamanho_litros'], 1) ?>L</td>
                            <td>
                                <span class="badge badge-lg <?= $p['estoque_atual'] == 0 ? 'bg-danger' : ($p['estoque_atual'] <= $p['estoque_minimo'] ? 'bg-warning' : 'bg-success') ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td>
                                <small>Mín: <?= $p['estoque_minimo'] ?> / Máx: <?= $p['estoque_maximo'] ?></small>
                                <?php if ($p['estoque_maximo'] > 0): ?>
                                <div class="progress mt-1" style="height: 8px;">
                                    <div class="progress-bar <?= $percentual_estoque < 30 ? 'bg-danger' : ($percentual_estoque < 60 ? 'bg-warning' : 'bg-success') ?>"
                                         style="width: <?= min($percentual_estoque, 100) ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['estoque_atual'] == 0): ?>
                                    <span class="badge bg-danger">Zerado</span>
                                <?php elseif ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0): ?>
                                    <span class="badge bg-warning text-dark">Crítico</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td>R$ <?= number_format($p['custo_compra'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?></td>
                            <td>
                                <strong>R$ <?= number_format($valor_total_venda, 2, ',', '.') ?></strong>
                                <br><small class="text-muted">Custo: R$ <?= number_format($valor_total_custo, 2, ',', '.') ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-primary" onclick="verHistorico(<?= $p['id'] ?>)" title="Histórico">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <a href="estoque_movimentacoes.php?produto_id=<?= $p['id'] ?>" class="btn btn-success" title="Movimentar">
                                        <i class="fas fa-exchange-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.card-stat {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
}
.card-stat .stat-icon {
    position: absolute;
    right: 20px;
    top: 20px;
    font-size: 48px;
    opacity: 0.3;
}
.card-stat h3 { font-size: 36px; font-weight: bold; margin: 0; }
.card-stat p  { margin: 5px 0 0 0; font-size: 14px; opacity: 0.9; }
.card-value   { border-left: 4px solid; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.card-value h2 { font-size: 28px; font-weight: bold; margin: 10px 0; }
.badge-lg     { font-size: 14px; padding: 6px 12px; }
.progress     { background-color: #e9ecef; }
.table-warning { background-color: #fff3cd !important; }
.table-danger  { background-color: #f8d7da !important; }
</style>

<script>
function verHistorico(produtoId) {
    window.location.href = 'estoque_relatorios.php?produto_id=' + produtoId + '&tipo=historico_precos';
}
</script>

<?php require_once '../includes/footer.php'; ?>
