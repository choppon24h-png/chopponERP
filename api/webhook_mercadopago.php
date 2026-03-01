<?php
/**
 * Webhook Mercado Pago
 * Recebe notificações de pagamento do Mercado Pago
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/MercadoPagoAPI.php';

// Log de entrada
$log_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'get' => $_GET,
    'post' => $_POST,
    'body' => file_get_contents('php://input')
];

file_put_contents(__DIR__ . '/../logs/webhook_mercadopago.log', json_encode($log_data) . "\n", FILE_APPEND);

// Responder imediatamente ao Mercado Pago
http_response_code(200);
header('Content-Type: application/json');
ob_clean();
echo json_encode(['status' => 'received']);

// Processar webhook em background
try {
    // Obter dados do webhook
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    // Verificar tipo de notificação
    if (!isset($data['type']) && !isset($data['topic'])) {
        throw new Exception('Tipo de notificação não identificado');
    }
    
    $type = $data['type'] ?? $data['topic'] ?? '';
    
    // Processar apenas notificações de pagamento
    if ($type === 'payment' || $type === 'merchant_order') {
        $payment_id = $data['data']['id'] ?? $data['id'] ?? null;
        
        if (!$payment_id) {
            throw new Exception('ID de pagamento não encontrado');
        }
        
        // Buscar royalty pelo payment_id
        $stmt = $conn->prepare("SELECT id, estabelecimento_id, valor_royalties FROM royalties WHERE payment_id = ? LIMIT 1");
        $stmt->bind_param("s", $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($royalty = $result->fetch_assoc()) {
            // Buscar configuração do Mercado Pago
            $stmt = $conn->prepare("SELECT * FROM mercadopago_config WHERE estabelecimento_id = ? AND status = 1 LIMIT 1");
            $stmt->bind_param("i", $royalty['estabelecimento_id']);
            $stmt->execute();
            $config_result = $stmt->get_result();
            
            if ($config = $config_result->fetch_assoc()) {
                // Inicializar API
                $mp = new MercadoPagoAPI($config['access_token'], $config['ambiente']);
                
                // Consultar status do pagamento
                $payment_info = $mp->consultarPagamento($payment_id);
                
                if ($payment_info['success']) {
                    $status = $payment_info['data']['status'] ?? '';
                    
                    // Mapear status do Mercado Pago para status do sistema
                    $status_map = [
                        'approved' => 'aprovado',
                        'pending' => 'processando',
                        'in_process' => 'processando',
                        'rejected' => 'recusado',
                        'cancelled' => 'cancelado',
                        'refunded' => 'cancelado'
                    ];
                    
                    $novo_status = $status_map[$status] ?? 'pendente';
                    
                    // Atualizar royalty
                    if ($novo_status === 'aprovado') {
                        $stmt = $conn->prepare("UPDATE royalties SET payment_status = ?, paid_at = NOW(), status = 'pago' WHERE id = ?");
                    } else {
                        $stmt = $conn->prepare("UPDATE royalties SET payment_status = ? WHERE id = ?");
                    }
                    $stmt->bind_param("si", $novo_status, $royalty['id']);
                    $stmt->execute();
                    
                    // Registrar log
                    $stmt = $conn->prepare("INSERT INTO royalties_payment_log (royalty_id, estabelecimento_id, metodo_pagamento, acao, status, response_data, ip_address, user_agent) VALUES (?, ?, 'mercadopago', 'webhook', ?, ?, ?, ?)");
                    $response_json = json_encode($payment_info['data']);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $stmt->bind_param("iissss", $royalty['id'], $royalty['estabelecimento_id'], $status, $response_json, $ip, $user_agent);
                    $stmt->execute();
                }
            }
        }
    }
    
} catch (Exception $e) {
    // Log de erro
    file_put_contents(__DIR__ . '/../logs/webhook_mercadopago_error.log', 
        date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", 
        FILE_APPEND
    );
}
