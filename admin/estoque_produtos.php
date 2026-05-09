<?php
/**
 * ESTOQUE - CADASTRO DE PRODUTOS
 * Aba 1: Cadastro e gerenciamento de barris
 */

$page_title = 'Estoque - Produtos';
$current_page = 'estoque_produtos';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/EstoqueManager.php';

requireAuth();

$conn = getDBConnection();
$estoqueManager = new EstoqueManager($conn);

$success = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'criar') {
        $resultado = $estoqueManager->criarProduto($_POST);
        if ($resultado['success']) {
            $success = $resultado['message'];
        } else {
            $error = $resultado['message'];
        }
    }
    
    if ($action === 'atualizar') {
        $resultado = $estoqueManager->atualizarProduto($_POST['id'], $_POST);
        if ($resultado['success']) {
            $success = $resultado['message'];
        } else {
            $error = $resultado['message'];
        }
    }
}

// Buscar produtos
$produtos = $estoqueManager->listarProdutos($_GET);

// Buscar fornecedores
$stmt = $conn->query("SELECT id, nome FROM fornecedores WHERE ativo = 1 ORDER BY nome");
$fornecedores = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="fas fa-boxes"></i> Gestão de Estoque - Produtos</h1>
        <p class="text-muted">Cadastro e gerenciamento de barris de chopp</p>
    </div>

    <!-- Abas de Navegação -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php" class="tab-link active">
            <i class="fas fa-box"></i> Cadastro
        </a>
        <a href="estoque_visao.php" class="tab-link">
            <i class="fas fa-warehouse"></i> Estoque
        </a>
        <a href="estoque_movimentacoes.php" class="tab-link">
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

    <!-- Botão Novo Produto -->
    <div class="mb-3">
        <button class="btn btn-primary" onclick="abrirModalProduto()">
            <i class="fas fa-plus"></i> Novo Produto
        </button>
        <a href="fornecedores.php" class="btn btn-secondary">
            <i class="fas fa-truck"></i> Gerenciar Fornecedores
        </a>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="busca" class="form-control" 
                           placeholder="Buscar por nome, código ou código de barras"
                           value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <select name="fornecedor_id" class="form-select">
                        <option value="">Todos os fornecedores</option>
                        <?php foreach ($fornecedores as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= ($_GET['fornecedor_id'] ?? '') == $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="categoria" class="form-select">
                        <option value="">Todas as categorias</option>
                        <option value="Pilsen">Pilsen</option>
                        <option value="IPA">IPA</option>
                        <option value="Lager">Lager</option>
                        <option value="Stout">Stout</option>
                        <option value="Weiss">Weiss</option>
                        <option value="Artesanal">Artesanal</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de Produtos -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Tamanho</th>
                            <th>Fornecedor</th>
                            <th>Estoque</th>
                            <th>Custo</th>
                            <th>Preço Venda</th>
                            <th>Markup</th>
                            <th>R$/100ml</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produtos)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">
                                Nenhum produto cadastrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($produtos as $p): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($p['codigo']) ?></code></td>
                            <td>
                                <strong><?= htmlspecialchars($p['nome']) ?></strong>
                                <?php if ($p['categoria']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($p['categoria']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($p['tamanho_litros'], 1) ?>L</td>
                            <td><?= htmlspecialchars($p['fornecedor_nome'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $p['estoque_atual'] <= $p['estoque_minimo'] ? 'bg-danger' : 'bg-success' ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td>R$ <?= number_format($p['custo_compra'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?></td>
                            <td>
                                <?php if ($p['markup_livre']): ?>
                                <span class="badge bg-info" title="Markup Livre">
                                    <?= number_format($p['markup_percentual'], 1) ?>%
                                </span>
                                <?php else: ?>
                                <?= number_format($p['markup_percentual'], 1) ?>%
                                <?php endif; ?>
                            </td>
                            <td>R$ <?= number_format($p['preco_100ml'], 2, ',', '.') ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-info" onclick='editarProduto(<?= json_encode($p) ?>)' title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-primary" onclick="verDetalhes(<?= $p['id'] ?>)" title="Detalhes">
                                        <i class="fas fa-eye"></i>
                                    </button>
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

<!-- Modal: Produto -->
<div id="modalProduto" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="modalProdutoTitle">Novo Produto</h2>
            <span class="close" onclick="fecharModalProduto()">&times;</span>
        </div>
        <form method="POST" id="formProduto">
            <input type="hidden" name="action" id="produto_action" value="criar">
            <input type="hidden" name="id" id="produto_id">
            
            <div class="modal-body">
                <div class="row">
                    <!-- Informações Básicas -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Nome do Produto</label>
                        <input type="text" name="nome" id="produto_nome" class="form-control" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Código de Barras</label>
                        <input type="text" name="codigo_barras" id="produto_codigo_barras" class="form-control">
                        <small class="text-muted">EAN-13 ou similar</small>
                    </div>
                    
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" id="produto_descricao" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <!-- Características -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label required">Tamanho (Litros)</label>
                        <select name="tamanho_litros" id="produto_tamanho" class="form-select" required onchange="calcularPreco100ml()">
                            <option value="">Selecione</option>
                            <option value="5">5 Litros</option>
                            <option value="10">10 Litros</option>
                            <option value="20">20 Litros</option>
                            <option value="30">30 Litros</option>
                            <option value="50">50 Litros</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Peso (Kg)</label>
                        <input type="number" name="peso_kg" id="produto_peso" class="form-control" step="0.01" min="0">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" id="produto_categoria" class="form-select">
                            <option value="">Selecione</option>
                            <option value="Pilsen">Pilsen</option>
                            <option value="IPA">IPA</option>
                            <option value="Lager">Lager</option>
                            <option value="Stout">Stout</option>
                            <option value="Weiss">Weiss</option>
                            <option value="Artesanal">Artesanal</option>
                        </select>
                    </div>
                    
                    <!-- Fornecedor -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Fornecedor</label>
                        <select name="fornecedor_id" id="produto_fornecedor" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach ($fornecedores as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Lote e Validade -->
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Lote</label>
                        <input type="text" name="lote" id="produto_lote" class="form-control">
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Validade</label>
                        <input type="date" name="data_validade" id="produto_validade" class="form-control">
                    </div>
                    
                    <!-- Estoque -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Estoque Mínimo</label>
                        <input type="number" name="estoque_minimo" id="produto_estoque_min" class="form-control" value="0" min="0">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Estoque Máximo</label>
                        <input type="number" name="estoque_maximo" id="produto_estoque_max" class="form-control" value="0" min="0">
                    </div>
                    
                    <!-- Preços -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Custo de Compra</label>
                        <input type="number" name="custo_compra" id="produto_custo" class="form-control money" 
                               step="0.01" min="0" required onchange="calcularMarkup()">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Preço de Venda</label>
                        <input type="number" name="preco_venda" id="produto_preco_venda" class="form-control money" 
                               step="0.01" min="0" required onchange="calcularMarkup()">
                    </div>
                    
                    <!-- Markup -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            <input type="checkbox" name="markup_livre" id="produto_markup_livre" onchange="toggleMarkupLivre()">
                            Markup Livre
                        </label>
                        <input type="number" name="markup_percentual" id="produto_markup" class="form-control" 
                               step="0.01" min="0" readonly onchange="calcularPrecoVendaPorMarkup()">
                        <small class="text-muted">Calculado automaticamente ou defina manualmente</small>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Preço por 100ml</label>
                        <input type="text" id="produto_preco_100ml" class="form-control" readonly>
                        <small class="text-muted">Calculado automaticamente</small>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalProduto()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar
                </button>
            </div>
        </form>
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
    max-width: 900px;
}

.modal-lg {
    max-width: 1000px;
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
function abrirModalProduto() {
    document.getElementById('modalProduto').style.display = 'block';
    document.getElementById('modalProdutoTitle').textContent = 'Novo Produto';
    document.getElementById('formProduto').reset();
    document.getElementById('produto_action').value = 'criar';
    document.getElementById('produto_id').value = '';
    document.getElementById('produto_markup').readOnly = true;
}

function fecharModalProduto() {
    document.getElementById('modalProduto').style.display = 'none';
}

function editarProduto(produto) {
    document.getElementById('modalProduto').style.display = 'block';
    document.getElementById('modalProdutoTitle').textContent = 'Editar Produto';
    document.getElementById('produto_action').value = 'atualizar';
    document.getElementById('produto_id').value = produto.id;
    document.getElementById('produto_nome').value = produto.nome;
    document.getElementById('produto_codigo_barras').value = produto.codigo_barras || '';
    document.getElementById('produto_descricao').value = produto.descricao || '';
    document.getElementById('produto_tamanho').value = produto.tamanho_litros;
    document.getElementById('produto_peso').value = produto.peso_kg || '';
    document.getElementById('produto_categoria').value = produto.categoria || '';
    document.getElementById('produto_fornecedor').value = produto.fornecedor_id || '';
    document.getElementById('produto_lote').value = produto.lote || '';
    document.getElementById('produto_validade').value = produto.data_validade || '';
    document.getElementById('produto_estoque_min').value = produto.estoque_minimo;
    document.getElementById('produto_estoque_max').value = produto.estoque_maximo;
    document.getElementById('produto_custo').value = produto.custo_compra;
    document.getElementById('produto_preco_venda').value = produto.preco_venda;
    document.getElementById('produto_markup').value = produto.markup_percentual;
    document.getElementById('produto_markup_livre').checked = produto.markup_livre == 1;
    document.getElementById('produto_preco_100ml').value = 'R$ ' + parseFloat(produto.preco_100ml).toFixed(2);
    
    toggleMarkupLivre();
}

function toggleMarkupLivre() {
    const checkbox = document.getElementById('produto_markup_livre');
    const markupInput = document.getElementById('produto_markup');
    markupInput.readOnly = !checkbox.checked;
    
    if (checkbox.checked) {
        markupInput.focus();
    } else {
        calcularMarkup();
    }
}

function calcularMarkup() {
    const custo = parseFloat(document.getElementById('produto_custo').value) || 0;
    const precoVenda = parseFloat(document.getElementById('produto_preco_venda').value) || 0;
    
    if (custo > 0 && !document.getElementById('produto_markup_livre').checked) {
        const markup = ((precoVenda - custo) / custo) * 100;
        document.getElementById('produto_markup').value = markup.toFixed(2);
    }
    
    calcularPreco100ml();
}

function calcularPrecoVendaPorMarkup() {
    if (document.getElementById('produto_markup_livre').checked) {
        const custo = parseFloat(document.getElementById('produto_custo').value) || 0;
        const markup = parseFloat(document.getElementById('produto_markup').value) || 0;
        const precoVenda = custo * (1 + (markup / 100));
        document.getElementById('produto_preco_venda').value = precoVenda.toFixed(2);
        calcularPreco100ml();
    }
}

function calcularPreco100ml() {
    const precoVenda = parseFloat(document.getElementById('produto_preco_venda').value) || 0;
    const tamanhoLitros = parseFloat(document.getElementById('produto_tamanho').value) || 0;
    
    if (tamanhoLitros > 0) {
        const precoMl = precoVenda / (tamanhoLitros * 1000);
        const preco100ml = precoMl * 100;
        document.getElementById('produto_preco_100ml').value = 'R$ ' + preco100ml.toFixed(2);
    }
}

// Fechar modal ao clicar fora
window.onclick = function(event) {
    const modal = document.getElementById('modalProduto');
    if (event.target == modal) {
        fecharModalProduto();
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
