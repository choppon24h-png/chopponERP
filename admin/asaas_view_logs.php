<?php
/**
 * Visualizar Logs de Debug do Asaas
 * Exibe logs do error_log do PHP relacionados ao Asaas
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = getDBConnection();

// Buscar logs do banco de dados
$stmt = $conn->prepare("
    SELECT * FROM asaas_logs 
    ORDER BY data_criacao DESC 
    LIMIT 100
");
$stmt->execute();
$logs_db = $stmt->fetchAll();

// Tentar ler error_log do PHP
$error_log_path = ini_get('error_log');
if (empty($error_log_path)) {
    $error_log_path = $_SERVER['DOCUMENT_ROOT'] . '/error_log';
}

$error_logs = [];
if (file_exists($error_log_path) && is_readable($error_log_path)) {
    $lines = file($error_log_path);
    $lines = array_reverse($lines); // Mais recentes primeiro
    
    foreach ($lines as $line) {
        if (stripos($line, '[ASAAS') !== false) {
            $error_logs[] = $line;
            if (count($error_logs) >= 100) break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs Asaas - Debug</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .log-container { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .log-entry { padding: 10px; margin: 5px 0; border-left: 4px solid #ccc; background: #f9f9f9; }
        .log-entry.debug { border-left-color: #2196F3; }
        .log-entry.error { border-left-color: #f44336; background: #ffebee; }
        .log-entry.success { border-left-color: #4CAF50; }
        .timestamp { color: #666; font-size: 0.9em; }
        .message { margin-top: 5px; }
        .refresh-btn { 
            padding: 10px 20px; 
            background: #2196F3; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer;
            margin-bottom: 20px;
        }
        .refresh-btn:hover { background: #1976D2; }
        .clear-btn { 
            padding: 10px 20px; 
            background: #f44336; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer;
            margin-left: 10px;
        }
        .clear-btn:hover { background: #d32f2f; }
        .tab-container { margin-bottom: 20px; }
        .tab { 
            display: inline-block; 
            padding: 10px 20px; 
            background: #e0e0e0; 
            cursor: pointer; 
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
        }
        .tab.active { background: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <h1>🔍 Logs de Debug - Asaas</h1>
    
    <button class="refresh-btn" onclick="location.reload()">🔄 Atualizar</button>
    <button class="clear-btn" onclick="if(confirm('Limpar logs do banco de dados?')) location.href='?clear=1'">🗑️ Limpar Logs DB</button>
    
    <?php if (isset($_GET['clear'])): 
        $conn->exec("DELETE FROM asaas_logs");
        echo "<p style='color: green;'>✓ Logs do banco de dados limpos!</p>";
    endif; ?>
    
    <div class="tab-container">
        <div class="tab active" onclick="showTab('error-log')">Error Log PHP</div>
        <div class="tab" onclick="showTab('database-log')">Logs Banco de Dados</div>
    </div>
    
    <!-- Tab 1: Error Log PHP -->
    <div id="error-log" class="tab-content active">
        <div class="log-container">
            <h2>📄 Error Log do PHP (<?php echo count($error_logs); ?> entradas)</h2>
            <p><strong>Arquivo:</strong> <?php echo htmlspecialchars($error_log_path); ?></p>
            
            <?php if (empty($error_logs)): ?>
                <p style="color: #999;">Nenhum log do Asaas encontrado no error_log.</p>
                <p><strong>Dica:</strong> Tente processar um pagamento Asaas para gerar logs.</p>
            <?php else: ?>
                <?php foreach ($error_logs as $log): 
                    $class = 'debug';
                    if (stripos($log, '[ASAAS ERROR]') !== false) $class = 'error';
                    if (stripos($log, '[ASAAS DEBUG]') !== false && stripos($log, 'sucesso') !== false) $class = 'success';
                ?>
                    <div class="log-entry <?php echo $class; ?>">
                        <div class="message"><?php echo htmlspecialchars($log); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tab 2: Database Log -->
    <div id="database-log" class="tab-content">
        <div class="log-container">
            <h2>💾 Logs do Banco de Dados (<?php echo count($logs_db); ?> entradas)</h2>
            
            <?php if (empty($logs_db)): ?>
                <p style="color: #999;">Nenhum log no banco de dados.</p>
            <?php else: ?>
                <?php foreach ($logs_db as $log): 
                    $class = 'debug';
                    if ($log['status'] === 'erro') $class = 'error';
                    if ($log['status'] === 'sucesso') $class = 'success';
                ?>
                    <div class="log-entry <?php echo $class; ?>">
                        <div class="timestamp">
                            <?php echo date('d/m/Y H:i:s', strtotime($log['data_criacao'])); ?> - 
                            <strong><?php echo strtoupper($log['operacao']); ?></strong>
                        </div>
                        <div class="message">
                            <strong>Status:</strong> <?php echo htmlspecialchars($log['status']); ?><br>
                            <?php if (!empty($log['dados_requisicao'])): ?>
                                <strong>Requisição:</strong> <pre><?php echo htmlspecialchars($log['dados_requisicao']); ?></pre>
                            <?php endif; ?>
                            <?php if (!empty($log['dados_resposta'])): ?>
                                <strong>Resposta:</strong> <pre><?php echo htmlspecialchars($log['dados_resposta']); ?></pre>
                            <?php endif; ?>
                            <?php if (!empty($log['mensagem_erro'])): ?>
                                <strong style="color: red;">Erro:</strong> <?php echo htmlspecialchars($log['mensagem_erro']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function showTab(tabId) {
            // Esconder todas as tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar tab selecionada
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
