<?php
$page_title = 'Regras de Cashback';
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
        $estabelecimento_id = isAdminGeral() ? ($_POST['estabelecimento_id'] ?? null) : getEstabelecimentoId();
        $nome = sanitize($_POST['nome']);
        $descricao = sanitize($_POST['descricao']);
        $tipo_regra = $_POST['tipo_regra'];
        $valor_regra = numberToFloat($_POST['valor_regra']);
        $valor_minimo = numberToFloat($_POST['valor_minimo'] ?? '0');
        $valor_maximo = !empty($_POST['valor_maximo']) ? numberToFloat($_POST['valor_maximo']) : null;
        $multiplicador = numberToFloat($_POST['multiplicador'] ?? '1');
        $prioridade = intval($_POST['prioridade'] ?? 0);
        
        // Dias da semana (JSON)
        $dias_semana = isset($_POST['dias_semana']) ? json_encode($_POST['dias_semana']) : null;
        
        // Horários
        $hora_inicio = !empty($_POST['hora_inicio']) ? $_POST['hora_inicio'] : null;
        $hora_fim = !empty($_POST['hora_fim']) ? $_POST['hora_fim'] : null;
        
        // Datas
        $data_inicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : null;
        $data_fim = !empty($_POST['data_fim']) ? $_POST['data_fim'] : null;
        
        // Bebidas específicas (JSON)
        $bebidas_especificas = isset($_POST['bebidas_especificas']) ? json_encode($_POST['bebidas_especificas']) : null;
        
        $stmt = $conn->prepare("
            INSERT INTO cashback_regras (
                estabelecimento_id, nome, descricao, tipo_regra, valor_regra,
                valor_minimo, valor_maximo, dias_semana, hora_inicio, hora_fim,
                data_inicio, data_fim, bebidas_especificas, multiplicador, prioridade
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([
            $estabelecimento_id, $nome, $descricao, $tipo_regra, $valor_regra,
            $valor_minimo, $valor_maximo, $dias_semana, $hora_inicio, $hora_fim,
            $data_inicio, $data_fim, $bebidas_especificas, $multiplicador, $prioridade
        ])) {
            $success = 'Regra criada com sucesso!';
            Logger::info("Regra de cashback criada", [
                'regra_nome' => $nome,
                'estabelecimento_id' => $estabelecimento_id
            ]);
        } else {
            $error = 'Erro ao criar regra.';
        }
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE cashback_regras SET ativo = NOT ativo WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Status atualizado com sucesso!';
        } else {
            $error = 'Erro ao atualizar status.';
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM cashback_regras WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Regra excluída com sucesso!';
        } else {
            $error = 'Erro ao excluir regra.';
        }
    }
}

// Buscar regras
$estabelecimento_filter = '';
$params = [];

if (!isAdminGeral()) {
    $estabelecimento_filter = 'WHERE cr.estabelecimento_id = ?';
    $params[] = getEstabelecimentoId();
}

