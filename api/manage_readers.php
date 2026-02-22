<?php
/**
 * API de Gerenciamento de Leitoras SumUp Solo
 * v2.0 — Novas ações: delete_all, update_estab, check_api
 *         Correção: affiliate_key obrigatório no checkout
 *
 * Ações disponíveis:
 *   POST action=create       → Cria/vincula uma leitora via pairing_code
 *   POST action=test         → Testa a comunicação com uma leitora já cadastrada
 *   POST action=delete       → Exclui uma leitora da SumUp e do banco
 *   POST action=delete_all   → Exclui TODAS as leitoras da SumUp e do banco
 *   POST action=update_estab → Vincula/desvincula leitora de um estabelecimento
 *   POST action=check_api    → Verifica se o token SumUp está válido
 *   GET  action=list         → Lista todas as leitoras com status atualizado
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
$action        = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ─── Obter token e merchant_code do banco (prioridade) ou constante ──────────
$stmt_pay = $conn->query("SELECT token_sumup, affiliate_key, merchant_code FROM payment LIMIT 1");
$payment_cfg = $stmt_pay->fetch(PDO::FETCH_ASSOC);

// Token: prioridade ao salvo no banco, fallback à constante
$sumup_token   = !empty($payment_cfg['token_sumup'])   ? $payment_cfg['token_sumup']   : SUMUP_TOKEN;
$affiliate_key = !empty($payment_cfg['affiliate_key']) ? $payment_cfg['affiliate_key'] : '';
// merchant_code: se salvo no banco, usa; caso contrário usa a constante
$merchant_code = !empty($payment_cfg['merchant_code']) ? $payment_cfg['merchant_code'] : SUMUP_MERCHANT_CODE;

function paymentLog(string $message, array $context = []): void {
    if (class_exists('Logger') && method_exists('Logger', 'payment')) {
        Logger::payment($message, $context);
    } else {
        Logger::info($message, $context);
    }
}

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
    `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reader_id_unique` (`reader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─────────────────────────────────────────────────────────────
// Helper: chamada à API SumUp
// ─────────────────────────────────────────────────────────────
function sumupRequest(string $method, string $endpoint, array $body = [], int $timeout = 20): array {
    global $sumup_token, $merchant_code;
    $url = "https://api.sumup.com/v0.1/merchants/{$merchant_code}/{$endpoint}";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$sumup_token}",
        "Content-Type: application/json",
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http_code'  => $code,
        'data'       => $resp ? json_decode($resp, true) : null,
        'raw'        => $resp,
        'curl_error' => $err,
    ];
}

// ─────────────────────────────────────────────────────────────
// Helper: buscar status do reader na SumUp
// Retorna o ÚLTIMO STATUS CONHECIDO (não é em tempo real).
// Para estar ONLINE, o dispositivo deve estar ligado e com
// Connections → API → Connect ativo.
// ─────────────────────────────────────────────────────────────
function getReaderStatus(string $reader_id): array {
    global $sumup_token, $merchant_code;
    $url = "https://api.sumup.com/v0.1/merchants/{$merchant_code}/readers/{$reader_id}/status";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $resp) {
        $decoded = json_decode($resp, true);
        // A SumUp retorna: { "data": { "status": "ONLINE|OFFLINE", ... } }
        $data = $decoded['data'] ?? $decoded ?? [];
        $raw_status = strtoupper($data['status'] ?? 'OFFLINE');
        return [
            'online'        => $raw_status === 'ONLINE',
            'status_label'  => $raw_status,
            'battery'       => $data['battery_level'] ?? null,
            'connection'    => $data['connection_type'] ?? null,
            'firmware'      => $data['firmware_version'] ?? null,
            'last_activity' => $data['last_activity'] ?? null,
            'http_code'     => $code,
        ];
    }

    return [
        'online'        => false,
        'status_label'  => 'OFFLINE',
        'battery'       => null,
        'connection'    => null,
        'firmware'      => null,
        'last_activity' => null,
        'http_code'     => $code,
    ];
}

// ─────────────────────────────────────────────────────────────
// ACTION: check_api — Verificar se o token SumUp está válido
// ─────────────────────────────────────────────────────────────
if ($action === 'check_api') {
    // Probe principal: endpoint usado na integracao de leitoras
    $probe_readers = sumupRequest('GET', 'readers');
    $code = intval($probe_readers['http_code']);
    $data = $probe_readers['data'] ?? [];

    paymentLog('SumUp check_api probe', [
        'http_code' => $code,
        'merchant_code' => $merchant_code,
        'curl_error' => $probe_readers['curl_error'] ?? '',
    ]);

    if ($code === 200) {
        $count = is_array($data) ? count($data) : 0;
        echo json_encode([
            'api_ok'   => true,
            'merchant' => $merchant_code,
            'name'     => '',
            'details'  => "Token e merchant_code validados. Readers visiveis: {$count}.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Probe secundario: valida se o token responde fora do contexto do merchant_code
    $ch = curl_init('https://api.sumup.com/v0.1/checkouts?limit=1');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$sumup_token}"]);
    $resp_checkout = curl_exec($ch);
    $code_checkout = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $err_checkout  = curl_error($ch);
    curl_close($ch);

    $token_ok = ($code_checkout === 200);
    $error_code = 'unknown_error';
    $error_msg  = "Falha ao validar API SumUp (HTTP {$code}).";
    $hint       = 'Verifique token, merchant code e permissoes da API key.';

    if (in_array($code, [401, 403], true)) {
        $error_code = 'auth_error';
        $error_msg  = 'Token invalido, expirado ou sem escopo para Readers API.';
        $hint       = 'Gere nova API key (sup_sk_) e valide permissoes da conta/merchant.';
    } elseif ($code === 404 && $token_ok) {
        $error_code = 'merchant_mismatch';
        $error_msg  = 'Token valido, mas merchant_code nao encontrado para Readers API.';
        $hint       = 'Confirme se o Merchant Code configurado corresponde ao mesmo account da API key.';
    } elseif ($code === 404) {
        $error_code = 'endpoint_or_auth';
        $error_msg  = 'A API respondeu 404 na Readers API.';
        $hint       = 'Pode ser merchant_code incorreto ou token sem acesso ao recurso de leitoras.';
    } elseif (!empty($probe_readers['curl_error'])) {
        $error_code = 'network_error';
        $error_msg  = 'Erro de rede ao consultar SumUp.';
        $hint       = 'Verifique DNS/firewall/SSL e conectividade do servidor.';
    }

    paymentLog('SumUp check_api falhou', [
        'error_code' => $error_code,
        'http_readers' => $code,
        'http_checkouts' => $code_checkout,
        'token_ok' => $token_ok,
        'merchant_code' => $merchant_code,
        'raw_readers' => $probe_readers['raw'] ?? '',
        'raw_checkouts' => $resp_checkout,
        'curl_error_readers' => $probe_readers['curl_error'] ?? '',
        'curl_error_checkouts' => $err_checkout,
    ]);

    echo json_encode([
        'api_ok'         => false,
        'error_code'     => $error_code,
        'error'          => $error_msg . ' ' . $hint . " (Readers HTTP {$code}; Checkouts HTTP {$code_checkout})",
        'hint'           => $hint,
        'http_readers'   => $code,
        'http_checkouts' => $code_checkout,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: list
// ─────────────────────────────────────────────────────────────
if ($action === 'list') {
    $stmt    = $conn->query("
        SELECT sr.*, e.name AS estabelecimento_nome
        FROM sumup_readers sr
        LEFT JOIN estabelecimentos e ON sr.estabelecimento_id = e.id
        ORDER BY sr.created_at DESC
    ");
    $readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($readers as &$r) {
        $st = getReaderStatus($r['reader_id']);
        $r['online']        = $st['online'];
        $r['status_label']  = $st['status_label'];
        $r['battery']       = $st['battery'];
        $r['connection']    = $st['connection'];
        $r['firmware']      = $st['firmware'];
        $r['last_activity'] = $st['last_activity'];

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
    $pairing_code   = strtoupper(trim($_POST['pairing_code'] ?? ''));
    $name           = trim($_POST['name'] ?? $pairing_code);
    $estabelecimento_id = !empty($_POST['estabelecimento_id']) ? intval($_POST['estabelecimento_id']) : null;

    if (empty($pairing_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Código de pareamento é obrigatório']);
        exit;
    }

    if (!preg_match('/^[A-Z0-9]{8,9}$/', $pairing_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Código de pareamento inválido. Deve ter 8-9 caracteres alfanuméricos (ex: A4RZALFHY).']);
        exit;
    }

    // Chamar API SumUp para criar o reader
    $result = sumupRequest('POST', 'readers', [
        'pairing_code' => $pairing_code,
        'name'         => $name,
    ], 30);

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

    $reader    = $result['data'];
    $reader_id = $reader['id'] ?? '';
    $serial    = $reader['device']['identifier'] ?? null;
    $model     = $reader['device']['model'] ?? null;
    $status    = $reader['status'] ?? 'processing';

    // Salvar no banco
    $stmt = $conn->prepare("
        INSERT INTO sumup_readers (reader_id, name, serial, model, status, estabelecimento_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), serial=VALUES(serial), model=VALUES(model),
            status=VALUES(status), estabelecimento_id=VALUES(estabelecimento_id), updated_at=NOW()
    ");
    $stmt->execute([$reader_id, $name, $serial, $model, $status, $estabelecimento_id]);

    // Buscar status atual
    $st = getReaderStatus($reader_id);

    // Mensagem orientativa sobre o status OFFLINE
    if ($st['online']) {
        $mensagem = "✅ Leitora pareada e ONLINE! Serial: {$serial}";
    } elseif ($status === 'paired') {
        $mensagem = "✅ Pareamento confirmado! A leitora está OFFLINE no momento. " .
                    "Para ficar ONLINE: no SumUp Solo → Connections → API → Connect.";
    } elseif ($status === 'processing') {
        $mensagem = "⏳ Aguardando confirmação no dispositivo. Ligue o SumUp Solo e confirme o código na tela.";
    } else {
        $mensagem = "Leitora criada (status: {$status}). Ligue o SumUp Solo para completar o pareamento.";
    }

    echo json_encode([
        'success'      => true,
        'reader_id'    => $reader_id,
        'name'         => $name,
        'serial'       => $serial,
        'model'        => $model,
        'status'       => $status,
        'sumup_status' => $status,
        'online'       => $st['online'],
        'status_label' => $st['status_label'],
        'battery'      => $st['battery'],
        'connection'   => $st['connection'],
        'firmware'     => $st['firmware'],
        'last_activity'=> $st['last_activity'],
        'mensagem'     => $mensagem,
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

    // Buscar dados atualizados da SumUp (GET /readers/{id})
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
            'success'      => false,
            'online'       => false,
            'status_label' => 'ERRO',
            'mensagem'     => 'Leitora não encontrada na conta SumUp (HTTP ' . $code_r . '). Pode ter sido excluída ou o token está incorreto.',
        ]);
        exit;
    }

    $reader_data  = json_decode($resp_r, true);
    $sumup_status = $reader_data['status'] ?? 'unknown';
    $serial       = $reader_data['device']['identifier'] ?? $reader['serial'];
    $model        = $reader_data['device']['model'] ?? $reader['model'];

    // Buscar status de conectividade (ONLINE/OFFLINE)
    $st = getReaderStatus($reader_id);

    // Atualizar banco
    $conn->prepare("UPDATE sumup_readers SET serial=?, model=?, status=?, battery_level=?, connection_type=?, firmware_version=?, last_activity=?, updated_at=NOW() WHERE reader_id=?")
         ->execute([$serial, $model, $sumup_status, $st['battery'], $st['connection'], $st['firmware'], $st['last_activity'], $reader_id]);

    // Mensagem de diagnóstico detalhada
    if ($st['online']) {
        $mensagem = "✅ Leitora ONLINE | Serial: {$serial} | Bateria: " . ($st['battery'] ?? '—') . "% | Conexão: " . ($st['connection'] ?? '—');
    } elseif ($sumup_status === 'processing') {
        $mensagem = "⏳ Aguardando confirmação de pareamento. Ligue o SumUp Solo e confirme o código no dispositivo.";
    } elseif ($sumup_status === 'expired') {
        $mensagem = "❌ Pareamento expirado (mais de 5 minutos). Exclua esta leitora e cadastre novamente com um novo código.";
    } elseif ($sumup_status === 'paired') {
        $mensagem = "⚠️ Leitora OFFLINE. O pareamento está correto (status: paired), mas o dispositivo não está conectado à API SumUp. " .
                    "Solução: No SumUp Solo → Connections → API → Connect. O dispositivo deve exibir 'Connected — Ready to transact'.";
    } else {
        $mensagem = "⚠️ Status SumUp: {$sumup_status}. Verifique se o SumUp Solo está ligado e com internet.";
    }

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

    // Aceitar 204 (sucesso), 404 (já não existe) ou 200
    if ($result['http_code'] !== 204 && $result['http_code'] !== 404 && $result['http_code'] !== 200) {
        http_response_code(422);
        echo json_encode([
            'error'     => 'Erro ao excluir leitora na SumUp: ' . ($result['data']['message'] ?? 'Erro desconhecido'),
            'http_code' => $result['http_code'],
            'detail'    => $result['raw'],
        ]);
        exit;
    }

    // Remover do banco
    $conn->prepare("DELETE FROM sumup_readers WHERE reader_id = ?")->execute([$reader_id]);

    // Remover reader_id das TAPs vinculadas
    try {
        $conn->prepare("UPDATE tap SET reader_id = NULL, pairing_code = NULL WHERE reader_id = ?")->execute([$reader_id]);
    } catch (Exception $e) { /* ignora se coluna não existir */ }

    echo json_encode([
        'success'  => true,
        'mensagem' => 'Leitora excluída com sucesso. No SumUp Solo: Connections → API → Disconnect para completar a desvinculação.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: delete_all — Excluir TODAS as leitoras
// ─────────────────────────────────────────────────────────────
if ($action === 'delete_all') {
    // Buscar todas as leitoras do banco
    $stmt    = $conn->query("SELECT reader_id, name FROM sumup_readers ORDER BY id");
    $readers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($readers)) {
        echo json_encode([
            'success'  => true,
            'excluidas'=> 0,
            'mensagem' => 'Nenhuma leitora encontrada para excluir.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $excluidas = 0;
    $erros     = [];

    foreach ($readers as $r) {
        $rid    = $r['reader_id'];
        $result = sumupRequest('DELETE', "readers/{$rid}");

        if (in_array($result['http_code'], [200, 204, 404])) {
            // Sucesso ou já não existia na SumUp
            $conn->prepare("DELETE FROM sumup_readers WHERE reader_id = ?")->execute([$rid]);
            try {
                $conn->prepare("UPDATE tap SET reader_id = NULL, pairing_code = NULL WHERE reader_id = ?")->execute([$rid]);
            } catch (Exception $e) { /* ignora */ }
            $excluidas++;
        } else {
            $erros[] = $r['name'] . ' (HTTP ' . $result['http_code'] . ')';
        }
    }

    if (!empty($erros)) {
        echo json_encode([
            'success'   => false,
            'excluidas' => $excluidas,
            'error'     => 'Algumas leitoras não puderam ser excluídas: ' . implode(', ', $erros),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success'   => true,
            'excluidas' => $excluidas,
            'mensagem'  => "Todas as {$excluidas} leitoras foram excluídas com sucesso da Cloud API SumUp. " .
                           "Nos dispositivos físicos: Connections → API → Disconnect.",
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACTION: update_estab — Vincular leitora a um estabelecimento
// ─────────────────────────────────────────────────────────────
if ($action === 'update_estab') {
    $reader_id          = trim($_POST['reader_id'] ?? '');
    $estabelecimento_id = !empty($_POST['estabelecimento_id']) ? intval($_POST['estabelecimento_id']) : null;

    if (empty($reader_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'reader_id é obrigatório']);
        exit;
    }

    $conn->prepare("UPDATE sumup_readers SET estabelecimento_id = ?, updated_at = NOW() WHERE reader_id = ?")
         ->execute([$estabelecimento_id, $reader_id]);

    $estab_nome = null;
    if ($estabelecimento_id) {
        $stmt_e = $conn->prepare("SELECT name FROM estabelecimentos WHERE id = ?");
        $stmt_e->execute([$estabelecimento_id]);
        $estab = $stmt_e->fetch(PDO::FETCH_ASSOC);
        $estab_nome = $estab['name'] ?? null;
    }

    echo json_encode([
        'success'              => true,
        'estabelecimento_nome' => $estab_nome,
        'mensagem'             => $estab_nome
            ? "Leitora vinculada ao estabelecimento: {$estab_nome}"
            : 'Vínculo de estabelecimento removido.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// Ação inválida
// ─────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => "Ação '{$action}' inválida"]);
