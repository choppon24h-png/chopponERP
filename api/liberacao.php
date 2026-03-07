<?php
/**
 * API - Controle de Liberação
 * POST /api/liberacao.php?action=iniciada|finalizada
 *
 * Campos aceitos em action=finalizada:
 *   checkout_id   (obrigatório)
 *   qtd_ml        — volume liberado em ml
 *   total_pulsos  — total de pulsos QP: reportado pelo ESP32 (auditoria)
 */

// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

// Validar token
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$action      = $_GET['action'] ?? '';
$input       = $_POST;
$checkout_id = $input['checkout_id'] ?? '';

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'checkout_id é obrigatório']);
    exit;
}

$conn = getDBConnection();

// ── action=iniciada ───────────────────────────────────────────────────────
if ($action === 'iniciada') {
    $stmt = $conn->prepare("
        UPDATE `order`
        SET status_liberacao = 'PROCESSING'
        WHERE checkout_id = ?
    ");
    $stmt->execute([$checkout_id]);

    http_response_code(200);
    ob_clean();
    echo json_encode(['success' => true]);

// ── action=finalizada ─────────────────────────────────────────────────────
} elseif ($action === 'finalizada') {

    $qtd_ml       = intval($input['qtd_ml']       ?? 0);
    $total_pulsos = intval($input['total_pulsos']  ?? 0); // QP: do ESP32

    $stmt = $conn->prepare("SELECT * FROM `order` WHERE checkout_id = ? LIMIT 1");
    $stmt->execute([$checkout_id]);
    $order = $stmt->fetch();

    if ($order) {
        $qtd_liberada    = $order['qtd_liberada'] + $qtd_ml;
        $status_liberacao = ($qtd_liberada >= $order['quantidade']) ? 'FINISHED' : 'PROCESSING';

        // Verifica se a coluna total_pulsos existe na tabela antes de atualizar
        // (compatibilidade com bancos que ainda não rodaram a migration)
        try {
            $stmt = $conn->prepare("
                UPDATE `order`
                SET qtd_liberada     = ?,
                    status_liberacao = ?,
                    total_pulsos     = ?
                WHERE id = ?
            ");
            $stmt->execute([$qtd_liberada, $status_liberacao, $total_pulsos, $order['id']]);
        } catch (\PDOException $e) {
            // Coluna total_pulsos não existe — atualiza sem ela
            $stmt = $conn->prepare("
                UPDATE `order`
                SET qtd_liberada     = ?,
                    status_liberacao = ?
                WHERE id = ?
            ");
            $stmt->execute([$qtd_liberada, $status_liberacao, $order['id']]);
        }

        http_response_code(200);
        ob_clean();
        echo json_encode([
            'success'       => true,
            'status'        => $status_liberacao,
            'qtd_liberada'  => $qtd_liberada,
            'total_pulsos'  => $total_pulsos,
        ]);
    } else {
        http_response_code(404);
        ob_clean();
        echo json_encode(['error' => 'Pedido não encontrado']);
    }

} else {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Ação inválida']);
}
