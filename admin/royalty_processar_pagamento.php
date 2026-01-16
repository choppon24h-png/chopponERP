<?php
/**
 * Processar Pagamento de Royalty
 * Redireciona para o gateway de pagamento selecionado
 * 
 * CORREÇÕES APLICADAS:
 * 1. Linha 94: Alterado 'status = 1' para 'ativo = 1' em stripe_config
 * 2. Linha 105: Alterado new StripeAPI($config['secret_key']) para new StripeAPI($estabelecimento_id)
 * Data: 2026-01-07
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';
require_once '../includes/MercadoPagoAPI.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

// Verificar parâmetros
$royalty_id = (int)($_GET['id'] ?? 0);
$metodo = $_GET['metodo'] ?? '';

if (!$royalty_id || !in_array($metodo, ['stripe', 'cora', 'mercadopago', 'asaas'])) {
    header('Location: financeiro_royalties.php?error=parametros_invalidos');
    exit;
}

// Buscar royalty
$royalty = $royaltiesManager->buscarPorId($royalty_id);

if (!$royalty) {
    header('Location: financeiro_royalties.php?error=royalty_nao_encontrado');
    exit;
}

// Verificar se já foi pago
if ($royalty['status'] === 'pago') {
    header('Location: financeiro_royalties.php?error=royalty_ja_pago');
    exit;
}

$estabelecimento_id = $royalty['estabelecimento_id'];

try {
    // Registrar log
    $stmt = $conn->prepare("
        INSERT INTO royalties_payment_log 
        (royalty_id, estabelecimento_id, metodo_pagamento, acao, status, ip_address, user_agent)
        VALUES (?, ?, ?, 'iniciar_pagamento', 'iniciado', ?, ?)
    ");
    $stmt->execute([
        $royalty_id,
        $estabelecimento_id,
        $metodo,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    // Processar conforme método escolhido
    switch ($metodo) {
        case 'stripe':
            processarStripe($conn, $royalty, $estabelecimento_id);
            break;
            
        case 'cora':
            processarCora($conn, $royalty, $estabelecimento_id);
            break;
            
        case 'mercadopago':
            processarMercadoPago($conn, $royalty, $estabelecimento_id);
            break;
            
        case 'asaas':
            processarAsaas($conn, $royalty, $estabelecimento_id);
            break;
    }
    
} catch (Exception $e) {
    // Log de erro
    $stmt = $conn->prepare("
        INSERT INTO royalties_payment_log 
        (royalty_id, estabelecimento_id, metodo_pagamento, acao, status, erro_mensagem, ip_address)
        VALUES (?, ?, ?, 'erro', 'falhou', ?, ?)
    ");
    $stmt->execute([
        $royalty_id,
        $estabelecimento_id,
        $metodo,
        $e->getMessage(),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    header('Location: financeiro_royalties.php?error=' . urlencode($e->getMessage()));
    exit;
}

/**
 * Processar pagamento via Stripe
 * 
 * CORREÇÕES APLICADAS:
 * 1. Linha 94: Usar 'ativo' em vez de 'status'
 * 2. Linha 105: Passar estabelecimento_id em vez de config['secret_key']
 *    - A classe StripeAPI vai buscar a secret_key do banco usando o estabelecimento_id
 */
function processarStripe($conn, $royalty, $estabelecimento_id) {
    // Buscar configuração Stripe
    // CORRIGIDO: Usar 'ativo' em vez de 'status'
    $stmt = $conn->prepare("SELECT * FROM stripe_config WHERE estabelecimento_id = ? AND ativo = 1");
    $stmt->execute([$estabelecimento_id]);
    $config = $stmt->fetch();
    
    if (!$config) {
        throw new Exception('Configuração Stripe não encontrada ou inativa');
    }
    
    require_once '../includes/stripe_api.php';
    
    // CORRIGIDO: Passar estabelecimento_id em vez de secret_key
    // A classe StripeAPI vai buscar a configuração do banco automaticamente
    $stripe = new StripeAPI($estabelecimento_id);
    
    $session = $stripe->createCheckoutSession([
        'amount' => $royalty['valor_royalties'] * 100, // Centavos
        'currency' => 'brl',
        'description' => "Royalty - Período: " . date('d/m/Y', strtotime($royalty['periodo_inicial'])) . " a " . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'success_url' => SITE_URL . '/admin/royalty_pagamento_sucesso.php?id=' . $royalty['id'] . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => SITE_URL . '/admin/financeiro_royalties.php?error=pagamento_cancelado',
        'metadata' => [
            'royalty_id' => $royalty['id'],
            'estabelecimento_id' => $estabelecimento_id
        ]
    ]);
    
    // Atualizar royalty
    $stmt = $conn->prepare("
        UPDATE royalties 
        SET metodo_pagamento = 'stripe', 
            payment_id = ?, 
            payment_url = ?,
            payment_status = 'processando'
        WHERE id = ?
    ");
    $stmt->execute([$session['id'], $session['url'], $royalty['id']]);
    
    // Redirecionar para Stripe
    header('Location: ' . $session['url']);
    exit;
}

/**
 * Processar pagamento via Cora
 */
function processarCora($conn, $royalty, $estabelecimento_id) {
    // Buscar configuração Cora
    $stmt = $conn->prepare("SELECT * FROM cora_config WHERE estabelecimento_id = ? AND status = 'Ativo'");
    $stmt->execute([$estabelecimento_id]);
    $config = $stmt->fetch();
    
    if (!$config) {
        throw new Exception('Configuração Cora não encontrada');
    }
    
    require_once '../includes/cora_api_v2.php';
    
    $cora = new CoraAPIv2($conn, $estabelecimento_id);
    
    // Gerar boleto
    $boleto = $cora->emitirBoleto([
        'valor' => $royalty['valor_royalties'],
        'vencimento' => date('Y-m-d', strtotime('+7 days')),
        'descricao' => "Royalty - Período: " . date('d/m/Y', strtotime($royalty['periodo_inicial'])) . " a " . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'pagador' => [
            'nome' => $royalty['estabelecimento_nome'],
            'documento' => $royalty['estabelecimento_cnpj'] ?? ''
        ]
    ]);
    
    // Atualizar royalty
    $stmt = $conn->prepare("
        UPDATE royalties 
        SET metodo_pagamento = 'cora', 
            payment_id = ?, 
            payment_url = ?,
            payment_status = 'pendente',
            payment_data = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $boleto['id'],
        $boleto['pdf_url'],
        json_encode($boleto),
        $royalty['id']
    ]);
    
    // Redirecionar para visualizar boleto
    header('Location: ' . $boleto['pdf_url']);
    exit;
}

