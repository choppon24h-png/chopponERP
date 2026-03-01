<?php
/**
 * API - Logout
 * POST /api/logout.php
 * Versão 1.0 - Revoga tokens
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Token é obrigatório']);
    exit;
}

// Decodificar token para obter JTI
$decoded = jwtDecode($token);

if ($decoded === false) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

// Adicionar à blacklist
if (isset($decoded->jti) && isset($decoded->exp)) {
    jwtBlacklist($decoded->jti, $decoded->exp);
    
    Logger::info("Logout realizado", [
        'user_id' => $decoded->user->id ?? 'unknown',
        'jti' => $decoded->jti
    ]);
}

// Verificar se refresh token foi enviado
$input = json_decode(file_get_contents('php://input'), true);
$refresh_token = $input['refresh_token'] ?? '';

if (!empty($refresh_token)) {
    $decoded_refresh = jwtDecode($refresh_token);
    if ($decoded_refresh !== false && isset($decoded_refresh->jti) && isset($decoded_refresh->exp)) {
        jwtBlacklist($decoded_refresh->jti, $decoded_refresh->exp);
    }
}

http_response_code(200);
ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Logout realizado com sucesso'
]);
