<?php
/**
 * EmailManager — ChopponERP v2.0
 *
 * Gerencia envio de e-mails com dois modos:
 *   1. SMTP tradicional com senha (App Password do Gmail ou qualquer SMTP)
 *   2. Gmail OAuth2 com Refresh Token (sem senha, mais seguro)
 *
 * Todas as tentativas de envio são registradas na tabela email_log.
 *
 * Uso:
 *   $em = new EmailManager($conn);
 *   $em->enviar('dest@email.com', 'Assunto', '<p>Corpo HTML</p>', 'nova_venda', 42);
 *   $em->enviarTeste('meu@email.com');
 */

class EmailManager
{
    private \PDO $conn;
    private ?array $config = null;

    // ── Constantes de tipo de alerta ──────────────────────────────────────────
    const TIPO_NOVA_VENDA      = 'nova_venda';
    const TIPO_VOLUME_CRITICO  = 'volume_critico';
    const TIPO_CONTAS_PAGAR    = 'contas_pagar';
    const TIPO_ESTOQUE_MINIMO  = 'estoque_minimo';
    const TIPO_ROYALTIES       = 'royalties';
    const TIPO_TAP_OFFLINE     = 'tap_offline';
    const TIPO_RESUMO_DIARIO   = 'resumo_diario';
    const TIPO_TESTE           = 'teste';
    const TIPO_OUTRO           = 'outro';

    public function __construct(\PDO $conn)
    {
        $this->conn = $conn;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIGURAÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Carrega a configuração ativa do banco.
     * Lança Exception se não houver configuração ativa.
     */
    public function carregarConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $stmt = $this->conn->query("SELECT * FROM smtp_config WHERE status = 1 LIMIT 1");
        $cfg  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$cfg) {
            throw new \RuntimeException('Nenhuma configuração de e-mail ativa encontrada. Configure em Configurações → E-mail.');
        }

