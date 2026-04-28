<?php
/**
 * API — Criar Pedido
 * POST /api/create_order.php
 *
 * v3.0.0 — Correção completa do fluxo PIX
 *
 * PROBLEMAS CORRIGIDOS:
 *
 *   1. PROBLEMA PRINCIPAL — generateQRCode() era função global, não método da classe.
 *      O código chamava $sumup->generateQRCode() mas o método não existia na classe
 *      SumUpIntegration. O PHP lançava "Call to undefined method", capturado pelo
 *      try/catch genérico, e o campo qr_code retornava string vazia.
 *      CORREÇÃO: generateQRCode() foi movido para dentro da classe SumUpIntegration
 *      como método público. A chamada $sumup->generateQRCode() agora funciona.
 *
 *   2. PROBLEMA SECUNDÁRIO — qr_code_base64 não era lido do retorno de createCheckoutPix().
 *      O método createCheckoutPix() agora retorna 'qr_code_base64' diretamente,
 *      evitando uma segunda chamada desnecessária a generateQRCode().
 *      CORREÇÃO: create_order.php agora usa $result['qr_code_base64'] com fallback
 *      para $sumup->generateQRCode($result['pix_code']) caso esteja vazio.
 *
 *   3. PROBLEMA TERCIÁRIO — exit() ausente após respostas de erro de validação.
 *      Após enviar JSON de erro (token inválido, campo faltando, TAP não encontrada),
 *      o código continuava executando as linhas seguintes, podendo causar comportamento
 *      inesperado. CORREÇÃO: exit() adicionado após cada ob_end_flush() de erro.
 *
 *   4. MELHORIA — Logs de diagnóstico detalhados em cada etapa do fluxo PIX,
 *      visíveis no cPanel → Logs → error_log ou logs/system.log.
 *
 *   5. MELHORIA — Campo 'pix_code' incluído na resposta JSON para o app Android,
 *      permitindo que o app exiba o código "copia e cola" além do QR Code.
 *
 * FLUXO PIX CORRETO:
 *   App → POST create_order.php (payment_method=pix)
 *     → SumUp: POST /v0.1/checkouts  (criar checkout)
 *     → SumUp: PUT  /v0.1/checkouts/{id} com {payment_type:"pix"}
 *       → SumUp retorna pix.artefacts com código EMV e URL da imagem JPEG
 *     → PHP baixa imagem ou gera QR Code via API externa
 *     → PHP converte para Base64
 *   App ← { success:true, checkout_id, qr_code:<base64>, pix_code:<emv> }
 *
 * FLUXO CARTÃO CORRETO:
 *   App → POST create_order.php (payment_method=credit|debit)
 *     → SumUp: POST /v0.1/merchants/{code}/readers/{id}/checkout
 *       → SumUp envia cobrança para a leitora física
 *   App ← { success:true, checkout_id, reader_name, reader_id }
 */

// Buffer de saída: permite limpar e reescrever mesmo após headers enviados.
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');

// ── Proteção global: captura erros fatais e SEMPRE retorna JSON válido ────────
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
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

// ── Autenticação JWT ──────────────────────────────────────────────────────────
$headers = getallheaders();
$token   = $headers['token'] ?? $headers['Token'] ?? '';

