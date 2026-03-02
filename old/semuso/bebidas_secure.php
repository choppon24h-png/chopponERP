<?php
$page_title = 'Bebidas';
$current_page = 'bebidas';

require_once '../includes/config_secure.php';
require_once '../includes/auth_secure.php';
require_once '../includes/upload_helper.php';
require_once '../includes/permissions.php';

// Verificar permissão de acesso
requirePagePermission('bebidas', 'view');

$conn = getDBConnection();
$success = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ VALIDAR TOKEN CSRF
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        Logger::security("Tentativa de CSRF detectada", [
            'page' => 'bebidas',
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        die('Token CSRF inválido. Recarregue a página e tente novamente.');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        // ✅ Validar permissão de criação
        requirePagePermission('bebidas', 'create');
        
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $ibu = sanitize($_POST['ibu']);
        $alcool = numberToFloat($_POST['alcool']);
        $brand = sanitize($_POST['brand']);
        $type = sanitize($_POST['type']);
        $value = numberToFloat($_POST['value']);
        $promotional_value = numberToFloat($_POST['promotional_value']);
        $estabelecimento_id = isAdminGeral() ? ($_POST['estabelecimento_id'] ?? null) : getEstabelecimentoId();
        
        // ✅ UPLOAD SEGURO DE IMAGEM
        $image_path = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/bebidas/';
            $result = SecureUpload::upload($_FILES['image'], 'image', $upload_dir);
            
            if ($result['success']) {
                $image_path = 'uploads/bebidas/' . $result['filename'];
                
                // Criar thumbnail (opcional)
                $thumb_dir = '../uploads/bebidas/thumbs/';
                if (!is_dir($thumb_dir)) {
                    mkdir($thumb_dir, 0755, true);
                }
                SecureUpload::resizeImage(
                    $result['path'],
                    $thumb_dir . $result['filename'],
                    300,
                    300
                );
            } else {
                $error = $result['error'];
            }
        }
        
        if (empty($error)) {
            $stmt = $conn->prepare("
                INSERT INTO bebidas (estabelecimento_id, name, description, ibu, alcool, brand, type, value, promotional_value, image)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$estabelecimento_id, $name, $description, $ibu, $alcool, $brand, $type, $value, $promotional_value, $image_path])) {
                $success = 'Bebida cadastrada com sucesso!';
                
                Logger::info("Bebida criada", [
                    'bebida_name' => $name,
                    'estabelecimento_id' => $estabelecimento_id,
                    'user_id' => $_SESSION['user_id']
                ]);
            } else {
                $error = 'Erro ao cadastrar bebida.';
                Logger::error("Erro ao criar bebida", [
                    'bebida_name' => $name,
                    'error' => $stmt->errorInfo()
                ]);
            }
        }
    }
    
    if ($action === 'update') {
        // ✅ Validar permissão de edição
        requirePagePermission('bebidas', 'edit');
        
        $id = intval($_POST['id']);
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $ibu = sanitize($_POST['ibu']);
        $alcool = numberToFloat($_POST['alcool']);
        $brand = sanitize($_POST['brand']);
        $type = sanitize($_POST['type']);
        $value = numberToFloat($_POST['value']);
        $promotional_value = numberToFloat($_POST['promotional_value']);
        
        // Buscar bebida existente para verificar permissão e imagem antiga
        $stmt = $conn->prepare("SELECT * FROM bebidas WHERE id = ?");
        $stmt->execute([$id]);
        $bebida_atual = $stmt->fetch();
        
        if (!$bebida_atual) {
            $error = 'Bebida não encontrada.';
        } else {
            // Verificar se usuário tem permissão para editar esta bebida
            if (!isAdminGeral() && $bebida_atual['estabelecimento_id'] != getEstabelecimentoId()) {
                Logger::security("Tentativa de editar bebida sem permissão", [
                    'bebida_id' => $id,
                    'user_id' => $_SESSION['user_id'],
                    'estabelecimento_id' => getEstabelecimentoId()
                ]);
                die('Você não tem permissão para editar esta bebida.');
            }
            
            // ✅ UPLOAD SEGURO DE NOVA IMAGEM
            $image_path = $bebida_atual['image'];
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/bebidas/';
                $result = SecureUpload::upload($_FILES['image'], 'image', $upload_dir);
                
                if ($result['success']) {
                    // Remover imagem antiga
                    if (!empty($bebida_atual['image'])) {
                        $old_image = '../' . $bebida_atual['image'];
                        SecureUpload::deleteFile($old_image);
                        
                        // Remover thumbnail antigo
                        $old_thumb = str_replace('/bebidas/', '/bebidas/thumbs/', $old_image);
                        SecureUpload::deleteFile($old_thumb);
                    }
                    
                    $image_path = 'uploads/bebidas/' . $result['filename'];
                    
                    // Criar novo thumbnail
                    $thumb_dir = '../uploads/bebidas/thumbs/';
                    if (!is_dir($thumb_dir)) {
                        mkdir($thumb_dir, 0755, true);
                    }
                    SecureUpload::resizeImage(
                        $result['path'],
                        $thumb_dir . $result['filename'],
                        300,
                        300
                    );
                } else {
                    $error = $result['error'];
                }
            }
            
            if (empty($error)) {
                $stmt = $conn->prepare("
                    UPDATE bebidas 
                    SET name = ?, description = ?, ibu = ?, alcool = ?, brand = ?, type = ?, value = ?, promotional_value = ?, image = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$name, $description, $ibu, $alcool, $brand, $type, $value, $promotional_value, $image_path, $id])) {
                    $success = 'Bebida atualizada com sucesso!';
                    
                    Logger::info("Bebida atualizada", [
                        'bebida_id' => $id,
                        'bebida_name' => $name,
                        'user_id' => $_SESSION['user_id']
                    ]);
                } else {
                    $error = 'Erro ao atualizar bebida.';
                    Logger::error("Erro ao atualizar bebida", [
                        'bebida_id' => $id,
                        'error' => $stmt->errorInfo()
                    ]);
                }
            }
        }
    }
    
    if ($action === 'delete') {
        // ✅ Validar permissão de exclusão
        requirePagePermission('bebidas', 'delete');
        
        $id = intval($_POST['id']);
        
        // Buscar bebida para verificar permissão e remover imagem
        $stmt = $conn->prepare("SELECT * FROM bebidas WHERE id = ?");
        $stmt->execute([$id]);
        $bebida = $stmt->fetch();
        
        if (!$bebida) {
            $error = 'Bebida não encontrada.';
        } else {
            // Verificar permissão
            if (!isAdminGeral() && $bebida['estabelecimento_id'] != getEstabelecimentoId()) {
                Logger::security("Tentativa de excluir bebida sem permissão", [
                    'bebida_id' => $id,
                    'user_id' => $_SESSION['user_id']
                ]);
                die('Você não tem permissão para excluir esta bebida.');
            }
            
            $stmt = $conn->prepare("DELETE FROM bebidas WHERE id = ?");
            if ($stmt->execute([$id])) {
                // Remover imagem e thumbnail
                if (!empty($bebida['image'])) {
                    $image_file = '../' . $bebida['image'];
                    SecureUpload::deleteFile($image_file);
                    
                    $thumb_file = str_replace('/bebidas/', '/bebidas/thumbs/', $image_file);
                    SecureUpload::deleteFile($thumb_file);
                }
                
                $success = 'Bebida excluída com sucesso!';
                
                Logger::info("Bebida excluída", [
                    'bebida_id' => $id,
                    'bebida_name' => $bebida['name'],
                    'user_id' => $_SESSION['user_id']
                ]);
            } else {
                $error = 'Erro ao excluir bebida.';
                Logger::error("Erro ao excluir bebida", [
                    'bebida_id' => $id,
                    'error' => $stmt->errorInfo()
                ]);
            }
        }
    }
}

