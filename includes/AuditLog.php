<?php
/**
 * AuditLog — Helper de Auditoria de Acessos
 * Choppon ERP v1.0.0
 *
 * Registra todos os eventos de acesso e ações críticas do sistema
 * na tabela `audit_log` para fins de auditoria e rastreabilidade.
 *
 * Uso:
 *   AuditLog::login($conn, $user, $ok);
 *   AuditLog::logout($conn);
 *   AuditLog::trocaEstabelecimento($conn, $estab_id, $estab_nome);
 *   AuditLog::acao($conn, 'criar', 'Usuário criado: João Silva');
 *   AuditLog::pagina($conn, 'admin/pedidos.php');
 */
class AuditLog
{
    // ── Helpers internos ──────────────────────────────────────────────────────

    /**
     * Retorna o IP real do cliente, considerando proxies
     */
    private static function getIP(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP']  ?? '',   // Cloudflare
            $_SERVER['HTTP_X_FORWARDED_FOR']   ?? '',   // Proxy/load balancer
            $_SERVER['HTTP_X_REAL_IP']         ?? '',   // Nginx proxy
            $_SERVER['REMOTE_ADDR']            ?? '',
        ];
        foreach ($candidates as $ip) {
            $ip = trim(explode(',', $ip)[0]);
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return 'desconhecido';
    }

    /**
     * Retorna o User-Agent truncado
     */
    private static function getUA(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido', 0, 500);
    }

    /**
     * Retorna o ID da sessão atual
     */
    private static function getSID(): string
    {
        return session_id() ?: '';
    }

    /**
     * Retorna a página atual (REQUEST_URI truncado)
     */
    private static function getPagina(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Remove query string para não expor dados sensíveis
        $uri = strtok($uri, '?');
        return substr($uri, 0, 255);
    }

    /**
     * Insere um registro na tabela audit_log.
     * Falhas silenciosas: não interrompe o fluxo principal.
     */
    private static function inserir(
        $conn,
        string $evento,
        ?int   $user_id,
        ?string $user_name,
        ?string $user_email,
        ?int   $user_type,
        ?int   $estab_id,
        ?string $estab_nome,
        ?string $pagina,
        ?string $descricao
    ): void {
        try {
            $stmt = $conn->prepare("
                INSERT INTO audit_log
                    (user_id, user_name, user_email, user_type,
                     estabelecimento_id, estabelecimento_nome,
                     evento, pagina, descricao,
                     ip, user_agent, session_id, created_at)
                VALUES
                    (?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                $user_name,
                $user_email,
                $user_type,
                $estab_id,
                $estab_nome,
                $evento,
                $pagina ?? self::getPagina(),
                $descricao,
                self::getIP(),
                self::getUA(),
                self::getSID(),
            ]);
        } catch (Exception $e) {
            // Falha silenciosa: log de auditoria não deve derrubar o sistema
            if (class_exists('Logger')) {
                Logger::error('AuditLog::inserir falhou: ' . $e->getMessage());
            }
        }
    }

    // ── Métodos públicos ──────────────────────────────────────────────────────

