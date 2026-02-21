<?php
/**
 * Configurações de Pagamento + Gestão de Leitoras SumUp Solo
 */
$page_title   = 'Configurações de Pagamento';
$current_page = 'pagamentos';

require_once '../includes/config.php';
require_once '../includes/auth.php';

$conn    = getDBConnection();
$success = '';
$error   = '';

// ─── Processar salvar configuração de pagamento ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $token_sumup = sanitize($_POST['token_sumup']);
    $pix         = isset($_POST['pix'])    ? 1 : 0;
    $credit      = isset($_POST['credit']) ? 1 : 0;
    $debit       = isset($_POST['debit'])  ? 1 : 0;

    $stmt     = $conn->query("SELECT id FROM payment LIMIT 1");
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE payment SET token_sumup = ?, pix = ?, credit = ?, debit = ? WHERE id = ?");
        if ($stmt->execute([$token_sumup, $pix, $credit, $debit, $existing['id']])) {
            $success = 'Configurações atualizadas com sucesso!';
        } else {
            $error = 'Erro ao atualizar configurações.';
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO payment (token_sumup, pix, credit, debit) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$token_sumup, $pix, $credit, $debit])) {
            $success = 'Configurações salvas com sucesso!';
        } else {
            $error = 'Erro ao salvar configurações.';
        }
    }
}

// ─── Buscar configurações atuais ─────────────────────────────
$stmt    = $conn->query("SELECT * FROM payment LIMIT 1");
$payment = $stmt->fetch();

// ─── Buscar leitoras cadastradas ─────────────────────────────
// Garantir que a tabela existe antes de consultar
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

