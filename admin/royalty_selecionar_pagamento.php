<?php
/**
 * Seleção de Método de Pagamento para Royalty
 *
 * REGRAS DE NEGÓCIO (v2.0 — 2026-05-14):
 *  1. Somente Admin Geral pode acessar esta tela.
 *     Usuários de estabelecimento NÃO podem iniciar pagamentos manualmente.
 *  2. O pagamento é sempre gerado na conta do ESTABELECIMENTO RECEBEDOR
 *     (a matriz — is_matriz = 1), não do franqueado pagador.
 *  3. Se o estabelecimento recebedor tiver banco_padrao_royalties definido,
 *     apenas esse gateway é exibido (sem escolha).
 *  4. Se não houver banco padrão, exibe todos os gateways configurados
 *     para o recebedor.
 */

$page_title = 'Selecionar Método de Pagamento';
$current_page = 'financeiro_royalties';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

// ── REGRA 1: Somente Admin Geral pode acessar ────────────────────────────────
if (!isAdminGeral()) {
    header('Location: financeiro_royalties.php?error=acesso_negado');
    exit;
}

// ── Verificar ID do royalty ───────────────────────────────────────────────────
$royalty_id = (int)($_GET['id'] ?? 0);
if (!$royalty_id) {
    header('Location: financeiro_royalties.php?error=id_invalido');
    exit;
}

// ── Buscar dados do royalty ───────────────────────────────────────────────────
$royalty = $royaltiesManager->buscarPorId($royalty_id);
if (!$royalty) {
    header('Location: financeiro_royalties.php?error=royalty_nao_encontrado');
    exit;
}

// Verificar se já foi pago
if (in_array($royalty['status'], ['pago', 'conciliado', 'pagamento_manual'])) {
    header('Location: financeiro_royalties.php?error=royalty_ja_pago');
    exit;
}

$estab_pagador_id = (int)$royalty['estabelecimento_id'];

// ── REGRA 2: Determinar o RECEBEDOR (matriz) ─────────────────────────────────
// Prioridade: campo estabelecimento_recebedor_id do royalty → is_matriz=1 → id=1
$recebedor_id = null;

// Tentar campo do royalty
if (!empty($royalty['estabelecimento_recebedor_id'])) {
    $recebedor_id = (int)$royalty['estabelecimento_recebedor_id'];
}

// Fallback: buscar a matriz pelo flag is_matriz
if (!$recebedor_id) {
    try {
        $stmt = $conn->query("SELECT id, name FROM estabelecimentos WHERE is_matriz = 1 AND status = 1 LIMIT 1");
        $matriz = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($matriz) {
            $recebedor_id = (int)$matriz['id'];
        }
    } catch (Exception $e) { /* coluna pode não existir ainda */ }
}

// Fallback final: estabelecimento 1
if (!$recebedor_id) {
    $recebedor_id = 1;
}

// Buscar dados do recebedor
$stmt = $conn->prepare("SELECT id, name, banco_padrao_royalties FROM estabelecimentos WHERE id = ?");
$stmt->execute([$recebedor_id]);
$recebedor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recebedor) {
    header('Location: financeiro_royalties.php?error=recebedor_nao_encontrado');
    exit;
}

$banco_padrao = $recebedor['banco_padrao_royalties'] ?? null;

// ── REGRA 3/4: Verificar gateways disponíveis para o RECEBEDOR ───────────────
$stripe_disponivel      = false;
$cora_disponivel        = false;
$mercadopago_disponivel = false;
$asaas_disponivel       = false;

// Se há banco padrão, verificar apenas ele; senão, verificar todos
$verificar = $banco_padrao
    ? [$banco_padrao]
    : ['stripe', 'cora', 'mercadopago', 'asaas'];

