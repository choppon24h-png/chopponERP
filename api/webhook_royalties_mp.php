<?php
/**
 * ============================================================
 * WEBHOOK — Conciliação de Royalties via Mercado Pago
 * ============================================================
 * URL de configuração no painel MP:
 *   https://ochoppoficial.com.br/api/webhook_royalties_mp.php
 *
 * Eventos tratados:
 *   - payment (type=payment, topic=payment)
 *   - merchant_order
 *
 * Fluxo:
 *   1. Recebe notificação do MP
 *   2. Responde HTTP 200 imediatamente
 *   3. Consulta o pagamento na API do MP
 *   4. Localiza o royalty pelo mp_preference_id ou mp_payment_id
 *   5. Se approved → status = 'conciliado', grava dados do pagamento
 *   6. Registra histórico e log do webhook
 * ============================================================
 */

// ── Buffer de saída: evita que warnings corrompam a resposta ──────────────────
ob_start();

require_once __DIR__ . '/../includes/config.php';

// ── Responder HTTP 200 ao Mercado Pago imediatamente ──────────────────────────
http_response_code(200);
header('Content-Type: application/json');
ob_clean();
echo json_encode(['status' => 'received', 'ts' => time()]);
flush();

// ── Capturar payload ──────────────────────────────────────────────────────────
$raw_body   = file_get_contents('php://input');
$data       = json_decode($raw_body, true) ?: [];
$ip_origem  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ── Inicializar conexão ───────────────────────────────────────────────────────
try {
    $conn = getDBConnection();
} catch (\Exception $e) {
    _log_webhook(null, null, null, null, null, 0, 'Falha ao conectar ao banco: ' . $e->getMessage(), $raw_body, $ip_origem);
    exit;
}

// ── Identificar tipo de evento ────────────────────────────────────────────────
$event_type = $data['type']  ?? $data['topic'] ?? '';
$payment_id = null;

if ($event_type === 'payment') {
    $payment_id = $data['data']['id'] ?? $data['id'] ?? null;
} elseif ($event_type === 'merchant_order') {
    // merchant_order pode conter payments
    $order_id = $data['data']['id'] ?? null;
    if ($order_id) {
        $payment_id = _buscar_payment_id_do_order($order_id, $conn);
    }
}

// Também aceitar query string ?id=xxx&topic=payment (formato antigo MP)
if (!$payment_id && isset($_GET['id'])) {
    $payment_id = $_GET['id'];
}

if (!$payment_id) {
    _log_webhook($conn, null, 'mercadopago', $event_type, null, null, 0,
        'payment_id não encontrado no payload', $raw_body, $ip_origem);
    exit;
}

// ── Consultar pagamento na API do Mercado Pago ────────────────────────────────
try {
    $mp_data = _consultar_pagamento_mp($payment_id, $conn);
} catch (\Exception $e) {
    _log_webhook($conn, null, 'mercadopago', $event_type, $payment_id, null, 0,
        'Erro ao consultar MP API: ' . $e->getMessage(), $raw_body, $ip_origem);
    exit;
}

if (!$mp_data) {
    _log_webhook($conn, null, 'mercadopago', $event_type, $payment_id, null, 0,
        'Resposta vazia da API do MP', $raw_body, $ip_origem);
    exit;
}

$mp_status         = $mp_data['status']          ?? 'unknown';
$mp_valor          = $mp_data['transaction_amount'] ?? null;
$mp_method         = $mp_data['payment_type_id'] ?? null;
$mp_method_detail  = $mp_data['payment_method_id'] ?? null;
$mp_preference_id  = $mp_data['preference_id']   ?? null;
$mp_external_ref   = $mp_data['external_reference'] ?? null; // pode conter royalty_id

// ── Localizar o royalty correspondente ───────────────────────────────────────
$royalty = _localizar_royalty($conn, $payment_id, $mp_preference_id, $mp_external_ref);

if (!$royalty) {
    _log_webhook($conn, null, 'mercadopago', $event_type, $payment_id, $mp_valor, 0,
        "Royalty não encontrado para payment_id={$payment_id}, preference_id={$mp_preference_id}, external_ref={$mp_external_ref}",
        $raw_body, $ip_origem);
    exit;
}

$royalty_id = $royalty['id'];

