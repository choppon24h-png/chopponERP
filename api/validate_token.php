<?php
/**
 * API - Validar Token
 * GET /api/validate_token.php
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
    http_response_code(401);
    ob_clean();
    echo json_encode(['valid' => false, 'error' => 'Token não fornecido']);
    exit;
}

$decoded = jwtDecode($token);

if ($decoded !== false) {
    http_response_code(200);
    ob_clean();
    echo json_encode(['valid' => $decoded]);
} else {
    http_response_code(401);
    ob_clean();
    echo json_encode(['valid' => false, 'error' => 'Token inválido']);
}
