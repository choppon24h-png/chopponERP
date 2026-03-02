<?php
$page_title = 'Clientes';
$current_page = 'clientes';

require_once '../includes/config.php';
require_once '../includes/auth.php';

requireAuth();

$conn = getDBConnection();
$success = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $nome = sanitize($_POST['nome']);
        $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
        $email = sanitize($_POST['email']);
        $telefone = sanitize($_POST['telefone'] ?? '');
        $data_nascimento = $_POST['data_nascimento'] ?? null;
        
        // Endereço
        $endereco_rua = sanitize($_POST['endereco_rua'] ?? '');
        $endereco_numero = sanitize($_POST['endereco_numero'] ?? '');
        $endereco_complemento = sanitize($_POST['endereco_complemento'] ?? '');
        $endereco_bairro = sanitize($_POST['endereco_bairro'] ?? '');
        $endereco_cidade = sanitize($_POST['endereco_cidade'] ?? '');
        $endereco_estado = sanitize($_POST['endereco_estado'] ?? '');
        $endereco_cep = preg_replace('/[^0-9]/', '', $_POST['endereco_cep'] ?? '');
        
        $estabelecimento_id = isAdminGeral() ? ($_POST['estabelecimento_id'] ?? null) : getEstabelecimentoId();
        
        // Validar CPF
        if (strlen($cpf) != 11) {
            $error = 'CPF inválido.';
        } else {
            // Verificar se CPF já existe
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE cpf = ? AND estabelecimento_id = ?");
            $stmt->execute([$cpf, $estabelecimento_id]);
            if ($stmt->fetch()) {
                $error = 'CPF já cadastrado neste estabelecimento.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO clientes (
                        estabelecimento_id, nome, cpf, email, telefone, data_nascimento,
                        endereco_rua, endereco_numero, endereco_complemento, endereco_bairro,
                        endereco_cidade, endereco_estado, endereco_cep
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([
                    $estabelecimento_id, $nome, $cpf, $email, $telefone, $data_nascimento,
                    $endereco_rua, $endereco_numero, $endereco_complemento, $endereco_bairro,
                    $endereco_cidade, $endereco_estado, $endereco_cep
                ])) {
                    $success = 'Cliente cadastrado com sucesso!';
                    Logger::info("Cliente criado", [
                        'cliente_nome' => $nome,
                        'cpf' => $cpf,
                        'estabelecimento_id' => $estabelecimento_id
                    ]);
                } else {
                    $error = 'Erro ao cadastrar cliente.';
                }
            }
        }
    }
    
    if ($action === 'update') {
        $id = intval($_POST['id']);
        $nome = sanitize($_POST['nome']);
        $email = sanitize($_POST['email']);
        $telefone = sanitize($_POST['telefone'] ?? '');
        $data_nascimento = $_POST['data_nascimento'] ?? null;
        
        // Endereço
        $endereco_rua = sanitize($_POST['endereco_rua'] ?? '');
        $endereco_numero = sanitize($_POST['endereco_numero'] ?? '');
        $endereco_complemento = sanitize($_POST['endereco_complemento'] ?? '');
        $endereco_bairro = sanitize($_POST['endereco_bairro'] ?? '');
        $endereco_cidade = sanitize($_POST['endereco_cidade'] ?? '');
        $endereco_estado = sanitize($_POST['endereco_estado'] ?? '');
        $endereco_cep = preg_replace('/[^0-9]/', '', $_POST['endereco_cep'] ?? '');
        
        $stmt = $conn->prepare("
            UPDATE clientes SET
                nome = ?, email = ?, telefone = ?, data_nascimento = ?,
                endereco_rua = ?, endereco_numero = ?, endereco_complemento = ?,
                endereco_bairro = ?, endereco_cidade = ?, endereco_estado = ?, endereco_cep = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([
            $nome, $email, $telefone, $data_nascimento,
            $endereco_rua, $endereco_numero, $endereco_complemento,
            $endereco_bairro, $endereco_cidade, $endereco_estado, $endereco_cep,
            $id
        ])) {
            $success = 'Cliente atualizado com sucesso!';
        } else {
            $error = 'Erro ao atualizar cliente.';
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM clientes WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Cliente excluído com sucesso!';
        } else {
            $error = 'Erro ao excluir cliente.';
        }
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE clientes SET status = NOT status WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Status atualizado com sucesso!';
        } else {
            $error = 'Erro ao atualizar status.';
        }
    }
}

