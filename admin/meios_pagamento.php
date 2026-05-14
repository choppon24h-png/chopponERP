<?php
/**
 * Financeiro — Meios de Pagamento
 * Unifica: Stripe, Banco Cora, Mercado Pago, Asaas
 * ChopponERP v2.0
 */
$page_title   = 'Financeiro - Meios de Pagamento';
$current_page = 'meios_pagamento';

require_once '../includes/config.php';
require_once '../includes/auth.php';
requireAuth();

if (!isAdminGeral()) {
    header('Location: ../index.php');
    exit;
}

$conn    = getDBConnection();
$success = '';
$error   = '';

// ── Detectar aba ativa ────────────────────────────────────────────────────────
$aba = $_GET['aba'] ?? 'stripe';
$allowed_abas = ['stripe', 'cora', 'mercadopago', 'asaas'];
if (!in_array($aba, $allowed_abas)) $aba = 'stripe';

// ── Buscar estabelecimentos ───────────────────────────────────────────────────
$stmt_e = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
$estabelecimentos = $stmt_e->fetchAll(\PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO POST — STRIPE
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gateway'] ?? '') === 'stripe') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $estab_id             = intval($_POST['estabelecimento_id']);
        $stripe_public_key    = sanitize($_POST['stripe_public_key']);
        $stripe_secret_key    = sanitize($_POST['stripe_secret_key']);
        $stripe_webhook_secret= sanitize($_POST['stripe_webhook_secret']);
        $modo                 = sanitize($_POST['modo']);
        $ativo                = isset($_POST['ativo']) ? 1 : 0;
        try {
            $stmt = $conn->prepare("SELECT id FROM stripe_config WHERE estabelecimento_id = ?");
            $stmt->execute([$estab_id]);
            if ($stmt->fetch()) {
                $conn->prepare("UPDATE stripe_config SET stripe_public_key=?, stripe_secret_key=?, stripe_webhook_secret=?, modo=?, ativo=? WHERE estabelecimento_id=?")
                     ->execute([$stripe_public_key, $stripe_secret_key, $stripe_webhook_secret, $modo, $ativo, $estab_id]);
                $success = 'Configuração do Stripe atualizada com sucesso!';
            } else {
                $conn->prepare("INSERT INTO stripe_config (estabelecimento_id, stripe_public_key, stripe_secret_key, stripe_webhook_secret, modo, ativo) VALUES (?,?,?,?,?,?)")
                     ->execute([$estab_id, $stripe_public_key, $stripe_secret_key, $stripe_webhook_secret, $modo, $ativo]);
                $success = 'Configuração do Stripe cadastrada com sucesso!';
            }
        } catch (\Exception $e) { $error = 'Erro Stripe: ' . $e->getMessage(); }
    }
    if ($action === 'delete') {
        try {
            $conn->prepare("DELETE FROM stripe_config WHERE id=?")->execute([intval($_POST['id'])]);
            $success = 'Configuração Stripe removida!';
        } catch (\Exception $e) { $error = $e->getMessage(); }
    }
    $aba = 'stripe';
}

// ════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO POST — CORA
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gateway'] ?? '') === 'cora') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $estab_id     = intval($_POST['estabelecimento_id']);
        $client_id    = sanitize($_POST['client_id']);
        $client_secret= sanitize($_POST['client_secret']);
        $ambiente     = sanitize($_POST['ambiente']);
        $ativo        = isset($_POST['ativo']) ? 1 : 0;
        try {
            $stmt = $conn->prepare("SELECT id FROM cora_config WHERE estabelecimento_id = ?");
            $stmt->execute([$estab_id]);
            if ($stmt->fetch()) {
                $conn->prepare("UPDATE cora_config SET client_id=?, client_secret=?, environment=?, ativo=? WHERE estabelecimento_id=?")
                     ->execute([$client_id, $client_secret, $ambiente, $ativo, $estab_id]);
                $success = 'Configuração do Banco Cora atualizada!';
            } else {
                $conn->prepare("INSERT INTO cora_config (estabelecimento_id, client_id, client_secret, environment, ativo) VALUES (?,?,?,?,?)")
                     ->execute([$estab_id, $client_id, $client_secret, $ambiente, $ativo]);
                $success = 'Configuração do Banco Cora cadastrada!';
            }
        } catch (\Exception $e) { $error = 'Erro Cora: ' . $e->getMessage(); }
    }
    if ($action === 'delete') {
        try {
            $conn->prepare("DELETE FROM cora_config WHERE id=?")->execute([intval($_POST['id'])]);
            $success = 'Configuração Cora removida!';
        } catch (\Exception $e) { $error = $e->getMessage(); }
    }
    $aba = 'cora';
}

