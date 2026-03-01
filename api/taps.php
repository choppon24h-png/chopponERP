<?php
/**
 * API - Listar TAPs
 * GET /api/taps.php
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

// Validar token
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$decoded = jwtDecode($token);
$user = $decoded->user;

$conn = getDBConnection();

// Se for admin geral, lista todas
if ($user->type == 1) {
    $stmt = $conn->query("
        SELECT t.*, 
               b.name as bebida_name,
               e.name as estabelecimento_name,
               (t.volume - t.volume_consumido) as volume_atual
        FROM tap t
        INNER JOIN bebidas b ON t.bebida_id = b.id
        INNER JOIN estabelecimentos e ON t.estabelecimento_id = e.id
        WHERE t.status = 1
        ORDER BY t.id
    ");
} else {
    // Lista apenas dos estabelecimentos do usuário
    $estabelecimentos = implode(',', $user->estabelecimentos);
    $stmt = $conn->query("
        SELECT t.*, 
               b.name as bebida_name,
               e.name as estabelecimento_name,
               (t.volume - t.volume_consumido) as volume_atual
        FROM tap t
        INNER JOIN bebidas b ON t.bebida_id = b.id
        INNER JOIN estabelecimentos e ON t.estabelecimento_id = e.id
        WHERE t.status = 1 AND t.estabelecimento_id IN ($estabelecimentos)
        ORDER BY t.id
    ");
}

$taps = $stmt->fetchAll();

http_response_code(200);
ob_clean();
echo json_encode($taps);