foreach ($verificar as $gw) {
    switch ($gw) {
        case 'stripe':
            $stmt = $conn->prepare("SELECT 1 FROM stripe_config WHERE estabelecimento_id = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$recebedor_id]);
            if ($stmt->rowCount() > 0) $stripe_disponivel = true;
            break;
        case 'cora':
            $stmt = $conn->prepare("SELECT 1 FROM cora_config WHERE estabelecimento_id = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$recebedor_id]);
            if ($stmt->rowCount() > 0) $cora_disponivel = true;
            break;
        case 'mercadopago':
            // Verificar payment_config primeiro, depois mercadopago_config legada
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
            if ($mp_ok) $mercadopago_disponivel = true;
            break;
        case 'asaas':
            $stmt = $conn->prepare("SELECT 1 FROM asaas_config WHERE estabelecimento_id = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$recebedor_id]);
            if ($stmt->rowCount() > 0) $asaas_disponivel = true;
            break;
    }
}

$algum_disponivel = $stripe_disponivel || $cora_disponivel || $mercadopago_disponivel || $asaas_disponivel;

// ── Se banco padrão definido e disponível: redirecionar direto ───────────────
if ($banco_padrao && $algum_disponivel) {
    // Redirecionar direto sem mostrar tela de escolha
    header("Location: royalty_processar_pagamento.php?id={$royalty_id}&metodo={$banco_padrao}&recebedor={$recebedor_id}");
    exit;
}

require_once '../includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="page-header">
                <h1><i class="fas fa-credit-card"></i> Selecionar Método de Pagamento</h1>
                <p class="text-muted">Escolha como o royalty será cobrado</p>
            </div>

            <!-- Alerta: pagamento vai para a matriz -->
            <div class="alert alert-info" style="border-left:4px solid #2563eb;">
                <i class="fas fa-university"></i>
                <strong>Pagamento direcionado para:</strong>
                <?= htmlspecialchars($recebedor['name']) ?>
                <?php if ($banco_padrao): ?>
                    &nbsp;|&nbsp; <strong>Gateway obrigatório:</strong> <?= strtoupper($banco_padrao) ?>
                <?php endif; ?>
            </div>

            <!-- Informações do Royalty -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0"><i class="fas fa-file-invoice-dollar"></i> Detalhes do Royalty</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Franqueado (pagador):</strong> <?= htmlspecialchars($royalty['estabelecimento_nome']) ?></p>
                            <p><strong>Período:</strong>
                                <?= date('d/m/Y', strtotime($royalty['periodo_inicial'])) ?> a
                                <?= date('d/m/Y', strtotime($royalty['periodo_final'])) ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Faturamento Bruto:</strong> R$ <?= number_format($royalty['valor_faturamento_bruto'], 2, ',', '.') ?></p>
                            <p><strong>Royalties (<?= $royalty['percentual_royalties'] ?>%):</strong>
                                <span class="h4 text-success">R$ <?= number_format($royalty['valor_royalties'], 2, ',', '.') ?></span>
                            </p>
                        </div>
                    </div>
                    <?php if (!empty($royalty['descricao_cobranca'])): ?>
                    <p><strong>Descrição:</strong> <?= nl2br(htmlspecialchars($royalty['descricao_cobranca'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Métodos de Pagamento -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0"><i class="fas fa-credit-card"></i> Escolha o Método de Pagamento</h3>
                    <small class="text-muted">Gateways configurados para: <strong><?= htmlspecialchars($recebedor['name']) ?></strong></small>
                </div>
                <div class="card-body">
                    <?php if (!$algum_disponivel): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Nenhum gateway de pagamento configurado para o estabelecimento recebedor
                            (<strong><?= htmlspecialchars($recebedor['name']) ?></strong>).
                            <br>Configure Stripe, Cora, Mercado Pago ou Asaas em
                            <a href="pagamentos.php">Admin → Pagamentos</a>.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php if ($stripe_disponivel): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card payment-method-card h-100" onclick="selecionarMetodo('stripe')">
                                    <div class="card-body text-center">
                                        <i class="fab fa-stripe fa-4x text-primary mb-3"></i>
                                        <h4>Stripe</h4>
                                        <p class="text-muted">Cartão de Crédito</p>
                                        <button type="button" class="btn btn-primary btn-block w-100">
                                            <i class="fas fa-arrow-right"></i> Pagar com Stripe
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($cora_disponivel): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card payment-method-card h-100" onclick="selecionarMetodo('cora')">
                                    <div class="card-body text-center">
                                        <i class="fas fa-university fa-4x text-success mb-3"></i>
                                        <h4>Banco Cora</h4>
                                        <p class="text-muted">Boleto Bancário</p>
                                        <button type="button" class="btn btn-success btn-block w-100">
                                            <i class="fas fa-arrow-right"></i> Pagar com Cora
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($mercadopago_disponivel): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card payment-method-card h-100" onclick="selecionarMetodo('mercadopago')">
                                    <div class="card-body text-center">
                                        <i class="fab fa-cc-mastercard fa-4x text-info mb-3"></i>
                                        <h4>Mercado Pago</h4>
                                        <p class="text-muted">Cartão, PIX ou Boleto</p>
                                        <button type="button" class="btn btn-info btn-block w-100">
                                            <i class="fas fa-arrow-right"></i> Pagar com Mercado Pago
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asaas_disponivel): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card payment-method-card h-100" onclick="selecionarMetodo('asaas')">
                                    <div class="card-body text-center">
                                        <i class="fas fa-dollar-sign fa-4x text-warning mb-3"></i>
                                        <h4>Asaas</h4>
                                        <p class="text-muted">Cartão, PIX ou Boleto</p>
                                        <button type="button" class="btn btn-warning btn-block w-100">
                                            <i class="fas fa-arrow-right"></i> Pagar com Asaas
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <a href="financeiro_royalties.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.payment-method-card {
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid #e0e0e0;
}
.payment-method-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    border-color: #007bff;
}
</style>

<script>
function selecionarMetodo(metodo) {
    const royaltyId  = <?= $royalty_id ?>;
    const recebedorId = <?= $recebedor_id ?>;
    const labels = {
        stripe: 'Stripe (Cartão)',
        cora: 'Banco Cora (Boleto)',
        mercadopago: 'Mercado Pago',
        asaas: 'Asaas'
    };
    if (confirm(`Confirma gerar cobrança via ${labels[metodo] || metodo.toUpperCase()}?\n\nO pagamento será direcionado para: <?= addslashes(htmlspecialchars($recebedor['name'])) ?>`)) {
        window.location.href = `royalty_processar_pagamento.php?id=${royaltyId}&metodo=${metodo}&recebedor=${recebedorId}`;
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