// Buscar clientes
$estabelecimento_filter = '';
$params = [];

if (!isAdminGeral()) {
    $estabelecimento_filter = 'WHERE c.estabelecimento_id = ?';
    $params[] = getEstabelecimentoId();
}

$stmt = $conn->prepare("
    SELECT 
        c.*,
        e.name as estabelecimento_name,
        COUNT(DISTINCT cc.id) as total_consumos,
        COALESCE(SUM(cc.valor_total), 0) as total_gasto
    FROM clientes c
    LEFT JOIN estabelecimentos e ON c.estabelecimento_id = e.id
    LEFT JOIN clientes_consumo cc ON c.id = cc.cliente_id
    $estabelecimento_filter
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="content-header">
    <h1><?php echo $page_title; ?></h1>
    <button class="btn btn-primary" onclick="openModal('createModal')">
        <i class="fas fa-plus"></i> Novo Cliente
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <?php echo $success; ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Estatísticas -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-primary">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo count($clientes); ?></h3>
            <p>Total de Clientes</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-success">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo count(array_filter($clientes, fn($c) => $c['status'] == 1)); ?></h3>
            <p>Clientes Ativos</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-warning">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatMoney(array_sum(array_column($clientes, 'pontos_cashback'))); ?></h3>
            <p>Total em Cashback</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-info">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo formatMoney(array_sum(array_column($clientes, 'total_consumido'))); ?></h3>
            <p>Total Consumido</p>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <input type="text" id="searchCliente" class="form-control" placeholder="Buscar por nome, CPF ou email...">
            </div>
            <div class="col-md-3">
                <select id="filterStatus" class="form-control">
                    <option value="">Todos os status</option>
                    <option value="1">Ativos</option>
                    <option value="0">Inativos</option>
                </select>
            </div>
            <div class="col-md-3">
                <select id="filterOrdem" class="form-control">
                    <option value="recente">Mais recentes</option>
                    <option value="nome">Nome A-Z</option>
                    <option value="pontos">Mais pontos</option>
                    <option value="consumo">Maior consumo</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Tabela de Clientes -->
<div class="card">
    <div class="card-body">
        <table class="table" id="tabelaClientes">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>CPF</th>
                    <th>Email</th>
                    <th>Telefone</th>
                    <th>Pontos</th>
                    <th>Total Consumido</th>
                    <th>Consumos</th>
                    <?php if (isAdminGeral()): ?>
                    <th>Estabelecimento</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $cliente): ?>
                <tr data-status="<?php echo $cliente['status']; ?>">
                    <td>
                        <strong><?php echo htmlspecialchars($cliente['nome']); ?></strong><br>
                        <small class="text-muted">Cadastro: <?php echo formatDateBR($cliente['created_at']); ?></small>
                    </td>
                    <td><?php echo formatCPF($cliente['cpf']); ?></td>
                    <td><?php echo htmlspecialchars($cliente['email']); ?></td>
                    <td><?php echo htmlspecialchars($cliente['telefone']); ?></td>
                    <td>
                        <span class="badge badge-warning">
                            <i class="fas fa-coins"></i> <?php echo formatMoney($cliente['pontos_cashback']); ?>
                        </span>
                    </td>
                    <td><?php echo formatMoney($cliente['total_consumido']); ?></td>
                    <td><?php echo $cliente['total_consumos']; ?></td>
                    <?php if (isAdminGeral()): ?>
                    <td><?php echo htmlspecialchars($cliente['estabelecimento_name']); ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($cliente['status']): ?>
                        <span class="badge badge-success">Ativo</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewCliente(<?php echo $cliente['id']; ?>)" title="Ver Detalhes">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="editCliente(<?php echo $cliente['id']; ?>)" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-<?php echo $cliente['status'] ? 'secondary' : 'success'; ?>" 
                                onclick="toggleStatus(<?php echo $cliente['id']; ?>)" 
                                title="<?php echo $cliente['status'] ? 'Desativar' : 'Ativar'; ?>">
                            <i class="fas fa-<?php echo $cliente['status'] ? 'ban' : 'check'; ?>"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCliente(<?php echo $cliente['id']; ?>)" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Criar Cliente -->
