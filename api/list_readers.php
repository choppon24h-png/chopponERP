<?php
/**
 * API - Listar Readers SumUp
 * GET /api/list_readers.php
 *
 * Retorna todos os readers cadastrados na conta SumUp com:
 * - reader_id, name (pairing_code), serial, status (ONLINE/OFFLINE)
 * - battery_level, connection_type, last_activity
 *
 * Usado pelo painel admin para exibir dropdown de vinculação TAP ↔ Reader
 */
header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/logger.php';

// Verificar autenticação de admin
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$sumup_token   = SUMUP_TOKEN;
$merchant_code = SUMUP_MERCHANT_CODE;

// ─────────────────────────────────────────────────────────────
// 1. Listar todos os readers da conta
// ─────────────────────────────────────────────────────────────
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp  = curl_exec($ch);
$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro ao consultar readers na SumUp', 'http' => $http]);
    exit;
}

$data    = json_decode($resp, true);
$readers = $data['items'] ?? [];
$result  = [];

foreach ($readers as $rd) {
    $reader_id = $rd['id'] ?? '';
    $name      = $rd['name'] ?? '';
    $serial    = $rd['device']['identifier'] ?? null;

    // Buscar status individual
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/status");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    $resp2 = curl_exec($ch2);
    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    $status_data    = [];
    $status         = 'OFFLINE';
    $battery        = null;
    $conn_type      = null;
    $last_activity  = null;

    if ($http2 === 200) {
        $sd            = json_decode($resp2, true);
        $status_data   = $sd['data'] ?? [];
        $status        = strtoupper($status_data['status'] ?? 'OFFLINE');
        $battery       = $status_data['battery_level'] ?? null;
        $conn_type     = $status_data['connection_type'] ?? null;
        $last_activity = $status_data['last_activity'] ?? null;
    }

    // Pairing code = nome sem sufixo "RE" (se aplicável)
    $pairing_code = preg_replace('/RE$/', '', $name);

    $result[] = [
        'reader_id'     => $reader_id,
        'name'          => $name,
        'pairing_code'  => $pairing_code,
        'serial'        => $serial,
        'status'        => $status,
        'battery'       => $battery !== null ? round($battery) . '%' : null,
        'connection'    => $conn_type,
        'last_activity' => $last_activity,
        'label'         => $name . ' | Serial: ' . ($serial ?? 'N/A') . ' | ' . $status
    ];
}

// Ordenar: ONLINE primeiro
usort($result, function($a, $b) {
    if ($a['status'] === 'ONLINE' && $b['status'] !== 'ONLINE') return -1;
    if ($a['status'] !== 'ONLINE' && $b['status'] === 'ONLINE') return 1;
    return 0;
});

Logger::info("list_readers - consultado", ['count' => count($result)]);

echo json_encode(['readers' => $result], JSON_UNESCAPED_UNICODE);
