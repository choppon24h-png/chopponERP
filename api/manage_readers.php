<?php
/**
 * API de Gerenciamento de Leitoras SumUp Solo
 * 
 * Ações disponíveis:
 *   POST action=create  → Cria/vincula uma leitora via pairing_code
 *   POST action=test    → Testa a comunicação com uma leitora já cadastrada
 *   POST action=delete  → Exclui uma leitora da SumUp e do banco
 *   GET  action=list    → Lista todas as leitoras cadastradas com status atualizado
 */
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Verificar autenticação
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$conn          = getDBConnection();
$sumup_token   = SUMUP_TOKEN;
$merchant_code = SUMUP_MERCHANT_CODE;
$action        = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Garantir que a tabela sumup_readers existe
$conn->exec("CREATE TABLE IF NOT EXISTS `sumup_readers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reader_id` VARCHAR(60) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `serial` VARCHAR(100) NULL DEFAULT NULL,
    `model` VARCHAR(50) NULL DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'processing',
    `battery_level` INT NULL DEFAULT NULL,
    `connection_type` VARCHAR(30) NULL DEFAULT NULL,
    `firmware_version` VARCHAR(50) NULL DEFAULT NULL,
    `last_activity` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reader_id_unique` (`reader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─────────────────────────────────────────────────────────────
// Helper: chamada à API SumUp
// ─────────────────────────────────────────────────────────────
function sumupRequest(string $method, string $endpoint, array $body = []): array {
    global $sumup_token, $merchant_code;
    $url = "https://api.sumup.com/v0.1/merchants/{$merchant_code}/{$endpoint}";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$sumup_token}",
        "Content-Type: application/json",
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => $code,
        'data'      => $resp ? json_decode($resp, true) : null,
        'raw'       => $resp,
        'curl_error'=> $err,
    ];
}

// ─────────────────────────────────────────────────────────────
// Helper: buscar status do reader na SumUp
// ─────────────────────────────────────────────────────────────
function getReaderStatus(string $reader_id): array {
    global $sumup_token, $merchant_code;
    $url = "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/status";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = ($code === 200 && $resp) ? (json_decode($resp, true)['data'] ?? []) : [];
    return [
        'online'        => strtolower($data['status'] ?? 'offline') === 'online',
        'status_label'  => strtolower($data['status'] ?? 'offline') === 'online' ? 'ONLINE' : 'OFFLINE',
        'battery'       => $data['battery_level'] ?? null,
        'connection'    => $data['connection_type'] ?? null,
        'firmware'      => $data['firmware_version'] ?? null,
        'last_activity' => $data['last_activity'] ?? null,
    ];
}

