<?php
/**
 * Página de Sucesso do Pagamento de Royalty
 *
 * Acessível por qualquer usuário autenticado (admin ou estabelecimento).
 * Quando o Stripe redireciona aqui após o checkout, o status do royalty
 * é atualizado para 'enviado' (aguardando confirmação via webhook).
 * A confirmação definitiva (status 'pago') vem pelo webhook checkout.session.completed.
 */

$page_title = 'Pagamento Realizado';
$current_page = 'financeiro_royalties';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

$royalty_id = (int)($_GET['id'] ?? 0);

if (!$royalty_id) {
    header('Location: financeiro_royalties.php');
    exit;
}

$royalty = $royaltiesManager->buscarPorId($royalty_id);

if (!$royalty) {
    header('Location: financeiro_royalties.php?error=royalty_nao_encontrado');
    exit;
}

// Se o Stripe retornou com session_id, atualizar status para 'enviado'
// (confirmação definitiva virá pelo webhook checkout.session.completed)
$session_id = $_GET['session_id'] ?? null;
if ($session_id && !in_array($royalty['status'], ['pago', 'conciliado', 'pagamento_manual'])) {
    try {
        $conn->prepare("
            UPDATE royalties
            SET status         = 'enviado',
                payment_status = 'processando',
                payment_id     = COALESCE(NULLIF(payment_id,''), ?),
                updated_at     = NOW()
            WHERE id = ? AND status NOT IN ('pago','conciliado','pagamento_manual')
        ")->execute([$session_id, $royalty_id]);
        // Recarregar royalty atualizado
        $royalty = $royaltiesManager->buscarPorId($royalty_id);
    } catch (Exception $e) {
        error_log('[royalty_pagamento_sucesso] Erro ao atualizar status: ' . $e->getMessage());
    }
}

require_once '../includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mt-5">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle fa-5x text-success"></i>
                    </div>

                    <h1 class="text-success mb-3">Pagamento Enviado com Sucesso!</h1>

                    <p class="lead">Seu pagamento foi processado e está sendo confirmado pelo gateway.</p>
                    <p class="text-muted">A confirmação definitiva será feita automaticamente. Você pode acompanhar o status na tela de Royalties.</p>

                    <div class="alert alert-info mt-4 text-start">
                        <h5><i class="fas fa-info-circle"></i> Detalhes do Pagamento</h5>
                        <p class="mb-1"><strong>Estabelecimento:</strong> <?php echo htmlspecialchars($royalty['estabelecimento_nome']); ?></p>
                        <p class="mb-1"><strong>Valor:</strong> R$ <?php echo number_format($royalty['valor_royalties'], 2, ',', '.'); ?></p>
                        <p class="mb-1"><strong>Método:</strong> <?php echo strtoupper($royalty['metodo_pagamento'] ?? 'N/A'); ?></p>
                        <p class="mb-1"><strong>Status:</strong>
                            <?php
                            $s = $royalty['status'] ?? 'pendente';
                            $labels = [
                                'enviado'    => '<span class="badge bg-primary">Enviado — aguardando confirmação</span>',
                                'pago'       => '<span class="badge bg-success">Pago</span>',
                                'processando'=> '<span class="badge bg-warning text-dark">Processando</span>',
                            ];
                            echo $labels[$s] ?? '<span class="badge bg-secondary">' . htmlspecialchars($s) . '</span>';
                            ?>
                        </p>
                        <?php if ($session_id): ?>
                        <p class="mb-0 text-muted small"><strong>Referência Stripe:</strong> <?php echo htmlspecialchars($session_id); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($royalty['metodo_pagamento'] === 'cora' && !empty($royalty['payment_url'])): ?>
                    <div class="mt-4">
                        <a href="<?php echo htmlspecialchars($royalty['payment_url']); ?>" target="_blank" class="btn btn-primary btn-lg">
                            <i class="fas fa-file-pdf"></i> Visualizar Boleto
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <a href="financeiro_royalties.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar para Royalties
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
