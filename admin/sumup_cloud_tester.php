<?php
$page_title = 'SumUp Cloud Tester';
$current_page = 'pagamentos';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

requireAuth();
?>

<div class="page-header">
    <h1>Teste SumUp Cloud API</h1>
    <p style="color: var(--gray-600); margin-top: 6px;">
        Gerenciamento completo de leitoras: teste de transacoes, status em tempo real e exclusao de readers vinculados.
    </p>
</div>

<div class="row">
    <div class="col-md-8">

        <!-- CARD: Teste de Transacao -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-vial"></i> Teste de Transacao</h4>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="readerSelect">Leitora (reader_id)</label>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select id="readerSelect" class="form-control" style="flex:1;">
                            <option value="">Carregando leitoras...</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary" onclick="loadReaders()" title="Recarregar leitoras">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    <small id="readerMeta" style="color: var(--gray-600); display: block; margin-top: 4px;">
                        Selecione a leitora conectada.
                    </small>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="amountInput">Valor (BRL)</label>
                            <input type="number" min="1" step="0.01" id="amountInput" class="form-control" value="1.00">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="descInput">Descricao</label>
                            <input type="text" id="descInput" class="form-control" value="Teste Cloud API R$1,00">
                        </div>
                    </div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top: 10px;">
                    <button class="btn btn-primary" onclick="runCheckout('debit')">
                        <i class="fas fa-credit-card"></i> Testar Debito
                    </button>
                    <button class="btn btn-info" onclick="runCheckout('credit')">
                        <i class="fas fa-credit-card"></i> Testar Credito
                    </button>
                    <button class="btn btn-warning" onclick="cancelTransaction()">
                        <i class="fas fa-ban"></i> Cancelar na Leitora
                    </button>
                    <button class="btn btn-secondary" onclick="checkReaderStatus()">
                        <i class="fas fa-signal"></i> Ler Status
                    </button>
                    <button class="btn btn-success" onclick="runHealthCheck()">
                        <i class="fas fa-heartbeat"></i> Health Check
                    </button>
                </div>
            </div>
        </div>

        <!-- CARD: Gerenciamento de Leitoras -->
        <div class="card" style="margin-top:16px; border: 2px solid #dc3545;">
            <div class="card-header" style="background: #fff5f5;">
                <h4 style="color:#dc3545;"><i class="fas fa-trash-alt"></i> Gerenciamento de Leitoras</h4>
                <small style="color:#6c757d;">Use estas opcoes para desvincular leitoras da conta SumUp (necessario para testes)</small>
            </div>
            <div class="card-body">

                <!-- Deletar leitora selecionada -->
                <div style="background:#fff8f8; border:1px solid #f5c6cb; border-radius:6px; padding:12px; margin-bottom:12px;">
                    <h6 style="color:#721c24; margin-bottom:8px;"><i class="fas fa-minus-circle"></i> Deletar Leitora Selecionada</h6>
                    <p style="font-size:13px; color:#6c757d; margin-bottom:10px;">
                        Remove a leitora selecionada acima da conta SumUp. Apos deletar, desconecte fisicamente no dispositivo:
                        <strong>Menu &gt; Connections &gt; API &gt; Disconnect</strong>
                    </p>
                    <button class="btn btn-danger" onclick="deleteSelectedReader()">
                        <i class="fas fa-trash"></i> Deletar Leitora Selecionada
                    </button>
                </div>

                <!-- Deletar todas as leitoras -->
                <div style="background:#fff0f0; border:1px solid #f5c6cb; border-radius:6px; padding:12px;">
                    <h6 style="color:#721c24; margin-bottom:8px;"><i class="fas fa-bomb"></i> Deletar TODAS as Leitoras</h6>
                    <p style="font-size:13px; color:#6c757d; margin-bottom:10px;">
                        Remove <strong>TODAS</strong> as leitoras vinculadas a esta conta SumUp. Esta acao e irreversivel.
                        Utilize apenas para limpar o ambiente de testes.
                    </p>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <input type="text" id="confirmDeleteAll" class="form-control" style="max-width:200px;"
                            placeholder='Digite "DELETE_ALL"' autocomplete="off">
                        <button class="btn btn-danger" onclick="deleteAllReaders()" style="background:#8b0000; border-color:#8b0000;">
                            <i class="fas fa-bomb"></i> Deletar TODAS as Leitoras
                        </button>
                    </div>
                    <small style="color:#6c757d; margin-top:6px; display:block;">
                        Digite exatamente <code>DELETE_ALL</code> no campo acima para confirmar.
                    </small>
                </div>

            </div>
        </div>

        <!-- CARD: Resposta da API -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h4><i class="fas fa-terminal"></i> Resposta da API</h4>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearResult()">
                    <i class="fas fa-times"></i> Limpar
                </button>
            </div>
            <div class="card-body">
                <div id="resultBox" style="background:#111;color:#d4d4d4;border-radius:8px;padding:12px;min-height:180px;white-space:pre-wrap;font-family:Consolas,monospace;font-size:12px;"></div>
            </div>
        </div>

    </div>

    <div class="col-md-4">

        <!-- CARD: Configuracao Atual -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-info-circle"></i> Configuracao Atual</h4>
            </div>
            <div class="card-body" id="cfgBox">
                Carregando...
            </div>
        </div>

        <!-- CARD: Leitoras Vinculadas -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h4><i class="fas fa-list"></i> Leitoras Vinculadas</h4>
                <button class="btn btn-sm btn-outline-primary" onclick="loadReadersTable()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
            <div class="card-body" id="readersTableBox" style="padding:8px;">
                <p style="color:#6c757d; font-size:13px;">Carregando...</p>
            </div>
        </div>

        <!-- CARD: Logs -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h4><i class="fas fa-file-alt"></i> Logs</h4>
            </div>
            <div class="card-body">
                <p style="margin-bottom:8px; font-size:13px;">
                    Este teste grava cada request/response em:
                </p>
                <code style="font-size:11px;">/logs/paymentslogs.log</code><br>
                <code style="font-size:11px;">/logs/sumup_cloud_YYYY-MM-DD.log</code>
                <div style="margin-top:10px; display:flex; gap:6px; flex-wrap:wrap;">
                    <a class="btn btn-sm btn-outline-primary" href="logs.php?log=paymentslogs.log" target="_blank">
                        paymentslogs.log
                    </a>
                    <a class="btn btn-sm btn-outline-secondary" href="logs_viewer.php?modulo=payments" target="_blank">
                        Viewer payments
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
const TEST_API = '<?php echo SITE_URL; ?>/api/sumup_cloud_tester.php';

