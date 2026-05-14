<?php
/**
 * Processar Pagamento de Royalty
 *
 * REGRAS DE NEGÓCIO (v2.0 — 2026-05-14):
 *  1. Somente Admin Geral pode acessar.
 *  2. O gateway usado é sempre do RECEBEDOR (matriz), não do pagador.
 *     O parâmetro ?recebedor=<id> é passado por royalty_selecionar_pagamento.php.
 *     Se ausente, o sistema resolve automaticamente via is_matriz=1.
 *  3. O webhook de royalties MP aponta para /api/webhook_royalties_mp.php.
 *  4. O estabelecimento_recebedor_id é gravado no royalty para conciliação.
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';
require_once '../includes/MercadoPagoAPI.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

// ── REGRA 1: Somente Admin Geral ─────────────────────────────────────────────
if (!isAdminGeral()) {
    header('Location: financeiro_royalties.php?error=acesso_negado');
    exit;
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$royalty_id = (int)($_GET['id'] ?? 0);
$metodo     = $_GET['metodo'] ?? '';

if (!$royalty_id || !in_array($metodo, ['stripe', 'cora', 'mercadopago', 'asaas'])) {
    header('Location: financeiro_royalties.php?error=parametros_invalidos');
    exit;
}

// ── Buscar royalty ────────────────────────────────────────────────────────────
$royalty = $royaltiesManager->buscarPorId($royalty_id);
if (!$royalty) {
    header('Location: financeiro_royalties.php?error=royalty_nao_encontrado');
    exit;
}

if (in_array($royalty['status'], ['pago', 'conciliado', 'pagamento_manual'])) {
    header('Location: financeiro_royalties.php?error=royalty_ja_pago');
    exit;
}

$estab_pagador_id = (int)$royalty['estabelecimento_id'];

// ── REGRA 2: Determinar o RECEBEDOR ──────────────────────────────────────────
// Prioridade: ?recebedor= → royalty.estabelecimento_recebedor_id → is_matriz=1 → id=1
$recebedor_id = (int)($_GET['recebedor'] ?? 0);

if (!$recebedor_id && !empty($royalty['estabelecimento_recebedor_id'])) {
    $recebedor_id = (int)$royalty['estabelecimento_recebedor_id'];
}

if (!$recebedor_id) {
    try {
        $stmt = $conn->query("SELECT id FROM estabelecimentos WHERE is_matriz = 1 AND status = 1 LIMIT 1");
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) $recebedor_id = (int)$m['id'];
    } catch (Exception $e) { /* coluna pode não existir */ }
}

if (!$recebedor_id) {
    $recebedor_id = 1; // fallback absoluto
}

// Gravar recebedor no royalty se ainda não estiver definido
if (empty($royalty['estabelecimento_recebedor_id'])) {
    try {
        $conn->prepare("UPDATE royalties SET estabelecimento_recebedor_id = ? WHERE id = ?")
             ->execute([$recebedor_id, $royalty_id]);
    } catch (Exception $e) { /* coluna pode não existir ainda */ }
}

