<?php
/**
 * EmailManager — ChopponERP v3.0
 *
 * Gerencia envio de e-mails com dois modos:
 *   1. SMTP com senha (primário) — App Password do Gmail ou qualquer SMTP
 *   2. Gmail OAuth2 com Refresh Token (fallback) — sem senha, via API
 *
 * Prioridade de envio:
 *   SMTP com senha configurada → fallback para OAuth2 se SMTP falhar
 *   Se apenas OAuth2 estiver configurado, usa OAuth2 diretamente.
 *
 * Segurança:
 *   Senhas e tokens armazenados com AES-256-GCM (não base64 simples).
 *   Chave derivada de DB_PASS + DB_NAME via SHA-256.
 *
 * Todas as tentativas de envio são registradas na tabela email_log.
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
    // CRIPTOGRAFIA AES-256-GCM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Deriva a chave de criptografia a partir das constantes do sistema.
     * Usa SHA-256 sobre DB_PASS + DB_NAME para gerar 32 bytes.
     */
    private function chaveEncriptacao(): string
    {
        $seed = (defined('DB_PASS') ? DB_PASS : 'choppon_default_key')
              . (defined('DB_NAME') ? DB_NAME : 'choppon_db');
        return hash('sha256', $seed, true); // 32 bytes binários
    }

    /**
     * Criptografa um valor com AES-256-GCM.
     * Retorna string no formato: base64(iv + tag + ciphertext)
     * Prefixo "gcm:" identifica o novo formato vs base64 legado.
     */
    public function criptografar(string $valor): string
    {
        if (empty($valor)) return '';

        $chave  = $this->chaveEncriptacao();
        $iv     = random_bytes(12); // 96 bits para GCM
        $tag    = '';

        $cifrado = openssl_encrypt(
            $valor,
            'aes-256-gcm',
            $chave,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($cifrado === false) {
            // Fallback para base64 se openssl não suportar GCM
            return base64_encode($valor);
        }

        return 'gcm:' . base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Descriptografa um valor.
     * Suporta tanto o novo formato GCM quanto o base64 legado.
     */
    public function descriptografar(string $valor): string
    {
        if (empty($valor)) return '';

        // Novo formato AES-256-GCM
        if (str_starts_with($valor, 'gcm:')) {
            $dados  = base64_decode(substr($valor, 4));
            if (strlen($dados) < 28) return ''; // iv(12) + tag(16) = 28 mínimo

            $chave   = $this->chaveEncriptacao();
            $iv      = substr($dados, 0, 12);
            $tag     = substr($dados, 12, 16);
            $cifrado = substr($dados, 28);

            $resultado = openssl_decrypt(
                $cifrado,
                'aes-256-gcm',
                $chave,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $resultado !== false ? $resultado : '';
        }

        // Formato legado: base64 simples
        return base64_decode($valor);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIGURAÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Carrega a configuração ativa do banco.
     *
     * Prioridade:
     *   1. SMTP com senha configurada (smtp_password com smtp_username preenchido)
     *   2. Gmail OAuth2 com refresh_token configurado
     *   3. Qualquer registro ativo
     *
     * Lança RuntimeException se não houver configuração ativa.
     */
    public function carregarConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        // Prioridade 1: SMTP com senha configurada
        $stmt = $this->conn->query(
            "SELECT * FROM smtp_config
              WHERE status = 1
                AND modo = 'smtp_password'
                AND smtp_username IS NOT NULL AND smtp_username != ''
                AND smtp_password IS NOT NULL AND smtp_password != ''
              ORDER BY updated_at DESC
              LIMIT 1"
        );
        $cfg = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Prioridade 2: OAuth2 com refresh token configurado
        if (!$cfg) {
            $stmt = $this->conn->query(
                "SELECT * FROM smtp_config
                  WHERE status = 1
                    AND modo = 'gmail_oauth2'
                    AND oauth_refresh_token IS NOT NULL
                    AND oauth_refresh_token != ''
                  ORDER BY updated_at DESC
                  LIMIT 1"
            );
            $cfg = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        // Prioridade 3: qualquer registro ativo
        if (!$cfg) {
            $stmt = $this->conn->query(
                "SELECT * FROM smtp_config
                  WHERE status = 1
                  ORDER BY updated_at DESC
                  LIMIT 1"
            );
            $cfg = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$cfg) {
            throw new \RuntimeException('Nenhuma configuração de e-mail ativa encontrada. Configure em Integrações → Config. E-mail.');
        }

        $this->config = $cfg;
        return $cfg;
    }

    /**
     * Carrega configuração por modo específico (para o formulário de edição).
     */
    public function carregarConfigPorModo(string $modo): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM smtp_config WHERE modo = ? ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([$modo]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retorna as colunas existentes na tabela smtp_config.
     */
    private function colunasSmtpConfig(): array
    {
        $rows = $this->conn->query("SHOW COLUMNS FROM smtp_config")->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows, 'Field');
    }

    /**
     * Garante que todas as colunas necessárias existam na tabela smtp_config.
     */
    private function garantirEstrutura(): void
    {
        try {
            $existentes = $this->colunasSmtpConfig();
        } catch (\Throwable $e) {
            return;
        }

        // ── Remover FK e UNIQUE KEY em estabelecimento_id ─────────────────────
        try {
            $fks = $this->conn->query(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'smtp_config'
                    AND COLUMN_NAME = 'estabelecimento_id'
                    AND REFERENCED_TABLE_NAME IS NOT NULL"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($fks as $fk) {
                try { $this->conn->exec("ALTER TABLE `smtp_config` DROP FOREIGN KEY `$fk`"); } catch (\Throwable $e) {}
            }
            $uks = $this->conn->query(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'smtp_config'
                    AND COLUMN_NAME = 'estabelecimento_id'
                    AND NON_UNIQUE = 0
                    AND INDEX_NAME != 'PRIMARY'"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($uks as $uk) {
                try { $this->conn->exec("ALTER TABLE `smtp_config` DROP INDEX `$uk`"); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {}

        // ── Tornar estabelecimento_id nullable ────────────────────────────────
        try {
            $this->conn->exec(
                "ALTER TABLE `smtp_config`
                 MODIFY COLUMN `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL"
            );
        } catch (\Throwable $e) {}

        // ── Adicionar colunas faltantes ───────────────────────────────────────
        $necessarias = [
            'modo'                => "ADD COLUMN `modo` ENUM('smtp_password','gmail_oauth2') NOT NULL DEFAULT 'smtp_password' AFTER `id`",
            'smtp_host'           => "ADD COLUMN `smtp_host` VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com'",
            'smtp_port'           => "ADD COLUMN `smtp_port` INT NOT NULL DEFAULT 587",
            'smtp_secure'         => "ADD COLUMN `smtp_secure` ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls'",
            'smtp_username'       => "ADD COLUMN `smtp_username` VARCHAR(255) NOT NULL DEFAULT ''",
            'smtp_password'       => "ADD COLUMN `smtp_password` TEXT NULL DEFAULT NULL",
            'oauth_client_id'     => "ADD COLUMN `oauth_client_id` VARCHAR(255) NULL DEFAULT NULL",
            'oauth_client_secret' => "ADD COLUMN `oauth_client_secret` TEXT NULL DEFAULT NULL",
            'oauth_refresh_token' => "ADD COLUMN `oauth_refresh_token` TEXT NULL DEFAULT NULL",
            'oauth_access_token'  => "ADD COLUMN `oauth_access_token` TEXT NULL DEFAULT NULL",
            'oauth_token_expiry'  => "ADD COLUMN `oauth_token_expiry` DATETIME NULL DEFAULT NULL",
            'oauth_email'         => "ADD COLUMN `oauth_email` VARCHAR(255) NULL DEFAULT NULL",
            'from_email'          => "ADD COLUMN `from_email` VARCHAR(255) NOT NULL DEFAULT ''",
            'from_name'           => "ADD COLUMN `from_name` VARCHAR(255) NOT NULL DEFAULT 'Chopp ON'",
            'updated_at'          => "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];

        try { $existentes = $this->colunasSmtpConfig(); } catch (\Throwable $e) { return; }

        foreach ($necessarias as $col => $ddl) {
            if (!in_array($col, $existentes, true)) {
                try { $this->conn->exec("ALTER TABLE `smtp_config` $ddl"); } catch (\Throwable $e) {}
            }
        }

        // ── Garantir smtp_password aceita NULL ────────────────────────────────
        try {
            $this->conn->exec("ALTER TABLE `smtp_config` MODIFY COLUMN `smtp_password` TEXT NULL DEFAULT NULL");
        } catch (\Throwable $e) {}
    }

    /**
     * Salva ou atualiza a configuração SMTP/OAuth2.
     * Cada modo (smtp_password / gmail_oauth2) tem seu próprio registro.
     * Senhas e tokens são armazenados com AES-256-GCM.
     */
    public function salvarConfig(array $dados): void
    {
        $this->garantirEstrutura();

        $modo = $dados['modo'] ?? 'smtp_password';

        // Buscar registro existente do mesmo modo
        $stmt = $this->conn->prepare("SELECT id FROM smtp_config WHERE modo = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$modo]);
        $existe = $stmt->fetch();

        $colunasReais = $this->colunasSmtpConfig();

        $campos = [
            'modo'          => $modo,
            'smtp_host'     => $dados['smtp_host']     ?? ($dados['host'] ?? 'smtp.gmail.com'),
            'smtp_port'     => intval($dados['smtp_port'] ?? ($dados['port'] ?? 587)),
            'smtp_secure'   => $dados['smtp_secure']   ?? ($dados['encryption'] ?? 'tls'),
            'smtp_username' => $dados['smtp_username']  ?? ($dados['username'] ?? ''),
            'from_name'     => $dados['from_name']     ?? 'Chopp ON',
            'status'        => 1,
        ];

        // from_email: no modo OAuth2, usar oauth_email se from_email vazio
        $from_email = $dados['from_email'] ?? '';
        if (empty($from_email) && $modo === 'gmail_oauth2' && !empty($dados['oauth_email'])) {
            $from_email = $dados['oauth_email'];
        }
        $campos['from_email'] = $from_email;

        // ── Senha SMTP — criptografar com AES-256-GCM ─────────────────────────
        $nova_senha = $dados['smtp_password'] ?? $dados['password'] ?? '';
        if (!empty($nova_senha)) {
            $campos['smtp_password'] = $this->criptografar($nova_senha);
        }

        // ── OAuth2 — criptografar client_secret e tokens ──────────────────────
        if (!empty($dados['oauth_client_id'])) {
            $campos['oauth_client_id'] = trim($dados['oauth_client_id']);
        }
        if (!empty($dados['oauth_client_secret'])) {
            $campos['oauth_client_secret'] = $this->criptografar(trim($dados['oauth_client_secret']));
        }
        if (!empty($dados['oauth_refresh_token'])) {
            $campos['oauth_refresh_token'] = $this->criptografar(trim($dados['oauth_refresh_token']));
            $campos['oauth_access_token']  = null;
            $campos['oauth_token_expiry']  = null;
        }
        if (!empty($dados['oauth_email'])) {
            $campos['oauth_email'] = trim($dados['oauth_email']);
        }

        // Filtrar apenas colunas que existem na tabela
        $campos = array_filter($campos, fn($k) => in_array($k, $colunasReais, true), ARRAY_FILTER_USE_KEY);

        if ($existe) {
            $sets   = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($campos)));
            $values = array_values($campos);
            $values[] = $existe['id'];
            $this->conn->prepare("UPDATE smtp_config SET $sets WHERE id = ?")->execute($values);
        } else {
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($campos)));
            $phs  = implode(', ', array_fill(0, count($campos), '?'));
            $this->conn->prepare("INSERT INTO smtp_config ($cols) VALUES ($phs)")->execute(array_values($campos));
        }

        $this->config = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Envia um e-mail e registra no log.
     *
     * Estratégia:
     *   1. Tenta SMTP se configurado (smtp_password com senha)
     *   2. Se SMTP falhar OU não estiver configurado, tenta OAuth2 como fallback
     *   3. Se ambos falharem, retorna erro detalhado
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
            // ── Carregar configurações disponíveis ────────────────────────────
            $cfg_smtp   = $this->carregarConfigPorModo('smtp_password');
            $cfg_oauth  = $this->carregarConfigPorModo('gmail_oauth2');

            $smtp_disponivel  = !empty($cfg_smtp)
                && !empty($cfg_smtp['smtp_username'])
                && !empty($cfg_smtp['smtp_password'])
                && ($cfg_smtp['status'] ?? 0) == 1;

            $oauth_disponivel = !empty($cfg_oauth)
                && !empty($cfg_oauth['oauth_refresh_token'])
                && ($cfg_oauth['status'] ?? 0) == 1;

            if (!$smtp_disponivel && !$oauth_disponivel) {
                $msg = 'Nenhuma configuração de e-mail ativa. Configure SMTP ou OAuth2 em Integrações → Config. E-mail.';
                $this->atualizarLog($log_id, 'erro', $msg, 'nenhum');
                return ['success' => false, 'message' => $msg];
            }

            // ── Tentativa 1: SMTP (primário) ──────────────────────────────────
            if ($smtp_disponivel) {
                $result = $this->enviarViaSMTP($cfg_smtp, $destinatario, $assunto, $corpo);
                if ($result['success']) {
                    $this->atualizarLog($log_id, 'enviado', null, 'smtp_password');
                    return $result;
                }
                $erro_smtp = $result['message'];
            } else {
                $erro_smtp = null;
            }

            // ── Tentativa 2: OAuth2 (fallback) ────────────────────────────────
            if ($oauth_disponivel) {
                $result = $this->enviarViaOAuth2($cfg_oauth, $destinatario, $assunto, $corpo);
                if ($result['success']) {
                    $modo_label = $smtp_disponivel ? 'gmail_oauth2 (fallback)' : 'gmail_oauth2';
                    $this->atualizarLog($log_id, 'enviado', null, $modo_label);
                    if ($smtp_disponivel) {
                        $result['message'] .= ' (SMTP falhou, enviado via OAuth2 como fallback)';
                    }
                    return $result;
                }
                $erro_oauth = $result['message'];
            } else {
                $erro_oauth = null;
            }

            // ── Ambos falharam ────────────────────────────────────────────────
            $erros = [];
            if ($erro_smtp)  $erros[] = "SMTP: {$erro_smtp}";
            if ($erro_oauth) $erros[] = "OAuth2: {$erro_oauth}";
            $msg_final = implode(' | ', $erros);

            $this->atualizarLog($log_id, 'erro', $msg_final, 'falha_total');
            return ['success' => false, 'message' => $msg_final];

        } catch (\Throwable $e) {
            $this->atualizarLog($log_id, 'erro', $e->getMessage(), 'excecao');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Envia e-mail de teste para verificar a configuração.
     * Funciona mesmo que apenas SMTP ou apenas OAuth2 esteja configurado.
     */
    public function enviarTeste(string $email): array
    {
        // Determinar qual modo será usado para o template
        $cfg_smtp  = $this->carregarConfigPorModo('smtp_password');
        $cfg_oauth = $this->carregarConfigPorModo('gmail_oauth2');

        $smtp_ativo  = !empty($cfg_smtp)  && !empty($cfg_smtp['smtp_username'])  && !empty($cfg_smtp['smtp_password'])  && ($cfg_smtp['status'] ?? 0) == 1;
        $oauth_ativo = !empty($cfg_oauth) && !empty($cfg_oauth['oauth_refresh_token']) && ($cfg_oauth['status'] ?? 0) == 1;

        if (!$smtp_ativo && !$oauth_ativo) {
            return ['success' => false, 'message' => 'Nenhuma configuração de e-mail ativa. Configure SMTP ou OAuth2 primeiro.'];
        }

        $cfg_para_template = $smtp_ativo ? $cfg_smtp : $cfg_oauth;
        $modo_label = $smtp_ativo
            ? 'SMTP com senha' . ($oauth_ativo ? ' (OAuth2 disponível como fallback)' : '')
            : 'Gmail OAuth2';

        $assunto = '✅ Teste de E-mail — Chopp ON';
        $corpo   = $this->templateTeste($cfg_para_template, $modo_label);

        return $this->enviar($email, $assunto, $corpo, self::TIPO_TESTE);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO VIA SMTP
    // ─────────────────────────────────────────────────────────────────────────

    private function enviarViaSMTP(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
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

        return $this->enviarComCurl($cfg, $destinatario, $assunto, $corpo);
    }

    private function enviarComPHPMailer(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet    = 'UTF-8';
            $mail->Host       = $cfg['smtp_host'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['smtp_username'] ?? '';
            $mail->Password   = $this->descriptografar($cfg['smtp_password'] ?? '');
            $mail->Port       = (int)($cfg['smtp_port'] ?? 587);

            $enc = $cfg['smtp_secure'] ?? 'tls';
            $mail->SMTPSecure = ($enc === 'ssl')
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $mail->setFrom($cfg['from_email'] ?? $cfg['smtp_username'], $cfg['from_name'] ?? 'Chopp ON');
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $mail->Body    = $corpo;
            $mail->AltBody = strip_tags($corpo);

            $dests = is_array($destinatario) ? $destinatario : [$destinatario];
            foreach ($dests as $d) {
                $mail->addAddress(trim($d));
            }

            $mail->send();
            return ['success' => true, 'message' => 'E-mail enviado com sucesso via PHPMailer SMTP'];

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return ['success' => false, 'message' => 'PHPMailer: ' . $e->getMessage()];
        }
    }

    private function enviarComCurl(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        $dests = is_array($destinatario) ? $destinatario : [$destinatario];
        $from  = $cfg['from_email'] ?? $cfg['smtp_username'] ?? '';
        $pass  = $this->descriptografar($cfg['smtp_password'] ?? '');
        $host  = $cfg['smtp_host'] ?? 'smtp.gmail.com';
        $port  = (int)($cfg['smtp_port'] ?? 587);
        $enc   = $cfg['smtp_secure'] ?? 'tls';

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

        $ch = curl_init();
        $protocol = ($enc === 'ssl') ? 'smtps' : 'smtp';
        curl_setopt_array($ch, [
            CURLOPT_URL            => "{$protocol}://{$host}:{$port}",
            CURLOPT_MAIL_FROM      => "<{$from}>",
            CURLOPT_MAIL_RCPT      => array_map(fn($d) => "<" . trim($d) . ">", $dests),
            CURLOPT_READDATA       => fopen('data://text/plain,' . urlencode($message), 'r'),
            CURLOPT_UPLOAD         => true,
            CURLOPT_USERNAME       => $cfg['smtp_username'] ?? '',
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
            // Último recurso: mail() nativo
            $headers_native  = "From: {$cfg['from_name']} <{$from}>\r\n";
            $headers_native .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers_native .= "MIME-Version: 1.0\r\n";
            $sent = mail(implode(',', $dests), $assunto, $corpo, $headers_native);
            if ($sent) {
                return ['success' => true, 'message' => 'E-mail enviado via mail() nativo'];
            }
            return ['success' => false, 'message' => "cURL SMTP falhou ({$error}) e mail() nativo também falhou."];
        }

        return ['success' => true, 'message' => 'E-mail enviado com sucesso via cURL SMTP'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENVIO VIA GMAIL OAUTH2
    // ─────────────────────────────────────────────────────────────────────────

    private function enviarViaOAuth2(array $cfg, $destinatario, string $assunto, string $corpo): array
    {
        if (empty($cfg['oauth_client_id'])) {
            return ['success' => false, 'message' => 'OAuth2: Client ID não configurado.'];
        }
        if (empty($cfg['oauth_client_secret'])) {
            return ['success' => false, 'message' => 'OAuth2: Client Secret não configurado.'];
        }
        if (empty($cfg['oauth_refresh_token'])) {
            return ['success' => false, 'message' => 'OAuth2: Refresh Token não configurado. Autorize na aba Gmail OAuth2.'];
        }

        $token_result = $this->obterAccessTokenDetalhado($cfg);
        if (!$token_result['success']) {
            return ['success' => false, 'message' => 'OAuth2 — Falha ao obter Access Token: ' . $token_result['message']];
        }
        $access_token = $token_result['access_token'];

        $dests = is_array($destinatario) ? $destinatario : [$destinatario];
        $from  = $cfg['oauth_email'] ?: $cfg['from_email'];

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

        $response  = curl_exec($ch);
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

        $err_code   = $data['error']['code']    ?? $http_code;
        $err_msg    = $data['error']['message']  ?? $response;
        $err_status = $data['error']['status']   ?? '';

        $msg = "Gmail API HTTP {$err_code}";
        if ($err_status) $msg .= " ({$err_status})";
        $msg .= ": {$err_msg}";

        if ($err_code === 401 || $err_status === 'UNAUTHENTICATED') {
            $msg .= ' — Refresh Token expirou. Reautorize na aba Gmail OAuth2.';
        } elseif ($err_code === 403 || $err_status === 'PERMISSION_DENIED') {
            $msg .= ' — Permissão negada. Verifique o escopo "https://mail.google.com/" no Google Cloud Console.';
        }

        return ['success' => false, 'message' => $msg];
    }

    /**
     * Obtém um access token válido com retorno detalhado de erro.
     */
    private function obterAccessTokenDetalhado(array $cfg): array
    {
        // Verificar cache (com 60s de margem)
        if (!empty($cfg['oauth_access_token']) && !empty($cfg['oauth_token_expiry'])) {
            if (strtotime($cfg['oauth_token_expiry']) > (time() + 60)) {
                return ['success' => true, 'access_token' => $this->descriptografar($cfg['oauth_access_token'])];
            }
        }

        $client_id     = $cfg['oauth_client_id'];
        $client_secret = $this->descriptografar($cfg['oauth_client_secret'] ?? '');
        $refresh_token = $this->descriptografar($cfg['oauth_refresh_token'] ?? '');

        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return ['success' => false, 'message' => 'Credenciais OAuth2 incompletas.'];
        }

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
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['success' => false, 'message' => 'Erro de conexão com Google OAuth2: ' . $curl_err];
        }

        $data = json_decode($response, true);

        if ($http_code !== 200 || empty($data['access_token'])) {
            $err_type = $data['error'] ?? 'unknown';
            $err_desc = $data['error_description'] ?? $response;
            $msg = "Google OAuth2 HTTP {$http_code} — {$err_type}: {$err_desc}";
            if ($err_type === 'invalid_grant') {
                $msg .= ' — Refresh Token revogado ou expirado. Reautorize.';
            }
            return ['success' => false, 'message' => $msg];
        }

        $access_token = $data['access_token'];
        $expires_in   = intval($data['expires_in'] ?? 3600);
        $expiry       = date('Y-m-d H:i:s', time() + $expires_in);

        // Salvar access token criptografado no banco
        try {
            $this->conn->prepare("
                UPDATE smtp_config
                SET oauth_access_token = ?, oauth_token_expiry = ?
                WHERE id = ?
            ")->execute([
                $this->criptografar($access_token),
                $expiry,
                $cfg['id'],
            ]);
        } catch (\Throwable $e) {}

        $this->config = null;

        return ['success' => true, 'access_token' => $access_token];
    }

    /**
     * Troca o código de autorização OAuth2 pelo refresh token.
     */
    public function trocarCodigoPorToken(string $code, string $redirect_uri): array
    {
        // Buscar config OAuth2
        $cfg = $this->carregarConfigPorModo('gmail_oauth2');
        if (!$cfg) {
            try {
                $cfg = $this->carregarConfig();
            } catch (\RuntimeException $e) {
                $stmt = $this->conn->query(
                    "SELECT * FROM smtp_config
                      WHERE oauth_client_id IS NOT NULL AND oauth_client_id != ''
                      ORDER BY updated_at DESC LIMIT 1"
                );
                $cfg = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        }

        if (!$cfg) {
            return ['success' => false, 'message' => 'Salve as credenciais OAuth2 (Client ID e Client Secret) antes de autorizar.'];
        }

        $client_id     = $cfg['oauth_client_id'] ?? '';
        $client_secret = $this->descriptografar($cfg['oauth_client_secret'] ?? '');

        if (empty($client_id) || empty($client_secret)) {
            return ['success' => false, 'message' => 'Client ID e Client Secret não configurados. Salve as credenciais primeiro.'];
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
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['success' => false, 'message' => 'Erro de conexão com Google: ' . $curl_err];
        }

        $data = json_decode($response, true);

        if ($http_code !== 200 || empty($data['refresh_token'])) {
            $err_type = $data['error'] ?? 'unknown';
            $err_desc = $data['error_description'] ?? $response;
            $msg = "Erro ao obter tokens (HTTP {$http_code}) — {$err_type}: {$err_desc}";

            if ($err_type === 'invalid_grant') {
                $msg .= ' — Código expirado (válido ~10 min) ou já usado. Clique em "Autorizar Gmail" novamente.';
            } elseif ($err_type === 'redirect_uri_mismatch') {
                $msg .= ' — URI de redirecionamento não corresponde. Verifique no Google Cloud Console: "' . $redirect_uri . '"';
            } elseif (empty($data['refresh_token']) && !empty($data['access_token'])) {
                $msg = 'Google retornou access_token mas não refresh_token. Acesse https://myaccount.google.com/permissions, revogue o acesso ao app e autorize novamente.';
            }

            return ['success' => false, 'message' => $msg];
        }

        // Salvar tokens criptografados
        try {
            $this->conn->prepare("
                UPDATE smtp_config
                SET oauth_refresh_token = ?,
                    oauth_access_token  = ?,
                    oauth_token_expiry  = ?,
                    modo                = 'gmail_oauth2'
                WHERE id = ?
            ")->execute([
                $this->criptografar($data['refresh_token']),
                $this->criptografar($data['access_token']),
                date('Y-m-d H:i:s', time() + intval($data['expires_in'] ?? 3600)),
                $cfg['id'],
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Token obtido mas falha ao salvar no banco: ' . $e->getMessage()];
        }

        $this->config = null;

        return ['success' => true, 'message' => '✅ Gmail OAuth2 configurado com sucesso! Refresh Token salvo com criptografia AES-256-GCM.'];
    }

    /**
     * Gera a URL de autorização OAuth2 do Google.
     */
    public function gerarUrlAutorizacao(?string $redirect_uri = null): string
    {
        $cfg = $this->carregarConfigPorModo('gmail_oauth2');
        if (!$cfg) {
            try {
                $cfg = $this->carregarConfig();
            } catch (\RuntimeException $e) {
                return '';
            }
        }

        $client_id = $cfg['oauth_client_id'] ?? '';
        if (empty($client_id)) {
            return '';
        }

        if (empty($redirect_uri)) {
            $redirect_uri = 'https://ochoppoficial.com.br/oauth2cb.php';
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        $params = http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://mail.google.com/',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALERTAS
    // ─────────────────────────────────────────────────────────────────────────

    public function carregarAlertasConfig(int $estab_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM email_alertas_config WHERE estabelecimento_id = ? LIMIT 1");
        $stmt->execute([$estab_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

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

    public function marcarAlertaEnviado(int $estab_id, string $tipo, int $ref_id = 0): void
    {
        try {
            $this->conn->prepare("
                INSERT IGNORE INTO email_alertas_enviados (estabelecimento_id, tipo, referencia_id, data_envio)
                VALUES (?, ?, ?, CURDATE())
            ")->execute([$estab_id, $tipo, $ref_id]);
        } catch (\PDOException $e) {}
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGS
    // ─────────────────────────────────────────────────────────────────────────

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
            return 0;
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
        } catch (\PDOException $e) {}
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
            Este é um e-mail automático do sistema Chopp ON ERP. Não responda.
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
