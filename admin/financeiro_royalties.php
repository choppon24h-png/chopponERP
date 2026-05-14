<?php
/**
 * ========================================
 * FINANCEIRO - ROYALTIES
 * Sistema de Gestão de Royalties
 * Versão: 4.0 - Reescrita Completa
 * Data: 2025-12-04
 * ========================================
 */

$page_title = 'Financeiro - Royalties';
$current_page = 'financeiro_royalties';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';
require_once '../includes/EmailTemplate.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

// ===== PROCESSAMENTO DE AÇÕES =====

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'criar') {
        $resultado = $royaltiesManager->criar($_POST);
        
        if ($resultado['success']) {
            $success = $resultado['message'];
            $_SESSION['royalty_criado_id'] = $resultado['royalty_id'];
        } else {
            $error = $resultado['message'];
        }
    }
}

// ===== BUSCAR DADOS PARA LISTAGEM =====

$filtros = [
    'estabelecimento_id' => $_GET['estabelecimento_id'] ?? null,
    'status' => $_GET['status'] ?? null,
    'data_inicial' => $_GET['data_inicial'] ?? null,
    'data_final' => $_GET['data_final'] ?? null
];

$royalties = $royaltiesManager->listar($filtros);

// ── Dashboard: totais globais (independente dos filtros de listagem) ────────────
$_dash_where = isAdminGeral() ? '1=1' : 'estabelecimento_id = ' . intval(getEstabelecimentoId());
try {
    $_dash = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('pendente','link_gerado','enviado') THEN valor_royalties ELSE 0 END), 0) AS total_pendente,
            COALESCE(SUM(CASE WHEN status IN ('pago','conciliado','pagamento_manual')  THEN valor_royalties ELSE 0 END), 0) AS total_pago,
            COALESCE(SUM(valor_faturamento_bruto), 0) AS faturamento_bruto,
            COUNT(*) AS total_registros,
            COUNT(CASE WHEN status IN ('pendente','link_gerado','enviado') THEN 1 END) AS qtd_pendente,
            COUNT(CASE WHEN status IN ('pago','conciliado','pagamento_manual') THEN 1 END) AS qtd_pago
        FROM royalties
        WHERE {$_dash_where}
    ")->fetch(\PDO::FETCH_ASSOC);
} catch (\Exception $_e) {
    $_dash = ['total_pendente'=>0,'total_pago'=>0,'faturamento_bruto'=>0,'total_registros'=>0,'qtd_pendente'=>0,'qtd_pago'=>0];
}
$total_pendente    = $_dash['total_pendente']    ?? 0;
$total_pago        = $_dash['total_pago']        ?? 0;
$faturamento_bruto = $_dash['faturamento_bruto'] ?? 0;
$total_registros   = $_dash['total_registros']   ?? 0;
$qtd_pendente      = $_dash['qtd_pendente']      ?? 0;
$qtd_pago          = $_dash['qtd_pago']          ?? 0;
// Manter compat
$total_link_gerado = 0;
$total_enviado     = 0;

// Buscar estabelecimentos para dropdown
if (isAdminGeral()) {
    $stmt = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos = $stmt->fetchAll();
} else {
    $estabelecimento_id = getEstabelecimentoId();
    $stmt = $conn->prepare("SELECT id, name, email_alerta FROM estabelecimentos WHERE id = ?");
    $stmt->execute([$estabelecimento_id]);
    $estabelecimento_atual = $stmt->fetch();
}

// ── Processar salvamento de banco padrão ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_banco_padrao') {
    $estab_id_bp = intval($_POST['estab_id_banco_padrao'] ?? 0);
    $banco_padrao = sanitize($_POST['banco_padrao'] ?? '');
    $bancos_validos = ['stripe', 'cora', 'mercadopago', 'asaas'];
    if ($estab_id_bp > 0 && in_array($banco_padrao, $bancos_validos)) {
        try {
            $cols = $conn->query("SHOW COLUMNS FROM estabelecimentos LIKE 'banco_padrao_royalties'")->fetchAll();
            if (empty($cols)) {
                $conn->exec("ALTER TABLE estabelecimentos ADD COLUMN banco_padrao_royalties VARCHAR(20) NULL DEFAULT NULL COMMENT 'Gateway padrao para pagamento de royalties'");
            }
            $conn->prepare("UPDATE estabelecimentos SET banco_padrao_royalties = ? WHERE id = ?")
                 ->execute([$banco_padrao, $estab_id_bp]);
            $success = 'Banco padrão de royalties atualizado com sucesso!';
        } catch (\Exception $e) {
            $error = 'Erro ao salvar banco padrão: ' . $e->getMessage();
        }
    }
}

// ── Buscar gateways disponíveis e banco padrão por estabelecimento ───────────────────
$gateways_por_estab = [];
try {
    $rows = $conn->query("SELECT estabelecimento_id FROM stripe_config WHERE ativo = 1")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r2) $gateways_por_estab[$r2['estabelecimento_id']][] = 'stripe';
    $rows = $conn->query("SELECT estabelecimento_id FROM cora_config WHERE ativo = 1")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r2) $gateways_por_estab[$r2['estabelecimento_id']][] = 'cora';
    $rows = $conn->query("SELECT estabelecimento_id FROM mercadopago_config WHERE status = 1")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r2) $gateways_por_estab[$r2['estabelecimento_id']][] = 'mercadopago';
    $rows = $conn->query("SELECT estabelecimento_id FROM asaas_config WHERE ativo = 1")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r2) $gateways_por_estab[$r2['estabelecimento_id']][] = 'asaas';
} catch (\Exception $e) { /* tabelas podem nao existir */ }

