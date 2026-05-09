<?php
/**
 * ESTOQUE - RELATÓRIOS
 * Aba 4: Relatórios e análises de estoque
 */

$page_title = 'Estoque - Relatórios';
$current_page = 'estoque_relatorios';

require_once '../includes/config.php';
require_once '../includes/auth.php';

requireAuth();

$conn = getDBConnection();

// Tipo de relatório
$tipo_relatorio = $_GET['tipo'] ?? 'movimentacoes';

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="fas fa-chart-bar"></i> Gestão de Estoque - Relatórios</h1>
        <p class="text-muted">Análises e relatórios detalhados</p>
    </div>

    <!-- Abas de Navegação -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php" class="tab-link">
            <i class="fas fa-box"></i> Cadastro
        </a>
        <a href="estoque_visao.php" class="tab-link">
            <i class="fas fa-warehouse"></i> Estoque
        </a>
        <a href="estoque_movimentacoes.php" class="tab-link">
            <i class="fas fa-exchange-alt"></i> Movimentações
        </a>
        <a href="estoque_relatorios.php" class="tab-link active">
            <i class="fas fa-chart-bar"></i> Relatórios
        </a>
        <a href="estoque_inventario.php" class="tab-link">
            <i class="fas fa-archive"></i> Inventário
        </a>
    </div>

    <!-- Seletor de Relatório -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-12">
                    <label class="form-label"><strong>Selecione o tipo de relatório:</strong></label>
                    <div class="btn-group w-100" role="group">
                        <a href="?tipo=movimentacoes" class="btn btn-outline-primary <?= $tipo_relatorio == 'movimentacoes' ? 'active' : '' ?>">
                            <i class="fas fa-exchange-alt"></i> Movimentações
                        </a>
                        <a href="?tipo=estoque_critico" class="btn btn-outline-warning <?= $tipo_relatorio == 'estoque_critico' ? 'active' : '' ?>">
                            <i class="fas fa-exclamation-triangle"></i> Estoque Crítico
                        </a>
                        <a href="?tipo=historico_precos" class="btn btn-outline-info <?= $tipo_relatorio == 'historico_precos' ? 'active' : '' ?>">
                            <i class="fas fa-chart-line"></i> Histórico de Preços
                        </a>
                        <a href="?tipo=giro_estoque" class="btn btn-outline-success <?= $tipo_relatorio == 'giro_estoque' ? 'active' : '' ?>">
                            <i class="fas fa-sync"></i> Giro de Estoque
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($tipo_relatorio == 'movimentacoes'): ?>
    <!-- RELATÓRIO: Movimentações -->
    <?php
    $where = ["1=1"];
    $params = [];
    
    if (!empty($_GET['data_inicio'])) {
        $where[] = "DATE(m.created_at) >= ?";
        $params[] = $_GET['data_inicio'];
    }
    
    if (!empty($_GET['data_fim'])) {
        $where[] = "DATE(m.created_at) <= ?";
        $params[] = $_GET['data_fim'];
    }
    
    if (!empty($_GET['tipo_mov'])) {
        $where[] = "m.tipo = ?";
        $params[] = $_GET['tipo_mov'];
    }
    
    $sql = "
        SELECT m.*, p.nome as produto_nome, p.codigo,
               u.name as usuario_nome
        FROM estoque_movimentacoes m
        INNER JOIN estoque_produtos p ON m.produto_id = p.id
        INNER JOIN users u ON m.usuario_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.created_at DESC
        LIMIT 500
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $movimentacoes = $stmt->fetchAll();
    
    // Totalizadores
    $total_entradas = 0;
    $total_saidas = 0;
    $valor_entradas = 0;
    $valor_saidas = 0;
    
    foreach ($movimentacoes as $m) {
        if ($m['tipo'] == 'entrada') {
            $total_entradas += $m['quantidade'];
            $valor_entradas += ($m['valor_total'] ?? 0);
        } elseif ($m['tipo'] == 'saida') {
            $total_saidas += $m['quantidade'];
            $valor_saidas += ($m['valor_total'] ?? 0);
        }
    }
    ?>
    
    <div class="card mb-3">
        <div class="card-header">
            <h3>Filtros</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tipo" value="movimentacoes">
                <div class="col-md-3">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?= $_GET['data_inicio'] ?? '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?= $_GET['data_fim'] ?? '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo_mov" class="form-select">
                        <option value="">Todos</option>
                        <option value="entrada">Entrada</option>
                        <option value="saida">Saída</option>
                        <option value="ajuste">Ajuste</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5>Total Entradas</h5>
                    <h3><?= $total_entradas ?> un</h3>
                    <small>R$ <?= number_format($valor_entradas, 2, ',', '.') ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5>Total Saídas</h5>
                    <h3><?= $total_saidas ?> un</h3>
                    <small>R$ <?= number_format($valor_saidas, 2, ',', '.') ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5>Saldo</h5>
                    <h3><?= $total_entradas - $total_saidas ?> un</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5>Total Movimentações</h5>
                    <h3><?= count($movimentacoes) ?></h3>
                </div>
            </div>
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
                            <td><?= ucfirst($m['tipo']) ?></td>
                            <td><?= htmlspecialchars($m['produto_nome']) ?></td>
                            <td><?= $m['quantidade'] ?></td>
                            <td><?= $m['custo_unitario'] ? 'R$ ' . number_format($m['custo_unitario'], 2, ',', '.') : '-' ?></td>
                            <td><?= $m['valor_total'] ? 'R$ ' . number_format($m['valor_total'], 2, ',', '.') : '-' ?></td>
                            <td><?= htmlspecialchars($m['usuario_nome']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tipo_relatorio == 'estoque_critico'): ?>
    <!-- RELATÓRIO: Estoque Crítico -->
    <?php
    $stmt = $conn->query("
        SELECT p.*, f.nome as fornecedor_nome,
               (p.estoque_minimo - p.estoque_atual) as quantidade_repor,
               (p.estoque_minimo - p.estoque_atual) * p.custo_compra as valor_repor
        FROM estoque_produtos p
        LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
        WHERE p.estoque_atual <= p.estoque_minimo
          AND p.ativo = 1
        ORDER BY p.estoque_atual ASC
    ");
    $produtos_criticos = $stmt->fetchAll();
    
    $total_investimento = array_sum(array_column($produtos_criticos, 'valor_repor'));
    ?>
    
    <div class="alert alert-warning">
        <h5><i class="fas fa-exclamation-triangle"></i> Produtos com Estoque Crítico</h5>
        <p>Total de produtos: <strong><?= count($produtos_criticos) ?></strong></p>
        <p>Investimento necessário para reposição: <strong>R$ <?= number_format($total_investimento, 2, ',', '.') ?></strong></p>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Lista de Produtos Críticos</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Produto</th>
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
                        <?php foreach ($produtos_criticos as $p): ?>
                        <tr class="<?= $p['estoque_atual'] == 0 ? 'table-danger' : 'table-warning' ?>">
                            <td>
                                <strong><?= htmlspecialchars($p['nome']) ?></strong><br>
                                <small><?= htmlspecialchars($p['codigo']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($p['fornecedor_nome'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $p['estoque_atual'] == 0 ? 'bg-danger' : 'bg-warning' ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td><?= $p['estoque_minimo'] ?> un</td>
                            <td><strong><?= $p['quantidade_repor'] ?> un</strong></td>
                            <td>R$ <?= number_format($p['custo_compra'], 2, ',', '.') ?></td>
                            <td><strong>R$ <?= number_format($p['valor_repor'], 2, ',', '.') ?></strong></td>
                            <td>
                                <a href="estoque_movimentacoes.php?produto_id=<?= $p['id'] ?>&tipo=entrada" class="btn btn-sm btn-success">
                                    <i class="fas fa-plus"></i> Repor
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tipo_relatorio == 'historico_precos'): ?>
    <!-- RELATÓRIO: Histórico de Preços -->
    <?php
    $stmt = $conn->query("
        SELECT h.*, p.nome as produto_nome, p.codigo,
               u.name as usuario_nome
        FROM estoque_historico_precos h
        INNER JOIN estoque_produtos p ON h.produto_id = p.id
        INNER JOIN users u ON h.usuario_id = u.id
        ORDER BY h.created_at DESC
        LIMIT 200
    ");
    $historico = $stmt->fetchAll();
    ?>
    
    <div class="card">
        <div class="card-header">
            <h3>Histórico de Alterações de Preços</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Produto</th>
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tipo_relatorio == 'giro_estoque'): ?>
    <!-- RELATÓRIO: Giro de Estoque -->
    <?php
    // Calcular giro dos últimos 30 dias
    $stmt = $conn->query("
        SELECT p.id, p.nome, p.codigo, p.estoque_atual,
               SUM(CASE WHEN m.tipo = 'saida' THEN m.quantidade ELSE 0 END) as total_saidas_30d,
               COUNT(DISTINCT DATE(m.created_at)) as dias_com_movimento
        FROM estoque_produtos p
        LEFT JOIN estoque_movimentacoes m ON p.id = m.produto_id 
            AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        WHERE p.ativo = 1
        GROUP BY p.id
        HAVING total_saidas_30d > 0
        ORDER BY total_saidas_30d DESC
    ");
    $giro = $stmt->fetchAll();
    ?>
    
    <div class="alert alert-info">
        <h5><i class="fas fa-info-circle"></i> Giro de Estoque (Últimos 30 dias)</h5>
        <p>Análise de movimentação de produtos no período</p>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Produtos Mais Movimentados</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Posição</th>
                            <th>Produto</th>
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
                            $giro_estoque = $g['estoque_atual'] > 0 ? $g['total_saidas_30d'] / $g['estoque_atual'] : 0;
                        ?>
                        <tr>
                            <td><strong>#<?= $posicao++ ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($g['nome']) ?></strong><br>
                                <small><?= htmlspecialchars($g['codigo']) ?></small>
                            </td>
                            <td><?= $g['estoque_atual'] ?> un</td>
                            <td><strong><?= $g['total_saidas_30d'] ?> un</strong></td>
                            <td><?= $g['dias_com_movimento'] ?> dias</td>
                            <td><?= number_format($media_diaria, 1) ?> un/dia</td>
                            <td>
                                <span class="badge <?= $giro_estoque >= 2 ? 'bg-success' : ($giro_estoque >= 1 ? 'bg-warning' : 'bg-danger') ?>">
                                    <?= number_format($giro_estoque, 2) ?>x
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<style>
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
}

.tab-link:hover {
    color: #333;
    background-color: #f5f5f5;
}

.tab-link.active {
    color: #007bff;
    border-bottom-color: #007bff;
    font-weight: bold;
}
</style>

<script>
function exportarExcel() {
    // Implementar exportação para Excel
    alert('Funcionalidade de exportação será implementada');
}
</script>

<?php require_once '../includes/footer.php'; ?>
