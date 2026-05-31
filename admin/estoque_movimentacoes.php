<?php
/**
 * ESTOQUE - MOVIMENTAÇÕES
 * Aba 3: Entrada, Saída e Ajustes de Estoque
 */

$page_title = 'Estoque - Movimentações';
$current_page = 'estoque_movimentacoes';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/EstoqueManager.php';

requireAuth();

$conn           = getDBConnection();
$user_estab_id  = isAdminGeral() ? null : getEstabelecimentoId();
$estoqueManager = new EstoqueManager($conn, $user_estab_id);

$success = '';
$error = '';
$alerta_markup = null;

// Processar movimentação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'movimentar') {
    $resultado = $estoqueManager->registrarMovimentacao($_POST);
    
    if ($resultado['success']) {
        $success = $resultado['message'];
        
        // Verificar se houve alteração de markup
        if ($resultado['markup_alterado']) {
            $alerta_markup = [
                'markup_anterior' => $resultado['markup_anterior'],
                'markup_novo' => $resultado['markup_novo'],
                'quantidade_anterior' => $resultado['quantidade_anterior'],
                'quantidade_nova' => $resultado['quantidade_nova']
            ];
        }
    } else {
        $error = $resultado['message'];
    }
}

// Buscar movimentações recentes
$filtros = [];
$where = ["1=1"];
$params = [];

// ── Filtro obrigatório por estabelecimento ────────────────────────────────────
if (!isAdminGeral()) {
    // Franqueado: vê apenas movimentações dos produtos do seu estabelecimento
    $where[] = "p.estabelecimento_id = ?";
    $params[] = $user_estab_id;
} elseif (!empty($_GET['estab'])) {
    $where[] = "p.estabelecimento_id = ?";
    $params[] = (int)$_GET['estab'];
}

if (!empty($_GET['produto_id'])) {
    $where[] = "m.produto_id = ?";
    $params[] = $_GET['produto_id'];
}

if (!empty($_GET['tipo'])) {
    $where[] = "m.tipo = ?";
    $params[] = $_GET['tipo'];
}

if (!empty($_GET['data_inicio'])) {
    $where[] = "DATE(m.created_at) >= ?";
    $params[] = $_GET['data_inicio'];
}

if (!empty($_GET['data_fim'])) {
    $where[] = "DATE(m.created_at) <= ?";
    $params[] = $_GET['data_fim'];
}

$sql = "
    SELECT m.*, p.nome as produto_nome, p.codigo as produto_codigo,
           u.name as usuario_nome, f.nome as fornecedor_nome
    FROM estoque_movimentacoes m
    INNER JOIN estoque_produtos p ON m.produto_id = p.id
    INNER JOIN users u ON m.usuario_id = u.id
    LEFT JOIN fornecedores f ON m.fornecedor_id = f.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.created_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$movimentacoes = $stmt->fetchAll();

// Buscar produtos para dropdown
$produtos = $estoqueManager->listarProdutos();

