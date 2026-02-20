<?php
$page_title = 'Bebidas';
$current_page = 'bebidas';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';

// Verificar permissão de acesso
requirePagePermission('bebidas', 'view');

$conn = getDBConnection();
$success = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $ibu = sanitize($_POST['ibu']);
        $alcool = numberToFloat($_POST['alcool']);
        $brand = sanitize($_POST['brand']);
        $type = sanitize($_POST['type']);
        $value = numberToFloat($_POST['value']);
        $promotional_value = numberToFloat($_POST['promotional_value']);
        $estabelecimento_id = isAdminGeral() ? ($_POST['estabelecimento_id'] ?? null) : getEstabelecimentoId();
        
        // Upload de imagem
        $image_path = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $upload_dir = '../uploads/bebidas/';
            $file_ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_ext;
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = 'uploads/bebidas/' . $file_name;
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO bebidas (estabelecimento_id, name, description, ibu, alcool, brand, type, value, promotional_value, image)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$estabelecimento_id, $name, $description, $ibu, $alcool, $brand, $type, $value, $promotional_value, $image_path])) {
            $success = 'Bebida cadastrada com sucesso!';
        } else {
            $error = 'Erro ao cadastrar bebida.';
        }
    }
    
    if ($action === 'update') {
        $id = $_POST['id'];
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $ibu = sanitize($_POST['ibu']);
        $alcool = numberToFloat($_POST['alcool']);
        $brand = sanitize($_POST['brand']);
        $type = sanitize($_POST['type']);
        $value = numberToFloat($_POST['value']);
        $promotional_value = numberToFloat($_POST['promotional_value']);
        
        // Upload de imagem
        $image_update = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $upload_dir = '../uploads/bebidas/';
            $file_ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_ext;
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_update = ", image = 'uploads/bebidas/" . $file_name . "'";
            }
        }
        
        $stmt = $conn->prepare("
            UPDATE bebidas 
            SET name = ?, description = ?, ibu = ?, alcool = ?, brand = ?, type = ?, value = ?, promotional_value = ? $image_update
            WHERE id = ?
        ");
        
        if ($stmt->execute([$name, $description, $ibu, $alcool, $brand, $type, $value, $promotional_value, $id])) {
            $success = 'Bebida atualizada com sucesso!';
        } else {
            $error = 'Erro ao atualizar bebida.';
        }
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM bebidas WHERE id = ?");
        if ($stmt->execute([$id])) {
            $success = 'Bebida excluída com sucesso!';
        } else {
            $error = 'Erro ao excluir bebida.';
        }
    }
}

// Listar bebidas
if (isAdminGeral()) {
    $stmt = $conn->query("
        SELECT b.*, e.name as estabelecimento_name 
        FROM bebidas b
        INNER JOIN estabelecimentos e ON b.estabelecimento_id = e.id
        ORDER BY b.created_at DESC
    ");
} else {
    $estabelecimento_id = getEstabelecimentoId();
    $stmt = $conn->prepare("
        SELECT b.*, e.name as estabelecimento_name 
        FROM bebidas b
        INNER JOIN estabelecimentos e ON b.estabelecimento_id = e.id
        WHERE b.estabelecimento_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$estabelecimento_id]);
}
$bebidas = $stmt->fetchAll();