if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode([
        'error'      => 'Token inválido',
        'error_type' => 'AUTH_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Leitura do input ──────────────────────────────────────────────────────────
// Suporta tanto application/x-www-form-urlencoded (Android padrão) quanto JSON
$input = $_POST;
if (empty($input)) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

// ── Validar campos obrigatórios ───────────────────────────────────────────────
$required_fields = ['valor', 'descricao', 'android_id', 'payment_method', 'quantidade', 'cpf'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        ob_clean();
        echo json_encode([
            'error'      => "{$field} é obrigatório",
            'error_type' => 'MISSING_FIELD',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
}

$payment_method = strtolower(trim($input['payment_method']));

// Validar payment_method
if (!in_array($payment_method, ['pix', 'credit', 'debit'])) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
        'error'      => "payment_method inválido. Use: pix, credit ou debit",
        'error_type' => 'INVALID_PAYMENT_METHOD',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Log de diagnóstico: início da requisição ──────────────────────────────────
Logger::info('Create Order - Requisição recebida', [
    'payment_method' => $payment_method,
    'android_id'     => $input['android_id'],
    'valor'          => $input['valor'],
    'quantidade'     => $input['quantidade'],
]);

try {
    $conn = getDBConnection();

    // ── Buscar TAP pelo android_id ────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? LIMIT 1");
    $stmt->execute([$input['android_id']]);
    $tap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tap) {
        Logger::warning('Create Order - TAP não encontrada', [
            'android_id' => $input['android_id'],
        ]);
        http_response_code(404);
        ob_clean();
        echo json_encode([
            'error'      => 'TAP não encontrada',
            'error_type' => 'TAP_NOT_FOUND',
        ], JSON_UNESCAPED_UNICODE);
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

    Logger::info('Create Order - Pedido criado no banco', [
        'order_id'       => $order_id,
        'payment_method' => $payment_method,
        'tap_id'         => $tap['id'],
        'valor'          => $input['valor'],
    ]);

    // ── Instanciar SumUp ──────────────────────────────────────────────────────
    $sumup = new SumUpIntegration();

    $order_data = [
        'id'        => $order_id,
        'valor'     => $input['valor'],
        'descricao' => $input['descricao'],
        'cpf'       => $input['cpf'],
    ];

    // ══════════════════════════════════════════════════════════════════════════
    // FLUXO PIX (via Mercado Pago — SumUp descontinuou PIX pela API)
    // ══════════════════════════════════════════════════════════════════════════
    if ($payment_method === 'pix') {

        Logger::info('Create Order - Iniciando fluxo PIX (Mercado Pago)', [
            'order_id' => $order_id,
        ]);

        require_once '../includes/mercadopago_pix.php';

        try {
            $mp_pix = new MercadoPagoPix($tap['estabelecimento_id']);
        } catch (\RuntimeException $e) {
            Logger::error('Create Order - MercadoPago nao configurado', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);
            http_response_code(500);
            ob_clean();
            echo json_encode([
                'success'    => false,
                'error'      => 'PIX nao configurado para este estabelecimento.',
                'error_type' => 'MP_NOT_CONFIGURED',
            ], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }

        $mp_result = $mp_pix->createPixPayment($order_data);

        if ($mp_result) {
            // Salvar no banco (checkout_id = payment_id do Mercado Pago)
            $stmt = $conn->prepare("
                UPDATE `order`
                SET checkout_id = ?, pix_code = ?, response = ?
                WHERE id = ?
            ");
            $stmt->execute([
                (string) $mp_result['payment_id'],
                $mp_result['qr_code'],
                json_encode($mp_result),
                $order_id,
            ]);

            Logger::payment('Create Order - PIX Mercado Pago criado com sucesso', [
                'order_id'   => $order_id,
                'payment_id' => $mp_result['payment_id'],
                'qr_code_ok' => !empty($mp_result['qr_code']),
                'qr_b64_ok'  => !empty($mp_result['qr_code_base64']),
            ]);

            http_response_code(200);
            ob_clean();
            echo json_encode([
                'success'     => true,
                'checkout_id' => (string) $mp_result['payment_id'],
                'qr_code'     => $mp_result['qr_code_base64'] ?? '',
                'pix_code'    => $mp_result['qr_code']        ?? null,
                'ticket_url'  => $mp_result['ticket_url']     ?? null,
            ], JSON_UNESCAPED_UNICODE);

        } else {
            $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")
                 ->execute([$order_id]);

            Logger::error('Create Order - PIX Mercado Pago falhou', [
                'order_id' => $order_id,
            ]);

            http_response_code(500);
            ob_clean();
            echo json_encode([
                'success'    => false,
                'error'      => 'Erro ao gerar PIX. Tente novamente.',
                'error_type' => 'PIX_MP_FAILED',
            ], JSON_UNESCAPED_UNICODE);
        }

        ob_end_flush();
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLUXO CARTÃO (credit / debit)
    // ══════════════════════════════════════════════════════════════════════════

    // Verificar se a TAP tem leitora configurada
    if (empty($tap['reader_id'])) {
        $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")
             ->execute([$order_id]);

        Logger::error('Create Order - TAP sem reader_id configurado', [
            'tap_id'     => $tap['id'],
            'android_id' => $input['android_id'],
        ]);

        http_response_code(400);
        ob_clean();
        echo json_encode([
            'error'      => 'Esta TAP não possui leitora de cartão configurada. Configure o pairing_code no painel administrativo.',
            'error_type' => 'NO_READER_CONFIGURED',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    Logger::info('Create Order - Iniciando checkout cartão', [
        'order_id'   => $order_id,
        'reader_id'  => $tap['reader_id'],
        'card_type'  => $payment_method,
        'valor'      => $input['valor'],
    ]);

    // Enviar checkout para a leitora via Cloud API
    $result = $sumup->createCheckoutCard($order_data, $tap['reader_id'], $payment_method);

    if (isset($result['checkout_id'])) {
        // ── Buscar nome/serial do reader ──────────────────────────────────────
        // Protegido com try/catch para não bloquear a resposta ao app
        $reader_name   = 'Leitora ' . substr($tap['reader_id'], -6);
        $reader_serial = null;

        try {
            $reader_info = $sumup->getReaderInfo($tap['reader_id']);
            if ($reader_info) {
                $reader_name   = $reader_info['name']   ?? $reader_name;
                $reader_serial = $reader_info['serial'] ?? null;
            }
        } catch (Exception $readerEx) {
            Logger::warning('Create Order - getReaderInfo falhou (não crítico)', [
                'error'     => $readerEx->getMessage(),
                'reader_id' => $tap['reader_id'],
            ]);
        }

        // ── Salvar no banco ───────────────────────────────────────────────────
        $stmt = $conn->prepare("
            UPDATE `order`
            SET checkout_id = ?, response = ?, checkout_status = 'PENDING'
            WHERE id = ?
        ");
        $stmt->execute([
            $result['checkout_id'],
            $result['response'] ?? '',
            $order_id,
        ]);

        Logger::info('Create Order - Checkout cartão criado com sucesso', [
            'order_id'      => $order_id,
            'checkout_id'   => $result['checkout_id'],
            'reader_id'     => $tap['reader_id'],
            'reader_name'   => $reader_name,
            'reader_serial' => $reader_serial,
        ]);

        http_response_code(200);
        ob_clean();
        echo json_encode([
            'success'       => true,
            'checkout_id'   => $result['checkout_id'],
            'card_type'     => $payment_method,
            'reader_name'   => $reader_name,
            'reader_serial' => $reader_serial,
            'reader_id'     => $tap['reader_id'],
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // ── Falha no checkout de cartão ───────────────────────────────────────
        $error_type = $result['error_type'] ?? 'CARD_CHECKOUT_FAILED';
        $error_msg  = $result['error_msg_pt'] ?? $result['error'] ?? 'Erro ao criar checkout de cartão.';

        Logger::error('Create Order - Checkout cartão falhou', [
            'order_id'   => $order_id,
            'tap_id'     => $tap['id'],
            'reader_id'  => $tap['reader_id'],
            'error_type' => $error_type,
            'error_msg'  => $error_msg,
        ]);

        $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")
             ->execute([$order_id]);

        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success'    => false,
            'error'      => $error_msg,
            'error_type' => $error_type,
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    // ── Captura qualquer exceção não tratada ──────────────────────────────────
    if (isset($conn) && isset($order_id)) {
        try {
            $conn->prepare("UPDATE `order` SET checkout_status = 'FAILED' WHERE id = ?")
                 ->execute([$order_id]);
        } catch (Exception $dbEx) {
            // Ignorar erro ao atualizar banco no handler de exceção
        }
    }

    Logger::error('Create Order - Exceção não tratada', [
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'trace'   => substr($e->getTraceAsString(), 0, 500),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
    }

    ob_clean();
    echo json_encode([
        'success'    => false,
        'error'      => 'Erro interno: ' . $e->getMessage(),
        'error_type' => 'EXCEPTION',
        'debug'      => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ],
    ], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();