$banco_padrao_por_estab = [];
try {
    $cols = $conn->query("SHOW COLUMNS FROM estabelecimentos LIKE 'banco_padrao_royalties'")->fetchAll();
    if (!empty($cols)) {
        $rows = $conn->query("SELECT id, banco_padrao_royalties FROM estabelecimentos WHERE banco_padrao_royalties IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r2) $banco_padrao_por_estab[$r2['id']] = $r2['banco_padrao_royalties'];
    }
} catch (\Exception $e) { /* ignorar */ }

$gateway_labels = [
    'stripe'      => ['label' => 'Stripe',       'icon' => 'fab fa-stripe',        'cor' => '#635bff'],
    'cora'        => ['label' => 'Banco Cora',   'icon' => 'fas fa-university',    'cor' => '#ff6b00'],
    'mercadopago' => ['label' => 'Mercado Pago', 'icon' => 'fab fa-cc-mastercard', 'cor' => '#009ee3'],
    'asaas'       => ['label' => 'Asaas',        'icon' => 'fas fa-dollar-sign',   'cor' => '#00a650'],
];

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-header">
                <h1><i class="fas fa-coins"></i> Royalties</h1>
                <p class="text-muted">Gestão de cobranças de royalties</p>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Dashboard de Royalties -->
    <div class="row mb-4" id="dashboardRoyalties">
        <!-- Card: Faturamento Bruto -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #6366f1 !important;">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:#6366f1;text-transform:uppercase;letter-spacing:.5px;">Faturamento Bruto</div>
                            <div style="font-size:24px;font-weight:700;color:#111827;margin:4px 0;">R$ <?= number_format($faturamento_bruto, 2, ',', '.') ?></div>
                            <div style="font-size:12px;color:#6b7280;"><?= $total_registros ?> lançamento<?= $total_registros != 1 ? 's' : '' ?> no total</div>
                        </div>
                        <div style="width:48px;height:48px;background:#ede9fe;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-chart-line" style="color:#6366f1;font-size:20px;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card: Pendentes -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #f59e0b !important;">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:#d97706;text-transform:uppercase;letter-spacing:.5px;">Pendentes</div>
                            <div style="font-size:24px;font-weight:700;color:#111827;margin:4px 0;">R$ <?= number_format($total_pendente, 2, ',', '.') ?></div>
                            <div style="font-size:12px;color:#6b7280;"><?= $qtd_pendente ?> cobrança<?= $qtd_pendente != 1 ? 's' : '' ?> em aberto</div>
                        </div>
                        <div style="width:48px;height:48px;background:#fef3c7;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-clock" style="color:#d97706;font-size:20px;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card: Pagos / Concluídos -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #10b981 !important;">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:#059669;text-transform:uppercase;letter-spacing:.5px;">Concluídos</div>
                            <div style="font-size:24px;font-weight:700;color:#111827;margin:4px 0;">R$ <?= number_format($total_pago, 2, ',', '.') ?></div>
                            <div style="font-size:12px;color:#6b7280;"><?= $qtd_pago ?> pagamento<?= $qtd_pago != 1 ? 's' : '' ?> confirmado<?= $qtd_pago != 1 ? 's' : '' ?></div>
                        </div>
                        <div style="width:48px;height:48px;background:#d1fae5;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-check-circle" style="color:#059669;font-size:20px;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card: Taxa de Recebimento -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #3b82f6 !important;">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;">Taxa de Recebimento</div>
                            <?php
                                $_total_royalties = $total_pendente + $total_pago;
                                $_taxa = $_total_royalties > 0 ? round(($total_pago / $_total_royalties) * 100, 1) : 0;
                            ?>
                            <div style="font-size:24px;font-weight:700;color:#111827;margin:4px 0;"><?= $_taxa ?>%</div>
                            <div style="font-size:12px;color:#6b7280;">do valor total cobrado</div>
                        </div>
                        <div style="width:48px;height:48px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-percentage" style="color:#2563eb;font-size:20px;"></i>
                        </div>
                    </div>
                    <!-- Barra de progresso -->
                    <div style="margin-top:10px;">
                        <div style="background:#e5e7eb;border-radius:9999px;height:6px;">
                            <div style="background:#3b82f6;border-radius:9999px;height:6px;width:<?= $_taxa ?>%;transition:width .5s;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Alerta webhook MP -->
    <div class="alert alert-info alert-dismissible" style="border-left:4px solid #009ee3;background:#f0f9ff;" role="alert">
        <i class="fab fa-cc-mastercard" style="color:#009ee3;"></i>
        <strong>Conciliação automática via Mercado Pago ativa.</strong>
        Configure o webhook no painel do MP para:
        <code style="background:#e0f2fe;padding:2px 6px;border-radius:4px;"><?= defined('SITE_URL') ? SITE_URL : 'https://ochoppoficial.com.br' ?>/api/webhook_royalties_mp.php</code>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Painel: Banco Padrão por Estabelecimento -->
    <?php if (isAdminGeral() && !empty($estabelecimentos)): ?>
    <div class="card mb-4" style="border-left:4px solid #2563eb;">
        <div class="card-header" style="background:#eff6ff;cursor:pointer;" onclick="toggleBancoPadrao()">
            <i class="fas fa-university" style="color:#2563eb;"></i>
            <strong style="color:#1e40af;"> Banco Padrão para Pagamento de Royalties</strong>
            <span style="float:right;font-size:12px;color:#6b7280;">Clique para expandir/recolher <i class="fas fa-chevron-down" id="iconBancoPadrao"></i></span>
        </div>
        <div id="painelBancoPadrao" style="display:none;">
        <div class="card-body">
            <p class="text-muted" style="font-size:13px;margin-bottom:16px;">
                <i class="fas fa-info-circle"></i>
                Defina qual gateway de pagamento cada franqueado deverá usar para pagar os royalties.
                Ao definir um banco padrão, o franqueado <strong>só poderá pagar pelo gateway selecionado</strong>.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
            <?php foreach ($estabelecimentos as $est):
                $gws_estab = $gateways_por_estab[$est['id']] ?? [];
                $bp = $banco_padrao_por_estab[$est['id']] ?? '';
            ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;">
                <div style="font-weight:600;color:#111827;font-size:14px;margin-bottom:10px;">
                    <i class="fas fa-store" style="color:#6b7280;"></i> <?= htmlspecialchars($est['name']) ?>
                </div>
                <?php if (empty($gws_estab)): ?>
                    <div style="font-size:12px;color:#9ca3af;"><i class="fas fa-exclamation-triangle"></i> Nenhum gateway ativo. Configure em <a href="meios_pagamento.php">Meios de Pagamento</a>.</div>
                <?php else: ?>
                <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="salvar_banco_padrao">
                    <input type="hidden" name="estab_id_banco_padrao" value="<?= $est['id'] ?>">
                    <select name="banco_padrao" style="flex:1;min-width:160px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">-- Sem padrão (livre) --</option>
                        <?php foreach ($gws_estab as $gw):
                            $gl = $gateway_labels[$gw];
                        ?>
                        <option value="<?= $gw ?>" <?= $bp === $gw ? 'selected' : '' ?>>
                            <?= $gl['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" style="white-space:nowrap;">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                    <?php if (!empty($bp) && isset($gateway_labels[$bp])): ?>
                    <span style="font-size:11px;padding:3px 8px;border-radius:9999px;background:#d1fae5;color:#065f46;font-weight:600;">
                        <i class="<?= $gateway_labels[$bp]['icon'] ?>"></i> <?= $gateway_labels[$bp]['label'] ?> (padrão)
                    </span>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        </div>
    </div>
    <script>
    function toggleBancoPadrao() {
        const p = document.getElementById('painelBancoPadrao');
        const i = document.getElementById('iconBancoPadrao');
        if (p.style.display === 'none') {
            p.style.display = 'block';
            i.className = 'fas fa-chevron-up';
        } else {
            p.style.display = 'none';
            i.className = 'fas fa-chevron-down';
        }
    }
    </script>
    <?php endif; ?>

    <!-- Botão Novo Lançamento -->
    <?php if (isAdminGeral()): ?>
    <div class="row mb-3">
        <div class="col-12">
            <button type="button" class="btn btn-primary" onclick="openModal('modalNovoRoyalty')">
                <i class="fas fa-plus"></i> Novo Lançamento
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-filter"></i> Filtros
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if (isAdminGeral()): ?>
                <div class="col-md-3">
                    <label class="form-label">Estabelecimento</label>
                    <select name="estabelecimento_id" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($estabelecimentos as $est): ?>
                        <option value="<?= $est['id'] ?>" <?= $filtros['estabelecimento_id'] == $est['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($est['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="pendente" <?= $filtros['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                        <option value="link_gerado" <?= $filtros['status'] === 'link_gerado' ? 'selected' : '' ?>>Link Gerado</option>
                        <option value="enviado" <?= $filtros['status'] === 'enviado' ? 'selected' : '' ?>>Enviado</option>
                        <option value="pago" <?= $filtros['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                        <option value="cancelado" <?= $filtros['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Data Inicial</label>
                    <input type="date" name="data_inicial" class="form-control" value="<?= $filtros['data_inicial'] ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Data Final</label>
                    <input type="date" name="data_final" class="form-control" value="<?= $filtros['data_final'] ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de Royalties -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Lançamentos de Royalties
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Estabelecimento</th>
                            <th>Período</th>
                            <th>Faturamento Bruto</th>
                            <th>Royalties (7%)</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($royalties)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <i class="fas fa-inbox"></i> Nenhum royalty encontrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($royalties as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['estabelecimento_nome']) ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($r['periodo_inicial'])) ?> a 
                                <?= date('d/m/Y', strtotime($r['periodo_final'])) ?>
                            </td>
                            <td>R$ <?= number_format($r['valor_faturamento_bruto'], 2, ',', '.') ?></td>
                            <td><strong>R$ <?= number_format($r['valor_royalties'], 2, ',', '.') ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($r['data_vencimento'])) ?></td>
                            <td><?php echo getStatusBadge($r['status']); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <!-- Visualizar -->
                                    <button class="btn btn-info" onclick="visualizarRoyalty(<?= $r['id'] ?>)" title="Visualizar">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <!-- Editar (somente admin e status pendente/link_gerado) -->
                                    <?php if (isAdminGeral() && !in_array($r['status'], ['pago','conciliado','pagamento_manual','cancelado'])): ?>
                                    <button class="btn btn-secondary" onclick="editarRoyalty(<?= $r['id'] ?>)" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Pagar (gerar link) -->
                                    <?php if ($r['status'] === 'pendente'): ?>
                                    <button class="btn btn-success" onclick="pagarRoyalty(<?= $r['id'] ?>)" title="Gerar Link de Pagamento">
                                        <i class="fas fa-credit-card"></i> Pagar
                                    </button>
                                    <?php endif; ?>
                                    <!-- Boleto/Reenvio -->
                                    <?php if (in_array($r['status'], ['link_gerado','enviado'])): ?>
                                    <?php if (!empty($r['boleto_url'])): ?>
                                    <button class="btn btn-warning" onclick="window.open('<?= htmlspecialchars($r['boleto_url']) ?>', '_blank')" title="Ver Boleto">
                                        <i class="fas fa-barcode"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!empty($r['mp_link_pagamento'])): ?>
                                    <button class="btn btn-outline-info" onclick="window.open('<?= htmlspecialchars($r['mp_link_pagamento']) ?>', '_blank')" title="Link MP">
                                        <i class="fab fa-cc-mastercard"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-primary" onclick="reenviarEmail(<?= $r['id'] ?>)" title="Reenviar E-mail">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Pagamento Manual -->
                                    <?php if (!in_array($r['status'], ['pago','conciliado','pagamento_manual','cancelado'])): ?>
                                    <button class="btn btn-purple" onclick="pagamentoManual(<?= $r['id'] ?>)" title="Marcar como Pago Manualmente"
                                        style="background:#7c3aed;color:#fff;border-color:#7c3aed;">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Cancelar -->
                                    <?php if (!in_array($r['status'], ['pago','conciliado','pagamento_manual','cancelado'])): ?>
                                    <button class="btn btn-danger" onclick="cancelarRoyalty(<?= $r['id'] ?>)" title="Cancelar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Função auxiliar para exibir badge de status
function getStatusBadge($status) {
    $badges = [
        'pendente'         => '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pendente</span>',
        'link_gerado'      => '<span class="badge bg-info"><i class="fas fa-link"></i> Link Gerado</span>',
        'enviado'          => '<span class="badge bg-primary"><i class="fas fa-envelope"></i> Enviado</span>',
        'pago'             => '<span class="badge bg-success"><i class="fas fa-check"></i> Pago</span>',
        'conciliado'       => '<span class="badge" style="background:#009ee3;"><i class="fab fa-cc-mastercard"></i> Conciliado MP</span>',
        'pagamento_manual' => '<span class="badge" style="background:#7c3aed;"><i class="fas fa-hand-holding-usd"></i> Pgto. Manual</span>',
        'cancelado'        => '<span class="badge bg-danger"><i class="fas fa-times"></i> Cancelado</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}
?>


<!-- Modal: Novo Lançamento de Royalty -->
<?php if (isAdminGeral()): ?>
<div id="modalNovoRoyalty" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Novo Lançamento de Royalty</h2>
            <span class="close" onclick="closeModal('modalNovoRoyalty')">&times;</span>
        </div>
        <div class="modal-body">
            <form id="formNovoRoyalty" method="POST" action="">
                <input type="hidden" name="action" value="criar">
                
                <div class="row">
                    <!-- Estabelecimento -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Estabelecimento</label>
                        <select name="estabelecimento_id" id="estabelecimento_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($estabelecimentos as $est): ?>
                            <option value="<?= $est['id'] ?>"><?= htmlspecialchars($est['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- CNPJ (preenchido automaticamente) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">CNPJ do Estabelecimento</label>
                        <input type="text" id="cnpj_estabelecimento" class="form-control" readonly 
                               placeholder="Selecione um estabelecimento">
                        <small class="text-muted">CNPJ preenchido automaticamente</small>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Período Inicial -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Período Inicial</label>
                        <input type="date" name="periodo_inicial" id="periodo_inicial" class="form-control" required>
                    </div>
                    
                    <!-- Período Final -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Período Final</label>
                        <input type="date" name="periodo_final" id="periodo_final" class="form-control" required>
                    </div>
                </div>
                
                <!-- Descrição -->
                <div class="mb-3">
                    <label class="form-label required">Descrição da Cobrança</label>
                    <textarea name="descricao" id="descricao" class="form-control" rows="2" required
                              placeholder="Ex: Royalties referente ao mês de Dezembro/2024"></textarea>
                </div>
                
                <div class="row">
                    <!-- Valor Faturamento Bruto -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Valor do Faturamento Bruto</label>
                        <input type="text" name="valor_faturamento_bruto" id="valor_faturamento_bruto" 
                               class="form-control money" required placeholder="R$ 0,00">
                    </div>
                    
                    <!-- Valor Royalties (calculado automaticamente) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Valor dos Royalties (7%)</label>
                        <input type="text" id="valor_royalties_display" class="form-control" readonly 
                               value="R$ 0,00" style="background: #e9ecef; font-weight: bold; color: #28a745;">
                        <small class="text-muted">Calculado automaticamente</small>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Forma de Pagamento (Apenas para referência) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Forma de Pagamento</label>
                        <select name="forma_pagamento" id="forma_pagamento" class="form-select">
                            <option value="boleto_pix">Boleto + PIX</option>
                            <option value="cartao_pix">Cartão + PIX</option>
                            <option value="todos">Todos os métodos</option>
                        </select>
                    </div>
                </div>
                
                <!-- E-mail para Cobrança -->
                <div class="mb-3">
                    <label class="form-label required">E-mail para Cobrança</label>
                    <input type="email" name="email_cobranca" id="email_cobranca" class="form-control" required
                           placeholder="email@exemplo.com">
                    <small class="text-muted">Preenchido automaticamente do cadastro do estabelecimento</small>
                </div>
                
                <!-- E-mails Adicionais -->
                <div class="mb-3">
                    <label class="form-label">E-mails Adicionais (opcional)</label>
                    <textarea name="emails_adicionais" id="emails_adicionais" class="form-control" rows="2"
                              placeholder="email1@exemplo.com, email2@exemplo.com, email3@exemplo.com"></textarea>
                    <small class="text-muted">Separe múltiplos e-mails por vírgula</small>
                </div>
                
                <div class="row">
                    <!-- Data de Vencimento -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Data de Vencimento</label>
                        <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                        <small class="text-muted">Padrão: 30 dias após hoje</small>
                    </div>
                </div>
                
                <!-- Observações -->
                <div class="mb-3">
                    <label class="form-label">Observações (opcional)</label>
                    <textarea name="observacoes" id="observacoes" class="form-control" rows="2"
                              placeholder="Informações adicionais sobre esta cobrança"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalNovoRoyalty')">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Criar Royalty
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Conferência antes de Gerar Link -->
<div id="modalConferencia" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h2><i class="fas fa-clipboard-check"></i> Conferência - Gerar Link de Pagamento</h2>
            <span class="close" onclick="closeModal('modalConferencia')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="row">
                <!-- Coluna Esquerda: Dados Informados -->
                <div class="col-md-6">
                    <h4 class="mb-3"><i class="fas fa-info-circle"></i> Dados Informados</h4>
                    <div class="card">
                        <div class="card-body">
                            <div id="conferencia_dados"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Coluna Direita: Preview -->
                <div class="col-md-6">
                    <h4 class="mb-3"><i class="fas fa-eye"></i> Preview</h4>
                    
                    <!-- Preview do Link -->
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-link"></i> Link de Pagamento
                        </div>
                        <div class="card-body">
                            <p class="text-muted"><small>O link será gerado após confirmação</small></p>
                            <div class="alert alert-info">
                                <strong>🔗 Link Stripe</strong><br>
                                <span id="preview_link">https://buy.stripe.com/...</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preview do E-mail -->
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <i class="fas fa-envelope"></i> Preview do E-mail
                        </div>
                        <div class="card-body">
                            <p><strong>Assunto:</strong></p>
                            <p id="preview_assunto" class="text-muted"></p>
                            
                            <p><strong>Destinatários:</strong></p>
                            <p id="preview_destinatarios" class="text-muted"></p>
                            
                            <p><strong>Corpo:</strong></p>
                            <div id="preview_corpo" class="border p-3" style="max-height: 300px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer mt-4">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalConferencia')">
                    <i class="fas fa-arrow-left"></i> Voltar para Edição
                </button>
                <button type="button" class="btn btn-success" onclick="gerarLink()">
                    <i class="fas fa-link"></i> Gerar Link Stripe
                </button>
                <button type="button" class="btn btn-primary" onclick="gerarEEnviar()">
                    <i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Visualizar Royalty -->
<div id="modalVisualizarRoyalty" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice-dollar"></i> Detalhes do Royalty</h2>
            <span class="close" onclick="closeModal('modalVisualizarRoyalty')">&times;</span>
        </div>
        <div class="modal-body">
            <div id="visualizar_conteudo"></div>
        </div>
    </div>
</div>


<script>
// ===== VARIÁVEIS GLOBAIS =====
let royaltyAtual = null;

// ===== INICIALIZAÇÃO =====
document.addEventListener('DOMContentLoaded', function() {
    // Aplicar máscara de moeda
    aplicarMascaraMoeda();
    
    // Configurar data de vencimento padrão (30 dias)
    const dataVencimento = document.getElementById('data_vencimento');
    if (dataVencimento) {
        const hoje = new Date();
        hoje.setDate(hoje.getDate() + 30);
        dataVencimento.value = hoje.toISOString().split('T')[0];
    }
    
    // Event listeners
    const estabelecimentoSelect = document.getElementById('estabelecimento_id');
    if (estabelecimentoSelect) {
        estabelecimentoSelect.addEventListener('change', carregarDadosEstabelecimento);
    }
    
    const valorFaturamento = document.getElementById('valor_faturamento_bruto');
    if (valorFaturamento) {
        valorFaturamento.addEventListener('input', calcularRoyalties);
        valorFaturamento.addEventListener('blur', calcularRoyalties);
    }
    
    // Validação de período
    const periodoInicial = document.getElementById('periodo_inicial');
    const periodoFinal = document.getElementById('periodo_final');
    if (periodoInicial && periodoFinal) {
        periodoFinal.addEventListener('change', function() {
            if (periodoInicial.value && periodoFinal.value) {
                if (new Date(periodoFinal.value) < new Date(periodoInicial.value)) {
                    alert('O período final deve ser maior que o período inicial!');
                    periodoFinal.value = '';
                }
            }
        });
    }
});

// ===== CARREGAR DADOS DO ESTABELECIMENTO =====
function carregarDadosEstabelecimento() {
    const estabelecimentoId = document.getElementById('estabelecimento_id').value;
    
    if (!estabelecimentoId) {
        document.getElementById('cnpj_estabelecimento').value = '';
        document.getElementById('email_cobranca').value = '';
        return;
    }
    
    fetch('ajax/get_estabelecimento_email.php?id=' + estabelecimentoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cnpj_estabelecimento').value = data.cnpj || '';
                document.getElementById('email_cobranca').value = data.email || '';
            }
        })
        .catch(error => {
            console.error('Erro ao buscar dados do estabelecimento:', error);
        });
}

// ===== CALCULAR ROYALTIES (7%) =====
function calcularRoyalties() {
    const valorInput = document.getElementById('valor_faturamento_bruto');
    const valorDisplay = document.getElementById('valor_royalties_display');
    
    if (!valorInput || !valorDisplay) return;
    
    // Remover formatação e converter para número (usando parseBRToFloat para evitar bug de separador)
    let valor = parseFloat(parseBRToFloat(valorInput.value.replace(/[R$\s]/g, ''))) || 0;
    
    // Calcular 7%
    const royalties = valor * 0.07;
    
    // Exibir formatado
    valorDisplay.value = 'R$ ' + royalties.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ===== APLICAR MÁSCARA DE MOEDA =====
function aplicarMascaraMoeda() {
    const inputs = document.querySelectorAll('.money');
    inputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = (parseInt(value) / 100).toFixed(2);
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            e.target.value = 'R$ ' + value;
            
            // Calcular royalties se for o campo de faturamento
            if (e.target.id === 'valor_faturamento_bruto') {
                calcularRoyalties();
            }
        });
    });
}

// ===== CONFERIR ROYALTY (ABRIR MODAL DE CONFERÊNCIA) =====
function conferirRoyalty(id) {
    fetch('ajax/royalties_actions.php?action=buscar&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                royaltyAtual = data.royalty;
                preencherModalConferencia(data.royalty);
                openModal('modalConferencia');
            } else {
                alert('Erro ao buscar royalty: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao buscar dados do royalty');
        });
}