// Buscar bebidas
$estabelecimento_filter = '';
$params = [];

if (!isAdminGeral()) {
    $estabelecimento_filter = 'WHERE b.estabelecimento_id = ?';
    $params[] = getEstabelecimentoId();
}

$stmt = $conn->prepare("
    SELECT b.*, e.name as estabelecimento_name
    FROM bebidas b
    LEFT JOIN estabelecimentos e ON b.estabelecimento_id = e.id
    $estabelecimento_filter
    ORDER BY b.name ASC
");
$stmt->execute($params);
$bebidas = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="content-header">
    <h1><?php echo $page_title; ?></h1>
    <?php if (hasPagePermission('bebidas', 'create')): ?>
    <button class="btn btn-primary" onclick="openModal('createModal')">
        <i class="fas fa-plus"></i> Nova Bebida
    </button>
    <?php endif; ?>
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

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Imagem</th>
                    <th>Nome</th>
                    <th>Marca</th>
                    <th>Tipo</th>
                    <th>IBU</th>
                    <th>Álcool</th>
                    <th>Valor</th>
                    <?php if (isAdminGeral()): ?>
                    <th>Estabelecimento</th>
                    <?php endif; ?>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bebidas as $bebida): ?>
                <tr>
                    <td>
                        <?php if ($bebida['image']): ?>
                        <img src="../<?php echo htmlspecialchars($bebida['image']); ?>" 
                             alt="<?php echo htmlspecialchars($bebida['name']); ?>" 
                             style="width: 50px; height: 50px; object-fit: cover;">
                        <?php else: ?>
                        <div style="width: 50px; height: 50px; background: #ddd;"></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($bebida['name']); ?></td>
                    <td><?php echo htmlspecialchars($bebida['brand']); ?></td>
                    <td><?php echo htmlspecialchars($bebida['type']); ?></td>
                    <td><?php echo htmlspecialchars($bebida['ibu']); ?></td>
                    <td><?php echo number_format($bebida['alcool'], 1); ?>%</td>
                    <td><?php echo formatMoney($bebida['value']); ?></td>
                    <?php if (isAdminGeral()): ?>
                    <td><?php echo htmlspecialchars($bebida['estabelecimento_name']); ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if (hasPagePermission('bebidas', 'edit')): ?>
                        <button class="btn btn-sm btn-warning" onclick="editBebida(<?php echo $bebida['id']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php endif; ?>
                        
                        <?php if (hasPagePermission('bebidas', 'delete')): ?>
                        <button class="btn btn-sm btn-danger" onclick="deleteBebida(<?php echo $bebida['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Criar Bebida -->
<div id="createModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('createModal')">&times;</span>
        <h2>Nova Bebida</h2>
        
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
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
            
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required>
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Marca *</label>
                    <input type="text" name="brand" required>
                </div>
                
                <div class="form-group">
                    <label>Tipo *</label>
                    <input type="text" name="type" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>IBU</label>
                    <input type="text" name="ibu">
                </div>
                
                <div class="form-group">
                    <label>Álcool (%) *</label>
                    <input type="text" name="alcool" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Valor (R$) *</label>
                    <input type="text" name="value" required>
                </div>
                
                <div class="form-group">
                    <label>Valor Promocional (R$)</label>
                    <input type="text" name="promotional_value">
                </div>
            </div>
            
            <div class="form-group">
                <label>Imagem</label>
                <input type="file" name="image" accept="image/*">
                <small>Formatos aceitos: JPG, PNG, GIF, WEBP. Tamanho máximo: 5MB</small>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