<div id="createModal" class="modal">
    <div class="modal-content modal-lg">
        <span class="close" onclick="closeModal('createModal')">&times;</span>
        <h2>Novo Cliente</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="create">
            
            <?php if (isAdminGeral()): ?>
            <div class="form-group">
                <label>Estabelecimento *</label>
                <select name="estabelecimento_id" required>
                    <option value="">Selecione...</option>
                    <?php
                    $stmt = $conn->query("SELECT id, name FROM estabelecimentos ORDER BY name");
                    while ($est = $stmt->fetch()):
                    ?>
                    <option value="<?php echo $est['id']; ?>"><?php echo htmlspecialchars($est['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <h3>Dados Pessoais</h3>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Nome Completo *</label>
                    <input type="text" name="nome" required>
                </div>
                
                <div class="form-group col-md-3">
                    <label>CPF *</label>
                    <input type="text" name="cpf" class="cpf-mask" required>
                </div>
                
                <div class="form-group col-md-3">
                    <label>Data de Nascimento</label>
                    <input type="date" name="data_nascimento">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                
                <div class="form-group col-md-6">
                    <label>Telefone</label>
                    <input type="text" name="telefone" class="phone-mask">
                </div>
            </div>
            
            <h3>Endereço</h3>
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>CEP</label>
                    <input type="text" name="endereco_cep" class="cep-mask" id="cep">
                </div>
                
                <div class="form-group col-md-7">
                    <label>Rua</label>
                    <input type="text" name="endereco_rua" id="rua">
                </div>
                
                <div class="form-group col-md-2">
                    <label>Número</label>
                    <input type="text" name="endereco_numero">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Complemento</label>
                    <input type="text" name="endereco_complemento">
                </div>
                
                <div class="form-group col-md-4">
                    <label>Bairro</label>
                    <input type="text" name="endereco_bairro" id="bairro">
                </div>
                
                <div class="form-group col-md-3">
                    <label>Cidade</label>
                    <input type="text" name="endereco_cidade" id="cidade">
                </div>
                
                <div class="form-group col-md-1">
                    <label>UF</label>
                    <input type="text" name="endereco_estado" id="estado" maxlength="2">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
// Busca em tempo real
document.getElementById('searchCliente').addEventListener('keyup', filterTable);
document.getElementById('filterStatus').addEventListener('change', filterTable);

function filterTable() {
    const search = document.getElementById('searchCliente').value.toLowerCase();
    const status = document.getElementById('filterStatus').value;
    const rows = document.querySelectorAll('#tabelaClientes tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const rowStatus = row.getAttribute('data-status');
        
        const matchSearch = text.includes(search);
        const matchStatus = status === '' || rowStatus === status;
        
        row.style.display = (matchSearch && matchStatus) ? '' : 'none';
    });
}

function viewCliente(id) {
    window.location.href = 'cliente_detalhes.php?id=' + id;
}

function editCliente(id) {
    // Implementar modal de edição
    alert('Funcionalidade de edição em desenvolvimento');
}

function toggleStatus(id) {
    if (confirm('Deseja alterar o status deste cliente?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteCliente(id) {
    if (confirm('Tem certeza que deseja excluir este cliente? Esta ação não pode ser desfeita.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Máscaras
document.querySelectorAll('.cpf-mask').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        e.target.value = value;
    });
});

document.querySelectorAll('.phone-mask').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = value.replace(/^(\d{2})(\d)/g, '($1) $2');
        value = value.replace(/(\d)(\d{4})$/, '$1-$2');
        e.target.value = value;
    });
});

document.querySelectorAll('.cep-mask').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = value.replace(/^(\d{5})(\d)/, '$1-$2');
        e.target.value = value;
    });
    
    // Buscar CEP
    input.addEventListener('blur', function(e) {
        const cep = e.target.value.replace(/\D/g, '');
        if (cep.length === 8) {
            fetch(`https://viacep.com.br/ws/${cep}/json/`)
                .then(response => response.json())
                .then(data => {
                    if (!data.erro) {
                        document.getElementById('rua').value = data.logradouro;
                        document.getElementById('bairro').value = data.bairro;
                        document.getElementById('cidade').value = data.localidade;
                        document.getElementById('estado').value = data.uf;
                    }
                });
        }
    });
});
</script>

<?php
// Função helper para formatar CPF
function formatCPF($cpf) {
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

require_once '../includes/footer.php';
?>
