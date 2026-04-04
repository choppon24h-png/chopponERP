<?php
/**
 * API - Verificar TAP
 * POST /api/verify_tap.php
 * Retorna informações da bebida e TAP para o app Android
 *
 * CORREÇÃO v1.1:
 *   - Adicionado campo `tap_status` na resposta JSON (1=ativa, 0=desativada)
 *   - O app Android deve verificar tap_status == 0 e redirecionar para
 *     a tela OFFLINE em vez de voltar para a Home
 *   - Antes, o campo status não era retornado, então o app não sabia
 *     que a TAP havia sido desativada e tratava como erro, voltando para Home
 *   - UPDATE last_call protegido com try/catch (coluna pode não existir no banco)
 *
 * CORREÇÃO v1.2 (2026-04):
 *   - Adicionado campo `esp32_mac` na resposta JSON
 *   - O app Android usa este campo para atualizar o MAC salvo em SharedPreferences
 *     quando o tablet é trocado de máquina ou o ESP32 é substituído.
 *   - Resolve o bug onde o Android ficava em loop tentando conectar ao MAC antigo
 *     gravado em cache, mesmo quando o banco de dados registrava outro MAC.
 *   - Campo retornado como null se não cadastrado (sem quebrar compatibilidade).
 */


// ── Buffer de saída: captura TUDO desde o início ─────────────────────────
// Garante que warnings/notices dos includes não corrompam o JSON de resposta.
ob_start();

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/jwt.php';

$headers = getallheaders();
$token = $headers['token'] ?? $headers['Token'] ?? '';

// Validar token
if (!jwtValidate($token)) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$input = $_POST;
$android_id = $input['android_id'] ?? '';

if (empty($android_id)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'android_id é obrigatório']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT t.*, b.name as bebida_name, b.value, b.image,
           (t.volume - t.volume_consumido) as volume_atual,
           t.esp32_mac
    FROM tap t
    INNER JOIN bebidas b ON t.bebida_id = b.id
    WHERE t.android_id = ?
    LIMIT 1
");  /* v1.2: esp32_mac adicionado para sincronização de MAC no Android */
$stmt->execute([$android_id]);
$tap = $stmt->fetch();

if ($tap) {
    $image_url = SITE_URL . '/' . $tap['image'];

    // FIX: Protege o UPDATE com try/catch — coluna last_call pode não existir no banco
    try {
        $stmtUpdate = $conn->prepare("UPDATE tap SET last_call = now() WHERE id = ?");
        $stmtUpdate->execute([$tap['id']]);
    } catch (Throwable $e) {
        Logger::debug("verify_tap", "last_call update ignorado: " . $e->getMessage());
    }

    http_response_code(200);
    ob_clean();
    // v1.2 FIX: esp32_mac retornado para o Android sincronizar o MAC salvo em cache.
    // Se o campo não existir no banco (banco antigo), retorna null sem quebrar.
    $esp32_mac_val = isset($tap['esp32_mac']) && !empty($tap['esp32_mac'])
        ? strtoupper(trim($tap['esp32_mac']))
        : null;
    echo json_encode([
        'image'      => $image_url,
        'preco'      => $tap['value'],
        'bebida'     => $tap['bebida_name'],
        'volume'     => $tap['volume_atual'],
        'cartao'     => !empty($tap['reader_id']),
        'tap_status' => (int) $tap['status'],  // FIX v1.1: 1=ativa, 0=desativada (OFFLINE)
        'esp32_mac'  => $esp32_mac_val,        // FIX v1.2: MAC do ESP32 para sincronização
    ]);
} else {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'TAP não encontrada']);
}
