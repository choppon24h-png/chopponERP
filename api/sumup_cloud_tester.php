<?php
/**
 * API de teste SumUp Cloud API - VERSÃO MELHORADA
 * Uso interno do painel admin para validar comunicacao com leitora.
 *
 * MELHORIAS IMPLEMENTADAS:
 * - Funcionalidade de deleção de readers (individual e em lote)
 * - Logging robusto com contexto e persistência
 * - Validação melhorada de entrada
 * - Otimização de requisições (batch operations)
 * - Melhor tratamento de erros
 * - Documentação inline completa
 *
 * CORREÇÕES APLICADAS:
 * - BUG CRÍTICO: validateReaderId() usava regex /^rdr_[A-Z0-9]{27}$/i (31 chars total)
 *   Corrigido para /^rdr_[A-Z0-9]{26}$/i (30 chars total), conforme documentação oficial
 *   SumUp: campo id do reader tem min length: 30, max length: 30.
 *   Referência: https://developer.sumup.com/api (seção Readers > Delete a reader)
 *   Exemplo de ID real: rdr_1JHCGHNM3095NBKJP2CMDWJTXC (4 + 26 = 30 chars)
 *
 * @version 2.1.0
 * @author ChopponERP Team
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

/**
 * Classe para gerenciar logging estruturado
 */
class SumUpCloudLogger {
    private $logFile;
    private $requestId;
    
    public function __construct($logDir = '../logs') {
        $this->requestId = substr(md5(uniqid()), 0, 8);
        $this->logFile = $logDir . '/sumup_cloud_' . date('Y-m-d') . '.log';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log estruturado com contexto
     */
    public function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[{$timestamp}] [{$this->requestId}] [{$level}] {$message} {$contextStr}\n";
        
        @file_put_contents($this->logFile, $logLine, FILE_APPEND);
        
        // Também registrar via Logger existente se disponível
        if (method_exists('Logger', 'payment')) {
            Logger::payment($message, array_merge(['level' => $level, 'request_id' => $this->requestId], $context));
        }
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('DEBUG', $message, $context);
    }
    
    public function getRequestId(): string {
        return $this->requestId;
    }
}

/**
 * Classe para validação de entrada
 */
class InputValidator {
    /**
     * Valida formato de reader_id (deve ser rdr_*)
     *
     * Formato oficial SumUp: rdr_ (4 chars) + 26 chars alfanuméricos = 30 chars total.
     * Referência: https://developer.sumup.com/api — campo id: min length: 30, max length: 30
     * Exemplo real: rdr_1JHCGHNM3095NBKJP2CMDWJTXC (30 chars)
     *
     * BUG CORRIGIDO: regex anterior usava {27} (31 chars total) → corrigido para {26} (30 chars total)
     * O flag /i aceita maiúsculas e minúsculas, garantindo compatibilidade com qualquer case.
     */
    public static function validateReaderId(string $id): bool {
        // Aceita rdr_ + exatamente 26 chars alfanuméricos (case-insensitive) = 30 chars total
        return preg_match('/^rdr_[A-Z0-9]{26}$/i', trim($id)) === 1;
    }
    
    /**
     * Valida merchant_code
     */
    public static function validateMerchantCode(string $code): bool {
        return preg_match('/^[A-Z0-9]{8}$/i', trim($code)) === 1;
    }
    
    /**
     * Valida amount (valor em BRL)
     */
    public static function validateAmount(float $amount): bool {
        return $amount > 0 && $amount <= 999999.99;
    }
    
    /**
     * Valida card_type
     */
    public static function validateCardType(string $type): bool {
        return in_array(strtolower(trim($type)), ['debit', 'credit'], true);
    }
    
    /**
     * Sanitiza string para segurança
     */
    public static function sanitizeString(string $str, int $maxLength = 500): string {
        return substr(trim($str), 0, $maxLength);
    }
}

/**
 * Mascara valores sensíveis para logging seguro
 */
function maskSecret(?string $value, int $visible = 6): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (strlen($value) <= $visible) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, $visible) . str_repeat('*', max(3, strlen($value) - $visible));
}

/**
 * Carrega configuração de pagamento do banco de dados ou variáveis de ambiente
 */
