<?php
/**
 * API - Controle de Liberação
 * POST /api/liberacao.php?action=iniciada|finalizada
 *
 * Campos aceitos em action=finalizada:
 *   checkout_id   (obrigatório)
 *   qtd_ml        — volume liberado em ml
 *   total_pulsos  — total de pulsos QP: reportado pelo ESP32 (auditoria)
 *
 * CORREÇÃO v3.2.0:
 *   action=finalizada agora debita o volume dispensado em tap.volume_consumido
 *   usando o tap_id do pedido, garantindo que taps.php mostre o saldo correto.
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

    if (!$order) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['error' => 'Pedido não encontrado']);
        exit;
    }

    // Proteção contra duplo processamento
    if ($order['status_liberacao'] === 'FINISHED') {
        ob_clean();
        echo json_encode(['success' => true, 'note' => 'already_finished']);
        exit;
    }

    $qtd_liberada     = $order['qtd_liberada'] + $qtd_ml;
    $status_liberacao = ($qtd_liberada >= $order['quantidade']) ? 'FINISHED' : 'PROCESSING';

    $conn->beginTransaction();
    try {
        // 1. Atualizar pedido
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
            $stmt = $conn->prepare("
                UPDATE `order`
                SET qtd_liberada     = ?,
                    status_liberacao = ?
                WHERE id = ?
            ");
            $stmt->execute([$qtd_liberada, $status_liberacao, $order['id']]);
        }

        // 2. Debitar volume no barril (tap)
        //    qtd_ml vem em ml → converter para litros (÷ 1000)
        $tap_consumido = 0;
        $tap_atual     = 0;

        if (!empty($order['tap_id']) && $qtd_ml > 0) {
            $volume_litros = $qtd_ml / 1000.0;

            $stmtTap = $conn->prepare("
                UPDATE tap
                SET volume_consumido = volume_consumido + ?
                WHERE id = ?
            ");
            $stmtTap->execute([$volume_litros, $order['tap_id']]);

            // Buscar valores atualizados para retornar ao Android
            $stmtT = $conn->prepare("SELECT volume, volume_consumido FROM tap WHERE id = ? LIMIT 1");
            $stmtT->execute([$order['tap_id']]);
            $tap_row = $stmtT->fetch();
            if ($tap_row) {
                $tap_consumido = (float)$tap_row['volume_consumido'];
                $tap_atual     = max(0, (float)$tap_row['volume'] - $tap_consumido);
            }
        }

        $conn->commit();

    } catch (\PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error'   => 'Erro ao finalizar liberação: ' . $e->getMessage(),
        ]);
        exit;
    }

    // ── Lançamento automático na conta bancária ──────────────────────────────────────────
    $lancamento_id = null;
    if ($status_liberacao === 'FINISHED') {
        try {
            require_once '../includes/LancamentoBancarioHelper.php';
            $stmt_reload = $conn->prepare("SELECT o.*, b.name AS bebida_nome FROM `order` o LEFT JOIN bebidas b ON o.bebida_id = b.id WHERE o.id = ? LIMIT 1");
            $stmt_reload->execute([$order['id']]);
            $order_reload = $stmt_reload->fetch(PDO::FETCH_ASSOC);
            if ($order_reload) {
                $res_lanc = LancamentoBancarioHelper::lancarPedido($conn, $order_reload, 0);
                $lancamento_id = $res_lanc['lancamento_id'];
                file_put_contents(
                    __DIR__ . '/../logs/lancamento_bancario.log',
                    date('Y-m-d H:i:s') . " - Liberacao Pedido #{$order['id']} | " . $res_lanc['message'] . "\n",
                    FILE_APPEND
                );
            }
        } catch (Exception $e_lanc) {
            file_put_contents(
                __DIR__ . '/../logs/lancamento_bancario.log',
                date('Y-m-d H:i:s') . " - ERRO Liberacao #{$order['id']}: " . $e_lanc->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }

    http_response_code(200);
    ob_clean();
    echo json_encode([
        'success'             => true,
        'status'              => $status_liberacao,
        'qtd_liberada'        => $qtd_liberada,
        'total_pulsos'        => $total_pulsos,
        'tap_volume_consumido'=> $tap_consumido,
        'tap_volume_atual'    => $tap_atual,
        'lancamento_bancario' => $lancamento_id,
    ]);

} else {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Ação inválida']);
}
