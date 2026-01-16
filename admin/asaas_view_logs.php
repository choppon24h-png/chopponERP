<?php
/**
 * Visualizador de Logs - Asaas
 * Exibe logs de debug e erros do processamento Asaas
 */

// Configurações de erro
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$page_title = 'Logs Asaas - Debug';
$current_page = 'asaas_logs';

// Incluir arquivos necessários
try {
    require_once '../includes/config.php';
    require_once '../includes/auth.php';
    requireAdminGeral();
} catch (Exception $e) {
    die('Erro ao carregar sistema: ' . $e->getMessage());
}

$conn = getDBConnection();

// Processar ações
$action = $_GET['action'] ?? '';

if ($action === 'clear_db_logs') {
    try {
        $conn->exec("TRUNCATE TABLE asaas_logs");
        $success_msg = "Logs do banco de dados limpos com sucesso!";
    } catch (Exception $e) {
        $error_msg = "Erro ao limpar logs: " . $e->getMessage();
    }
}

// Buscar logs do banco de dados
$db_logs = [];
try {
    $stmt = $conn->query("
        SELECT * FROM asaas_logs 
        ORDER BY data_criacao DESC 
        LIMIT 500
    ");
    $db_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $db_logs_error = "Erro ao buscar logs do banco: " . $e->getMessage();
}

// Ler error_log do PHP (filtrar apenas logs [ASAAS])
$error_log_path = __DIR__ . '/../logs/php_errors.log';
$php_error_logs = [];

if (file_exists($error_log_path)) {
    $lines = file($error_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines); // Mais recentes primeiro
    
    foreach ($lines as $line) {
        if (stripos($line, '[ASAAS') !== false) {
            $php_error_logs[] = $line;
            if (count($php_error_logs) >= 200) break; // Limitar a 200 linhas
        }
    }
} else {
    $php_error_logs[] = "Arquivo de log não encontrado: {$error_log_path}";
}

// Incluir header
include '../includes/header.php';
?>

<style>
    .log-container {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .log-entry {
        background: white;
        border-left: 4px solid #6c757d;
        padding: 12px;
        margin: 8px 0;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .log-entry.success {
        border-left-color: #28a745;
        background: #f1f9f3;
    }
    
    .log-entry.error {
        border-left-color: #dc3545;
        background: #fcf1f2;
    }
    
    .log-entry.debug {
        border-left-color: #007bff;
        background: #f1f6fc;
    }
    
    .log-timestamp {
        color: #6c757d;
        font-size: 11px;
        display: block;
        margin-bottom: 4px;
    }
    
    .log-message {
        color: #212529;
        word-wrap: break-word;
    }
    
    .tabs {
        display: flex;
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 20px;
    }
    
    .tab {
        padding: 12px 24px;
        cursor: pointer;
        background: #f8f9fa;
        border: none;
        border-bottom: 3px solid transparent;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .tab:hover {
        background: #e9ecef;
    }
    
    .tab.active {
        background: white;
        border-bottom-color: #007bff;
        color: #007bff;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .action-buttons {
        margin: 20px 0;
        display: flex;
        gap: 10px;
    }
    
    .btn-refresh {
        background: #007bff;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 500;
    }
    
    .btn-clear {
        background: #dc3545;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 500;
    }
    
    .btn-refresh:hover {
        background: #0056b3;
    }
    
    .btn-clear:hover {
        background: #c82333;
    }
    
    .alert {
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .empty-logs {
        text-align: center;
        padding: 40px;
        color: #6c757d;
    }
    
    .log-stats {
        background: #e9ecef;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-around;
    }
    
    .stat-item {
        text-align: center;
    }
    
    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #007bff;
    }
    
    .stat-label {
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-bug"></i> Logs de Debug - Asaas
                    </h3>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($success_msg)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_msg)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <button class="btn-refresh" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Atualizar Logs
                        </button>
                        <button class="btn-clear" onclick="if(confirm('Tem certeza que deseja limpar os logs do banco de dados?')) { window.location.href='?action=clear_db_logs'; }">
                            <i class="fas fa-trash"></i> Limpar Logs do Banco
                        </button>
                    </div>
                    
                    <div class="log-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo count($php_error_logs); ?></div>
                            <div class="stat-label">Logs PHP (Error Log)</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo count($db_logs); ?></div>
                            <div class="stat-label">Logs Banco de Dados</div>
                        </div>
                    </div>
                    
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('php')">
                            <i class="fas fa-file-alt"></i> Error Log PHP
                        </button>
                        <button class="tab" onclick="switchTab('db')">
                            <i class="fas fa-database"></i> Logs do Banco
                        </button>
                    </div>
                    
                    <!-- Tab: Error Log PHP -->
                    <div id="tab-php" class="tab-content active">
                        <div class="log-container">
                            <h5><i class="fas fa-file-code"></i> PHP Error Log (Filtrado: [ASAAS])</h5>
                            <p class="text-muted">Arquivo: <?php echo $error_log_path; ?></p>
                            
                            <?php if (empty($php_error_logs)): ?>
                                <div class="empty-logs">
                                    <i class="fas fa-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                                    <p>Nenhum log [ASAAS] encontrado no error_log do PHP.</p>
                                    <p class="text-muted">Tente processar um pagamento Asaas para gerar logs.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($php_error_logs as $log): ?>
                                    <?php
                                        $class = 'debug';
                                        if (stripos($log, 'ERROR') !== false || stripos($log, 'ERRO') !== false) {
                                            $class = 'error';
                                        } elseif (stripos($log, 'SUCCESS') !== false || stripos($log, 'SUCESSO') !== false) {
                                            $class = 'success';
                                        }
                                    ?>
                                    <div class="log-entry <?php echo $class; ?>">
                                        <div class="log-message"><?php echo htmlspecialchars($log); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tab: Logs do Banco -->
                    <div id="tab-db" class="tab-content">
                        <div class="log-container">
                            <h5><i class="fas fa-database"></i> Logs do Banco de Dados (Tabela: asaas_logs)</h5>
                            
                            <?php if (isset($db_logs_error)): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $db_logs_error; ?>
                                </div>
                            <?php elseif (empty($db_logs)): ?>
                                <div class="empty-logs">
                                    <i class="fas fa-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                                    <p>Nenhum log encontrado na tabela asaas_logs.</p>
                                    <p class="text-muted">Os logs são salvos automaticamente durante o processamento.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($db_logs as $log): ?>
                                    <?php
                                        $class = 'debug';
                                        $status = strtoupper($log['status'] ?? '');
                                        if ($status === 'ERROR' || $status === 'ERRO') {
                                            $class = 'error';
                                        } elseif ($status === 'SUCCESS' || $status === 'SUCESSO') {
                                            $class = 'success';
                                        }
                                    ?>
                                    <div class="log-entry <?php echo $class; ?>">
                                        <span class="log-timestamp">
                                            <?php echo $log['data_criacao']; ?> | 
                                            Operação: <?php echo htmlspecialchars($log['operacao']); ?> | 
                                            Status: <?php echo htmlspecialchars($log['status']); ?>
                                        </span>
                                        <div class="log-message">
                                            <strong>Requisição:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($log['dados_requisicao'] ?? 'N/A')); ?>
                                            <br><br>
                                            <strong>Resposta:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($log['dados_resposta'] ?? 'N/A')); ?>
                                            <?php if (!empty($log['mensagem_erro'])): ?>
                                                <br><br>
                                                <strong style="color: #dc3545;">Erro:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($log['mensagem_erro'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Remover classe active de todas as tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Adicionar classe active na tab clicada
    event.target.closest('.tab').classList.add('active');
    document.getElementById('tab-' + tabName).classList.add('active');
}

// Auto-refresh a cada 10 segundos (opcional)
// setInterval(() => location.reload(), 10000);
</script>

<?php include '../includes/footer.php'; ?>