/**
 * Processar pagamento via Asaas
 */
function processarAsaas($conn, $royalty, $estabelecimento_id) {
    // LOG: Início do processamento
    error_log("[ASAAS DEBUG] Iniciando processamento Asaas");
    error_log("[ASAAS DEBUG] Royalty ID: " . $royalty['id']);
    error_log("[ASAAS DEBUG] Estabelecimento ID: " . $estabelecimento_id);
    error_log("[ASAAS DEBUG] Valor: " . $royalty['valor_royalties']);
    // Buscar configuração Asaas
    $stmt = $conn->prepare("SELECT * FROM asaas_config WHERE estabelecimento_id = ? AND ativo = 1");
    $stmt->execute([$estabelecimento_id]);
    $config = $stmt->fetch();
    
    if (!$config) {
        error_log("[ASAAS ERROR] Configuração Asaas não encontrada para estabelecimento: " . $estabelecimento_id);
        throw new Exception('Configuração Asaas não encontrada ou inativa');
    }
    
    error_log("[ASAAS DEBUG] Configuração encontrada: API Key = " . substr($config['asaas_api_key'], 0, 10) . "...");
    error_log("[ASAAS DEBUG] Ambiente: " . $config['ambiente']);
    
    require_once '../includes/AsaasAPI.php';
    
    $asaas = new AsaasAPI($conn, $estabelecimento_id);
    
    // Buscar ou criar cliente no Asaas
    // Quando royalty é criado, o cliente é o estabelecimento
    $stmt = $conn->prepare("
        SELECT id, name, cnpj, email, telefone, celular, 
               endereco, numero, complemento, bairro, cep, cidade, estado
        FROM estabelecimentos 
        WHERE id = ?
    ");
    $stmt->execute([$estabelecimento_id]);
    $estabelecimento = $stmt->fetch();
    
    if (!$estabelecimento) {
        error_log("[ASAAS ERROR] Estabelecimento não encontrado: " . $estabelecimento_id);
        throw new Exception('Estabelecimento não encontrado');
    }
    
    error_log("[ASAAS DEBUG] Estabelecimento encontrado: " . $estabelecimento['name']);
    error_log("[ASAAS DEBUG] CNPJ: " . ($estabelecimento['cnpj'] ?? 'N/A'));
    error_log("[ASAAS DEBUG] Email: " . ($estabelecimento['email'] ?? 'N/A'));
    
    // Preparar dados do cliente
    $dados_cliente = [
        'cliente_id' => $estabelecimento_id, // Usar ID do estabelecimento como cliente_id
        'nome' => $estabelecimento['name'],
        'cpf_cnpj' => preg_replace('/[^0-9]/', '', $estabelecimento['cnpj'] ?? ''),
        'email' => $estabelecimento['email'] ?? null,
        'telefone' => $estabelecimento['telefone'] ?? null,
        'celular' => $estabelecimento['celular'] ?? null,
        'endereco' => $estabelecimento['endereco'] ?? null,
        'numero' => $estabelecimento['numero'] ?? null,
        'complemento' => $estabelecimento['complemento'] ?? null,
        'bairro' => $estabelecimento['bairro'] ?? null,
        'cep' => preg_replace('/[^0-9]/', '', $estabelecimento['cep'] ?? ''),
        'referencia_externa' => 'ESTABELECIMENTO_' . $estabelecimento_id
    ];
    
    // Buscar ou criar cliente
    error_log("[ASAAS DEBUG] Buscando ou criando cliente no Asaas...");
    error_log("[ASAAS DEBUG] Dados cliente: " . json_encode($dados_cliente));
    
    try {
        $customer_id = $asaas->buscarOuCriarCliente($estabelecimento_id, $dados_cliente);
        error_log("[ASAAS DEBUG] Cliente criado/encontrado: " . $customer_id);
    } catch (Exception $e) {
        error_log("[ASAAS ERROR] Erro ao buscar/criar cliente: " . $e->getMessage());
        throw $e;
    }
    
    // Criar cobrança
    error_log("[ASAAS DEBUG] Criando cobrança no Asaas...");
    
    $dados_cobranca = [
        'customer_id' => $customer_id,
        'tipo_cobranca' => 'UNDEFINED', // Cliente escolhe entre Boleto, PIX ou Cartão
        'valor' => $royalty['valor_royalties'],
        'data_vencimento' => date('Y-m-d', strtotime('+7 days')),
        'descricao' => "Royalty - Período: " . date('d/m/Y', strtotime($royalty['periodo_inicial'])) . " a " . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'referencia_externa' => 'ROYALTY_' . $royalty['id']
    ];
    
    error_log("[ASAAS DEBUG] Dados cobrança: " . json_encode($dados_cobranca));
    
    try {
        $cobranca = $asaas->criarCobranca($dados_cobranca);
        error_log("[ASAAS DEBUG] Cobrança criada com sucesso!");
        error_log("[ASAAS DEBUG] Cobrança ID: " . ($cobranca['id'] ?? 'N/A'));
        error_log("[ASAAS DEBUG] Invoice URL: " . ($cobranca['invoiceUrl'] ?? 'N/A'));
        error_log("[ASAAS DEBUG] Resposta completa: " . json_encode($cobranca));
    } catch (Exception $e) {
        error_log("[ASAAS ERROR] Erro ao criar cobrança: " . $e->getMessage());
        error_log("[ASAAS ERROR] Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
    
    // Atualizar royalty
    $stmt = $conn->prepare("
        UPDATE royalties 
        SET metodo_pagamento = 'asaas', 
            payment_id = ?, 
            payment_url = ?,
            payment_status = 'pendente',
            payment_data = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $cobranca['id'],
        $cobranca['invoiceUrl'],
        json_encode($cobranca),
        $royalty['id']
    ]);
    
    // Atualizar tabela asaas_pagamentos com conta_receber_id
    $stmt = $conn->prepare("
        UPDATE asaas_pagamentos 
        SET conta_receber_id = ?
        WHERE asaas_payment_id = ?
    ");
    $stmt->execute([$royalty['id'], $cobranca['id']]);
    
    // Redirecionar para fatura do Asaas
    error_log("[ASAAS DEBUG] Redirecionando para: " . $cobranca['invoiceUrl']);
    error_log("[ASAAS DEBUG] Processamento Asaas concluído com sucesso!");
    
    header('Location: ' . $cobranca['invoiceUrl']);
    exit;
}

/**
 * Processar pagamento via Mercado Pago
 */
function processarMercadoPago($conn, $royalty, $estabelecimento_id) {
    // Buscar configuração Mercado Pago
    $stmt = $conn->prepare("SELECT * FROM mercadopago_config WHERE estabelecimento_id = ? AND status = 1");
    $stmt->execute([$estabelecimento_id]);
    $config = $stmt->fetch();
    
    if (!$config) {
        throw new Exception('Configuração Mercado Pago não encontrada');
    }
    
    $mp = new MercadoPagoAPI($conn, $estabelecimento_id);
    
    // Criar preferência de pagamento
    $preferencia = $mp->criarPreferencia([
        'titulo' => "Royalty - " . $royalty['estabelecimento_nome'],
        'descricao' => "Período: " . date('d/m/Y', strtotime($royalty['periodo_inicial'])) . " a " . date('d/m/Y', strtotime($royalty['periodo_final'])),
        'valor' => $royalty['valor_royalties'],
        'pagador_nome' => $royalty['estabelecimento_nome'],
        'pagador_email' => $royalty['estabelecimento_email'] ?? 'contato@choppon.com.br',
        'pagador_cpf' => $royalty['estabelecimento_cnpj'] ?? '',
        'url_sucesso' => SITE_URL . '/admin/royalty_pagamento_sucesso.php?id=' . $royalty['id'],
        'url_falha' => SITE_URL . '/admin/financeiro_royalties.php?error=pagamento_falhou',
        'url_pendente' => SITE_URL . '/admin/financeiro_royalties.php?info=pagamento_pendente',
        'referencia_externa' => 'ROYALTY_' . $royalty['id'],
        'webhook_url' => SITE_URL . '/api/webhook_mercadopago.php'
    ]);
    
    // Atualizar royalty
    $stmt = $conn->prepare("
        UPDATE royalties 
        SET metodo_pagamento = 'mercadopago', 
            payment_id = ?, 
            payment_url = ?,
            payment_status = 'pendente',
            payment_data = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $preferencia['id'],
        $preferencia['init_point'],
        json_encode($preferencia),
        $royalty['id']
    ]);
    
    // Redirecionar para Mercado Pago
    header('Location: ' . $preferencia['init_point']);
    exit;
}
?>
