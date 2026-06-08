<?php
/**
 * oauth2_callback.php
 *
 * Endpoint dedicado para receber o callback do Google OAuth2.
 *
 * Por que arquivo separado?
 * O HostGator compartilhado usa Mod_Security com regras que bloqueiam
 * parâmetros como code=4/0Adk..., scope=https://... e iss=https://...
 * quando chegam em páginas PHP genéricas. Este arquivo tem um .htaccess
 * próprio desabilitando as regras específicas do WAF para este path.
 *
 * URL de redirecionamento a cadastrar no Google Cloud Console:
 *   https://ochoppoficial.com.br/admin/oauth2_callback.php
 */

// Iniciar sessão antes de qualquer saída
session_start();

// Carregar dependências mínimas
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EmailManager.php';

// ── Verificar autenticação ───────────────────────────────────────────────────
// O callback pode chegar sem sessão ativa (nova aba). Verificamos o state.
$state_recebido  = $_GET['state']  ?? '';
$state_esperado  = $_SESSION['oauth2_state'] ?? '';

// Parâmetros do Google
$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

// ── Erro retornado pelo Google ───────────────────────────────────────────────
if (!empty($error)) {
    $_SESSION['email_msg']  = 'Autorização negada pelo Google: ' . htmlspecialchars($error);
    $_SESSION['email_tipo'] = 'danger';
    header('Location: email_config.php?aba=oauth2');
    exit;
}

// ── Validar state (CSRF) ─────────────────────────────────────────────────────
if (empty($state_esperado) || $state_recebido !== $state_esperado) {
    // State inválido ou sessão expirou — redirecionar para login
    header('Location: ../index.php?erro=sessao_expirada');
    exit;
}
unset($_SESSION['oauth2_state']); // Consumir o state

// ── Validar code ─────────────────────────────────────────────────────────────
if (empty($code)) {
    $_SESSION['email_msg']  = 'Código de autorização não recebido do Google.';
    $_SESSION['email_tipo'] = 'danger';
    header('Location: email_config.php?aba=oauth2');
    exit;
}

// ── Trocar code por tokens ───────────────────────────────────────────────────
try {
    $conn    = getDBConnection();
    $manager = new EmailManager($conn);

    // Usar a nova redirect_uri (este arquivo)
    $redirect_uri = 'https://ochoppoficial.com.br/admin/oauth2_callback.php';
    $resultado    = $manager->trocarCodigoPorToken($code, $redirect_uri);

    if ($resultado['success']) {
        $_SESSION['email_msg']  = 'Gmail autorizado com sucesso! OAuth2 está ativo.';
        $_SESSION['email_tipo'] = 'success';
    } else {
        $_SESSION['email_msg']  = 'Erro ao obter token: ' . ($resultado['message'] ?? 'Erro desconhecido');
        $_SESSION['email_tipo'] = 'danger';
    }
} catch (Exception $e) {
    $_SESSION['email_msg']  = 'Exceção ao processar token: ' . $e->getMessage();
    $_SESSION['email_tipo'] = 'danger';
}

header('Location: email_config.php?aba=oauth2');
exit;
