<?php
/**
 * Script de correção: Restaura o reader_id original da TAP 15
 * para o leitor SumUp Solo A4RZALFHYRE (rdr_1JHCGHNM3095NBKJP2CMDWJTXC)
 *
 * ATENÇÃO: Remover este arquivo após execução!
 * Acesse: https://ochoppoficial.com.br/api/fix_tap_reader.php?key=choppon_fix_2026
 */

header('Content-Type: application/json');
require_once '../includes/config.php';

$key = $_GET['key'] ?? '';
if ($key !== 'choppon_fix_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = 15");
$stmt->execute();
$tap_antes = $stmt->fetch(PDO::FETCH_ASSOC);

// Restaurar para o reader correto (pareado com pairing_code A4RZALFHYRE)
$reader_correto = 'rdr_1JHCGHNM3095NBKJP2CMDWJTXC';

$stmt = $conn->prepare("UPDATE tap SET reader_id = ? WHERE id = 15");
$ok = $stmt->execute([$reader_correto]);

$stmt = $conn->prepare("SELECT id, android_id, reader_id, pairing_code FROM tap WHERE id = 15");
$stmt->execute();
$tap_depois = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'status'     => $ok ? 'OK' : 'ERRO',
    'tap_antes'  => $tap_antes,
    'tap_depois' => $tap_depois,
    'mensagem'   => $ok
        ? 'reader_id restaurado para o leitor correto (A4RZALFHYRE). Ligue o SumUp Solo e tente novamente!'
        : 'Falha ao atualizar. Verifique o banco de dados.'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
