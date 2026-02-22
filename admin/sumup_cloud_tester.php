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
        Envia R$ 1,00 para a leitora em debito/credito e executa cancelamento para validacao completa.
    </p>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-vial"></i> Teste de Transacao</h4>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="readerSelect">Leitora (reader_id)</label>
                    <select id="readerSelect" class="form-control">
                        <option value="">Carregando leitoras...</option>
                    </select>
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
                        <i class="fas fa-credit-card"></i> Testar Debito R$1,00
                    </button>
                    <button class="btn btn-info" onclick="runCheckout('credit')">
                        <i class="fas fa-credit-card"></i> Testar Credito R$1,00
                    </button>
                    <button class="btn btn-warning" onclick="cancelTransaction()">
                        <i class="fas fa-ban"></i> Cancelar na Leitora
                    </button>
                    <button class="btn btn-secondary" onclick="checkReaderStatus()">
                        <i class="fas fa-signal"></i> Ler Status da Leitora
                    </button>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h4><i class="fas fa-terminal"></i> Resposta da API</h4>
            </div>
            <div class="card-body">
                <div id="resultBox" style="background:#111;color:#d4d4d4;border-radius:8px;padding:12px;min-height:180px;white-space:pre-wrap;font-family:Consolas,monospace;font-size:12px;"></div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-info-circle"></i> Configuracao Atual</h4>
            </div>
            <div class="card-body" id="cfgBox">
                Carregando...
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h4><i class="fas fa-file-alt"></i> Logs</h4>
            </div>
            <div class="card-body">
                <p style="margin-bottom:8px;">
                    Este teste grava cada request/response em:
                </p>
                <code>/logs/paymentslogs.log</code>
                <div style="margin-top:10px;">
                    <a class="btn btn-sm btn-outline-primary" href="logs.php?log=paymentslogs.log" target="_blank">
                        Abrir paymentslogs.log
                    </a>
                    <a class="btn btn-sm btn-outline-secondary" href="logs_viewer.php?modulo=payments" target="_blank">
                        Abrir Viewer (modulo payments)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const TEST_API = '<?php echo SITE_URL; ?>/api/sumup_cloud_tester.php';

function setResult(title, payload) {
    const box = document.getElementById('resultBox');
    const ts = new Date().toLocaleString('pt-BR');
    box.textContent = '[' + ts + '] ' + title + '\n\n' + JSON.stringify(payload, null, 2);
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
            '<p><strong>Merchant:</strong> ' + (data.merchant_code || '-') + '</p>' +
            '<p><strong>Token:</strong> <code>' + (data.token_masked || '-') + '</code></p>' +
            '<p><strong>Affiliate Key:</strong> <code>' + (data.affiliate_key_masked || '-') + '</code></p>' +
            '<p><strong>Affiliate App ID:</strong> <code>' + (data.affiliate_app_id || '-') + '</code></p>';
    } catch (e) {
        document.getElementById('cfgBox').innerHTML = '<span style="color:#dc3545;">Erro ao carregar configuracao.</span>';
    }
}

async function loadReaders() {
    const sel = document.getElementById('readerSelect');
    sel.innerHTML = '<option value="">Carregando leitoras...</option>';
    try {
        const resp = await fetch(TEST_API + '?action=readers');
        const data = await resp.json();
        if (!data.success || !Array.isArray(data.readers) || data.readers.length === 0) {
            sel.innerHTML = '<option value="">Nenhuma leitora retornada pela SumUp</option>';
            setResult('Leitoras', data);
            return;
        }

        sel.innerHTML = '<option value="">Selecione...</option>';
        data.readers.forEach((r) => {
            const rid = r.id || '';
            const serial = (r.device && r.device.identifier) ? r.device.identifier : '-';
            const status = r.status_live || r.status || 'UNKNOWN';
            const opt = document.createElement('option');
            opt.value = rid;
            opt.textContent = rid + ' | Serial: ' + serial + ' | Status: ' + status;
            opt.dataset.meta = JSON.stringify(r);
            sel.appendChild(opt);
        });
        setResult('Leitoras carregadas', data);
    } catch (e) {
        sel.innerHTML = '<option value="">Erro ao carregar leitoras</option>';
        setResult('Erro ao carregar leitoras', { error: String(e) });
    }
}

document.getElementById('readerSelect').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const meta = opt && opt.dataset.meta ? JSON.parse(opt.dataset.meta) : null;
    const serial = meta && meta.device ? (meta.device.identifier || '-') : '-';
    const st = meta ? (meta.status_live || meta.status || '-') : '-';
    document.getElementById('readerMeta').textContent = 'Serial: ' + serial + ' | Status: ' + st;
});

async function runCheckout(cardType) {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione um reader_id' });
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

async function checkReaderStatus() {
    const readerId = getReaderId();
    if (!readerId) {
        setResult('Validacao', { error: 'Selecione um reader_id' });
        return;
    }
    const resp = await fetch(TEST_API + '?action=reader_status&reader_id=' + encodeURIComponent(readerId));
    const data = await resp.json();
    setResult('Status da leitora', data);
}

document.addEventListener('DOMContentLoaded', async function () {
    await loadConfig();
    await loadReaders();
});
</script>

<?php require_once '../includes/footer.php'; ?>
