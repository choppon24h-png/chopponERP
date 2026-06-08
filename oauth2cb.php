<?php
/**
 * oauth2cb.php — Proxy OAuth2 para contornar Mod_Security
 *
 * O Mod_Security do HostGator compartilhado bloqueia URLs que contêm
 * parâmetros como scope=https://mail.google.com, iss=https://... etc.
 * Este arquivo fica na RAIZ do site (não na pasta admin/) com um nome
 * simples e sem parâmetros suspeitos no path.
 *
 * URL a cadastrar no Google Cloud Console:
 *   https://ochoppoficial.com.br/oauth2cb.php
 *
 * Fluxo:
 *   1. Google redireciona para https://ochoppoficial.com.br/oauth2cb.php?code=...&state=...
 *   2. Este arquivo processa o callback internamente (sem redirecionar)
 *   3. Troca o code pelo refresh_token via cURL (server-side, sem expor ao WAF)
 *   4. Salva o token no banco e redireciona para admin/email_config.php
 */

// Iniciar sessão antes de qualquer saída
session_start();

// Carregar dependências
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/EmailManager.php';

// ── Parâmetros recebidos do Google ────────────────────────────────────────────
$state_recebido = $_GET['state']  ?? '';
$state_esperado = $_SESSION['oauth2_state'] ?? '';
$code           = $_GET['code']   ?? '';
$error          = $_GET['error']  ?? '';

// ── Erro retornado pelo Google ────────────────────────────────────────────────
if (!empty($error)) {
    $_SESSION['email_msg']  = 'Autorização negada pelo Google: ' . htmlspecialchars($error);
    $_SESSION['email_tipo'] = 'danger';
    header('Location: admin/email_config.php?aba=oauth2');
    exit;
}

// ── Validar state (CSRF) ──────────────────────────────────────────────────────
if (empty($state_esperado) || $state_recebido !== $state_esperado) {
    // State inválido ou sessão expirou
    header('Location: index.php?erro=sessao_expirada');
    exit;
}
unset($_SESSION['oauth2_state']); // Consumir o state

// ── Validar code ──────────────────────────────────────────────────────────────
if (empty($code)) {
    $_SESSION['email_msg']  = 'Código de autorização não recebido do Google.';
    $_SESSION['email_tipo'] = 'danger';
    header('Location: admin/email_config.php?aba=oauth2');
    exit;
}

// ── Trocar code por tokens ────────────────────────────────────────────────────
try {
    $conn    = getDBConnection();
    $manager = new EmailManager($conn);

    // redirect_uri DEVE ser idêntica à cadastrada no Google Cloud Console
    $redirect_uri = 'https://ochoppoficial.com.br/oauth2cb.php';
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

header('Location: admin/email_config.php?aba=oauth2');
exit;