        $this->config = $cfg;
        return $cfg;
    }

    /**
     * Salva ou atualiza a configuração SMTP/OAuth2.
     */
    public function salvarConfig(array $dados): void
    {
        $stmt = $this->conn->query("SELECT id FROM smtp_config LIMIT 1");
        $existe = $stmt->fetch();

        $campos = [
            'modo'         => $dados['modo']         ?? 'smtp_password',
            'smtp_host'    => $dados['smtp_host']    ?? 'smtp.gmail.com',
            'smtp_port'    => intval($dados['smtp_port'] ?? 587),
            'smtp_secure'  => $dados['smtp_secure']  ?? 'tls',
            'smtp_username'=> $dados['smtp_username'] ?? '',
            'from_email'   => $dados['from_email']   ?? '',
            'from_name'    => $dados['from_name']    ?? 'Chopp ON',
            'status'       => 1,
        ];

        // Senha SMTP — só atualiza se preenchida
        if (!empty($dados['smtp_password'])) {
            $campos['smtp_password'] = base64_encode($dados['smtp_password']);
        }

        // OAuth2
        if (!empty($dados['oauth_client_id'])) {
            $campos['oauth_client_id'] = $dados['oauth_client_id'];
        }
        if (!empty($dados['oauth_client_secret'])) {
            $campos['oauth_client_secret'] = base64_encode($dados['oauth_client_secret']);
        }
        if (!empty($dados['oauth_refresh_token'])) {
            $campos['oauth_refresh_token'] = base64_encode($dados['oauth_refresh_token']);
            // Limpar access token ao trocar refresh token
            $campos['oauth_access_token'] = null;
            $campos['oauth_token_expiry']  = null;
        }
        if (!empty($dados['oauth_email'])) {
            $campos['oauth_email'] = $dados['oauth_email'];
        }

        if ($existe) {
            $sets   = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($campos)));
            $values = array_values($campos);
            $values[] = $existe['id'];
            $this->conn->prepare("UPDATE smtp_config SET $sets WHERE id = ?")->execute($values);
        } else {
            $cols   = implode(', ', array_map(fn($k) => "`$k`", array_keys($campos)));
            $phs    = implode(', ', array_fill(0, count($campos), '?'));
            $this->conn->prepare("INSERT INTO smtp_config ($cols) VALUES ($phs)")->execute(array_values($campos));
        }

        $this->config = null; // Limpar cache
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Envia um e-mail e registra no log.
     *
     * @param string|array $destinatario  E-mail(s) do destinatário
     * @param string       $assunto
     * @param string       $corpo         HTML do e-mail
     * @param string       $tipo          Constante TIPO_*
     * @param int|null     $referencia_id ID do registro relacionado
     * @param int|null     $estab_id      ID do estabelecimento
     * @return array ['success' => bool, 'message' => string]
     */
    public function enviar(
        $destinatario,
        string $assunto,
        string $corpo,
        string $tipo = self::TIPO_OUTRO,
        ?int $referencia_id = null,
        ?int $estab_id = null
    ): array {
        $dest_str = is_array($destinatario) ? implode(', ', $destinatario) : $destinatario;
        $log_id   = $this->criarLogPendente($estab_id, $tipo, $referencia_id, $dest_str, $assunto, $corpo);

        try {
            $cfg = $this->carregarConfig();

            if ($cfg['modo'] === 'gmail_oauth2') {
                $result = $this->enviarViaOAuth2($cfg, $destinatario, $assunto, $corpo);
            } else {
                $result = $this->enviarViaSMTP($cfg, $destinatario, $assunto, $corpo);
            }

            if ($result['success']) {
                $this->atualizarLog($log_id, 'enviado', null, $cfg['modo']);
            } else {
                $this->atualizarLog($log_id, 'erro', $result['message'], $cfg['modo']);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->atualizarLog($log_id, 'erro', $e->getMessage(), 'desconhecido');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Envia e-mail de teste para verificar a configuração.
     */
    public function enviarTeste(string $email): array
    {
        try {
            $cfg = $this->carregarConfig();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $modo_label = $cfg['modo'] === 'gmail_oauth2' ? 'Gmail OAuth2' : 'SMTP com senha';
        $assunto    = '✅ Teste de E-mail — Chopp ON';
        $corpo      = $this->templateTeste($cfg, $modo_label);

        return $this->enviar($email, $assunto, $corpo, self::TIPO_TESTE);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO VIA SMTP (PHPMailer ou fallback nativo)
    // ─────────────────────────────────────────────────────────────────────────

    private function enviarViaSMTP(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        // Tentar PHPMailer primeiro
        $phpmailer_paths = [
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
            __DIR__ . '/../vendor/autoload.php',
        ];

        $phpmailer_disponivel = false;
        foreach ($phpmailer_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (file_exists(dirname($path) . '/SMTP.php')) {
                    require_once dirname($path) . '/SMTP.php';
                    require_once dirname($path) . '/Exception.php';
                }
                $phpmailer_disponivel = class_exists('PHPMailer\PHPMailer\PHPMailer');
                break;
            }
        }

        if ($phpmailer_disponivel) {
            return $this->enviarComPHPMailer($cfg, $destinatario, $assunto, $corpo);
        }

        // Fallback: usar cURL direto para SMTP (sem PHPMailer)
        return $this->enviarComCurl($cfg, $destinatario, $assunto, $corpo);
    }

    private function enviarComPHPMailer(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet    = 'UTF-8';
            $mail->Host       = $cfg['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['smtp_username'];
            $mail->Password   = base64_decode($cfg['smtp_password'] ?? '');
            $mail->Port       = (int)$cfg['smtp_port'];
            $mail->SMTPSecure = ($cfg['smtp_secure'] === 'ssl')
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $mail->Body    = $corpo;
            $mail->AltBody = strip_tags($corpo);

            $dests = is_array($destinatario) ? $destinatario : [$destinatario];
            foreach ($dests as $d) {
                $mail->addAddress(trim($d));
            }

            $mail->send();
            return ['success' => true, 'message' => 'E-mail enviado com sucesso via PHPMailer'];

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return ['success' => false, 'message' => 'PHPMailer: ' . $e->getMessage()];
        }
    }

    /**
     * Fallback sem PHPMailer: usa cURL para enviar via SMTP do Gmail.
     * Funciona com App Password do Gmail.
     */
    private function enviarComCurl(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        $dests = is_array($destinatario) ? $destinatario : [$destinatario];
        $from  = $cfg['from_email'];
        $pass  = base64_decode($cfg['smtp_password'] ?? '');
        $host  = $cfg['smtp_host'];
        $port  = (int)$cfg['smtp_port'];

        // Montar mensagem RFC 2822
        $boundary = md5(uniqid());
        $headers  = "From: {$cfg['from_name']} <{$from}>\r\n";
        $headers .= "To: " . implode(', ', $dests) . "\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= strip_tags($corpo) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $corpo . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $message = $headers . "\r\n" . $body;

        // Tentar via cURL SMTP
        $ch = curl_init();
        $protocol = ($cfg['smtp_secure'] === 'ssl') ? 'smtps' : 'smtp';
        curl_setopt_array($ch, [
            CURLOPT_URL            => "{$protocol}://{$host}:{$port}",
            CURLOPT_MAIL_FROM      => "<{$from}>",
            CURLOPT_MAIL_RCPT      => array_map(fn($d) => "<" . trim($d) . ">", $dests),
            CURLOPT_READDATA       => fopen('data://text/plain,' . urlencode($message), 'r'),
            CURLOPT_UPLOAD         => true,
            CURLOPT_USERNAME       => $cfg['smtp_username'],
            CURLOPT_PASSWORD       => $pass,
            CURLOPT_USE_SSL        => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            // Último recurso: mail() nativo do PHP
            $headers_native  = "From: {$cfg['from_name']} <{$from}>\r\n";
            $headers_native .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers_native .= "MIME-Version: 1.0\r\n";

            $sent = mail(implode(',', $dests), $assunto, $corpo, $headers_native);
            if ($sent) {
                return ['success' => true, 'message' => 'E-mail enviado via mail() nativo'];
            }
            return ['success' => false, 'message' => "cURL SMTP falhou ({$error}) e mail() nativo também falhou. Verifique as configurações SMTP."];
        }

        return ['success' => true, 'message' => 'E-mail enviado com sucesso via cURL SMTP'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO VIA GMAIL OAUTH2
    // ─────────────────────────────────────────────────────────────────────────

    private function enviarViaOAuth2(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        if (empty($cfg['oauth_client_id']) || empty($cfg['oauth_client_secret']) || empty($cfg['oauth_refresh_token'])) {
            return ['success' => false, 'message' => 'OAuth2 Gmail não configurado. Informe Client ID, Client Secret e Refresh Token.'];
        }

        // Obter access token válido
        $access_token = $this->obterAccessToken($cfg);
        if (!$access_token) {
            return ['success' => false, 'message' => 'Falha ao obter Access Token do Google. Verifique o Refresh Token.'];
        }

        $dests = is_array($destinatario) ? $destinatario : [$destinatario];
        $from  = $cfg['oauth_email'] ?: $cfg['from_email'];

        // Montar mensagem RFC 2822 em base64url para Gmail API
        $boundary = md5(uniqid());
        $raw  = "From: {$cfg['from_name']} <{$from}>\r\n";
        $raw .= "To: " . implode(', ', $dests) . "\r\n";
        $raw .= "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n";
        $raw .= "MIME-Version: 1.0\r\n";
        $raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $raw .= "Date: " . date('r') . "\r\n\r\n";
        $raw .= "--{$boundary}\r\n";
        $raw .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $raw .= strip_tags($corpo) . "\r\n";
        $raw .= "--{$boundary}\r\n";
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $raw .= $corpo . "\r\n";
        $raw .= "--{$boundary}--\r\n";

        $raw_b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        // Chamar Gmail API
        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['raw' => $raw_b64]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['success' => false, 'message' => 'Erro de conexão com Gmail API: ' . $curl_err];
        }

        $data = json_decode($response, true);

        if ($http_code === 200 && !empty($data['id'])) {
            return ['success' => true, 'message' => 'E-mail enviado com sucesso via Gmail OAuth2 (ID: ' . $data['id'] . ')'];
        }

        $err_msg = $data['error']['message'] ?? $response;
        return ['success' => false, 'message' => "Gmail API HTTP {$http_code}: {$err_msg}"];
    }

    /**
     * Obtém um access token válido, renovando via refresh token se necessário.
     */
    private function obterAccessToken(array $cfg): ?string
    {
        // Verificar se o access token em cache ainda é válido (com 60s de margem)
        if (!empty($cfg['oauth_access_token']) && !empty($cfg['oauth_token_expiry'])) {
            if (strtotime($cfg['oauth_token_expiry']) > (time() + 60)) {
                return base64_decode($cfg['oauth_access_token']);
            }
        }

        // Renovar via refresh token
        $client_id     = $cfg['oauth_client_id'];
        $client_secret = base64_decode($cfg['oauth_client_secret']);
        $refresh_token = base64_decode($cfg['oauth_refresh_token']);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            return null;
        }

        $access_token = $data['access_token'];
        $expires_in   = intval($data['expires_in'] ?? 3600);
        $expiry       = date('Y-m-d H:i:s', time() + $expires_in);

        // Salvar no banco para cache
        $this->conn->prepare("
            UPDATE smtp_config
            SET oauth_access_token = ?, oauth_token_expiry = ?
            WHERE id = ?
        ")->execute([
            base64_encode($access_token),
            $expiry,
            $cfg['id'],
        ]);

        $this->config = null; // Limpar cache local

        return $access_token;
    }

    /**
     * Troca o código de autorização OAuth2 pelo refresh token.
     * Chamado uma única vez durante o setup do OAuth2.
     */
    public function trocarCodigoPorToken(string $code, string $redirect_uri): array
    {
        try {
            $cfg = $this->carregarConfig();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $client_id     = $cfg['oauth_client_id'] ?? '';
        $client_secret = base64_decode($cfg['oauth_client_secret'] ?? '');

        if (empty($client_id) || empty($client_secret)) {
            return ['success' => false, 'message' => 'Client ID e Client Secret não configurados.'];
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($http_code !== 200 || empty($data['refresh_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? $response;
            return ['success' => false, 'message' => "Erro ao obter tokens: {$err}"];
        }

        // Salvar refresh token e access token
        $this->conn->prepare("
            UPDATE smtp_config
            SET oauth_refresh_token = ?,
                oauth_access_token  = ?,
                oauth_token_expiry  = ?,
                modo                = 'gmail_oauth2'
            WHERE id = ?
        ")->execute([
            base64_encode($data['refresh_token']),
            base64_encode($data['access_token']),
            date('Y-m-d H:i:s', time() + intval($data['expires_in'] ?? 3600)),
            $cfg['id'],
        ]);

        $this->config = null;

        return ['success' => true, 'message' => 'Gmail OAuth2 configurado com sucesso! Refresh Token salvo.'];
    }

    /**
     * Gera a URL de autorização OAuth2 do Google.
     */
    public function gerarUrlAutorizacao(string $redirect_uri): string
    {
        try {
            $cfg = $this->carregarConfig();
        } catch (\RuntimeException $e) {
            return '';
        }

        $params = http_build_query([
            'client_id'     => $cfg['oauth_client_id'] ?? '',
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://mail.google.com/',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALERTAS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Carrega a configuração de alertas de um estabelecimento.
     */
    public function carregarAlertasConfig(int $estab_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM email_alertas_config WHERE estabelecimento_id = ? LIMIT 1");
        $stmt->execute([$estab_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Salva a configuração de alertas de um estabelecimento.
     */
    public function salvarAlertasConfig(int $estab_id, array $dados): void
    {
        $existente = $this->carregarAlertasConfig($estab_id);

        $campos = [
            'email_principal'           => trim($dados['email_principal'] ?? ''),
            'email_copia'               => trim($dados['email_copia'] ?? ''),
            'alerta_nova_venda'         => intval($dados['alerta_nova_venda'] ?? 0),
            'alerta_nova_venda_assunto' => $dados['alerta_nova_venda_assunto'] ?? 'Nova venda realizada — {estabelecimento}',
            'alerta_nova_venda_corpo'   => $dados['alerta_nova_venda_corpo'] ?? null,
            'alerta_volume_critico'     => intval($dados['alerta_volume_critico'] ?? 0),
            'alerta_volume_assunto'     => $dados['alerta_volume_assunto'] ?? 'ALERTA: Volume crítico no barril — {tap_id}',
            'alerta_volume_corpo'       => $dados['alerta_volume_corpo'] ?? null,
            'alerta_contas_pagar'       => intval($dados['alerta_contas_pagar'] ?? 0),
            'alerta_contas_assunto'     => $dados['alerta_contas_assunto'] ?? 'Contas a pagar vencendo — {estabelecimento}',
            'alerta_contas_corpo'       => $dados['alerta_contas_corpo'] ?? null,
            'dias_antes_contas'         => intval($dados['dias_antes_contas'] ?? 3),
            'dias_apos_contas'          => intval($dados['dias_apos_contas'] ?? 2),
            'alerta_estoque_minimo'     => intval($dados['alerta_estoque_minimo'] ?? 0),
            'alerta_estoque_assunto'    => $dados['alerta_estoque_assunto'] ?? 'Estoque mínimo atingido — {produto}',
            'alerta_estoque_corpo'      => $dados['alerta_estoque_corpo'] ?? null,
            'alerta_royalties'          => intval($dados['alerta_royalties'] ?? 0),
            'alerta_royalties_assunto'  => $dados['alerta_royalties_assunto'] ?? 'Royalties vencendo — {estabelecimento}',
            'alerta_royalties_corpo'    => $dados['alerta_royalties_corpo'] ?? null,
            'alerta_tap_offline'        => intval($dados['alerta_tap_offline'] ?? 0),
            'alerta_tap_assunto'        => $dados['alerta_tap_assunto'] ?? 'TAP offline detectada — {tap_id}',
            'alerta_tap_corpo'          => $dados['alerta_tap_corpo'] ?? null,
            'resumo_diario'             => intval($dados['resumo_diario'] ?? 0),
            'resumo_horario'            => $dados['resumo_horario'] ?? '08:00:00',
            'resumo_assunto'            => $dados['resumo_assunto'] ?? 'Resumo diário — {estabelecimento} — {data}',
            'status'                    => 1,
        ];

        if ($existente) {
            $sets   = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($campos)));
            $values = array_values($campos);
            $values[] = $existente['id'];
            $this->conn->prepare("UPDATE email_alertas_config SET $sets WHERE id = ?")->execute($values);
        } else {
            $campos['estabelecimento_id'] = $estab_id;
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($campos)));
            $phs  = implode(', ', array_fill(0, count($campos), '?'));
            $this->conn->prepare("INSERT INTO email_alertas_config ($cols) VALUES ($phs)")->execute(array_values($campos));
        }
    }

    /**
     * Verifica se um alerta já foi enviado hoje para evitar duplicatas.
     */
    public function alertaJaEnviado(int $estab_id, string $tipo, int $ref_id = 0): bool
    {
        $stmt = $this->conn->prepare("
            SELECT id FROM email_alertas_enviados
            WHERE estabelecimento_id = ? AND tipo = ? AND referencia_id = ? AND data_envio = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$estab_id, $tipo, $ref_id]);
        return (bool)$stmt->fetch();
    }

    /**
     * Registra que um alerta foi enviado hoje.
     */
    public function marcarAlertaEnviado(int $estab_id, string $tipo, int $ref_id = 0): void
    {
        try {
            $this->conn->prepare("
                INSERT IGNORE INTO email_alertas_enviados (estabelecimento_id, tipo, referencia_id, data_envio)
                VALUES (?, ?, ?, CURDATE())
            ")->execute([$estab_id, $tipo, $ref_id]);
        } catch (\PDOException $e) {
            // Ignorar duplicata
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Busca os logs de e-mail com filtros opcionais.
     */
    public function buscarLogs(array $filtros = [], int $limite = 100, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['estab_id'])) {
            $where[]  = 'l.estabelecimento_id = ?';
            $params[] = $filtros['estab_id'];
        }
        if (!empty($filtros['status'])) {
            $where[]  = 'l.status = ?';
            $params[] = $filtros['status'];
        }
        if (!empty($filtros['tipo'])) {
            $where[]  = 'l.tipo = ?';
            $params[] = $filtros['tipo'];
        }
        if (!empty($filtros['data_inicio'])) {
            $where[]  = 'DATE(l.created_at) >= ?';
            $params[] = $filtros['data_inicio'];
        }
        if (!empty($filtros['data_fim'])) {
            $where[]  = 'DATE(l.created_at) <= ?';
            $params[] = $filtros['data_fim'];
        }

        $where_sql = implode(' AND ', $where);
        $params[]  = $limite;
        $params[]  = $offset;

        $stmt = $this->conn->prepare("
            SELECT l.*, e.name AS estabelecimento_nome
            FROM email_log l
            LEFT JOIN estabelecimentos e ON e.id = l.estabelecimento_id
            WHERE $where_sql
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Conta os logs com os mesmos filtros.
     */
    public function contarLogs(array $filtros = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['estab_id'])) {
            $where[]  = 'estabelecimento_id = ?';
            $params[] = $filtros['estab_id'];
        }
        if (!empty($filtros['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filtros['status'];
        }
        if (!empty($filtros['tipo'])) {
            $where[]  = 'tipo = ?';
            $params[] = $filtros['tipo'];
        }

        $where_sql = implode(' AND ', $where);
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM email_log WHERE $where_sql");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Limpa logs mais antigos que N dias.
     */
    public function limparLogsAntigos(int $dias = 90): int
    {
        $stmt = $this->conn->prepare("DELETE FROM email_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$dias]);
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS INTERNOS
    // ─────────────────────────────────────────────────────────────────────────

    private function criarLogPendente(
        ?int $estab_id,
        string $tipo,
        ?int $ref_id,
        string $destinatario,
        string $assunto,
        string $corpo
    ): int {
        try {
            $this->conn->prepare("
                INSERT INTO email_log
                    (estabelecimento_id, tipo, referencia_id, destinatario, assunto, corpo_html, status, tentativas)
                VALUES (?, ?, ?, ?, ?, ?, 'pendente', 1)
            ")->execute([$estab_id, $tipo, $ref_id, $destinatario, $assunto, $corpo]);
            return (int)$this->conn->lastInsertId();
        } catch (\PDOException $e) {
            return 0; // Tabela pode não existir ainda
        }
    }

    private function atualizarLog(int $log_id, string $status, ?string $erro, string $modo): void
    {
        if ($log_id === 0) return;
        try {
            $this->conn->prepare("
                UPDATE email_log
                SET status = ?, erro_detalhe = ?, modo_envio = ?, enviado_em = IF(? = 'enviado', NOW(), NULL)
                WHERE id = ?
            ")->execute([$status, $erro, $modo, $status, $log_id]);
        } catch (\PDOException $e) {
            // Ignorar
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEMPLATE DE TESTE
    // ─────────────────────────────────────────────────────────────────────────

    private function templateTeste(array $cfg, string $modo_label): string
    {
        $host = htmlspecialchars($cfg['smtp_host'] ?? '');
        $port = $cfg['smtp_port'] ?? '';
        $user = htmlspecialchars($cfg['smtp_username'] ?? $cfg['oauth_email'] ?? '');
        $from = htmlspecialchars($cfg['from_email'] ?? '');
        $data = date('d/m/Y H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
        <tr>
          <td style="background:linear-gradient(135deg,#f97316,#ea580c);padding:30px;text-align:center;">
            <h1 style="color:#fff;margin:0;font-size:24px;">✅ Teste de E-mail — Chopp ON</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:30px;">
            <div style="background:#d1fae5;border-left:4px solid #10b981;padding:15px;border-radius:4px;margin-bottom:20px;">
              <strong style="color:#065f46;">Parabéns! Sua configuração de e-mail está funcionando corretamente.</strong>
            </div>
            <p style="color:#374151;">Este é um e-mail de teste enviado pelo sistema <strong>Chopp ON ERP</strong>.</p>
            <table style="width:100%;border-collapse:collapse;margin:20px 0;">
              <tr style="background:#f9fafb;">
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:bold;color:#6b7280;width:40%;">Modo de Envio</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;color:#111827;">{$modo_label}</td>
              </tr>
              <tr>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:bold;color:#6b7280;">Servidor</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;color:#111827;">{$host}:{$port}</td>
              </tr>
              <tr style="background:#f9fafb;">
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:bold;color:#6b7280;">Usuário / Gmail</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;color:#111827;">{$user}</td>
              </tr>
              <tr>
                <td style="padding:10px;font-weight:bold;color:#6b7280;">Remetente</td>
                <td style="padding:10px;color:#111827;">{$from}</td>
              </tr>
            </table>
            <p style="color:#6b7280;font-size:13px;">Data/Hora do envio: {$data}</p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb;padding:20px;text-align:center;font-size:12px;color:#9ca3af;">
            Este é um e-mail automático do sistema Chopp ON ERP. Não responda.<br>
            &copy; {$data} Chopp ON. Todos os direitos reservados.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
