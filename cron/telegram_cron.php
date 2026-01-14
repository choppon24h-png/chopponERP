<?php
/**
 * ========================================
 * TELEGRAM CRON - Notificações Automáticas
 * ========================================
 * 
 * Script para execução via CRON (cron-job.org ou Hostgator)
 * Envia notificações automáticas via Telegram
 * 
 * @author ChopponERP Team
 * @version 2.0.0
 * @date 2026-01-12
 * 
 * CONFIGURAÇÃO CRON (Hostgator):
 * ================================
 * Painel cPanel → Cron Jobs
 * 
 * Comando:
 * /usr/local/bin/php /home/seu_usuario/public_html/cron/telegram_cron.php
 * 
 * Frequência Recomendada:
 * - Estoque: A cada 6 horas (0 */6 * * *)
 * - Contas: Diariamente às 8h (0 8 * * *)
 * - Promoções: Diariamente às 9h (0 9 * * *)
 * - Completo: Diariamente às 8h (0 8 * * *)
 * 
 * CONFIGURAÇÃO CRON (cron-job.org):
 * ==================================
 * URL: https://seu-dominio.com/cron/telegram_cron.php?key=SUA_CHAVE_SECRETA
 * Frequência: Diariamente às 08:00
 * 
 * SEGURANÇA:
 * ==========
 * - Defina TELEGRAM_CRON_KEY no config.php
 * - Acesse via: telegram_cron.php?key=SUA_CHAVE
 * - Sem chave válida = acesso negado
 */

// Definir timezone
date_default_timezone_set('America/Sao_Paulo');

// Caminho base do projeto
$base_path = dirname(__DIR__);

// Incluir arquivos necessários
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/TelegramNotifier.php';

// ========================================
// VALIDAÇÃO DE SEGURANÇA
// ========================================

/**
 * Validar chave de segurança
 * Previne execução não autorizada via browser
 */
function validarAcesso() {
    // Se executado via CLI (terminal), permitir
    if (php_sapi_name() === 'cli') {
        return true;
    }
    
    // Se executado via HTTP, exigir chave
    if (!defined('TELEGRAM_CRON_KEY') || empty(TELEGRAM_CRON_KEY)) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'error' => 'TELEGRAM_CRON_KEY não configurada. Defina no config.php'
        ]));
    }
    
    $chaveRecebida = $_GET['key'] ?? '';
    
    if ($chaveRecebida !== TELEGRAM_CRON_KEY) {
        http_response_code(401);
        die(json_encode([
            'success' => false,
            'error' => 'Chave de acesso inválida'
        ]));
    }
    
    return true;
}

// Validar acesso
validarAcesso();

// ========================================
// CONFIGURAÇÃO DE LOGS
// ========================================

$log_file = $base_path . '/logs/telegram_cron_' . date('Y-m-d') . '.log';
$log_dir = dirname($log_file);

if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

/**
 * Função de log
 */
function logMessage($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

/**
 * Função para enviar resposta JSON (quando executado via HTTP)
 */
function sendJsonResponse($success, $data = []) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success], $data));
    }
}

// ========================================
// INÍCIO DA EXECUÇÃO
// ========================================

logMessage(str_repeat('=', 80));
logMessage('INICIANDO VERIFICAÇÃO DE ALERTAS TELEGRAM');
logMessage(str_repeat('=', 80));
logMessage('Versão: 2.0.0');
logMessage('Data/Hora: ' . date('d/m/Y H:i:s'));
logMessage('Timezone: ' . date_default_timezone_get());

$startTime = microtime(true);
$resultado = [
    'estabelecimentos_processados' => 0,
    'total_alertas' => 0,
    'alertas_estoque' => 0,
    'alertas_contas' => 0,
    'alertas_promocoes' => 0,
    'erros' => 0
];

