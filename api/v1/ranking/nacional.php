<?php
/**
 * API v1 - Ranking Nacional
 * GET /api/v1/ranking/nacional.php
 */
ob_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

require_once '../../../includes/config.php';
require_once '../../../includes/jwt.php';

$headers = getallheaders();
$token = $headers['Authorization'] ?? $headers['Token'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (empty($token) || !jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$conn = getDBConnection();

// Buscar top 10 clientes por total consumido
$stmt = $conn->query("
    SELECT c.id, c.nome, c.pontos_cashback, c.total_consumido, e.name as estabelecimento_nome
    FROM clientes c
    LEFT JOIN estabelecimentos e ON c.estabelecimento_id = e.id
    WHERE c.status = 1
    ORDER BY c.total_consumido DESC
    LIMIT 10
");
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mascarar nomes para privacidade (ex: João S.)
foreach ($ranking as &$user) {
    $partes = explode(' ', trim($user['nome']));
    if (count($partes) > 1) {
        $user['nome_exibicao'] = $partes[0] . ' ' . substr($partes[count($partes)-1], 0, 1) . '.';
    } else {
        $user['nome_exibicao'] = $partes[0];
    }
    unset($user['nome']); // Remove nome completo
}

http_response_code(200);
ob_clean();
echo json_encode([
    'success' => true,
    'data' => $ranking
]);