// ════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO POST — MERCADO PAGO
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gateway'] ?? '') === 'mercadopago') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $estab_id       = intval($_POST['estabelecimento_id']);
        $access_token   = sanitize($_POST['access_token']);
        $public_key     = sanitize($_POST['public_key'] ?? '');
        $ambiente       = sanitize($_POST['ambiente']);
        $webhook_url    = sanitize($_POST['webhook_url'] ?? '');
        $webhook_secret = sanitize($_POST['webhook_secret'] ?? '');
        $status_mp      = isset($_POST['status']) ? 1 : 0;
        try {
            $stmt = $conn->prepare("SELECT id FROM mercadopago_config WHERE estabelecimento_id = ?");
            $stmt->execute([$estab_id]);
            if ($stmt->fetch()) {
                $conn->prepare("UPDATE mercadopago_config SET access_token=?, public_key=?, ambiente=?, webhook_url=?, webhook_secret=?, status=? WHERE estabelecimento_id=?")
                     ->execute([$access_token, $public_key, $ambiente, $webhook_url, $webhook_secret, $status_mp, $estab_id]);
                $success = 'Configuração do Mercado Pago atualizada!';
            } else {
                $conn->prepare("INSERT INTO mercadopago_config (estabelecimento_id, access_token, public_key, ambiente, webhook_url, webhook_secret, status) VALUES (?,?,?,?,?,?,?)")
                     ->execute([$estab_id, $access_token, $public_key, $ambiente, $webhook_url, $webhook_secret, $status_mp]);
                $success = 'Configuração do Mercado Pago cadastrada!';
            }
        } catch (\Exception $e) { $error = 'Erro MP: ' . $e->getMessage(); }
    }
    if ($action === 'delete') {
        try {
            $conn->prepare("DELETE FROM mercadopago_config WHERE id=?")->execute([intval($_POST['id'])]);
            $success = 'Configuração Mercado Pago removida!';
        } catch (\Exception $e) { $error = $e->getMessage(); }
    }
    $aba = 'mercadopago';
}

// ════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO POST — ASAAS
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gateway'] ?? '') === 'asaas') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $estab_id             = intval($_POST['estabelecimento_id']);
        $asaas_api_key        = sanitize($_POST['asaas_api_key']);
        $asaas_webhook_token  = sanitize($_POST['asaas_webhook_token'] ?? '');
        $ambiente             = sanitize($_POST['ambiente']);
        $ativo                = isset($_POST['ativo']) ? 1 : 0;

        $prefixo = ($ambiente === 'production') ? '$aact_prod_' : '$aact_hmlg_';
        if (strpos($asaas_api_key, $prefixo) !== 0) {
            $error = "ATENÇÃO: A API Key não parece ser do ambiente {$ambiente}. Chaves de produção começam com \$aact_prod_ e de sandbox com \$aact_hmlg_";
        } else {
            try {
                $conn->prepare("
                    INSERT INTO asaas_config (estabelecimento_id, asaas_api_key, asaas_webhook_token, ambiente, ativo)
                    VALUES (:e, :k, :w, :a, :at)
                    ON DUPLICATE KEY UPDATE asaas_api_key=VALUES(asaas_api_key), asaas_webhook_token=VALUES(asaas_webhook_token), ambiente=VALUES(ambiente), ativo=VALUES(ativo)
                ")->execute([':e'=>$estab_id,':k'=>$asaas_api_key,':w'=>$asaas_webhook_token,':a'=>$ambiente,':at'=>$ativo]);
                $success = 'Configuração do Asaas salva com sucesso!';
            } catch (\Exception $e) { $error = 'Erro Asaas: ' . $e->getMessage(); }
        }
    }
    if ($action === 'delete') {
        try {
            $conn->prepare("DELETE FROM asaas_config WHERE id=?")->execute([intval($_POST['id'])]);
            $success = 'Configuração Asaas removida!';
        } catch (\Exception $e) { $error = $e->getMessage(); }
    }
    $aba = 'asaas';
}

