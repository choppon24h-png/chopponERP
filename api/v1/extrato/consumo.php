<?php
/**
 * API v1 - Extrato de Consumo
 * GET /api/v1/extrato/consumo.php
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

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = ($page - 1) * $limit;

$conn = getDBConnection();

// Total de registros
$stmtTotal = $conn->prepare("SELECT COUNT(*) FROM clientes_consumo WHERE cliente_id = ?");
$stmtTotal->execute([$user->id]);
$total = $stmtTotal->fetchColumn();

// Buscar consumos
$stmt = $conn->prepare("
    SELECT cc.id, cc.bebida_nome, cc.quantidade, cc.valor_unitario, cc.valor_total, 
           cc.pontos_ganhos, cc.data_consumo, e.name as estabelecimento_nome
    FROM clientes_consumo cc
    LEFT JOIN estabelecimentos e ON cc.estabelecimento_id = e.id
    WHERE cc.cliente_id = ? 
    ORDER BY cc.data_consumo DESC 
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $user->id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$consumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

http_response_code(200);
ob_clean();
echo json_encode([
    'success' => true,
    'data' => $consumos,
    'pagination' => [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);
