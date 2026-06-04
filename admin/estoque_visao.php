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
if (!empty($_GET['busca']))          $filtros_visao['busca']          = $_GET['busca'];
if ($filtro_estab_id)                $filtros_visao['estabelecimento_id'] = $filtro_estab_id;

// Buscar todos os produtos (sem filtro de status para calcular totalizadores corretos)
$todos_produtos = $estoqueManager->listarProdutos($filtros_visao);

// ── Totalizadores ─────────────────────────────────────────────────────────────
$total_produtos         = count($todos_produtos);
$total_estoque_unidades = 0;
$total_valor_custo      = 0;
$total_valor_venda      = 0;
$produtos_criticos      = 0;
$produtos_zerados       = 0;

// Para o card "maior estoque" e "estoque mínimo"
$maior_estoque_produto  = null;
$maior_estoque_qtd      = -1;
$menor_minimo_produto   = null;
$menor_minimo_qtd       = PHP_INT_MAX;

foreach ($todos_produtos as $p) {
    $total_estoque_unidades += $p['estoque_atual'];
    $total_valor_custo      += ($p['estoque_atual'] * $p['custo_compra']);
    $total_valor_venda      += ($p['estoque_atual'] * $p['preco_venda']);

    if ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0) {
        $produtos_criticos++;
    }
    if ($p['estoque_atual'] == 0) {
        $produtos_zerados++;
    }

    // Produto com maior quantidade em estoque
    if ($p['estoque_atual'] > $maior_estoque_qtd) {
        $maior_estoque_qtd     = $p['estoque_atual'];
        $maior_estoque_produto = $p;
    }

    // Produto com menor estoque mínimo definido (e que tenha estoque_minimo > 0)
    if ($p['estoque_minimo'] > 0 && $p['estoque_minimo'] < $menor_minimo_qtd) {
        $menor_minimo_qtd     = $p['estoque_minimo'];
        $menor_minimo_produto = $p;
    }
}

$lucro_potencial = $total_valor_venda - $total_valor_custo;

// Buscar alertas ativos — filtrado por estabelecimento
if ($user_estab_id) {
    $stmt_al = $conn->prepare("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0 AND estabelecimento_id = ?");
    $stmt_al->execute([$user_estab_id]);
} elseif ($filtro_estab_id) {
    $stmt_al = $conn->prepare("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0 AND estabelecimento_id = ?");
    $stmt_al->execute([$filtro_estab_id]);
} else {
    $stmt_al = $conn->query("SELECT COUNT(*) as total FROM estoque_alertas WHERE lido = 0");
}
$alertas_ativos = $stmt_al->fetch()['total'];

