<?php
/**
 * API - Finalizar Venda (Protocolo BLE Industrial v2.3)
 * POST /api/finish_sale.php
 *
 * Chamado APÓS receber DONE do ESP32.
 * Confirma a venda com o volume real dispensado e DEBITA o barril.
 *
 * Campos POST obrigatórios:
 *   checkout_id   — ID do pedido
 *
 * Campos POST opcionais:
 *   command_id    — ID do comando BLE
 *   session_id    — SESSION_ID da venda
 *   ml_real       — Volume real dispensado (alias: ml_dispensado, qtd_ml)
 *   total_pulsos  — Total de pulsos QP: (auditoria)
 *   android_id    — ID do dispositivo Android
 *
 * Resposta de sucesso:
 *   { "success": true, "status": "FINISHED", "qtd_liberada": 300, "ml_real": 298,
 *     "tap_volume_consumido": 1500, "tap_volume_atual": 48500 }
 *
 * CORREÇÃO v3.2.0:
 *   Após marcar o pedido como FINISHED, debita o volume dispensado
 *   diretamente em tap.volume_consumido usando o tap_id do próprio pedido.
 *   O volume_atual exibido na tela taps.php é calculado como
 *   (tap.volume - tap.volume_consumido), portanto basta incrementar
 *   volume_consumido para que a tela reflita o saldo real.
 */
ob_start();
header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$headers = getallheaders();
$token   = $headers['token'] ?? $headers['Token'] ?? '';
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

// ── Validação de campos (com aliases para compatibilidade Android) ─────────────
$checkout_id  = trim($_POST['checkout_id']  ?? '');
$command_id   = trim($_POST['command_id']   ?? '');
$session_id   = trim($_POST['session_id']   ?? '');
// Aceita ml_real, ml_dispensado ou qtd_ml
$ml_real      = intval($_POST['ml_real'] ?? $_POST['ml_dispensado'] ?? $_POST['qtd_ml'] ?? 0);
$total_pulsos = intval($_POST['total_pulsos'] ?? 0);
$android_id   = trim($_POST['android_id'] ?? '');

if (empty($checkout_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Campo obrigatório: checkout_id']);
    exit;
}

$conn = getDBConnection();

// ── Buscar pedido com tap_id ──────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM `order` WHERE checkout_id = ? LIMIT 1");
$stmt->execute([$checkout_id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'Pedido não encontrado']);
    exit;
}

// ── Proteção contra duplo processamento ──────────────────────────────────────
// Se o pedido já foi finalizado, retorna sucesso sem debitar novamente
if ($order['status_liberacao'] === 'FINISHED') {
    // Buscar dados atuais do barril para retornar
    $tap_consumido = 0;
    $tap_atual     = 0;
    if (!empty($order['tap_id'])) {
        $stmtT = $conn->prepare("SELECT volume, volume_consumido FROM tap WHERE id = ? LIMIT 1");
        $stmtT->execute([$order['tap_id']]);
        $tap_row = $stmtT->fetch();
        if ($tap_row) {
            $tap_consumido = (float)$tap_row['volume_consumido'];
            $tap_atual     = max(0, (float)$tap_row['volume'] - $tap_consumido);
        }
    }
    ob_clean();
    echo json_encode([
        'success'             => true,
        'status'              => 'FINISHED',
        'qtd_liberada'        => $order['qtd_liberada'],
        'ml_real'             => $ml_real,
        'total_pulsos'        => $total_pulsos,
        'tap_volume_consumido'=> $tap_consumido,
        'tap_volume_atual'    => $tap_atual,
        'note'                => 'already_finished',
    ]);
    exit;
}

// ── Atualizar ble_sales (se command_id disponível) ────────────────────────────
if (!empty($command_id)) {
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'DONE', ml_real = ?
            WHERE command_id = ? AND checkout_id = ?
        ");
        $stmt->execute([$ml_real, $command_id, $checkout_id]);
    } catch (\PDOException $e) {
        // Tabela pode não existir em ambiente legado — continuar
    }
} else {
    try {
        $stmt = $conn->prepare("
            UPDATE ble_sales
            SET status = 'DONE', ml_real = ?
            WHERE checkout_id = ? AND status = 'STARTED'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$ml_real, $checkout_id]);
    } catch (\PDOException $e) {
        // Ignorar
    }
}

// ── Calcular volume total liberado ────────────────────────────────────────────
// ml_real vem em mililitros do Android; quantidade no pedido também é em ml
$qtd_liberada     = $order['qtd_liberada'] + $ml_real;
$status_liberacao = ($qtd_liberada >= $order['quantidade']) ? 'FINISHED' : 'PROCESSING';

