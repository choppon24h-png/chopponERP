<?php
$page_title = 'Usuários';
$current_page = 'usuarios';

require_once '../includes/config.php';
require_once '../includes/auth.php';
requireAdminGeral();

$conn = getDBConnection();
$success = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $type = $_POST['type'];
        
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, type) VALUES (?, ?, ?, ?)");
        
        if ($stmt->execute([$name, $email, $password, $type])) {
            $user_id = $conn->lastInsertId();
            
            // Se não for admin geral, associar estabelecimentos
            if ($type > 1 && isset($_POST['estabelecimentos'])) {
                $estabelecimentos = $_POST['estabelecimentos'];
                $stmt = $conn->prepare("INSERT INTO user_estabelecimento (user_id, estabelecimento_id) VALUES (?, ?)");
                foreach ($estabelecimentos as $estab_id) {
                    $stmt->execute([$user_id, $estab_id]);
                }
            }
            
            $success = 'Usuário cadastrado com sucesso!';
        } else {
            $error = 'Erro ao cadastrar usuário. Email pode já estar em uso.';
        }
    }
    
    if ($action === 'update') {
        $id = $_POST['id'];
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $type = $_POST['type'];
        
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, type = ? WHERE id = ?");
            $stmt->execute([$name, $email, $password, $type, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, type = ? WHERE id = ?");
            $stmt->execute([$name, $email, $type, $id]);
        }
        
        // Atualizar estabelecimentos
        if ($type > 1 && isset($_POST['estabelecimentos'])) {
            $conn->prepare("DELETE FROM user_estabelecimento WHERE user_id = ?")->execute([$id]);
            $estabelecimentos = $_POST['estabelecimentos'];
            $stmt = $conn->prepare("INSERT INTO user_estabelecimento (user_id, estabelecimento_id) VALUES (?, ?)");
            foreach ($estabelecimentos as $estab_id) {
                $stmt->execute([$id, $estab_id]);
            }
        }
        
        $success = 'Usuário atualizado com sucesso!';
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Usuário excluído com sucesso!';
        } else {
            $error = 'Erro ao excluir usuário.';
        }
    }
}

// Listar usuários
$stmt = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$usuarios = $stmt->fetchAll();

// Listar estabelecimentos
$stmt = $conn->query("SELECT * FROM estabelecimentos WHERE status = 1 ORDER BY name");
$estabelecimentos = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Usuários</h1>
    <button class="btn btn-primary" onclick="openModal('modalUsuario')">+ Novo Usuário</button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Tipo</th>
                        <th>Cadastrado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                    <tr>
                        <td><?php echo $usuario['id']; ?></td>
                        <td><?php echo $usuario['name']; ?></td>
                        <td><?php echo $usuario['email']; ?></td>
                        <td><?php echo getUserType($usuario['type']); ?></td>
                        <td><?php echo formatDateTimeBR($usuario['created_at']); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-primary" onclick='editUsuario(<?php echo json_encode($usuario); ?>)'>Editar</button>
                                <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirmDelete('Deseja excluir este usuário?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Excluir</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Usuário -->
<div id="modalUsuario" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Novo Usuário</h3>
            <button class="modal-close" onclick="closeModal('modalUsuario')">&times;</button>
        </div>
        <form method="POST" id="formUsuario">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="usuarioId">
                
                <div class="form-group">
                    <label for="name">Nome *</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" name="email" id="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Senha <span id="passwordLabel">*</span></label>
                    <input type="password" name="password" id="password" class="form-control">
                    <small style="color: var(--gray-600);" id="passwordHint">Deixe em branco para manter a senha atual</small>
                </div>
                
                <div class="form-group">
                    <label for="type">Tipo de Usuário *</label>
                    <select name="type" id="type" class="form-control" required onchange="toggleEstabelecimentos()">
                        <option value="">Selecione</option>
                        <option value="1">Admin. Geral</option>
                        <option value="2">Admin. Estabelecimento</option>
                        <option value="3">Gerente Estabelecimento</option>
                        <option value="4">Operador</option>
                    </select>
                </div>
                
                <div class="form-group" id="estabelecimentosGroup" style="display: none;">
                    <label>Estabelecimentos *</label>
                    <?php foreach ($estabelecimentos as $estab): ?>
                    <div class="checkbox-label">
                        <input type="checkbox" name="estabelecimentos[]" value="<?php echo $estab['id']; ?>">
                        <span><?php echo $estab['name']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUsuario')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function toggleEstabelecimentos() {
    const type = document.getElementById('type').value;
    const group = document.getElementById('estabelecimentosGroup');
    if (type > 1) {
        group.style.display = 'block';
    } else {
        group.style.display = 'none';
    }
}

function editUsuario(usuario) {
    document.getElementById('modalTitle').textContent = 'Editar Usuário';
    document.getElementById('formAction').value = 'update';
    document.getElementById('usuarioId').value = usuario.id;
    document.getElementById('name').value = usuario.name;
    document.getElementById('email').value = usuario.email;
    document.getElementById('type').value = usuario.type;
    document.getElementById('password').required = false;
    document.getElementById('passwordLabel').textContent = '';
    document.getElementById('passwordHint').style.display = 'block';
    
    toggleEstabelecimentos();
    
    // Carregar estabelecimentos do usuário
    $.ajax({
        url: 'ajax/get_user_estabelecimentos.php',
        type: 'GET',
        data: { user_id: usuario.id },
        dataType: 'json',
        success: function(response) {
            $('input[name="estabelecimentos[]"]').prop('checked', false);
            response.forEach(function(estab_id) {
                $('input[name="estabelecimentos[]"][value="' + estab_id + '"]').prop('checked', true);
            });
        }
    });
    
    openModal('modalUsuario');
}

// Reset form ao abrir modal para novo usuário
document.querySelector('[onclick="openModal(\'modalUsuario\')"]').addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Novo Usuário';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formUsuario').reset();
    document.getElementById('password').required = true;
    document.getElementById('passwordLabel').textContent = '*';
    document.getElementById('passwordHint').style.display = 'none';
    document.getElementById('estabelecimentosGroup').style.display = 'none';
});
</script>
JS;

require_once '../includes/footer.php';
?>
