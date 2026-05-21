<?php
/**
 * API v1 - Login Admin (Acesso Master)
 * POST /api/v1/auth/admin_login.php
 */
ob_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../../includes/config.php';
require_once '../../../includes/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Email e senha são obrigatórios']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // Buscar estabelecimentos do usuário
    $stmt = $conn->prepare("
        SELECT estabelecimento_id 
        FROM user_estabelecimento 
        WHERE user_id = ? AND status = 1
    ");
    $stmt->execute([$user['id']]);
    $estabelecimentos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $token = jwtEncode([
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'type' => $user['type'],
            'estabelecimentos' => $estabelecimentos,
            'role' => 'admin'
        ]
    ]);
    
    http_response_code(200);
    ob_clean();
    echo json_encode([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'type' => $user['type']
        ],
        'isAdmin' => $user['type'] == 1
    ]);
} else {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Credenciais inválidas']);
}
