<?php
/**
 * verify_tap_mac.php — Valida se um MAC BLE pertence à TAP do tablet
 *
 * Chamado pelo Android durante o scan BLE inteligente (fallback de descoberta).
 * O Android escaneia dispositivos com nome CHOPP_ e valida cada MAC encontrado
 * antes de salvar e conectar.
 *
 * POST params:
 *   android_id  → ANDROID_ID do tablet (Settings.Secure.ANDROID_ID)
 *   mac         → endereço MAC BLE do dispositivo encontrado (ex: DC:B4:D9:99:3B:96)
 *
 * Resposta:
 *   { "valid": true,  "tap_id": 5, "name": "Torneira 01" }   → MAC pertence a este tablet
 *   { "valid": false, "message": "..." }                      → MAC não pertence a este tablet
 *
 * Autenticação: JWT no header 'token' (padrão ApiHelper.java)
 *
 * Lógica de validação:
 *   1. Busca a TAP pelo android_id na tabela `tap`
 *   2. Compara o esp32_mac cadastrado com o MAC enviado (case-insensitive)
 *   3. Se a TAP não tiver esp32_mac cadastrado, retorna valid=false
 *      (o admin deve cadastrar o MAC no painel antes do primeiro uso)
 *
 * Rate limiting: máx 30 req/minuto por android_id para evitar brute force de MACs
 */

require_once '../includes/config.php';
require_once '../includes/jwt.php';

header('Content-Type: application/json; charset=utf-8');

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$jwt_token = $_SERVER['HTTP_TOKEN'] ?? '';
if (empty($jwt_token)) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'message' => 'Token JWT ausente.']);
    exit;
}

$payload = jwtValidate($jwt_token);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'message' => 'Token JWT inválido ou expirado.']);
    exit;
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$android_id = trim($_POST['android_id'] ?? '');
$mac        = strtoupper(trim($_POST['mac'] ?? ''));

if (empty($android_id)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'android_id obrigatório.']);
    exit;
}

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'mac obrigatório.']);
    exit;
}

// Validar formato do MAC (XX:XX:XX:XX:XX:XX)
if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'Formato de MAC inválido.']);
    exit;
}

$conn = getDBConnection();

// ── Rate limiting: máx 30 validações por android_id por minuto ───────────────
// Usa tabela de log de pedidos como proxy; se não existir, ignora
try {
    $rate_stmt = $conn->prepare("
        SELECT COUNT(*) FROM activity_logs
        WHERE description LIKE ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $rate_stmt->execute(['verify_tap_mac:' . $android_id . '%']);
    $rate_count = (int)$rate_stmt->fetchColumn();
    if ($rate_count >= 30) {
        http_response_code(429);
        echo json_encode(['valid' => false, 'message' => 'Muitas tentativas. Aguarde 1 minuto.']);
        exit;
    }
} catch (Exception $e) {
    // Tabela activity_logs não existe — ignora rate limiting
}

// ── Buscar TAP pelo android_id ────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, esp32_mac, android_id, status
    FROM tap
    WHERE android_id = ?
    LIMIT 1
");
$stmt->execute([$android_id]);
$tap = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Log de auditoria ──────────────────────────────────────────────────────────
try {
    $log = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
        VALUES (0, 'verify_tap_mac', ?, ?, NOW())
    ");
    $log->execute([
        'verify_tap_mac:' . $android_id . ':mac=' . $mac . ':found=' . ($tap ? 'yes' : 'no'),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
} catch (Exception $e) { /* tabela não existe */ }

// ── TAP não encontrada para este android_id ───────────────────────────────────
if (!$tap) {
    echo json_encode([
        'valid'   => false,
        'message' => 'Nenhuma TAP cadastrada para este dispositivo. Contate o administrador.',
    ]);
    exit;
}

// ── TAP inativa ───────────────────────────────────────────────────────────────
if ((int)$tap['status'] !== 1) {
    echo json_encode([
        'valid'   => false,
        'message' => 'TAP inativa. Contate o administrador.',
    ]);
    exit;
}

// ── TAP sem MAC cadastrado ────────────────────────────────────────────────────
if (empty($tap['esp32_mac'])) {
    echo json_encode([
        'valid'   => false,
        'message' => 'MAC do ESP32 não cadastrado para esta TAP. Cadastre o MAC no painel admin.',
    ]);
    exit;
}

// ── Comparar MAC (case-insensitive) ──────────────────────────────────────────
$mac_cadastrado = strtoupper(trim($tap['esp32_mac']));

if ($mac_cadastrado === $mac) {
    echo json_encode([
        'valid'  => true,
        'tap_id' => (int)$tap['id'],
    ]);
} else {
    echo json_encode([
        'valid'   => false,
        'message' => 'MAC não corresponde à TAP deste dispositivo.',
    ]);
}