// ── Gravar mp_payment_id e status MP independente do resultado ────────────────
try {
    $conn->prepare("
        UPDATE royalties
        SET mp_payment_id      = ?,
            mp_payment_status  = ?,
            mp_payment_method  = ?,
            mp_payment_detail  = ?,
            mp_webhook_payload = ?,
            updated_at         = NOW()
        WHERE id = ?
    ")->execute([
        $payment_id,
        $mp_status,
        $mp_method,
        $mp_method_detail,
        $raw_body,
        $royalty_id
    ]);
} catch (\Exception $e) { /* não bloquear */ }

// ── Processar apenas pagamentos aprovados ─────────────────────────────────────
if ($mp_status !== 'approved') {
    _log_webhook($conn, $royalty_id, 'mercadopago', $event_type, $payment_id, $mp_valor, 1,
        "Pagamento recebido com status={$mp_status} (não aprovado, sem conciliação)", $raw_body, $ip_origem);
    exit;
}

// ── Verificar se já foi conciliado (idempotência) ─────────────────────────────
if (in_array($royalty['status'], ['conciliado', 'pago', 'pagamento_manual'])) {
    _log_webhook($conn, $royalty_id, 'mercadopago', $event_type, $payment_id, $mp_valor, 1,
        "Royalty já conciliado (status={$royalty['status']}), ignorando duplicata", $raw_body, $ip_origem);
    exit;
}

// ── Conciliar: atualizar royalty para 'conciliado' ────────────────────────────
try {
    $conn->beginTransaction();

    $conn->prepare("
        UPDATE royalties
        SET status             = 'conciliado',
            mp_payment_id      = ?,
            mp_payment_status  = 'approved',
            mp_payment_method  = ?,
            mp_payment_detail  = ?,
            mp_conciliado_em   = NOW(),
            mp_webhook_payload = ?,
            data_pagamento     = CURDATE(),
            updated_at         = NOW()
        WHERE id = ?
    ")->execute([
        $payment_id,
        $mp_method,
        $mp_method_detail,
        $raw_body,
        $royalty_id
    ]);

    // Atualizar conta a pagar vinculada (se existir)
    if (!empty($royalty['conta_pagar_id'])) {
        $conn->prepare("
            UPDATE contas_pagar
            SET status       = 'pago',
                data_pagamento = CURDATE(),
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$royalty['conta_pagar_id']]);
    }

    // Registrar no histórico
    $descricao_hist = sprintf(
        'Pagamento conciliado automaticamente via webhook do Mercado Pago. ' .
        'Payment ID: %s | Método: %s (%s) | Valor: R$ %s',
        $payment_id,
        $mp_method ?? '-',
        $mp_method_detail ?? '-',
        number_format((float)$mp_valor, 2, ',', '.')
    );

    $conn->prepare("
        INSERT INTO royalties_historico
        (royalty_id, acao, descricao, dados_json, user_id, created_at)
        VALUES (?, 'pagamento_webhook', ?, ?, NULL, NOW())
    ")->execute([
        $royalty_id,
        $descricao_hist,
        json_encode([
            'payment_id'     => $payment_id,
            'mp_status'      => $mp_status,
            'mp_method'      => $mp_method,
            'mp_detail'      => $mp_method_detail,
            'valor'          => $mp_valor,
            'preference_id'  => $mp_preference_id,
            'external_ref'   => $mp_external_ref,
        ])
    ]);

    $conn->commit();

    _log_webhook($conn, $royalty_id, 'mercadopago', $event_type, $payment_id, $mp_valor, 1,
        "Royalty #{$royalty_id} conciliado com sucesso via webhook MP", $raw_body, $ip_origem);

} catch (\Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    _log_webhook($conn, $royalty_id, 'mercadopago', $event_type, $payment_id, $mp_valor, 0,
        'Erro ao conciliar royalty: ' . $e->getMessage(), $raw_body, $ip_origem);
}

exit;

// ─────────────────────────────────────────────────────────────────────────────
// FUNÇÕES AUXILIARES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Consulta o pagamento na API do Mercado Pago usando o access_token
 * configurado na tabela mercadopago_config (qualquer estabelecimento ativo).
 */
