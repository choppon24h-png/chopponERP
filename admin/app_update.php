<?php
/**
 * admin/app_update.php — Painel de Gerenciamento de Versão do App Android
 *
 * Permite ao Admin Geral:
 *   - Ver a versão atual publicada
 *   - Definir nova versão (versionCode, versionName, URL do APK, changelog)
 *   - Marcar como atualização obrigatória (force)
 *   - Sincroniza automaticamente /app/version.json e o banco app_versions
 */

$page_title = 'Atualização do App';
require_once '../includes/header.php';
requireAdminGeral();

$conn = getDBConnection();

// Buscar versão atual do banco
$current = null;
try {
    $stmt = $conn->prepare("
        SELECT av.*, u.name AS updated_by_name
        FROM app_versions av
        LEFT JOIN users u ON u.id = av.updated_by
        WHERE av.platform = 'android'
        ORDER BY av.id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela ainda não existe — será criada pelo endpoint
}

// Fallback: ler version.json
if (!$current) {
    $jsonPath = __DIR__ . '/../app/version.json';
    if (file_exists($jsonPath)) {
        $j = json_decode(file_get_contents($jsonPath), true);
        if ($j) {
            $current = [
                'version_code' => $j['versionCode'] ?? 1,
                'version_name' => $j['versionName'] ?? '1.0.0',
                'apk_url'      => $j['apkUrl'] ?? '',
                'force'        => $j['force'] ?? false,
                'changelog'    => $j['changelog'] ?? '',
                'updated_at'   => date('Y-m-d H:i:s', @filemtime($jsonPath) ?: time()),
                'updated_by_name' => 'Sistema',
            ];
        }
    }
}
?>

<div class="page-header">
    <h1><i class="fas fa-mobile-alt"></i> Atualização do App Android</h1>
    <p class="page-subtitle">Gerencie a versão do APK distribuída para os tablets ChoppON.</p>
</div>

<!-- ── Versão atual ─────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header">
        <h5><i class="fas fa-info-circle"></i> Versão Atualmente Publicada</h5>
    </div>
    <div class="card-body">
        <?php if ($current): ?>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-label">Build (versionCode)</div>
                    <div class="stat-value text-primary"><?php echo (int)$current['version_code']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-label">Versão (versionName)</div>
                    <div class="stat-value text-success"><?php echo htmlspecialchars($current['version_name']); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-label">Atualização Obrigatória</div>
                    <div class="stat-value">
                        <?php if ($current['force']): ?>
                            <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> SIM</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Não</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-label">Última Atualização</div>
                    <div class="stat-value" style="font-size:14px;">
                        <?php echo date('d/m/Y H:i', strtotime($current['updated_at'])); ?>
                        <?php if (!empty($current['updated_by_name'])): ?>
                            <br><small class="text-muted">por <?php echo htmlspecialchars($current['updated_by_name']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <strong>URL do APK:</strong>
            <a href="<?php echo htmlspecialchars($current['apk_url']); ?>" target="_blank" class="text-break">
                <?php echo htmlspecialchars($current['apk_url']); ?>
            </a>
        </div>
        <?php if (!empty($current['changelog'])): ?>
        <div class="mt-2">
            <strong>Changelog:</strong>
            <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($current['changelog'])); ?></p>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            Nenhuma versão publicada ainda. Preencha o formulário abaixo para publicar a primeira versão.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Formulário de publicação ────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-upload"></i> Publicar Nova Versão</h5>
    </div>
    <div class="card-body">
        <div id="alertUpdate" class="alert" style="display:none;"></div>

        <form id="formAppUpdate">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="version_code">
                            Build / versionCode <span class="text-danger">*</span>
                            <i class="fas fa-question-circle text-muted"
                               title="Número inteiro incremental. Deve ser MAIOR que a versão atual para o app detectar atualização."></i>
                        </label>
                        <input type="number" class="form-control" id="version_code" name="version_code"
                               min="1" required
                               value="<?php echo $current ? ((int)$current['version_code'] + 1) : 2; ?>"
                               placeholder="Ex: 5">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="version_name">
                            Versão / versionName <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="version_name" name="version_name"
                               required maxlength="20"
                               value="<?php echo $current ? htmlspecialchars($current['version_name']) : '1.0.0'; ?>"
                               placeholder="Ex: 2.3.0">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="apk_url">
                            URL do APK <span class="text-danger">*</span>
                            <i class="fas fa-question-circle text-muted"
                               title="URL pública do arquivo .apk. Suba o APK via FTP para /app/ e use https://ochoppoficial.com.br/app/app-release.apk"></i>
                        </label>
                        <input type="url" class="form-control" id="apk_url" name="apk_url"
                               required
                               value="<?php echo $current ? htmlspecialchars($current['apk_url']) : 'https://ochoppoficial.com.br/app/app-release.apk'; ?>"
                               placeholder="https://ochoppoficial.com.br/app/app-release.apk">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="changelog">Changelog (O que há de novo)</label>
                        <textarea class="form-control" id="changelog" name="changelog"
                                  rows="4" maxlength="2000"
                                  placeholder="Descreva as melhorias e correções desta versão..."><?php echo $current ? htmlspecialchars($current['changelog']) : ''; ?></textarea>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="force" name="force" value="1"
                               <?php echo ($current && $current['force']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="force">
                            <strong>Atualização Obrigatória</strong>
                            <small class="text-muted d-block">
                                Quando marcado, o app não permitirá que o usuário ignore a atualização.
                                Use apenas para versões críticas de segurança ou compatibilidade.
                            </small>
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btnPublicar">
                    <i class="fas fa-upload"></i> Publicar Versão
                </button>
                <a href="<?php echo SITE_URL; ?>/api/app_version.php" target="_blank" class="btn btn-outline-secondary">
                    <i class="fas fa-eye"></i> Ver version.json atual
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── Instruções ──────────────────────────────────────────────────────────── -->
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="fas fa-book"></i> Como publicar uma nova versão</h5>
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li class="mb-2">
                <strong>Gere o APK no Android Studio:</strong>
                <code>Build → Generate Signed Bundle/APK → APK → Release</code>
            </li>
            <li class="mb-2">
                <strong>Suba o APK via FTP</strong> para o diretório:
                <code>/home2/inlaud99/ochoppoficial.com.br/app/app-release.apk</code>
            </li>
            <li class="mb-2">
                <strong>Preencha o formulário acima</strong> com o novo <code>versionCode</code>
                (deve ser maior que o atual) e <code>versionName</code>.
                O <code>versionCode</code> deve coincidir com o valor em <code>build.gradle.kts</code>
                (<code>versionCode = X</code>).
            </li>
            <li class="mb-2">
                <strong>Clique em "Publicar Versão".</strong>
                O sistema atualiza o banco de dados e o arquivo <code>/app/version.json</code> automaticamente.
            </li>
            <li>
                <strong>No tablet:</strong> Acesse a tela de <strong>Acesso Master</strong> e toque em
                <strong>"Verificar Atualização"</strong>. O app detectará a nova versão e oferecerá o download.
            </li>
        </ol>
    </div>
</div>

<script>
document.getElementById('formAppUpdate').addEventListener('submit', function(e) {
    e.preventDefault();

    var btn = document.getElementById('btnPublicar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publicando...';

    var fd = new FormData(this);
    fd.append('action', 'update_version');
    // Garantir que force=0 quando não marcado
    if (!document.getElementById('force').checked) {
        fd.set('force', '0');
    }

    fetch('<?php echo SITE_URL; ?>/api/app_version.php', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var alert = document.getElementById('alertUpdate');
        alert.style.display = 'block';
        if (data.success) {
            alert.className = 'alert alert-success';
            alert.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            // Recarregar após 2s para mostrar versão atualizada
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            alert.className = 'alert alert-danger';
            alert.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.message || 'Erro desconhecido.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Publicar Versão';
        }
    })
    .catch(function(err) {
        var alert = document.getElementById('alertUpdate');
        alert.style.display = 'block';
        alert.className = 'alert alert-danger';
        alert.innerHTML = '<i class="fas fa-times-circle"></i> Erro de conexão. Tente novamente.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Publicar Versão';
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
