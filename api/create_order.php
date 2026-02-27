<?php
/**
 * API - Criar Pedido
 * POST /api/create_order.php
 *
 * CORREÇÃO v2.3.0:
 *   - ob_start() para bufferizar output e garantir JSON válido mesmo em erro fatal
 *   - register_shutdown_function com ob_clean() antes de escrever o erro
 *   - generateQRCode corrigido: $sumup->generateQRCode() (método da classe)
 *   - getReaderInfo protegido com try/catch para não bloquear resposta
 */

// Buffer de saída: permite limpar e reescrever mesmo após headers enviados
ob_start();

header('Content-Type: application/json');

// Proteção global: captura erros fatais e SEMPRE retorna JSON válido
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Limpar qualquer output parcial que possa ter sido gerado
        if (ob_get_level() > 0) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success'    => false,
            'error'      => 'Erro interno do servidor: ' . $error['message'],
            'error_type' => 'FATAL_ERROR',
            'debug'      => [
                'file' => basename($error['file']),
                'line' => $error['line'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
    }
});

require_once '../includes/config.php';
require_once '../includes/jwt.php';
require_once '../includes/sumup.php';
require_once '../includes/email_sender.php';

$headers = getallheaders();
$token   = $headers['token'] ?? $headers['Token'] ?? '';

// ── Autenticação JWT ──────────────────────────────────────────────────────────
if (!jwtValidate($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido', 'error_type' => 'AUTH_ERROR']);
    ob_end_flush();
    exit;
}

$input = $_POST;

// ── Validar campos obrigatórios ───────────────────────────────────────────────
$required_fields = ['valor', 'descricao', 'android_id', 'payment_method', 'quantidade', 'cpf'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "$field é obrigatório", 'error_type' => 'MISSING_FIELD']);
        ob_end_flush();
        exit;
    }
}

$payment_method = strtolower(trim($input['payment_method']));

