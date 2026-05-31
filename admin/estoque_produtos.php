<?php
/**
 * ESTOQUE - CADASTRO DE PRODUTOS
 * Suporte multi-estabelecimento: cada unidade vê apenas seus próprios produtos
 */

$page_title = 'Estoque - Produtos';
$current_page = 'estoque_produtos';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/EstoqueManager.php';

requireAuth();

$conn = getDBConnection();

// ── Determinar estabelecimento do usuário ──────────────────────────────────
$user_estab_id = isAdminGeral() ? null : getEstabelecimentoId();
$estoqueManager = new EstoqueManager($conn, $user_estab_id);

// Filtro de estabelecimento para Admin Geral
$filtro_estab_id = null;
if (isAdminGeral() && !empty($_GET['estab'])) {
    $filtro_estab_id = (int)$_GET['estab'];
}

// Buscar lista de estabelecimentos para o filtro (apenas admin)
$estabelecimentos_lista = [];
if (isAdminGeral()) {
    $stmt_estabs = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 'ativo' ORDER BY name");
    $estabelecimentos_lista = $stmt_estabs->fetchAll();
}

$success = '';
$error = '';

// ── Processar ações ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'criar') {
        // Para franqueado, força o estabelecimento_id da sessão
        if (!isAdminGeral()) {
            $_POST['estabelecimento_id'] = $user_estab_id;
        }
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

