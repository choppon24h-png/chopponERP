<?php
/**
 * app_version.php — Endpoint de controle de versão do APK ChoppON
 *
 * v1.0.0
 *
 * FLUXO:
 *   Android (AcessoMaster) → GET /api/app_version.php
 *     Retorna JSON com versão atual, URL do APK, changelog e flag force.
 *
 *   ERP (admin/app_update.php) → POST /api/app_version.php
 *     Atualiza os dados de versão no banco (requer autenticação de sessão PHP).
 *
 * GET — público (chamado pelo Android sem autenticação)
 *   Retorna:
 *     { versionCode, versionName, apkUrl, force, changelog, updated_at }
 *
 * POST — protegido (chamado pelo ERP via AJAX)
 *   Parâmetros:
 *     action       → "update_version" | "get_version"
 *     version_code → int
 *     version_name → string (ex: "2.3.0")
 *     apk_url      → string (URL pública do APK)
 *     force        → 0|1
 *     changelog    → string (texto livre)
 *
 * FALLBACK:
 *   Se a tabela app_versions não existir, lê /app/version.json como fallback.
 */

ob_start();

// CORS — Android faz GET direto, sem preflight
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: token, Token, Authorization, Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

require_once __DIR__ . '/../includes/config.php';

// ── Garantir tabela ───────────────────────────────────────────────────────────
function ensureVersionTable($conn) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `app_versions` (
                `id`           INT(11)      NOT NULL AUTO_INCREMENT,
                `platform`     VARCHAR(20)  NOT NULL DEFAULT 'android',
                `version_code` INT(11)      NOT NULL DEFAULT 1,
                `version_name` VARCHAR(20)  NOT NULL DEFAULT '1.0.0',
                `apk_url`      VARCHAR(500) NOT NULL DEFAULT '',
                `force`        TINYINT(1)   NOT NULL DEFAULT 0,
                `changelog`    TEXT         DEFAULT NULL,
                `updated_by`   INT(11)      DEFAULT NULL,
                `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_platform` (`platform`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Inserir registro inicial se vazio
        $count = $conn->query("SELECT COUNT(*) FROM app_versions WHERE platform = 'android'")->fetchColumn();
        if ((int)$count === 0) {
            // Tentar ler version.json como seed
            $jsonPath = __DIR__ . '/../app/version.json';
            $seed = ['versionCode' => 1, 'versionName' => '1.0.0',
                     'apkUrl' => 'https://ochoppoficial.com.br/app/app-release.apk',
                     'force' => false, 'changelog' => 'Versão inicial.'];
            if (file_exists($jsonPath)) {
                $parsed = json_decode(file_get_contents($jsonPath), true);
                if ($parsed) $seed = $parsed;
            }
            $conn->prepare("
                INSERT INTO app_versions (platform, version_code, version_name, apk_url, force, changelog)
                VALUES ('android', ?, ?, ?, ?, ?)
            ")->execute([
                (int)($seed['versionCode'] ?? 1),
                $seed['versionName'] ?? '1.0.0',
                $seed['apkUrl'] ?? '',
                (int)($seed['force'] ?? 0),
                $seed['changelog'] ?? '',
            ]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── Buscar versão atual ───────────────────────────────────────────────────────
function getVersion($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT version_code, version_name, apk_url, force, changelog, updated_at
            FROM app_versions
            WHERE platform = 'android'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'versionCode' => (int)$row['version_code'],
                'versionName' => $row['version_name'],
                'apkUrl'      => $row['apk_url'],
                'force'       => (bool)$row['force'],
                'changelog'   => $row['changelog'] ?? '',
                'updated_at'  => $row['updated_at'],
            ];
        }
    } catch (Exception $e) { /* fallback abaixo */ }

    // Fallback: ler version.json estático
    $jsonPath = __DIR__ . '/../app/version.json';
    if (file_exists($jsonPath)) {
        $data = json_decode(file_get_contents($jsonPath), true);
        if ($data) {
            $data['updated_at'] = date('Y-m-d H:i:s', filemtime($jsonPath));
            return $data;
        }
    }

    return [
        'versionCode' => 1,
        'versionName' => '1.0.0',
        'apkUrl'      => 'https://ochoppoficial.com.br/app/app-release.apk',
        'force'       => false,
        'changelog'   => '',
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
}

// ── Conexão ───────────────────────────────────────────────────────────────────
try {
    $conn = getDBConnection();
    ensureVersionTable($conn);
} catch (Exception $e) {
    $conn = null;
}

// ── GET — Android consulta versão ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $version = getVersion($conn);
    ob_clean();
    echo json_encode($version, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_end_flush();
    exit;
}

// ── POST — ERP atualiza versão ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar autenticação de sessão (chamado pelo ERP logado)
    require_once __DIR__ . '/../includes/auth.php';

    if (!isLoggedIn()) {
        http_response_code(401);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if (!isAdminGeral()) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Apenas Admin Geral pode atualizar a versão.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($content_type, 'application/json') !== false) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $body = $_POST;
    }

    $action = trim($body['action'] ?? 'update_version');

    if ($action === 'get_version') {
        $version = getVersion($conn);
        ob_clean();
        echo json_encode(['success' => true, 'data' => $version], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if ($action === 'update_version') {
        $version_code = (int)($body['version_code'] ?? 0);
        $version_name = trim($body['version_name'] ?? '');
        $apk_url      = trim($body['apk_url']      ?? '');
        $force        = (int)($body['force']        ?? 0);
        $changelog    = trim($body['changelog']     ?? '');

        if ($version_code <= 0 || empty($version_name) || empty($apk_url)) {
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.'], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }

        if (!filter_var($apk_url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'URL do APK inválida.'], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }

        try {
            // Verificar se já existe registro para android
            $existing = $conn->prepare("SELECT id FROM app_versions WHERE platform = 'android' ORDER BY id DESC LIMIT 1");
            $existing->execute();
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $conn->prepare("
                    UPDATE app_versions
                    SET version_code = ?, version_name = ?, apk_url = ?, force = ?, changelog = ?,
                        updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$version_code, $version_name, $apk_url, $force, $changelog,
                             $_SESSION['user_id'], $row['id']]);
            } else {
                $conn->prepare("
                    INSERT INTO app_versions (platform, version_code, version_name, apk_url, force, changelog, updated_by)
                    VALUES ('android', ?, ?, ?, ?, ?, ?)
                ")->execute([$version_code, $version_name, $apk_url, $force, $changelog, $_SESSION['user_id']]);
            }

            // Sincronizar version.json estático (fallback para Android que usa a URL antiga)
            $jsonPath = __DIR__ . '/../app/version.json';
            $jsonData = json_encode([
                'versionCode' => $version_code,
                'versionName' => $version_name,
                'apkUrl'      => $apk_url,
                'force'       => (bool)$force,
                'changelog'   => $changelog,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            @file_put_contents($jsonPath, $jsonData);

            Logger::info('Versão do app atualizada', [
                'version_code' => $version_code,
                'version_name' => $version_name,
                'updated_by'   => $_SESSION['user_id'],
            ]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => "Versão $version_name (build $version_code) publicada com sucesso.",
            ], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;

        } catch (Exception $e) {
            Logger::error('Erro ao atualizar versão do app', ['error' => $e->getMessage()]);
            http_response_code(500);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar versão.'], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }
    }

    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => "Ação '$action' desconhecida."], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
}
