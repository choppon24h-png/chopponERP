<?php
/**
 * Configurações do Sistema Chopp On Tap
 * Versão 3.0.1 - Correção CSS Telegram
 */

// Modo Debug (ative para ver logs detalhados)
define('DEBUG_MODE', true);

// Configurações do Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'inlaud99_choppontap');
define('DB_USER', 'inlaud99_admin');
define('DB_PASS', 'Admin259087@');
define('DB_CHARSET', 'utf8mb4');

// Configurações do Sistema
define('SITE_NAME', 'Chopp On Tap');

// Detectar URL automaticamente
function detectSiteURL() {
    // Detectar protocolo
    $protocol = 'http://';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https://';
    } elseif ($_SERVER['SERVER_PORT'] == 443) {
        $protocol = 'https://';
    }
    
    // Detectar host
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Detectar caminho base
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Remover arquivos do caminho
    $path = str_replace([
        '/index.php',
        '/test_url.php',
        '/update_password.php',
        '/install.php',
        '/admin/dashboard.php',
        '/admin/bebidas.php',
        '/admin/taps.php',
        '/admin/pagamentos.php',
        '/admin/pedidos.php',
        '/admin/usuarios.php',
        '/admin/estabelecimentos.php',
        '/admin/logs.php',
        '/admin/telegram.php',
        '/admin/logout.php'
    ], '', $script);
    
    // Pegar apenas o diretório
    $path = dirname($path);
    
    // Limpar barras
    $path = str_replace('\\', '/', $path);
    $path = rtrim($path, '/');
    
    // Se o path for só ".", remover
    if ($path === '.' || $path === '/') {
        $path = '';
    }
    
    return $protocol . $host . $path;
}

define('SITE_URL', detectSiteURL());

define('SYSTEM_VERSION', 'v3.0.1');

// Configurações de Sessão
define('SESSION_TIMEOUT', 7200); // 2 horas em segundos

// Configurações SumUp
define('SUMUP_TOKEN', 'sup_sk_8vNpSEJPVudqJrWPdUlomuE3EfVofw1bL');
define('SUMUP_MERCHANT_CODE', 'MCTSYDUE');
define('SUMUP_CHECKOUT_URL', 'https://api.sumup.com/v0.1/checkouts/');
define('SUMUP_MERCHANT_URL', 'https://api.sumup.com/v0.1/merchants/');

// Configurações JWT
define('JWT_SECRET', 'teste');
define('JWT_ALGORITHM', 'HS256');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
            Logger::error("Erro de conexão com banco de dados", ['error' => $e->getMessage()]);
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

// Log da URL detectada (apenas em debug)
if (DEBUG_MODE) {
    Logger::debug("URL detectada", [
        'SITE_URL' => SITE_URL,
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'N/A',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'N/A',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'N/A'
    ]);
}
// NOTA: Sem fechamento ?> intencional — evita emissão de whitespace/newline
// que corromperia respostas JSON das APIs.