// ===== PREENCHER MODAL DE CONFERÊNCIA =====
function preencherModalConferencia(royalty) {
    // Dados informados
    const dadosHTML = `
        <table class="table table-bordered">
            <tr>
                <th>Estabelecimento:</th>
                <td>${royalty.estabelecimento_nome}</td>
            </tr>
            <tr>
                <th>Período:</th>
                <td>${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}</td>
            </tr>
            <tr>
                <th>Faturamento Bruto:</th>
                <td>R$ ${formatarValor(royalty.valor_faturamento_bruto)}</td>
            </tr>
            <tr>
                <th>Royalties (7%):</th>
                <td class="text-success"><strong>R$ ${formatarValor(royalty.valor_royalties)}</strong></td>
            </tr>
            <tr>
                <th>Data Vencimento:</th>
                <td>${formatarData(royalty.data_vencimento)}</td>
            </tr>
            <tr>
                <th>E-mail Principal:</th>
                <td>${royalty.email_cobranca}</td>
            </tr>
            ${royalty.emails_adicionais ? `
            <tr>
                <th>E-mails Adicionais:</th>
                <td>${royalty.emails_adicionais}</td>
            </tr>
            ` : ''}
            <tr>
                <th>Forma Pagamento:</th>
                <td>${formatarFormaPagamento(royalty.forma_pagamento)}</td>
            </tr>
        </table>
    `;
    document.getElementById('conferencia_dados').innerHTML = dadosHTML;
    
    // Preview do e-mail
    const assunto = `Cobrança de Royalties - ${royalty.estabelecimento_nome} - ${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}`;
    document.getElementById('preview_assunto').textContent = assunto;
    
    const destinatarios = royalty.emails_adicionais 
        ? `${royalty.email_cobranca}, ${royalty.emails_adicionais}`
        : royalty.email_cobranca;
    document.getElementById('preview_destinatarios').textContent = destinatarios;
    
    const corpoEmail = `
        <p>Prezado(a) <strong>${royalty.estabelecimento_nome}</strong>,</p>
        <p>Segue link para pagamento dos royalties referente ao período ${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}.</p>
        <ul>
            <li><strong>Valor:</strong> R$ ${formatarValor(royalty.valor_royalties)}</li>
            <li><strong>Vencimento:</strong> ${formatarData(royalty.data_vencimento)}</li>
            <li><strong>Forma de Pagamento:</strong> ${formatarFormaPagamento(royalty.forma_pagamento)}</li>
        </ul>
        <p><strong>Descrição:</strong> ${royalty.descricao}</p>
    `;
    document.getElementById('preview_corpo').innerHTML = corpoEmail;
}