function loadPaymentConfig(PDO $conn): array {
    $cfg = [
        'token_sumup' => SUMUP_TOKEN,
        'merchant_code' => SUMUP_MERCHANT_CODE,
        'affiliate_key' => '',
        'affiliate_app_id' => '',
    ];

    $db = null;
    try {
        $stmt = $conn->query("SELECT token_sumup, merchant_code, affiliate_key, affiliate_app_id FROM payment LIMIT 1");
        $db = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        // Fallback para bases sem affiliate_app_id
        try {
            $stmt = $conn->query("SELECT token_sumup, merchant_code, affiliate_key FROM payment LIMIT 1");
            $db = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e2) {
            // Usar apenas variáveis de ambiente
        }
    }

    if ($db) {
        if (!empty($db['token_sumup'])) {
            $cfg['token_sumup'] = $db['token_sumup'];
        }
        if (!empty($db['merchant_code'])) {
            $cfg['merchant_code'] = $db['merchant_code'];
        }
        if (!empty($db['affiliate_key'])) {
            $cfg['affiliate_key'] = $db['affiliate_key'];
        }
        if (!empty($db['affiliate_app_id'])) {
            $cfg['affiliate_app_id'] = $db['affiliate_app_id'];
        }
    }

    return $cfg;
}

/**
 * Faz requisição HTTP para SumUp API com tratamento robusto de erros
 */
function sumupHttp(string $method, string $url, string $token, ?array $body = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $code,
        'raw' => $resp,
        'data' => $resp ? json_decode($resp, true) : null,
        'curl_error' => $err,
    ];
}

/**
 * Extrai o estado operacional do reader a partir da resposta do endpoint /readers/{id}/status
 *
 * A SumUp retorna dois campos distintos no objeto status.data:
 *   - status: estado da sessão Cloud API ("ONLINE" | "OFFLINE")
 *   - state:  estado operacional do dispositivo ("IDLE" | "PROCESSING" | "CARD_INSERTED" | etc.)
 *
 * O campo 'status' pode ser "OFFLINE" mesmo quando o dispositivo está fisicamente
 * ligado, conectado ao Wi-Fi e pronto para transacionar (state = "IDLE").
 * Isso ocorre quando a sessão Cloud API não foi iniciada via Connections > API > Connect.
 * Entretanto, a própria API SumUp aceita checkouts de dispositivos com state=IDLE.
 *
 * @return array{status: string, state: string, connection_type: string|null, has_wifi: bool}
 */
