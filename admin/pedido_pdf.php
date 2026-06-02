<?php
/**
 * pedido_pdf.php
 * Gera o PDF/impressão do pedido de estoque.
 * Cabeçalho com dados do estabelecimento emissor (nome, CNPJ, telefone, endereço).
 * Campo de pagamento e texto fixo de condições de entrega.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/PedidoEstoqueManager.php';
requireAuth();

$conn          = getDBConnection();
$user_estab_id = isAdminGeral() ? null : getEstabelecimentoId();
$pm            = new PedidoEstoqueManager($conn, $user_estab_id);

$pedido_id = (int)($_GET['id'] ?? 0);
if (!$pedido_id) {
    http_response_code(400);
    die('ID do pedido não informado.');
}

$pedido = $pm->buscarPorId($pedido_id);
if (!$pedido) {
    http_response_code(404);
    die('Pedido não encontrado.');
}

$itens = $pm->buscarItens($pedido_id);

// ── Dados do estabelecimento EMISSOR (origem do pedido) ───────────────────
// Prioridade: estab_origem_id do pedido → estabelecimento do usuário logado → primeiro ativo
$emissor_id = $pedido['estab_origem_id'] ?? $user_estab_id;
$emissor    = null;
if ($emissor_id) {
    $stmt_em = $conn->prepare("SELECT name, document, phone, address FROM estabelecimentos WHERE id = ? LIMIT 1");
    $stmt_em->execute([$emissor_id]);
    $emissor = $stmt_em->fetch(PDO::FETCH_ASSOC);
}
if (!$emissor) {
    $stmt_em = $conn->query("SELECT name, document, phone, address FROM estabelecimentos WHERE status = 1 ORDER BY id ASC LIMIT 1");
    $emissor = $stmt_em->fetch(PDO::FETCH_ASSOC);
}
$emissor_nome = $emissor['name']     ?? 'Chopp On Tap';
$emissor_cnpj = $emissor['document'] ?? '';
$emissor_tel  = $emissor['phone']    ?? '';
$emissor_end  = $emissor['address']  ?? '';

// ── Dados do destinatário ─────────────────────────────────────────────────
if ($pedido['tipo_destinatario'] === 'estabelecimento') {
    $dest_nome     = $pedido['estabelecimento_nome']     ?? '—';
    $dest_doc      = $pedido['estabelecimento_document'] ?? '';
    $dest_email    = '';
    $dest_telefone = $pedido['estabelecimento_phone']    ?? '';
    $dest_end      = $pedido['estabelecimento_address']  ?? '';
} else {
    $dest_nome     = $pedido['cliente_nome']     ?? '—';
    $dest_doc      = $pedido['cliente_cpf_cnpj'] ?? '';
    $dest_email    = $pedido['cliente_email']    ?? '';
    $dest_telefone = $pedido['cliente_telefone'] ?? '';
    $dest_end      = '';
}

// ── Pagamento ─────────────────────────────────────────────────────────────
$pagamento_labels = [
    'pix'           => 'PIX',
    'debito'        => 'Cartão de Débito',
    'credito'       => 'Cartão de Crédito',
    'entrada_50_50' => 'Entrada 50% + 50% na Entrega',
];
$pag_key   = $pedido['pagamento'] ?? 'pix';
$pag_label = $pagamento_labels[$pag_key] ?? $pag_key;

// ── Status label e cor ────────────────────────────────────────────────────
$status_label = match($pedido['status']) {
    'aguardando'  => 'AGUARDANDO',
    'visualizado' => 'VISUALIZADO',
    'faturado'    => 'FATURADO',
    'cancelado'   => 'CANCELADO',
    default       => strtoupper($pedido['status']),
};
$status_color = match($pedido['status']) {
    'aguardando'  => '#f59e0b',
    'visualizado' => '#007bff',
    'faturado'    => '#28a745',
    'cancelado'   => '#dc3545',
    default       => '#666',
};

// ── Endereço de entrega ───────────────────────────────────────────────────
$entrega_str = '';
if ($pedido['entrega']) {
    $partes = array_filter([
        trim($pedido['entrega_logradouro']  ?? ''),
        trim($pedido['entrega_numero']      ?? ''),
        trim($pedido['entrega_complemento'] ?? ''),
        trim($pedido['entrega_bairro']      ?? ''),
        trim($pedido['entrega_cidade']      ?? ''),
        trim($pedido['entrega_estado']      ?? ''),
        trim($pedido['entrega_cep']         ?? ''),
    ]);
    $entrega_str = implode(', ', $partes);
}

// ── Logo em base64 ────────────────────────────────────────────────────────
$logo_path = realpath(__DIR__ . '/../assets/images/logo.png');
$logo_b64  = '';
if ($logo_path && file_exists($logo_path)) {
    $logo_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Pedido <?= htmlspecialchars($pedido['numero_pedido']) ?> — <?= htmlspecialchars($emissor_nome) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #222; background: #fff; }

  /* Barra de ações (não imprime) */
  .no-print { display: flex; gap: 10px; padding: 14px 20px; background: #f0f4f8; border-bottom: 1px solid #ddd; }
  .btn-print { padding: 9px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
  .btn-print.blue { background: #007bff; color: #fff; }
  .btn-print.gray { background: #6c757d; color: #fff; }
  @media print { .no-print { display: none !important; } body { padding: 0; } }

  /* Layout do documento */
  .doc { max-width: 820px; margin: 0 auto; padding: 30px 30px 40px; }

  /* Cabeçalho do emissor */
  .doc-header { background: linear-gradient(135deg, #0066cc, #004499); color: #fff; border-radius: 10px; padding: 22px 26px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
  .doc-header .emissor { display: flex; align-items: center; gap: 16px; }
  .doc-header .emissor img { height: 58px; background: #fff; border-radius: 6px; padding: 4px; }
  .doc-header .emissor-logo-placeholder { width: 58px; height: 58px; background: rgba(255,255,255,.2); border-radius: 6px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; }
  .doc-header .emissor-nome { font-size: 20px; font-weight: 700; }
  .doc-header .emissor-detalhe { font-size: 12px; opacity: .85; margin-top: 2px; }
  .doc-header .pedido-info { text-align: right; }
  .doc-header .pedido-num  { font-size: 26px; font-weight: 700; font-family: monospace; }
  .doc-header .pedido-data { font-size: 12px; opacity: .8; margin-top: 3px; }
  .status-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #fff; margin-top: 6px; background: <?= $status_color ?>; }

  /* Seções */
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #555; border-bottom: 2px solid #0066cc; padding-bottom: 5px; margin-bottom: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
  .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .info-item .label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .4px; }
  .info-item .value { font-size: 13px; font-weight: 600; margin-top: 2px; }

  /* Pagamento — linha única compacta */
  .pag-row { display: flex; align-items: center; gap: 12px; background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 10px 16px; flex-wrap: wrap; }
  .pag-row.debito  { background: #e3f2fd; border-color: #90caf9; }
  .pag-row.credito { background: #f3e5f5; border-color: #ce93d8; }
  .pag-row.entrada { background: #fff8e1; border-color: #ffe082; }
  .pag-label { font-size: 14px; font-weight: 700; color: #1b5e20; white-space: nowrap; }
  .pag-row.debito .pag-label  { color: #0d47a1; }
  .pag-row.credito .pag-label { color: #4a148c; }
  .pag-row.entrada .pag-label { color: #e65100; }
  .pag-sep { color: #aaa; font-size: 16px; }
  .pix-key { font-family: monospace; font-size: 16px; font-weight: 700; color: #1b5e20; letter-spacing: 1px; }
  .pix-hint { font-size: 11px; color: #388e3c; }

  /* Tabela de itens */
  .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .items-table thead tr { background: #0066cc; color: #fff; }
  .items-table thead th { padding: 9px 10px; text-align: left; font-weight: 600; }
  .items-table tbody tr:nth-child(even) { background: #f5f8ff; }
  .items-table tbody td { padding: 8px 10px; border-bottom: 1px solid #eee; }

  /* Totais */
  .totals-box { margin-left: auto; width: 300px; background: linear-gradient(135deg, #0066cc, #004499); color: #fff; border-radius: 10px; padding: 16px 20px; margin-top: 16px; }
  .total-line  { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
  .total-final { display: flex; justify-content: space-between; padding-top: 10px; margin-top: 8px; border-top: 1px solid rgba(255,255,255,.3); font-size: 18px; font-weight: 700; }

  /* Entrega */
  .entrega-box { background: #e8f5e9; border-left: 4px solid #28a745; border-radius: 6px; padding: 12px 16px; margin-bottom: 10px; }

  /* Aviso operacional */
  .aviso-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 16px; font-size: 12px; color: #856404; margin-top: 10px; }
  .aviso-box strong { display: block; margin-bottom: 4px; font-size: 13px; }

  /* Rodapé */
  .doc-footer { margin-top: 30px; padding-top: 14px; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between; font-size: 11px; color: #888; }
</style>
</head>
<body>

<!-- Barra de ações (não imprime) -->
<div class="no-print">
    <button class="btn-print blue" onclick="window.print()">
        <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/></svg>
        Imprimir / Salvar PDF
    </button>
    <a href="estoque_pedidos.php?ver=<?= $pedido_id ?>" class="btn-print gray">← Voltar</a>
</div>

<div class="doc">

    <!-- ── Cabeçalho com dados do estabelecimento EMISSOR ── -->
    <div class="doc-header">
        <div class="emissor">
            <?php if ($logo_b64): ?>
            <img src="<?= $logo_b64 ?>" alt="<?= htmlspecialchars($emissor_nome) ?>">
            <?php else: ?>
            <div class="emissor-logo-placeholder">C</div>
            <?php endif; ?>
            <div>
                <div class="emissor-nome"><?= htmlspecialchars($emissor_nome) ?></div>
                <?php if ($emissor_cnpj): ?>
                <div class="emissor-detalhe">CNPJ: <?= htmlspecialchars($emissor_cnpj) ?></div>
                <?php endif; ?>
                <?php if ($emissor_tel): ?>
                <div class="emissor-detalhe">Tel: <?= htmlspecialchars($emissor_tel) ?></div>
                <?php endif; ?>
                <?php if ($emissor_end): ?>
                <div class="emissor-detalhe" style="font-size:11px;opacity:.75;"><?= htmlspecialchars($emissor_end) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pedido-info">
            <div class="pedido-num"><?= htmlspecialchars($pedido['numero_pedido']) ?></div>
            <div class="pedido-data">Emitido em: <?= date('d/m/Y \à\s H:i', strtotime($pedido['created_at'])) ?></div>
            <div class="pedido-data">Por: <?= htmlspecialchars($pedido['criado_por_nome'] ?? '—') ?></div>
            <div><span class="status-badge"><?= $status_label ?></span></div>
            <?php if ($pedido['data_faturamento']): ?>
            <div class="pedido-data" style="margin-top:4px;">Faturado em: <?= date('d/m/Y H:i', strtotime($pedido['data_faturamento'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Forma de Pagamento — linha única compacta ── -->
    <div class="section">
        <div class="section-title">💳 Forma de Pagamento</div>
        <?php
        $pag_row_class = match($pag_key) {
            'debito'        => 'debito',
            'credito'       => 'credito',
            'entrada_50_50' => 'entrada',
            default         => '',
        };
        ?>
        <div class="pag-row <?= $pag_row_class ?>">
            <?php if ($pag_key === 'pix'): ?>
            <svg width="22" height="22" viewBox="0 0 512 512" fill="#1b5e20"><path d="M392.4 119.6c-17.5-17.5-45.9-17.5-63.4 0L256 192.6l-73-73c-17.5-17.5-45.9-17.5-63.4 0L55 184.2c-17.5 17.5-17.5 45.9 0 63.4l73 73-73 73c-17.5 17.5-17.5 45.9 0 63.4l64.6 64.6c17.5 17.5 45.9 17.5 63.4 0l73-73 73 73c17.5 17.5 45.9 17.5 63.4 0l64.6-64.6c17.5-17.5 17.5-45.9 0-63.4l-73-73 73-73c17.5-17.5 17.5-45.9 0-63.4z"/></svg>
            <span class="pag-label">PIX</span>
            <?php if ($emissor_cnpj): ?>
            <span class="pag-sep">|</span>
            <span class="pix-key"><?= htmlspecialchars($emissor_cnpj) ?></span>
            <span class="pix-hint">(Chave PIX: CNPJ do estabelecimento)</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="pag-label"><?= htmlspecialchars($pag_label) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Dados do destinatário ── -->
    <div class="section">
        <div class="section-title">
            <?= $pedido['tipo_destinatario'] === 'estabelecimento' ? '🏪 Estabelecimento Destinatário' : '👤 Cliente Final' ?>
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Nome / Razão Social</div>
                <div class="value"><?= htmlspecialchars($dest_nome) ?></div>
            </div>
            <?php if ($dest_doc): ?>
            <div class="info-item">
                <div class="label">CPF / CNPJ</div>
                <div class="value"><?= htmlspecialchars($dest_doc) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($dest_email): ?>
            <div class="info-item">
                <div class="label">E-mail</div>
                <div class="value"><?= htmlspecialchars($dest_email) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($dest_telefone): ?>
            <div class="info-item">
                <div class="label">Telefone</div>
                <div class="value"><?= htmlspecialchars($dest_telefone) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($dest_end): ?>
            <div class="info-item" style="grid-column: span 2;">
                <div class="label">Endereço</div>
                <div class="value"><?= htmlspecialchars($dest_end) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Itens do pedido ── -->
    <div class="section">
        <div class="section-title">📦 Itens do Pedido</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Código</th>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th style="text-align:center;">Qtd</th>
                    <th style="text-align:right;">Preço Unit.</th>
                    <th style="text-align:right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><code><?= htmlspecialchars($item['produto_codigo']) ?></code></td>
                <td>
                    <strong><?= htmlspecialchars($item['produto_nome']) ?></strong>
                    <?php if (!empty($item['observacoes'])): ?>
                    <br><small style="color:#888;"><?= htmlspecialchars($item['observacoes']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($item['categoria'] ?? '—') ?></td>
                <td style="text-align:center;"><?= $item['quantidade'] ?></td>
                <td style="text-align:right;">R$ <?= number_format($item['preco_unitario'], 2, ',', '.') ?></td>
                <td style="text-align:right;font-weight:700;">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Entrega (após itens) ── -->
    <?php if ($pedido['entrega'] && $entrega_str): ?>
    <div class="section" style="margin-top:16px;">
        <div class="section-title">🚚 Endereço de Entrega</div>
        <div class="entrega-box">
            <strong><?= htmlspecialchars($entrega_str) ?></strong>
            <span style="margin-left:16px;color:#28a745;font-weight:700;">
                Taxa: R$ <?= number_format($pedido['entrega_taxa'], 2, ',', '.') ?>
            </span>
        </div>
        <div class="aviso-box">
            <strong>⚠️ Condições de Entrega</strong>
            Não subimos escadas para entrega. O ponto deverá ser de 127 volts, tomada única, sem extensão ou adaptador.
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Totais ── -->
    <div class="totals-box">
        <div class="total-line">
            <span>Subtotal dos Produtos</span>
            <span>R$ <?= number_format($pedido['subtotal'], 2, ',', '.') ?></span>
        </div>
        <?php if ($pedido['entrega']): ?>
        <div class="total-line">
            <span>🚚 Taxa de Entrega</span>
            <span>R$ <?= number_format($pedido['entrega_taxa'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($pedido['desconto'] > 0): ?>
        <div class="total-line">
            <span>Desconto</span>
            <span>- R$ <?= number_format($pedido['desconto'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="total-final">
            <span><strong>TOTAL</strong></span>
            <span><strong>R$ <?= number_format($pedido['total'], 2, ',', '.') ?></strong></span>
        </div>
    </div>

    <!-- ── Observações ── -->
    <?php if ($pedido['observacoes']): ?>
    <div class="section" style="margin-top:20px;">
        <div class="section-title">💬 Observações</div>
        <p style="font-size:13px;line-height:1.6;color:#444;"><?= nl2br(htmlspecialchars($pedido['observacoes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Rodapé ── -->
    <div class="doc-footer">
        <span><?= htmlspecialchars($emissor_nome) ?><?php if ($emissor_cnpj): ?> — CNPJ: <?= htmlspecialchars($emissor_cnpj) ?><?php endif; ?></span>
        <span>Documento gerado em <?= date('d/m/Y \à\s H:i') ?></span>
    </div>

</div><!-- /doc -->

<script>
// Auto-print se vier com ?print=1
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.onload = () => window.print();
}
</script>
</body>
</html>
