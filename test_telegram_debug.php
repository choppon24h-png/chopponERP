<?php
/**
 * ========================================
 * DEBUG AVANÇADO - Telegram CRON
 * ========================================
 * Identifica causa exata do erro 500
 * Testa cada componente isoladamente
 */

// Capturar TODOS os erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);

// Função de log segura
function debugLog($message, $data = null) {
    echo "<div style='margin: 10px 0; padding: 10px; background: #f0f0f0; border-left: 4px solid #333;'>";
    echo "<strong>" . htmlspecialchars($message) . "</strong>";
    if ($data !== null) {
        echo "<pre style='margin-top: 5px; background: white; padding: 10px;'>";
        print_r($data);
        echo "</pre>";
    }
    echo "</div>";
    flush();
    ob_flush();
}

// Iniciar buffer de saída
ob_start();

?>
<!DOCTYPE html>
<html>
<head>
    <title>🔍 DEBUG Avançado - Telegram CRON</title>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        h1 { color: #333; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .section { margin: 20px 0; padding: 15px; background: white; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<h1>🔍 DEBUG AVANÇADO - Telegram CRON</h1>
<p><strong>Data/Hora:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>

<?php

// ========================================
// TESTE 1: Informações do Servidor
// ========================================
echo "<div class='section'>";
echo "<h2>📊 TESTE 1: Informações do Servidor</h2>";

debugLog("Versão PHP", phpversion());
debugLog("SAPI", php_sapi_name());
debugLog("Sistema Operacional", PHP_OS);
debugLog("Diretório Atual", getcwd());
debugLog("Arquivo Atual", __FILE__);
debugLog("Diretório do Arquivo", __DIR__);

// Verificar extensões necessárias
$extensoes = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
$extensoes_status = [];
foreach ($extensoes as $ext) {
    $extensoes_status[$ext] = extension_loaded($ext) ? '✓ OK' : '✗ FALTANDO';
}
debugLog("Extensões PHP", $extensoes_status);

echo "</div>";

// ========================================
// TESTE 2: Detecção de Caminho Base
// ========================================
echo "<div class='section'>";
echo "<h2>📁 TESTE 2: Detecção de Caminho Base</h2>";

$base_path = dirname(__DIR__);
debugLog("Base Path Inicial (dirname(__DIR__))", $base_path);

// Testar diferentes métodos
$metodos = [
    'dirname(__DIR__)' => dirname(__DIR__),
    'dirname(__FILE__, 2)' => dirname(__FILE__, 2),
    'realpath(dirname(__FILE__) . \'/..\')' => realpath(dirname(__FILE__) . '/..'),
    'realpath(__DIR__ . \'/..\')' => realpath(__DIR__ . '/..'),
    '$_SERVER[\'DOCUMENT_ROOT\']' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A'
];

foreach ($metodos as $nome => $caminho) {
    $config_existe = file_exists($caminho . '/includes/config.php');
    $status = $config_existe ? '<span class="success">✓ config.php ENCONTRADO</span>' : '<span class="error">✗ config.php NÃO ENCONTRADO</span>';
    echo "<p><strong>{$nome}:</strong> {$caminho} - {$status}</p>";
}

// Escolher o melhor caminho
if (!file_exists($base_path . '/includes/config.php')) {
    $base_path = dirname(__FILE__, 2);
    if (!file_exists($base_path . '/includes/config.php')) {
        $base_path = realpath(dirname(__FILE__) . '/..');
    }
}

debugLog("Base Path Escolhido", $base_path);
debugLog("config.php existe?", file_exists($base_path . '/includes/config.php') ? 'SIM' : 'NÃO');

echo "</div>";

// ========================================
// TESTE 3: Incluir config.php
// ========================================
echo "<div class='section'>";
echo "<h2>⚙️ TESTE 3: Incluir config.php</h2>";

$config_file = $base_path . '/includes/config.php';

if (!file_exists($config_file)) {
    echo "<p class='error'>✗ ERRO: Arquivo config.php não encontrado em: {$config_file}</p>";
    echo "<p><strong>Arquivos no diretório includes:</strong></p>";
    $includes_dir = $base_path . '/includes';
    if (is_dir($includes_dir)) {
        $arquivos = scandir($includes_dir);
        debugLog("Arquivos em /includes", $arquivos);
    } else {
        echo "<p class='error'>Diretório /includes não existe!</p>";
    }
    echo "</div></body></html>";
    exit;
}

try {
    require_once $config_file;
    echo "<p class='success'>✓ config.php incluído com sucesso</p>";
} catch (Throwable $e) {
    echo "<p class='error'>✗ ERRO ao incluir config.php:</p>";
    debugLog("Erro", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "</div></body></html>";
    exit;
}

echo "</div>";

// ========================================
// TESTE 4: Verificar Constantes
// ========================================
echo "<div class='section'>";
echo "<h2>🔑 TESTE 4: Constantes Definidas</h2>";

$constantes_esperadas = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'TELEGRAM_CRON_KEY', 'TELEGRAM_NOTIFICATIONS_ENABLED'
];

$constantes_definidas = [];
foreach ($constantes_esperadas as $const) {
    if (defined($const)) {
        $valor = constant($const);
        // Mascarar dados sensíveis
        if (in_array($const, ['DB_PASS', 'TELEGRAM_CRON_KEY'])) {
            $valor = substr($valor, 0, 10) . '...' . substr($valor, -5);
        }
        $constantes_definidas[$const] = $valor;
        echo "<p class='success'>✓ {$const} = {$valor}</p>";
    } else {
        echo "<p class='error'>✗ {$const} NÃO DEFINIDA</p>";
    }
}

echo "</div>";

// ========================================
// TESTE 5: Conexão com Banco
// ========================================
echo "<div class='section'>";
echo "<h2>🗄️ TESTE 5: Conexão com Banco de Dados</h2>";

try {
    if (function_exists('getDBConnection')) {
        echo "<p class='success'>✓ Função getDBConnection() existe</p>";
        
        $conn = getDBConnection();
        echo "<p class='success'>✓ Conexão estabelecida com sucesso</p>";
        debugLog("Tipo de Conexão", get_class($conn));
        
        // Testar query simples
        $stmt = $conn->query("SELECT DATABASE() as db_name, NOW() as current_time");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        debugLog("Banco Conectado", $result);
        
    } else {
        echo "<p class='error'>✗ Função getDBConnection() NÃO existe</p>";
        echo "<p>Tentando criar conexão PDO diretamente...</p>";
        
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $conn = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        echo "<p class='success'>✓ Conexão PDO direta estabelecida</p>";
    }
} catch (Throwable $e) {
    echo "<p class='error'>✗ ERRO na conexão:</p>";
    debugLog("Erro", [
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

echo "</div>";

// ========================================
// TESTE 6: Verificar Tabela telegram_config
// ========================================
echo "<div class='section'>";
echo "<h2>📋 TESTE 6: Tabela telegram_config</h2>";

try {
    $stmt = $conn->query("SHOW TABLES LIKE 'telegram_config'");
    $tabela_existe = $stmt->rowCount() > 0;
    
    if ($tabela_existe) {
        echo "<p class='success'>✓ Tabela telegram_config existe</p>";
        
        // Verificar estrutura
        $stmt = $conn->query("DESCRIBE telegram_config");
        $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        debugLog("Colunas da Tabela", $colunas);
        
        // Verificar registros
        $stmt = $conn->query("SELECT COUNT(*) as total FROM telegram_config");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "<p>Total de registros: <strong>{$total}</strong></p>";
        
        // Verificar registros ativos
        $stmt = $conn->query("SELECT COUNT(*) as total FROM telegram_config WHERE status = 1");
        $ativos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "<p>Registros ativos: <strong>{$ativos}</strong></p>";
        
    } else {
        echo "<p class='error'>✗ Tabela telegram_config NÃO existe</p>";
        echo "<p class='warning'>⚠ Execute o SQL de criação da tabela!</p>";
    }
} catch (Throwable $e) {
    echo "<p class='error'>✗ ERRO ao verificar tabela:</p>";
    debugLog("Erro", $e->getMessage());
}

echo "</div>";

// ========================================
// TESTE 7: Incluir TelegramNotifier.php
// ========================================
echo "<div class='section'>";
echo "<h2>📱 TESTE 7: Classe TelegramNotifier</h2>";

$notifier_file = $base_path . '/includes/TelegramNotifier.php';

if (!file_exists($notifier_file)) {
    echo "<p class='error'>✗ Arquivo TelegramNotifier.php não encontrado em: {$notifier_file}</p>";
} else {
    echo "<p class='success'>✓ Arquivo TelegramNotifier.php encontrado</p>";
    debugLog("Tamanho do Arquivo", filesize($notifier_file) . ' bytes');
    
    try {
        require_once $notifier_file;
        echo "<p class='success'>✓ TelegramNotifier.php incluído com sucesso</p>";
        
        if (class_exists('TelegramNotifier')) {
            echo "<p class='success'>✓ Classe TelegramNotifier existe</p>";
            
            // Listar métodos
            $metodos = get_class_methods('TelegramNotifier');
            debugLog("Métodos da Classe (" . count($metodos) . ")", $metodos);
            
            // Verificar métodos esperados
            $metodos_esperados = ['verificarEstoqueMinimo', 'verificarContasPagar', 'verificarPromocoes'];
            foreach ($metodos_esperados as $metodo) {
                if (in_array($metodo, $metodos)) {
                    echo "<p class='success'>✓ Método {$metodo}() existe</p>";
                } else {
                    echo "<p class='error'>✗ Método {$metodo}() NÃO existe</p>";
                }
            }
            
        } else {
            echo "<p class='error'>✗ Classe TelegramNotifier NÃO foi definida</p>";
        }
        
    } catch (Throwable $e) {
        echo "<p class='error'>✗ ERRO ao incluir TelegramNotifier.php:</p>";
        debugLog("Erro", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

echo "</div>";

// ========================================
// TESTE 8: Instanciar TelegramNotifier
// ========================================
echo "<div class='section'>";
echo "<h2>🚀 TESTE 8: Instanciar TelegramNotifier</h2>";

try {
    if (isset($conn) && class_exists('TelegramNotifier')) {
        // Buscar primeiro estabelecimento ativo
        $stmt = $conn->query("SELECT estabelecimento_id FROM telegram_config WHERE status = 1 LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            $estabelecimento_id = $config['estabelecimento_id'];
            echo "<p>Tentando instanciar para estabelecimento ID: <strong>{$estabelecimento_id}</strong></p>";
            
            $notifier = new TelegramNotifier($conn, $estabelecimento_id);
            echo "<p class='success'>✓ TelegramNotifier instanciado com sucesso!</p>";
            
        } else {
            echo "<p class='warning'>⚠ Nenhuma configuração ativa encontrada</p>";
            echo "<p>Configure em: Admin → Integrações → Telegram</p>";
        }
    } else {
        echo "<p class='error'>✗ Pré-requisitos não atendidos</p>";
        if (!isset($conn)) echo "<p>- Conexão com banco não estabelecida</p>";
        if (!class_exists('TelegramNotifier')) echo "<p>- Classe TelegramNotifier não existe</p>";
    }
} catch (Throwable $e) {
    echo "<p class='error'>✗ ERRO ao instanciar:</p>";
    debugLog("Erro", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

echo "</div>";

// ========================================
// TESTE 9: Verificar Versão PHP e Funções
// ========================================
echo "<div class='section'>";
echo "<h2>🔧 TESTE 9: Compatibilidade PHP</h2>";

$php_version = phpversion();
echo "<p>Versão PHP: <strong>{$php_version}</strong></p>";

// Verificar se suporta dirname(__FILE__, 2)
if (version_compare($php_version, '7.0.0', '>=')) {
    echo "<p class='success'>✓ Suporta dirname() com 2 parâmetros (PHP 7.0+)</p>";
} else {
    echo "<p class='error'>✗ NÃO suporta dirname() com 2 parâmetros (PHP < 7.0)</p>";
}

// Verificar funções usadas
$funcoes = ['file_exists', 'require_once', 'date_default_timezone_set', 'json_encode', 'curl_init'];
foreach ($funcoes as $func) {
    if (function_exists($func)) {
        echo "<p class='success'>✓ Função {$func}() disponível</p>";
    } else {
        echo "<p class='error'>✗ Função {$func}() NÃO disponível</p>";
    }
}

echo "</div>";

// ========================================
// RESUMO FINAL
// ========================================
echo "<div class='section'>";
echo "<h2>📊 RESUMO FINAL</h2>";
echo "<p><strong>Se todos os testes passaram, o telegram_cron.php deveria funcionar.</strong></p>";
echo "<p>Se ainda houver erro 500, o problema pode estar em:</p>";
echo "<ul>";
echo "<li>Limite de memória PHP</li>";
echo "<li>Timeout de execução</li>";
echo "<li>Permissões de arquivo</li>";
echo "<li>Configuração do .htaccess</li>";
echo "<li>Erro de sintaxe não detectado</li>";
echo "</ul>";
echo "<p><strong>Próximo passo:</strong> Copie TODO este resultado e me envie para análise.</p>";
echo "</div>";

?>

</body>
</html>
