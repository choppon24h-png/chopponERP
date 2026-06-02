<?php
/**
 * ESTOQUE - RELATÓRIOS
 * Aba 4: Relatórios e análises de estoque
 *
 * MULTI-TENANT: todas as queries filtram por estabelecimento_id.
 *               Admin Geral vê todos (com filtro opcional via GET).
 */

$page_title   = 'Estoque - Relatórios';
$current_page = 'estoque_relatorios';

require_once '../includes/config.php';
require_once '../includes/auth.php';

requireAuth();

$conn = getDBConnection();

// ── Multi-tenant ──────────────────────────────────────────────────────────────
$user_estab_id   = isAdminGeral() ? null : getEstabelecimentoId();
$filtro_estab_id = null;
if (isAdminGeral() && !empty($_GET['estab'])) {
    $filtro_estab_id = (int)$_GET['estab'];
}
// estab_id efetivo para queries: franqueado usa o seu; admin usa filtro ou null (todos)
$estab_eff = $user_estab_id ?? $filtro_estab_id;

// Lista de estabelecimentos para o select (apenas admin)
$estabelecimentos_lista = [];
if (isAdminGeral()) {
    $stmt_estabs = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos_lista = $stmt_estabs->fetchAll();
}

// Tipo de relatório
$tipo_relatorio = $_GET['tipo'] ?? 'movimentacoes';

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-chart-bar"></i> Gestão de Estoque - Relatórios</h1>
            <p class="text-muted">Análises e relatórios detalhados</p>
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
        <a href="estoque_visao.php" class="tab-link"><i class="fas fa-warehouse"></i> Estoque</a>
        <a href="estoque_movimentacoes.php" class="tab-link"><i class="fas fa-exchange-alt"></i> Movimentações</a>
        <a href="estoque_relatorios.php" class="tab-link active"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="estoque_inventario.php" class="tab-link"><i class="fas fa-archive"></i> Inventário</a>
        <a href="estoque_pedidos.php" class="tab-link"><i class="fas fa-shopping-bag"></i> Pedidos</a>
    </div>

    <!-- Filtro de Estabelecimento (Admin Geral) + Seletor de Relatório -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" id="formFiltrosRelatorio">
                <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo_relatorio) ?>">
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
                    <div class="filter-item" style="flex:3;min-width:300px;">
                        <label class="filter-label">Tipo de Relatório</label>
                        <div class="d-flex gap-2" style="flex-wrap:wrap;">
                            <a href="?tipo=movimentacoes<?= $filtro_estab_id ? '&estab='.$filtro_estab_id : '' ?>"
                               class="btn btn-sm btn-outline-primary <?= $tipo_relatorio == 'movimentacoes' ? 'active' : '' ?>">
                                <i class="fas fa-exchange-alt"></i> Movimentações
                            </a>
                            <a href="?tipo=estoque_critico<?= $filtro_estab_id ? '&estab='.$filtro_estab_id : '' ?>"
                               class="btn btn-sm btn-outline-warning <?= $tipo_relatorio == 'estoque_critico' ? 'active' : '' ?>">
                                <i class="fas fa-exclamation-triangle"></i> Estoque Crítico
                            </a>
                            <a href="?tipo=historico_precos<?= $filtro_estab_id ? '&estab='.$filtro_estab_id : '' ?>"
                               class="btn btn-sm btn-outline-info <?= $tipo_relatorio == 'historico_precos' ? 'active' : '' ?>">
                                <i class="fas fa-chart-line"></i> Histórico de Preços
                            </a>
                            <a href="?tipo=giro_estoque<?= $filtro_estab_id ? '&estab='.$filtro_estab_id : '' ?>"
                               class="btn btn-sm btn-outline-success <?= $tipo_relatorio == 'giro_estoque' ? 'active' : '' ?>">
                                <i class="fas fa-sync"></i> Giro de Estoque
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tipo_relatorio == 'movimentacoes'): ?>
    <!-- ══════════════════════════════════════════════════════════════
         RELATÓRIO: Movimentações
    ══════════════════════════════════════════════════════════════ -->
    <?php
    $where  = ["1=1"];
    $params = [];

    // ── Isolamento multi-tenant ──────────────────────────────────
    if ($estab_eff) {
        $where[]  = "p.estabelecimento_id = ?";
        $params[] = $estab_eff;
    }

    if (!empty($_GET['data_inicio'])) {
        $where[]  = "DATE(m.created_at) >= ?";
        $params[] = $_GET['data_inicio'];
    }
    if (!empty($_GET['data_fim'])) {
        $where[]  = "DATE(m.created_at) <= ?";
        $params[] = $_GET['data_fim'];
    }
    if (!empty($_GET['tipo_mov'])) {
        $where[]  = "m.tipo = ?";
        $params[] = $_GET['tipo_mov'];
    }

    $sql = "
        SELECT m.*, p.nome as produto_nome, p.codigo,
               e.name as estabelecimento_nome,
               u.name as usuario_nome
        FROM estoque_movimentacoes m
        INNER JOIN estoque_produtos p ON m.produto_id = p.id
        LEFT  JOIN estabelecimentos e ON p.estabelecimento_id = e.id
        INNER JOIN users u ON m.usuario_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.created_at DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $movimentacoes = $stmt->fetchAll();

    $total_entradas = 0; $total_saidas = 0;
    $valor_entradas = 0; $valor_saidas = 0;
    foreach ($movimentacoes as $m) {
        if ($m['tipo'] == 'entrada') { $total_entradas += $m['quantidade']; $valor_entradas += ($m['valor_total'] ?? 0); }
        elseif ($m['tipo'] == 'saida') { $total_saidas += $m['quantidade']; $valor_saidas += ($m['valor_total'] ?? 0); }
    }
    ?>

    <div class="card mb-3">
        <div class="card-header"><h3>Filtros</h3></div>
        <div class="card-body">
            <form method="GET" id="formFiltrosMov">
                <input type="hidden" name="tipo" value="movimentacoes">
                <?php if ($filtro_estab_id): ?><input type="hidden" name="estab" value="<?= $filtro_estab_id ?>"><?php endif; ?>
                <div class="filter-grid">
                    <div class="filter-item">
                        <label class="filter-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?= $_GET['data_inicio'] ?? '' ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?= $_GET['data_fim'] ?? '' ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Tipo</label>
                        <select name="tipo_mov" class="form-control">
                            <option value="">Todos</option>
                            <option value="entrada" <?= ($_GET['tipo_mov'] ?? '') == 'entrada' ? 'selected' : '' ?>>Entrada</option>
                            <option value="saida"   <?= ($_GET['tipo_mov'] ?? '') == 'saida'   ? 'selected' : '' ?>>Saída</option>
                            <option value="ajuste"  <?= ($_GET['tipo_mov'] ?? '') == 'ajuste'  ? 'selected' : '' ?>>Ajuste</option>
                        </select>
                    </div>
                    <div class="filter-item filter-item-btn">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card bg-success text-white"><div class="card-body">
                <h5>Total Entradas</h5><h3><?= $total_entradas ?> un</h3>
                <small>R$ <?= number_format($valor_entradas, 2, ',', '.') ?></small>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white"><div class="card-body">
                <h5>Total Saídas</h5><h3><?= $total_saidas ?> un</h3>
                <small>R$ <?= number_format($valor_saidas, 2, ',', '.') ?></small>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white"><div class="card-body">
                <h5>Saldo</h5><h3><?= $total_entradas - $total_saidas ?> un</h3>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white"><div class="card-body">
                <h5>Total Movimentações</h5><h3><?= count($movimentacoes) ?></h3>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h3>Detalhamento</h3>
            <button class="btn btn-sm btn-success" onclick="exportarExcel()">
                <i class="fas fa-file-excel"></i> Exportar Excel
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="tabelaRelatorio">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tipo</th>
                            <th>Produto</th>
                            <?php if (isAdminGeral() && !$estab_eff): ?><th>Estabelecimento</th><?php endif; ?>
                            <th>Qtd</th>
                            <th>Custo Unit.</th>
                            <th>Valor Total</th>
                            <th>Usuário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimentacoes as $m): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                            <td>
                                <span class="badge <?= $m['tipo'] == 'entrada' ? 'bg-success' : ($m['tipo'] == 'saida' ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                    <?= ucfirst($m['tipo']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($m['produto_nome']) ?></td>
                            <?php if (isAdminGeral() && !$estab_eff): ?>
                            <td><small><?= htmlspecialchars($m['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td><?= $m['quantidade'] ?></td>
                            <td><?= $m['custo_unitario'] ? 'R$ ' . number_format($m['custo_unitario'], 2, ',', '.') : '-' ?></td>
                            <td><?= $m['valor_total'] ? 'R$ ' . number_format($m['valor_total'], 2, ',', '.') : '-' ?></td>
                            <td><?= htmlspecialchars($m['usuario_nome']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($movimentacoes)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">Nenhuma movimentação encontrada</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($tipo_relatorio == 'estoque_critico'): ?>
    <!-- ══════════════════════════════════════════════════════════════
         RELATÓRIO: Estoque Crítico
    ══════════════════════════════════════════════════════════════ -->
    <?php
    $where_crit  = ["p.estoque_atual <= p.estoque_minimo", "p.ativo = 1"];
    $params_crit = [];

    // ── Isolamento multi-tenant ──────────────────────────────────
    if ($estab_eff) {
        $where_crit[] = "p.estabelecimento_id = ?";
        $params_crit[] = $estab_eff;
    }

    $stmt = $conn->prepare("
        SELECT p.*, f.nome as fornecedor_nome,
               e.name as estabelecimento_nome,
               (p.estoque_minimo - p.estoque_atual) as quantidade_repor,
               (p.estoque_minimo - p.estoque_atual) * p.custo_compra as valor_repor
        FROM estoque_produtos p
        LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
        LEFT JOIN estabelecimentos e ON p.estabelecimento_id = e.id
        WHERE " . implode(' AND ', $where_crit) . "
        ORDER BY p.estoque_atual ASC
    ");
    $stmt->execute($params_crit);
    $produtos_criticos_list = $stmt->fetchAll();
    $total_investimento = array_sum(array_column($produtos_criticos_list, 'valor_repor'));
    ?>

    <div class="alert alert-warning">
        <h5><i class="fas fa-exclamation-triangle"></i> Produtos com Estoque Crítico</h5>
        <p>Total de produtos: <strong><?= count($produtos_criticos_list) ?></strong></p>
        <p>Investimento necessário para reposição: <strong>R$ <?= number_format($total_investimento, 2, ',', '.') ?></strong></p>
    </div>

    <div class="card">
        <div class="card-header"><h3>Lista de Produtos Críticos</h3></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <?php if (isAdminGeral() && !$estab_eff): ?><th>Estabelecimento</th><?php endif; ?>
                            <th>Fornecedor</th>
                            <th>Estoque Atual</th>
                            <th>Estoque Mínimo</th>
                            <th>Qtd. a Repor</th>
                            <th>Custo Unit.</th>
                            <th>Valor Reposição</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produtos_criticos_list as $p): ?>
                        <tr class="<?= $p['estoque_atual'] == 0 ? 'table-danger' : 'table-warning' ?>">
                            <td>
                                <strong><?= htmlspecialchars($p['nome']) ?></strong><br>
                                <small><?= htmlspecialchars($p['codigo']) ?></small>
                            </td>
                            <?php if (isAdminGeral() && !$estab_eff): ?>
                            <td><small><?= htmlspecialchars($p['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($p['fornecedor_nome'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $p['estoque_atual'] == 0 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td><?= $p['estoque_minimo'] ?> un</td>
                            <td><strong><?= $p['quantidade_repor'] ?> un</strong></td>
                            <td>R$ <?= number_format($p['custo_compra'], 2, ',', '.') ?></td>
                            <td><strong>R$ <?= number_format($p['valor_repor'], 2, ',', '.') ?></strong></td>
                            <td>
                                <a href="estoque_movimentacoes.php?produto_id=<?= $p['id'] ?>&tipo=entrada"
                                   class="btn btn-sm btn-success">
                                    <i class="fas fa-plus"></i> Repor
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($produtos_criticos_list)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">Nenhum produto crítico encontrado</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($tipo_relatorio == 'historico_precos'): ?>
    <!-- ══════════════════════════════════════════════════════════════
         RELATÓRIO: Histórico de Preços
    ══════════════════════════════════════════════════════════════ -->
    <?php
    $where_hp  = ["1=1"];
    $params_hp = [];

    // ── Isolamento multi-tenant ──────────────────────────────────
    if ($estab_eff) {
        $where_hp[]  = "p.estabelecimento_id = ?";
        $params_hp[] = $estab_eff;
    }

    $stmt = $conn->prepare("
        SELECT h.*, p.nome as produto_nome, p.codigo,
               e.name as estabelecimento_nome,
               u.name as usuario_nome
        FROM estoque_historico_precos h
        INNER JOIN estoque_produtos p ON h.produto_id = p.id
        LEFT  JOIN estabelecimentos e ON p.estabelecimento_id = e.id
        INNER JOIN users u ON h.usuario_id = u.id
        WHERE " . implode(' AND ', $where_hp) . "
        ORDER BY h.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params_hp);
    $historico = $stmt->fetchAll();
    ?>

    <div class="card">
        <div class="card-header"><h3>Histórico de Alterações de Preços</h3></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Produto</th>
                            <?php if (isAdminGeral() && !$estab_eff): ?><th>Estabelecimento</th><?php endif; ?>
                            <th>Custo Anterior</th>
                            <th>Custo Novo</th>
                            <th>Variação</th>
                            <th>Markup Anterior</th>
                            <th>Markup Novo</th>
                            <th>Usuário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($h['produto_nome']) ?></strong><br>
                                <small><?= htmlspecialchars($h['codigo']) ?></small>
                            </td>
                            <?php if (isAdminGeral() && !$estab_eff): ?>
                            <td><small><?= htmlspecialchars($h['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td>R$ <?= number_format($h['custo_anterior'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($h['custo_novo'], 2, ',', '.') ?></td>
                            <td>
                                <span class="badge <?= $h['variacao_percentual'] > 0 ? 'bg-danger' : 'bg-success' ?>">
                                    <?= $h['variacao_percentual'] > 0 ? '+' : '' ?><?= number_format($h['variacao_percentual'], 2) ?>%
                                </span>
                            </td>
                            <td><?= number_format($h['markup_anterior'], 2) ?>%</td>
                            <td><?= number_format($h['markup_novo'], 2) ?>%</td>
                            <td><?= htmlspecialchars($h['usuario_nome']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($historico)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">Nenhum histórico encontrado</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($tipo_relatorio == 'giro_estoque'): ?>
    <!-- ══════════════════════════════════════════════════════════════
         RELATÓRIO: Giro de Estoque
    ══════════════════════════════════════════════════════════════ -->
    <?php
    $where_giro  = ["p.ativo = 1"];
    $params_giro = [];

    // ── Isolamento multi-tenant ──────────────────────────────────
    if ($estab_eff) {
        $where_giro[]  = "p.estabelecimento_id = ?";
        $params_giro[] = $estab_eff;
    }

    $stmt = $conn->prepare("
        SELECT p.id, p.nome, p.codigo, p.estoque_atual,
               e.name as estabelecimento_nome,
               SUM(CASE WHEN m.tipo = 'saida' THEN m.quantidade ELSE 0 END) as total_saidas_30d,
               COUNT(DISTINCT DATE(m.created_at)) as dias_com_movimento
        FROM estoque_produtos p
        LEFT JOIN estabelecimentos e ON p.estabelecimento_id = e.id
        LEFT JOIN estoque_movimentacoes m ON p.id = m.produto_id
            AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        WHERE " . implode(' AND ', $where_giro) . "
        GROUP BY p.id
        HAVING total_saidas_30d > 0
        ORDER BY total_saidas_30d DESC
    ");
    $stmt->execute($params_giro);
    $giro = $stmt->fetchAll();
    ?>

    <div class="alert alert-info">
        <h5><i class="fas fa-info-circle"></i> Giro de Estoque (Últimos 30 dias)</h5>
        <p>Análise de movimentação de produtos no período</p>
    </div>

    <div class="card">
        <div class="card-header"><h3>Produtos Mais Movimentados</h3></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Posição</th>
                            <th>Produto</th>
                            <?php if (isAdminGeral() && !$estab_eff): ?><th>Estabelecimento</th><?php endif; ?>
                            <th>Estoque Atual</th>
                            <th>Saídas (30d)</th>
                            <th>Dias com Movimento</th>
                            <th>Média Diária</th>
                            <th>Giro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $posicao = 1;
                        foreach ($giro as $g):
                            $media_diaria = $g['dias_com_movimento'] > 0 ? $g['total_saidas_30d'] / $g['dias_com_movimento'] : 0;
                            $giro_est     = $g['estoque_atual'] > 0 ? $g['total_saidas_30d'] / $g['estoque_atual'] : 0;
                        ?>
                        <tr>
                            <td><strong>#<?= $posicao++ ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($g['nome']) ?></strong><br>
                                <small><?= htmlspecialchars($g['codigo']) ?></small>
                            </td>
                            <?php if (isAdminGeral() && !$estab_eff): ?>
                            <td><small><?= htmlspecialchars($g['estabelecimento_nome'] ?? '-') ?></small></td>
                            <?php endif; ?>
                            <td><?= $g['estoque_atual'] ?> un</td>
                            <td><strong><?= $g['total_saidas_30d'] ?> un</strong></td>
                            <td><?= $g['dias_com_movimento'] ?> dias</td>
                            <td><?= number_format($media_diaria, 1) ?> un/dia</td>
                            <td>
                                <span class="badge <?= $giro_est >= 2 ? 'bg-success' : ($giro_est >= 1 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                    <?= number_format($giro_est, 2) ?>x
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($giro)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">Nenhuma movimentação nos últimos 30 dias</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
/* tabs-navigation, tab-link: definidos em assets/css/style.css */
</style>

<script>
function exportarExcel() {
    alert('Funcionalidade de exportação será implementada');
}
</script>

<?php require_once '../includes/footer.php'; ?>