// ============================================================
// Utilitarios
// ============================================================

function setResult(title, payload) {
    const box = document.getElementById('resultBox');
    const ts = new Date().toLocaleString('pt-BR');
    const isSuccess = payload && (payload.success === true || (payload.json && payload.json.success === true));
    const color = isSuccess ? '#98fb98' : '#ff8080';
    box.innerHTML = '';
    const header = document.createElement('span');
    header.style.color = color;
    header.textContent = '[' + ts + '] ' + title + '\n\n';
    box.appendChild(header);
    const body = document.createTextNode(JSON.stringify(payload, null, 2));
    box.appendChild(body);
}

function clearResult() {
    document.getElementById('resultBox').innerHTML = '<span style="color:#666;">Aguardando acao...</span>';
}

function getReaderId() {
    return (document.getElementById('readerSelect').value || '').trim();
}

function getAmount() {
    const v = parseFloat(document.getElementById('amountInput').value || '1');
    return Number.isFinite(v) && v > 0 ? v : 1.00;
}

function getDescription() {
    return (document.getElementById('descInput').value || 'Teste Cloud API R$1,00').trim();
}

async function postAction(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(data).forEach((k) => fd.append(k, data[k]));
    const resp = await fetch(TEST_API, { method: 'POST', body: fd });
    const json = await resp.json();
    return { ok: resp.ok, status: resp.status, json };
}

function extractReaderStatusFromPayload(payload) {
    if (!payload || typeof payload !== 'object') return 'UNKNOWN';
    const statusRoot = payload.status && typeof payload.status === 'object'
        ? (payload.status.data && typeof payload.status.data === 'object' ? payload.status.data : payload.status)
        : {};
    const raw = statusRoot.status || statusRoot.state || statusRoot.connection_status || 'UNKNOWN';
    return String(raw || 'UNKNOWN').trim().toUpperCase();
}

function isReaderReadyStatus(status) {
    return ['ONLINE', 'CONNECTED', 'READY', 'READY_TO_TRANSACT'].includes(String(status || '').toUpperCase());
}

async function fetchReaderStatus(readerId) {
    const resp = await fetch(TEST_API + '?action=reader_status&reader_id=' + encodeURIComponent(readerId));
    const data = await resp.json();
    return { ok: resp.ok, status: resp.status, data };
}

function statusBadge(status) {
    const s = (status || 'UNKNOWN').toUpperCase();
    const colors = {
        'ONLINE': '#28a745', 'CONNECTED': '#28a745', 'READY': '#28a745', 'READY_TO_TRANSACT': '#28a745',
        'OFFLINE': '#dc3545', 'UNKNOWN': '#6c757d', 'PROCESSING': '#ffc107', 'PAIRED': '#17a2b8',
        'EXPIRED': '#fd7e14'
    };
    const bg = colors[s] || '#6c757d';
    return '<span style="background:' + bg + ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;">' + s + '</span>';
}

