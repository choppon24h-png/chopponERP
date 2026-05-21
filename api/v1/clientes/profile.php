<?php
/**
 * API v1 - Perfil do Cliente
 * GET /api/v1/clientes/profile.php
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

$decoded = jwtDecode($token);
$user = $decoded->user;

if ($user->role !== 'cliente') {
    http_response_code(403);
    ob_clean();
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT id, estabelecimento_id, nome, cpf, email, telefone, 
           endereco_rua, endereco_numero, endereco_complemento, 
           endereco_bairro, endereco_cidade, endereco_estado, endereco_cep, 
           data_nascimento, pontos_cashback, total_consumido, status, created_at
    FROM clientes 
    WHERE id = ? AND status = 1
");
$stmt->execute([$user->id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cliente) {
    // Buscar último consumo
    $stmtConsumo = $conn->prepare("
        SELECT data_consumo, valor_total, bebida_nome 
        FROM clientes_consumo 
        WHERE cliente_id = ? 
        ORDER BY data_consumo DESC LIMIT 1
    ");
    $stmtConsumo->execute([$user->id]);
    $ultimoConsumo = $stmtConsumo->fetch(PDO::FETCH_ASSOC);
    
    $cliente['ultimo_consumo'] = $ultimoConsumo;
    
    http_response_code(200);
    ob_clean();
    echo json_encode(['success' => true, 'data' => $cliente]);
} else {
    http_response_code(404);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
}
