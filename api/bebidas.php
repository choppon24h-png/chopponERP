<?php
/**
 * API - Listar Bebidas
 * GET /api/bebidas.php
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
        SELECT b.*, e.name as estabelecimento_name
        FROM bebidas b
        INNER JOIN estabelecimentos e ON b.estabelecimento_id = e.id
        ORDER BY b.name
    ");
} else {
    // Lista apenas dos estabelecimentos do usuário
    $estabelecimentos = implode(',', $user->estabelecimentos);
    $stmt = $conn->query("
        SELECT b.*, e.name as estabelecimento_name
        FROM bebidas b
        INNER JOIN estabelecimentos e ON b.estabelecimento_id = e.id
        WHERE b.estabelecimento_id IN ($estabelecimentos)
        ORDER BY b.name
    ");
}

$bebidas = $stmt->fetchAll();

// Adicionar URL completa da imagem
foreach ($bebidas as &$bebida) {
    $bebida['image_url'] = SITE_URL . '/' . $bebida['image'];
}

http_response_code(200);
ob_clean();
echo json_encode($bebidas);
