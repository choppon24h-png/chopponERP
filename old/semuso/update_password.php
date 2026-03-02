<?php
/**
 * Script para atualizar senha do administrador
 * 
 * IMPORTANTE: Delete este arquivo após usar!
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Atualizar Senha - Chopp On Tap</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #FF8C00; margin-bottom: 20px; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; color: #1976d2; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        button { background: #FF8C00; color: #fff; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: 600; }
        button:hover { background: #e67e00; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Atualizar Senha do Administrador</h1>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? 'choppon24h@gmail.com';
    $newPassword = $_POST['password'] ?? 'Admin259087@';
    
    try {
        $conn = getDBConnection();
        
        // Gerar novo hash
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Atualizar no banco
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $result = $stmt->execute([$hash, $email]);
        
        if ($result) {
            echo "<div class='alert alert-success'>
                <strong>✅ Senha atualizada com sucesso!</strong><br><br>
                Email: <code>$email</code><br>
                Nova senha: <code>$newPassword</code><br>
                Hash: <code>" . substr($hash, 0, 30) . "...</code>
            </div>";
            
            echo "<div class='alert alert-warning'>
                <strong>⚠️ IMPORTANTE:</strong> Delete este arquivo agora por segurança!<br>
                <code>rm update_password.php</code>
            </div>";
            
            echo "<p><a href='index.php'><button>Fazer Login</button></a></p>";
        } else {
            echo "<div class='alert alert-danger'>
                <strong>❌ Erro ao atualizar senha</strong>
            </div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>
            <strong>❌ Erro:</strong> " . $e->getMessage() . "
        </div>";
    }
    
} else {
    echo "<div class='info'>
        Este script irá atualizar a senha do usuário administrador no banco de dados.
    </div>
    
    <form method='POST'>
        <div class='form-group'>
            <label>Email do Usuário</label>
            <input type='email' name='email' value='choppon24h@gmail.com' required>
        </div>
        
        <div class='form-group'>
            <label>Nova Senha</label>
            <input type='text' name='password' value='Admin259087@' required>
        </div>
        
        <button type='submit'>Atualizar Senha</button>
    </form>
    
    <div class='alert alert-warning' style='margin-top: 20px;'>
        <strong>⚠️ Atenção:</strong> Delete este arquivo após usar!
    </div>";
}

echo "</div>
</body>
</html>";
?>
