<?php
/**
 * Funções de Autenticação
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AuditLog.php';

/**
 * Realiza login do usuário
 */
function login($email, $password) {
    Logger::auth("Tentativa de login", ['email' => $email]);
    
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        Logger::debug("Usuário encontrado no banco", [
            'email' => $email,
            'user_found' => !empty($user),
            'user_id' => $user['id'] ?? null
        ]);
        
        if (!$user) {
            Logger::auth("Login falhou: Usuário não encontrado", ['email' => $email]);
            AuditLog::login($conn, null, false, $email);
            return false;
        }
        
        // Log do hash armazenado (apenas para debug)
        Logger::debug("Verificando senha", [
            'email' => $email,
            'hash_stored' => substr($user['password'], 0, 20) . '...',
            'password_length' => strlen($password)
        ]);
        
        // Verificar senha
        $passwordValid = password_verify($password, $user['password']);
        
        Logger::debug("Resultado da verificação de senha", [
            'email' => $email,
            'password_valid' => $passwordValid
        ]);
        
        if ($passwordValid) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type'] = $user['type'];
            $_SESSION['login_time'] = time();
            
            // Se não for admin geral, buscar estabelecimento padrão
            if ($user['type'] > 1) {
                $stmt = $conn->prepare("SELECT estabelecimento_id FROM user_estabelecimento WHERE user_id = ? AND status = 1 LIMIT 1");
                $stmt->execute([$user['id']]);
                $userEstab = $stmt->fetch();
                if ($userEstab) {
                    $_SESSION['estabelecimento_id'] = $userEstab['estabelecimento_id'];
                }
            }
            
            Logger::auth("Login bem-sucedido", [
                'user_id' => $user['id'],
                'email' => $email,
                'type' => $user['type']
            ]);

            // ── Auditoria: login bem-sucedido ────────────────────────────────────────────
            AuditLog::login($conn, $user, true, $email);

            // ── Notificação Telegram: acesso master ─────────────────────────────────────────
            if ((int)$user['type'] === 1) {
                try {
                    require_once __DIR__ . '/telegram.php';
                    $conn_tg     = getDBConnection();
                    $ip_acesso   = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
                    $user_agent  = substr($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido', 0, 80);
                    $msg_master  = "<b>&#128274; ACESSO MASTER DETECTADO</b>\n\n";
                    $msg_master .= "&#128100; <b>Usu&aacute;rio:</b> " . htmlspecialchars($user['name']) . "\n";
                    $msg_master .= "&#128231; <b>E-mail:</b> " . htmlspecialchars($email) . "\n";
                    $msg_master .= "&#128197; <b>Data/Hora:</b> " . date('d/m/Y H:i:s') . "\n";
                    $msg_master .= "&#127760; <b>IP:</b> " . $ip_acesso . "\n";
                    $msg_master .= "&#128241; <b>Dispositivo:</b> " . htmlspecialchars($user_agent) . "\n";
                    $msg_master .= "\n<i>&#9888; Administrador Geral acessou o painel.</i>";
                    // Enviar para todos os estabelecimentos ativos
                    $stmt_estabs = $conn_tg->query("SELECT id FROM estabelecimentos WHERE status = 1 LIMIT 50");
                    $estab_ids   = $stmt_estabs->fetchAll(PDO::FETCH_COLUMN);
                    $telegram_master = new TelegramBot($conn_tg);
                    foreach ($estab_ids as $estab_id) {
                        $telegram_master->sendMessage((int)$estab_id, $msg_master, 'acesso_master', $user['id']);
                    }
                    file_put_contents(
                        __DIR__ . '/../logs/telegram.log',
                        date('Y-m-d H:i:s') . " - Acesso master: {$email} (IP: {$ip_acesso})\n",
                        FILE_APPEND
                    );
                } catch (Exception $e_tg_master) {
                    Logger::error('Telegram acesso master: ' . $e_tg_master->getMessage());
                }
            }

            return true;
        }
        
        Logger::auth("Login falhou: Senha incorreta", ['email' => $email]);
        AuditLog::login($conn, $user, false, $email);
        return false;
        
    } catch (Exception $e) {
        Logger::error("Erro no processo de login", [
            'email' => $email,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Realiza logout do usuário
 */
function logout() {
    // ── Auditoria: logout ────────────────────────────────────────────────────
    try {
        if (isset($_SESSION['user_id'])) {
            $conn_al = getDBConnection();
            AuditLog::logout($conn_al);
        }
    } catch (Exception $e_al) {
        // silencioso — não impede o logout
    }
    session_unset();
    session_destroy();
    redirect('index.php');
}

/**
 * Verifica se usuário está autenticado
 */
function requireAuth() {
    if (!isLoggedIn()) {
        redirect('index.php');
    }
}

/**
 * Verifica se usuário é admin geral
 */
function requireAdminGeral() {
    requireAuth();
    if (!isAdminGeral()) {
        redirect('admin/dashboard.php');
    }
}

/**
 * Obtém dados do usuário logado
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Obtém estabelecimentos do usuário
 */
function getUserEstabelecimentos($user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT e.* 
        FROM estabelecimentos e
        INNER JOIN user_estabelecimento ue ON e.id = ue.estabelecimento_id
        WHERE ue.user_id = ? AND ue.status = 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Define estabelecimento ativo na sessão
 */
function setEstabelecimento($estabelecimento_id) {
    $_SESSION['estabelecimento_id'] = $estabelecimento_id;
}

/**
 * Obtém ID do estabelecimento ativo
 */
function getEstabelecimentoId() {
    return $_SESSION['estabelecimento_id'] ?? null;
}
