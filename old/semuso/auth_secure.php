<?php
/**
 * Funções de Autenticação - Versão Segura
 * Inclui: Rate limiting, regeneração de session, proteção contra timing attacks
 */

require_once __DIR__ . '/config_secure.php';

// Rate limiting simples (em produção, use Redis ou Memcached)
function checkRateLimit($identifier, $max_attempts = 5, $time_window = 300) {
    $key = 'rate_limit_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time()
        ];
    }
    
    $data = $_SESSION[$key];
    
    // Resetar se passou o tempo
    if (time() - $data['first_attempt'] > $time_window) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time()
        ];
        return true;
    }
    
    // Verificar limite
    if ($data['attempts'] >= $max_attempts) {
        $remaining_time = $time_window - (time() - $data['first_attempt']);
        Logger::security("Rate limit excedido", [
            'identifier' => $identifier,
            'attempts' => $data['attempts'],
            'remaining_time' => $remaining_time
        ]);
        return false;
    }
    
    return true;
}

function incrementRateLimit($identifier) {
    $key = 'rate_limit_' . md5($identifier);
    if (isset($_SESSION[$key])) {
        $_SESSION[$key]['attempts']++;
    }
}

function resetRateLimit($identifier) {
    $key = 'rate_limit_' . md5($identifier);
    unset($_SESSION[$key]);
}

/**
 * Realiza login do usuário com segurança aprimorada
 */
function login($email, $password) {
    // Verificar rate limiting
    if (!checkRateLimit('login_' . $email)) {
        Logger::security("Login bloqueado por rate limit", ['email' => $email]);
        sleep(2); // Delay adicional para dificultar brute force
        return false;
    }
    
    incrementRateLimit('login_' . $email);
    
    Logger::auth("Tentativa de login", ['email' => $email]);
    
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        // Usar timing-safe comparison para evitar timing attacks
        $user_exists = !empty($user);
        
        if (!$user_exists) {
            // Executar password_verify mesmo se usuário não existir
            // para evitar timing attacks
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuv');
            
            Logger::auth("Login falhou: Usuário não encontrado", ['email' => $email]);
            sleep(1); // Delay para dificultar enumeração de usuários
            return false;
        }
        
        // Verificar senha
        $passwordValid = password_verify($password, $user['password']);
        
        if (!DEBUG_MODE) {
            // Em produção, não logar detalhes da senha
            Logger::debug("Verificação de senha", [
                'email' => $email,
                'password_valid' => $passwordValid
            ]);
        }
        
        if ($passwordValid) {
            // IMPORTANTE: Regenerar session ID para prevenir session fixation
            session_regenerate_id(true);
            
            // Resetar rate limit após login bem-sucedido
            resetRateLimit('login_' . $email);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type'] = $user['type'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Gerar novo token CSRF
            unset($_SESSION['csrf_token']);
            generateCSRFToken();
            
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
                'type' => $user['type'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            return true;
        }
        
        Logger::auth("Login falhou: Senha incorreta", [
            'email' => $email,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        sleep(1); // Delay para dificultar brute force
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
    Logger::auth("Logout", [
        'user_id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['user_email'] ?? null
    ]);
    
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
        Logger::security("Tentativa de acesso não autorizado a área admin", [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_type' => $_SESSION['user_type'] ?? null
        ]);
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
?>
