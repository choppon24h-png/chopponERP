<?php
/**
 * Configuração de E-mail — ChopponERP v2.0
 * Abas: SMTP | Gmail OAuth2 | Alertas | Teste | Logs
 */
$page_title    = 'Configuração de E-mail';
$current_page  = 'email_config';
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/EmailManager.php';
requireAuth();

if (!isAdminGeral()) {
    header('Location: ../index.php');
    exit;
}

$conn    = getDBConnection();
$em      = new EmailManager($conn);
$success = '';
$error   = '';

// ── Buscar estabelecimentos ───────────────────────────────────────────────────
$estabelecimentos = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
$estab_id = intval($_GET['estabelecimento_id'] ?? ($_POST['estabelecimento_id'] ?? ($estabelecimentos[0]['id'] ?? 0)));

// ── Processar ações POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar_smtp') {
        try {
            $em->salvarConfig($_POST);
            $success = 'Configuração SMTP salva com sucesso!';
        } catch (\Throwable $e) {
            $error = 'Erro ao salvar: ' . $e->getMessage();
        }
    }

    if ($action === 'salvar_oauth2') {
        try {
            $em->salvarConfig(array_merge($_POST, ['modo' => 'gmail_oauth2']));
            $success = 'Credenciais OAuth2 salvas! Clique em "Autorizar Gmail" para obter o Refresh Token.';
        } catch (\Throwable $e) {
            $error = 'Erro ao salvar OAuth2: ' . $e->getMessage();
        }
    }

    if ($action === 'oauth2_callback') {
        $code         = trim($_POST['oauth_code'] ?? '');
        $redirect_uri = trim($_POST['redirect_uri'] ?? '');
        if (empty($code)) {
            $error = 'Código de autorização não informado.';
        } else {
            $result = $em->trocarCodigoPorToken($code, $redirect_uri);
            $result['success'] ? $success = $result['message'] : $error = $result['message'];
        }
    }

    if ($action === 'salvar_alertas') {
        try {
            $em->salvarAlertasConfig($estab_id, $_POST);
            $success = 'Configuração de alertas salva com sucesso!';
        } catch (\Throwable $e) {
            $error = 'Erro ao salvar alertas: ' . $e->getMessage();
        }
    }

    if ($action === 'enviar_teste') {
        $email_teste = trim($_POST['email_teste'] ?? '');
        if (empty($email_teste) || !filter_var($email_teste, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido para o teste.';
        } else {
            $result = $em->enviarTeste($email_teste);
            if ($result['success']) {
                $success = '✅ E-mail de teste enviado com sucesso para <strong>' . htmlspecialchars($email_teste) . '</strong>! Verifique a caixa de entrada.';
            } else {
                $error = '❌ Falha no envio: ' . $result['message'];
            }
        }
    }

    if ($action === 'limpar_logs') {
        $dias     = intval($_POST['dias_logs'] ?? 90);
        $apagados = $em->limparLogsAntigos($dias);
        $success  = "Logs com mais de {$dias} dias removidos ({$apagados} registros).";
    }
}

// ── Carregar dados ────────────────────────────────────────────────────────────
// Carregar config por modo específico para os formulários de edição
$smtp_config_smtp   = $em->carregarConfigPorModo('smtp_password') ?? [];
$smtp_config_oauth  = $em->carregarConfigPorModo('gmail_oauth2')  ?? [];
// Config ativa (prioridade OAuth2 com token > OAuth2 sem token > SMTP)
try {
    $smtp_config = $em->carregarConfig();
} catch (\RuntimeException $e) {
    $smtp_config = [];
}

$alertas_config = $em->carregarAlertasConfig($estab_id);

$log_filtros = [
    'estab_id'    => !empty($_GET['log_estab']) ? intval($_GET['log_estab']) : null,
    'status'      => $_GET['log_status'] ?? '',
    'tipo'        => $_GET['log_tipo']   ?? '',
    'data_inicio' => $_GET['log_di']     ?? '',
    'data_fim'    => $_GET['log_df']     ?? '',
];
$logs       = $em->buscarLogs($log_filtros, 50, 0);
$total_logs = $em->contarLogs($log_filtros);

