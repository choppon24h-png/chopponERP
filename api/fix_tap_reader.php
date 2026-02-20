<?php
/**
 * Script de Correção de Vinculação TAP ↔ Reader SumUp
 *
 * USO:
 *   GET  /api/fix_tap_reader.php?key=choppon_fix_2026
 *        → Lista todas as TAPs e todos os readers disponíveis na SumUp
 *
 *   GET  /api/fix_tap_reader.php?key=choppon_fix_2026&tap_id=15&reader_id=rdr_XXXXX
 *        → Vincula a TAP 15 ao reader especificado
 *
 * SEGURANÇA: Remova este arquivo após uso!
 */
header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
if ($key !== 'choppon_fix_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

require_once '../includes/config.php';
$conn = getDBConnection();

$sumup_token   = SUMUP_TOKEN;
$merchant_code = SUMUP_MERCHANT_CODE;

// Buscar todos os readers da conta SumUp
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp_readers = curl_exec($ch);
$http_readers = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$readers_raw  = ($http_readers === 200) ? json_decode($resp_readers, true)['items'] ?? [] : [];
$readers_info = [];

foreach ($readers_raw as $rd) {
    $rid = $rd['id'] ?? '';
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$rid}/status");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    $resp_st = curl_exec($ch2);
    $http_st = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    $st_data = ($http_st === 200) ? (json_decode($resp_st, true)['data'] ?? []) : [];

    $readers_info[] = [
        'reader_id'     => $rid,
        'name'          => $rd['name'] ?? '',
        'serial'        => $rd['device']['identifier'] ?? null,
        'status'        => strtoupper($st_data['status'] ?? 'OFFLINE'),
        'battery'       => $st_data['battery_level'] ?? null,
        'connection'    => $st_data['connection_type'] ?? null,
        'last_activity' => $st_data['last_activity'] ?? null,
    ];
}

// Buscar todas as TAPs do banco
$stmt = $conn->query("SELECT id, android_id, reader_id, pairing_code FROM tap ORDER BY id");
$taps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ação de vinculação: ?tap_id=X&reader_id=rdr_XXXXX
$tap_id    = isset($_GET['tap_id'])    ? (int)$_GET['tap_id']    : null;
$reader_id = isset($_GET['reader_id']) ? trim($_GET['reader_id']) : null;

if ($tap_id && $reader_id) {
    $valid_reader = null;
    foreach ($readers_info as $r) {
        if ($r['reader_id'] === $reader_id) {
            $valid_reader = $r;
            break;
        }
    }

    if (!$valid_reader) {
        http_response_code(400);
        echo json_encode([
            'error'            => 'reader_id inválido ou não encontrado na conta SumUp',
            'reader_id_enviado' => $reader_id,
            'readers_validos'  => array_column($readers_info, 'reader_id'),
        ]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = ?");
    $stmt->execute([$tap_id]);
    $tap_antes = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tap_antes) {
        http_response_code(404);
        echo json_encode(['error' => "TAP {$tap_id} não encontrada"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE tap SET reader_id = ?, pairing_code = ? WHERE id = ?");
    $stmt->execute([$reader_id, $valid_reader['name'], $tap_id]);

    $stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = ?");
    $stmt->execute([$tap_id]);
    $tap_depois = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'       => 'OK',
        'tap_antes'    => $tap_antes,
        'tap_depois'   => $tap_depois,
        'reader_info'  => $valid_reader,
        'mensagem'     => "TAP {$tap_id} vinculada ao reader '{$valid_reader['name']}' (serial: {$valid_reader['serial']}) com sucesso!",
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Listagem padrão
echo json_encode([
    'instrucoes' => [
        'listar'   => '/api/fix_tap_reader.php?key=choppon_fix_2026',
        'vincular' => '/api/fix_tap_reader.php?key=choppon_fix_2026&tap_id=15&reader_id=rdr_XXXXX',
    ],
    'taps'    => $taps,
    'readers' => $readers_info,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
