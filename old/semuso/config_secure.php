<?php
/**
 * Configurações do Sistema Chopp On Tap
 * Versão 3.1.0 - SEGURA com variáveis de ambiente
 */

// Carregar variáveis de ambiente
function loadEnv($file = __DIR__ . '/../.env') {
    if (!file_exists($file)) {
        die("Arquivo .env não encontrado. Copie .env.example para .env e configure.");
    }
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignorar comentários
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse linha KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remover aspas se existirem
            $value = trim($value, '"\'');
            
            // Definir variável de ambiente
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Carregar .env
loadEnv();

// Função helper para obter variável de ambiente
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Modo Debug (NUNCA true em produção)
define('DEBUG_MODE', filter_var(env('DEBUG_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));

// Configurações do Banco de Dados
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// Validar configurações obrigatórias
if (!DB_NAME || !DB_USER || !DB_PASS) {
    die("Configurações de banco de dados não encontradas no arquivo .env");
}

// Configurações do Sistema
define('SITE_NAME', env('SITE_NAME', 'Chopp On Tap'));
define('SITE_URL', env('SITE_URL', detectSiteURL()));
define('SYSTEM_VERSION', env('SYSTEM_VERSION', 'v3.1.0'));

// Configurações de Sessão
define('SESSION_TIMEOUT', (int)env('SESSION_TIMEOUT', 3600)); // 1 hora (reduzido de 2h)

// Configurações SumUp
define('SUMUP_TOKEN', env('SUMUP_TOKEN'));
define('SUMUP_MERCHANT_CODE', env('SUMUP_MERCHANT_CODE'));
define('SUMUP_CHECKOUT_URL', 'https://api.sumup.com/v0.1/checkouts/');
define('SUMUP_MERCHANT_URL', 'https://api.sumup.com/v0.1/merchants/');

// Configurações JWT
define('JWT_SECRET', env('JWT_SECRET'));
define('JWT_ALGORITHM', 'HS256');

// Validar JWT_SECRET
if (!JWT_SECRET || strlen(JWT_SECRET) < 32) {
    die("JWT_SECRET deve ter no mínimo 32 caracteres. Configure no arquivo .env");
}

// Timezone
date_default_timezone_set(env('TIMEZONE', 'America/Sao_Paulo'));

// Configurar sessão segura
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar timeout de sessão
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Headers de segurança
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Content Security Policy (ajuste conforme necessário)
if (!DEBUG_MODE) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;");
}

// Incluir sistema de logs
require_once __DIR__ . '/logger.php';

// Conexão com o Banco de Dados
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                Logger::error("Erro de conexão com banco de dados", ['error' => $e->getMessage()]);
            }
            die("Erro de conexão com o banco de dados. Verifique as configurações.");
        }
    }
    
    return $conn;
}

// Função para verificar se usuário está logado
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Função para verificar se é admin geral
function isAdminGeral() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] == 1;
}

// Função para redirecionar
function redirect($url) {
    // Se a URL não começar com http, adicionar SITE_URL
    if (strpos($url, 'http') !== 0) {
        $url = SITE_URL . '/' . ltrim($url, '/');
    }
    header("Location: " . $url);
    exit();
}

// Função para sanitizar input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Função para formatar valor monetário
function formatMoney($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// Função para formatar data brasileira
function formatDateBR($date) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date('d/m/Y', $timestamp);
}

// Função para formatar data e hora brasileira
function formatDateTimeBR($datetime) {
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    return date('d/m/Y H:i:s', $timestamp);
}

// Função para converter número BR para float
function numberToFloat($number) {
    $number = str_replace('.', '', $number);
    $number = str_replace(',', '.', $number);
    return floatval($number);
}

// Função para obter tipo de usuário
function getUserType($type) {
    switch ($type) {
        case 1:
            return 'Admin. Geral';
        case 2:
            return 'Admin. Estabelecimento';
        case 3:
            return 'Gerente Estabelecimento';
        case 4:
            return 'Operador';
        default:
            return 'Tipo inválido';
    }
}

// Função para obter método de pagamento
function getPaymentMethod($method) {
    switch ($method) {
        case 'pix':
            return 'PIX';
        case 'credit':
            return 'Crédito';
        case 'debit':
            return 'Débito';
        default:
            return $method;
    }
}

// Função para obter classe CSS do status do pedido
function getOrderStatusClass($status) {
    switch ($status) {
        case 'SUCCESSFUL':
            return 'success';
        case 'PENDING':
            return 'warning';
        case 'CANCELLED':
        case 'FAILED':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Função para obter classe CSS do status da TAP
function getTapStatusClass($tap) {
    if (!$tap['status']) {
        return 'secondary';
    }
    $volume_atual = $tap['volume'] - $tap['volume_consumido'];
    if ($volume_atual > $tap['volume_critico']) {
        return 'success';
    }
    if ($volume_atual == 0) {
        return 'danger';
    }
    return 'warning';
}

// Função para calcular tempo de sessão
function getSessionTime() {
    if (isset($_SESSION['login_time'])) {
        $diff = time() - $_SESSION['login_time'];
        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);
        return sprintf('%02d:%02d', $hours, $minutes);
    }
    return '00:00';
}

// Função simplificada de detecção de URL (fallback)
function detectSiteURL() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Detectar caminho base
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base_path = dirname($script_name);
    $base_path = rtrim(str_replace('\\', '/', $base_path), '/');
    
    // Remover /includes se presente
    $base_path = str_replace('/includes', '', $base_path);
    
    return $protocol . $host . $base_path;
}

// Funções de segurança CSRF
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// Log da URL detectada (apenas em debug)
if (DEBUG_MODE) {
    Logger::debug("Configuração carregada", [
        'SITE_URL' => SITE_URL,
        'DEBUG_MODE' => DEBUG_MODE,
        'SESSION_TIMEOUT' => SESSION_TIMEOUT
    ]);
}
?>
