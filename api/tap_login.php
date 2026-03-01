<?php
/**
 * API - Login de TAP
 * POST /api/tap_login.php
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$input = $_POST;

$android_id = $input['android_id'] ?? '';
$senha = $input['senha'] ?? '';

if (empty($android_id) || empty($senha)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Android ID e senha são obrigatórios']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? AND status = 1 LIMIT 1");
$stmt->execute([$android_id]);
$tap = $stmt->fetch();

if ($tap && password_verify($senha, $tap['senha'])) {
    http_response_code(200);
    ob_clean();
    echo json_encode([
        'success' => true,
    ]);
} else {
    http_response_code(200);
    ob_clean();
    echo json_encode(['error' => 'Credenciais inválidas']);
}
?>