// ============================================================
// Carregar Configuracao
// ============================================================

async function loadConfig() {
    try {
        const resp = await fetch(TEST_API + '?action=config');
        const data = await resp.json();
        const el = document.getElementById('cfgBox');
        if (!data.success) {
            el.innerHTML = '<span style="color:#dc3545;">Falha ao carregar configuracao.</span>';
            return;
        }
        el.innerHTML =
            '<p style="margin-bottom:6px;"><strong>Merchant:</strong> <code>' + (data.merchant_code || '-') + '</code></p>' +
            '<p style="margin-bottom:6px;"><strong>Token:</strong> <code>' + (data.token_masked || '-') + '</code></p>' +
            '<p style="margin-bottom:6px;"><strong>Affiliate Key:</strong> <code>' + (data.affiliate_key_masked || '-') + '</code></p>' +
            '<p style="margin-bottom:6px;"><strong>Affiliate App ID:</strong> <code>' + (data.affiliate_app_id || '-') + '</code></p>' +
            '<p style="margin-bottom:0;"><strong>Affiliate Ativo:</strong> ' + (data.has_affiliate ? '<span style="color:#28a745;">Sim</span>' : '<span style="color:#6c757d;">Nao</span>') + '</p>';
    } catch (e) {
        document.getElementById('cfgBox').innerHTML = '<span style="color:#dc3545;">Erro ao carregar configuracao.</span>';
    }
}

// ============================================================
// Carregar Leitoras (Select)
// ============================================================

/**
 * Normaliza o campo readers da resposta da API.
 * A SumUp pode retornar: array direto, { items: [...] } ou objeto aninhado.
 */
function normalizeReaders(data) {
    if (!data || !data.success) return [];
    const r = data.readers;
    if (Array.isArray(r)) return r;
    if (r && Array.isArray(r.items)) return r.items;
    if (r && typeof r === 'object') {
        // Tenta extrair qualquer array dentro do objeto
        const keys = Object.keys(r);
        for (const k of keys) {
            if (Array.isArray(r[k])) return r[k];
        }
    }
    return [];
}

async function loadReaders() {
    const sel = document.getElementById('readerSelect');
    sel.innerHTML = '<option value="">Carregando leitoras...</option>';
    try {
        const resp = await fetch(TEST_API + '?action=readers_db');
        const data = await resp.json();
        const readers = normalizeReaders(data);
        if (!data.success || readers.length === 0) {
            sel.innerHTML = '<option value="">Nenhuma leitora encontrada</option>';
            setResult('Leitoras (' + (data.source || 'sem fonte') + ')', data);
            await loadReadersTable();
            return;
        }

        sel.innerHTML = '<option value="">Selecione...</option>';
        readers.forEach((r) => {
            const rid = r.reader_id || r.id || '';
            const serial = r.serial || ((r.device && r.device.identifier) ? r.device.identifier : '-');
            const modelo = r.model || '-';
            const status = r.status_live || r.status || 'UNKNOWN';
            const estab = r.estabelecimento_nome || '-';
            const opt = document.createElement('option');
            opt.value = rid;
            opt.textContent = rid + ' | ' + serial + ' | ' + modelo + ' | ' + status + ' | ' + estab;
            opt.dataset.meta = JSON.stringify(r);
            sel.appendChild(opt);
        });
        setResult('Leitoras carregadas (' + readers.length + ') [fonte: ' + (data.source || '?') + ']', data);
        await loadReadersTable();
    } catch (e) {
        sel.innerHTML = '<option value="">Erro ao carregar leitoras</option>';
        setResult('Erro ao carregar leitoras', { error: String(e) });
    }
}

// ============================================================
// Tabela de Leitoras Vinculadas (sidebar)
// ============================================================

