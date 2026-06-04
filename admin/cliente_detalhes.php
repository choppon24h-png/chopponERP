<?php
$page_title = 'Detalhes do Cliente';
$current_page = 'clientes';

require_once '../includes/config.php';
require_once '../includes/auth.php';

requireAuth();

$conn = getDBConnection();
$cliente_id = intval($_GET['id'] ?? 0);

if (!$cliente_id) {
    redirect('clientes.php');
}

// Buscar dados do cliente
$stmt = $conn->prepare("
    SELECT c.*, e.name as estabelecimento_name
    FROM clientes c
    LEFT JOIN estabelecimentos e ON c.estabelecimento_id = e.id
    WHERE c.id = ?
");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    redirect('clientes.php');
}

// Verificar permissão
if (!isAdminGeral() && $cliente['estabelecimento_id'] != getEstabelecimentoId()) {
    redirect('clientes.php');
}

// Buscar histórico de consumo
$stmt = $conn->prepare("
    SELECT 
        cc.*,
        DATE_FORMAT(cc.data_consumo, '%d/%m/%Y %H:%i') as data_formatada
    FROM clientes_consumo cc
    WHERE cc.cliente_id = ?
    ORDER BY cc.data_consumo DESC
");
$stmt->execute([$cliente_id]);
$consumos = $stmt->fetchAll();

// Buscar histórico de cashback
$stmt = $conn->prepare("
    SELECT 
        ch.*,
        u.name as user_name,
        DATE_FORMAT(ch.data_operacao, '%d/%m/%Y %H:%i') as data_formatada
    FROM cashback_historico ch
    LEFT JOIN users u ON ch.user_id = u.id
    WHERE ch.cliente_id = ?
    ORDER BY ch.data_operacao DESC
    LIMIT 50
");
$stmt->execute([$cliente_id]);
$historico_cashback = $stmt->fetchAll();

// Estatísticas
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_consumos,
        SUM(valor_total) as total_gasto,
        SUM(quantidade) as total_litros,
        AVG(valor_total) as ticket_medio,
        MAX(data_consumo) as ultima_compra,
        MIN(data_consumo) as primeira_compra
    FROM clientes_consumo
    WHERE cliente_id = ?
");
$stmt->execute([$cliente_id]);
$stats = $stmt->fetch();

// Bebidas mais consumidas
$stmt = $conn->prepare("
    SELECT 
        bebida_nome,
        SUM(quantidade) as total_quantidade,
        SUM(valor_total) as total_valor,
        COUNT(*) as vezes_consumida
    FROM clientes_consumo
    WHERE cliente_id = ?
    GROUP BY bebida_nome
    ORDER BY total_quantidade DESC
    LIMIT 5
");
$stmt->execute([$cliente_id]);
$bebidas_favoritas = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="clientes.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <h1><?php echo htmlspecialchars($cliente['nome']); ?></h1>
    </div>
    <div>
        <button class="btn btn-warning" onclick="openModal('editModal')">
            <i class="fas fa-edit"></i> Editar
        </button>
        <button class="btn btn-success" onclick="openModal('addConsumoModal')">
            <i class="fas fa-plus"></i> Adicionar Consumo
        </button>
        <button class="btn btn-info" onclick="openModal('ajusteCashbackModal')">
            <i class="fas fa-coins"></i> Ajustar Cashback
        </button>
    </div>
</div>

<!-- Informações do Cliente -->
<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3>Dados Pessoais</h3>
            </div>
            <div class="card-body">
                <div class="info-item">
                    <strong>CPF:</strong>
                    <span><?php echo formatCPF($cliente['cpf']); ?></span>
                </div>
                <div class="info-item">
                    <strong>Email:</strong>
                    <span><?php echo htmlspecialchars($cliente['email']); ?></span>
                </div>
                <div class="info-item">
                    <strong>Telefone:</strong>
                    <span><?php echo htmlspecialchars($cliente['telefone']); ?></span>
                </div>
                <div class="info-item">
                    <strong>Data de Nascimento:</strong>
                    <span><?php echo $cliente['data_nascimento'] ? formatDateBR($cliente['data_nascimento']) : '-'; ?></span>
                </div>
                <div class="info-item">
                    <strong>Status:</strong>
                    <span class="badge badge-<?php echo $cliente['status'] ? 'success' : 'secondary'; ?>">
                        <?php echo $cliente['status'] ? 'Ativo' : 'Inativo'; ?>
                    </span>
                </div>
                <div class="info-item">
                    <strong>Cadastro:</strong>
                    <span><?php echo formatDateBR($cliente['created_at']); ?></span>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h3>Endereço</h3>
            </div>
            <div class="card-body">
                <?php if ($cliente['endereco_rua']): ?>
                <p>
                    <?php echo htmlspecialchars($cliente['endereco_rua']); ?>, 
                    <?php echo htmlspecialchars($cliente['endereco_numero']); ?>
                    <?php if ($cliente['endereco_complemento']): ?>
                    <br><?php echo htmlspecialchars($cliente['endereco_complemento']); ?>
                    <?php endif; ?>
                    <br><?php echo htmlspecialchars($cliente['endereco_bairro']); ?>
                    <br><?php echo htmlspecialchars($cliente['endereco_cidade']); ?> - <?php echo htmlspecialchars($cliente['endereco_estado']); ?>
                    <br>CEP: <?php echo formatCEP($cliente['endereco_cep']); ?>
                </p>
                <?php else: ?>
                <p class="text-muted">Endereço não cadastrado</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo formatMoney($cliente['pontos_cashback']); ?></h3>
                    <p>Saldo de Cashback</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo formatMoney($stats['total_gasto'] ?? 0); ?></h3>
                    <p>Total Consumido</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_consumos'] ?? 0; ?></h3>
                    <p>Total de Consumos</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-info">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo formatMoney($stats['ticket_medio'] ?? 0); ?></h3>
                    <p>Ticket Médio</p>
                </div>
            </div>
        </div>
        
        <!-- Bebidas Favoritas -->
        <?php if (!empty($bebidas_favoritas)): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h3>Bebidas Favoritas</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive"><table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Bebida</th>
                            <th>Quantidade</th>
                            <th>Valor Total</th>
                            <th>Vezes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bebidas_favoritas as $bebida): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($bebida['bebida_nome']); ?></td>
                            <td><?php echo number_format($bebida['total_quantidade'], 2); ?>L</td>
                            <td><?php echo formatMoney($bebida['total_valor']); ?></td>
                            <td><?php echo $bebida['vezes_consumida']; ?>x</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="card mt-4">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link active" href="#consumo" data-toggle="tab">
                    <i class="fas fa-history"></i> Histórico de Consumo
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#cashback" data-toggle="tab">
                    <i class="fas fa-coins"></i> Histórico de Cashback
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <!-- Tab Histórico de Consumo -->
            <div class="tab-pane fade show active" id="consumo">
                <?php if (empty($consumos)): ?>
                <p class="text-muted text-center">Nenhum consumo registrado.</p>
                <?php else: ?>
                <div class="table-responsive"><table class="table">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Bebida</th>
                            <th>Quantidade</th>
                            <th>Valor Unit.</th>
                            <th>Valor Total</th>
                            <th>Pontos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consumos as $consumo): ?>
                        <tr>
                            <td><?php echo $consumo['data_formatada']; ?></td>
                            <td><?php echo htmlspecialchars($consumo['bebida_nome']); ?></td>
                            <td><?php echo number_format($consumo['quantidade'], 3); ?>L</td>
                            <td><?php echo formatMoney($consumo['valor_unitario']); ?></td>
                            <td><strong><?php echo formatMoney($consumo['valor_total']); ?></strong></td>
                            <td>
                                <span class="badge badge-warning">
                                    +<?php echo formatMoney($consumo['pontos_ganhos']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>TOTAL</strong></td>
                            <td><strong><?php echo number_format($stats['total_litros'], 2); ?>L</strong></td>
                            <td></td>
                            <td><strong><?php echo formatMoney($stats['total_gasto']); ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table></div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Histórico de Cashback -->
            <div class="tab-pane fade" id="cashback">
                <?php if (empty($historico_cashback)): ?>
                <p class="text-muted text-center">Nenhuma movimentação de cashback.</p>
                <?php else: ?>
                <div class="table-responsive"><table class="table">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                            <th>Saldo Anterior</th>
                            <th>Saldo Atual</th>
                            <th>Descrição</th>
                            <th>Usuário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico_cashback as $hist): ?>
                        <tr>
                            <td><?php echo $hist['data_formatada']; ?></td>
                            <td>
                                <?php
                                $tipo_class = [
                                    'credito' => 'success',
                                    'resgate' => 'danger',
                                    'ajuste' => 'warning',
                                    'expiracao' => 'secondary'
                                ];
                                $tipo_label = [
                                    'credito' => 'Crédito',
                                    'resgate' => 'Resgate',
                                    'ajuste' => 'Ajuste',
                                    'expiracao' => 'Expiração'
                                ];
                                ?>
                                <span class="badge badge-<?php echo $tipo_class[$hist['tipo']]; ?>">
                                    <?php echo $tipo_label[$hist['tipo']]; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($hist['tipo'] == 'resgate'): ?>
                                <span class="text-danger">-<?php echo formatMoney($hist['valor']); ?></span>
                                <?php else: ?>
                                <span class="text-success">+<?php echo formatMoney($hist['valor']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatMoney($hist['saldo_anterior']); ?></td>
                            <td><strong><?php echo formatMoney($hist['saldo_atual']); ?></strong></td>
                            <td><?php echo htmlspecialchars($hist['descricao']); ?></td>
                            <td><?php echo htmlspecialchars($hist['user_name'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Adicionar Consumo -->
<div id="addConsumoModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addConsumoModal')">&times;</span>
        <h2>Adicionar Consumo Manual</h2>
        
        <form method="POST" action="cliente_add_consumo.php">
            <input type="hidden" name="cliente_id" value="<?php echo $cliente_id; ?>">
            
            <div class="form-group">
                <label>Bebida *</label>
                <select name="bebida_id" required>
                    <option value="">Selecione...</option>
                    <?php
                    $stmt = $conn->prepare("SELECT id, name, value FROM bebidas WHERE estabelecimento_id = ? ORDER BY name");
                    $stmt->execute([$cliente['estabelecimento_id']]);
                    while ($bebida = $stmt->fetch()):
                    ?>
                    <option value="<?php echo $bebida['id']; ?>" data-valor="<?php echo $bebida['value']; ?>">
                        <?php echo htmlspecialchars($bebida['name']); ?> - <?php echo formatMoney($bebida['value']); ?>/L
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Quantidade (Litros) *</label>
                    <input type="number" name="quantidade" step="0.001" min="0.001" required>
                </div>
                
                <div class="form-group col-md-6">
                    <label>Valor Unitário (R$/L) *</label>
                    <input type="text" name="valor_unitario" class="money-mask" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Data/Hora do Consumo *</label>
                <input type="datetime-local" name="data_consumo" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addConsumoModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ajustar Cashback -->
<div id="ajusteCashbackModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('ajusteCashbackModal')">&times;</span>
        <h2>Ajustar Cashback</h2>
        
        <form method="POST" action="cliente_ajustar_cashback.php">
            <input type="hidden" name="cliente_id" value="<?php echo $cliente_id; ?>">
            
            <div class="alert alert-info">
                <strong>Saldo Atual:</strong> <?php echo formatMoney($cliente['pontos_cashback']); ?>
            </div>
            
            <div class="form-group">
                <label>Tipo de Ajuste *</label>
                <select name="tipo" required>
                    <option value="credito">Adicionar Pontos</option>
                    <option value="resgate">Resgatar Pontos</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Valor (R$) *</label>
                <input type="text" name="valor" class="money-mask" required>
            </div>
            
            <div class="form-group">
                <label>Descrição/Motivo *</label>
                <textarea name="descricao" rows="3" required></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('ajusteCashbackModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<style>
.info-item {
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}
.info-item:last-child {
    border-bottom: none;
}
.info-item strong {
    display: inline-block;
    width: 150px;
}
.nav-tabs .nav-link {
    color: #666;
}
.nav-tabs .nav-link.active {
    color: #007bff;
    font-weight: bold;
}
</style>

<script>
// Tabs
document.querySelectorAll('[data-toggle="tab"]').forEach(tab => {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        const target = this.getAttribute('href');
        
        // Remove active de todos
        document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('show', 'active');
        });
        
        // Adiciona active no clicado
        this.classList.add('active');
        document.querySelector(target).classList.add('show', 'active');
    });
});

// Máscara de dinheiro
document.querySelectorAll('.money-mask').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = (parseInt(value) / 100).toFixed(2);
        value = value.replace('.', ',');
        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
        e.target.value = 'R$ ' + value;
    });
});
</script>

<?php
function formatCPF($cpf) {
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

function formatCEP($cep) {
    return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $cep);
}

include 'includes/footer.php';
?>