// ── Buscar dados de cada gateway ──────────────────────────────────────────────
try {
    $stripe_configs = $conn->query("SELECT sc.*, e.name AS estab_nome FROM stripe_config sc INNER JOIN estabelecimentos e ON sc.estabelecimento_id=e.id ORDER BY e.name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) { $stripe_configs = []; }

try {
    $cora_configs = $conn->query("SELECT c.*, e.name AS estab_nome FROM cora_config c LEFT JOIN estabelecimentos e ON c.estabelecimento_id=e.id ORDER BY e.name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) { $cora_configs = []; }

try {
    $mp_configs = $conn->query("SELECT mc.*, e.name AS estab_nome FROM mercadopago_config mc INNER JOIN estabelecimentos e ON mc.estabelecimento_id=e.id ORDER BY e.name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) { $mp_configs = []; }

try {
    $asaas_configs = $conn->query("SELECT ac.*, e.name AS estab_nome FROM asaas_config ac LEFT JOIN estabelecimentos e ON ac.estabelecimento_id=e.id ORDER BY e.name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) { $asaas_configs = []; }

// ── Contar ativos por gateway ─────────────────────────────────────────────────
$stripe_ativos = count(array_filter($stripe_configs, fn($c) => $c['ativo'] ?? 0));
$cora_ativos   = count(array_filter($cora_configs,   fn($c) => $c['ativo'] ?? 0));
$mp_ativos     = count(array_filter($mp_configs,     fn($c) => $c['status'] ?? 0));
$asaas_ativos  = count(array_filter($asaas_configs,  fn($c) => $c['ativo'] ?? 0));

require_once '../includes/header.php';
?>

<style>
/* ── Tabs ── */
.tabs-navigation { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; flex-wrap:wrap; }
.tab-link { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; border:none; background:transparent; color:#6b7280; font-size:14px; font-weight:500; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; border-radius:4px 4px 0 0; transition:all .2s; }
.tab-link:hover { background:#f3f4f6; color:#374151; }
.tab-link.active { color:#2563eb; border-bottom-color:#2563eb; background:#eff6ff; }
.tab-link .badge-count { background:#e5e7eb; color:#374151; font-size:10px; font-weight:700; padding:1px 7px; border-radius:9999px; }
.tab-link.active .badge-count { background:#dbeafe; color:#1d4ed8; }
.tab-link .badge-ok { background:#d1fae5; color:#065f46; font-size:10px; font-weight:700; padding:1px 7px; border-radius:9999px; }

/* ── Cards de gateway ── */
.gateway-header { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
.gateway-logo { width:44px; height:44px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
.gateway-logo.stripe { background:#635bff; color:#fff; }
.gateway-logo.cora   { background:#ff6b00; color:#fff; }
.gateway-logo.mp     { background:#009ee3; color:#fff; }
.gateway-logo.asaas  { background:#00a650; color:#fff; }
.gateway-title { font-size:18px; font-weight:700; color:#111827; margin:0; }
.gateway-subtitle { font-size:13px; color:#6b7280; margin:0; }

/* ── Tabela de configs ── */
.config-table { width:100%; border-collapse:collapse; font-size:13px; }
.config-table th { background:#f9fafb; padding:10px 14px; text-align:left; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; }
.config-table td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.config-table tr:hover td { background:#f9fafb; }
.badge-ativo   { background:#d1fae5; color:#065f46; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }
.badge-inativo { background:#fee2e2; color:#991b1b; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }
.badge-prod    { background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }
.badge-sandbox { background:#dbeafe; color:#1e40af; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }

/* ── Formulário ── */
.form-section { background:#fff; border-radius:8px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:20px; }
.form-section h4 { font-size:15px; font-weight:600; color:#374151; margin:0 0 16px; padding-bottom:10px; border-bottom:1px solid #e5e7eb; }
.form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:500; color:#374151; margin-bottom:5px; }
.form-group input, .form-group select { width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#111827; background:#fff; box-sizing:border-box; transition:border-color .2s; }
.form-group input:focus, .form-group select:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.form-group small { display:block; font-size:12px; color:#6b7280; margin-top:4px; }
.form-check { display:flex; align-items:center; gap:8px; font-size:14px; color:#374151; cursor:pointer; }
.form-check input[type=checkbox] { width:16px; height:16px; }

/* ── Empty state ── */
.empty-state { text-align:center; padding:40px 20px; color:#9ca3af; }
.empty-state i { font-size:40px; margin-bottom:12px; display:block; }
</style>

<div class="page-header">
    <h1><i class="fas fa-credit-card"></i> Meios de Pagamento</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Cards de resumo -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
    <?php
    $gws = [
        ['stripe',     'fab fa-stripe',         '#635bff', 'Stripe',       $stripe_ativos, count($stripe_configs)],
        ['cora',       'fas fa-university',      '#ff6b00', 'Banco Cora',   $cora_ativos,   count($cora_configs)],
        ['mercadopago','fab fa-cc-mastercard',   '#009ee3', 'Mercado Pago', $mp_ativos,     count($mp_configs)],
        ['asaas',      'fas fa-dollar-sign',     '#00a650', 'Asaas',        $asaas_ativos,  count($asaas_configs)],
    ];
    foreach ($gws as [$k, $ico, $cor, $nome, $ativos, $total]):
    ?>
    <a href="?aba=<?= $k ?>" style="text-decoration:none;">
        <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-left:4px solid <?= $cor ?>;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.12)'" onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,.08)'">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <i class="<?= $ico ?>" style="color:<?= $cor ?>;font-size:20px;"></i>
                <span style="font-weight:600;color:#374151;font-size:14px;"><?= $nome ?></span>
            </div>
            <div style="font-size:22px;font-weight:700;color:<?= $ativos > 0 ? '#10b981' : '#9ca3af' ?>;"><?= $ativos ?></div>
            <div style="font-size:12px;color:#6b7280;"><?= $total ?> configuração(ões)</div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Tabs de navegação -->
<div class="tabs-navigation">
    <a href="?aba=stripe" class="tab-link <?= $aba === 'stripe' ? 'active' : '' ?>">
        <i class="fab fa-stripe"></i> Stripe
        <?php if ($stripe_ativos > 0): ?><span class="badge-ok"><?= $stripe_ativos ?> ativo</span><?php endif; ?>
    </a>
    <a href="?aba=cora" class="tab-link <?= $aba === 'cora' ? 'active' : '' ?>">
        <i class="fas fa-university"></i> Banco Cora
        <?php if ($cora_ativos > 0): ?><span class="badge-ok"><?= $cora_ativos ?> ativo</span><?php endif; ?>
    </a>
    <a href="?aba=mercadopago" class="tab-link <?= $aba === 'mercadopago' ? 'active' : '' ?>">
        <i class="fab fa-cc-mastercard"></i> Mercado Pago
        <?php if ($mp_ativos > 0): ?><span class="badge-ok"><?= $mp_ativos ?> ativo</span><?php endif; ?>
    </a>
    <a href="?aba=asaas" class="tab-link <?= $aba === 'asaas' ? 'active' : '' ?>">
        <i class="fas fa-dollar-sign"></i> Asaas
        <?php if ($asaas_ativos > 0): ?><span class="badge-ok"><?= $asaas_ativos ?> ativo</span><?php endif; ?>
    </a>
</div>

<?php // ════════════════════════════ ABA STRIPE ════════════════════════════
if ($aba === 'stripe'): ?>

<div class="form-section">
    <div class="gateway-header">
        <div class="gateway-logo stripe"><i class="fab fa-stripe"></i></div>
        <div>
            <p class="gateway-title">Stripe Pagamentos</p>
            <p class="gateway-subtitle">Processamento de cartão de crédito e links de pagamento para royalties</p>
        </div>
        <button class="btn btn-primary" style="margin-left:auto;" onclick="document.getElementById('formStripe').style.display=document.getElementById('formStripe').style.display==='none'?'block':'none'">
            <i class="fas fa-plus"></i> Nova Configuração
        </button>
    </div>

    <!-- Formulário Stripe -->
    <div id="formStripe" style="display:none;background:#f9fafb;border-radius:8px;padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;">
        <h4><i class="fas fa-plus-circle"></i> Nova Configuração Stripe</h4>
        <form method="POST">
            <input type="hidden" name="gateway" value="stripe">
            <input type="hidden" name="action" value="save">
            <div class="form-row">
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Modo *</label>
                    <select name="modo">
                        <option value="test">Teste</option>
                        <option value="live">Produção</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Chave Pública (pk_...) *</label>
                    <input type="text" name="stripe_public_key" placeholder="pk_test_..." required>
                </div>
                <div class="form-group">
                    <label>Chave Secreta (sk_...) *</label>
                    <input type="password" name="stripe_secret_key" placeholder="sk_test_..." required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Webhook Secret (whsec_...)</label>
                    <input type="text" name="stripe_webhook_secret" placeholder="whsec_...">
                    <small>Obtenha no painel Stripe → Webhooks</small>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px;">
                    <label class="form-check">
                        <input type="checkbox" name="ativo" value="1" checked> Ativo
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formStripe').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>

    <!-- Listagem Stripe -->
    <?php if (empty($stripe_configs)): ?>
        <div class="empty-state"><i class="fab fa-stripe"></i>Nenhuma configuração Stripe cadastrada.</div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="config-table">
                <thead>
                    <tr><th>Estabelecimento</th><th>Chave Pública</th><th>Modo</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stripe_configs as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['estab_nome']) ?></strong></td>
                        <td><code style="font-size:11px;"><?= htmlspecialchars(substr($c['stripe_public_key'], 0, 20)) ?>...</code></td>
                        <td><span class="<?= $c['modo'] === 'live' ? 'badge-prod' : 'badge-sandbox' ?>"><?= $c['modo'] === 'live' ? 'Produção' : 'Teste' ?></span></td>
                        <td><span class="<?= $c['ativo'] ? 'badge-ativo' : 'badge-inativo' ?>"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editarStripe(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover configuração Stripe?')">
                                <input type="hidden" name="gateway" value="stripe">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function editarStripe(c) {
    const f = document.getElementById('formStripe');
    f.style.display = 'block';
    f.querySelector('[name=estabelecimento_id]').value = c.estabelecimento_id;
    f.querySelector('[name=modo]').value = c.modo;
    f.querySelector('[name=stripe_public_key]').value = c.stripe_public_key;
    f.querySelector('[name=stripe_secret_key]').value = '';
    f.querySelector('[name=stripe_webhook_secret]').value = c.stripe_webhook_secret || '';
    f.querySelector('[name=ativo]').checked = c.ativo == 1;
    f.querySelector('h4').textContent = '✏️ Editar Configuração Stripe';
    f.scrollIntoView({behavior:'smooth'});
}
</script>

<?php // ════════════════════════════ ABA CORA ════════════════════════════
elseif ($aba === 'cora'): ?>

<div class="form-section">
    <div class="gateway-header">
        <div class="gateway-logo cora"><i class="fas fa-university"></i></div>
        <div>
            <p class="gateway-title">Banco Cora</p>
            <p class="gateway-subtitle">Emissão de boletos e PIX para cobrança de royalties</p>
        </div>
        <button class="btn btn-primary" style="margin-left:auto;" onclick="document.getElementById('formCora').style.display=document.getElementById('formCora').style.display==='none'?'block':'none'">
            <i class="fas fa-plus"></i> Nova Configuração
        </button>
    </div>

    <div id="formCora" style="display:none;background:#f9fafb;border-radius:8px;padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;">
        <h4><i class="fas fa-plus-circle"></i> Nova Configuração Banco Cora</h4>
        <form method="POST">
            <input type="hidden" name="gateway" value="cora">
            <input type="hidden" name="action" value="save">
            <div class="form-row">
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ambiente *</label>
                    <select name="ambiente">
                        <option value="stage">Sandbox (Testes)</option>
                        <option value="production">Produção</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Client ID *</label>
                    <input type="text" name="client_id" placeholder="Client ID da API Cora" required>
                </div>
                <div class="form-group">
                    <label>Client Secret *</label>
                    <input type="password" name="client_secret" placeholder="Client Secret da API Cora" required>
                    <small>Obtenha em: painel Cora → Configurações → API</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px;">
                    <label class="form-check"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formCora').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>

    <?php if (empty($cora_configs)): ?>
        <div class="empty-state"><i class="fas fa-university"></i>Nenhuma configuração Banco Cora cadastrada.</div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="config-table">
                <thead>
                    <tr><th>Estabelecimento</th><th>Client ID</th><th>Ambiente</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cora_configs as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['estab_nome'] ?? '—') ?></strong></td>
                        <td><code style="font-size:11px;"><?= htmlspecialchars(substr($c['client_id'], 0, 20)) ?>...</code></td>
                        <td><span class="<?= ($c['environment'] ?? '') === 'production' ? 'badge-prod' : 'badge-sandbox' ?>"><?= ($c['environment'] ?? 'stage') === 'production' ? 'Produção' : 'Sandbox' ?></span></td>
                        <td><span class="<?= $c['ativo'] ? 'badge-ativo' : 'badge-inativo' ?>"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editarCora(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover configuração Cora?')">
                                <input type="hidden" name="gateway" value="cora">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function editarCora(c) {
    const f = document.getElementById('formCora');
    f.style.display = 'block';
    f.querySelector('[name=estabelecimento_id]').value = c.estabelecimento_id;
    f.querySelector('[name=ambiente]').value = c.environment || 'stage';
    f.querySelector('[name=client_id]').value = c.client_id;
    f.querySelector('[name=client_secret]').value = '';
    f.querySelector('[name=ativo]').checked = c.ativo == 1;
    f.querySelector('h4').textContent = '✏️ Editar Configuração Banco Cora';
    f.scrollIntoView({behavior:'smooth'});
}
</script>

<?php // ════════════════════════════ ABA MERCADO PAGO ════════════════════════════
elseif ($aba === 'mercadopago'): ?>

<div class="form-section">
    <div class="gateway-header">
        <div class="gateway-logo mp"><i class="fab fa-cc-mastercard"></i></div>
        <div>
            <p class="gateway-title">Mercado Pago</p>
            <p class="gateway-subtitle">PIX, cartão e pagamentos via app para as TAPs</p>
        </div>
        <button class="btn btn-primary" style="margin-left:auto;" onclick="document.getElementById('formMP').style.display=document.getElementById('formMP').style.display==='none'?'block':'none'">
            <i class="fas fa-plus"></i> Nova Configuração
        </button>
    </div>

    <div id="formMP" style="display:none;background:#f9fafb;border-radius:8px;padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;">
        <h4><i class="fas fa-plus-circle"></i> Nova Configuração Mercado Pago</h4>
        <form method="POST">
            <input type="hidden" name="gateway" value="mercadopago">
            <input type="hidden" name="action" value="save">
            <div class="form-row">
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ambiente *</label>
                    <select name="ambiente">
                        <option value="sandbox">Sandbox (Teste)</option>
                        <option value="production">Produção</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Access Token *</label>
                    <input type="text" name="access_token" placeholder="APP_USR-..." required>
                    <small>Token de acesso fornecido pelo Mercado Pago</small>
                </div>
                <div class="form-group">
                    <label>Public Key</label>
                    <input type="text" name="public_key" placeholder="APP_USR-...">
                    <small>Chave pública (opcional)</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Webhook URL</label>
                    <input type="url" name="webhook_url" placeholder="https://seusite.com/api/webhook_mercadopago.php">
                </div>
                <div class="form-group">
                    <label>Webhook Secret</label>
                    <input type="text" name="webhook_secret" placeholder="Secret para validar webhooks">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px;">
                    <label class="form-check"><input type="checkbox" name="status" value="1" checked> Ativo</label>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formMP').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>

    <?php if (empty($mp_configs)): ?>
        <div class="empty-state"><i class="fab fa-cc-mastercard"></i>Nenhuma configuração Mercado Pago cadastrada.</div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="config-table">
                <thead>
                    <tr><th>Estabelecimento</th><th>Access Token</th><th>Ambiente</th><th>Webhook</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($mp_configs as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['estab_nome']) ?></strong></td>
                        <td><code style="font-size:11px;"><?= htmlspecialchars(substr($c['access_token'], 0, 18)) ?>...</code></td>
                        <td><span class="<?= $c['ambiente'] === 'production' ? 'badge-prod' : 'badge-sandbox' ?>"><?= $c['ambiente'] === 'production' ? 'Produção' : 'Sandbox' ?></span></td>
                        <td style="font-size:11px;color:#6b7280;"><?= !empty($c['webhook_url']) ? '✅ Configurado' : '—' ?></td>
                        <td><span class="<?= $c['status'] ? 'badge-ativo' : 'badge-inativo' ?>"><?= $c['status'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editarMP(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover configuração Mercado Pago?')">
                                <input type="hidden" name="gateway" value="mercadopago">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function editarMP(c) {
    const f = document.getElementById('formMP');
    f.style.display = 'block';
    f.querySelector('[name=estabelecimento_id]').value = c.estabelecimento_id;
    f.querySelector('[name=ambiente]').value = c.ambiente;
    f.querySelector('[name=access_token]').value = c.access_token;
    f.querySelector('[name=public_key]').value = c.public_key || '';
    f.querySelector('[name=webhook_url]').value = c.webhook_url || '';
    f.querySelector('[name=webhook_secret]').value = c.webhook_secret || '';
    f.querySelector('[name=status]').checked = c.status == 1;
    f.querySelector('h4').textContent = '✏️ Editar Configuração Mercado Pago';
    f.scrollIntoView({behavior:'smooth'});
}
</script>

<?php // ════════════════════════════ ABA ASAAS ════════════════════════════
elseif ($aba === 'asaas'): ?>

<div class="form-section">
    <div class="gateway-header">
        <div class="gateway-logo asaas"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <p class="gateway-title">Asaas</p>
            <p class="gateway-subtitle">Cobrança via boleto, PIX e cartão para franqueados</p>
        </div>
        <button class="btn btn-primary" style="margin-left:auto;" onclick="document.getElementById('formAsaas').style.display=document.getElementById('formAsaas').style.display==='none'?'block':'none'">
            <i class="fas fa-plus"></i> Nova Configuração
        </button>
    </div>

    <div id="formAsaas" style="display:none;background:#f9fafb;border-radius:8px;padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;">
        <h4><i class="fas fa-plus-circle"></i> Nova Configuração Asaas</h4>
        <form method="POST">
            <input type="hidden" name="gateway" value="asaas">
            <input type="hidden" name="action" value="save">
            <div class="form-row">
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ambiente *</label>
                    <select name="ambiente" id="asaasAmbiente" onchange="atualizarPlaceholderAsaas()">
                        <option value="sandbox">Sandbox (Testes)</option>
                        <option value="production">Produção</option>
                    </select>
                    <small><strong>Sandbox:</strong> chave começa com $aact_hmlg_ | <strong>Produção:</strong> $aact_prod_</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>API Key *</label>
                    <input type="text" name="asaas_api_key" id="asaasApiKey" placeholder="$aact_hmlg_..." required>
                    <small>Obtenha em: Asaas → Minha Conta → Integrações</small>
                </div>
                <div class="form-group">
                    <label>Webhook Token (Opcional)</label>
                    <input type="text" name="asaas_webhook_token" placeholder="Token para autenticação de webhooks">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px;">
                    <label class="form-check"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAsaas').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>

    <?php if (empty($asaas_configs)): ?>
        <div class="empty-state"><i class="fas fa-dollar-sign"></i>Nenhuma configuração Asaas cadastrada.</div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="config-table">
                <thead>
                    <tr><th>Estabelecimento</th><th>API Key</th><th>Ambiente</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($asaas_configs as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['estab_nome'] ?? '—') ?></strong></td>
                        <td><code style="font-size:11px;"><?= htmlspecialchars(substr($c['asaas_api_key'], 0, 18)) ?>...</code></td>
                        <td><span class="<?= $c['ambiente'] === 'production' ? 'badge-prod' : 'badge-sandbox' ?>"><?= $c['ambiente'] === 'production' ? 'Produção' : 'Sandbox' ?></span></td>
                        <td><span class="<?= $c['ativo'] ? 'badge-ativo' : 'badge-inativo' ?>"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editarAsaas(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover configuração Asaas?')">
                                <input type="hidden" name="gateway" value="asaas">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function editarAsaas(c) {
    const f = document.getElementById('formAsaas');
    f.style.display = 'block';
    f.querySelector('[name=estabelecimento_id]').value = c.estabelecimento_id;
    f.querySelector('[name=ambiente]').value = c.ambiente;
    f.querySelector('[name=asaas_api_key]').value = c.asaas_api_key;
    f.querySelector('[name=asaas_webhook_token]').value = c.asaas_webhook_token || '';
    f.querySelector('[name=ativo]').checked = c.ativo == 1;
    f.querySelector('h4').textContent = '✏️ Editar Configuração Asaas';
    f.scrollIntoView({behavior:'smooth'});
}
function atualizarPlaceholderAsaas() {
    const amb = document.getElementById('asaasAmbiente').value;
    document.getElementById('asaasApiKey').placeholder = amb === 'production' ? '$aact_prod_...' : '$aact_hmlg_...';
}
</script>

<?php endif; // fim das abas ?>

<?php require_once '../includes/footer.php'; ?>