$stmt = $conn->prepare("
    SELECT 
        cr.*,
        e.name as estabelecimento_name
    FROM cashback_regras cr
    LEFT JOIN estabelecimentos e ON cr.estabelecimento_id = e.id
    $estabelecimento_filter
    ORDER BY cr.prioridade DESC, cr.created_at DESC
");
$stmt->execute($params);
$regras = $stmt->fetchAll();

// Buscar configuração de cashback
$estabelecimento_id = isAdminGeral() ? null : getEstabelecimentoId();
if ($estabelecimento_id) {
    $stmt = $conn->prepare("SELECT * FROM cashback_config WHERE estabelecimento_id = ?");
    $stmt->execute([$estabelecimento_id]);
    $config = $stmt->fetch();
} else {
    $config = null;
}

require_once '../includes/header.php';
?>

<div class="content-header">
    <h1><?php echo $page_title; ?></h1>
    <div>
        <?php if (!isAdminGeral()): ?>
        <button class="btn btn-secondary" onclick="openModal('configModal')">
            <i class="fas fa-cog"></i> Configurações
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="fas fa-plus"></i> Nova Regra
        </button>
    </div>
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

<!-- Configurações Atuais -->
<?php if ($config): ?>
<div class="card mb-3">
    <div class="card-header">
        <h3>Configurações Gerais</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Sistema:</strong>
                <span class="badge badge-<?php echo $config['ativo'] ? 'success' : 'danger'; ?>">
                    <?php echo $config['ativo'] ? 'Ativo' : 'Inativo'; ?>
                </span>
            </div>
            <div class="col-md-3">
                <strong>Permite Resgate:</strong>
                <span class="badge badge-<?php echo $config['permite_resgate'] ? 'success' : 'secondary'; ?>">
                    <?php echo $config['permite_resgate'] ? 'Sim' : 'Não'; ?>
                </span>
            </div>
            <div class="col-md-3">
                <strong>Valor Mínimo Resgate:</strong>
                <?php echo formatMoney($config['valor_minimo_resgate']); ?>
            </div>
            <div class="col-md-3">
                <strong>Pontos Expiram:</strong>
                <?php echo $config['pontos_expiram'] ? $config['dias_expiracao'] . ' dias' : 'Não'; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabela de Regras -->
<div class="card">
    <div class="card-body">
        <?php if (empty($regras)): ?>
        <p class="text-muted text-center">Nenhuma regra cadastrada.</p>
        <?php else: ?>
        <div class="table-responsive"><table class="table">
            <thead>
                <tr>
                    <th>Prioridade</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Valor</th>
                    <th>Condições</th>
                    <?php if (isAdminGeral()): ?>
                    <th>Estabelecimento</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($regras as $regra): ?>
                <tr>
                    <td>
                        <span class="badge badge-secondary"><?php echo $regra['prioridade']; ?></span>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($regra['nome']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($regra['descricao']); ?></small>
                    </td>
                    <td>
                        <?php
                        $tipos = [
                            'percentual' => 'Percentual',
                            'valor_fixo' => 'Valor Fixo',
                            'pontos_por_real' => 'Pontos/R$'
                        ];
                        echo $tipos[$regra['tipo_regra']];
                        ?>
                    </td>
                    <td>
                        <?php if ($regra['tipo_regra'] == 'percentual'): ?>
                        <strong><?php echo number_format($regra['valor_regra'], 2); ?>%</strong>
                        <?php else: ?>
                        <strong><?php echo formatMoney($regra['valor_regra']); ?></strong>
                        <?php endif; ?>
                        
                        <?php if ($regra['multiplicador'] != 1): ?>
                        <br><small class="text-info">Multiplicador: <?php echo $regra['multiplicador']; ?>x</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $condicoes = [];
                        
                        if ($regra['valor_minimo'] > 0) {
                            $condicoes[] = 'Mín: ' . formatMoney($regra['valor_minimo']);
                        }
                        
                        if ($regra['valor_maximo']) {
                            $condicoes[] = 'Máx: ' . formatMoney($regra['valor_maximo']);
                        }
                        
                        if ($regra['dias_semana']) {
                            $dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                            $dias_selecionados = json_decode($regra['dias_semana']);
                            $dias_texto = array_map(fn($d) => $dias[$d], $dias_selecionados);
                            $condicoes[] = implode(', ', $dias_texto);
                        }
                        
                        if ($regra['hora_inicio'] && $regra['hora_fim']) {
                            $condicoes[] = substr($regra['hora_inicio'], 0, 5) . ' - ' . substr($regra['hora_fim'], 0, 5);
                        }
                        
                        if ($regra['data_inicio'] && $regra['data_fim']) {
                            $condicoes[] = formatDateBR($regra['data_inicio']) . ' a ' . formatDateBR($regra['data_fim']);
                        }
                        
                        if ($regra['bebidas_especificas']) {
                            $condicoes[] = 'Bebidas específicas';
                        }
                        
                        echo !empty($condicoes) ? implode('<br>', $condicoes) : '<span class="text-muted">Sem condições</span>';
                        ?>
                    </td>
                    <?php if (isAdminGeral()): ?>
                    <td><?php echo htmlspecialchars($regra['estabelecimento_name']); ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($regra['ativo']): ?>
                        <span class="badge badge-success">Ativa</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Inativa</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" onclick="editRegra(<?php echo $regra['id']; ?>)" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-<?php echo $regra['ativo'] ? 'secondary' : 'success'; ?>" 
                                onclick="toggleStatus(<?php echo $regra['id']; ?>)" 
                                title="<?php echo $regra['ativo'] ? 'Desativar' : 'Ativar'; ?>">
                            <i class="fas fa-<?php echo $regra['ativo'] ? 'ban' : 'check'; ?>"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteRegra(<?php echo $regra['id']; ?>)" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Criar Regra -->
<div id="createModal" class="modal">
    <div class="modal-content modal-lg">
        <span class="close" onclick="closeModal('createModal')">&times;</span>
        <h2>Nova Regra de Cashback</h2>
        
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
            
            <h3>Informações Básicas</h3>
            <div class="form-row">
                <div class="form-group col-md-8">
                    <label>Nome da Regra *</label>
                    <input type="text" name="nome" required placeholder="Ex: Cashback Dobrado Final de Semana">
                </div>
                
                <div class="form-group col-md-4">
                    <label>Prioridade</label>
                    <input type="number" name="prioridade" value="0" min="0" max="100">
                    <small class="text-muted">Maior = aplicada primeiro</small>
                </div>
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="descricao" rows="2" placeholder="Descrição da regra para o cliente"></textarea>
            </div>
            
            <h3>Tipo e Valor do Cashback</h3>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Tipo de Regra *</label>
                    <select name="tipo_regra" id="tipoRegra" required>
                        <option value="percentual">Percentual do Valor</option>
                        <option value="valor_fixo">Valor Fixo por Compra</option>
                        <option value="pontos_por_real">Pontos por Real Gasto</option>
                    </select>
                </div>
                
                <div class="form-group col-md-3">
                    <label id="labelValorRegra">Percentual (%) *</label>
                    <input type="text" name="valor_regra" class="money-mask" required>
                </div>
                
                <div class="form-group col-md-3">
                    <label>Multiplicador</label>
                    <input type="text" name="multiplicador" value="1.00" step="0.01">
                    <small class="text-muted">Ex: 2.00 = dobro</small>
                </div>
            </div>
            
            <h3>Condições (Opcional)</h3>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Valor Mínimo da Compra</label>
                    <input type="text" name="valor_minimo" class="money-mask" placeholder="R$ 0,00">
                </div>
                
                <div class="form-group col-md-6">
                    <label>Valor Máximo de Cashback</label>
                    <input type="text" name="valor_maximo" class="money-mask" placeholder="Sem limite">
                </div>
            </div>
            
            <div class="form-group">
                <label>Dias da Semana</label>
                <div class="checkbox-group">
                    <label><input type="checkbox" name="dias_semana[]" value="0"> Domingo</label>
                    <label><input type="checkbox" name="dias_semana[]" value="1"> Segunda</label>
                    <label><input type="checkbox" name="dias_semana[]" value="2"> Terça</label>
                    <label><input type="checkbox" name="dias_semana[]" value="3"> Quarta</label>
                    <label><input type="checkbox" name="dias_semana[]" value="4"> Quinta</label>
                    <label><input type="checkbox" name="dias_semana[]" value="5"> Sexta</label>
                    <label><input type="checkbox" name="dias_semana[]" value="6"> Sábado</label>
                </div>
                <small class="text-muted">Deixe em branco para todos os dias</small>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Horário Início</label>
                    <input type="time" name="hora_inicio">
                </div>
                
                <div class="form-group col-md-6">
                    <label>Horário Fim</label>
                    <input type="time" name="hora_fim">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio">
                </div>
                
                <div class="form-group col-md-6">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Regra</button>
            </div>
        </form>
    </div>
</div>

<style>
.checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}
.checkbox-group label {
    margin: 0;
    font-weight: normal;
}
</style>

<script>
// Alterar label conforme tipo de regra
document.getElementById('tipoRegra').addEventListener('change', function() {
    const label = document.getElementById('labelValorRegra');
    switch(this.value) {
        case 'percentual':
            label.textContent = 'Percentual (%) *';
            break;
        case 'valor_fixo':
            label.textContent = 'Valor Fixo (R$) *';
            break;
        case 'pontos_por_real':
            label.textContent = 'Pontos por R$ *';
            break;
    }
});

function toggleStatus(id) {
    if (confirm('Deseja alterar o status desta regra?')) {
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

function deleteRegra(id) {
    if (confirm('Tem certeza que deseja excluir esta regra?')) {
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

function editRegra(id) {
    alert('Funcionalidade de edição em desenvolvimento');
}
</script>

<?php require_once '../includes/footer.php'; ?>