// ── Iniciar transação para garantir consistência pedido + barril ──────────────
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
        // Fallback sem total_pulsos
        $stmt = $conn->prepare("
            UPDATE `order`
            SET qtd_liberada     = ?,
                status_liberacao = ?
            WHERE id = ?
        ");
        $stmt->execute([$qtd_liberada, $status_liberacao, $order['id']]);
    }

    // 2. Debitar volume no barril (tap) usando tap_id do pedido
    //    volume_consumido é armazenado em Litros (L), ml_real vem em ml
    //    Conversão: ml_real / 1000 = litros
    $tap_consumido = 0;
    $tap_atual     = 0;

    if (!empty($order['tap_id']) && $ml_real > 0) {
        $volume_litros = $ml_real / 1000.0;

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
        'error'   => 'Erro ao finalizar venda: ' . $e->getMessage(),
    ]);
    exit;
}

// ── Lançamento automático na conta bancária ──────────────────────────────────────────
// Somente quando o pedido foi totalmente finalizado (FINISHED)
$lancamento_id = null;
if ($status_liberacao === 'FINISHED') {
    try {
        require_once '../includes/LancamentoBancarioHelper.php';
        // Recarregar pedido com dados atualizados
        $stmt_reload = $conn->prepare("SELECT o.*, b.name AS bebida_nome FROM `order` o LEFT JOIN bebidas b ON o.bebida_id = b.id WHERE o.id = ? LIMIT 1");
        $stmt_reload->execute([$order['id']]);
        $order_reload = $stmt_reload->fetch(PDO::FETCH_ASSOC);
        if ($order_reload) {
            $resultado_lancamento = LancamentoBancarioHelper::lancarPedido($conn, $order_reload, 0);
            $lancamento_id = $resultado_lancamento['lancamento_id'];
            file_put_contents(
                __DIR__ . '/../logs/lancamento_bancario.log',
                date('Y-m-d H:i:s') . " - Pedido #{$order['id']} | " . $resultado_lancamento['message'] . "\n",
                FILE_APPEND
            );
        }
    } catch (Exception $e_lanc) {
        // Não bloquear a resposta por erro no lançamento bancário
        file_put_contents(
            __DIR__ . '/../logs/lancamento_bancario.log',
            date('Y-m-d H:i:s') . " - ERRO Pedido #{$order['id']}: " . $e_lanc->getMessage() . "\n",
            FILE_APPEND
        );
    }
}

// ── Notificação Telegram de venda (FINISHED) ─────────────────────────────────────────
if ($status_liberacao === 'FINISHED') {
    try {
        require_once '../includes/telegram.php';
        $stmt_tg = $conn->prepare("
            SELECT o.*, b.name AS bebida_nome, b.brand AS bebida_marca,
                   e.name AS estabelecimento_nome
            FROM `order` o
            LEFT JOIN bebidas b ON o.bebida_id = b.id
            LEFT JOIN estabelecimentos e ON o.estabelecimento_id = e.id
            WHERE o.id = ? AND o.telegram_notificado = 0
            LIMIT 1
        ");
        $stmt_tg->execute([$order['id']]);
        $order_tg = $stmt_tg->fetch(PDO::FETCH_ASSOC);
        if ($order_tg) {
            $telegram = new TelegramBot($conn);
            $msg_venda = TelegramBot::formatVendaMessage($order_tg);
            if ($telegram->sendMessage($order_tg['estabelecimento_id'], $msg_venda, 'venda', $order_tg['id'])) {
                $stmt_tg2 = $conn->prepare("UPDATE `order` SET telegram_notificado = 1 WHERE id = ?");
                $stmt_tg2->execute([$order_tg['id']]);
                file_put_contents(__DIR__ . '/../logs/telegram.log',
                    date('Y-m-d H:i:s') . " - finish_sale: Telegram enviado pedido #{$order['id']}\n", FILE_APPEND);
            }
        }
    } catch (Exception $e_tg) {
        file_put_contents(__DIR__ . '/../logs/telegram.log',
            date('Y-m-d H:i:s') . " - finish_sale: ERRO Telegram pedido #{$order['id']}: " . $e_tg->getMessage() . "\n", FILE_APPEND);
    }
}

ob_clean();
echo json_encode([
    'success'             => true,
    'status'              => $status_liberacao,
    'qtd_liberada'        => $qtd_liberada,
    'ml_real'             => $ml_real,
    'total_pulsos'        => $total_pulsos,
    'tap_volume_consumido'=> $tap_consumido,
    'tap_volume_atual'    => $tap_atual,
    'lancamento_bancario' => $lancamento_id,
]);