try {
    $conn = getDBConnection();

    // ── Buscar TAP ────────────────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? LIMIT 1");
    $stmt->execute([$input['android_id']]);
    $tap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tap) {
        http_response_code(404);
        echo json_encode(['error' => 'TAP não encontrada', 'error_type' => 'TAP_NOT_FOUND']);
        ob_end_flush();
        exit;
    }

    // ── Criar pedido no banco ─────────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO `order`
            (tap_id, bebida_id, estabelecimento_id, method, valor, descricao, quantidade, cpf, checkout_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
    ");
    $stmt->execute([
        $tap['id'],
        $tap['bebida_id'],
        $tap['estabelecimento_id'],
        $payment_method,
        $input['valor'],
        $input['descricao'],
        $input['quantidade'],
        $input['cpf'],
    ]);
    $order_id = $conn->lastInsertId();

    Logger::info("Create Order - Pedido criado", [
        'order_id'       => $order_id,
        'payment_method' => $payment_method,
        'valor'          => $input['valor'],
        'android_id'     => $input['android_id'],
        'tap_id'         => $tap['id'],
    ]);

    // ── Instanciar SumUp ──────────────────────────────────────────────────────
    $sumup = new SumUpIntegration();

    $order_data = [
        'id'        => $order_id,
        'valor'     => $input['valor'],
        'descricao' => $input['descricao'],
    ];

    // ── PIX ───────────────────────────────────────────────────────────────────
    if ($payment_method === 'pix') {
        $result = $sumup->createCheckoutPix($order_data);

        if ($result) {
            $stmt = $conn->prepare("
                UPDATE `order`
                SET checkout_id = ?, pix_code = ?, response = ?
                WHERE id = ?
            ");
            $stmt->execute([$result['checkout_id'], $result['pix_code'], $result['response'], $order_id]);

            // Gerar QR Code — método da instância (CORREÇÃO: não é função global)
            $qr_code_base64 = '';
            if (!empty($result['pix_code'])) {
                $qr_code_base64 = $sumup->generateQRCode($result['pix_code']);
            }

            Logger::info("Create Order - PIX criado", [
                'order_id'    => $order_id,
                'checkout_id' => $result['checkout_id'],
                'qr_code_ok'  => !empty($qr_code_base64),
            ]);

            http_response_code(200);
            echo json_encode([
                'success'     => true,
                'checkout_id' => $result['checkout_id'],
                'qr_code'     => $qr_code_base64,
                'pix_code'    => $result['pix_code'],
            ]);
        } else {
            $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")->execute([$order_id]);
            Logger::error("Create Order - PIX falhou", ['order_id' => $order_id]);
            http_response_code(500);
            echo json_encode([
                'error'      => 'Erro ao criar checkout PIX. Verifique a configuração SumUp.',
                'error_type' => 'PIX_CHECKOUT_FAILED',
            ]);
        }

        ob_end_flush();
        exit;
    }

    // ── DÉBITO / CRÉDITO (Cloud API - SumUp Solo) ─────────────────────────────
    if (empty($tap['reader_id'])) {
        $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")->execute([$order_id]);
        Logger::error("Create Order - Sem reader_id", ['tap_id' => $tap['id']]);
        http_response_code(400);
        echo json_encode([
            'error'      => 'Esta TAP não possui leitora de cartão configurada. Configure o pairing_code no painel administrativo.',
            'error_type' => 'NO_READER_CONFIGURED',
        ]);
        ob_end_flush();
        exit;
    }

    Logger::info("Create Order - Iniciando checkout cartão", [
        'reader_id'    => $tap['reader_id'],
        'card_type'    => $payment_method,
        'valor'        => $input['valor'],
    ]);

    // Enviar checkout para a leitora via Cloud API
    $result = $sumup->createCheckoutCard($order_data, $tap['reader_id'], $payment_method);

    if (isset($result['checkout_id'])) {
        // Buscar nome/serial do reader — protegido com try/catch para não bloquear resposta
        $reader_name   = 'Leitora ' . substr($tap['reader_id'], -6);
        $reader_serial = null;
        try {
            $reader_info = $sumup->getReaderInfo($tap['reader_id']);
            if ($reader_info) {
                $reader_name   = $reader_info['name']   ?? $reader_name;
                $reader_serial = $reader_info['serial'] ?? null;
            }
        } catch (Exception $readerEx) {
            Logger::warning("Create Order - getReaderInfo falhou (não crítico)", [
                'error' => $readerEx->getMessage(),
            ]);
        }

        Logger::info("Create Order - Checkout cartão criado", [
            'checkout_id'   => $result['checkout_id'],
            'order_id'      => $order_id,
            'reader_id'     => $tap['reader_id'],
            'reader_name'   => $reader_name,
            'reader_serial' => $reader_serial,
        ]);

        $stmt = $conn->prepare("
            UPDATE `order`
            SET checkout_id = ?, response = ?, checkout_status = 'PENDING'
            WHERE id = ?
        ");
        $stmt->execute([$result['checkout_id'], $result['response'] ?? '', $order_id]);

        http_response_code(200);
        echo json_encode([
            'success'       => true,
            'checkout_id'   => $result['checkout_id'],
            'card_type'     => $payment_method,
            'reader_name'   => $reader_name,
            'reader_serial' => $reader_serial,
            'reader_id'     => $tap['reader_id'],
        ]);
    } else {
        $error_type = $result['error_type'] ?? 'CARD_CHECKOUT_FAILED';
        $error_msg  = $result['error_msg_pt'] ?? $result['error'] ?? 'Erro ao criar checkout de cartão.';

        Logger::error("Create Order - Checkout cartão falhou", [
            'tap_id'     => $tap['id'],
            'reader_id'  => $tap['reader_id'],
            'order_id'   => $order_id,
            'error_type' => $error_type,
            'error_msg'  => $error_msg,
        ]);

        $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")->execute([$order_id]);

        http_response_code(500);
        echo json_encode([
            'error'      => $error_msg,
            'error_type' => $error_type,
        ]);
    }

} catch (Throwable $e) {
    if (isset($conn) && isset($order_id)) {
        try {
            $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")->execute([$order_id]);
        } catch (Exception $dbEx) { /* ignorar */ }
    }

    Logger::error("Create Order - Exceção não tratada", [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success'    => false,
        'error'      => 'Erro interno: ' . $e->getMessage(),
        'error_type' => 'EXCEPTION',
        'debug'      => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ],
    ]);
}

ob_end_flush();
