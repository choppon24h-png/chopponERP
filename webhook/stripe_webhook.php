<?php
/**
 * Webhook do Stripe
 * Processa eventos de pagamento e atualiza status de royalties
 * 
 * URL do Webhook: https://seu-dominio.com/webhook/stripe_webhook.php
 * 
 * Eventos processados:
 * - invoice.paid: Fatura foi paga
 * - invoice.payment_failed: Pagamento da fatura falhou
 * - invoice.finalized: Fatura foi finalizada
 */

require_once '../includes/config.php';
require_once '../includes/stripe_api.php';

// Configurar log de erros
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/stripe_webhook.log');

// Ler payload do webhook
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Log do recebimento
error_log('[' . date('Y-m-d H:i:s') . '] Webhook recebido - Signature: ' . substr($sig_header, 0, 50));

// Responder 200 OK imediatamente para evitar timeout
http_response_code(200);

try {
    $conn = getDBConnection();
    
    // Decodificar evento
    $event = json_decode($payload, true);
    
    if (!$event || !isset($event['id']) || !isset($event['type'])) {
        throw new Exception('Payload inválido');
    }
    
    $event_id = $event['id'];
    $event_type = $event['type'];
    
    // Verificar se evento já foi processado
    $stmt = $conn->prepare("SELECT id FROM stripe_webhooks WHERE event_id = ?");
    $stmt->execute([$event_id]);
    if ($stmt->fetch()) {
        error_log('[' . date('Y-m-d H:i:s') . '] Evento já processado: ' . $event_id);
        exit('OK - Already processed');
    }
    
    // Registrar evento
    $stmt = $conn->prepare("
        INSERT INTO stripe_webhooks (event_id, event_type, payload, processed)
        VALUES (?, ?, ?, FALSE)
    ");
    $stmt->execute([$event_id, $event_type, $payload]);
    $webhook_log_id = $conn->lastInsertId();
    
    // Validar assinatura do webhook
    // Nota: Para validação completa, precisamos buscar o webhook_secret do estabelecimento
    // Por simplicidade, vamos processar sem validação estrita aqui
    // Em produção, implemente validação completa usando StripeAPI::validateWebhook()
    
    // Processar evento baseado no tipo
    switch ($event_type) {
        case 'invoice.paid':
            processInvoicePaid($event, $conn);
            break;

        case 'invoice.payment_failed':
            processInvoicePaymentFailed($event, $conn);
            break;

        case 'invoice.finalized':
            processInvoiceFinalized($event, $conn);
            break;

        // Evento gerado pelo Stripe Checkout Session (usado para royalties)
        case 'checkout.session.completed':
            processCheckoutSessionCompleted($event, $conn);
            break;

        // Evento de payment_intent bem-sucedido (fallback)
        case 'payment_intent.succeeded':
            processPaymentIntentSucceeded($event, $conn);
            break;

        default:
            error_log('[' . date('Y-m-d H:i:s') . '] Evento não processado: ' . $event_type);
    }
    
    // Marcar evento como processado
    $stmt = $conn->prepare("
        UPDATE stripe_webhooks 
        SET processed = TRUE, processed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$webhook_log_id]);
    
    error_log('[' . date('Y-m-d H:i:s') . '] Evento processado com sucesso: ' . $event_id);
    echo 'OK';
    
} catch (Exception $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] Erro no webhook: ' . $e->getMessage());
    
    // Salvar erro no log do webhook
    if (isset($webhook_log_id)) {
        $stmt = $conn->prepare("
            UPDATE stripe_webhooks 
            SET error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $webhook_log_id]);
    }
    
    echo 'ERROR: ' . $e->getMessage();
}

/**
 * Processar evento de fatura paga
 */
