<?php
/**
 * Visualizador de Logs do Sistema
 * Permite visualizar logs de Royalties, Stripe, Cora e E-mail em tempo real
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

// Apenas Admin Geral pode acessar
if (!isAdminGeral()) {
    header('Location: ../index.php');
    exit;
}

$modulo = $_GET['modulo'] ?? 'royalties';
$linhas = intval($_GET['linhas'] ?? 100);
$nivel = $_GET['nivel'] ?? 'all';

// Definir arquivo de log baseado no módulo
$mes_ano = date('Y-m');
$arquivos_log = [
    'royalties' => "../logs/royalties_{$mes_ano}.log",
    'stripe' => "../logs/stripe_{$mes_ano}.log",
    'cora' => "../logs/cora_{$mes_ano}.log",
    'email' => "../logs/email_{$mes_ano}.log",
    'payments' => "../logs/paymentslogs.log"
];

$arquivo_log = $arquivos_log[$modulo] ?? $arquivos_log['royalties'];

// Ler arquivo de log
$logs = [];
if (file_exists($arquivo_log)) {
    $conteudo = file($arquivo_log, FILE_IGNORE_NEW_LINES);
    $conteudo = array_reverse($conteudo); // Mais recentes primeiro
    
    foreach ($conteudo as $linha) {
        // Filtrar por nível se necessário
        if ($nivel !== 'all') {
            if (stripos($linha, "[$nivel]") === false) {
                continue;
            }
        }
        
        $logs[] = $linha;
        
        if (count($logs) >= $linhas) {
            break;
        }
    }
}

// Função para colorir logs
function colorirLog($linha) {
    if (stripos($linha, '[ERROR]') !== false) {
        return '<span class="log-error">' . htmlspecialchars($linha) . '</span>';
    } elseif (stripos($linha, '[WARNING]') !== false) {
        return '<span class="log-warning">' . htmlspecialchars($linha) . '</span>';
    } elseif (stripos($linha, '[SUCCESS]') !== false) {
        return '<span class="log-success">' . htmlspecialchars($linha) . '</span>';
    } elseif (stripos($linha, '[INFO]') !== false) {
        return '<span class="log-info">' . htmlspecialchars($linha) . '</span>';
    } elseif (stripos($linha, '[DEBUG]') !== false) {
        return '<span class="log-debug">' . htmlspecialchars($linha) . '</span>';
    } else {
        return '<span class="log-default">' . htmlspecialchars($linha) . '</span>';
    }
}
?>

<style>
.log-container {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 20px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.6;
    max-height: 70vh;
    overflow-y: auto;
}

.log-error { color: #f48771; font-weight: bold; }
.log-warning { color: #dcdcaa; }
.log-success { color: #4ec9b0; font-weight: bold; }
.log-info { color: #9cdcfe; }
.log-debug { color: #808080; }
.log-default { color: #d4d4d4; }

.log-line {
    padding: 4px 0;
    border-bottom: 1px solid #333;
}

.log-line:hover {
    background: #2d2d2d;
}

.filter-bar {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.badge-modulo {
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.badge-royalties { background: #007bff; color: white; }
.badge-stripe { background: #635bff; color: white; }
.badge-cora { background: #00a868; color: white; }
.badge-email { background: #dc3545; color: white; }
.badge-payments { background: #fd7e14; color: white; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-alt"></i> Visualizador de Logs</h2>
        <div>
            <button class="btn btn-sm btn-primary" onclick="location.reload()">
                <i class="fas fa-sync"></i> Atualizar
            </button>
            <button class="btn btn-sm btn-danger" onclick="limparLogs()">
                <i class="fas fa-trash"></i> Limpar Logs
            </button>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="stats-card">
                <h6 class="text-muted mb-2">Total de Linhas</h6>
                <h3><?= count($logs) ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <h6 class="text-muted mb-2">Erros</h6>
                <h3 class="text-danger"><?= count(array_filter($logs, fn($l) => stripos($l, '[ERROR]') !== false)) ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <h6 class="text-muted mb-2">Avisos</h6>
                <h3 class="text-warning"><?= count(array_filter($logs, fn($l) => stripos($l, '[WARNING]') !== false)) ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <h6 class="text-muted mb-2">Sucessos</h6>
                <h3 class="text-success"><?= count(array_filter($logs, fn($l) => stripos($l, '[SUCCESS]') !== false)) ?></h3>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filter-bar">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Módulo</label>
                <select name="modulo" class="form-select" onchange="this.form.submit()">
                    <option value="royalties" <?= $modulo === 'royalties' ? 'selected' : '' ?>>
                        Royalties
                    </option>
                    <option value="stripe" <?= $modulo === 'stripe' ? 'selected' : '' ?>>
                        Stripe
                    </option>
                    <option value="cora" <?= $modulo === 'cora' ? 'selected' : '' ?>>
                        Banco Cora
                    </option>
                    <option value="email" <?= $modulo === 'email' ? 'selected' : '' ?>>
                        E-mail
                    </option>
                    <option value="payments" <?= $modulo === 'payments' ? 'selected' : '' ?>>
                        Pagamentos / SumUp
                    </option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Nível</label>
                <select name="nivel" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $nivel === 'all' ? 'selected' : '' ?>>Todos</option>
                    <option value="ERROR" <?= $nivel === 'ERROR' ? 'selected' : '' ?>>Apenas Erros</option>
                    <option value="WARNING" <?= $nivel === 'WARNING' ? 'selected' : '' ?>>Apenas Avisos</option>
                    <option value="SUCCESS" <?= $nivel === 'SUCCESS' ? 'selected' : '' ?>>Apenas Sucessos</option>
                    <option value="INFO" <?= $nivel === 'INFO' ? 'selected' : '' ?>>Apenas Info</option>
                    <option value="DEBUG" <?= $nivel === 'DEBUG' ? 'selected' : '' ?>>Apenas Debug</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Número de Linhas</label>
                <select name="linhas" class="form-select" onchange="this.form.submit()">
                    <option value="50" <?= $linhas === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $linhas === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $linhas === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $linhas === 500 ? 'selected' : '' ?>>500</option>
                    <option value="1000" <?= $linhas === 1000 ? 'selected' : '' ?>>1000</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div>
                    <span class="badge-modulo badge-<?= $modulo ?>">
                        <?= strtoupper($modulo) ?>
                    </span>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Container de Logs -->
    <div class="log-container" id="logContainer">
        <?php if (empty($logs)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <p>Nenhum log encontrado para este módulo.</p>
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-line"><?= colorirLog($log) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="text-center mt-3 text-muted">
        <small>
            <i class="fas fa-info-circle"></i> 
            Arquivo: <?= basename($arquivo_log) ?> | 
            Atualização automática a cada 5 segundos
        </small>
    </div>
</div>

<script>
// Auto-atualização a cada 5 segundos
setInterval(function() {
    location.reload();
}, 5000);

// Limpar logs
function limparLogs() {
    if (!confirm('Tem certeza que deseja LIMPAR todos os logs deste módulo?')) {
        return;
    }
    
    fetch('ajax/limpar_logs.php?modulo=<?= $modulo ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Logs limpos com sucesso!');
                location.reload();
            } else {
                alert('Erro ao limpar logs: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao limpar logs');
        });
}

// Auto-scroll para o topo
document.getElementById('logContainer').scrollTop = 0;
</script>

<?php require_once '../includes/footer.php'; ?>