// ── Registrar log de auditoria ────────────────────────────────────────────────
try {
    $conn->prepare("
        INSERT INTO royalties_payment_log
        (royalty_id, estabelecimento_id, metodo_pagamento, acao, status, ip_address, user_agent)
        VALUES (?, ?, ?, 'iniciar_pagamento', 'iniciado', ?, ?)
    ")->execute([
        $royalty_id,
        $estab_pagador_id,
        $metodo,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
} catch (Exception $e) { /* tabela pode não existir */ }

// ── Processar ─────────────────────────────────────────────────────────────────
try {
    switch ($metodo) {
        case 'stripe':
            processarStripe($conn, $royalty, $recebedor_id);
            break;
        case 'cora':
            processarCora($conn, $royalty, $recebedor_id);
            break;
        case 'mercadopago':
            processarMercadoPago($conn, $royalty, $recebedor_id);
            break;
        case 'asaas':
            processarAsaas($conn, $royalty, $recebedor_id);
            break;
    }
} catch (Exception $e) {
    try {
        $conn->prepare("
            INSERT INTO royalties_payment_log
            (royalty_id, estabelecimento_id, metodo_pagamento, acao, status, erro_mensagem, ip_address)
            VALUES (?, ?, ?, 'erro', 'falhou', ?, ?)
        ")->execute([
            $royalty_id,
            $estab_pagador_id,
            $metodo,
            $e->getMessage(),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e2) { /* ignorar */ }

    header('Location: financeiro_royalties.php?error=' . urlencode($e->getMessage()));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNÇÕES DE PROCESSAMENTO — usam $recebedor_id (matriz) como origem do gateway
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Stripe — usa a conta Stripe do RECEBEDOR
 */
function processarStripe($conn, $royalty, $recebedor_id) {
    $stmt = $conn->prepare("SELECT * FROM stripe_config WHERE estabelecimento_id = ? AND ativo = 1");
    $stmt->execute([$recebedor_id]);
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception('Configuração Stripe não encontrada para o estabelecimento recebedor (id=' . $recebedor_id . ')');
    }

    require_once '../includes/stripe_api.php';
    $stripe = new StripeAPI($recebedor_id);

    $session = $stripe->createCheckoutSession([
        'amount'      => (int)round($royalty['valor_royalties'] * 100),
        'currency'    => 'brl',
        'description' => 'Royalty — ' . date('d/m/Y', strtotime($royalty['periodo_inicial']))
                         . ' a ' . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'success_url' => SITE_URL . '/admin/royalty_pagamento_sucesso.php?id=' . $royalty['id'] . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => SITE_URL . '/admin/financeiro_royalties.php?error=pagamento_cancelado',
        'metadata'    => [
            'royalty_id'                   => $royalty['id'],
            'estabelecimento_pagador_id'   => $royalty['estabelecimento_id'],
            'estabelecimento_recebedor_id' => $recebedor_id,
        ],
    ]);

    $conn->prepare("
        UPDATE royalties
        SET metodo_pagamento = 'stripe',
            payment_id       = ?,
            payment_url      = ?,
            payment_status   = 'processando'
        WHERE id = ?
    ")->execute([$session['id'], $session['url'], $royalty['id']]);

    header('Location: ' . $session['url']);
    exit;
}

/**
 * Cora — usa a conta Cora do RECEBEDOR
 */
function processarCora($conn, $royalty, $recebedor_id) {
    $stmt = $conn->prepare("SELECT * FROM cora_config WHERE estabelecimento_id = ? AND ativo = 1");
    $stmt->execute([$recebedor_id]);
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception('Configuração Cora não encontrada para o estabelecimento recebedor (id=' . $recebedor_id . ')');
    }

    require_once '../includes/cora_api_v2.php';
    $cora = new CoraAPIv2($conn, $recebedor_id);

    $boleto = $cora->emitirBoleto([
        'valor'      => $royalty['valor_royalties'],
        'vencimento' => date('Y-m-d', strtotime('+7 days')),
        'descricao'  => 'Royalty — ' . date('d/m/Y', strtotime($royalty['periodo_inicial']))
                        . ' a ' . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'pagador'    => [
            'nome'      => $royalty['estabelecimento_nome'],
            'documento' => $royalty['estabelecimento_cnpj'] ?? '',
        ],
    ]);

    $conn->prepare("
        UPDATE royalties
        SET metodo_pagamento = 'cora',
            payment_id       = ?,
            payment_url      = ?,
            payment_status   = 'pendente',
            payment_data     = ?
        WHERE id = ?
    ")->execute([$boleto['id'], $boleto['pdf_url'], json_encode($boleto), $royalty['id']]);

    header('Location: ' . $boleto['pdf_url']);
    exit;
}

/**
 * Mercado Pago — usa a conta MP do RECEBEDOR
 * Webhook aponta para /api/webhook_royalties_mp.php (específico de royalties)
 */
function processarMercadoPago($conn, $royalty, $recebedor_id) {
    // Verificar credenciais: payment_config primeiro, depois mercadopago_config legada
    $mp_ok = false;
    try {
        $stmt = $conn->prepare("SELECT 1 FROM payment_config WHERE estabelecimento_id = ? AND mp_access_token IS NOT NULL AND mp_access_token != '' LIMIT 1");
        $stmt->execute([$recebedor_id]);
        if ($stmt->rowCount() > 0) $mp_ok = true;
    } catch (Exception $e) { /* tabela pode não existir */ }

    if (!$mp_ok) {
        $stmt = $conn->prepare("SELECT 1 FROM mercadopago_config WHERE estabelecimento_id = ? AND status = 1 LIMIT 1");
        $stmt->execute([$recebedor_id]);
        if ($stmt->rowCount() > 0) $mp_ok = true;
    }

    if (!$mp_ok) {
        throw new Exception('Configuração Mercado Pago não encontrada para o estabelecimento recebedor (id=' . $recebedor_id . '). Configure em Admin → Pagamentos.');
    }

    $mp = new MercadoPagoAPI($conn, $recebedor_id);

    $preferencia = $mp->criarPreferencia([
        'titulo'          => 'Royalty — ' . $royalty['estabelecimento_nome'],
        'descricao'       => 'Período: ' . date('d/m/Y', strtotime($royalty['periodo_inicial']))
                             . ' a ' . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'valor'           => $royalty['valor_royalties'],
        'pagador_nome'    => $royalty['estabelecimento_nome'],
        'pagador_email'   => $royalty['estabelecimento_email'] ?? 'contato@choppon.com.br',
        'pagador_cpf'     => $royalty['estabelecimento_cnpj'] ?? '',
        'url_sucesso'     => SITE_URL . '/admin/royalty_pagamento_sucesso.php?id=' . $royalty['id'],
        'url_falha'       => SITE_URL . '/admin/financeiro_royalties.php?error=pagamento_falhou',
        'url_pendente'    => SITE_URL . '/admin/financeiro_royalties.php?info=pagamento_pendente',
        'referencia_externa' => 'ROYALTY_' . $royalty['id'],
        // Webhook específico de royalties (não o webhook de pedidos de chopp)
        'webhook_url'     => SITE_URL . '/api/webhook_royalties_mp.php',
    ]);

    $conn->prepare("
        UPDATE royalties
        SET metodo_pagamento    = 'mercadopago',
            payment_id          = ?,
            payment_url         = ?,
            payment_status      = 'pendente',
            payment_data        = ?,
            mp_preference_id    = ?,
            mp_link_pagamento   = ?
        WHERE id = ?
    ")->execute([
        $preferencia['id'],
        $preferencia['init_point'],
        json_encode($preferencia),
        $preferencia['id'],
        $preferencia['init_point'],
        $royalty['id'],
    ]);

    header('Location: ' . $preferencia['init_point']);
    exit;
}

/**
 * Asaas — usa a conta Asaas do RECEBEDOR
 */
function processarAsaas($conn, $royalty, $recebedor_id) {
    $stmt = $conn->prepare("SELECT * FROM asaas_config WHERE estabelecimento_id = ? AND ativo = 1");
    $stmt->execute([$recebedor_id]);
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception('Configuração Asaas não encontrada para o estabelecimento recebedor (id=' . $recebedor_id . ')');
    }

    require_once '../includes/AsaasAPI.php';
    $asaas = new AsaasAPI($conn, $recebedor_id);

    // Dados do pagador (franqueado)
    $stmt = $conn->prepare("
        SELECT id, name, cnpj, email, telefone, celular,
               endereco, numero, complemento, bairro, cep, cidade, estado
        FROM estabelecimentos WHERE id = ?
    ");
    $stmt->execute([$royalty['estabelecimento_id']]);
    $pagador = $stmt->fetch();

    if (!$pagador) {
        throw new Exception('Estabelecimento pagador não encontrado');
    }

    $customer_id = $asaas->buscarOuCriarCliente($royalty['estabelecimento_id'], [
        'cliente_id'        => $royalty['estabelecimento_id'],
        'nome'              => $pagador['name'],
        'cpf_cnpj'          => preg_replace('/[^0-9]/', '', $pagador['cnpj'] ?? ''),
        'email'             => $pagador['email'] ?? null,
        'telefone'          => $pagador['telefone'] ?? null,
        'celular'           => $pagador['celular'] ?? null,
        'endereco'          => $pagador['endereco'] ?? null,
        'numero'            => $pagador['numero'] ?? null,
        'complemento'       => $pagador['complemento'] ?? null,
        'bairro'            => $pagador['bairro'] ?? null,
        'cep'               => preg_replace('/[^0-9]/', '', $pagador['cep'] ?? ''),
        'referencia_externa' => 'ESTABELECIMENTO_' . $royalty['estabelecimento_id'],
    ]);

    $cobranca = $asaas->criarCobranca([
        'customer_id'       => $customer_id,
        'tipo_cobranca'     => 'UNDEFINED',
        'valor'             => $royalty['valor_royalties'],
        'data_vencimento'   => date('Y-m-d', strtotime('+7 days')),
        'descricao'         => 'Royalty — ' . date('d/m/Y', strtotime($royalty['periodo_inicial']))
                               . ' a ' . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'referencia_externa' => 'ROYALTY_' . $royalty['id'],
    ]);

    $conn->prepare("
        UPDATE royalties
        SET metodo_pagamento = 'asaas',
            payment_id       = ?,
            payment_url      = ?,
            payment_status   = 'pendente',
            payment_data     = ?
        WHERE id = ?
    ")->execute([
        $cobranca['id'],
        $cobranca['invoiceUrl'],
        json_encode($cobranca),
        $royalty['id'],
    ]);

    header('Location: ' . $cobranca['invoiceUrl']);
    exit;
}
?>
