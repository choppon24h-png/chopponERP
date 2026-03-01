<?php
/**
 * API - Promoções
 * Endpoint para verificar promoções ativas e aplicar descontos
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../includes/config.php';
require_once '../includes/promocoes.php';

// Função para retornar erro
function returnError($message, $code = 400) {
    http_response_code($code);
    ob_clean();
    echo json_encode(['error' => $message]);
    exit;
}

// Função para retornar sucesso
function returnSuccess($data) {
    ob_clean();
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// Verificar método
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // GET /api/promocoes.php?estabelecimento_id=1
    // Retorna promoções ativas de um estabelecimento
    
    $estabelecimento_id = $_GET['estabelecimento_id'] ?? null;
    
    if (!$estabelecimento_id) {
        returnError('estabelecimento_id é obrigatório');
    }
    
    try {
        $promocoes = getPromocoesAtivas($estabelecimento_id);
        
        $resultado = [];
        foreach ($promocoes as $promo) {
            $bebidas = getPromocaoBebidas($promo['id']);
            
            $resultado[] = [
                'id' => $promo['id'],
                'nome' => $promo['nome'],
                'descricao' => $promo['descricao'],
                'data_inicio' => $promo['data_inicio'],
                'data_fim' => $promo['data_fim'],
                'tipo_regra' => $promo['tipo_regra'],
                'cupons' => $promo['cupons'] ? explode(',', $promo['cupons']) : [],
                'cashback_valor' => $promo['cashback_valor'],
                'cashback_ml' => $promo['cashback_ml'],
                'bebidas' => array_map(function($b) {
                    return [
                        'id' => $b['id'],
                        'nome' => $b['name'],
                        'valor' => $b['valor'],
                        'valor_promo' => $b['valor_promo']
                    ];
                }, $bebidas)
            ];
        }
        
        returnSuccess($resultado);
        
    } catch (Exception $e) {
        returnError('Erro ao buscar promoções: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    // POST /api/promocoes.php
    // Body: { "action": "verificar", "bebida_id": 1, "estabelecimento_id": 1, "cupom": "#cupom24h", "cashback": 150 }
    // Retorna se a bebida tem promoção e calcula desconto
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        returnError('Dados inválidos');
    }
    
    $action = $data['action'] ?? null;
    
    if ($action === 'verificar') {
        $bebida_id = $data['bebida_id'] ?? null;
        $estabelecimento_id = $data['estabelecimento_id'] ?? null;
        $cupom = $data['cupom'] ?? null;
        $cashback = $data['cashback'] ?? null;
        
        if (!$bebida_id || !$estabelecimento_id) {
            returnError('bebida_id e estabelecimento_id são obrigatórios');
        }
        
        try {
            $resultado = calcularDescontoPromocao($bebida_id, $estabelecimento_id, $cupom, $cashback);
            returnSuccess($resultado);
            
        } catch (Exception $e) {
            returnError('Erro ao verificar promoção: ' . $e->getMessage(), 500);
        }
    }
    
    if ($action === 'validar_cupom') {
        $cupom = $data['cupom'] ?? null;
        $promocao_id = $data['promocao_id'] ?? null;
        
        if (!$cupom || !$promocao_id) {
            returnError('cupom e promocao_id são obrigatórios');
        }
        
        try {
            $valido = validarCupomPromocao($cupom, $promocao_id);
            returnSuccess(['valido' => $valido]);
            
        } catch (Exception $e) {
            returnError('Erro ao validar cupom: ' . $e->getMessage(), 500);
        }
    }
    
    if ($action === 'registrar_uso') {
        $promocao_id = $data['promocao_id'] ?? null;
        $bebida_id = $data['bebida_id'] ?? null;
        $pedido_id = $data['pedido_id'] ?? null;
        $cupom = $data['cupom'] ?? null;
        $cashback = $data['cashback'] ?? null;
        $ml_liberado = $data['ml_liberado'] ?? null;
        
        if (!$promocao_id || !$bebida_id) {
            returnError('promocao_id e bebida_id são obrigatórios');
        }
        
        try {
            $resultado = registrarUsoPromocao($promocao_id, $bebida_id, $pedido_id, $cupom, $cashback, $ml_liberado);
            returnSuccess(['registrado' => $resultado]);
            
        } catch (Exception $e) {
            returnError('Erro ao registrar uso: ' . $e->getMessage(), 500);
        }
    }
    
    returnError('Ação não reconhecida');
}

returnError('Método não permitido', 405);