async function loadReadersTable() {
    const box = document.getElementById('readersTableBox');
    box.innerHTML = '<p style="color:#6c757d; font-size:13px;">Carregando...</p>';
    try {
        const resp = await fetch(TEST_API + '?action=readers_db');
        const data = await resp.json();
        const readers = normalizeReaders(data);

        if (!data.success || readers.length === 0) {
            box.innerHTML = '<p style="color:#6c757d; font-size:13px; text-align:center; padding:12px;">Nenhuma leitora vinculada.</p>';
            return;
        }

        let html = '<div style="font-size:12px;">';
        readers.forEach((r, i) => {
            const rid = r.reader_id || r.id || '';
            const name = r.name || r.serial || rid.substring(0, 16) + '...';
            const status = r.status_live || r.status || 'UNKNOWN';
            const battery = r.battery_level != null ? r.battery_level + '%' : '-';
            const estab = r.estabelecimento_nome || '-';
            html += '<div style="border:1px solid #dee2e6; border-radius:6px; padding:8px; margin-bottom:8px;">';
            html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">';
            html += '<strong style="font-size:11px;">' + (name.length > 20 ? name.substring(0, 20) + '...' : name) + '</strong>';
            html += statusBadge(status);
            html += '</div>';
            html += '<div style="color:#6c757d; font-size:11px; margin-bottom:4px;">ID: <code>' + rid.substring(0, 20) + '...</code></div>';
            html += '<div style="color:#6c757d; font-size:11px; margin-bottom:6px;">Estab: ' + estab + ' | Bat: ' + battery + '</div>';
            html += '<button class="btn btn-xs btn-danger" style="font-size:11px; padding:2px 8px;" onclick="deleteReaderById(\'' + rid + '\')">';
            html += '<i class="fas fa-trash"></i> Deletar</button>';
            html += '</div>';
        });
        html += '<div style="color:#6c757d; font-size:11px; text-align:right; margin-top:4px;">';
        html += readers.length + ' leitora(s) vinculada(s) [fonte: ' + (data.source || '?') + ']';
        html += '</div>';
        html += '</div>';
        box.innerHTML = html;
    } catch (e) {
        box.innerHTML = '<p style="color:#dc3545; font-size:13px;">Erro ao carregar leitoras.</p>';
    }
}

document.getElementById('readerSelect').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const meta = opt && opt.dataset.meta ? JSON.parse(opt.dataset.meta) : null;
    const rid = meta ? (meta.reader_id || meta.id || '-') : '-';
    const serial = meta ? (meta.serial || (meta.device ? (meta.device.identifier || '-') : '-')) : '-';
    const model = meta ? (meta.model || '-') : '-';
    const st = meta ? (meta.status_live || meta.status || '-') : '-';
    const conn = meta ? (meta.connection_type || '-') : '-';
    const battery = meta ? (meta.battery_level ?? '-') : '-';
    const last = meta ? (meta.last_activity || '-') : '-';
    const estab = meta ? (meta.estabelecimento_nome || '-') : '-';
    document.getElementById('readerMeta').textContent =
        'Reader ID: ' + rid +
        ' | Serial: ' + serial +
        ' | Modelo: ' + model +
        ' | Status: ' + st +
        ' | Conexao: ' + conn +
        ' | Bateria: ' + battery +
        ' | Ultima atividade: ' + last +
        ' | Estab: ' + estab;
});

// ============================================================
// Checkout
// ============================================================

async function runCheckout(cardType) {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione um reader_id' });
        return;
    }

    setResult('Pre-check da leitora...', { reader_id: readerId });
    const pre = await fetchReaderStatus(readerId);
    const preStatus = extractReaderStatusFromPayload(pre.data || {});
    const ready = pre.ok && pre.data && pre.data.success && isReaderReadyStatus(preStatus);

    if (!ready) {
        setResult('Checkout bloqueado - leitora nao pronta', {
            precheck: pre,
            status_interpretado: preStatus,
            next_steps: [
                'No SumUp Solo acesse Connections -> API -> Connect.',
                'Confirme no display: Connected / Ready to transact.',
                'Clique em "Ler Status" e tente novamente.'
            ]
        });
        return;
    }

    const amount = getAmount();
    const description = getDescription();
    setResult('Enviando checkout ' + cardType + '...', { reader_id: readerId, amount, description });
    const out = await postAction('checkout', {
        reader_id: readerId,
        card_type: cardType,
        amount: amount.toFixed(2),
        description
    });
    setResult('Checkout ' + cardType + ' concluido', out);
}

// ============================================================
// Cancelar Transacao
// ============================================================

async function cancelTransaction() {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione um reader_id' });
        return;
    }
    setResult('Enviando cancelamento...', { reader_id: readerId });
    const out = await postAction('cancel', { reader_id: readerId });
    setResult('Cancelamento concluido', out);
}

// ============================================================
// Status da Leitora
// ============================================================

async function checkReaderStatus() {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione um reader_id' });
        return;
    }
    const out = await fetchReaderStatus(readerId);
    setResult('Status da leitora', {
        ...out.data,
        status_interpretado: extractReaderStatusFromPayload(out.data || {}),
        pronta_para_checkout: isReaderReadyStatus(extractReaderStatusFromPayload(out.data || {}))
    });
}

