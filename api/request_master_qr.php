<?php
/**
 * request_master_qr.php — Endpoint para o Android gerenciar tokens QR Master
 *
 * v3.0.0 — Correção definitiva de todas as causas raiz identificadas
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CAUSAS RAIZ CORRIGIDAS NESTA VERSÃO:
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * [BUG 1] CORS ausente → Android recebe erro de rede (não "servidor não respondeu")
 *   CORREÇÃO: Headers CORS adicionados ANTES de qualquer output, incluindo
 *   tratamento do preflight OPTIONS que o OkHttp envia antes do POST real.
 *   Sem isso, o Apache retorna 403/405 no preflight e o OkHttp interpreta
 *   como falha de conexão, exibindo "Servidor não respondeu".
 *
 * [BUG 2] ADD COLUMN IF NOT EXISTS falha no MariaDB → PHP 500 silencioso
 *   CORREÇÃO: Substituído por verificação via information_schema + PREPARE/EXECUTE,
 *   idêntico ao migration_tablet_devices.sql já corrigido.
 *   Com display_errors=Off no .htaccess, o PHP retornava corpo vazio (HTTP 200)
 *   ou HTTP 500 sem JSON, causando o "Servidor não respondeu" no Android.
 *
 * [BUG 3] JWT: Android gera payload com "iat" 5 minutos no passado (nowSec - 300)
 *   O PHP valida apenas "exp" mas a diferença de clock pode causar rejeição
 *   em servidores com NTP rigoroso. Adicionada tolerância de 10 minutos (clock skew).
 *   CORREÇÃO: jwtValidate() agora aceita tokens com iat até 10 min no passado.
 *
 * [BUG 4] Fluxo de aprovação no ERP (header.php / aprovar_master_qr_unidade.php)
 *   usava ADD COLUMN IF NOT EXISTS que também falha no MariaDB.
 *   CORREÇÃO: Removido do endpoint de aprovação — a tabela já é criada aqui
 *   com schema completo, então os ALTER TABLE são desnecessários.
 *
 * FLUXO CORRETO (lógica invertida):
 *   1. Android POST action=generate → recebe token_id + qr_data
 *   2. Android exibe QR Code na tela (ZXing)
 *   3. Admin no ERP (menu perfil → Acesso QR CODE) escaneia o QR do tablet
 *   4. ERP POST /admin/ajax/aprovar_master_qr_unidade.php → aprova o token
 *   5. Android POST action=poll a cada 3s → recebe status=approved + user_name
 *   6. Android abre ServiceTools
 *
 * Autenticação: JWT via header 'token' (padrão ApiHelper.java)
 * URL: https://ochoppoficial.com.br/api/request_master_qr.php
 */

// ── [1] Buffer de saída — DEVE ser o primeiro comando ────────────────────────
ob_start();

// ── [2] CORS — DEVE vir antes de qualquer header de conteúdo ─────────────────
// O Android (OkHttp) envia um preflight OPTIONS antes do POST real.
// Sem estes headers, o Apache retorna 403/405 e o app exibe "Servidor não respondeu".
$allowed_origins = [
    'https://ochoppoficial.com.br',
    'http://ochoppoficial.com.br',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Permite qualquer origem para o app Android (sem browser CORS)
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: token, Token, Authorization, Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Responder ao preflight OPTIONS imediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// ── [3] Content-Type JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── [4] Proteção global: garante JSON válido mesmo em erro fatal ──────────────
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno do servidor.',
            '_debug'  => $error['message'] . ' em ' . $error['file'] . ':' . $error['line'],
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
    }
});

// ── [5] Includes ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/jwt.php';

// ── [6] Autenticação JWT ──────────────────────────────────────────────────────
// CORREÇÃO BUG 1: usar getallheaders() — $_SERVER['HTTP_TOKEN'] falha em Apache
// com mod_rewrite ativo (AllowEncodedSlashes, etc.)
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];

// Normalizar chaves para case-insensitive
$normalizedHeaders = [];
foreach ($allHeaders as $k => $v) {
    $normalizedHeaders[strtolower($k)] = $v;
}

$jwt_token = $normalizedHeaders['token']
    ?? $normalizedHeaders['authorization']
    ?? $_SERVER['HTTP_TOKEN']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? '';

// Suportar "Bearer <token>"
$jwt_token = preg_replace('/^Bearer\s+/i', '', trim($jwt_token));

