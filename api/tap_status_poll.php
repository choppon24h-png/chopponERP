<?php
/**
 * tap_status_poll.php — Endpoint leve de polling de status da TAP para o Android.
 *
 * O Android consulta este endpoint a cada N segundos para detectar mudanças
 * de status feitas pelo ERP web (taps.php). Quando o status muda, o Android
 * reage automaticamente:
 *   - status=0 (desativada) → desconecta BLE → navega para OfflineTap
 *   - status=1 (ativada)    → reconecta BLE → navega para Home
 *
 * Método: GET ou POST
 * Parâmetros:
 *   - android_id (string, obrigatório): ID único do dispositivo Android
 *
 * Headers:
 *   - token (string, obrigatório): JWT de autenticação
 *
 * Resposta JSON:
 * {
 *   "success": true,
 *   "tap_id": 5,
 *   "status": 1,           // 0=offline, 1=online
 *   "status_label": "online",
 *   "bebida": "Chopp Pilsen",
 *   "esp32_mac": "AA:BB:CC:DD:EE:FF",
 *   "preco": 3.50,
 *   "image": "https://...",
 *   "cartao": true,
 *   "volume": 50000,
 *   "volume_consumido": 12000,
 *   "volume_critico": 5000,
 *   "vencimento": "2025-12-31"
 * }
 *
 * Erros:
 *   HTTP 401 → JWT inválido
 *   HTTP 400 → android_id ausente
 *   HTTP 404 → TAP não encontrada para este android_id
 *   HTTP 500 → erro interno
 */

ob_start();

require_once '../includes/config.php';
require_once '../includes/jwt.php';

ob_clean();

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: token, Token, Content-Type, Authorization');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_flush();
    exit;
}

$TAG      = 'TAP_STATUS_POLL';
$response = ['success' => false, 'error' => 'Erro desconhecido'];
$httpCode = 500;

try {
    // ── Validação JWT ─────────────────────────────────────────────────────────
    $headers    = getallheaders();
    $token      = $headers['token'] ?? $headers['Token'] ?? $headers['Authorization'] ?? '';
    // Suporte a Bearer token
    if (stripos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    $jwtPayload = jwtValidate($token);
    if (!$jwtPayload) {
        Logger::warning($TAG, "JWT inválido ou expirado");
        $response = ['success' => false, 'error' => 'Token inválido ou expirado'];
        $httpCode = 401;
        throw new RuntimeException('jwt_invalid');
    }

    // ── Parâmetros ────────────────────────────────────────────────────────────
    // Aceita GET ou POST
    $android_id = trim(
        $_POST['android_id'] ?? $_GET['android_id'] ?? ''
    );

    Logger::debug($TAG, "Polling | android_id=$android_id");

    if (empty($android_id)) {
        $response = [
            'success' => false,
            'error'   => 'Parâmetro android_id é obrigatório.'
        ];
        $httpCode = 400;
        throw new RuntimeException('missing_android_id');
    }

    // ── Banco de Dados ────────────────────────────────────────────────────────
    $conn = getDBConnection();

    // ── Query principal ───────────────────────────────────────────────────────
    // COLUNAS OPCIONAIS: esp32_mac e last_call foram adicionadas via migration
    // (migration_add_tap_esp32_fields.sql). Se a migration nao foi executada
    // no servidor, a coluna nao existe e a query falharia com HTTP 500.
    // Solucao: verificar quais colunas existem antes de montar a query.
    //
    // COLUNA INEXISTENTE: t.cartao nao existe na tabela tap.
    // O verify_tap.php deriva 'cartao' de !empty(reader_id) — fazemos o mesmo.

    $colsResult = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tap'
            AND COLUMN_NAME  IN ('esp32_mac','last_call','senha')"
    );
    $existingCols = $colsResult->fetchAll(PDO::FETCH_COLUMN);
    $hasEsp32Mac  = in_array('esp32_mac',  $existingCols);
    $hasLastCall  = in_array('last_call',  $existingCols);

    $selectEsp32  = $hasEsp32Mac ? 't.esp32_mac' : "'' AS esp32_mac";
    $selectLast   = $hasLastCall ? 't.last_call'  : 'NULL AS last_call';

    Logger::debug($TAG, "Colunas opcionais: esp32_mac=" . ($hasEsp32Mac?'sim':'nao')
                      . " | last_call=" . ($hasLastCall?'sim':'nao'));

    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.status,
            t.volume,
            t.volume_consumido,
            t.volume_critico,
            t.vencimento,
            t.reader_id,
            $selectEsp32,
            $selectLast,
            b.name  AS bebida,
            b.image AS image,
            b.value AS preco,
            b.promotional_value AS preco_promocional
        FROM tap t
        INNER JOIN bebidas b ON t.bebida_id = b.id
        WHERE t.android_id = ?
        LIMIT 1
    ");
    $stmt->execute([$android_id]);
    $tap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tap) {
        Logger::warning($TAG, "TAP nao encontrada para android_id=$android_id");
        $response = [
            'success' => false,
            'error'   => 'TAP nao encontrada para este dispositivo.'
        ];
        $httpCode = 404;
        throw new RuntimeException('tap_not_found');
    }

    // ── Atualiza last_call para rastreamento (somente se coluna existir) ───────
    if ($hasLastCall) {
        $stmtUpdate = $conn->prepare(
            "UPDATE tap SET last_call = NOW() WHERE id = ?"
        );
        $stmtUpdate->execute([$tap['id']]);
    }

    $statusInt   = (int) $tap['status'];
    $statusLabel = $statusInt === 1 ? 'online' : 'offline';
    // cartao: derivado de reader_id (igual ao verify_tap.php)
    $temCartao   = !empty($tap['reader_id']);
    $esp32Mac    = $tap['esp32_mac'] ?? '';

    Logger::debug($TAG,
        "TAP id={$tap['id']} | status=$statusLabel | bebida={$tap['bebida']} | mac=$esp32Mac"
    );

    // ── Resposta ──────────────────────────────────────────────────────────────
    $response = [
        'success'          => true,
        'tap_id'           => (int) $tap['id'],
        'status'           => $statusInt,
        'status_label'     => $statusLabel,
        'bebida'           => $tap['bebida'],
        'esp32_mac'        => $esp32Mac,
        'preco'            => (float) ($tap['preco'] ?? 0),
        'image'            => $tap['image'] ?? '',
        'cartao'           => $temCartao,
        'volume'           => (int) $tap['volume'],
        'volume_consumido' => (int) $tap['volume_consumido'],
        'volume_critico'   => (int) $tap['volume_critico'],
        'vencimento'       => $tap['vencimento'] ?? '',
        'last_call'        => date('Y-m-d H:i:s'),
    ];
    $httpCode = 200;

} catch (RuntimeException $e) {
    Logger::debug($TAG, "Fluxo encerrado: " . $e->getMessage());
} catch (Throwable $e) {
    Logger::error($TAG, "Erro inesperado: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
    $response = [
        'success' => false,
        'error'   => defined('DEBUG_MODE') && DEBUG_MODE
                        ? 'Erro interno: ' . $e->getMessage()
                        : 'Erro interno ao processar a solicitação.'
    ];
    $httpCode = 500;
}

// ── Resposta final ────────────────────────────────────────────────────────────
ob_clean();
http_response_code($httpCode);
$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
Logger::debug($TAG, "Resposta (HTTP $httpCode): $json");
echo $json;
ob_end_flush();
