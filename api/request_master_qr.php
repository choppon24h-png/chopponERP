<?php
/**
 * request_master_qr.php — Endpoint para o Android gerenciar tokens QR Master
 *
 * v2.0.0 — Correção do corpo vazio (HTTP 200) + alinhamento com ApiHelper.java
 *
 * PROBLEMA CORRIGIDO:
 *   O arquivo anterior lia o JWT via $_SERVER['HTTP_TOKEN'], que falha em
 *   servidores Apache/Nginx com mod_rewrite. O ApiHelper.java envia o header
 *   como 'token' (minúsculo), que só é lido corretamente via getallheaders().
 *   Resultado: jwtValidate('') retornava false, o PHP saía no exit(401) mas
 *   o ob_start/ob_end_flush não estava configurado, causando corpo vazio com HTTP 200
 *   dependendo da configuração do servidor.
 *
 * NOVA LÓGICA (lógica invertida):
 *   - Android GERA o QR Code e exibe na tela
 *   - Admin no ERP escaneia o QR Code do tablet e aprova
 *   - Android faz polling para saber se foi aprovado
 *
 * Ações:
 *   action=generate  → Android solicita um novo token e recebe o QR data
 *                       Retorna: {"success":true,"token_id":42,"qr_data":"CHOPPON_MASTER:<hex>","expires_at":"..."}
 *   action=poll      → Android verifica se o token foi aprovado
 *                       Retorna: {"success":true,"status":"pending|approved|rejected|expired","user_name":"...","user_type":3}
 *
 * Campos POST:
 *   - action    : "generate" ou "poll"   (obrigatório)
 *   - device_id : android_id do tablet   (obrigatório em generate e poll)
 *   - token_id  : id retornado em generate (obrigatório em poll)
 *
 * Autenticação: JWT via header 'token' (padrão ApiHelper.java)
 */

// Buffer de saída: garante que NADA vaze antes do json_encode
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Proteção global: garante JSON válido mesmo em erro fatal
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
            'message' => 'Erro interno: ' . $error['message'],
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
    }
});

require_once '../includes/config.php';
require_once '../includes/jwt.php';

// ── Autenticação JWT ──────────────────────────────────────────────────────────
// CORREÇÃO: usar getallheaders() igual ao verify_checkout.php e create_order.php
// $_SERVER['HTTP_TOKEN'] falha em Apache com AllowEncodedSlashes ou mod_rewrite
$headers   = getallheaders();
$jwt_token = $headers['token'] ?? $headers['Token'] ?? $headers['Authorization'] ?? '';
// Suportar "Bearer <token>" além de token direto
$jwt_token = preg_replace('/^Bearer\s+/i', '', $jwt_token);

if (empty($jwt_token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Token JWT ausente.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

if (!jwtValidate($jwt_token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
// Suporta tanto application/x-www-form-urlencoded (ApiHelper.java) quanto JSON
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'application/json') !== false) {
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
    echo json_encode(['success' => false, 'message' => 'Parâmetro action é obrigatório.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Conexão com banco ─────────────────────────────────────────────────────────
$conn = getDBConnection();

// ── Garantir tabela master_qr_tokens com schema completo ─────────────────────
$conn->exec("
    CREATE TABLE IF NOT EXISTS master_qr_tokens (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        token            VARCHAR(64)  NOT NULL UNIQUE,
        device_id        VARCHAR(128) NOT NULL,
        status           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at       DATETIME     NOT NULL,
        approved_by      INT          NULL,
        approved_user_id INT          NULL,
        approved_name    VARCHAR(255) NULL,
        approved_type    TINYINT      NULL,
        used_at          DATETIME     NULL,
        INDEX idx_token   (token),
        INDEX idx_device  (device_id),
        INDEX idx_status  (status),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Adicionar colunas novas caso a tabela já exista com schema antigo
$alterations = [
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending' AFTER device_id",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER expires_at",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_user_id INT NULL AFTER approved_by",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_name VARCHAR(255) NULL AFTER approved_user_id",
    "ALTER TABLE master_qr_tokens ADD COLUMN IF NOT EXISTS approved_type TINYINT NULL AFTER approved_name",
];
foreach ($alterations as $sql) {
    try { $conn->exec($sql); } catch (Exception $e) { /* coluna já existe */ }
}

// ── ACTION: generate ──────────────────────────────────────────────────────────
if ($action === 'generate') {
    if (empty($device_id)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Parâmetro device_id é obrigatório.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Rate limiting: máx 5 tokens por device por minuto
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM master_qr_tokens
        WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$device_id]);
    if ((int)$stmt->fetchColumn() >= 5) {
        http_response_code(429);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 minuto.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Expirar tokens antigos deste device
    $conn->prepare("
        UPDATE master_qr_tokens
        SET status = 'expired'
        WHERE device_id = ? AND status = 'pending' AND expires_at <= NOW()
    ")->execute([$device_id]);

    // Gerar token de 64 chars hex (256 bits de entropia)
    $raw_token = bin2hex(random_bytes(32));
    $qr_data   = 'CHOPPON_MASTER:' . $raw_token;
    $expires   = date('Y-m-d H:i:s', time() + 300); // 5 minutos

    $stmt = $conn->prepare("
        INSERT INTO master_qr_tokens (token, device_id, status, expires_at)
        VALUES (?, ?, 'pending', ?)
    ");
    $stmt->execute([$raw_token, $device_id, $expires]);
    $new_token_id = (int)$conn->lastInsertId();

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

// ── ACTION: poll ──────────────────────────────────────────────────────────────
if ($action === 'poll') {
    if ($token_id <= 0) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Parâmetro token_id é obrigatório.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    // Expirar automaticamente se passou do prazo
    $conn->prepare("
        UPDATE master_qr_tokens
        SET status = 'expired'
        WHERE id = ? AND status = 'pending' AND expires_at < NOW()
    ")->execute([$token_id]);

    $stmt = $conn->prepare("
        SELECT status, expires_at, approved_name, approved_type
        FROM master_qr_tokens
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$token_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'status' => 'not_found', 'message' => 'Token não encontrado.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    $response = ['success' => true, 'status' => $row['status']];

    if ($row['status'] === 'approved') {
        $response['user_name'] = $row['approved_name'] ?? 'Técnico';
        $response['user_type'] = (int)($row['approved_type'] ?? 3);
        // Marcar como usado para evitar reuso
        $conn->prepare("UPDATE master_qr_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL")
             ->execute([$token_id]);
    }

    http_response_code(200);
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// ── Ação desconhecida ─────────────────────────────────────────────────────────
http_response_code(400);
ob_clean();
echo json_encode([
    'success' => false,
    'message' => "Ação '$action' desconhecida. Use 'generate' ou 'poll'.",
], JSON_UNESCAPED_UNICODE);
ob_end_flush();