try {
    $stats_log = $conn->query("
        SELECT COUNT(*) AS total,
               SUM(status='enviado') AS enviados,
               SUM(status='erro')    AS erros,
               SUM(status='pendente') AS pendentes
        FROM email_log
    ")->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $stats_log = ['total' => 0, 'enviados' => 0, 'erros' => 0, 'pendentes' => 0];
}

$aba_ativa    = $_GET['aba'] ?? 'smtp';

// Redirect URI dedicado para evitar bloqueio do Mod_Security do HostGator
// O callback vai para oauth2_callback.php (arquivo limpo com .htaccess específico)
$redirect_uri = 'https://ochoppoficial.com.br/admin/oauth2_callback.php';
$oauth2_url   = $em->gerarUrlAutorizacao($redirect_uri);

// Mensagens de sessão vindas do oauth2_callback.php
if (!empty($_SESSION['email_msg'])) {
    if ($_SESSION['email_tipo'] === 'success') {
        $success = $_SESSION['email_msg'];
    } else {
        $error = $_SESSION['email_msg'];
    }
    unset($_SESSION['email_msg'], $_SESSION['email_tipo']);
    // Recarregar config após autorização bem-sucedida
    $smtp_config_oauth = $em->carregarConfigPorModo('gmail_oauth2') ?? [];
    try { $smtp_config = $em->carregarConfig(); } catch (\RuntimeException $e) { $smtp_config = []; }
}

require_once '../includes/header.php';
?>

<style>
.tabs-navigation { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; flex-wrap:wrap; }
.tab-link { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border:none; background:transparent; color:#6b7280; font-size:14px; font-weight:500; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; border-radius:4px 4px 0 0; transition:all .2s; }
.tab-link:hover { background:#f3f4f6; color:#374151; }
.tab-link.active { color:#2563eb; border-bottom-color:#2563eb; background:#eff6ff; }

/* stat-cards/stat-card/form-row/form-section: definidos em assets/css/style.css */
.stat-card .stat-num { font-size:28px; font-weight:700; }
.stat-card.verde .stat-num { color:#10b981; }
.stat-card.vermelho .stat-num { color:#ef4444; }
.stat-card.amarelo .stat-num { color:#f59e0b; }
.stat-card.azul .stat-num { color:#3b82f6; }

.toggle-group { display:flex; align-items:center; gap:10px; padding:12px 0; border-bottom:1px solid #f3f4f6; }
.toggle-group:last-child { border-bottom:none; }
.toggle-group label.toggle-label { font-size:14px; color:#374151; cursor:pointer; flex:1; }
.toggle { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
.toggle input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; cursor:pointer; inset:0; background:#d1d5db; border-radius:24px; transition:.3s; }
.toggle-slider:before { content:''; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
.toggle input:checked + .toggle-slider { background:#10b981; }
.toggle input:checked + .toggle-slider:before { transform:translateX(20px); }

.oauth-step { display:flex; gap:16px; align-items:flex-start; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:12px; }
.oauth-step-num { width:32px; height:32px; border-radius:50%; background:#3b82f6; color:#fff; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
.oauth-step-num.done { background:#10b981; }
.oauth-step-content h5 { margin:0 0 6px; font-size:14px; font-weight:600; color:#1e293b; }
.oauth-step-content p { margin:0; font-size:13px; color:#64748b; }
.oauth-step-content code { background:#1e293b; color:#e2e8f0; padding:2px 8px; border-radius:4px; font-size:12px; word-break:break-all; }

.log-table { width:100%; border-collapse:collapse; font-size:13px; }
.log-table th { background:#f9fafb; padding:10px 12px; text-align:left; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; }
.log-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:top; }
.log-table tr:hover td { background:#f9fafb; }
.badge { display:inline-block; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; }
.badge-enviado  { background:#d1fae5; color:#065f46; }
.badge-erro     { background:#fee2e2; color:#991b1b; }
.badge-pendente { background:#fef3c7; color:#92400e; }
.badge-ignorado { background:#f3f4f6; color:#6b7280; }
.log-erro-detail { font-size:11px; color:#ef4444; margin-top:4px; max-width:300px; word-break:break-word; }

.status-badge-ok   { display:inline-flex;align-items:center;gap:6px;background:#d1fae5;color:#065f46;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600; }
.status-badge-warn { display:inline-flex;align-items:center;gap:6px;background:#fef3c7;color:#92400e;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600; }
.status-badge-err  { display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600; }
</style>

<div class="page-header">
    <h1><i class="fas fa-envelope"></i> Configuração de E-mail</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Cards de status -->
<div class="stat-cards">
    <div class="stat-card azul">
        <div class="stat-num"><?= number_format($stats_log['total'] ?? 0) ?></div>
        <div class="stat-label">Total de E-mails</div>
    </div>
    <div class="stat-card verde">
        <div class="stat-num"><?= number_format($stats_log['enviados'] ?? 0) ?></div>
        <div class="stat-label">Enviados</div>
    </div>
    <div class="stat-card vermelho">
        <div class="stat-num"><?= number_format($stats_log['erros'] ?? 0) ?></div>
        <div class="stat-label">Com Erro</div>
    </div>
    <div class="stat-card amarelo">
        <div class="stat-num"><?= number_format($stats_log['pendentes'] ?? 0) ?></div>
        <div class="stat-label">Pendentes</div>
    </div>
    <div class="stat-card">
        <div class="stat-num" style="font-size:14px;color:<?= !empty($smtp_config) ? '#10b981' : '#ef4444' ?>;">
            <?= !empty($smtp_config) ? '✅ Ativo' : '❌ Não config.' ?>
        </div>
        <div class="stat-label">Servidor</div>
    </div>
    <div class="stat-card">
        <div class="stat-num" style="font-size:13px;color:#3b82f6;">
            <?= !empty($smtp_config['modo']) ? strtoupper(str_replace('_', ' ', $smtp_config['modo'])) : '—' ?>
        </div>
        <div class="stat-label">Modo de Envio</div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs-navigation">
    <a href="?aba=smtp&estabelecimento_id=<?= $estab_id ?>" class="tab-link <?= $aba_ativa === 'smtp' ? 'active' : '' ?>">
        <i class="fas fa-server"></i> Configuração SMTP
    </a>
    <a href="?aba=oauth2&estabelecimento_id=<?= $estab_id ?>" class="tab-link <?= $aba_ativa === 'oauth2' ? 'active' : '' ?>">
        <i class="fab fa-google"></i> Gmail OAuth2
        <?php if (!empty($smtp_config['oauth_refresh_token'])): ?>
            <span class="badge" style="background:#d1fae5;color:#065f46;font-size:10px;">✓</span>
        <?php endif; ?>
    </a>
    <a href="?aba=alertas&estabelecimento_id=<?= $estab_id ?>" class="tab-link <?= $aba_ativa === 'alertas' ? 'active' : '' ?>">
        <i class="fas fa-bell"></i> Alertas
    </a>
    <a href="?aba=teste&estabelecimento_id=<?= $estab_id ?>" class="tab-link <?= $aba_ativa === 'teste' ? 'active' : '' ?>">
        <i class="fas fa-paper-plane"></i> Teste de Envio
    </a>
    <a href="?aba=logs&estabelecimento_id=<?= $estab_id ?>" class="tab-link <?= $aba_ativa === 'logs' ? 'active' : '' ?>">
        <i class="fas fa-list-alt"></i> Logs
        <?php if (($stats_log['erros'] ?? 0) > 0): ?>
            <span class="badge badge-erro"><?= $stats_log['erros'] ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($aba_ativa === 'smtp'): ?>
<!-- ══ ABA SMTP ══════════════════════════════════════════════════════════════ -->
<form method="POST">
    <input type="hidden" name="action" value="salvar_smtp">
    <input type="hidden" name="modo" value="smtp_password">
    <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">

    <div class="form-section">
        <h4><i class="fas fa-server"></i> Servidor SMTP</h4>
        <div class="form-row">
            <div class="form-group">
                <label>Host SMTP *</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtp_config_smtp['smtp_host'] ?? 'smtp.gmail.com') ?>" placeholder="smtp.gmail.com" required>
                <small>Gmail: smtp.gmail.com | Outlook: smtp.office365.com</small>
            </div>
            <div class="form-group">
                <label>Porta *</label>
                <select name="smtp_port">
                    <option value="587" <?= ($smtp_config_smtp['smtp_port'] ?? 587) == 587 ? 'selected' : '' ?>>587 — TLS (recomendado)</option>
                    <option value="465" <?= ($smtp_config_smtp['smtp_port'] ?? '') == 465 ? 'selected' : '' ?>>465 — SSL</option>
                    <option value="25"  <?= ($smtp_config_smtp['smtp_port'] ?? '') == 25  ? 'selected' : '' ?>>25 — Sem criptografia</option>
                </select>
            </div>
            <div class="form-group">
                <label>Criptografia</label>
                <select name="smtp_secure">
                    <option value="tls"  <?= ($smtp_config_smtp['smtp_secure'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                    <option value="ssl"  <?= ($smtp_config_smtp['smtp_secure'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= ($smtp_config_smtp['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>Nenhuma</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Usuário SMTP (e-mail) *</label>
                <input type="email" name="smtp_username" value="<?= htmlspecialchars($smtp_config_smtp['smtp_username'] ?? '') ?>" placeholder="seuemail@gmail.com" required>
            </div>
            <div class="form-group">
                <label>Senha SMTP / App Password</label>
                <input type="password" name="smtp_password" placeholder="<?= !empty($smtp_config_smtp['smtp_password']) ? '●●●●●● (deixe em branco para manter)' : 'App Password do Gmail' ?>">
                <small>Para Gmail: gere uma <strong>Senha de App</strong> em <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a></small>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h4><i class="fas fa-user"></i> Remetente</h4>
        <div class="form-row">
            <div class="form-group">
                <label>Nome do Remetente *</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($smtp_config_smtp['from_name'] ?? 'Chopp ON') ?>" required>
            </div>
            <div class="form-group">
                <label>E-mail do Remetente *</label>
                <input type="email" name="from_email" value="<?= htmlspecialchars($smtp_config_smtp['from_email'] ?? '') ?>" required>
            </div>
        </div>
    </div>

    <div class="form-section" style="background:#fffbeb;border:1px solid #fde68a;">
        <h4 style="color:#92400e;"><i class="fas fa-info-circle"></i> Por que o Gmail bloqueia o envio?</h4>
        <p style="font-size:13px;color:#78350f;margin:0;">
            O Gmail desativou o acesso por senha comum em 2022. Use uma <strong>Senha de App</strong> (não a senha da conta) ou,
            preferencialmente, use a aba <strong>Gmail OAuth2</strong> para autenticação por token — mais seguro e sem senha armazenada.
        </p>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configuração SMTP</button>
</form>

<?php elseif ($aba_ativa === 'oauth2'): ?>
<!-- ══ ABA OAUTH2 ═════════════════════════════════════════════════════════════ -->
<div class="form-section">
    <h4><i class="fab fa-google"></i> Autenticação Gmail via OAuth2 (Recomendado)</h4>
    <p style="font-size:13px;color:#6b7280;margin-bottom:20px;">
        O OAuth2 permite enviar e-mails pelo Gmail <strong>sem armazenar sua senha</strong>.
        Você autoriza o acesso uma única vez e o sistema usa um token renovável automaticamente.
    </p>

    <div style="margin-bottom:20px;">
        <?php if (!empty($smtp_config_oauth['oauth_refresh_token'])): ?>
            <span class="status-badge-ok"><i class="fas fa-check-circle"></i> Gmail OAuth2 configurado e ativo</span>
            <span style="font-size:12px;color:#6b7280;margin-left:10px;">Conta: <?= htmlspecialchars($smtp_config_oauth['oauth_email'] ?? '—') ?></span>
        <?php elseif (!empty($smtp_config_oauth['oauth_client_id'])): ?>
            <span class="status-badge-warn"><i class="fas fa-exclamation-triangle"></i> Credenciais salvas — aguardando autorização</span>
        <?php else: ?>
            <span class="status-badge-err"><i class="fas fa-times-circle"></i> OAuth2 não configurado</span>
        <?php endif; ?>
    </div>

    <div class="oauth-step">
        <div class="oauth-step-num <?= !empty($smtp_config_oauth['oauth_client_id']) ? 'done' : '' ?>">1</div>
        <div class="oauth-step-content">
            <h5>Criar projeto no Google Cloud Console</h5>
            <p>Acesse <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a>,
            crie um projeto, ative a <strong>Gmail API</strong> e crie credenciais OAuth2 do tipo <strong>"Aplicativo da Web"</strong>.
            Adicione como URI de redirecionamento autorizado:<br>
            <code><?= htmlspecialchars($redirect_uri) ?></code></p>
        </div>
    </div>

    <div class="oauth-step">
        <div class="oauth-step-num <?= !empty($smtp_config_oauth['oauth_client_id']) ? 'done' : '' ?>">2</div>
        <div class="oauth-step-content">
            <h5>Informar Client ID e Client Secret</h5>
        </div>
    </div>

    <form method="POST" style="margin:0 0 20px 48px;">
        <input type="hidden" name="action" value="salvar_oauth2">
        <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Client ID *</label>
                <input type="text" name="oauth_client_id" value="<?= htmlspecialchars($smtp_config_oauth['oauth_client_id'] ?? '') ?>" placeholder="xxxxxxxx.apps.googleusercontent.com" required>
                <small>Formato: <code>XXXXXXXXXX.apps.googleusercontent.com</code></small>
            </div>
            <div class="form-group">
                <label>Client Secret *</label>
                <input type="password" name="oauth_client_secret" placeholder="<?= !empty($smtp_config_oauth['oauth_client_secret']) ? '●●●●●● (salvo — deixe em branco para manter)' : 'GOCSPX-...' ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>E-mail Gmail autorizado *</label>
                <input type="email" name="oauth_email" value="<?= htmlspecialchars($smtp_config_oauth['oauth_email'] ?? '') ?>" placeholder="seuemail@gmail.com" required>
                <small>Deve ser a mesma conta Gmail que você vai autorizar no passo 3.</small>
            </div>
            <div class="form-group">
                <label>Nome do Remetente</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($smtp_config_oauth['from_name'] ?? 'Chopp ON') ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Credenciais</button>
    </form>

    <div class="oauth-step">
        <div class="oauth-step-num <?= !empty($smtp_config_oauth['oauth_refresh_token']) ? 'done' : '' ?>">3</div>
        <div class="oauth-step-content">
            <h5>Autorizar acesso ao Gmail</h5>
            <p>Clique no botão abaixo para ser redirecionado ao Google e autorizar o acesso. O token será salvo automaticamente.</p>
            <?php if (!empty($smtp_config_oauth['oauth_client_id'])): ?>
                <a href="<?= htmlspecialchars($oauth2_url) ?>" class="btn btn-danger" style="margin-top:10px;">
                    <i class="fab fa-google"></i> Autorizar Gmail
                </a>
                <small style="display:block;margin-top:8px;color:#6b7280;">Você será redirecionado para o Google. Após autorizar, o Refresh Token será salvo automaticamente.</small>
            <?php else: ?>
                <button class="btn btn-secondary" disabled>Salve as credenciais primeiro</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="oauth-step">
        <div class="oauth-step-num <?= !empty($smtp_config_oauth['oauth_refresh_token']) ? 'done' : '' ?>">4</div>
        <div class="oauth-step-content">
            <h5>Ou cole o código de autorização manualmente</h5>
            <p>Se o redirecionamento automático não funcionar, copie o valor do parâmetro <code>code=</code> da URL de retorno e cole abaixo.</p>
        </div>
    </div>

    <form method="POST" style="margin:0 0 0 48px;">
        <input type="hidden" name="action" value="oauth2_callback">
        <input type="hidden" name="redirect_uri" value="<?= htmlspecialchars($redirect_uri) ?>">
        <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">
        <div class="form-row">
            <div class="form-group" style="grid-column:1/-1;">
                <label>Código de Autorização (code=...)</label>
                <input type="text" name="oauth_code" placeholder="4/0AX4XfWh...">
            </div>
        </div>
        <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Trocar Código por Token</button>
    </form>
</div>

<?php elseif ($aba_ativa === 'alertas'): ?>
<!-- ══ ABA ALERTAS ════════════════════════════════════════════════════════════ -->
<div class="form-section" style="padding:16px;">
    <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <input type="hidden" name="aba" value="alertas">
        <label style="font-size:13px;font-weight:600;color:#374151;">Estabelecimento:</label>
        <select name="estabelecimento_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($estabelecimentos as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $e['id'] == $estab_id ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<form method="POST">
    <input type="hidden" name="action" value="salvar_alertas">
    <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">

    <div class="form-section">
        <h4><i class="fas fa-at"></i> Destinatários</h4>
        <div class="form-row">
            <div class="form-group">
                <label>E-mail Principal *</label>
                <input type="email" name="email_principal" value="<?= htmlspecialchars($alertas_config['email_principal'] ?? '') ?>" placeholder="gestor@empresa.com" required>
                <small>Receberá todos os alertas ativos</small>
            </div>
            <div class="form-group">
                <label>E-mails em Cópia (CC)</label>
                <input type="text" name="email_copia" value="<?= htmlspecialchars($alertas_config['email_copia'] ?? '') ?>" placeholder="outro@empresa.com, financeiro@empresa.com">
                <small>Separe múltiplos e-mails por vírgula</small>
            </div>
        </div>
    </div>

    <?php
    $alertas_def = [
        ['key' => 'nova_venda',     'icon' => 'fa-shopping-cart',        'titulo' => 'Alertas de Vendas',              'label' => 'Notificar nova venda realizada',                  'vars' => '{estabelecimento}, {valor}, {metodo}, {data}'],
        ['key' => 'volume_critico', 'icon' => 'fa-tint',                 'titulo' => 'Alertas de Volume Crítico',      'label' => 'Notificar quando o barril atingir volume crítico', 'vars' => '{tap_id}, {estabelecimento}, {volume_restante}, {percentual}'],
        ['key' => 'contas_pagar',   'icon' => 'fa-file-invoice-dollar',  'titulo' => 'Alertas de Contas a Pagar',      'label' => 'Notificar contas próximas do vencimento',         'vars' => '{estabelecimento}, {total_contas}, {valor_total}, {data}', 'extra' => 'contas'],
        ['key' => 'estoque_minimo', 'icon' => 'fa-boxes',                'titulo' => 'Alertas de Estoque Mínimo',      'label' => 'Notificar quando produto atingir estoque mínimo', 'vars' => '{produto}, {estabelecimento}, {quantidade_atual}, {quantidade_minima}'],
        ['key' => 'royalties',      'icon' => 'fa-percentage',           'titulo' => 'Alertas de Royalties',           'label' => 'Notificar royalties vencendo',                    'vars' => '{estabelecimento}, {valor}, {data_vencimento}'],
        ['key' => 'tap_offline',    'icon' => 'fa-wifi',                 'titulo' => 'Alertas de TAP Offline',         'label' => 'Notificar quando uma TAP ficar offline',          'vars' => '{tap_id}, {estabelecimento}, {ultima_comunicacao}'],
    ];
    foreach ($alertas_def as $al):
        $k = $al['key'];
    ?>
    <div class="form-section">
        <h4><i class="fas <?= $al['icon'] ?>"></i> <?= $al['titulo'] ?></h4>
        <div class="toggle-group">
            <label class="toggle">
                <input type="checkbox" name="alerta_<?= $k ?>" value="1" <?= !empty($alertas_config["alerta_{$k}"]) ? 'checked' : '' ?> id="tog_<?= $k ?>">
                <span class="toggle-slider"></span>
            </label>
            <label class="toggle-label" for="tog_<?= $k ?>"><?= $al['label'] ?></label>
        </div>
        <?php if (!empty($al['extra']) && $al['extra'] === 'contas'): ?>
        <div class="form-row" style="margin-top:12px;">
            <div class="form-group">
                <label>Alertar com quantos dias de antecedência?</label>
                <input type="number" name="dias_antes_contas" value="<?= intval($alertas_config['dias_antes_contas'] ?? 3) ?>" min="1" max="30">
            </div>
            <div class="form-group">
                <label>Alertar por quantos dias após o vencimento?</label>
                <input type="number" name="dias_apos_contas" value="<?= intval($alertas_config['dias_apos_contas'] ?? 2) ?>" min="0" max="30">
            </div>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin-top:12px;">
            <label>Assunto do e-mail</label>
            <input type="text" name="alerta_<?= $k ?>_assunto" value="<?= htmlspecialchars($alertas_config["alerta_{$k}_assunto"] ?? '') ?>">
            <small>Variáveis disponíveis: <?= $al['vars'] ?></small>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="form-section">
        <h4><i class="fas fa-chart-bar"></i> Resumo Diário</h4>
        <div class="toggle-group">
            <label class="toggle">
                <input type="checkbox" name="resumo_diario" value="1" <?= !empty($alertas_config['resumo_diario']) ? 'checked' : '' ?> id="tog_resumo">
                <span class="toggle-slider"></span>
            </label>
            <label class="toggle-label" for="tog_resumo">Enviar resumo diário de vendas e estoque</label>
        </div>
        <div class="form-row" style="margin-top:12px;">
            <div class="form-group">
                <label>Horário de envio</label>
                <input type="time" name="resumo_horario" value="<?= $alertas_config['resumo_horario'] ?? '08:00' ?>">
            </div>
            <div class="form-group">
                <label>Assunto do e-mail</label>
                <input type="text" name="resumo_assunto" value="<?= htmlspecialchars($alertas_config['resumo_assunto'] ?? 'Resumo diário — {estabelecimento} — {data}') ?>">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configuração de Alertas</button>
</form>

<?php elseif ($aba_ativa === 'teste'): ?>
<!-- ══ ABA TESTE ════════════════════════════════════════════════════════════════════════════════════════ -->
<div class="form-section">
    <h4><i class="fas fa-paper-plane"></i> Enviar E-mail de Teste</h4>

    <?php if (empty($smtp_config)): ?>
        <div class="alert alert-warning" style="margin-bottom:20px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Nenhuma configuração de e-mail ativa.</strong>
            Configure na aba <a href="?aba=smtp&estabelecimento_id=<?= $estab_id ?>"><strong>Configuração SMTP</strong></a>
            ou <a href="?aba=oauth2&estabelecimento_id=<?= $estab_id ?>"><strong>Gmail OAuth2</strong></a> antes de testar.
        </div>
        <!-- Mesmo sem config ativa, mostrar o formulário para facilitar o diagnóstico -->
        <form method="POST" style="opacity:.6;pointer-events:none;">
            <input type="hidden" name="action" value="enviar_teste">
            <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>E-mail destinatário para o teste *</label>
                    <input type="email" name="email_teste" placeholder="seuemail@gmail.com" disabled>
                    <small>Configure o servidor de e-mail primeiro para habilitar o envio</small>
                </div>
            </div>
            <button type="button" class="btn btn-success" disabled style="font-size:15px;padding:12px 24px;">
                <i class="fas fa-paper-plane"></i> Enviar E-mail de Teste Agora
            </button>
        </form>
    <?php else: ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:14px;margin-bottom:20px;">
            <strong style="color:#166534;">Configuração ativa:</strong>
            <span style="color:#374151;font-size:13px;margin-left:8px;">
                Modo: <strong><?= strtoupper(str_replace('_', ' ', $smtp_config['modo'] ?? 'smtp_password')) ?></strong>
                <?php if (($smtp_config['modo'] ?? '') === 'gmail_oauth2'): ?>
                    | Conta Gmail: <strong><?= htmlspecialchars($smtp_config['oauth_email'] ?? $smtp_config['from_email'] ?? '—') ?></strong>
                    | Token: <strong style="color:<?= !empty($smtp_config['oauth_refresh_token']) ? '#10b981' : '#ef4444' ?>">
                        <?= !empty($smtp_config['oauth_refresh_token']) ? '✅ Configurado' : '❌ Faltando — autorize na aba OAuth2' ?>
                    </strong>
                <?php else: ?>
                    | Servidor: <strong><?= htmlspecialchars($smtp_config['smtp_host'] ?? '—') ?>:<?= $smtp_config['smtp_port'] ?? '—' ?></strong>
                    | Remetente: <strong><?= htmlspecialchars($smtp_config['from_email'] ?? '—') ?></strong>
                <?php endif; ?>
            </span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="enviar_teste">
            <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>E-mail destinatário para o teste *</label>
                    <input type="email" name="email_teste"
                           value="<?= htmlspecialchars($_POST['email_teste'] ?? '') ?>"
                           placeholder="seuemail@gmail.com" required autofocus>
                    <small>O e-mail de teste será enviado para este endereço. Verifique a caixa de entrada e a pasta de spam.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-success" style="font-size:15px;padding:12px 24px;">
                <i class="fas fa-paper-plane"></i> Enviar E-mail de Teste Agora
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="form-section">
    <h4><i class="fas fa-question-circle"></i> Diagnóstico de Problemas Comuns</h4>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:#f9fafb;">
                <th style="padding:10px;border-bottom:2px solid #e5e7eb;">Erro</th>
                <th style="padding:10px;border-bottom:2px solid #e5e7eb;">Causa Provável</th>
                <th style="padding:10px;border-bottom:2px solid #e5e7eb;">Solução</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $diags = [
                ['535 Authentication failed',  'Senha incorreta ou sem App Password',       'Gere uma Senha de App no Google ou use OAuth2'],
                ['Connection timed out',        'Porta SMTP bloqueada pelo firewall',        'Tente porta 465 com SSL ou 587 com TLS. Verifique com a hospedagem.'],
                ['PHPMailer not found',         'PHPMailer não instalado via Composer',      'O sistema usa fallback automático via cURL. Se falhar, use OAuth2.'],
                ['OAuth2: invalid_grant',       'Refresh Token expirado ou revogado',        'Reautorize o Gmail na aba OAuth2'],
                ['Corpo vazio / HTTP 200',      'Erro PHP silencioso antes do envio',        'Verifique a aba Logs para detalhes do erro'],
            ];
            foreach ($diags as $i => $d):
            ?>
            <tr <?= $i % 2 ? 'style="background:#f9fafb;"' : '' ?>>
                <td style="padding:10px;border-bottom:1px solid #f3f4f6;color:#ef4444;font-weight:600;"><?= $d[0] ?></td>
                <td style="padding:10px;border-bottom:1px solid #f3f4f6;"><?= $d[1] ?></td>
                <td style="padding:10px;border-bottom:1px solid #f3f4f6;"><?= $d[2] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($aba_ativa === 'logs'): ?>
<!-- ══ ABA LOGS ══════════════════════════════════════════════════════════════ -->
<div class="form-section" style="padding:16px;">
    <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <input type="hidden" name="aba" value="logs">
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Estabelecimento</label>
            <select name="log_estab" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <?php foreach ($estabelecimentos as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= ($log_filtros['estab_id'] ?? '') == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Status</label>
            <select name="log_status" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <option value="enviado"  <?= ($log_filtros['status'] ?? '') === 'enviado'  ? 'selected' : '' ?>>Enviado</option>
                <option value="erro"     <?= ($log_filtros['status'] ?? '') === 'erro'     ? 'selected' : '' ?>>Erro</option>
                <option value="pendente" <?= ($log_filtros['status'] ?? '') === 'pendente' ? 'selected' : '' ?>>Pendente</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Tipo</label>
            <select name="log_tipo" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <?php foreach (['nova_venda'=>'Nova Venda','volume_critico'=>'Volume Crítico','contas_pagar'=>'Contas a Pagar','estoque_minimo'=>'Estoque Mínimo','royalties'=>'Royalties','tap_offline'=>'TAP Offline','teste'=>'Teste'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($log_filtros['tipo'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">De</label>
            <input type="date" name="log_di" value="<?= htmlspecialchars($log_filtros['data_inicio'] ?? '') ?>" class="form-control" style="width:auto;">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Até</label>
            <input type="date" name="log_df" value="<?= htmlspecialchars($log_filtros['data_fim'] ?? '') ?>" class="form-control" style="width:auto;">
        </div>
        <button type="submit" class="btn btn-primary" style="margin:0;"><i class="fas fa-filter"></i> Filtrar</button>
        <a href="?aba=logs" class="btn btn-secondary" style="margin:0;">Limpar</a>
    </form>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <span style="font-size:13px;color:#6b7280;"><?= number_format($total_logs) ?> registros encontrados</span>
    <form method="POST" style="display:flex;align-items:center;gap:8px;">
        <input type="hidden" name="action" value="limpar_logs">
        <input type="hidden" name="estabelecimento_id" value="<?= $estab_id ?>">
        <select name="dias_logs" class="form-control" style="width:auto;padding:6px 10px;">
            <option value="30">Mais de 30 dias</option>
            <option value="60">Mais de 60 dias</option>
            <option value="90" selected>Mais de 90 dias</option>
            <option value="180">Mais de 180 dias</option>
        </select>
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remover logs antigos?')">
            <i class="fas fa-trash"></i> Limpar Logs
        </button>
    </form>
</div>

<div class="form-section" style="padding:0;overflow:hidden;">
    <?php if (empty($logs)): ?>
        <div style="padding:40px;text-align:center;color:#9ca3af;">
            <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;"></i>
            Nenhum log encontrado com os filtros selecionados.
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data/Hora</th>
                        <th>Status</th>
                        <th>Tipo</th>
                        <th>Destinatário</th>
                        <th>Assunto</th>
                        <th>Modo</th>
                        <th>Detalhe do Erro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $tipo_icons = ['nova_venda'=>'🛒','volume_critico'=>'🍺','contas_pagar'=>'💰','estoque_minimo'=>'📦','royalties'=>'📊','tap_offline'=>'📡','resumo_diario'=>'📋','teste'=>'🧪','outro'=>'📧'];
                    foreach ($logs as $log):
                    ?>
                    <tr>
                        <td style="color:#9ca3af;"><?= $log['id'] ?></td>
                        <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($log['enviado_em'] ?: $log['created_at'])) ?></td>
                        <td><span class="badge badge-<?= $log['status'] ?>"><?= ucfirst($log['status']) ?></span></td>
                        <td style="white-space:nowrap;"><?= ($tipo_icons[$log['tipo']] ?? '📧') . ' ' . ucfirst(str_replace('_', ' ', $log['tipo'])) ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['destinatario']) ?>"><?= htmlspecialchars($log['destinatario']) ?></td>
                        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['assunto']) ?>"><?= htmlspecialchars($log['assunto']) ?></td>
                        <td style="white-space:nowrap;font-size:11px;color:#6b7280;"><?= strtoupper(str_replace('_', ' ', $log['modo_envio'] ?? '—')) ?></td>
                        <td>
                            <?php if ($log['status'] === 'erro' && !empty($log['erro_detalhe'])): ?>
                                <div class="log-erro-detail" title="<?= htmlspecialchars($log['erro_detalhe']) ?>">
                                    <?= htmlspecialchars(substr($log['erro_detalhe'], 0, 100)) ?><?= strlen($log['erro_detalhe']) > 100 ? '...' : '' ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#10b981;font-size:12px;">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php endif; // fim das abas ?>

<?php require_once '../includes/footer.php'; ?>