// Listar estabelecimentos para o formulário
$estabelecimentos = [];
if (isAdminGeral()) {
    $stmt = $conn->query("SELECT * FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos = $stmt->fetchAll();
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Bebidas</h1>
    <?php if (hasPagePermission('bebidas', 'create')): ?>
    <button class="btn btn-primary" onclick="openModal('modalBebida')">+ Nova Bebida</button>
    <?php endif; ?>
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
                        <th>Imagem</th>
                        <th>Nome</th>
                        <th>Marca</th>
                        <th>Tipo</th>
                        <th>IBU</th>
                        <th>Álcool</th>
                        <th>Valor</th>
                        <th>Valor Promo</th>
                        <?php if (isAdminGeral()): ?>
                        <th>Estabelecimento</th>
                        <?php endif; ?>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bebidas)): ?>
                    <tr>
                        <td colspan="<?php echo isAdminGeral() ? '10' : '9'; ?>" class="text-center">
                            Nenhuma bebida cadastrada
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($bebidas as $bebida): ?>
                        <tr>
                            <td>
                                <?php if ($bebida['image']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $bebida['image']; ?>" class="img-thumbnail" alt="<?php echo $bebida['name']; ?>">
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $bebida['name']; ?></td>
                            <td><?php echo $bebida['brand']; ?></td>
                            <td><?php echo $bebida['type']; ?></td>
                            <td><?php echo $bebida['ibu']; ?></td>
                            <td><?php echo $bebida['alcool']; ?>%</td>
                            <td><?php echo formatMoney($bebida['value']); ?></td>
                            <td><?php echo formatMoney($bebida['promotional_value']); ?></td>
                            <?php if (isAdminGeral()): ?>
                            <td><?php echo $bebida['estabelecimento_name']; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-primary" onclick='editBebida(<?php echo json_encode($bebida); ?>)'>Editar</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('Deseja excluir esta bebida?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $bebida['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Excluir</button>
                                    </form>
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

<!-- Modal Bebida -->
<div id="modalBebida" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Nova Bebida</h3>
            <button class="modal-close" onclick="closeModal('modalBebida')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="formBebida">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="bebidaId">
                
                <?php if (isAdminGeral()): ?>
                <div class="form-group">
                    <label for="estabelecimento_id">Estabelecimento *</label>
                    <select name="estabelecimento_id" id="estabelecimento_id" class="form-control" required>
                        <option value="">Selecione</option>
                        <?php foreach ($estabelecimentos as $estab): ?>
                        <option value="<?php echo $estab['id']; ?>"><?php echo $estab['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="name">Nome *</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Descrição *</label>
                    <input type="text" name="description" id="description" class="form-control" required>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="brand">Marca *</label>
                            <input type="text" name="brand" id="brand" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="type">Tipo *</label>
                            <input type="text" name="type" id="type" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="ibu">IBU *</label>
                            <input type="text" name="ibu" id="ibu" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="alcool">Teor Alcoólico (%) *</label>
                            <input type="text" name="alcool" id="alcool" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="value">Valor (R$) *</label>
                            <input type="number" name="value" id="value" class="form-control" step="0.01" min="0" required placeholder="Ex: 25.50">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="promotional_value">Valor Promocional (R$) *</label>
                            <input type="number" name="promotional_value" id="promotional_value" class="form-control" step="0.01" min="0" required placeholder="Ex: 20.00">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="image">Imagem</label>
                    <input type="file" name="image" id="image" class="form-control" accept="image/*" onchange="previewImage(this, 'imagePreview')">
                    <img id="imagePreview" src="" style="max-width: 200px; margin-top: 10px; display: none;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBebida')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function editBebida(bebida) {
    document.getElementById('modalTitle').textContent = 'Editar Bebida';
    document.getElementById('formAction').value = 'update';
    document.getElementById('bebidaId').value = bebida.id;
    document.getElementById('name').value = bebida.name;
    document.getElementById('description').value = bebida.description;
    document.getElementById('ibu').value = bebida.ibu;
    document.getElementById('alcool').value = bebida.alcool;
    document.getElementById('brand').value = bebida.brand;
    document.getElementById('type').value = bebida.type;
    document.getElementById('value').value = bebida.value;
    document.getElementById('promotional_value').value = bebida.promotional_value;
    
    if (bebida.image) {
        document.getElementById('imagePreview').src = '<?php echo SITE_URL; ?>/' + bebida.image;
        document.getElementById('imagePreview').style.display = 'block';
    }
    
    openModal('modalBebida');
}

function previewImage(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById(previewId);
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Reset form ao abrir modal para nova bebida
document.querySelector('[onclick="openModal(\'modalBebida\')"]').addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Nova Bebida';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formBebida').reset();
    document.getElementById('imagePreview').style.display = 'none';
});
</script>
JS;

require_once '../includes/footer.php';
?>
