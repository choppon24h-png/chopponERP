<?php
/**
 * Instalador Automático do Sistema Chopp On Tap
 * 
 * IMPORTANTE: Delete este arquivo após a instalação!
 */

// Verificar se já foi instalado
if (file_exists('includes/config.php')) {
    $config_content = file_get_contents('includes/config.php');
    if (strpos($config_content, 'INSTALLED') !== false) {
        die('Sistema já instalado. Delete este arquivo.');
    }
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Testar conexão com banco de dados
        $db_host = $_POST['db_host'];
        $db_name = $_POST['db_name'];
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];
        
        try {
            $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Salvar configurações
            $config_template = file_get_contents('includes/config.php');
            $config_template = str_replace("define('DB_HOST', 'localhost');", "define('DB_HOST', '$db_host');", $config_template);
            $config_template = str_replace("define('DB_NAME', 'choppon');", "define('DB_NAME', '$db_name');", $config_template);
            $config_template = str_replace("define('DB_USER', 'root');", "define('DB_USER', '$db_user');", $config_template);
            $config_template = str_replace("define('DB_PASS', '');", "define('DB_PASS', '$db_pass');", $config_template);
            
            file_put_contents('includes/config.php', $config_template);
            
            header('Location: install.php?step=2');
            exit;
        } catch (PDOException $e) {
            $error = 'Erro ao conectar: ' . $e->getMessage();
        }
    }
    
    if ($step == 2) {
        // Importar banco de dados
        require_once 'includes/config.php';
        
        try {
            $conn = getDBConnection();
            $sql = file_get_contents('database.sql');
            $conn->exec($sql);
            
            header('Location: install.php?step=3');
            exit;
        } catch (PDOException $e) {
            $error = 'Erro ao importar banco: ' . $e->getMessage();
        }
    }
    
    if ($step == 3) {
        // Configurar URL do site
        $site_url = rtrim($_POST['site_url'], '/');
        
        $config_content = file_get_contents('includes/config.php');
        $config_content = str_replace("define('SITE_URL', 'http://localhost/choppon');", "define('SITE_URL', '$site_url');", $config_content);
        $config_content = str_replace("// INSTALLED", "define('INSTALLED', true); // INSTALLED", $config_content);
        
        file_put_contents('includes/config.php', $config_content);
        
        $success = 'Instalação concluída com sucesso!';
        $step = 4;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Chopp On Tap</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 50px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #FF8C00; margin-bottom: 10px; }
        .step { color: #666; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #333; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        button { background: #FF8C00; color: #fff; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: 600; }
        button:hover { background: #e67e00; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-danger { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #efe; color: #3c3; border: 1px solid #cfc; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; color: #1976d2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🍺 Chopp On Tap</h1>
        <p class="step">Instalação - Passo <?php echo $step; ?> de 3</p>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($step == 1): ?>
            <h2>Configuração do Banco de Dados</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Host do Banco</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Nome do Banco</label>
                    <input type="text" name="db_name" value="inlaud99_choppontap" required>
                </div>
                <div class="form-group">
                    <label>Usuário do Banco</label>
                    <input type="text" name="db_user" value="inlaud99_admin" required>
                </div>
                <div class="form-group">
                    <label>Senha do Banco</label>
                    <input type="password" name="db_pass" value="Admin259087@" required>
                </div>
                <button type="submit">Próximo</button>
            </form>
        <?php elseif ($step == 2): ?>
            <h2>Importar Banco de Dados</h2>
            <div class="alert alert-danger">
                <strong>ATENÇÃO:</strong> Este processo irá <strong>APAGAR TODOS OS DADOS</strong> existentes no banco e criar uma estrutura limpa.
                <br>Se você já tem dados no sistema, faça backup antes de continuar!
            </div>
            <div class="info">
                O instalador irá criar todas as tabelas necessárias e inserir os dados iniciais (usuário admin, configurações padrão).
            </div>
            <form method="POST">
                <button type="submit">Importar Banco de Dados</button>
            </form>
        <?php elseif ($step == 3): ?>
            <h2>Configuração Final</h2>
            <form method="POST">
                <div class="form-group">
                    <label>URL do Site</label>
                    <input type="text" name="site_url" value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']); ?>" required>
                    <small style="color: #666;">Exemplo: https://seudominio.com.br ou https://seudominio.com.br/choppon</small>
                </div>
                <button type="submit">Finalizar Instalação</button>
            </form>
        <?php elseif ($step == 4): ?>
            <h2>✅ Instalação Concluída!</h2>
            <div class="info">
                <p><strong>Credenciais de Acesso:</strong></p>
                <p>Email: choppon24h@gmail.com</p>
                <p>Senha: Admin259087@</p>
            </div>
            <div class="alert alert-danger">
                <strong>IMPORTANTE:</strong> Delete o arquivo <code>install.php</code> por segurança!
            </div>
            <a href="index.php"><button>Acessar Sistema</button></a>
        <?php endif; ?>
    </div>
</body>
</html>