$stmt_readers = $conn->query("SELECT * FROM sumup_readers ORDER BY created_at DESC");
$readers      = $stmt_readers->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Configurações de Pagamento</h1>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     SEÇÃO 1 — Token SumUp e Métodos de Pagamento
     ════════════════════════════════════════════════════════════ -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-key"></i> Integração SumUp</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="save_payment" value="1">
                    <div class="form-group">
                        <label for="token_sumup">Token SumUp *</label>
                        <input type="text"
                               name="token_sumup"
                               id="token_sumup"
                               class="form-control"
                               value="<?php echo htmlspecialchars($payment['token_sumup'] ?? ''); ?>"
                               required>
                        <small style="color:var(--gray-600);">Token de autenticação da API SumUp (sup_sk_...)</small>
                    </div>

                    <div class="form-group">
                        <label>Métodos de Pagamento Habilitados</label>
                        <div class="checkbox-label">
                            <input type="checkbox" name="pix" id="pix" value="1"
                                <?php echo ($payment['pix'] ?? 1) ? 'checked' : ''; ?>>
                            <span>PIX</span>
                        </div>
                        <div class="checkbox-label">
                            <input type="checkbox" name="credit" id="credit" value="1"
                                <?php echo ($payment['credit'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Cartão de Crédito</span>
                        </div>
                        <div class="checkbox-label">
                            <input type="checkbox" name="debit" id="debit" value="1"
                                <?php echo ($payment['debit'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Cartão de Débito</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Configurações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-info-circle"></i> Informações</h4>
            </div>
            <div class="card-body">
                <p><strong>Merchant Code:</strong> <?php echo SUMUP_MERCHANT_CODE; ?></p>
                <p><strong>Webhook URL:</strong></p>
                <code style="font-size:11px;word-break:break-all;">
                    <?php echo SITE_URL; ?>/api/webhook.php
                </code>
                <hr>
                <p style="font-size:13px;color:var(--gray-600);">
                    Configure este webhook no painel SumUp para receber notificações de status de pagamento.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     SEÇÃO 2 — Leitoras de Cartão SumUp Solo
     ════════════════════════════════════════════════════════════ -->
<div class="row" style="margin-top:24px;">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h4><i class="fas fa-credit-card"></i> Leitoras de Cartão SumUp Solo</h4>
                <button class="btn btn-primary btn-sm" onclick="abrirModalNovaLeitora()">
                    <i class="fas fa-plus"></i> Nova Leitora
                </button>
            </div>
            <div class="card-body">
                <p style="font-size:13px;color:var(--gray-600);margin-bottom:16px;">
                    Cada leitora SumUp Solo deve ser cadastrada aqui antes de ser vinculada a uma TAP.
                    Para obter o código de pareamento, ligue o SumUp Solo — o código aparecerá na tela do dispositivo.
                </p>

                <div class="table-responsive">
                    <table class="table" id="tabelaLeitoras">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Nome / Código</th>
                                <th>Serial</th>
                                <th>Modelo</th>
                                <th>Bateria</th>
                                <th>Conexão</th>
                                <th>Última Atividade</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyLeitoras">
                            <?php if (empty($readers)): ?>
                                <tr id="trVazio">
                                    <td colspan="8" style="text-align:center;color:var(--gray-500);padding:32px;">
                                        <i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>
                                        Nenhuma leitora cadastrada. Clique em <strong>Nova Leitora</strong> para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($readers as $r): ?>
                                    <tr id="row-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                        <td>
                                            <span class="badge badge-secondary" id="badge-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                                <i class="fas fa-spinner fa-spin"></i> Verificando...
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($r['serial'] ?? '—'); ?></code></td>
                                        <td><?php echo htmlspecialchars($r['model'] ?? '—'); ?></td>
                                        <td id="bat-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo $r['battery_level'] !== null ? $r['battery_level'].'%' : '—'; ?>
                                        </td>
                                        <td id="conn-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo htmlspecialchars($r['connection_type'] ?? '—'); ?>
                                        </td>
                                        <td id="act-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo $r['last_activity'] ? date('d/m/Y H:i', strtotime($r['last_activity'])) : '—'; ?>
                                        </td>
                                        <td class="action-buttons">
                                            <button class="btn btn-sm btn-secondary"
                                                    onclick="testarLeitora('<?php echo htmlspecialchars($r['reader_id']); ?>', '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
                                                <i class="fas fa-wifi"></i> Testar
                                            </button>
                                            <button class="btn btn-sm btn-danger"
                                                    onclick="confirmarExcluir('<?php echo htmlspecialchars($r['reader_id']); ?>', '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
                                                <i class="fas fa-trash"></i> Excluir
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Nova Leitora
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalNovaLeitora">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Cadastrar Nova Leitora</h3>
            <button class="modal-close" onclick="fecharModalNovaLeitora()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="inputPairingCode">Código de Pareamento *</label>
                <input type="text"
                       id="inputPairingCode"
                       class="form-control"
                       placeholder="Ex: A4RZALFHY"
                       maxlength="9"
                       style="text-transform:uppercase;font-size:20px;letter-spacing:3px;font-weight:bold;text-align:center;"
                       oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                <small style="color:var(--gray-600);">
                    Ligue o SumUp Solo — o código de 8 ou 9 caracteres aparecerá na tela do dispositivo.
                </small>
            </div>
            <div class="form-group">
                <label for="inputNomeLeitora">Nome / Identificação da Unidade</label>
                <input type="text"
                       id="inputNomeLeitora"
                       class="form-control"
                       placeholder="Ex: Chopeira 01 - Bar Central">
                <small style="color:var(--gray-600);">Nome para identificar esta leitora no sistema.</small>
            </div>

            <!-- Resultado do teste -->
            <div id="resultadoTeste" style="display:none;margin-top:16px;padding:14px;border-radius:6px;font-size:13px;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalNovaLeitora()">Cancelar</button>
            <button class="btn btn-warning" id="btnTestarNova" onclick="testarNovaLeitora()">
                <i class="fas fa-wifi"></i> Testar Vinculação
            </button>
            <button class="btn btn-success" id="btnGravar" onclick="gravarLeitora()" style="display:none;">
                <i class="fas fa-save"></i> Gravar Leitora
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Confirmar Exclusão
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalExcluir">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i> Confirmar Exclusão</h3>
            <button class="modal-close" onclick="fecharModalExcluir()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Tem certeza que deseja excluir a leitora <strong id="nomeExcluir"></strong>?</p>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;font-size:13px;margin-top:8px;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Atenção:</strong> A leitora será desvinculada de todas as TAPs e o SumUp Solo exibirá um novo código de pareamento.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalExcluir()">Cancelar</button>
            <button class="btn btn-danger" id="btnConfirmarExcluir" onclick="excluirLeitora()">
                <i class="fas fa-trash"></i> Excluir
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Resultado do Teste
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalTeste">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-wifi"></i> Teste de Comunicação</h3>
            <button class="modal-close" onclick="document.getElementById('modalTeste').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body" id="modalTesteBody">
            <div style="text-align:center;padding:24px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--primary);"></i>
                <p style="margin-top:12px;">Testando comunicação com a leitora...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="document.getElementById('modalTeste').classList.remove('active')">Fechar</button>
        </div>
    </div>
</div>

<script>
// ─── Estado global ────────────────────────────────────────────
var readerIdParaExcluir = '';
var dadosNovaLeitora    = null;
var API_URL = '<?php echo SITE_URL; ?>/api/manage_readers.php';

// ─── Abrir/fechar modal Nova Leitora ─────────────────────────
function abrirModalNovaLeitora() {
    document.getElementById('inputPairingCode').value = '';
    document.getElementById('inputNomeLeitora').value = '';
    document.getElementById('resultadoTeste').style.display = 'none';
    document.getElementById('btnGravar').style.display = 'none';
    dadosNovaLeitora = null;
    document.getElementById('modalNovaLeitora').classList.add('active');
    setTimeout(function(){ document.getElementById('inputPairingCode').focus(); }, 100);
}

function fecharModalNovaLeitora() {
    document.getElementById('modalNovaLeitora').classList.remove('active');
}

// ─── Testar nova leitora (antes de gravar) ───────────────────
function testarNovaLeitora() {
    var code = document.getElementById('inputPairingCode').value.trim().toUpperCase();
    var name = document.getElementById('inputNomeLeitora').value.trim() || code;

    if (code.length < 8 || code.length > 9) {
        mostrarResultado('danger', '<i class="fas fa-times-circle"></i> O código deve ter 8 ou 9 caracteres alfanuméricos.');
        return;
    }

    var btn = document.getElementById('btnTestarNova');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
    document.getElementById('btnGravar').style.display = 'none';
    mostrarResultado('info', '<i class="fas fa-spinner fa-spin"></i> Conectando ao SumUp Solo... Aguarde até 20 segundos.');

    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('pairing_code', code);
    fd.append('name', name);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wifi"></i> Testar Vinculação';

            if (data.success) {
                dadosNovaLeitora = data;
                var statusIcon = data.online ? '🟢' : '🟡';
                var statusTxt  = data.online ? 'ONLINE' : 'Aguardando dispositivo ligar';
                var html = '<strong>' + statusIcon + ' Leitora vinculada com sucesso!</strong><br>';
                html += '<table style="margin-top:10px;font-size:12px;width:100%;border-collapse:collapse;">';
                html += '<tr><td style="padding:3px 8px 3px 0;width:130px;"><strong>Reader ID:</strong></td><td><code>' + escHtml(data.reader_id) + '</code></td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Serial:</strong></td><td><code>' + escHtml(data.serial || '—') + '</code></td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Modelo:</strong></td><td>' + escHtml(data.model || '—') + '</td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Status:</strong></td><td>' + statusTxt + '</td></tr>';
                if (data.battery !== null && data.battery !== undefined) html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Bateria:</strong></td><td>' + data.battery + '%</td></tr>';
                if (data.connection) html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Conexão:</strong></td><td>' + escHtml(data.connection) + '</td></tr>';
                html += '</table>';
                html += '<p style="margin-top:10px;font-size:12px;color:#555;">' + escHtml(data.mensagem) + '</p>';
                mostrarResultado('success', html);
                document.getElementById('btnGravar').style.display = 'inline-block';
            } else {
                mostrarResultado('danger',
                    '<strong><i class="fas fa-times-circle"></i> Erro ao vincular leitora</strong><br>' +
                    escHtml(data.error || 'Erro desconhecido')
                );
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wifi"></i> Testar Vinculação';
            mostrarResultado('danger', '<i class="fas fa-times-circle"></i> Erro de comunicação com o servidor.');
        });
}

// ─── Gravar leitora após teste bem-sucedido ──────────────────
function gravarLeitora() {
    if (!dadosNovaLeitora) return;
    fecharModalNovaLeitora();
    adicionarLinhaTabela(dadosNovaLeitora);
    var trVazio = document.getElementById('trVazio');
    if (trVazio) trVazio.remove();
    mostrarToast('Leitora gravada com sucesso!', 'success');
}

// ─── Adicionar linha na tabela ───────────────────────────────
function adicionarLinhaTabela(data) {
    var tbody     = document.getElementById('tbodyLeitoras');
    var badgeClass = data.online ? 'badge-success' : 'badge-warning';
    var badgeLabel = data.online ? 'ONLINE' : 'Aguardando';
    var lastAct    = data.last_activity ? new Date(data.last_activity).toLocaleString('pt-BR') : '—';
    var rid        = escHtml(data.reader_id);
    var rname      = escHtml(data.name);

    var tr = document.createElement('tr');
    tr.id  = 'row-' + rid;
    tr.innerHTML =
        '<td><span class="badge ' + badgeClass + '" id="badge-' + rid + '"><i class="fas fa-circle"></i> ' + badgeLabel + '</span></td>' +
        '<td><strong>' + rname + '</strong></td>' +
        '<td><code>' + escHtml(data.serial || '—') + '</code></td>' +
        '<td>' + escHtml(data.model || '—') + '</td>' +
        '<td id="bat-' + rid + '">' + (data.battery !== null && data.battery !== undefined ? data.battery + '%' : '—') + '</td>' +
        '<td id="conn-' + rid + '">' + escHtml(data.connection || '—') + '</td>' +
        '<td id="act-' + rid + '">' + lastAct + '</td>' +
        '<td class="action-buttons">' +
            '<button class="btn btn-sm btn-secondary" onclick="testarLeitora(\'' + rid + '\', \'' + rname + '\')">' +
                '<i class="fas fa-wifi"></i> Testar</button> ' +
            '<button class="btn btn-sm btn-danger" onclick="confirmarExcluir(\'' + rid + '\', \'' + rname + '\')">' +
                '<i class="fas fa-trash"></i> Excluir</button>' +
        '</td>';
    tbody.appendChild(tr);
}

// ─── Testar leitora existente ─────────────────────────────────
function testarLeitora(readerId, nome) {
    document.getElementById('modalTesteBody').innerHTML =
        '<div style="text-align:center;padding:24px;">' +
        '<i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--primary);"></i>' +
        '<p style="margin-top:12px;">Testando comunicação com <strong>' + escHtml(nome) + '</strong>...</p></div>';
    document.getElementById('modalTeste').classList.add('active');

    var fd = new FormData();
    fd.append('action', 'test');
    fd.append('reader_id', readerId);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var cor     = data.online ? '#27ae60' : '#e67e22';
            var icone   = data.online ? 'check-circle' : 'exclamation-triangle';
            var lastAct = data.last_activity ? new Date(data.last_activity).toLocaleString('pt-BR') : '—';

            var html = '<div style="border-left:4px solid ' + cor + ';padding:12px 16px;background:#f9f9f9;border-radius:4px;">';
            html += '<p style="font-size:15px;font-weight:bold;color:' + cor + ';margin-bottom:10px;">';
            html += '<i class="fas fa-' + icone + '"></i> ' + escHtml(data.status_label || (data.online ? 'ONLINE' : 'OFFLINE')) + '</p>';
            html += '<table style="font-size:13px;width:100%;border-collapse:collapse;">';
            html += '<tr><td style="width:150px;padding:3px 0;"><strong>Leitora:</strong></td><td>' + escHtml(nome) + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Serial:</strong></td><td><code>' + escHtml(data.serial || '—') + '</code></td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Modelo:</strong></td><td>' + escHtml(data.model || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Status SumUp:</strong></td><td>' + escHtml(data.sumup_status || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Bateria:</strong></td><td>' + (data.battery !== null && data.battery !== undefined ? data.battery + '%' : '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Conexão:</strong></td><td>' + escHtml(data.connection || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Firmware:</strong></td><td>' + escHtml(data.firmware || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Última Atividade:</strong></td><td>' + lastAct + '</td></tr>';
            html += '</table>';
            html += '<p style="margin-top:12px;font-size:13px;">' + escHtml(data.mensagem || '') + '</p>';
            html += '</div>';

            document.getElementById('modalTesteBody').innerHTML = html;

            // Atualizar badge e campos na tabela
            var badge = document.getElementById('badge-' + readerId);
            if (badge) {
                badge.className = 'badge ' + (data.online ? 'badge-success' : 'badge-warning');
                badge.innerHTML = '<i class="fas fa-circle"></i> ' + (data.online ? 'ONLINE' : 'OFFLINE');
            }
            var batEl  = document.getElementById('bat-'  + readerId);
            var connEl = document.getElementById('conn-' + readerId);
            var actEl  = document.getElementById('act-'  + readerId);
            if (batEl  && data.battery !== null && data.battery !== undefined) batEl.textContent  = data.battery + '%';
            if (connEl && data.connection)  connEl.textContent = data.connection;
            if (actEl  && data.last_activity) actEl.textContent = lastAct;
        })
        .catch(function() {
            document.getElementById('modalTesteBody').innerHTML =
                '<p style="color:#e74c3c;"><i class="fas fa-times-circle"></i> Erro de comunicação com o servidor.</p>';
        });
}

// ─── Confirmar exclusão ───────────────────────────────────────
function confirmarExcluir(readerId, nome) {
    readerIdParaExcluir = readerId;
    document.getElementById('nomeExcluir').textContent = nome;
    document.getElementById('modalExcluir').classList.add('active');
}

function fecharModalExcluir() {
    document.getElementById('modalExcluir').classList.remove('active');
    readerIdParaExcluir = '';
}

function excluirLeitora() {
    if (!readerIdParaExcluir) return;
    var btn = document.getElementById('btnConfirmarExcluir');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';

    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('reader_id', readerIdParaExcluir);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Excluir';
            fecharModalExcluir();

            if (data.success) {
                var row = document.getElementById('row-' + readerIdParaExcluir);
                if (row) row.remove();
                mostrarToast(data.mensagem, 'success');
                if (!document.querySelector('#tbodyLeitoras tr')) {
                    document.getElementById('tbodyLeitoras').innerHTML =
                        '<tr id="trVazio"><td colspan="8" style="text-align:center;color:var(--gray-500);padding:32px;">' +
                        '<i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>' +
                        'Nenhuma leitora cadastrada.</td></tr>';
                }
            } else {
                mostrarToast(data.error || 'Erro ao excluir leitora', 'danger');
            }
        })
        .catch(function(){ mostrarToast('Erro de comunicação com o servidor', 'danger'); });
}

// ─── Helpers ─────────────────────────────────────────────────
function mostrarResultado(tipo, html) {
    var el    = document.getElementById('resultadoTeste');
    var cores  = { success: '#d4edda', danger: '#f8d7da', info: '#d1ecf1', warning: '#fff3cd' };
    var bordas = { success: '#28a745', danger: '#dc3545', info: '#17a2b8', warning: '#ffc107' };
    el.style.display    = 'block';
    el.style.background = cores[tipo]  || '#f9f9f9';
    el.style.border     = '1px solid ' + (bordas[tipo] || '#ccc');
    el.innerHTML        = html;
}

function mostrarToast(msg, tipo) {
    var toast = document.createElement('div');
    var cores  = { success: '#28a745', danger: '#dc3545', info: '#17a2b8' };
    toast.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:6px;' +
        'background:' + (cores[tipo] || '#333') + ';color:#fff;font-size:14px;' +
        'box-shadow:0 4px 12px rgba(0,0,0,.2);max-width:400px;';
    toast.innerHTML = msg;
    document.body.appendChild(toast);
    setTimeout(function(){ toast.remove(); }, 4000);
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

// ─── Verificar status de todas as leitoras ao carregar ───────
document.addEventListener('DOMContentLoaded', function () {
    var badges = document.querySelectorAll('[id^="badge-"]');
    badges.forEach(function(badge) {
        var readerId = badge.id.replace('badge-', '');
        var fd = new FormData();
        fd.append('action', 'test');
        fd.append('reader_id', readerId);
        fetch(API_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                badge.className = 'badge ' + (data.online ? 'badge-success' : 'badge-warning');
                badge.innerHTML = '<i class="fas fa-circle"></i> ' + (data.online ? 'ONLINE' : 'OFFLINE');
                var batEl  = document.getElementById('bat-'  + readerId);
                var connEl = document.getElementById('conn-' + readerId);
                var actEl  = document.getElementById('act-'  + readerId);
                if (batEl  && data.battery !== null && data.battery !== undefined) batEl.textContent  = data.battery + '%';
                if (connEl && data.connection)  connEl.textContent = data.connection;
                if (actEl  && data.last_activity) actEl.textContent = new Date(data.last_activity).toLocaleString('pt-BR');
            })
            .catch(function() {
                badge.className = 'badge badge-secondary';
                badge.innerHTML = '<i class="fas fa-circle"></i> Erro';
            });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