// Buscar fornecedores
$stmt = $conn->query("SELECT id, nome FROM fornecedores WHERE ativo = 1 ORDER BY nome");
$fornecedores = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="fas fa-exchange-alt"></i> Gestão de Estoque - Movimentações</h1>
        <p class="text-muted">Registro de entradas, saídas e ajustes</p>
    </div>

    <!-- Abas de Navegação -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php" class="tab-link">
            <i class="fas fa-box"></i> Cadastro
        </a>
        <a href="estoque_visao.php" class="tab-link">
            <i class="fas fa-warehouse"></i> Estoque
        </a>
        <a href="estoque_movimentacoes.php" class="tab-link active">
            <i class="fas fa-exchange-alt"></i> Movimentações
        </a>
        <a href="estoque_relatorios.php" class="tab-link">
            <i class="fas fa-chart-bar"></i> Relatórios
        </a>
        <a href="estoque_inventario.php" class="tab-link">
            <i class="fas fa-archive"></i> Inventário
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($alerta_markup): ?>
    <div class="alert alert-warning alert-dismissible">
        <h5><i class="fas fa-exclamation-triangle"></i> Alteração de Markup Detectada</h5>
        <p>
            O custo do produto foi alterado, resultando em mudança no markup:<br>
            <strong>Markup Anterior:</strong> <?= number_format($alerta_markup['markup_anterior'], 2) ?>%<br>
            <strong>Markup Novo:</strong> <?= number_format($alerta_markup['markup_novo'], 2) ?>%<br>
            <strong>Variação:</strong> <?= number_format($alerta_markup['markup_novo'] - $alerta_markup['markup_anterior'], 2) ?>%
        </p>
        <p class="mb-0">
            <small>Considere revisar o preço de venda para manter a margem desejada.</small>
        </p>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Botões de Ação -->
    <div class="row mb-3">
        <div class="col-md-12">
            <button class="btn btn-success" onclick="abrirModalMovimentacao('entrada')">
                <i class="fas fa-arrow-down"></i> Nova Entrada
            </button>
            <button class="btn btn-danger" onclick="abrirModalMovimentacao('saida')">
                <i class="fas fa-arrow-up"></i> Nova Saída
            </button>
            <button class="btn btn-warning" onclick="abrirModalMovimentacao('ajuste')">
                <i class="fas fa-sync"></i> Ajuste de Estoque
            </button>
            <a href="estoque_pedidos.php" class="btn" style="background:#7c3aed;color:#fff;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-shopping-bag"></i> Novo Pedido
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Produto</label>
                    <select name="produto_id" class="form-select">
                        <option value="">Todos os produtos</option>
                        <?php foreach ($produtos as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($_GET['produto_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos os tipos</option>
                        <option value="entrada" <?= ($_GET['tipo'] ?? '') == 'entrada' ? 'selected' : '' ?>>Entrada</option>
                        <option value="saida" <?= ($_GET['tipo'] ?? '') == 'saida' ? 'selected' : '' ?>>Saída</option>
                        <option value="ajuste" <?= ($_GET['tipo'] ?? '') == 'ajuste' ? 'selected' : '' ?>>Ajuste</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($_GET['data_inicio'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($_GET['data_fim'] ?? '') ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de Movimentações -->
    <div class="card">
        <div class="card-header">
            <h3>Histórico de Movimentações</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Tipo</th>
                            <th>Produto</th>
                            <th>Quantidade</th>
                            <th>Estoque</th>
                            <th>Custo Unit.</th>
                            <th>Valor Total</th>
                            <th>Usuário</th>
                            <th>Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movimentacoes)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">
                                Nenhuma movimentação encontrada
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($movimentacoes as $m): ?>
                        <tr>
                            <td>
                                <?= date('d/m/Y', strtotime($m['created_at'])) ?><br>
                                <small class="text-muted"><?= date('H:i', strtotime($m['created_at'])) ?></small>
                            </td>
                            <td>
                                <?php if ($m['tipo'] == 'entrada'): ?>
                                    <span class="badge bg-success"><i class="fas fa-arrow-down"></i> Entrada</span>
                                <?php elseif ($m['tipo'] == 'saida'): ?>
                                    <span class="badge bg-danger"><i class="fas fa-arrow-up"></i> Saída</span>
                                <?php else: ?>
                                    <span class="badge bg-warning"><i class="fas fa-sync"></i> Ajuste</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($m['produto_nome']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($m['produto_codigo']) ?></small>
                            </td>
                            <td>
                                <strong><?= $m['quantidade'] ?> un</strong>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= $m['quantidade_anterior'] ?> → <?= $m['quantidade_nova'] ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($m['custo_unitario']): ?>
                                    R$ <?= number_format($m['custo_unitario'], 2, ',', '.') ?>
                                    <?php if ($m['custo_anterior'] && $m['custo_unitario'] != $m['custo_anterior']): ?>
                                    <br><small class="text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> Alterado
                                    </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m['valor_total']): ?>
                                    <strong>R$ <?= number_format($m['valor_total'], 2, ',', '.') ?></strong>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($m['usuario_nome']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick='verDetalhesMovimentacao(<?= json_encode($m) ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
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

<!-- Modal: Movimentação -->
<div id="modalMovimentacao" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalMovimentacaoTitle">Nova Movimentação</h2>
            <span class="close" onclick="fecharModalMovimentacao()">&times;</span>
        </div>
        <form method="POST" id="formMovimentacao">
            <input type="hidden" name="action" value="movimentar">
            <input type="hidden" name="tipo" id="mov_tipo">
            
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label required">Produto</label>
                        <select name="produto_id" id="mov_produto" class="form-select" required onchange="carregarDadosProduto()">
                            <option value="">Selecione um produto</option>
                            <?php foreach ($produtos as $p): ?>
                            <option value="<?= $p['id'] ?>" 
                                    data-estoque="<?= $p['estoque_atual'] ?>"
                                    data-custo="<?= $p['custo_compra'] ?>"
                                    data-markup="<?= $p['markup_percentual'] ?>">
                                <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= $p['estoque_atual'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small id="info_estoque" class="text-muted"></small>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Quantidade</label>
                        <input type="number" name="quantidade" id="mov_quantidade" class="form-control" 
                               min="1" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Custo Unitário</label>
                        <input type="number" name="custo_unitario" id="mov_custo" class="form-control" 
                               step="0.01" min="0" onchange="verificarMudancaCusto()">
                        <small class="text-muted">Deixe em branco para manter o custo atual</small>
                    </div>
                    
                    <div id="alerta_custo" class="col-md-12 mb-3" style="display: none;">
                        <div class="alert alert-warning">
                            <strong>Atenção!</strong> O custo informado é diferente do custo atual.
                            <div id="info_markup_mudanca"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Fornecedor</label>
                        <select name="fornecedor_id" id="mov_fornecedor" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach ($fornecedores as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nota Fiscal</label>
                        <input type="text" name="nota_fiscal" id="mov_nf" class="form-control">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Lote</label>
                        <input type="text" name="lote" id="mov_lote" class="form-control">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Data de Validade</label>
                        <input type="date" name="data_validade" id="mov_validade" class="form-control">
                    </div>
                    
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Motivo</label>
                        <input type="text" name="motivo" id="mov_motivo" class="form-control" 
                               placeholder="Ex: Compra de fornecedor, Venda, Perda, etc">
                    </div>
                    
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" id="mov_obs" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalMovimentacao()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary" id="btnSubmitMov">
                    <i class="fas fa-save"></i> Registrar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Detalhes da Movimentação -->
<div id="modalDetalhes" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Detalhes da Movimentação</h2>
            <span class="close" onclick="fecharModalDetalhes()">&times;</span>
        </div>
        <div class="modal-body" id="detalhesContent">
            <!-- Conteúdo será preenchido via JavaScript -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="fecharModalDetalhes()">
                Fechar
            </button>
        </div>
    </div>
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

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    overflow-y: auto;
}

.modal-content {
    background-color: #fff;
    margin: 2% auto;
    padding: 0;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
}

.modal-header {
    padding: 20px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 15px 20px;
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    text-align: right;
    border-radius: 0 0 8px 8px;
}

.close {
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
}

.close:hover {
    color: #000;
}

.required::after {
    content: ' *';
    color: red;
}
</style>

<script>
let custoAtualProduto = 0;
let markupAtualProduto = 0;

function abrirModalMovimentacao(tipo) {
    document.getElementById('modalMovimentacao').style.display = 'block';
    document.getElementById('mov_tipo').value = tipo;
    document.getElementById('formMovimentacao').reset();
    document.getElementById('mov_tipo').value = tipo;
    
    const titulos = {
        'entrada': 'Nova Entrada de Estoque',
        'saida': 'Nova Saída de Estoque',
        'ajuste': 'Ajuste de Estoque'
    };
    
    document.getElementById('modalMovimentacaoTitle').textContent = titulos[tipo];
    
    const btnSubmit = document.getElementById('btnSubmitMov');
    btnSubmit.className = 'btn btn-' + (tipo === 'entrada' ? 'success' : tipo === 'saida' ? 'danger' : 'warning');
}

function fecharModalMovimentacao() {
    document.getElementById('modalMovimentacao').style.display = 'none';
}

function carregarDadosProduto() {
    const select = document.getElementById('mov_produto');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        const estoque = option.getAttribute('data-estoque');
        const custo = option.getAttribute('data-custo');
        const markup = option.getAttribute('data-markup');
        
        custoAtualProduto = parseFloat(custo);
        markupAtualProduto = parseFloat(markup);
        
        document.getElementById('info_estoque').textContent = 'Estoque atual: ' + estoque + ' unidades';
        document.getElementById('mov_custo').value = custo;
    }
}

function verificarMudancaCusto() {
    const custoNovo = parseFloat(document.getElementById('mov_custo').value) || 0;
    const alertaDiv = document.getElementById('alerta_custo');
    const infoDiv = document.getElementById('info_markup_mudanca');
    
    if (custoNovo > 0 && custoNovo != custoAtualProduto) {
        // Calcular novo markup (mantendo preço de venda)
        // Isso seria feito no backend, aqui é só um preview
        alertaDiv.style.display = 'block';
        
        const variacao = ((custoNovo - custoAtualProduto) / custoAtualProduto) * 100;
        const sinal = variacao > 0 ? '+' : '';
        
        infoDiv.innerHTML = `
            <small>
                Custo anterior: R$ ${custoAtualProduto.toFixed(2)}<br>
                Custo novo: R$ ${custoNovo.toFixed(2)}<br>
                Variação: ${sinal}${variacao.toFixed(2)}%<br>
                <strong>O markup será recalculado automaticamente.</strong>
            </small>
        `;
    } else {
        alertaDiv.style.display = 'none';
    }
}

function verDetalhesMovimentacao(mov) {
    document.getElementById('modalDetalhes').style.display = 'block';
    
    const html = `
        <table class="table table-bordered">
            <tr><th>Data/Hora:</th><td>${mov.created_at}</td></tr>
            <tr><th>Tipo:</th><td>${mov.tipo}</td></tr>
            <tr><th>Produto:</th><td>${mov.produto_nome}</td></tr>
            <tr><th>Quantidade:</th><td>${mov.quantidade} unidades</td></tr>
            <tr><th>Estoque Anterior:</th><td>${mov.quantidade_anterior}</td></tr>
            <tr><th>Estoque Novo:</th><td>${mov.quantidade_nova}</td></tr>
            ${mov.custo_unitario ? `<tr><th>Custo Unitário:</th><td>R$ ${parseFloat(mov.custo_unitario).toFixed(2)}</td></tr>` : ''}
            ${mov.valor_total ? `<tr><th>Valor Total:</th><td>R$ ${parseFloat(mov.valor_total).toFixed(2)}</td></tr>` : ''}
            ${mov.fornecedor_nome ? `<tr><th>Fornecedor:</th><td>${mov.fornecedor_nome}</td></tr>` : ''}
            ${mov.nota_fiscal ? `<tr><th>Nota Fiscal:</th><td>${mov.nota_fiscal}</td></tr>` : ''}
            ${mov.lote ? `<tr><th>Lote:</th><td>${mov.lote}</td></tr>` : ''}
            ${mov.motivo ? `<tr><th>Motivo:</th><td>${mov.motivo}</td></tr>` : ''}
            ${mov.observacoes ? `<tr><th>Observações:</th><td>${mov.observacoes}</td></tr>` : ''}
            <tr><th>Usuário:</th><td>${mov.usuario_nome}</td></tr>
        </table>
    `;
    
    document.getElementById('detalhesContent').innerHTML = html;
}

function fecharModalDetalhes() {
    document.getElementById('modalDetalhes').style.display = 'none';
}

// Fechar modais ao clicar fora
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
