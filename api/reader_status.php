<?php
/**
 * API - Status da Leitora de Cartão SumUp
 * POST /api/reader_status.php
 *
 * Retorna o status da leitora SumUp vinculada ao android_id informado.
 * Usa o endpoint oficial GET /readers/{id}/status da SumUp para obter:
 * - status: ONLINE | OFFLINE
 * - state: IDLE | BUSY | etc.
 * - battery_level, connection_type, firmware_version, last_activity
 *
 * Não cria checkouts de teste — diagnóstico limpo e preciso.
 */

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/logger.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

if (!jwtValidate($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$input = $_POST;

if (empty($input['android_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'android_id é obrigatório']);
    exit;
}

$conn = getDBConnection();

// Buscar TAP pelo android_id
$stmt = $conn->prepare("SELECT id, reader_id, pairing_code FROM tap WHERE android_id = ? LIMIT 1");
$stmt->execute([$input['android_id']]);
$tap = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tap || empty($tap['reader_id'])) {
    echo json_encode([
        'leitora_nome'   => 'Não configurada',
        'reader_id'      => null,
        'serial'         => null,
        'status_leitora' => 'sem_leitora',
        'api_ativa'      => false,
        'bateria'        => null,
        'conexao'        => null,
        'firmware'       => null,
        'ultima_atividade' => null,
        'mensagem'       => 'Nenhuma leitora de cartão vinculada a esta TAP. Configure o pairing_code no painel administrativo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$reader_id     = $tap['reader_id'];
$pairing_code  = $tap['pairing_code'] ?? 'Desconhecido';
$merchant_code = SUMUP_MERCHANT_CODE;

// Buscar configuração SumUp (token)
$stmt2 = $conn->prepare("SELECT token_sumup FROM payment_config LIMIT 1");
$stmt2->execute();
$payment = $stmt2->fetch(PDO::FETCH_ASSOC);
$sumup_token = $payment['token_sumup'] ?? SUMUP_TOKEN;

// ─────────────────────────────────────────────────────────────
// 1. Verificar se a API SumUp está ativa (valida o token)
// ─────────────────────────────────────────────────────────────
$api_ativa = false;
$ch_api = curl_init();
curl_setopt($ch_api, CURLOPT_URL, 'https://api.sumup.com/v0.1/me');
curl_setopt($ch_api, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_api, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $sumup_token]);
curl_setopt($ch_api, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch_api, CURLOPT_TIMEOUT, 10);
$resp_api = curl_exec($ch_api);
$http_api  = curl_getinfo($ch_api, CURLINFO_HTTP_CODE);
curl_close($ch_api);

if ($http_api === 200) {
    $api_ativa = true;
}

// ─────────────────────────────────────────────────────────────
// 2. Buscar detalhes do reader (nome e serial) via GET /readers/{id}
// ─────────────────────────────────────────────────────────────
$reader_nome   = $pairing_code;
$reader_serial = null;

$ch_info = curl_init();
curl_setopt($ch_info, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}");
curl_setopt($ch_info, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_info, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $sumup_token]);
curl_setopt($ch_info, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch_info, CURLOPT_TIMEOUT, 10);
$resp_info = curl_exec($ch_info);
$http_info  = curl_getinfo($ch_info, CURLINFO_HTTP_CODE);
curl_close($ch_info);

if ($http_info === 200) {
    $reader_data   = json_decode($resp_info, true);
    $reader_nome   = $reader_data['name']                 ?? $pairing_code;
    $reader_serial = $reader_data['device']['identifier'] ?? null;
}

// ─────────────────────────────────────────────────────────────
// 3. Verificar status real do reader via GET /readers/{id}/status
//    Endpoint oficial da SumUp que retorna:
//    - status: ONLINE | OFFLINE
//    - state: IDLE | BUSY | null
//    - battery_level, connection_type, firmware_version, last_activity
// ─────────────────────────────────────────────────────────────
$status_leitora   = 'offline';
$mensagem_status  = 'Leitora desligada ou sem conexão com a SumUp.';
$bateria          = null;
$conexao          = null;
$firmware         = null;
$ultima_atividade = null;

$ch_status = curl_init();
curl_setopt($ch_status, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/status");
curl_setopt($ch_status, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_status, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $sumup_token]);
curl_setopt($ch_status, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch_status, CURLOPT_TIMEOUT, 12);
$resp_status = curl_exec($ch_status);
$http_status  = curl_getinfo($ch_status, CURLINFO_HTTP_CODE);
curl_close($ch_status);

if ($http_status === 200) {
    $status_data = json_decode($resp_status, true);
    $data        = $status_data['data'] ?? [];

    $sumup_status = strtoupper($data['status'] ?? 'OFFLINE');
    $sumup_state  = $data['state'] ?? null;
    $bateria      = $data['battery_level'] ?? null;
    $conexao      = $data['connection_type'] ?? null;
    $firmware     = $data['firmware_version'] ?? null;
    $ultima_atividade = $data['last_activity'] ?? null;

    if ($sumup_status === 'ONLINE') {
        if ($sumup_state === 'BUSY') {
            $status_leitora  = 'online';
            $mensagem_status = 'Leitora ONLINE e ocupada com outra transação.';
        } else {
            $status_leitora  = 'online';
            $mensagem_status = 'Leitora ONLINE e pronta para uso.';
        }
    } else {
        // OFFLINE — verificar se nunca se conectou (last_activity null)
        if (empty($ultima_atividade)) {
            $status_leitora  = 'offline';
            $mensagem_status = 'Leitora OFFLINE. O dispositivo nunca se conectou à SumUp. '
                . 'Verifique se o SumUp Solo está logado na conta correta (merchant: ' . $merchant_code . ').';
        } else {
            $status_leitora  = 'offline';
            $mensagem_status = 'Leitora OFFLINE. Última atividade: ' . $ultima_atividade . '. '
                . 'Verifique se o dispositivo está ligado e com internet.';
        }
    }
} else {
    $status_leitora  = 'nao_encontrada';
    $mensagem_status = 'Não foi possível obter o status da leitora na SumUp (HTTP ' . $http_status . ').';
}

Logger::info("Reader Status Check", [
    'android_id'      => $input['android_id'],
    'reader_id'       => $reader_id,
    'reader_nome'     => $reader_nome,
    'reader_serial'   => $reader_serial,
    'pairing_code'    => $pairing_code,
    'status_leitora'  => $status_leitora,
    'api_ativa'       => $api_ativa,
    'bateria'         => $bateria,
    'conexao'         => $conexao,
    'firmware'        => $firmware,
    'ultima_atividade'=> $ultima_atividade,
    'http_status'     => $http_status
]);

echo json_encode([
    'leitora_nome'     => $reader_nome . ' (' . $pairing_code . ')',
    'reader_id'        => $reader_id,
    'serial'           => $reader_serial,
    'status_leitora'   => $status_leitora,
    'api_ativa'        => $api_ativa,
    'bateria'          => $bateria !== null ? round($bateria) . '%' : null,
    'conexao'          => $conexao,
    'firmware'         => $firmware,
    'ultima_atividade' => $ultima_atividade,
    'mensagem'         => $mensagem_status
], JSON_UNESCAPED_UNICODE);