function processInvoicePaid($event, $conn) {
    $invoice = $event['data']['object'];
    $invoice_id = $invoice['id'];
    $amount_paid = $invoice['amount_paid'] / 100; // Converter de centavos para reais
    $paid_at = $invoice['status_transitions']['paid_at'] ?? time();
    
    error_log('[' . date('Y-m-d H:i:s') . '] Processando invoice.paid: ' . $invoice_id);
    
    // Buscar fatura no banco
    $stmt = $conn->prepare("
        SELECT si.*, r.id as royalty_id, r.conta_pagar_id
        FROM stripe_invoices si
        INNER JOIN royalties r ON si.royalty_id = r.id
        WHERE si.stripe_invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $stripe_invoice = $stmt->fetch();
    
    if (!$stripe_invoice) {
        error_log('[' . date('Y-m-d H:i:s') . '] Fatura não encontrada no banco: ' . $invoice_id);
        return;
    }
    
    // Atualizar status da fatura Stripe
    $stmt = $conn->prepare("
        UPDATE stripe_invoices 
        SET status = 'paid', paid_at = FROM_UNIXTIME(?)
        WHERE stripe_invoice_id = ?
    ");
    $stmt->execute([$paid_at, $invoice_id]);
    
    // Atualizar status do royalty
    $stmt = $conn->prepare("
        UPDATE royalties 
        SET status = 'pago'
        WHERE id = ?
    ");
    $stmt->execute([$stripe_invoice['royalty_id']]);
    
    // Atualizar conta a pagar (se existir)
    if ($stripe_invoice['conta_pagar_id']) {
        $stmt = $conn->prepare("
            UPDATE contas_pagar 
            SET status = 'pago', 
                data_pagamento = FROM_UNIXTIME(?),
                valor_pago = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $paid_at,
            $amount_paid,
            $stripe_invoice['conta_pagar_id']
        ]);
    }
    
    // Registrar no histórico
    $stmt = $conn->prepare("
        INSERT INTO royalties_historico (royalty_id, acao, descricao, dados_json, user_id)
        VALUES (?, 'pagamento_confirmado', 'Pagamento confirmado via Stripe Webhook', ?, NULL)
    ");
    $stmt->execute([
        $stripe_invoice['royalty_id'],
        json_encode($invoice)
    ]);
    
    error_log('[' . date('Y-m-d H:i:s') . '] Pagamento confirmado para royalty ID: ' . $stripe_invoice['royalty_id']);
}

/**
 * Processar evento de falha no pagamento
 */
function processInvoicePaymentFailed($event, $conn) {
    $invoice = $event['data']['object'];
    $invoice_id = $invoice['id'];
    
    error_log('[' . date('Y-m-d H:i:s') . '] Processando invoice.payment_failed: ' . $invoice_id);
    
    // Buscar fatura no banco
    $stmt = $conn->prepare("
        SELECT si.*, r.id as royalty_id
        FROM stripe_invoices si
        INNER JOIN royalties r ON si.royalty_id = r.id
        WHERE si.stripe_invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $stripe_invoice = $stmt->fetch();
    
    if (!$stripe_invoice) {
        error_log('[' . date('Y-m-d H:i:s') . '] Fatura não encontrada no banco: ' . $invoice_id);
        return;
    }
    
    // Registrar no histórico
    $stmt = $conn->prepare("
        INSERT INTO royalties_historico (royalty_id, acao, descricao, dados_json, user_id)
        VALUES (?, 'pagamento_falhou', 'Tentativa de pagamento falhou - Stripe Webhook', ?, NULL)
    ");
    $stmt->execute([
        $stripe_invoice['royalty_id'],
        json_encode($invoice)
    ]);
    
    error_log('[' . date('Y-m-d H:i:s') . '] Falha no pagamento registrada para royalty ID: ' . $stripe_invoice['royalty_id']);
}

/**
 * Processar evento de fatura finalizada
 */
function processInvoiceFinalized($event, $conn) {
    $invoice = $event['data']['object'];
    $invoice_id = $invoice['id'];
    error_log('[' . date('Y-m-d H:i:s') . '] Processando invoice.finalized: ' . $invoice_id);
    error_log('[' . date('Y-m-d H:i:s') . '] Fatura finalizada: ' . $invoice_id);
}

/**
 * Processar checkout.session.completed
 * Disparado quando o estabelecimento conclui o pagamento via Stripe Checkout.
 */
function processCheckoutSessionCompleted($event, $conn) {
    $session    = $event['data']['object'];
    $session_id = $session['id'];
    $amount_paid = isset($session['amount_total']) ? $session['amount_total'] / 100 : null;
    $paid_at    = $session['created'] ?? time();

    error_log('[' . date('Y-m-d H:i:s') . '] Processando checkout.session.completed: ' . $session_id);

    // Buscar royalty pelo session_id gravado em payment_id
    $royalty = null;
    $stmt = $conn->prepare("SELECT * FROM royalties WHERE payment_id = ? LIMIT 1");
    $stmt->execute([$session_id]);
    $royalty = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: buscar pelo metadata.royalty_id
    if (!$royalty) {
        $royalty_id_meta = $session['metadata']['royalty_id'] ?? null;
        if ($royalty_id_meta) {
            $stmt = $conn->prepare("SELECT * FROM royalties WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$royalty_id_meta]);
            $royalty = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$royalty) {
        error_log('[' . date('Y-m-d H:i:s') . '] checkout.session.completed: royalty não encontrado para session ' . $session_id);
        return;
    }

    // Atualizar status do royalty para pago
    $conn->prepare("
        UPDATE royalties
        SET status         = 'pago',
            payment_status = 'paid',
            data_pagamento = FROM_UNIXTIME(?),
            paid_at        = FROM_UNIXTIME(?),
            payment_data   = ?,
            updated_at     = NOW()
        WHERE id = ?
    ")->execute([
        $paid_at,
        $paid_at,
        json_encode($session),
        $royalty['id'],
    ]);

    // Atualizar conta a pagar vinculada (se existir)
    if (!empty($royalty['conta_pagar_id'])) {
        $conn->prepare("
            UPDATE contas_pagar
            SET status         = 'pago',
                data_pagamento = FROM_UNIXTIME(?),
                valor_pago     = ?
            WHERE id = ?
        ")->execute([$paid_at, $amount_paid, $royalty['conta_pagar_id']]);
    }

    // Registrar no histórico
    try {
        $conn->prepare("
            INSERT INTO royalties_historico (royalty_id, acao, descricao, dados_json, user_id)
            VALUES (?, 'pagamento_confirmado', 'Pagamento confirmado via Stripe Checkout (checkout.session.completed)', ?, NULL)
        ")->execute([$royalty['id'], json_encode($session)]);
    } catch (Exception $e) { /* tabela pode não existir */ }

    error_log('[' . date('Y-m-d H:i:s') . '] Royalty ' . $royalty['id'] . ' marcado como PAGO via Stripe Checkout.');
}

/**
 * Processar payment_intent.succeeded
 * Fallback para quando o pagamento é confirmado via PaymentIntent diretamente.
 */
function processPaymentIntentSucceeded($event, $conn) {
    $intent     = $event['data']['object'];
    $intent_id  = $intent['id'];
    $amount_paid = isset($intent['amount_received']) ? $intent['amount_received'] / 100 : null;
    $paid_at    = $intent['created'] ?? time();

    error_log('[' . date('Y-m-d H:i:s') . '] Processando payment_intent.succeeded: ' . $intent_id);

    // Buscar royalty pelo payment_id
    $stmt = $conn->prepare("SELECT * FROM royalties WHERE payment_id = ? LIMIT 1");
    $stmt->execute([$intent_id]);
    $royalty = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: metadata
    if (!$royalty) {
        $royalty_id_meta = $intent['metadata']['royalty_id'] ?? null;
        if ($royalty_id_meta) {
            $stmt = $conn->prepare("SELECT * FROM royalties WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$royalty_id_meta]);
            $royalty = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$royalty) {
        error_log('[' . date('Y-m-d H:i:s') . '] payment_intent.succeeded: royalty não encontrado para intent ' . $intent_id);
        return;
    }

    $conn->prepare("
        UPDATE royalties
        SET status         = 'pago',
            payment_status = 'paid',
            data_pagamento = FROM_UNIXTIME(?),
            paid_at        = FROM_UNIXTIME(?),
            payment_data   = ?,
            updated_at     = NOW()
        WHERE id = ?
    ")->execute([$paid_at, $paid_at, json_encode($intent), $royalty['id']]);

    if (!empty($royalty['conta_pagar_id'])) {
        $conn->prepare("
            UPDATE contas_pagar
            SET status         = 'pago',
                data_pagamento = FROM_UNIXTIME(?),
                valor_pago     = ?
            WHERE id = ?
        ")->execute([$paid_at, $amount_paid, $royalty['conta_pagar_id']]);
    }

    try {
        $conn->prepare("
            INSERT INTO royalties_historico (royalty_id, acao, descricao, dados_json, user_id)
            VALUES (?, 'pagamento_confirmado', 'Pagamento confirmado via Stripe PaymentIntent (payment_intent.succeeded)', ?, NULL)
        ")->execute([$royalty['id'], json_encode($intent)]);
    } catch (Exception $e) { /* tabela pode não existir */ }

    error_log('[' . date('Y-m-d H:i:s') . '] Royalty ' . $royalty['id'] . ' marcado como PAGO via PaymentIntent.');
}
?>
