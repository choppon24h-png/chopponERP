<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Se já estiver logado, redirecionar para dashboard
if (isLoggedIn()) {
    redirect('admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (login($email, $password)) {
        if ($remember) {
            setcookie('remember_email', $email, time() + (86400 * 30), '/');
        }
        redirect('admin/dashboard.php');
    } else {
        $error = 'Email ou senha inválidos';
    }
}

$remembered_email = $_COOKIE['remember_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="logo-container">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="logo">
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="index.php" class="login-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($remembered_email); ?>" 
                           required 
                           autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-control" 
                           required>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" <?php echo $remembered_email ? 'checked' : ''; ?>>
                        <span>Manter conectado</span>
                    </label>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-block">ENTRAR</button>
                </div>
                
                <div class="form-footer">
                    <a href="forgot-password.php" class="forgot-link">Esqueceu sua senha?</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
