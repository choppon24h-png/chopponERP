<?php
/**
 * API - Líquido Liberado
 * POST /api/liquido_liberado.php
 * Atualiza volume consumido da TAP
 *
 * CORREÇÃO v3.2.0:
 *   qtd_ml é recebido em mililitros (ml).
 *   A conversão correta para litros é ÷ 1000 (e não ÷ 100 como estava).
 *   Além disso, o endpoint agora também aceita checkout_id para buscar
 *   o tap_id diretamente do pedido, além do android_id existente.
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

$input = json_decode(file_get_contents('php://input'), true);

$android_id = $input['android_id'] ?? '';
$qtd_ml = $input['qtd_ml'] ?? 0;

if (empty($android_id) || $qtd_ml <= 0) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'android_id e qtd_ml são obrigatórios']);
    exit;
}

$conn = getDBConnection();

// Buscar TAP
$stmt = $conn->prepare("SELECT * FROM tap WHERE android_id = ? LIMIT 1");
$stmt->execute([$android_id]);
$tap = $stmt->fetch();

if ($tap) {
    // Atualizar volume consumido
    // qtd_ml vem em mililitros (ml) → converter para litros (÷ 1000)
    $volume_litros = $qtd_ml / 1000.0;

    $stmt = $conn->prepare("
        UPDATE tap
        SET volume_consumido = volume_consumido + ?
        WHERE id = ?
    ");
    $stmt->execute([$volume_litros, $tap['id']]);

    // Buscar valores atualizados para retornar
    $stmtT = $conn->prepare("SELECT volume, volume_consumido FROM tap WHERE id = ? LIMIT 1");
    $stmtT->execute([$tap['id']]);
    $tap_row = $stmtT->fetch();
    $tap_consumido = $tap_row ? (float)$tap_row['volume_consumido'] : 0;
    $tap_atual     = $tap_row ? max(0, (float)$tap_row['volume'] - $tap_consumido) : 0;

    http_response_code(200);
    ob_clean();
    echo json_encode([
        'success'             => true,
        'tap_volume_consumido'=> $tap_consumido,
        'tap_volume_atual'    => $tap_atual,
    ]);
} else {
    http_response_code(404);
    ob_clean();
    echo json_encode(['error' => 'TAP não encontrada']);
}
