<?php
/**
 * Limpar Logs
 */

require_once '../../includes/config.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Apenas Admin Geral
if (!isAdminGeral()) {
    echo json_encode(['success' => false, 'message' => 'Sem permissão']);
    exit;
}

$modulo = $_GET['modulo'] ?? '';
$mes_ano = date('Y-m');

$arquivos_log = [
    'royalties' => "../../logs/royalties_{$mes_ano}.log",
    'stripe' => "../../logs/stripe_{$mes_ano}.log",
    'cora' => "../../logs/cora_{$mes_ano}.log",
    'email' => "../../logs/email_{$mes_ano}.log",
    'payments' => "../../logs/paymentslogs.log"
];

if (!isset($arquivos_log[$modulo])) {
    echo json_encode(['success' => false, 'message' => 'Módulo inválido']);
    exit;
}

$arquivo = $arquivos_log[$modulo];

if (file_exists($arquivo)) {
    if (unlink($arquivo)) {
        echo json_encode(['success' => true, 'message' => 'Logs limpos com sucesso']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao deletar arquivo']);
    }
} else {
    echo json_encode(['success' => true, 'message' => 'Arquivo de log não existe']);
}
