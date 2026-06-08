<?php
/**
 * htaccess_editor.php — ChopponERP
 *
 * Editor de arquivos .htaccess diretamente pelo painel admin.
 * Permite editar o .htaccess da raiz e o da pasta admin/ sem precisar de FTP.
 *
 * ATENÇÃO: Restrito exclusivamente ao Admin Geral.
 * Diretivas proibidas no HostGator compartilhado são detectadas e bloqueadas.
 */

$page_title   = 'Editor .htaccess';
$current_page = 'htaccess_editor';

require_once '../includes/config.php';
require_once '../includes/auth.php';
requireAuth();

if (!isAdminGeral()) {
    header('Location: ../index.php');
    exit;
}

// ── Caminhos dos arquivos ─────────────────────────────────────────────────────
$arquivos = [
    'raiz'  => [
        'label' => '.htaccess (Raiz do site)',
        'path'  => realpath(__DIR__ . '/../.htaccess'),
        'desc'  => 'Controla rewrite, segurança, headers, PHP config, cache e GZIP para todo o site.',
    ],
    'admin' => [
        'label' => '.htaccess (Pasta admin/)',
        'path'  => realpath(__DIR__ . '/.htaccess'),
        'desc'  => 'Configurações específicas da pasta admin. Mantenha apenas diretivas permitidas em hospedagem compartilhada.',
    ],
];

// ── Diretivas PROIBIDAS no HostGator compartilhado ────────────────────────────
// Causam erro 500 imediato em toda a pasta onde o .htaccess está.
$diretivas_proibidas = [
    'SecRuleRemoveById',
    'SecFilterEngine',
    'SecFilterScanPOST',
    'SecRule',
    'SecAction',
    'SecDefaultAction',
    'SecRequestBodyAccess',
    'SecResponseBodyAccess',
    'mod_security',
];

$success = '';
$error   = '';
$aba     = $_GET['aba'] ?? 'raiz';
if (!array_key_exists($aba, $arquivos)) {
    $aba = 'raiz';
}

// ── Processar salvamento ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar') {
    $aba_post  = $_POST['aba']     ?? 'raiz';
    $conteudo  = $_POST['conteudo'] ?? '';

    if (!array_key_exists($aba_post, $arquivos)) {
        $error = 'Arquivo inválido selecionado.';
    } else {
        $caminho = $arquivos[$aba_post]['path'];

        // Verificar diretivas proibidas
        $encontradas = [];
        foreach ($diretivas_proibidas as $dir) {
            if (stripos($conteudo, $dir) !== false) {
                $encontradas[] = $dir;
            }
        }

        if (!empty($encontradas)) {
            $error = 'Diretivas PROIBIDAS detectadas (causam erro 500 no HostGator): <strong>'
                   . implode(', ', $encontradas)
                   . '</strong>. Remova-as antes de salvar.';
        } elseif (!is_writable($caminho)) {
            $error = 'O arquivo não tem permissão de escrita no servidor. Verifique as permissões via cPanel (chmod 644).';
        } else {
            // Fazer backup automático antes de salvar
            $backup_dir  = __DIR__ . '/../logs/htaccess_backups/';
            if (!is_dir($backup_dir)) {
                @mkdir($backup_dir, 0755, true);
            }
            $backup_file = $backup_dir . $aba_post . '_' . date('Ymd_His') . '.htaccess.bak';
            @copy($caminho, $backup_file);

            // Salvar o novo conteúdo
            $bytes = file_put_contents($caminho, $conteudo);
            if ($bytes === false) {
                $error = 'Falha ao escrever o arquivo. Verifique as permissões no servidor.';
            } else {
                $success = 'Arquivo <strong>' . htmlspecialchars($arquivos[$aba_post]['label']) . '</strong> salvo com sucesso! '
                         . ($backup_file ? '(Backup criado em logs/htaccess_backups/)' : '');
                // Recarregar aba salva
                $aba = $aba_post;
            }
        }
    }
}

// ── Carregar conteúdos atuais ─────────────────────────────────────────────────
$conteudos = [];
foreach ($arquivos as $key => $info) {
    $path = $info['path'];
    if ($path && file_exists($path)) {
        $conteudos[$key] = file_get_contents($path);
    } else {
        $conteudos[$key] = '# Arquivo não encontrado em: ' . ($path ?: 'caminho inválido');
    }
}

require_once '../includes/header.php';
?>