// ── Buscar produtos (filtro por estabelecimento aplicado automaticamente) ──
$filtros_get = $_GET;
if ($filtro_estab_id) {
    $filtros_get['estabelecimento_id'] = $filtro_estab_id;
}
$produtos = $estoqueManager->listarProdutos($filtros_get);

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
        <a href="estoque_produtos.php" class="tab-link active"><i class="fas fa-box"></i> Cadastro</a>
        <a href="estoque_visao.php" class="tab-link"><i class="fas fa-warehouse"></i> Estoque</a>
        <a href="estoque_movimentacoes.php" class="tab-link"><i class="fas fa-exchange-alt"></i> Movimentações</a>
        <a href="estoque_relatorios.php" class="tab-link"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="estoque_inventario.php" class="tab-link"><i class="fas fa-archive"></i> Inventário</a>
        <a href="estoque_pedidos.php" class="tab-link"><i class="fas fa-shopping-bag"></i> Pedidos</a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Botão Novo Produto -->
    <div class="mb-3 d-flex gap-2 align-items-center">
        <button class="btn btn-primary" onclick="abrirModalProduto()">
            <i class="fas fa-plus"></i> Novo Produto
        </button>
        <a href="fornecedores.php" class="btn btn-secondary">
            <i class="fas fa-truck"></i> Gerenciar Fornecedores
        </a>
        <?php if (!isAdminGeral()): ?>
        <span class="badge badge-info" style="font-size:0.85rem; padding:6px 12px;">
            <i class="fas fa-building"></i>
            <?php
            $stmt_nome = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ?");
            $stmt_nome->execute([$user_estab_id]);
            $nome_estab = $stmt_nome->fetchColumn();
            echo htmlspecialchars($nome_estab ?: 'Meu Estabelecimento');
            ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <?php if (isAdminGeral()): ?>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-building"></i> Estabelecimento</label>
                    <select name="estab" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os estabelecimentos</option>
                        <?php foreach ($estabelecimentos_lista as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filtro_estab_id == $e['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?= isAdminGeral() ? '3' : '4' ?>">
                    <label class="form-label">Busca</label>
                    <input type="text" name="busca" class="form-control"
                           placeholder="Nome, código ou código de barras"
                           value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fornecedor</label>
                    <select name="fornecedor_id" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedores as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= ($_GET['fornecedor_id'] ?? '') == $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <option value="Pilsen" <?= ($_GET['categoria'] ?? '') == 'Pilsen' ? 'selected' : '' ?>>Pilsen</option>
                        <option value="IPA" <?= ($_GET['categoria'] ?? '') == 'IPA' ? 'selected' : '' ?>>IPA</option>
                        <option value="Lager" <?= ($_GET['categoria'] ?? '') == 'Lager' ? 'selected' : '' ?>>Lager</option>
                        <option value="Stout" <?= ($_GET['categoria'] ?? '') == 'Stout' ? 'selected' : '' ?>>Stout</option>
                        <option value="Weiss" <?= ($_GET['categoria'] ?? '') == 'Weiss' ? 'selected' : '' ?>>Weiss</option>
                        <option value="Artesanal" <?= ($_GET['categoria'] ?? '') == 'Artesanal' ? 'selected' : '' ?>>Artesanal</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
                <?php if (!empty($_GET['busca']) || !empty($_GET['fornecedor_id']) || !empty($_GET['categoria']) || !empty($_GET['estab'])): ?>
                <div class="col-md-1">
                    <a href="estoque_produtos.php" class="btn btn-outline-secondary w-100" title="Limpar filtros">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Tabela de Produtos -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list"></i> Produtos cadastrados</span>
            <span class="badge badge-primary"><?= count($produtos) ?> produto(s)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Tamanho</th>
                            <th>Fornecedor</th>
                            <?php if (isAdminGeral() && !$filtro_estab_id): ?>
                            <th>Estabelecimento</th>
                            <?php endif; ?>
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
                            <td colspan="11" class="text-center text-muted py-4">
                                <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
                                Nenhum produto encontrado
                                <?php if ($filtro_estab_id || !empty($_GET['busca'])): ?>
                                <br><a href="estoque_produtos.php" class="btn btn-sm btn-outline-secondary mt-2">Limpar filtros</a>
                                <?php endif; ?>
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
                            <?php if (isAdminGeral() && !$filtro_estab_id): ?>
                            <td>
                                <small class="text-muted">
                                    <i class="fas fa-building"></i>
                                    <?= htmlspecialchars($p['estabelecimento_nome'] ?? '-') ?>
                                </small>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span class="badge <?= $p['estoque_atual'] <= $p['estoque_minimo'] ? 'badge-danger' : 'badge-success' ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                            </td>
                            <td>R$ <?= number_format($p['custo_compra'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?></td>
                            <td>
                                <?php if ($p['markup_livre']): ?>
                                <span class="badge badge-info" title="Markup Livre">
                                    <?= number_format($p['markup_percentual'], 1) ?>%
                                </span>
                                <?php else: ?>
                                <?= number_format($p['markup_percentual'], 1) ?>%
                                <?php endif; ?>
                            </td>
                            <td>R$ <?= number_format($p['preco_100ml'], 2, ',', '.') ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-icon btn-warning" onclick='editarProduto(<?= json_encode($p) ?>)' title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-icon btn-info" onclick="verDetalhes(<?= $p['id'] ?>)" title="Detalhes">
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
<div id="modalProduto" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:900px;">
        <div class="modal-box-header">
            <h3 id="modalProdutoTitle"><i class="fas fa-box"></i> Novo Produto</h3>
            <button type="button" class="btn-close-modal" onclick="fecharModalProduto()">×</button>
        </div>
        <form method="POST" id="formProduto">
            <input type="hidden" name="action" id="produto_action" value="criar">
            <input type="hidden" name="id" id="produto_id">
            <?php if (!isAdminGeral()): ?>
            <input type="hidden" name="estabelecimento_id" value="<?= $user_estab_id ?>">
            <?php endif; ?>

            <div class="modal-box-body">
                <?php if (isAdminGeral()): ?>
                <div class="form-section">
                    <div class="form-section-title">Estabelecimento</div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label required">Estabelecimento *</label>
                            <select name="estabelecimento_id" id="produto_estab" class="form-control" required>
                                <option value="">Selecione o estabelecimento</option>
                                <?php foreach ($estabelecimentos_lista as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <div class="form-section-title">Informações Básicas</div>
                    <div class="form-row">
                        <div class="form-group" style="flex:2;">
                            <label class="form-label required">Nome do Produto *</label>
                            <input type="text" name="nome" id="produto_nome" class="form-control" required placeholder="Ex: Barril Pilsen 30L">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Código de Barras</label>
                            <input type="text" name="codigo_barras" id="produto_codigo_barras" class="form-control" placeholder="EAN-13">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="produto_descricao" class="form-control" rows="2" placeholder="Descrição opcional"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Características</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Tamanho (Litros) *</label>
                            <select name="tamanho_litros" id="produto_tamanho" class="form-control" required onchange="calcularPreco100ml()">
                                <option value="">Selecione</option>
                                <option value="5">5 Litros</option>
                                <option value="10">10 Litros</option>
                                <option value="20">20 Litros</option>
                                <option value="30">30 Litros</option>
                                <option value="50">50 Litros</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Peso (Kg)</label>
                            <input type="number" name="peso_kg" id="produto_peso" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Categoria</label>
                            <select name="categoria" id="produto_categoria" class="form-control">
                                <option value="">Selecione</option>
                                <option value="Pilsen">Pilsen</option>
                                <option value="IPA">IPA</option>
                                <option value="Lager">Lager</option>
                                <option value="Stout">Stout</option>
                                <option value="Weiss">Weiss</option>
                                <option value="Artesanal">Artesanal</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fornecedor</label>
                            <select name="fornecedor_id" id="produto_fornecedor" class="form-control">
                                <option value="">Selecione</option>
                                <?php foreach ($fornecedores as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Lote</label>
                            <input type="text" name="lote" id="produto_lote" class="form-control" placeholder="Nº do lote">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Validade</label>
                            <input type="date" name="data_validade" id="produto_validade" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estoque Mínimo</label>
                            <input type="number" name="estoque_minimo" id="produto_estoque_min" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estoque Máximo</label>
                            <input type="number" name="estoque_maximo" id="produto_estoque_max" class="form-control" value="0" min="0">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Preços e Markup</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Custo de Compra *</label>
                            <input type="number" name="custo_compra" id="produto_custo" class="form-control"
                                   step="0.01" min="0" required onchange="calcularMarkup()" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Preço de Venda *</label>
                            <input type="number" name="preco_venda" id="produto_preco_venda" class="form-control"
                                   step="0.01" min="0" required onchange="calcularMarkup()" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Markup %
                                <label style="font-weight:normal; margin-left:8px;">
                                    <input type="checkbox" name="markup_livre" id="produto_markup_livre" onchange="toggleMarkupLivre()">
                                    Livre
                                </label>
                            </label>
                            <input type="number" name="markup_percentual" id="produto_markup" class="form-control"
                                   step="0.01" min="0" readonly onchange="calcularPrecoVendaPorMarkup()" placeholder="Auto">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Preço por 100ml</label>
                            <input type="text" id="produto_preco_100ml" class="form-control" readonly placeholder="Calculado auto">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalProduto()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar Produto
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalProduto() {
    document.getElementById('modalProduto').style.display = 'flex';
    document.getElementById('modalProdutoTitle').innerHTML = '<i class="fas fa-plus"></i> Novo Produto';
    document.getElementById('formProduto').reset();
    document.getElementById('produto_action').value = 'criar';
    document.getElementById('produto_id').value = '';
    document.getElementById('produto_markup').readOnly = true;
}

function fecharModalProduto() {
    document.getElementById('modalProduto').style.display = 'none';
}

function editarProduto(produto) {
    document.getElementById('modalProduto').style.display = 'flex';
    document.getElementById('modalProdutoTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Produto';
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
    document.getElementById('produto_preco_100ml').value = 'R$ ' + parseFloat(produto.preco_100ml || 0).toFixed(2);

    // Setar estabelecimento no select (admin)
    const estabSelect = document.getElementById('produto_estab');
    if (estabSelect) estabSelect.value = produto.estabelecimento_id || '';

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
        const preco100ml = (precoVenda / (tamanhoLitros * 1000)) * 100;
        document.getElementById('produto_preco_100ml').value = 'R$ ' + preco100ml.toFixed(2);
    }
}

// Fechar modal ao clicar fora
document.getElementById('modalProduto').addEventListener('click', function(e) {
    if (e.target === this) fecharModalProduto();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fecharModalProduto();
});
</script>

<?php require_once '../includes/footer.php'; ?>