function extractReaderStatusFull($payload): array {
    // O endpoint /readers/{id}/status retorna:
    // { success: true, reader: {...}, status: { data: { status: "OFFLINE", state: "IDLE", ... } } }
    // O precheck do frontend já faz GET /reader_status e retorna o payload completo
    $statusBlock = null;
    if (is_array($payload)) {
        if (isset($payload['status']['data']) && is_array($payload['status']['data'])) {
            $statusBlock = $payload['status']['data'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $statusBlock = $payload['data'];
        } else {
            $statusBlock = $payload;
        }
    }
    if (!is_array($statusBlock)) {
        return ['status' => 'UNKNOWN', 'state' => 'UNKNOWN', 'connection_type' => null, 'has_wifi' => false];
    }
    $status          = strtoupper(trim((string) ($statusBlock['status'] ?? 'UNKNOWN')));
    $state           = strtoupper(trim((string) ($statusBlock['state'] ?? 'UNKNOWN')));
    $connectionType  = $statusBlock['connection_type'] ?? null;
    $hasWifi         = !empty($connectionType);
    return [
        'status'          => $status,
        'state'           => $state,
        'connection_type' => $connectionType,
        'has_wifi'        => $hasWifi,
    ];
}

/**
 * Extrai status de reader de diferentes formatos de resposta (compat. legado)
 */
function extractReaderStatus($payload): string {
    $full = extractReaderStatusFull($payload);
    // Prioriza o state operacional se for um estado ativo conhecido
    if (in_array($full['state'], ['IDLE', 'READY', 'PROCESSING', 'CARD_INSERTED', 'CARD_TAPPED', 'PIN_ENTRY'], true)) {
        return $full['state'];
    }
    return $full['status'];
}

/**
 * Verifica se o reader está pronto para receber um checkout.
 *
 * REGRA CORRIGIDA (baseada no comportamento real da SumUp Cloud API):
 *
 * Um dispositivo está PRONTO se qualquer uma das condições for verdadeira:
 *   1. status == ONLINE | CONNECTED | READY | READY_TO_TRANSACT  (sessão Cloud API ativa)
 *   2. state  == IDLE | READY | PROCESSING | CARD_INSERTED       (dispositivo operacional)
 *      E connection_type não é nulo (Wi-Fi ou dados móveis conectados)
 *
 * Justificativa: O campo 'status=OFFLINE' é o estado da sessão Cloud API,
 * não o estado físico do dispositivo. Um Solo com state=IDLE e Wi-Fi ativo
 * aceita checkouts normalmente (confirmado com dados reais do usuário:
 * battery=99%, connection_type=Wi-Fi, state=IDLE, last_activity recente).
 *
 * @param array $statusFull Retorno de extractReaderStatusFull()
 */
function isReaderReadyFull(array $statusFull): bool {
    $status = $statusFull['status'];
    $state  = $statusFull['state'];
    $hasWifi = $statusFull['has_wifi'];

    // Condição 1: sessão Cloud API explicitamente ativa
    if (in_array($status, ['ONLINE', 'CONNECTED', 'READY', 'READY_TO_TRANSACT'], true)) {
        return true;
    }
    // Condição 2: dispositivo operacional com conexão de rede ativa
    if ($hasWifi && in_array($state, ['IDLE', 'READY', 'PROCESSING', 'CARD_INSERTED', 'CARD_TAPPED', 'PIN_ENTRY'], true)) {
        return true;
    }
    return false;
}

/** Compat. legado: aceita string de status */
function isReaderReady(string $status): bool {
    return in_array($status, ['ONLINE', 'CONNECTED', 'READY', 'READY_TO_TRANSACT', 'IDLE'], true);
}

// Inicializar logger e variáveis globais
$logger = new SumUpCloudLogger();
$conn = getDBConnection();
$cfg = loadPaymentConfig($conn);
$action = $_GET['action'] ?? $_POST['action'] ?? 'readers_db';

$logger->info('Request received', [
    'action' => $action,
    'method' => $_SERVER['REQUEST_METHOD'],
    'merchant_code' => $cfg['merchant_code'],
]);

// ============================================================================
// ACTION: config - Retorna configuração de pagamento
// ============================================================================
if ($action === 'config') {
    $logger->info('Config requested');
    echo json_encode([
        'success' => true,
        'merchant_code' => $cfg['merchant_code'],
        'token_masked' => maskSecret($cfg['token_sumup']),
        'affiliate_key_masked' => maskSecret($cfg['affiliate_key']),
        'affiliate_app_id' => $cfg['affiliate_app_id'],
        'has_affiliate' => !empty($cfg['affiliate_key']),
        'has_affiliate_app_id' => !empty($cfg['affiliate_app_id']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: readers - Lista leitores da API SumUp
// ============================================================================
if ($action === 'readers') {
    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers";
    $res = sumupHttp('GET', $url, $cfg['token_sumup'], null, 20);

    $logger->info('List readers from API', [
        'merchant_code' => $cfg['merchant_code'],
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
    ]);

    if ($res['http_code'] !== 200 || !is_array($res['data'])) {
        http_response_code(422);
        $logger->error('Failed to list readers from API', [
            'http_code' => $res['http_code'],
            'error' => $res['data'] ?? $res['raw'],
        ]);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao listar leitoras na SumUp',
            'http_code' => $res['http_code'],
            'detail' => $res['data'] ?? $res['raw'],
            'curl_error' => $res['curl_error'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // SumUp retorna { "items": [...] } — extrair o array correto
    $rawData = $res['data'];
    $readers = [];
    if (isset($rawData['items']) && is_array($rawData['items'])) {
        $readers = $rawData['items'];
    } elseif (is_array($rawData) && !isset($rawData['items'])) {
        // Fallback: resposta já é array direto
        $readers = array_values($rawData);
    }

    foreach ($readers as &$r) {
        $rid = $r['id'] ?? '';
        if ($rid === '') {
            continue;
        }
        $urlStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$rid}/status";
        $st = sumupHttp('GET', $urlStatus, $cfg['token_sumup'], null, 12);
        $sd = $st['data']['data'] ?? $st['data'] ?? [];
        $r['status_live'] = strtoupper((string) ($sd['status'] ?? 'OFFLINE'));
        $r['battery_level'] = $sd['battery_level'] ?? null;
        $r['connection_type'] = $sd['connection_type'] ?? null;
        $r['last_activity'] = $sd['last_activity'] ?? null;
    }
    unset($r);

    $logger->info('Readers listed successfully', ['count' => count($readers)]);

    echo json_encode([
        'success' => true,
        'merchant_code' => $cfg['merchant_code'],
        'readers' => $readers,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: readers_db - Lista leitores do banco de dados local (PADRÃO)
// ============================================================================
if ($action === 'readers_db') {
    try {
        $stmt = $conn->query("
            SELECT
                sr.reader_id,
                sr.name,
                sr.serial,
                sr.model,
                sr.status,
                sr.battery_level,
                sr.connection_type,
                sr.last_activity,
                sr.updated_at,
                sr.created_at,
                sr.estabelecimento_id,
                e.name AS estabelecimento_nome
            FROM sumup_readers sr
            LEFT JOIN estabelecimentos e ON e.id = sr.estabelecimento_id
            ORDER BY sr.updated_at DESC, sr.created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $logger->error('Failed to query sumup_readers table', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao consultar tabela sumup_readers',
            'detail' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enriquecer com status ao vivo da SumUp quando possível
    foreach ($rows as &$r) {
        $rid = trim((string) ($r['reader_id'] ?? ''));
        $r['status_live'] = strtoupper((string) ($r['status'] ?? 'UNKNOWN'));
        $r['source'] = 'db';
        if ($rid === '') {
            continue;
        }

        $urlStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$rid}/status";
        $st = sumupHttp('GET', $urlStatus, $cfg['token_sumup'], null, 10);
        if ($st['http_code'] === 200) {
            $sd = $st['data']['data'] ?? $st['data'] ?? [];
            $r['status_live'] = strtoupper((string) ($sd['status'] ?? $r['status_live']));
            $r['battery_level'] = $sd['battery_level'] ?? $r['battery_level'];
            $r['connection_type'] = $sd['connection_type'] ?? $r['connection_type'];
            $r['last_activity'] = $sd['last_activity'] ?? $r['last_activity'];
            $r['source'] = 'db+api';
        }
    }
    unset($r);

    $logger->info('Readers from DB listed', ['count' => count($rows)]);

    // Fallback para API pura se tabela local estiver vazia
    if (count($rows) === 0) {
        $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers";
        $res = sumupHttp('GET', $url, $cfg['token_sumup'], null, 20);
        if ($res['http_code'] === 200 && is_array($res['data'])) {
            // SumUp retorna { "items": [...] } — normalizar para array plano
            $apiData = $res['data'];
            $apiReaders = [];
            if (isset($apiData['items']) && is_array($apiData['items'])) {
                $apiReaders = $apiData['items'];
            } elseif (is_array($apiData) && !isset($apiData['items'])) {
                $apiReaders = array_values($apiData);
            }
            // Enriquecer com status ao vivo
            foreach ($apiReaders as &$ar) {
                $rid = $ar['id'] ?? '';
                $ar['reader_id'] = $rid;
                if ($rid === '') continue;
                $urlSt = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$rid}/status";
                $st = sumupHttp('GET', $urlSt, $cfg['token_sumup'], null, 10);
                if ($st['http_code'] === 200) {
                    $sd = $st['data']['data'] ?? $st['data'] ?? [];
                    $ar['status_live'] = strtoupper((string) ($sd['status'] ?? ($ar['status'] ?? 'UNKNOWN')));
                    $ar['battery_level'] = $sd['battery_level'] ?? null;
                    $ar['connection_type'] = $sd['connection_type'] ?? null;
                    $ar['last_activity'] = $sd['last_activity'] ?? null;
                } else {
                    $ar['status_live'] = strtoupper((string) ($ar['status'] ?? 'UNKNOWN'));
                }
                $ar['source'] = 'api_fallback';
            }
            unset($ar);
            $logger->info('Using API fallback (DB empty)', ['count' => count($apiReaders)]);
            echo json_encode([
                'success' => true,
                'merchant_code' => $cfg['merchant_code'],
                'source' => 'api_fallback',
                'readers' => $apiReaders,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'merchant_code' => $cfg['merchant_code'],
        'source' => 'db',
        'readers' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: reader_status - Obtém status detalhado de um reader específico
// ============================================================================
if ($action === 'reader_status') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? $_GET['reader_id'] ?? ''));
    if ($reader_id === '' || !InputValidator::validateReaderId($reader_id)) {
        http_response_code(400);
        $logger->warning('Invalid reader_id provided', ['reader_id' => $reader_id]);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio e deve ser válido']);
        exit;
    }

    $urlReader = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}";
    $urlStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/status";
    $r1 = sumupHttp('GET', $urlReader, $cfg['token_sumup'], null, 12);
    $r2 = sumupHttp('GET', $urlStatus, $cfg['token_sumup'], null, 12);

    $logger->info('Reader status retrieved', [
        'reader_id' => $reader_id,
        'http_reader' => $r1['http_code'],
        'http_status' => $r2['http_code'],
    ]);

    echo json_encode([
        'success' => true,
        'reader' => $r1['data'],
        'status' => $r2['data'],
        'http_reader' => $r1['http_code'],
        'http_status' => $r2['http_code'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: checkout - Inicia uma transação no reader
//
// PARÂMETROS ADICIONAIS:
//   force_checkout=1  → Ignora o pre-check de status e envia direto para a API SumUp.
//                       Use quando o status retorna OFFLINE mas o dispositivo está
//                       fisicamente conectado (Connections > API > Connect no Solo).
//                       A própria API SumUp retornará erro se o device estiver offline.
// ============================================================================
if ($action === 'checkout') {
    $reader_id     = trim((string) ($_POST['reader_id'] ?? ''));
    $card_type     = strtolower(trim((string) ($_POST['card_type'] ?? 'debit')));
    $amount_brl    = (float) ($_POST['amount'] ?? 1.00);
    $description   = InputValidator::sanitizeString(trim((string) ($_POST['description'] ?? 'Teste Cloud API R$1,00')));
    $force_checkout = !empty($_POST['force_checkout']) && $_POST['force_checkout'] === '1';

    if ($reader_id === '' || !InputValidator::validateReaderId($reader_id)) {
        http_response_code(400);
        $logger->warning('Checkout: invalid reader_id', ['reader_id' => $reader_id]);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio e deve ser válido']);
        exit;
    }
    
    if (!InputValidator::validateCardType($card_type)) {
        http_response_code(400);
        $logger->warning('Checkout: invalid card_type', ['card_type' => $card_type]);
        echo json_encode(['success' => false, 'error' => 'card_type invalido (debit ou credit)']);
        exit;
    }
    
    if (!InputValidator::validateAmount($amount_brl)) {
        http_response_code(400);
        $logger->warning('Checkout: invalid amount', ['amount' => $amount_brl]);
        echo json_encode(['success' => false, 'error' => 'amount invalido (0 < amount <= 999999.99)']);
        exit;
    }

    // 1) Pre-check de status em tempo real antes de enviar checkout
    //    Endpoint: GET /v0.1/merchants/{mc}/readers/{id}/status
    //    Resposta: { success, reader, status: { data: { status, state, connection_type, ... } } }
    $urlPreStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/status";
    $pre = sumupHttp('GET', $urlPreStatus, $cfg['token_sumup'], null, 12);

    // Extrair campos completos usando a nova função que entende o formato real da SumUp
    // O payload do precheck vem do frontend como: { success, reader, status: { data: {...} } }
    // Mas aqui fazemos a chamada diretamente, então $pre['data'] já é o objeto desserializado
    $preStatusFull = extractReaderStatusFull($pre['data']);
    $preStatus     = $preStatusFull['status'];   // ONLINE | OFFLINE
    $preState      = $preStatusFull['state'];    // IDLE | PROCESSING | etc.
    $preConnType   = $preStatusFull['connection_type']; // Wi-Fi | null
    $preReady      = isReaderReadyFull($preStatusFull);

    $logger->info('Checkout precheck', [
        'reader_id'       => $reader_id,
        'merchant_code'   => $cfg['merchant_code'],
        'http_code'       => $pre['http_code'],
        'status'          => $preStatus,
        'state'           => $preState,
        'connection_type' => $preConnType,
        'ready'           => $preReady ? 1 : 0,
        'force'           => $force_checkout ? 1 : 0,
        'curl_error'      => $pre['curl_error'],
    ]);

    // 2) Se leitora não está pronta E não foi solicitado force_checkout → retornar aviso
    //    NOTA: Um dispositivo com state=IDLE e Wi-Fi é considerado PRONTO (ver isReaderReadyFull).
    //    Só bloqueia se o dispositivo estiver genuinamente inacessível (sem rede, sem estado ativo).
    if (!$preReady && !$force_checkout) {
        $detail = 'Leitora sem conexão ativa ou estado operacional desconhecido.';
        if ($pre['http_code'] === 200) {
            if ($preState !== 'UNKNOWN' && empty($preConnType)) {
                $detail = "Dispositivo em estado {$preState} mas sem conexão de rede (Wi-Fi/dados). Verifique a conexão.";
            } elseif ($preState === 'UNKNOWN' && $preStatus === 'OFFLINE') {
                $detail = "Dispositivo offline e sem estado operacional detectado. Verifique se está ligado e conectado.";
            } else {
                $detail = "Status: {$preStatus} | State: {$preState} | Rede: " . ($preConnType ?? 'nenhuma') . ".";
            }
        } elseif ($pre['curl_error'] !== '') {
            $detail = 'Falha de rede ao consultar status: ' . $pre['curl_error'];
        } else {
            $detail = "Falha ao consultar status (HTTP {$pre['http_code']}). Verifique token e merchant_code.";
        }

        $logger->warning('Checkout precheck: reader not ready', [
            'reader_id'       => $reader_id,
            'status'          => $preStatus,
            'state'           => $preState,
            'connection_type' => $preConnType,
            'detail'          => $detail,
        ]);

        echo json_encode([
            'success'          => false,
            'reader_not_ready' => true,
            'http_code'        => 409,
            'precheck_status'  => $preStatus,
            'precheck_state'   => $preState,
            'response' => [
                'errors' => [
                    'type'   => 'READER_NOT_READY',
                    'detail' => $detail,
                ],
                'force_checkout_disponivel' => true,
                'force_checkout_aviso'      => 'Use force_checkout=1 para enviar direto à API SumUp. A API rejeitará com erro claro se o device estiver inacessível.',
            ],
            'precheck' => [
                'http_code'       => $pre['http_code'],
                'status'          => $preStatus,
                'state'           => $preState,
                'connection_type' => $preConnType,
                'curl_error'      => $pre['curl_error'],
                'raw'             => $pre['raw'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) Se force_checkout=1 e leitora ainda não pronta → logar aviso mas prosseguir
    if (!$preReady && $force_checkout) {
        $logger->warning('Checkout forced: reader state not fully ready', [
            'reader_id'       => $reader_id,
            'status'          => $preStatus,
            'state'           => $preState,
            'connection_type' => $preConnType,
        ]);
    }

    $value = (int) round($amount_brl * 100);
    if ($value <= 0) {
        $value = 100;
    }

    $body = [
        'total_amount' => [
            'value' => $value,
            'currency' => 'BRL',
            'minor_unit' => 2,
        ],
        'card_type' => $card_type,
        'description' => $description,
        'return_url' => SITE_URL . '/api/webhook.php',
    ];

    if (!empty($cfg['affiliate_key'])) {
        $body['affiliate'] = [
            'key' => $cfg['affiliate_key'],
            'foreign_transaction_id' => 'TEST-' . strtoupper($card_type) . '-' . time(),
        ];
        if (!empty($cfg['affiliate_app_id'])) {
            $body['affiliate']['app_id'] = $cfg['affiliate_app_id'];
        }
    }

    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/checkout";
    $logger->info('Checkout request', [
        'reader_id' => $reader_id,
        'card_type' => $card_type,
        'value' => $value,
        'merchant_code' => $cfg['merchant_code'],
        'has_affiliate' => isset($body['affiliate']),
        'has_app_id' => !empty($body['affiliate']['app_id'] ?? ''),
    ]);

    $res = sumupHttp('POST', $url, $cfg['token_sumup'], $body, 30);
    $logger->info('Checkout response', [
        'reader_id' => $reader_id,
        'card_type' => $card_type,
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
    ]);

    echo json_encode([
        'success' => in_array($res['http_code'], [200, 201, 202], true),
        'http_code' => $res['http_code'],
        'response' => $res['data'],
        'raw' => $res['raw'],
        'curl_error' => $res['curl_error'],
        'request_body' => $body,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: cancel - Cancela uma transação em andamento
// ============================================================================
if ($action === 'cancel') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? ''));
    if ($reader_id === '' || !InputValidator::validateReaderId($reader_id)) {
        http_response_code(400);
        $logger->warning('Cancel: invalid reader_id', ['reader_id' => $reader_id]);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio e deve ser válido']);
        exit;
    }

    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/terminate";
    $logger->info('Cancel request', [
        'reader_id' => $reader_id,
        'merchant_code' => $cfg['merchant_code'],
    ]);
    $res = sumupHttp('POST', $url, $cfg['token_sumup'], [], 20);
    $logger->info('Cancel response', [
        'reader_id' => $reader_id,
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
    ]);

    echo json_encode([
        'success' => in_array($res['http_code'], [200, 202, 204], true),
        'http_code' => $res['http_code'],
        'response' => $res['data'],
        'raw' => $res['raw'],
        'curl_error' => $res['curl_error'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: delete_reader - Deleta um reader específico ⭐ NOVO
// ============================================================================
if ($action === 'delete_reader') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? $_GET['reader_id'] ?? ''));
    $confirm = trim((string) ($_POST['confirm'] ?? $_GET['confirm'] ?? ''));
    
    if ($reader_id === '' || !InputValidator::validateReaderId($reader_id)) {
        http_response_code(400);
        $logger->warning('Delete: invalid reader_id', ['reader_id' => $reader_id]);
        echo json_encode([
            'success' => false,
            'error' => 'reader_id obrigatorio e deve ser válido',
            'example' => 'rdr_3MSAFM23CK82VSTT4BN6RWSQ65',  // 30 chars: rdr_ + 26 alfanumericos
        ]);
        exit;
    }
    
    // Requer confirmação explícita para evitar deleção acidental
    if ($confirm !== 'yes') {
        $logger->info('Delete confirmation required', ['reader_id' => $reader_id]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Confirmação necessária para deletar reader',
            'message' => 'Envie confirm=yes para confirmar a deleção',
            'warning' => 'Após deletar via API, você deve desconectar fisicamente o dispositivo no menu: Connections > API > Disconnect',
            'reader_id' => $reader_id,
        ]);
        exit;
    }

    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}";
    $logger->info('Delete reader request', [
        'reader_id' => $reader_id,
        'merchant_code' => $cfg['merchant_code'],
    ]);
    
    $res = sumupHttp('DELETE', $url, $cfg['token_sumup'], null, 15);
    
    $success = in_array($res['http_code'], [200, 204], true);
    
    $logger->info('Delete reader response', [
        'reader_id' => $reader_id,
        'http_code' => $res['http_code'],
        'success' => $success ? 1 : 0,
        'curl_error' => $res['curl_error'],
    ]);
    
    if (!$success) {
        http_response_code($res['http_code'] === 404 ? 404 : 422);
    }

    echo json_encode([
        'success' => $success,
        'http_code' => $res['http_code'],
        'reader_id' => $reader_id,
        'message' => $success ? 'Reader deletado com sucesso. Desconecte fisicamente no menu do dispositivo.' : 'Falha ao deletar reader',
        'response' => $res['data'],
        'curl_error' => $res['curl_error'],
        'next_steps' => $success ? [
            'Acesse o SumUp Solo',
            'Menu > Connections > API > Disconnect',
            'Confirme a desconexão no display',
        ] : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: delete_all_readers - Deleta TODOS os readers da conta ⭐ NOVO
// ============================================================================
if ($action === 'delete_all_readers') {
    $confirm = trim((string) ($_POST['confirm'] ?? $_GET['confirm'] ?? ''));
    $confirmAll = trim((string) ($_POST['confirm_all'] ?? $_GET['confirm_all'] ?? ''));
    
    // Dupla confirmação para evitar deleção acidental
    if ($confirm !== 'yes' || $confirmAll !== 'DELETE_ALL') {
        $logger->warning('Delete all: confirmation required');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Dupla confirmação necessária para deletar TODOS os readers',
            'required_params' => [
                'confirm' => 'yes',
                'confirm_all' => 'DELETE_ALL',
            ],
            'warning' => '⚠️ ESTA AÇÃO É IRREVERSÍVEL! Todos os readers serão deletados da conta.',
        ]);
        exit;
    }

    $logger->warning('Delete all readers initiated', ['merchant_code' => $cfg['merchant_code']]);

    // 1) Listar todos os readers
    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers";
    $listRes = sumupHttp('GET', $url, $cfg['token_sumup'], null, 20);
    
    if ($listRes['http_code'] !== 200 || !is_array($listRes['data'])) {
        http_response_code(422);
        $logger->error('Delete all: failed to list readers', [
            'http_code' => $listRes['http_code'],
        ]);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao listar readers para deleção',
            'http_code' => $listRes['http_code'],
            'detail' => $listRes['data'] ?? $listRes['raw'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // SumUp retorna { "items": [...] } — extrair o array correto
    $rawList = $listRes['data'];
    $readers = [];
    if (isset($rawList['items']) && is_array($rawList['items'])) {
        $readers = $rawList['items'];
    } elseif (is_array($rawList) && !isset($rawList['items'])) {
        $readers = array_values($rawList);
    }

    $results = [
        'total' => count($readers),
        'deleted' => 0,
        'failed' => 0,
        'details' => [],
        'raw_api_response' => $rawList,
    ];

    if (count($readers) === 0) {
        $logger->warning('Delete all: no readers found to delete');
        echo json_encode([
            'success' => true,
            'summary' => $results,
            'message' => 'Nenhuma leitora encontrada para deletar.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) Deletar cada reader
    foreach ($readers as $reader) {
        $rid = $reader['id'] ?? '';
        if ($rid === '') {
            $results['failed']++;
            $results['details'][] = [
                'reader_id' => 'unknown',
                'success' => false,
                'error' => 'Reader ID vazio',
            ];
            continue;
        }

        $deleteUrl = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$rid}";
        $deleteRes = sumupHttp('DELETE', $deleteUrl, $cfg['token_sumup'], null, 15);
        
        $deleteSuccess = in_array($deleteRes['http_code'], [200, 204], true);
        
        if ($deleteSuccess) {
            $results['deleted']++;
        } else {
            $results['failed']++;
        }
        
        $results['details'][] = [
            'reader_id' => $rid,
            'name' => $reader['name'] ?? 'N/A',
            'success' => $deleteSuccess,
            'http_code' => $deleteRes['http_code'],
            'error' => $deleteSuccess ? null : ($deleteRes['data']['message'] ?? 'Erro desconhecido'),
        ];

        $logger->info('Delete all: reader deleted', [
            'reader_id' => $rid,
            'success' => $deleteSuccess ? 1 : 0,
            'http_code' => $deleteRes['http_code'],
        ]);
    }

    $logger->warning('Delete all completed', [
        'total' => $results['total'],
        'deleted' => $results['deleted'],
        'failed' => $results['failed'],
    ]);

    echo json_encode([
        'success' => $results['failed'] === 0,
        'summary' => $results,
        'next_steps' => $results['deleted'] > 0 ? [
            'Acesse cada SumUp Solo',
            'Menu > Connections > API > Disconnect',
            'Confirme a desconexão em cada dispositivo',
        ] : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// ACTION: health_check - Verifica saúde da integração
// ============================================================================
if ($action === 'health_check') {
    $checks = [
        'database' => false,
        'sumup_api' => false,
        'config' => false,
    ];

    // Check database
    try {
        $stmt = $conn->query("SELECT 1");
        $checks['database'] = true;
    } catch (Exception $e) {
        $logger->error('Health check: database failed', ['error' => $e->getMessage()]);
    }

    // Check SumUp API
    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers";
    $res = sumupHttp('GET', $url, $cfg['token_sumup'], null, 10);
    $checks['sumup_api'] = $res['http_code'] === 200;

    // Check config
    $checks['config'] = !empty($cfg['merchant_code']) && !empty($cfg['token_sumup']);

    $allHealthy = array_reduce($checks, function($carry, $item) {
        return $carry && $item;
    }, true);

    $logger->info('Health check completed', $checks);

    http_response_code($allHealthy ? 200 : 503);
    echo json_encode([
        'success' => $allHealthy,
        'status' => $allHealthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// Ação inválida
// ============================================================================
http_response_code(400);
$logger->warning('Invalid action requested', ['action' => $action]);
echo json_encode([
    'success' => false,
    'error' => "Acao invalida: {$action}",
    'available_actions' => [
        'config' => 'Retorna configuração de pagamento',
        'readers' => 'Lista leitores da API SumUp',
        'readers_db' => 'Lista leitores do banco de dados (padrão)',
        'reader_status' => 'Obtém status de um reader específico',
        'checkout' => 'Inicia uma transação',
        'cancel' => 'Cancela uma transação',
        'delete_reader' => 'Deleta um reader específico',
        'delete_all_readers' => 'Deleta TODOS os readers (requer dupla confirmação)',
        'health_check' => 'Verifica saúde da integração',
    ],
], JSON_UNESCAPED_UNICODE);
