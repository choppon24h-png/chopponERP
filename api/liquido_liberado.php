<?php
/**
 * API - Líquido Liberado
 * POST /api/liquido_liberado.php
 * Atualiza volume consumido da TAP
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

$input = json_decode(file_get_contents('php://input'), true);

$android_id = $input['android_id'] ?? '';
$qtd_ml = $input['qtd_ml'] ?? 0;

if (empty($android_id) || $qtd_ml <= 0) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'android_id e qtd_ml são obrigatórios']);
    exit;
}

$conn = getDBConnection();

// Buscar TAP
$stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? LIMIT 1");
$stmt->execute([$android_id]);
$tap = $stmt->fetch();

if ($tap) {
    // Atualizar volume consumido (qtd_ml vem em centilitros, dividir por 100)
    $volume_adicional = $qtd_ml / 100;
    
    $stmt = $conn->prepare("
        UPDATE tap 
        SET volume_consumido = volume_consumido + ?
        WHERE id = ?
    ");
    $stmt->execute([$volume_adicional, $tap['id']]);
    
    http_response_code(200);
    ob_clean();
    echo json_encode([true]);
} else {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'TAP não encontrada']);
}
