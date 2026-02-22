<?php
/**
 * Visualizador de Logs do Sistema
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Verificar se está logado
if (!isLoggedIn()) {
    redirect('../index.php');
}

// Apenas admin geral pode ver logs
if (!isAdminGeral()) {
    redirect('dashboard.php');
}

$logFiles = [
    'system.log' => 'Sistema Geral',
    'paymentslogs.log' => 'Pagamentos / SumUp',
    'auth.log' => 'Autenticação',
    'security.log' => 'Segurança',
    'api.log' => 'API REST',
    'webhook.log' => 'Webhooks SumUp',
    'errors.log' => 'Erros',
    'debug.log' => 'Debug',
    'sql.log' => 'Consultas SQL'
];

$selectedLog = $_GET['log'] ?? 'auth.log';
$lines = $_GET['lines'] ?? 100;

$logPath = __DIR__ . '/../logs/' . $selectedLog;
$logContent = '';

if (file_exists($logPath)) {
    $allLines = file($logPath);
    $totalLines = count($allLines);
    $logContent = implode('', array_slice($allLines, -$lines));
} else {
    $logContent = "Arquivo de log não encontrado ou vazio.";
    $totalLines = 0;
}

include '../includes/header.php';
?>

<div class="content-wrapper">
    <div class="page-header">
        <h1>📋 Logs do Sistema</h1>
        <p>Visualize e monitore eventos do sistema</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Selecionar Log</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                <?php foreach ($logFiles as $file => $name): ?>
                    <a href="?log=<?php echo $file; ?>&lines=<?php echo $lines; ?>" 
                       class="btn <?php echo $selectedLog === $file ? 'btn-primary' : 'btn-secondary'; ?>">
                        <?php echo $name; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
                <label>Últimas linhas:</label>
                <select onchange="window.location.href='?log=<?php echo $selectedLog; ?>&lines=' + this.value" style="padding: 8px;">
                    <option value="50" <?php echo $lines == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $lines == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="200" <?php echo $lines == 200 ? 'selected' : ''; ?>>200</option>
                    <option value="500" <?php echo $lines == 500 ? 'selected' : ''; ?>>500</option>
                    <option value="1000" <?php echo $lines == 1000 ? 'selected' : ''; ?>>1000</option>
                </select>
                <span style="color: #666;">Total: <?php echo $totalLines; ?> linhas</span>
                <a href="?log=<?php echo $selectedLog; ?>&lines=<?php echo $lines; ?>&refresh=1" class="btn btn-secondary" style="margin-left: auto;">
                    🔄 Atualizar
                </a>
            </div>

            <div style="background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 4px; overflow-x: auto; max-height: 600px; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6;">
                <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($logContent); ?></pre>
            </div>

            <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 4px; color: #856404;">
                <strong>💡 Dica:</strong> Os logs são atualizados em tempo real. Use esta página para diagnosticar problemas de autenticação, API e webhooks.
            </div>

            <?php if (file_exists($logPath)): ?>
            <div style="margin-top: 20px;">
                <a href="?log=<?php echo $selectedLog; ?>&lines=<?php echo $lines; ?>&download=1" class="btn btn-primary">
                    ⬇️ Baixar Log Completo
                </a>
                <button onclick="if(confirm('Tem certeza que deseja limpar este log?')) { window.location.href='?log=<?php echo $selectedLog; ?>&clear=1'; }" class="btn btn-danger">
                    🗑️ Limpar Log
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top: 20px;">
        <div class="card-header">
            <h3>📊 Estatísticas dos Logs</h3>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php foreach ($logFiles as $file => $name): 
                    $filePath = __DIR__ . '/../logs/' . $file;
                    $exists = file_exists($filePath);
                    $size = $exists ? filesize($filePath) : 0;
                    $sizeFormatted = $size > 1024 * 1024 ? round($size / (1024 * 1024), 2) . ' MB' : round($size / 1024, 2) . ' KB';
                    $lineCount = $exists ? count(file($filePath)) : 0;
                ?>
                <div style="padding: 15px; background: <?php echo $exists ? '#f8f9fa' : '#fee'; ?>; border-radius: 4px; border-left: 4px solid <?php echo $exists ? '#0066CC' : '#dc3545'; ?>;">
                    <div style="font-weight: 600; margin-bottom: 5px;"><?php echo $name; ?></div>
                    <div style="font-size: 12px; color: #666;">
                        <?php if ($exists): ?>
                            <?php echo number_format($lineCount); ?> linhas<br>
                            <?php echo $sizeFormatted; ?>
                        <?php else: ?>
                            Arquivo não existe
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Processar download
if (isset($_GET['download']) && file_exists($logPath)) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $selectedLog . '"');
    readfile($logPath);
    exit;
}

// Processar limpeza
if (isset($_GET['clear']) && file_exists($logPath)) {
    file_put_contents($logPath, '');
    Logger::info("Log limpo pelo administrador", ['log_file' => $selectedLog, 'user_id' => $_SESSION['user_id']]);
    redirect('logs.php?log=' . $selectedLog);
}

include '../includes/footer.php';
?>