// ===== GERAR LINK =====
function gerarLink() {
    if (!royaltyAtual) return;
    
    if (!confirm('Confirma a geração do link de pagamento via Stripe?')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=gerar_link&id=${royaltyAtual.id}`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Gerar Link Stripe';
        
        if (data.success) {
            alert('Link gerado com sucesso!');
            closeModal('modalConferencia');
            location.reload();
        } else {
            alert('Erro ao gerar link: ' + data.message);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Gerar Link Stripe';
        console.error('Erro:', error);
        alert('Erro ao gerar link');
    });
}

// ===== GERAR E ENVIAR TUDO =====
function gerarEEnviar() {
    if (!royaltyAtual) return;
    
    if (!confirm('Confirma a geração do link E envio do e-mail?')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=gerar_e_enviar&id=${royaltyAtual.id}`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo';
        
        if (data.success) {
            alert('Link gerado e e-mail enviado com sucesso!');
            closeModal('modalConferencia');
            location.reload();
        } else {
            alert('Erro: ' + data.message);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo';
        console.error('Erro:', error);
        alert('Erro ao processar');
    });
}

// ===== REENVIAR E-MAIL =====
function reenviarEmail(id) {
    if (!confirm('Confirma o reenvio do e-mail de cobrança?')) {
        return;
    }
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=enviar_email&id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('E-mail enviado com sucesso!');
            location.reload();
        } else {
            alert('Erro ao enviar e-mail: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao enviar e-mail');
    });
}