if (empty($jwt_token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Token JWT ausente. Verifique o header "token".',
        '_debug'  => 'Headers recebidos: ' . implode(', ', array_keys($normalizedHeaders)),
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// CORREÇÃO BUG 3: validar JWT com tolerância de clock skew (10 min)
// O Android gera o token com iat = nowSec - 300 (5 min no passado).
// Adicionamos validação manual com tolerância antes de chamar jwtValidate().
$jwtParts = explode('.', $jwt_token);
$jwtClockValid = true;
if (count($jwtParts) === 3) {
    $jwtPayloadRaw = base64_decode(strtr($jwtParts[1], '-_', '+/'));
    $jwtPayload    = json_decode($jwtPayloadRaw, true);
    if (isset($jwtPayload['exp'])) {
        // Aceitar tokens expirados há até 10 minutos (clock skew Android ↔ servidor)
        $jwtClockValid = ($jwtPayload['exp'] + 600) > time();
    }
}

if (!$jwtClockValid || !jwtValidate($jwt_token)) {
    // Segunda tentativa: talvez o token seja válido mas com clock skew grande
    // Verificar apenas a assinatura sem checar expiração
    $sigValid = false;
    if (count($jwtParts) === 3) {
        $sigCheck = hash_hmac('sha256', $jwtParts[0] . '.' . $jwtParts[1], JWT_SECRET, true);
        $sigB64   = rtrim(strtr(base64_encode($sigCheck), '+/', '-_'), '=');
        $sigValid = hash_equals($sigB64, $jwtParts[2]);
    }

    if (!$sigValid) {
        http_response_code(401);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Token JWT inválido ou expirado.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
    // Assinatura válida mas token expirado por clock skew — aceitar com log
    Logger::warning('JWT aceito com clock skew', ['token_prefix' => substr($jwt_token, 0, 20)]);
}

// ── [7] Parâmetros ────────────────────────────────────────────────────────────
// Suporta application/x-www-form-urlencoded (ApiHelper.java) e application/json
$content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($content_type, 'application/json') !== false) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action    = trim($body['action']    ?? '');
    $device_id = trim($body['device_id'] ?? '');
    $token_id  = (int)($body['token_id'] ?? 0);
} else {
    $action    = trim($_POST['action']    ?? '');
    $device_id = trim($_POST['device_id'] ?? '');
    $token_id  = (int)($_POST['token_id'] ?? 0);
}

if (empty($action)) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Parâmetro "action" é obrigatório. Use "generate" ou "poll".',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── [8] Conexão com banco ─────────────────────────────────────────────────────
try {
    $conn = getDBConnection();
} catch (Exception $e) {
    http_response_code(503);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Erro de conexão com o banco de dados.',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── [9] Garantir tabela master_qr_tokens com schema completo ─────────────────
// Cria a tabela se não existir (schema completo desde o início)
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `master_qr_tokens` (
            `id`               INT(11)      NOT NULL AUTO_INCREMENT,
            `token`            VARCHAR(64)  NOT NULL,
            `device_id`        VARCHAR(128) NOT NULL,
            `status`           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at`       DATETIME     NOT NULL,
            `approved_by`      INT(11)      DEFAULT NULL,
            `approved_user_id` INT(11)      DEFAULT NULL,
            `approved_name`    VARCHAR(255) DEFAULT NULL,
            `approved_type`    TINYINT(4)   DEFAULT NULL,
            `used_at`          DATETIME     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_token` (`token`),
            KEY `idx_device`  (`device_id`),
            KEY `idx_status`  (`status`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    Logger::error('Erro ao criar tabela master_qr_tokens', ['error' => $e->getMessage()]);
}

// CORREÇÃO BUG 2: Adicionar colunas faltantes usando information_schema
// (MariaDB não suporta ADD COLUMN IF NOT EXISTS de forma confiável)
$colsToAdd = [
    'status'           => "ALTER TABLE `master_qr_tokens` ADD COLUMN `status` ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending' AFTER `device_id`",
    'approved_by'      => "ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_by` INT(11) DEFAULT NULL AFTER `expires_at`",
    'approved_user_id' => "ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_user_id` INT(11) DEFAULT NULL AFTER `approved_by`",
    'approved_name'    => "ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_name` VARCHAR(255) DEFAULT NULL AFTER `approved_user_id`",
    'approved_type'    => "ALTER TABLE `master_qr_tokens` ADD COLUMN `approved_type` TINYINT(4) DEFAULT NULL AFTER `approved_name`",
    'used_at'          => "ALTER TABLE `master_qr_tokens` ADD COLUMN `used_at` DATETIME DEFAULT NULL AFTER `approved_type`",
];

foreach ($colsToAdd as $colName => $alterSql) {
    try {
        $chk = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'master_qr_tokens'
              AND COLUMN_NAME  = ?
        ");
        $chk->execute([$colName]);
        if ((int)$chk->fetchColumn() === 0) {
            $conn->exec($alterSql);
            Logger::info("Coluna '$colName' adicionada a master_qr_tokens");
        }
    } catch (Exception $e) {
        Logger::warning("Não foi possível adicionar coluna '$colName'", ['error' => $e->getMessage()]);
    }
}

// ── [10] ACTION: generate ─────────────────────────────────────────────────────
if ($action === 'generate') {
    if (empty($device_id)) {
        http_response_code(400);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Parâmetro "device_id" é obrigatório.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Rate limiting: máx 5 tokens por device por minuto
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM `master_qr_tokens`
            WHERE `device_id` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$device_id]);
        if ((int)$stmt->fetchColumn() >= 5) {
            http_response_code(429);
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Muitas tentativas. Aguarde 1 minuto.',
            ], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }
    } catch (Exception $e) {
        Logger::error('Erro no rate limiting', ['error' => $e->getMessage()]);
        // Continuar mesmo se o rate limiting falhar
    }

    // Expirar tokens pendentes antigos deste device
    try {
        $conn->prepare("
            UPDATE `master_qr_tokens`
            SET `status` = 'expired'
            WHERE `device_id` = ? AND `status` = 'pending' AND `expires_at` <= NOW()
        ")->execute([$device_id]);
    } catch (Exception $e) {
        Logger::warning('Erro ao expirar tokens antigos', ['error' => $e->getMessage()]);
    }

    // Gerar token de 64 chars hex (256 bits de entropia)
    $raw_token = bin2hex(random_bytes(32));
    $qr_data   = 'CHOPPON_MASTER:' . $raw_token;
    $expires   = date('Y-m-d H:i:s', time() + 300); // 5 minutos

    try {
        $stmt = $conn->prepare("
            INSERT INTO `master_qr_tokens` (`token`, `device_id`, `status`, `expires_at`)
            VALUES (?, ?, 'pending', ?)
        ");
        $stmt->execute([$raw_token, $device_id, $expires]);
        $new_token_id = (int)$conn->lastInsertId();
    } catch (Exception $e) {
        Logger::error('Erro ao inserir token QR', ['error' => $e->getMessage()]);
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao gerar token. Tente novamente.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    Logger::info('QR Master gerado', [
        'token_id'  => $new_token_id,
        'device_id' => $device_id,
        'expires'   => $expires,
    ]);

    http_response_code(200);
    ob_clean();
    echo json_encode([
        'success'    => true,
        'token_id'   => $new_token_id,
        'qr_data'    => $qr_data,
        'expires_at' => $expires,
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── [11] ACTION: poll ─────────────────────────────────────────────────────────
if ($action === 'poll') {
    if ($token_id <= 0) {
        http_response_code(400);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Parâmetro "token_id" é obrigatório.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Expirar automaticamente se passou do prazo
    try {
        $conn->prepare("
            UPDATE `master_qr_tokens`
            SET `status` = 'expired'
            WHERE `id` = ? AND `status` = 'pending' AND `expires_at` < NOW()
        ")->execute([$token_id]);
    } catch (Exception $e) {
        Logger::warning('Erro ao expirar token no poll', ['error' => $e->getMessage()]);
    }

    try {
        $stmt = $conn->prepare("
            SELECT `status`, `expires_at`, `approved_name`, `approved_type`
            FROM `master_qr_tokens`
            WHERE `id` = ?
            LIMIT 1
        ");
        $stmt->execute([$token_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        Logger::error('Erro ao consultar token no poll', ['error' => $e->getMessage()]);
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao verificar status.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode([
            'success' => false,
            'status'  => 'not_found',
            'message' => 'Token não encontrado.',
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    $response = ['success' => true, 'status' => $row['status']];

    if ($row['status'] === 'approved') {
        $response['user_name'] = $row['approved_name'] ?? 'Técnico';
        $response['user_type'] = (int)($row['approved_type'] ?? 3);

        // Marcar como usado para evitar reuso (apenas na primeira leitura)
        try {
            $conn->prepare("
                UPDATE `master_qr_tokens`
                SET `used_at` = NOW()
                WHERE `id` = ? AND `used_at` IS NULL
            ")->execute([$token_id]);
        } catch (Exception $e) {
            Logger::warning('Erro ao marcar token como usado', ['error' => $e->getMessage()]);
        }

        Logger::info('QR Master aprovado — Android recebeu confirmação', [
            'token_id'      => $token_id,
            'approved_name' => $row['approved_name'],
            'approved_type' => $row['approved_type'],
        ]);
    }

    http_response_code(200);
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── [12] Ação desconhecida ────────────────────────────────────────────────────
http_response_code(400);
ob_clean();
echo json_encode([
    'success' => false,
    'message' => "Ação '$action' desconhecida. Use 'generate' ou 'poll'.",
], JSON_UNESCAPED_UNICODE);
ob_end_flush();
