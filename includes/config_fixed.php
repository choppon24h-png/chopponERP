<?php
/**
 * Configurações do Sistema Chopp On Tap
 * Versão 3.0.1 - Correção de caminhos
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

// Detectar URL automaticamente (VERSÃO SIMPLIFICADA)
function detectSiteURL() {
    // Detectar protocolo
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
    
    // Detectar host
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Detectar caminho base
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Remover /admin/* ou /api/* do caminho
    $path = preg_replace('#/(admin|api)/.*$#', '', $script);
    
    // Remover arquivos raiz
    $path = preg_replace('#/(index|install|test_url|update_password|testebd)\.php$#', '', $path);
    
    // Pegar apenas o diretório
    $path = dirname($path);
    
    // Normalizar
    $path = str_replace('\\', '/', $path);
    $path = rtrim($path, '/');
    
    // Se for raiz, deixar vazio
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
define('SUMUP_CHECKOUT_URL', 'https://api.sumup.com/v0.1/checkouts');
define('SUMUP_MERCHANT_URL', 'https://api.sumup.com/v0.1/merchants/');

// Configurações JWT
define('JWT_SECRET', 'chopp_on_tap_secret_key_2025');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRATION', 86400); // 24 horas

// Função de conexão com o banco de dados
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $conn = new PDO($dsn, DB_USER, DB_PASS);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die("Erro de conexão: " . $e->getMessage());
            } else {
                die("Erro ao conectar ao banco de dados.");
            }
        }
    }
    
    return $conn;
}

// Função auxiliar para sanitizar inputs
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Função para formatar moeda
function formatMoney($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// Função para formatar data
function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}

// Função para formatar data e hora
function formatDateTime($datetime) {
    return date('d/m/Y H:i:s', strtotime($datetime));
}

// Inicializar Logger (se existir)
if (file_exists(__DIR__ . '/logger.php')) {
    require_once __DIR__ . '/logger.php';
    Logger::getInstance();
    
    // Log da URL detectada em modo debug
    if (DEBUG_MODE) {
        Logger::getInstance()->log('debug', "SITE_URL detectado: " . SITE_URL);
        Logger::getInstance()->log('debug', "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'N/A'));
        Logger::getInstance()->log('debug', "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A'));
        Logger::getInstance()->log('debug', "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
    }
}
?>