// ── Filtro de status para a tabela (aplicado após totalizadores) ──────────────
$status_filtro = $_GET['status_estoque'] ?? '';
$produtos_tabela = [];
foreach ($todos_produtos as $p) {
    if ($status_filtro === 'critico'  && !($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0)) continue;
    if ($status_filtro === 'zerado'   && $p['estoque_atual'] != 0) continue;
    if ($status_filtro === 'normal'   && ($p['estoque_atual'] == 0 || ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0))) continue;
    $produtos_tabela[] = $p;
}

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

    <!-- ── Linha 1: 4 stat-cards principais ─────────────────────────────── -->
    <div class="stats-grid">
        <!-- Card 1: Total de barris em estoque -->
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-boxes"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?= $total_estoque_unidades ?></div>
                <div class="stat-label">Barris em Estoque</div>
                <small class="text-muted"><?= $total_produtos ?> produto(s)</small>
            </div>
        </div>

        <!-- Card 2: Marca com maior quantidade em estoque -->
        <div class="stat-card">
            <div class="stat-icon bg-success"><i class="fas fa-trophy"></i></div>
            <div class="stat-info">
                <?php if ($maior_estoque_produto): ?>
                <div class="stat-number"><?= $maior_estoque_qtd ?> un</div>
                <div class="stat-label">Maior Estoque</div>
                <small class="text-muted" title="<?= htmlspecialchars($maior_estoque_produto['nome']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($maior_estoque_produto['nome'], 0, 22, '…')) ?>
                </small>
                <?php else: ?>
                <div class="stat-number">—</div>
                <div class="stat-label">Maior Estoque</div>
                <small class="text-muted">Sem produtos</small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 3: Produto com estoque mínimo mais crítico -->
        <div class="stat-card">
            <div class="stat-icon bg-warning"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info">
                <?php if ($menor_minimo_produto): ?>
                <div class="stat-number"><?= $produtos_criticos ?> crítico(s)</div>
                <div class="stat-label">Estoque Mínimo</div>
                <small class="text-muted" title="<?= htmlspecialchars($menor_minimo_produto['nome']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($menor_minimo_produto['nome'], 0, 22, '…')) ?>
                    (mín: <?= $menor_minimo_produto['estoque_minimo'] ?>)
                </small>
                <?php else: ?>
                <div class="stat-number"><?= $produtos_criticos ?></div>
                <div class="stat-label">Estoque Mínimo</div>
                <small class="text-muted">Nenhum crítico</small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 4: Produtos zerados -->
        <div class="stat-card">
            <div class="stat-icon bg-danger"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?= $produtos_zerados ?></div>
                <div class="stat-label">Produtos Zerados</div>
                <small class="text-muted">Sem estoque</small>
            </div>
        </div>
    </div>

    <!-- ── Linha 2: 3 cards de valor ──────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-info"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-info">
                <div class="stat-number" style="font-size:18px;">R$ <?= number_format($total_valor_custo, 2, ',', '.') ?></div>
                <div class="stat-label">Valor Total (Custo)</div>
                <small class="text-muted">Investimento em estoque</small>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-number" style="font-size:18px;">R$ <?= number_format($total_valor_venda, 2, ',', '.') ?></div>
                <div class="stat-label">Valor Total (Venda)</div>
                <small class="text-muted">Potencial de venda</small>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="stat-info">
                <div class="stat-number" style="font-size:18px;">R$ <?= number_format($lucro_potencial, 2, ',', '.') ?></div>
                <div class="stat-label">Lucro Potencial</div>
                <small class="text-muted">Margem total</small>
            </div>
        </div>
    </div>

    <?php if ($alertas_ativos > 0): ?>
    <div class="alert alert-warning">
        <i class="fas fa-bell"></i> Você tem <strong><?= $alertas_ativos ?></strong> alerta(s) de estoque não lido(s).
        <a href="estoque_relatorios.php?tipo=estoque_critico" class="btn btn-sm btn-warning ms-2">Ver Críticos</a>
    </div>
    <?php endif; ?>

    <!-- ── Filtros (padrão filter-grid) ──────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" id="formFiltros">
                <div class="filter-grid">
                    <?php if (isAdminGeral()): ?>
                    <div class="filter-item">
                        <label class="filter-label"><i class="fas fa-building"></i> Estabelecimento</label>
                        <select name="estab" class="form-control" onchange="this.form.submit()">
                            <option value="">Todos os estabelecimentos</option>
                            <?php foreach ($estabelecimentos_lista as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $filtro_estab_id == $e['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="filter-item filter-item-wide">
                        <label class="filter-label">Busca</label>
                        <input type="text" name="busca" class="form-control"
                               placeholder="Buscar produto..."
                               value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Status</label>
                        <select name="status_estoque" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="critico" <?= $status_filtro == 'critico' ? 'selected' : '' ?>>Estoque Crítico</option>
                            <option value="zerado"  <?= $status_filtro == 'zerado'  ? 'selected' : '' ?>>Estoque Zerado</option>
                            <option value="normal"  <?= $status_filtro == 'normal'  ? 'selected' : '' ?>>Estoque Normal</option>
                        </select>
                    </div>
                    <div class="filter-item filter-item-btn">
                        <label class="filter-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filtrar
                            </button>
                            <?php if (!empty($_GET['busca']) || !empty($_GET['status_estoque']) || !empty($_GET['estab'])): ?>
                            <a href="estoque_visao.php" class="btn btn-outline-secondary" title="Limpar filtros">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Tabela de Estoque ──────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="mb-0">Produtos em Estoque</h3>
            <span class="badge bg-secondary"><?= count($produtos_tabela) ?> produto(s)</span>
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
                            <th>Mín / Máx</th>
                            <th>Status</th>
                            <th>Custo Unit.</th>
                            <th>Preço Venda</th>
                            <th>Valor Total</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produtos_tabela)): ?>
                        <tr>
                            <td colspan="<?= isAdminGeral() ? '10' : '9' ?>" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                Nenhum produto encontrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($produtos_tabela as $p):
                            $valor_total_venda  = $p['estoque_atual'] * $p['preco_venda'];
                            $valor_total_custo  = $p['estoque_atual'] * $p['custo_compra'];
                            $percentual_estoque = ($p['estoque_maximo'] > 0)
                                ? min(($p['estoque_atual'] / $p['estoque_maximo']) * 100, 100)
                                : 0;
                            $row_class = '';
                            if ($p['estoque_atual'] == 0) $row_class = 'table-danger';
                            elseif ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0) $row_class = 'table-warning';
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td>
                                <strong><?= htmlspecialchars($p['nome']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($p['codigo']) ?></small>
                            </td>
                            <?php if (isAdminGeral()): ?>
                            <td><small><?= htmlspecialchars($p['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td><?= number_format($p['tamanho_litros'], 1) ?>L</td>
                            <td>
                                <span class="badge <?= $p['estoque_atual'] == 0 ? 'bg-danger' : ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0 ? 'bg-warning' : 'bg-success') ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td>
                                <small><?= $p['estoque_minimo'] ?> / <?= $p['estoque_maximo'] ?></small>
                                <?php if ($p['estoque_maximo'] > 0): ?>
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill <?= $percentual_estoque < 30 ? 'danger' : ($percentual_estoque < 60 ? 'warning' : 'success') ?>"
                                         style="width: <?= $percentual_estoque ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['estoque_atual'] == 0): ?>
                                    <span class="badge bg-danger">Zerado</span>
                                <?php elseif ($p['estoque_atual'] <= $p['estoque_minimo'] && $p['estoque_minimo'] > 0): ?>
                                    <span class="badge bg-warning">Crítico</span>
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
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-primary" onclick="verHistorico(<?= $p['id'] ?>)" title="Histórico">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <a href="estoque_movimentacoes.php?produto_id=<?= $p['id'] ?>" class="btn btn-sm btn-success" title="Movimentar">
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
/* Progress bar inline */
.progress-bar-wrap {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    margin-top: 4px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s;
}
.progress-bar-fill.success { background: var(--success-color); }
.progress-bar-fill.warning { background: var(--warning-color); }
.progress-bar-fill.danger  { background: var(--danger-color); }
/* stat-info extras */
.stat-info small { display: block; font-size: 11px; margin-top: 2px; }
</style>

<script>
function verHistorico(produtoId) {
    window.location.href = 'estoque_relatorios.php?produto_id=' + produtoId + '&tipo=historico_precos';
}
</script>

<?php require_once '../includes/footer.php'; ?>
