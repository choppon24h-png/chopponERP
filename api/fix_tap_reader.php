<?php
/**
 * Script de correção: Atualiza o reader_id da TAP 15
 * para o leitor SumUp Solo que está ONLINE
 *
 * ATENÇÃO: Remover este arquivo após execução!
 * Acesse: https://ochoppoficial.com.br/api/fix_tap_reader.php?key=choppon_fix_2026
 */

header('Content-Type: application/json');
require_once '../includes/config.php';

// Chave de segurança para evitar execução não autorizada
$key = $_GET['key'] ?? '';
if ($key !== 'choppon_fix_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

$conn = getDBConnection();

// Leitura atual da TAP 15
$stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = 15");
$stmt->execute();
$tap_antes = $stmt->fetch(PDO::FETCH_ASSOC);

// Reader ONLINE confirmado em 20/02/2026
// rdr_5VNZH5F3818TYTXVZD60N42M17 (device: 200300104229, model: solo)
$reader_online = 'rdr_5VNZH5F3818TYTXVZD60N42M17';

// Atualizar
$stmt = $conn->prepare("UPDATE tap SET reader_id = ? WHERE id = 15");
$ok = $stmt->execute([$reader_online]);

// Leitura após atualização
$stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = 15");
$stmt->execute();
$tap_depois = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'status'     => $ok ? 'OK' : 'ERRO',
    'tap_antes'  => $tap_antes,
    'tap_depois' => $tap_depois,
    'mensagem'   => $ok
        ? 'reader_id atualizado com sucesso. Teste o pagamento com cartao agora!'
        : 'Falha ao atualizar. Verifique o banco de dados.'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
