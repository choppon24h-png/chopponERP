<?php
/**
 * Sistema de Logs Centralizado
 * Registra todos os eventos importantes do sistema
 */

class Logger {
    private static $logDir = __DIR__ . '/../logs/';
    
    /**
     * Níveis de log
     */
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const DEBUG = 'DEBUG';
    const SECURITY = 'SECURITY';
    
    /**
     * Escreve log em arquivo
     */
    private static function write($level, $message, $context = [], $filename = 'system.log') {
        // Criar diretório de logs se não existir
        if (!file_exists(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        
        $logFile = self::$logDir . $filename;
        
        // Formatar mensagem
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? 'N/A';
        
        $logEntry = sprintf(
            "[%s] [%s] [IP:%s] [%s %s] %s",
            $timestamp,
            $level,
            $ip,
            $method,
            $uri,
            $message
        );
        
        // Adicionar contexto se houver
        if (!empty($context)) {
            $logEntry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        $logEntry .= PHP_EOL;
        
        // Escrever no arquivo
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Limitar tamanho do arquivo (10MB)
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            self::rotateLog($logFile);
        }
    }
    
    /**
     * Rotacionar log quando fica muito grande
     */
    private static function rotateLog($logFile) {
        $backupFile = $logFile . '.' . date('Y-m-d_His') . '.bak';
        rename($logFile, $backupFile);
        
        // Manter apenas últimos 5 backups
        $backups = glob($logFile . '.*.bak');
        if (count($backups) > 5) {
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            foreach (array_slice($backups, 0, -5) as $oldBackup) {
                unlink($oldBackup);
            }
        }
    }
    
    /**
     * Log de informação
     */
    public static function info($message, $context = []) {
        self::write(self::INFO, $message, $context);
    }
    
    /**
     * Log de aviso
     */
    public static function warning($message, $context = []) {
        self::write(self::WARNING, $message, $context);
    }
    
    /**
     * Log de erro
     */
    public static function error($message, $context = []) {
        self::write(self::ERROR, $message, $context, 'errors.log');
    }
    
    /**
     * Log de debug
     */
    public static function debug($message, $context = []) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            self::write(self::DEBUG, $message, $context, 'debug.log');
        }
    }
    
    /**
     * Log de segurança (tentativas de login, acessos negados, etc)
     */
    public static function security($message, $context = []) {
        self::write(self::SECURITY, $message, $context, 'security.log');
    }
    
    /**
     * Log de autenticação
     */
    public static function auth($message, $context = []) {
        self::write(self::SECURITY, $message, $context, 'auth.log');
    }
    
    /**
     * Log de API
     */
    public static function api($message, $context = []) {
        self::write(self::INFO, $message, $context, 'api.log');
    }
    
    /**
     * Log de webhook
     */
    public static function webhook($message, $context = []) {
        self::write(self::INFO, $message, $context, 'webhook.log');
    }

    /**
     * Log de pagamentos (integrações de gateways)
     */
    public static function payment($message, $context = []) {
        self::write(self::INFO, $message, $context, 'paymentslogs.log');
    }
    
    /**
     * Log de SQL (para debug)
     */
    public static function sql($query, $params = []) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            self::write(self::DEBUG, "SQL: $query", ['params' => $params], 'sql.log');
        }
    }
    
    /**
     * Capturar exceção e logar
     */
    public static function exception($exception) {
        $context = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
        
        self::error('Exception: ' . get_class($exception), $context);
    }
}

// Configurar handler de erros global
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_NOTICE => 'NOTICE',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE'
    ];
    
    $type = $errorTypes[$errno] ?? 'UNKNOWN';
    
    Logger::error("PHP $type: $errstr", [
        'file' => $errfile,
        'line' => $errline
    ]);
    
    return false; // Permite que o handler padrão também execute
});

// Configurar handler de exceções não capturadas
set_exception_handler(function($exception) {
    Logger::exception($exception);
});
