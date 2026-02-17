<?php
/**
 * API: get_esp32_mac.php
 * 
 * Objetivo: Retornar o MAC do ESP32 vinculado ao tablet (android_id)
 * 
 * Parâmetros:
 *   - android_id: ID único do Android (obtido via Secure.getString())
 * 
 * Resposta:
 *   - esp32_mac: MAC address do ESP32 vinculado (ex: DC:B4:D9:99:3B:96)
 *   - status: "success" ou "error"
 *   - message: Mensagem descritiva
 * 
 * Exemplo de Uso:
 *   POST /api/get_esp32_mac.php
 *   {
 *     "android_id": "a1b2c3d4e5f6g7h8"
 *   }
 * 
 * Resposta de Sucesso:
 *   {
 *     "status": "success",
 *     "esp32_mac": "DC:B4:D9:99:3B:96",
 *     "bebida": "Chopp Artesanal",
 *     "message": "MAC do ESP32 obtido com sucesso"
 *   }
 * 
 * Resposta de Erro:
 *   {
 *     "status": "error",
 *     "esp32_mac": null,
 *     "message": "Tablet não encontrado no sistema"
 *   }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Incluir arquivo de configuração do banco de dados
require_once '../config/database.php';

// Receber dados do POST
$input = json_decode(file_get_contents('php://input'), true);

// Validar se android_id foi fornecido
if (!isset($input['android_id']) || empty($input['android_id'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'esp32_mac' => null,
        'message' => 'android_id é obrigatório'
    ]);
    exit;
}

$android_id = $input['android_id'];

try {
    // Preparar e executar query
    $query = "SELECT esp32_mac, bebida_name FROM tap WHERE android_id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Erro ao preparar query: " . $conn->error);
    }
    
    $stmt->bind_param("s", $android_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Erro ao executar query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Tablet não encontrado
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'esp32_mac' => null,
            'message' => 'Tablet não encontrado no sistema'
        ]);
        $stmt->close();
        exit;
    }
    
    $row = $result->fetch_assoc();
    $esp32_mac = $row['esp32_mac'];
    $bebida_name = $row['bebida_name'];
    
    // Validar se MAC foi configurado
    if (empty($esp32_mac)) {
        http_response_code(200);
        echo json_encode([
            'status' => 'warning',
            'esp32_mac' => null,
            'bebida' => $bebida_name,
            'message' => 'MAC do ESP32 não foi configurado ainda. Modo compatibilidade ativo.'
        ]);
        $stmt->close();
        exit;
    }
    
    // Sucesso: MAC encontrado
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'esp32_mac' => $esp32_mac,
        'bebida' => $bebida_name,
        'message' => 'MAC do ESP32 obtido com sucesso'
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'esp32_mac' => null,
        'message' => 'Erro ao consultar banco de dados: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