<style>
/* ── Editor .htaccess ────────────────────────────────────────────────────────── */
.htaccess-tabs { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; flex-wrap:wrap; }
.htaccess-tab  { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border:none; background:transparent; color:#6b7280; font-size:14px; font-weight:500; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; border-radius:4px 4px 0 0; transition:all .2s; }
.htaccess-tab:hover  { background:#f3f4f6; color:#374151; }
.htaccess-tab.active { color:#2563eb; border-bottom-color:#2563eb; background:#eff6ff; }

.editor-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:24px; margin-bottom:24px; }
.editor-card h3 { font-size:16px; font-weight:600; color:#1e293b; margin:0 0 6px; display:flex; align-items:center; gap:8px; }
.editor-card .desc { font-size:13px; color:#64748b; margin-bottom:16px; }

.htaccess-textarea {
    width: 100%;
    min-height: 480px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    line-height: 1.6;
    background: #1e293b;
    color: #e2e8f0;
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 16px;
    resize: vertical;
    box-sizing: border-box;
    tab-size: 4;
    white-space: pre;
    overflow-wrap: normal;
    overflow-x: auto;
}
.htaccess-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}

.editor-actions { display:flex; gap:12px; align-items:center; margin-top:16px; flex-wrap:wrap; }

.warning-box { background:#fff8e1; border:1px solid #ffc107; border-radius:8px; padding:14px 18px; margin-bottom:20px; font-size:13px; color:#856404; }
.warning-box strong { display:block; margin-bottom:6px; font-size:14px; }
.warning-box ul { margin:6px 0 0 18px; padding:0; }
.warning-box ul li { margin-bottom:3px; }

.info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; margin-bottom:20px; font-size:13px; color:#1e40af; }
.info-box strong { display:block; margin-bottom:6px; font-size:14px; }

.char-count { font-size:12px; color:#94a3b8; margin-top:6px; }

.btn-restore { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer; transition:all .2s; }
.btn-restore:hover { background:#e5e7eb; }
</style>

<div class="page-header">
    <h1><i class="fas fa-file-code"></i> Editor de .htaccess</h1>
    <p class="page-subtitle">Edite os arquivos de configuração do Apache diretamente pelo painel.</p>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="fas fa-check-circle"></i> <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
</div>
<?php endif; ?>

<!-- Aviso sobre diretivas proibidas -->
<div class="warning-box">
    <strong><i class="fas fa-exclamation-triangle"></i> Diretivas PROIBIDAS no HostGator Compartilhado (causam erro 500):</strong>
    <ul>
        <?php foreach ($diretivas_proibidas as $dir): ?>
        <li><code><?= htmlspecialchars($dir) ?></code></li>
        <?php endforeach; ?>
    </ul>
    O sistema bloqueia automaticamente o salvamento se detectar essas diretivas.
</div>

<div class="info-box">
    <strong><i class="fas fa-info-circle"></i> Backup automático</strong>
    A cada salvamento, uma cópia do arquivo anterior é criada em <code>logs/htaccess_backups/</code> com data e hora no nome.
</div>

<!-- Abas de seleção de arquivo -->
<div class="htaccess-tabs">
    <?php foreach ($arquivos as $key => $info): ?>
    <a href="?aba=<?= $key ?>" class="htaccess-tab <?= $aba === $key ? 'active' : '' ?>">
        <i class="fas fa-file-code"></i>
        <?= htmlspecialchars($info['label']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Editor do arquivo selecionado -->
<div class="editor-card">
    <h3>
        <i class="fas fa-edit"></i>
        <?= htmlspecialchars($arquivos[$aba]['label']) ?>
    </h3>
    <p class="desc"><?= htmlspecialchars($arquivos[$aba]['desc']) ?></p>

    <form method="POST" id="form-editor-<?= $aba ?>">
        <input type="hidden" name="action" value="salvar">
        <input type="hidden" name="aba" value="<?= $aba ?>">

        <textarea
            name="conteudo"
            id="editor-textarea-<?= $aba ?>"
            class="htaccess-textarea"
            spellcheck="false"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            onkeydown="handleTab(event, this)"
            oninput="updateCharCount(this)"
        ><?= htmlspecialchars($conteudos[$aba]) ?></textarea>

        <div class="char-count" id="char-count-<?= $aba ?>">
            <?= number_format(strlen($conteudos[$aba])) ?> caracteres
            | <?= substr_count($conteudos[$aba], "\n") + 1 ?> linhas
        </div>

        <div class="editor-actions">
            <button type="submit" class="btn btn-primary" onclick="return confirmarSalvamento()">
                <i class="fas fa-save"></i> Salvar Alterações
            </button>
            <button type="button" class="btn-restore" onclick="restaurarOriginal('<?= $aba ?>')">
                <i class="fas fa-undo"></i> Desfazer Alterações
            </button>
            <button type="button" class="btn btn-secondary" onclick="copiarConteudo('<?= $aba ?>')">
                <i class="fas fa-copy"></i> Copiar
            </button>
            <span style="margin-left:auto; font-size:12px; color:#94a3b8;">
                Arquivo: <code><?= htmlspecialchars($arquivos[$aba]['path']) ?></code>
            </span>
        </div>
    </form>
</div>

<!-- Seção de backups disponíveis -->
<?php
$backup_dir = __DIR__ . '/../logs/htaccess_backups/';
$backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . $aba . '_*.htaccess.bak');
    if ($files) {
        rsort($files); // mais recente primeiro
        $backups = array_slice($files, 0, 10); // últimos 10 backups
    }
}
?>
<?php if (!empty($backups)): ?>
<div class="editor-card">
    <h3><i class="fas fa-history"></i> Backups Recentes — <?= htmlspecialchars($arquivos[$aba]['label']) ?></h3>
    <p class="desc">Clique em "Restaurar" para carregar o conteúdo de um backup no editor (ainda precisará salvar).</p>
    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr style="background:#f9fafb;">
                <th style="padding:8px 12px; text-align:left; border-bottom:2px solid #e5e7eb;">Arquivo</th>
                <th style="padding:8px 12px; text-align:left; border-bottom:2px solid #e5e7eb;">Data/Hora</th>
                <th style="padding:8px 12px; text-align:left; border-bottom:2px solid #e5e7eb;">Tamanho</th>
                <th style="padding:8px 12px; text-align:center; border-bottom:2px solid #e5e7eb;">Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $bk): ?>
            <?php
            $bk_name  = basename($bk);
            $bk_size  = filesize($bk);
            $bk_mtime = filemtime($bk);
            $bk_date  = date('d/m/Y H:i:s', $bk_mtime);
            $bk_conteudo = addslashes(file_get_contents($bk));
            ?>
            <tr>
                <td style="padding:8px 12px; border-bottom:1px solid #f3f4f6;"><code><?= htmlspecialchars($bk_name) ?></code></td>
                <td style="padding:8px 12px; border-bottom:1px solid #f3f4f6;"><?= $bk_date ?></td>
                <td style="padding:8px 12px; border-bottom:1px solid #f3f4f6;"><?= number_format($bk_size) ?> bytes</td>
                <td style="padding:8px 12px; border-bottom:1px solid #f3f4f6; text-align:center;">
                    <button type="button" class="btn-restore"
                        onclick="carregarBackup('<?= $aba ?>', <?= json_encode(file_get_contents($bk)) ?>)">
                        <i class="fas fa-undo"></i> Restaurar
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// Conteúdo original para desfazer alterações
const conteudoOriginal = <?= json_encode($conteudos[$aba]) ?>;

function handleTab(e, el) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        el.value = el.value.substring(0, start) + '    ' + el.value.substring(end);
        el.selectionStart = el.selectionEnd = start + 4;
        updateCharCount(el);
    }
}

function updateCharCount(el) {
    const aba   = el.id.replace('editor-textarea-', '');
    const chars = el.value.length;
    const lines = el.value.split('\n').length;
    const el2   = document.getElementById('char-count-' + aba);
    if (el2) {
        el2.textContent = chars.toLocaleString('pt-BR') + ' caracteres | ' + lines.toLocaleString('pt-BR') + ' linhas';
    }
}

function restaurarOriginal(aba) {
    if (!confirm('Desfazer todas as alterações e restaurar o conteúdo atual do arquivo?')) return;
    const el = document.getElementById('editor-textarea-' + aba);
    if (el) {
        el.value = conteudoOriginal;
        updateCharCount(el);
    }
}

function copiarConteudo(aba) {
    const el = document.getElementById('editor-textarea-' + aba);
    if (!el) return;
    navigator.clipboard.writeText(el.value).then(function() {
        alert('Conteúdo copiado para a área de transferência!');
    }).catch(function() {
        el.select();
        document.execCommand('copy');
        alert('Conteúdo copiado!');
    });
}

function carregarBackup(aba, conteudo) {
    if (!confirm('Carregar este backup no editor? O conteúdo atual será substituído (ainda precisará salvar).')) return;
    const el = document.getElementById('editor-textarea-' + aba);
    if (el) {
        el.value = conteudo;
        updateCharCount(el);
    }
}

function confirmarSalvamento() {
    return confirm('Tem certeza que deseja salvar as alterações no .htaccess?\n\nUm backup automático será criado antes de sobrescrever.');
}

// Highlight básico: colorir comentários (#) em tempo real
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.querySelector('.htaccess-textarea');
    if (textarea) {
        updateCharCount(textarea);
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