// ─────────────────────────────────────────────────────────────
// ACTION: list
// ─────────────────────────────────────────────────────────────
if ($action === 'list') {
    $stmt    = $conn->query("SELECT * FROM sumup_readers ORDER BY created_at DESC");
    $readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Atualizar status de cada reader
    foreach ($readers as &$r) {
        $st = getReaderStatus($r['reader_id']);
        $r['online']        = $st['online'];
        $r['status_label']  = $st['status_label'];
        $r['battery']       = $st['battery'];
        $r['connection']    = $st['connection'];
        $r['firmware']      = $st['firmware'];
        $r['last_activity'] = $st['last_activity'];

        // Atualizar banco com dados mais recentes
        $conn->prepare("UPDATE sumup_readers SET battery_level=?, connection_type=?, firmware_version=?, last_activity=?, updated_at=NOW() WHERE reader_id=?")
             ->execute([$st['battery'], $st['connection'], $st['firmware'], $st['last_activity'], $r['reader_id']]);
    }
    unset($r);

    echo json_encode(['success' => true, 'readers' => $readers], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: create — Parear nova leitora via pairing_code
// ─────────────────────────────────────────────────────────────
if ($action === 'create') {
    $pairing_code = trim($_POST['pairing_code'] ?? '');
    $name         = trim($_POST['name'] ?? $pairing_code);

    if (empty($pairing_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Código de pareamento é obrigatório']);
        exit;
    }

    // Validar formato: 8-9 caracteres alfanuméricos
    if (!preg_match('/^[A-Z0-9]{8,9}$/', strtoupper($pairing_code))) {
        http_response_code(400);
        echo json_encode(['error' => 'Código de pareamento inválido. Deve ter 8-9 caracteres alfanuméricos (ex: A4RZALFHY).']);
        exit;
    }

    // Chamar API SumUp para criar o reader
    $result = sumupRequest('POST', 'readers', [
        'pairing_code' => strtoupper($pairing_code),
        'name'         => $name,
    ]);

    if ($result['http_code'] !== 201 && $result['http_code'] !== 200) {
        $err_msg = $result['data']['message'] ?? $result['data']['error'] ?? 'Erro ao parear leitora';
        http_response_code(422);
        echo json_encode([
            'error'     => $err_msg,
            'http_code' => $result['http_code'],
            'detail'    => $result['raw'],
        ]);
        exit;
    }

    $reader = $result['data'];
    $reader_id = $reader['id'] ?? '';
    $serial    = $reader['device']['identifier'] ?? null;
    $model     = $reader['device']['model'] ?? null;
    $status    = $reader['status'] ?? 'processing';

    // Salvar no banco
    $stmt = $conn->prepare("
        INSERT INTO sumup_readers (reader_id, name, serial, model, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), serial=VALUES(serial), model=VALUES(model), status=VALUES(status), updated_at=NOW()
    ");
    $stmt->execute([$reader_id, $name, $serial, $model, $status]);

    // Buscar status atual
    $st = getReaderStatus($reader_id);

    echo json_encode([
        'success'    => true,
        'reader_id'  => $reader_id,
        'name'       => $name,
        'serial'     => $serial,
        'model'      => $model,
        'status'     => $status,
        'online'     => $st['online'],
        'status_label' => $st['status_label'],
        'battery'    => $st['battery'],
        'connection' => $st['connection'],
        'firmware'   => $st['firmware'],
        'last_activity' => $st['last_activity'],
        'mensagem'   => $status === 'paired'
            ? "Leitora pareada com sucesso! Serial: {$serial}"
            : "Leitora criada. Aguardando confirmação do dispositivo físico (status: {$status}). Ligue o SumUp Solo para completar o pareamento.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: test — Testar comunicação com leitora existente
// ─────────────────────────────────────────────────────────────
if ($action === 'test') {
    $reader_id = trim($_POST['reader_id'] ?? '');

    if (empty($reader_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'reader_id é obrigatório']);
        exit;
    }

    // Buscar dados do reader no banco
    $stmt = $conn->prepare("SELECT * FROM sumup_readers WHERE reader_id = ?");
    $stmt->execute([$reader_id]);
    $reader = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reader) {
        http_response_code(404);
        echo json_encode(['error' => 'Leitora não encontrada no banco de dados']);
        exit;
    }

    // Buscar dados atualizados da SumUp
    $url_reader = "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}";
    $ch = curl_init($url_reader);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    $resp_r = curl_exec($ch);
    $code_r = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code_r !== 200) {
        echo json_encode([
            'success' => false,
            'online'  => false,
            'status_label' => 'ERRO',
            'mensagem' => 'Leitora não encontrada na conta SumUp. Pode ter sido excluída.',
        ]);
        exit;
    }

    $reader_data = json_decode($resp_r, true);
    $sumup_status = $reader_data['status'] ?? 'unknown';

    // Buscar status de conectividade
    $st = getReaderStatus($reader_id);

    // Atualizar banco
    $serial  = $reader_data['device']['identifier'] ?? $reader['serial'];
    $model   = $reader_data['device']['model'] ?? $reader['model'];
    $conn->prepare("UPDATE sumup_readers SET serial=?, model=?, status=?, battery_level=?, connection_type=?, firmware_version=?, last_activity=?, updated_at=NOW() WHERE reader_id=?")
         ->execute([$serial, $model, $sumup_status, $st['battery'], $st['connection'], $st['firmware'], $st['last_activity'], $reader_id]);

    $mensagem = $st['online']
        ? "✅ Leitora ONLINE | Serial: {$serial} | Bateria: {$st['battery']}% | Conexão: {$st['connection']}"
        : ($sumup_status === 'processing'
            ? "⏳ Aguardando pareamento. Ligue o SumUp Solo e confirme o código no dispositivo."
            : ($sumup_status === 'expired'
                ? "❌ Pareamento expirado. Exclua esta leitora e cadastre novamente."
                : "⚠️ Leitora OFFLINE. Verifique se o SumUp Solo está ligado e com internet."));

    echo json_encode([
        'success'       => true,
        'online'        => $st['online'],
        'status_label'  => $st['status_label'],
        'sumup_status'  => $sumup_status,
        'serial'        => $serial,
        'model'         => $model,
        'battery'       => $st['battery'],
        'connection'    => $st['connection'],
        'firmware'      => $st['firmware'],
        'last_activity' => $st['last_activity'],
        'mensagem'      => $mensagem,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: delete — Excluir leitora da SumUp e do banco
// ─────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $reader_id = trim($_POST['reader_id'] ?? '');

    if (empty($reader_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'reader_id é obrigatório']);
        exit;
    }

    // Chamar DELETE na SumUp
    $result = sumupRequest('DELETE', "readers/{$reader_id}");

    // Aceitar 204 (sucesso) ou 404 (já não existe)
    if ($result['http_code'] !== 204 && $result['http_code'] !== 404 && $result['http_code'] !== 200) {
        http_response_code(422);
        echo json_encode([
            'error'     => 'Erro ao excluir leitora na SumUp',
            'http_code' => $result['http_code'],
            'detail'    => $result['raw'],
        ]);
        exit;
    }

    // Remover do banco
    $conn->prepare("DELETE FROM sumup_readers WHERE reader_id = ?")->execute([$reader_id]);

    // Remover reader_id das TAPs vinculadas
    $conn->prepare("UPDATE tap SET reader_id = NULL, pairing_code = NULL WHERE reader_id = ?")->execute([$reader_id]);

    echo json_encode([
        'success'  => true,
        'mensagem' => 'Leitora excluída com sucesso. O SumUp Solo exibirá um novo código de pareamento.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => "Ação '{$action}' inválida"]);