// ============================================================
// Health Check
// ============================================================

async function runHealthCheck() {
    setResult('Executando health check...', {});
    try {
        const resp = await fetch(TEST_API + '?action=health_check');
        const data = await resp.json();
        setResult('Health Check ' + (data.success ? '✓ Saudavel' : '✗ Problemas detectados'), data);
    } catch (e) {
        setResult('Erro no health check', { error: String(e) });
    }
}

// ============================================================
// Deletar Leitora Selecionada
// ============================================================

async function deleteSelectedReader() {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione uma leitora no campo acima antes de deletar.' });
        return;
    }
    await deleteReaderById(readerId);
}

// ============================================================
// Deletar Leitora por ID (com confirmacao)
// ============================================================

async function deleteReaderById(readerId) {
    if (!readerId) return;

    const shortId = readerId.length > 24 ? readerId.substring(0, 24) + '...' : readerId;
    const confirmed = confirm(
        'ATENCAO: Esta acao e irreversivel!\n\n' +
        'Voce esta prestes a deletar a leitora:\n' +
        shortId + '\n\n' +
        'Apos deletar, voce devera desconectar fisicamente no dispositivo:\n' +
        'Menu > Connections > API > Disconnect\n\n' +
        'Confirmar exclusao?'
    );

    if (!confirmed) {
        setResult('Cancelado', { message: 'Exclusao cancelada pelo usuario.' });
        return;
    }

    setResult('Deletando leitora...', { reader_id: readerId });

    try {
        const out = await postAction('delete_reader', { reader_id: readerId, confirm: 'yes' });
        setResult(
            out.json && out.json.success ? '✓ Leitora deletada com sucesso' : '✗ Falha ao deletar leitora',
            out
        );

        if (out.json && out.json.success) {
            // Recarregar lista apos deleção
            await loadReaders();
        }
    } catch (e) {
        setResult('Erro ao deletar leitora', { error: String(e) });
    }
}

// ============================================================
// Deletar TODAS as Leitoras (dupla confirmacao)
// ============================================================

async function deleteAllReaders() {
    const confirmInput = (document.getElementById('confirmDeleteAll').value || '').trim();

    if (confirmInput !== 'DELETE_ALL') {
        alert('Digite exatamente "DELETE_ALL" no campo de confirmacao para prosseguir.');
        document.getElementById('confirmDeleteAll').focus();
        return;
    }

    const confirmed = confirm(
        '⚠️ AVISO CRITICO: ESTA ACAO E COMPLETAMENTE IRREVERSIVEL!\n\n' +
        'Voce esta prestes a deletar TODAS as leitoras vinculadas a esta conta SumUp.\n\n' +
        'Apos a exclusao, cada dispositivo precisara ser desconectado manualmente:\n' +
        'Menu > Connections > API > Disconnect\n\n' +
        'Tem CERTEZA ABSOLUTA que deseja continuar?'
    );

    if (!confirmed) {
        setResult('Cancelado', { message: 'Exclusao em lote cancelada pelo usuario.' });
        return;
    }

    // Segunda confirmacao
    const confirmed2 = confirm(
        'ULTIMA CONFIRMACAO:\n\n' +
        'Deletar TODAS as leitoras da conta?\n\n' +
        'Esta e sua ultima chance de cancelar.'
    );

    if (!confirmed2) {
        setResult('Cancelado', { message: 'Exclusao em lote cancelada na segunda confirmacao.' });
        return;
    }

    setResult('Deletando TODAS as leitoras...', { warning: 'Aguarde, processando...' });

    try {
        const out = await postAction('delete_all_readers', {
            confirm: 'yes',
            confirm_all: 'DELETE_ALL'
        });

        const summary = out.json && out.json.summary ? out.json.summary : {};
        const title = out.json && out.json.success
            ? '✓ Todas as leitoras deletadas (' + (summary.deleted || 0) + '/' + (summary.total || 0) + ')'
            : '⚠ Exclusao parcial: ' + (summary.deleted || 0) + ' deletadas, ' + (summary.failed || 0) + ' falharam';

        setResult(title, out);

        // Limpar campo de confirmacao e recarregar lista
        document.getElementById('confirmDeleteAll').value = '';
        await loadReaders();
    } catch (e) {
        setResult('Erro ao deletar leitoras', { error: String(e) });
    }
}

// ============================================================
// Inicializacao
// ============================================================

document.addEventListener('DOMContentLoaded', async function () {
    clearResult();
    await loadConfig();
    await loadReaders();
});
</script>

<?php require_once '../includes/footer.php'; ?>