    /**
     * Registra tentativa de login (bem-sucedida ou falha).
     *
     * @param mixed  $conn    Conexão PDO
     * @param array|null $user  Dados do usuário (null em caso de falha)
     * @param bool   $ok      true = login_ok, false = login_falha
     * @param string $email   E-mail tentado (para falhas)
     */
    public static function login($conn, ?array $user, bool $ok, string $email = ''): void
    {
        $evento    = $ok ? 'login_ok' : 'login_falha';
        $user_id   = $ok && $user ? (int)$user['id']   : null;
        $user_name = $ok && $user ? $user['name']       : null;
        $user_email= $ok && $user ? $user['email']      : ($email ?: null);
        $user_type = $ok && $user ? (int)$user['type']  : null;

        // Estabelecimento ativo após login
        $estab_id   = null;
        $estab_nome = null;
        if ($ok && $user && isset($_SESSION['estabelecimento_id'])) {
            $estab_id = (int)$_SESSION['estabelecimento_id'];
            try {
                $s = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ? LIMIT 1");
                $s->execute([$estab_id]);
                $estab_nome = $s->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }

        $desc = $ok
            ? "Login bem-sucedido: {$user_email}"
            : "Tentativa de login falhou: " . ($email ?: 'e-mail não informado');

        self::inserir($conn, $evento, $user_id, $user_name, $user_email, $user_type,
                      $estab_id, $estab_nome, '/index.php', $desc);
    }

    /**
     * Registra logout do usuário.
     */
    public static function logout($conn): void
    {
        if (!isset($_SESSION['user_id'])) return;

        $user_id    = (int)$_SESSION['user_id'];
        $user_name  = $_SESSION['user_name']  ?? null;
        $user_email = $_SESSION['user_email'] ?? null;
        $user_type  = isset($_SESSION['user_type']) ? (int)$_SESSION['user_type'] : null;
        $estab_id   = isset($_SESSION['estabelecimento_id']) ? (int)$_SESSION['estabelecimento_id'] : null;

        $estab_nome = null;
        if ($estab_id) {
            try {
                $s = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ? LIMIT 1");
                $s->execute([$estab_id]);
                $estab_nome = $s->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }

        self::inserir($conn, 'logout', $user_id, $user_name, $user_email, $user_type,
                      $estab_id, $estab_nome, self::getPagina(),
                      "Logout: {$user_email}");
    }

    /**
     * Registra troca de estabelecimento ativo.
     */
    public static function trocaEstabelecimento($conn, int $novo_estab_id, ?string $novo_estab_nome = null): void
    {
        if (!isset($_SESSION['user_id'])) return;

        $user_id    = (int)$_SESSION['user_id'];
        $user_name  = $_SESSION['user_name']  ?? null;
        $user_email = $_SESSION['user_email'] ?? null;
        $user_type  = isset($_SESSION['user_type']) ? (int)$_SESSION['user_type'] : null;

        if (!$novo_estab_nome) {
            try {
                $s = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ? LIMIT 1");
                $s->execute([$novo_estab_id]);
                $novo_estab_nome = $s->fetchColumn() ?: "#{$novo_estab_id}";
            } catch (Exception $e) {
                $novo_estab_nome = "#{$novo_estab_id}";
            }
        }

        $antigo_id = isset($_SESSION['estabelecimento_id']) ? (int)$_SESSION['estabelecimento_id'] : null;
        $desc = "Troca de estabelecimento: #{$antigo_id} → #{$novo_estab_id} ({$novo_estab_nome})";

        self::inserir($conn, 'troca_estabelecimento', $user_id, $user_name, $user_email, $user_type,
                      $novo_estab_id, $novo_estab_nome, self::getPagina(), $desc);
    }

    /**
     * Registra acesso a uma página administrativa.
     * Chamado no topo de páginas sensíveis.
     */
    public static function pagina($conn, ?string $pagina = null): void
    {
        if (!isset($_SESSION['user_id'])) return;

        $user_id    = (int)$_SESSION['user_id'];
        $user_name  = $_SESSION['user_name']  ?? null;
        $user_email = $_SESSION['user_email'] ?? null;
        $user_type  = isset($_SESSION['user_type']) ? (int)$_SESSION['user_type'] : null;
        $estab_id   = isset($_SESSION['estabelecimento_id']) ? (int)$_SESSION['estabelecimento_id'] : null;

        $estab_nome = null;
        if ($estab_id) {
            try {
                $s = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ? LIMIT 1");
                $s->execute([$estab_id]);
                $estab_nome = $s->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }

        $pag = $pagina ?? self::getPagina();
        self::inserir($conn, 'acesso_pagina', $user_id, $user_name, $user_email, $user_type,
                      $estab_id, $estab_nome, $pag, "Acesso: {$pag}");
    }

    /**
     * Registra uma ação crítica (criar, editar, excluir, exportar).
     *
     * @param mixed  $conn
     * @param string $evento   'criar'|'editar'|'excluir'|'exportar'|'visualizar_relatorio'
     * @param string $descricao Descrição legível da ação
     * @param string|null $pagina Página onde ocorreu (null = página atual)
     */
    public static function acao($conn, string $evento, string $descricao, ?string $pagina = null): void
    {
        if (!isset($_SESSION['user_id'])) return;

        $eventos_validos = ['criar','editar','excluir','exportar','visualizar_relatorio'];
        if (!in_array($evento, $eventos_validos)) $evento = 'editar';

        $user_id    = (int)$_SESSION['user_id'];
        $user_name  = $_SESSION['user_name']  ?? null;
        $user_email = $_SESSION['user_email'] ?? null;
        $user_type  = isset($_SESSION['user_type']) ? (int)$_SESSION['user_type'] : null;
        $estab_id   = isset($_SESSION['estabelecimento_id']) ? (int)$_SESSION['estabelecimento_id'] : null;

        $estab_nome = null;
        if ($estab_id) {
            try {
                $s = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ? LIMIT 1");
                $s->execute([$estab_id]);
                $estab_nome = $s->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }

        self::inserir($conn, $evento, $user_id, $user_name, $user_email, $user_type,
                      $estab_id, $estab_nome, $pagina ?? self::getPagina(), $descricao);
    }
}
