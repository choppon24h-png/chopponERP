<?php
/**
 * API - Criar Pedido
 * POST /api/create_order.php
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/sumup.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

// Validar token
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validar campos obrigatórios
$required_fields = ['valor', 'descricao', 'android_id', 'payment_method', 'quantidade', 'cpf'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['error' => "$field é obrigatório"]);
        exit;
    }
}

$conn = getDBConnection();

// Buscar TAP
$stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? LIMIT 1");
$stmt->execute([$input['android_id']]);
$tap = $stmt->fetch();

if (!$tap) {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'TAP não encontrada']);
    exit;
}

// Criar pedido
$stmt = $conn->prepare("
    INSERT INTO `order` (tap_id, bebida_id, estabelecimento_id, method, valor, descricao, quantidade, cpf, checkout_status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
");

$stmt->execute([
    $tap['id'],
    $tap['bebida_id'],
    $tap['estabelecimento_id'],
    $input['payment_method'],
    $input['valor'],
    $input['descricao'],
    $input['quantidade'],
    $input['cpf']
]);

$order_id = $conn->lastInsertId();

// Processar pagamento via SumUp
$sumup = new SumUpIntegration();

$order_data = [
    'id' => $order_id,
    'valor' => $input['valor'],
    'descricao' => $input['descricao']
];

if ($input['payment_method'] === 'pix') {
    // Criar checkout PIX
    $result = $sumup->createCheckoutPix($order_data);
    
    if ($result) {
        // Atualizar pedido com dados do checkout
        $stmt = $conn->prepare("
            UPDATE `order` 
            SET checkout_id = ?, pix_code = ?, response = ?
            WHERE id = ?
        ");
        $stmt->execute([$result['checkout_id'], $result['pix_code'], $result['response'], $order_id]);
        
        // Gerar QR Code
        $qr_code_base64 = generateQRCode($result['pix_code']);
        
        http_response_code(200);
        ob_clean();
        echo json_encode([
            'checkout_id' => $result['checkout_id'],
            'qr_code' => $qr_code_base64
        ]);
    } else {
        http_response_code(500);
        ob_clean();
        echo json_encode(['error' => 'Erro ao criar checkout PIX']);
    }
} else {
    // Criar checkout para cartão
    if (empty($tap['reader_id'])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['error' => 'TAP não possui leitora de cartão configurada']);
        exit;
    }
    
    $result = $sumup->createCheckoutCard($order_data, $tap['reader_id'], $input['payment_method']);
    
    if ($result) {
        // Atualizar pedido com dados do checkout
        $stmt = $conn->prepare("
            UPDATE `order` 
            SET checkout_id = ?, response = ?, checkout_status = 'PENDING'
            WHERE id = ?
        ");
        $stmt->execute([$result['checkout_id'], $result['response'], $order_id]);
        
        http_response_code(200);
        ob_clean();
        echo json_encode([
            'checkout_id' => $result['checkout_id']
        ]);
    } else {
        // Marcar como falhou
        $stmt = $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?");
        $stmt->execute([$order_id]);
        
        http_response_code(500);
        ob_clean();
        echo json_encode(['error' => 'Erro ao criar checkout de cartão']);
    }
}