function _consultar_pagamento_mp(string $payment_id, \PDO $conn): ?array
{
    // Buscar access_token de qualquer config ativa
    $row = null;
    try {
        $stmt = $conn->query("SELECT access_token FROM mercadopago_config WHERE status = 1 LIMIT 1");
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    if (!$row || empty($row['access_token'])) {
        // Tentar variável de ambiente como fallback
        $token = defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : (getenv('MP_ACCESS_TOKEN') ?: null);
        if (!$token) throw new \Exception('access_token do Mercado Pago não configurado');
        $row = ['access_token' => $token];
    }

    $url = "https://api.mercadopago.com/v1/payments/{$payment_id}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $row['access_token'],
            'Content-Type: application/json',
            'X-Idempotency-Key: webhook-royalties-' . $payment_id,
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        throw new \Exception("MP API retornou HTTP {$http_code} para payment/{$payment_id}");
    }

    return json_decode($response, true) ?: null;
}

/**
 * Busca o payment_id de um merchant_order.
 */
function _buscar_payment_id_do_order(string $order_id, \PDO $conn): ?string
{
    try {
        $row = $conn->query("SELECT access_token FROM mercadopago_config WHERE status = 1 LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        $url = "https://api.mercadopago.com/merchant_orders/{$order_id}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $row['access_token']],
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        foreach ($resp['payments'] ?? [] as $p) {
            if (($p['status'] ?? '') === 'approved') return (string)$p['id'];
        }
        // Retornar o primeiro payment_id disponível
        return isset($resp['payments'][0]['id']) ? (string)$resp['payments'][0]['id'] : null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Localiza o royalty pelo payment_id, preference_id ou external_reference.
 * Tenta múltiplas estratégias em ordem de prioridade.
 */
function _localizar_royalty(\PDO $conn, ?string $payment_id, ?string $preference_id, ?string $external_ref): ?array
{
    // Estratégia 1: pelo mp_payment_id já gravado
    if ($payment_id) {
        $stmt = $conn->prepare("SELECT * FROM royalties WHERE mp_payment_id = ? LIMIT 1");
        $stmt->execute([$payment_id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r) return $r;
    }

    // Estratégia 2: pelo mp_preference_id
    if ($preference_id) {
        $stmt = $conn->prepare("SELECT * FROM royalties WHERE mp_preference_id = ? LIMIT 1");
        $stmt->execute([$preference_id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r) return $r;
    }

    // Estratégia 3: external_reference pode ser "royalty_<id>"
    if ($external_ref) {
        $royalty_id_from_ref = null;
        if (preg_match('/royalty[_\-](\d+)/i', $external_ref, $m)) {
            $royalty_id_from_ref = (int)$m[1];
        } elseif (is_numeric($external_ref)) {
            $royalty_id_from_ref = (int)$external_ref;
        }
        if ($royalty_id_from_ref) {
            $stmt = $conn->prepare("SELECT * FROM royalties WHERE id = ? LIMIT 1");
            $stmt->execute([$royalty_id_from_ref]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
    }

    return null;
}

/**
 * Grava um registro na tabela royalties_webhook_log.
 * Também grava em arquivo de log como fallback.
 */
function _log_webhook(
    ?\PDO $conn,
    ?int $royalty_id,
    ?string $gateway,
    ?string $event_type,
    ?string $payment_id,
    $valor,
    int $processado,
    string $mensagem,
    string $payload,
    string $ip
): void {
    // Log em arquivo sempre
    $log_line = json_encode([
        'ts'          => date('Y-m-d H:i:s'),
        'royalty_id'  => $royalty_id,
        'gateway'     => $gateway,
        'event_type'  => $event_type,
        'payment_id'  => $payment_id,
        'valor'       => $valor,
        'processado'  => $processado,
        'mensagem'    => $mensagem,
        'ip'          => $ip,
    ]) . "\n";

    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    @file_put_contents($log_dir . '/webhook_royalties_mp.log', $log_line, FILE_APPEND);

    // Log no banco
    if ($conn) {
        try {
            $conn->prepare("
                INSERT INTO royalties_webhook_log
                (royalty_id, gateway, event_type, payment_id, valor, processado, erro, payload, ip_origem, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $royalty_id,
                $gateway ?? 'mercadopago',
                $event_type,
                $payment_id,
                $valor,
                $processado,
                $processado ? null : $mensagem,
                $payload,
                $ip,
            ]);
        } catch (\Exception $e) { /* não bloquear */ }
    }
}
