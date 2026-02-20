<?php
/**
 * API - Status da Leitora de Cartão SumUp
 * POST /api/reader_status.php
 *
 * Retorna o status da leitora SumUp vinculada ao android_id informado:
 * - nome da leitora (pairing_code)
 * - reader_id
 * - status: online | offline | sem_leitora
 * - api_ativa: true | false (valida o token SumUp)
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
        'status_leitora' => 'sem_leitora',
        'api_ativa'      => false,
        'mensagem'       => 'Nenhuma leitora de cartão vinculada a esta TAP.'
    ]);
    exit;
}

$reader_id    = $tap['reader_id'];
$pairing_code = $tap['pairing_code'] ?? 'Desconhecido';

// Buscar configuração SumUp (token)
$stmt2 = $conn->prepare("SELECT token_sumup FROM payment_config LIMIT 1");
$stmt2->execute();
$payment = $stmt2->fetch(PDO::FETCH_ASSOC);
$sumup_token = $payment['token_sumup'] ?? SUMUP_TOKEN;

// Verificar se a API SumUp está ativa (valida o token)
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

// Verificar status online/offline do reader via tentativa de checkout mínimo
// A SumUp não tem endpoint de "ping" direto; usamos checkout com valor 0.01
// e verificamos se o erro é READER_OFFLINE ou outro (ex: sucesso ou READER_BUSY)
$status_leitora = 'offline';
$mensagem_status = 'Leitora desligada ou sem conexão.';

$merchant_code = SUMUP_MERCHANT_CODE;
$ch_reader = curl_init();
curl_setopt($ch_reader, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/checkout");
curl_setopt($ch_reader, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_reader, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch_reader, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $sumup_token,
    'Content-Type: application/json'
]);
// Payload mínimo de teste (será cancelado imediatamente se aceito)
$payload = json_encode([
    'total_amount' => ['value' => 1, 'currency' => 'BRL', 'minor_unit' => 2],
    'description'  => 'PING_STATUS_CHECK',
    'card_type'    => 'debit',
    'return_url'   => 'https://ochoppoficial.com.br/api/webhook.php'
]);
curl_setopt($ch_reader, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch_reader, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch_reader, CURLOPT_TIMEOUT, 12);
$resp_reader = curl_exec($ch_reader);
$http_reader  = curl_getinfo($ch_reader, CURLINFO_HTTP_CODE);
curl_close($ch_reader);

$resp_json = json_decode($resp_reader, true);
$error_type = $resp_json['errors']['type'] ?? '';

if ($http_reader === 201) {
    // Checkout criado com sucesso = reader ONLINE
    $status_leitora  = 'online';
    $mensagem_status = 'Leitora online e pronta para uso.';

    // Cancelar imediatamente o checkout de teste
    $client_tx_id = $resp_json['data']['client_transaction_id'] ?? null;
    if ($client_tx_id) {
        $ch_cancel = curl_init();
        curl_setopt($ch_cancel, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/terminate");
        curl_setopt($ch_cancel, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_cancel, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch_cancel, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $sumup_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch_cancel, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch_cancel, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch_cancel, CURLOPT_TIMEOUT, 8);
        curl_exec($ch_cancel);
        curl_close($ch_cancel);
    }
} elseif ($error_type === 'READER_BUSY') {
    // Reader ocupado = está ONLINE (processando outra transação)
    $status_leitora  = 'online';
    $mensagem_status = 'Leitora online (ocupada com outra transação).';
} elseif ($error_type === 'READER_OFFLINE') {
    $status_leitora  = 'offline';
    $mensagem_status = 'Leitora desligada ou sem conexão. Ligue o SumUp Solo.';
} elseif ($error_type === 'READER_NOT_FOUND') {
    $status_leitora  = 'nao_encontrada';
    $mensagem_status = 'Leitora não encontrada na conta SumUp. Verifique o pareamento.';
} else {
    // Qualquer outro erro (ex: validação) indica que o reader respondeu = ONLINE
    $status_leitora  = 'online';
    $mensagem_status = 'Leitora online.';
}

Logger::info("Reader Status Check", [
    'android_id'     => $input['android_id'],
    'reader_id'      => $reader_id,
    'pairing_code'   => $pairing_code,
    'status_leitora' => $status_leitora,
    'api_ativa'      => $api_ativa,
    'http_reader'    => $http_reader,
    'error_type'     => $error_type
]);

echo json_encode([
    'leitora_nome'   => $pairing_code,
    'reader_id'      => $reader_id,
    'status_leitora' => $status_leitora,
    'api_ativa'      => $api_ativa,
    'mensagem'       => $mensagem_status
], JSON_UNESCAPED_UNICODE);
