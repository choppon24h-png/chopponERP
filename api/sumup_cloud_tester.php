<?php
/**
 * API de teste SumUp Cloud API
 * Uso interno do painel admin para validar comunicacao com leitora.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

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

function paymentLogTester(string $message, array $context = []): void {
    if (method_exists('Logger', 'payment')) {
        Logger::payment($message, $context);
    } else {
        Logger::info($message, $context);
    }
}

function loadPaymentConfigTester(PDO $conn): array {
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
        $stmt = $conn->query("SELECT token_sumup, merchant_code, affiliate_key FROM payment LIMIT 1");
        $db = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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

function sumupHttpTester(string $method, string $url, string $token, ?array $body = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
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

$conn = getDBConnection();
$cfg = loadPaymentConfigTester($conn);
$action = $_GET['action'] ?? $_POST['action'] ?? 'readers';

if ($action === 'config') {
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

if ($action === 'readers') {
    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers";
    $res = sumupHttpTester('GET', $url, $cfg['token_sumup'], null, 20);

    paymentLogTester('CloudTester readers list', [
        'merchant_code' => $cfg['merchant_code'],
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
    ]);

    if ($res['http_code'] !== 200 || !is_array($res['data'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao listar leitoras na SumUp',
            'http_code' => $res['http_code'],
            'detail' => $res['data'] ?? $res['raw'],
            'curl_error' => $res['curl_error'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $readers = $res['data'];
    foreach ($readers as &$r) {
        $rid = $r['id'] ?? '';
        if ($rid === '') {
            continue;
        }
        $urlStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$rid}/status";
        $st = sumupHttpTester('GET', $urlStatus, $cfg['token_sumup'], null, 12);
        $sd = $st['data']['data'] ?? $st['data'] ?? [];
        $r['status_live'] = strtoupper((string) ($sd['status'] ?? 'OFFLINE'));
        $r['battery_level'] = $sd['battery_level'] ?? null;
        $r['connection_type'] = $sd['connection_type'] ?? null;
        $r['last_activity'] = $sd['last_activity'] ?? null;
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'merchant_code' => $cfg['merchant_code'],
        'readers' => $readers,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reader_status') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? $_GET['reader_id'] ?? ''));
    if ($reader_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio']);
        exit;
    }

    $urlReader = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}";
    $urlStatus = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/status";
    $r1 = sumupHttpTester('GET', $urlReader, $cfg['token_sumup'], null, 12);
    $r2 = sumupHttpTester('GET', $urlStatus, $cfg['token_sumup'], null, 12);

    paymentLogTester('CloudTester reader_status', [
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

if ($action === 'checkout') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? ''));
    $card_type = strtolower(trim((string) ($_POST['card_type'] ?? 'debit')));
    $amount_brl = (float) ($_POST['amount'] ?? 1.00);
    $description = trim((string) ($_POST['description'] ?? 'Teste Cloud API R$1,00'));

    if ($reader_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio']);
        exit;
    }
    if (!in_array($card_type, ['debit', 'credit'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'card_type invalido']);
        exit;
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
    paymentLogTester('CloudTester checkout request', [
        'reader_id' => $reader_id,
        'card_type' => $card_type,
        'value' => $value,
        'merchant_code' => $cfg['merchant_code'],
        'has_affiliate' => isset($body['affiliate']),
        'has_app_id' => !empty($body['affiliate']['app_id'] ?? ''),
    ]);

    $res = sumupHttpTester('POST', $url, $cfg['token_sumup'], $body, 30);
    paymentLogTester('CloudTester checkout response', [
        'reader_id' => $reader_id,
        'card_type' => $card_type,
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
        'raw' => $res['raw'],
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

if ($action === 'cancel') {
    $reader_id = trim((string) ($_POST['reader_id'] ?? ''));
    if ($reader_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'reader_id obrigatorio']);
        exit;
    }

    $url = "https://api.sumup.com/v0.1/merchants/{$cfg['merchant_code']}/readers/{$reader_id}/terminate";
    paymentLogTester('CloudTester cancel request', [
        'reader_id' => $reader_id,
        'merchant_code' => $cfg['merchant_code'],
    ]);
    $res = sumupHttpTester('POST', $url, $cfg['token_sumup'], [], 20);
    paymentLogTester('CloudTester cancel response', [
        'reader_id' => $reader_id,
        'http_code' => $res['http_code'],
        'curl_error' => $res['curl_error'],
        'raw' => $res['raw'],
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

http_response_code(400);
echo json_encode(['success' => false, 'error' => "Acao invalida: {$action}"], JSON_UNESCAPED_UNICODE);