try {
    // ========================================
    // CONECTAR AO BANCO DE DADOS
    // ========================================
    
    logMessage('Conectando ao banco de dados...');
    $conn = getDBConnection();
    logMessage('✓ Conexão estabelecida com sucesso', 'SUCCESS');
    
    // ========================================
    // BUSCAR ESTABELECIMENTOS COM TELEGRAM ATIVO
    // ========================================
    
    logMessage('Buscando estabelecimentos com Telegram configurado...');
    
    $stmt = $conn->prepare("
        SELECT DISTINCT estabelecimento_id 
        FROM telegram_config 
        WHERE status = 1
    ");
    $stmt->execute();
    $estabelecimentos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $totalEstabelecimentos = count($estabelecimentos);
    
    if ($totalEstabelecimentos === 0) {
        logMessage('⚠ Nenhum estabelecimento com Telegram configurado', 'WARNING');
        logMessage('Configure em: Admin → Integrações → Telegram');
        
        sendJsonResponse(true, [
            'message' => 'Nenhum estabelecimento configurado',
            'resultado' => $resultado
        ]);
        
        exit(0);
    }
    
    logMessage("✓ Encontrados {$totalEstabelecimentos} estabelecimento(s) configurado(s)", 'SUCCESS');
    
    // ========================================
    // PROCESSAR CADA ESTABELECIMENTO
    // ========================================
    
    foreach ($estabelecimentos as $estabelecimentoId) {
        logMessage(str_repeat('-', 80));
        logMessage("Processando Estabelecimento ID: {$estabelecimentoId}");
        logMessage(str_repeat('-', 80));
        
        try {
            // Instanciar notificador
            $notifier = new TelegramNotifier($conn, $estabelecimentoId);
            logMessage('✓ TelegramNotifier instanciado', 'SUCCESS');
            
            // ========================================
            // VERIFICAR ESTOQUE MÍNIMO
            // ========================================
            
            logMessage('');
            logMessage('>>> Verificando Estoque Mínimo...');
            $alertasEstoque = $notifier->verificarEstoqueMinimo();
            logMessage("✓ Alertas de estoque: {$alertasEstoque}", 'SUCCESS');
            $resultado['alertas_estoque'] += $alertasEstoque;
            
            // ========================================
            // VERIFICAR CONTAS A PAGAR
            // ========================================
            
            logMessage('');
            logMessage('>>> Verificando Contas a Pagar...');
            $alertasContas = $notifier->verificarContasPagar();
            logMessage("✓ Alertas de contas: {$alertasContas}", 'SUCCESS');
            $resultado['alertas_contas'] += $alertasContas;
            
            // ========================================
            // VERIFICAR PROMOÇÕES
            // ========================================
            
            logMessage('');
            logMessage('>>> Verificando Promoções...');
            $alertasPromocoes = $notifier->verificarPromocoes();
            logMessage("✓ Alertas de promoções: {$alertasPromocoes}", 'SUCCESS');
            $resultado['alertas_promocoes'] += $alertasPromocoes;
            
            // ========================================
            // RESUMO DO ESTABELECIMENTO
            // ========================================
            
            $totalEstabelecimento = $alertasEstoque + $alertasContas + $alertasPromocoes;
            $resultado['total_alertas'] += $totalEstabelecimento;
            $resultado['estabelecimentos_processados']++;
            
            logMessage('');
            logMessage("✓ Estabelecimento processado com sucesso", 'SUCCESS');
            logMessage("  Total de alertas: {$totalEstabelecimento}");
            
        } catch (Exception $e) {
            logMessage("✗ ERRO ao processar estabelecimento {$estabelecimentoId}: " . $e->getMessage(), 'ERROR');
            logMessage("  Stack trace: " . $e->getTraceAsString(), 'ERROR');
            $resultado['erros']++;
        }
    }
    
    // ========================================
    // RESUMO FINAL
    // ========================================
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    logMessage('');
    logMessage(str_repeat('=', 80));
    logMessage('VERIFICAÇÃO CONCLUÍDA COM SUCESSO', 'SUCCESS');
    logMessage(str_repeat('=', 80));
    logMessage("Estabelecimentos processados: {$resultado['estabelecimentos_processados']}");
    logMessage("Total de alertas enviados: {$resultado['total_alertas']}");
    logMessage("  - Estoque: {$resultado['alertas_estoque']}");
    logMessage("  - Contas: {$resultado['alertas_contas']}");
    logMessage("  - Promoções: {$resultado['alertas_promocoes']}");
    logMessage("Erros: {$resultado['erros']}");
    logMessage("Tempo de execução: {$executionTime}s");
    logMessage(str_repeat('=', 80));
    logMessage('');
    
    // Enviar resposta JSON (se executado via HTTP)
    sendJsonResponse(true, [
        'message' => 'Verificação concluída com sucesso',
        'resultado' => $resultado,
        'execution_time' => $executionTime
    ]);
    
    exit(0);
    
} catch (Exception $e) {
    // ========================================
    // TRATAMENTO DE ERRO GLOBAL
    // ========================================
    
    logMessage('', 'ERROR');
    logMessage(str_repeat('=', 80), 'ERROR');
    logMessage('ERRO CRÍTICO', 'ERROR');
    logMessage(str_repeat('=', 80), 'ERROR');
    logMessage('Mensagem: ' . $e->getMessage(), 'ERROR');
    logMessage('Arquivo: ' . $e->getFile(), 'ERROR');
    logMessage('Linha: ' . $e->getLine(), 'ERROR');
    logMessage('Stack trace:', 'ERROR');
    logMessage($e->getTraceAsString(), 'ERROR');
    logMessage(str_repeat('=', 80), 'ERROR');
    logMessage('');
    
    // Enviar resposta JSON de erro (se executado via HTTP)
    sendJsonResponse(false, [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    exit(1);
}
?>
