<?php
/**
 * API - Refresh Token
 * POST /api/refresh_token.php
 * Versão 1.0
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$input = json_decode(file_get_contents('php://input'), true);

$refresh_token = $input['refresh_token'] ?? '';

if (empty($refresh_token)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Refresh token é obrigatório']);
    exit;
}

// Validar refresh token
$decoded = jwtValidateRefreshToken($refresh_token);

if ($decoded === false) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Refresh token inválido ou expirado']);
    exit;
}

// Verificar se está na blacklist
if (jwtIsBlacklisted($decoded->jti)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Refresh token foi revogado']);
    exit;
}

$user_id = $decoded->user_id;

// Buscar dados do usuário
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'Usuário não encontrado']);
    exit;
}

// Buscar estabelecimentos do usuário
$stmt = $conn->prepare("
    SELECT estabelecimento_id 
    FROM user_estabelecimento 
    WHERE user_id = ? AND status = 1
");
$stmt->execute([$user['id']]);
$estabelecimentos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Gerar novo token de acesso
$new_token = jwtEncode([
    'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'type' => $user['type'],
        'estabelecimentos' => $estabelecimentos
    ]
], 3600); // 1 hora

// Gerar novo refresh token
$new_refresh_token = jwtGenerateRefreshToken($user['id']);

// Adicionar refresh token antigo à blacklist
jwtBlacklist($decoded->jti, $decoded->exp);

Logger::info("Token renovado", [
    'user_id' => $user['id'],
    'email' => $user['email']
]);

http_response_code(200);
ob_clean();
echo json_encode([
    'token' => $new_token,
    'refresh_token' => $new_refresh_token,
    'expires_in' => 3600
]);