// ===== VISUALIZAR ROYALTY =====
function visualizarRoyalty(id) {
    fetch('ajax/royalties_actions.php?action=buscar&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const royalty = data.royalty;
                const html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Informações Gerais</h5>
                            <table class="table table-bordered">
                                <tr><th>Estabelecimento:</th><td>${royalty.estabelecimento_nome}</td></tr>
                                <tr><th>CNPJ:</th><td>${royalty.cnpj || '-'}</td></tr>
                                <tr><th>Período:</th><td>${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}</td></tr>
                                <tr><th>Descrição:</th><td>${royalty.descricao}</td></tr>
                                <tr><th>Status:</th><td>${royalty.status}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Valores</h5>
                            <table class="table table-bordered">
                                <tr><th>Faturamento Bruto:</th><td>R$ ${formatarValor(royalty.valor_faturamento_bruto)}</td></tr>
                                <tr><th>Royalties (7%):</th><td class="text-success"><strong>R$ ${formatarValor(royalty.valor_royalties)}</strong></td></tr>
                                <tr><th>Vencimento:</th><td>${formatarData(royalty.data_vencimento)}</td></tr>
                                <tr><th>Forma Pagamento:</th><td>${formatarFormaPagamento(royalty.forma_pagamento)}</td></tr>
                            </table>
                        </div>
                    </div>
                    ${royalty.payment_link_url ? `
                    <div class="alert alert-info mt-3">
                        <strong>🔗 Link de Pagamento:</strong><br>
                        <a href="${royalty.payment_link_url}" target="_blank">${royalty.payment_link_url}</a>
                    </div>
                    ` : ''}
                    ${royalty.observacoes ? `
                    <div class="mt-3">
                        <strong>Observações:</strong><br>
                        ${royalty.observacoes}
                    </div>
                    ` : ''}
                `;
                document.getElementById('visualizar_conteudo').innerHTML = html;
                openModal('modalVisualizarRoyalty');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao buscar dados');
        });
}

// ===== CANCELAR ROYALTY =====
function cancelarRoyalty(id) {
    if (!confirm('Tem certeza que deseja CANCELAR este royalty?')) {
        return;
    }
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=cancelar&id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Royalty cancelado com sucesso!');
            location.reload();
        } else {
            alert('Erro ao cancelar: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao cancelar');
    });
}

// ===== FUNÇÃO PAGAR ROYALTY =====
function pagarRoyalty(id) {
    window.location.href = `royalty_selecionar_pagamento.php?id=${id}`;
}
// ===== PAGAMENTO MANUAL =====
function pagamentoManual(id) {
    document.getElementById('pm_royalty_id').value = id;
    document.getElementById('pm_data_pagamento').value = new Date().toISOString().split('T')[0];
    document.getElementById('pm_valor_pago').value = '';
    document.getElementById('pm_observacao').value = '';
    openModal('modalPagamentoManual');
}
function confirmarPagamentoManual() {
    const id    = document.getElementById('pm_royalty_id').value;
    const data  = document.getElementById('pm_data_pagamento').value;
    const valor = document.getElementById('pm_valor_pago').value;
    const obs   = document.getElementById('pm_observacao').value;
    if (!data || !valor) { alert('Preencha a data e o valor pago.'); return; }
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=pagamento_manual&id=${id}&data_pagamento=${encodeURIComponent(data)}&valor_pago=${encodeURIComponent(valor)}&observacao=${encodeURIComponent(obs)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Pagamento registrado com sucesso!'); location.reload(); }
        else { alert('Erro: ' + data.message); }
    })
    .catch(() => alert('Erro ao registrar pagamento.'));
}
// ===== EDITAR ROYALTY =====
function editarRoyalty(id) {
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=buscar&id=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert('Erro ao carregar: ' + data.message); return; }
        const r = data.royalty;
        document.getElementById('edit_royalty_id').value          = r.id;
        document.getElementById('edit_mes_referencia').value      = r.mes_referencia ? r.mes_referencia.substring(0,7) : '';
        document.getElementById('edit_valor_faturamento').value   = r.valor_faturamento_bruto;
        document.getElementById('edit_percentual_royalties').value= r.percentual_royalties;
        document.getElementById('edit_valor_royalties').value     = r.valor_royalties;
        document.getElementById('edit_data_vencimento').value     = r.data_vencimento;
        document.getElementById('edit_observacoes').value         = r.observacoes || '';
        openModal('modalEditarRoyalty');
    })
    .catch(() => alert('Erro ao carregar dados.'));
}
function salvarEdicaoRoyalty() {
    const id = document.getElementById('edit_royalty_id').value;
    const params = new URLSearchParams({
        action: 'editar',
        id,
        mes_referencia:        document.getElementById('edit_mes_referencia').value,
        valor_faturamento_bruto: document.getElementById('edit_valor_faturamento').value,
        percentual_royalties:  document.getElementById('edit_percentual_royalties').value,
        valor_royalties:       document.getElementById('edit_valor_royalties').value,
        data_vencimento:       document.getElementById('edit_data_vencimento').value,
        observacoes:           document.getElementById('edit_observacoes').value,
    });
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Royalty atualizado!'); location.reload(); }
        else { alert('Erro: ' + data.message); }
    })
    .catch(() => alert('Erro ao salvar.'));
}

// ===== FUNÇÕES AUXILIARES =====
function formatarData(data) {
    if (!data) return '-';
    const d = new Date(data + 'T00:00:00');
    return d.toLocaleDateString('pt-BR');
}

function formatarValor(valor) {
    return parseFloat(valor).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatarFormaPagamento(forma) {
    const formas = {
        'boleto_pix': 'Boleto + PIX',
        'cartao_pix': 'Cartão + PIX',
        'todos': 'Todos os métodos'
    };
    return formas[forma] || forma;
}
</script>

<!-- Modal: Pagamento Manual -->
<div id="modalPagamentoManual" class="modal">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
            <h2 style="color:#fff;"><i class="fas fa-hand-holding-usd"></i> Registrar Pagamento Manual</h2>
            <span class="close" onclick="closeModal('modalPagamentoManual')" style="color:#fff;">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pm_royalty_id">
            <div class="alert alert-warning" style="font-size:13px;">
                <i class="fas fa-info-circle"></i> O status será alterado para <strong>Pagamento Manual</strong>, equivalente ao recebimento via webhook do Mercado Pago.
            </div>
            <div class="mb-3">
                <label class="form-label"><strong>Data do Pagamento *</strong></label>
                <input type="date" id="pm_data_pagamento" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label"><strong>Valor Pago (R$) *</strong></label>
                <input type="number" id="pm_valor_pago" class="form-control" step="0.01" min="0" placeholder="0,00">
            </div>
            <div class="mb-3">
                <label class="form-label">Observação</label>
                <textarea id="pm_observacao" class="form-control" rows="3" placeholder="Ex: Pago via transferência bancária, comprovante recebido..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalPagamentoManual')">Cancelar</button>
            <button type="button" class="btn btn-success" onclick="confirmarPagamentoManual()">
                <i class="fas fa-check"></i> Confirmar Pagamento
            </button>
        </div>
    </div>
</div>

<!-- Modal: Editar Royalty -->
<div id="modalEditarRoyalty" class="modal">
    <div class="modal-content" style="max-width:560px;">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Editar Lançamento de Royalty</h2>
            <span class="close" onclick="closeModal('modalEditarRoyalty')">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit_royalty_id">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><strong>Mês de Referência</strong></label>
                    <input type="month" id="edit_mes_referencia" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><strong>Vencimento</strong></label>
                    <input type="date" id="edit_data_vencimento" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><strong>Faturamento Bruto (R$)</strong></label>
                    <input type="number" id="edit_valor_faturamento" class="form-control" step="0.01" min="0"
                        oninput="recalcularEditRoyalty()">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><strong>% Royalties</strong></label>
                    <input type="number" id="edit_percentual_royalties" class="form-control" step="0.01" min="0" max="100"
                        oninput="recalcularEditRoyalty()">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><strong>Valor Royalties (R$)</strong></label>
                    <input type="number" id="edit_valor_royalties" class="form-control" step="0.01" min="0">
                </div>
                <div class="col-12">
                    <label class="form-label">Observações</label>
                    <textarea id="edit_observacoes" class="form-control" rows="3"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditarRoyalty')">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="salvarEdicaoRoyalty()">
                <i class="fas fa-save"></i> Salvar Alterações
            </button>
        </div>
    </div>
</div>
<script>
function recalcularEditRoyalty() {
    const fat = parseFloat(document.getElementById('edit_valor_faturamento').value) || 0;
    const pct = parseFloat(document.getElementById('edit_percentual_royalties').value) || 0;
    if (fat > 0 && pct > 0) {
        document.getElementById('edit_valor_royalties').value = (fat * pct / 100).toFixed(2);
    }
}
</script>
<?php require_once '../includes/footer.php'; ?>
