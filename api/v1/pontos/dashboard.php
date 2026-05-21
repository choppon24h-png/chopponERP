<?php
/**
 * API v1 - Dashboard de Pontos
 * GET /api/v1/pontos/dashboard.php
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

// Buscar dados do cliente
$stmt = $conn->prepare("SELECT pontos_cashback, total_consumido FROM clientes WHERE id = ?");
$stmt->execute([$user->id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar últimos consumos
$stmtConsumos = $conn->prepare("
    SELECT id, bebida_nome, quantidade, valor_total, pontos_ganhos, data_consumo 
    FROM clientes_consumo 
    WHERE cliente_id = ? 
    ORDER BY data_consumo DESC LIMIT 5
");
$stmtConsumos->execute([$user->id]);
$ultimosConsumos = $stmtConsumos->fetchAll(PDO::FETCH_ASSOC);

// Buscar total de pontos ganhos (histórico)
$stmtTotalPontos = $conn->prepare("
    SELECT SUM(valor) as total 
    FROM cashback_historico 
    WHERE cliente_id = ? AND tipo = 'credito'
");
$stmtTotalPontos->execute([$user->id]);
$totalPontosGanhos = $stmtTotalPontos->fetchColumn() ?: 0;

http_response_code(200);
ob_clean();
echo json_encode([
    'success' => true,
    'data' => [
        'pontos_atuais' => (float)$cliente['pontos_cashback'],
        'total_pontos_ganhos' => (float)$totalPontosGanhos,
        'saldo_consumido' => (float)$cliente['total_consumido'],
        'ultimos_consumos' => $ultimosConsumos
    ]
]);
