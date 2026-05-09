<?php
/**
 * pedido_pdf.php
 * Gera o PDF do pedido de estoque com layout personalizado Choppon.
 * Usa HTML puro com header Content-Type para impressão/download.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/PedidoEstoqueManager.php';
requireAuth();

$conn = getDBConnection();
$pm   = new PedidoEstoqueManager($conn);

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

// ── Dados do destinatário ─────────────────────────────────────────────────
if ($pedido['tipo_destinatario'] === 'estabelecimento') {
    $dest_nome     = $pedido['estabelecimento_nome']     ?? '—';
    $dest_doc      = $pedido['estabelecimento_document'] ?? '';
    $dest_email    = '';
    $dest_telefone = $pedido['estabelecimento_phone']    ?? '';
    $dest_end      = $pedido['estabelecimento_address']  ?? '';
} else {
    $dest_nome     = $pedido['cliente_nome']      ?? '—';
    $dest_doc      = $pedido['cliente_cpf_cnpj']  ?? '';
    $dest_email    = $pedido['cliente_email']      ?? '';
    $dest_telefone = $pedido['cliente_telefone']   ?? '';
    $dest_end      = '';
}

// ── Status label ──────────────────────────────────────────────────────────
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
        trim($pedido['entrega_logradouro'] ?? ''),
        trim($pedido['entrega_numero']     ?? ''),
        trim($pedido['entrega_complemento']?? ''),
        trim($pedido['entrega_bairro']     ?? ''),
        trim($pedido['entrega_cidade']     ?? ''),
        trim($pedido['entrega_estado']     ?? ''),
        trim($pedido['entrega_cep']        ?? ''),
    ]);
    $entrega_str = implode(', ', $partes);
}

// Caminho absoluto do logo
$logo_path = realpath(__DIR__ . '/../assets/images/logo.png');
$logo_b64  = '';
if ($logo_path && file_exists($logo_path)) {
    $logo_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

// ── Gerar HTML para impressão ─────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Pedido <?= htmlspecialchars($pedido['numero_pedido']) ?> — Chopp On Tap</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #222; background: #fff; }

  /* Botões de ação (não aparecem na impressão) */
  .no-print { display: flex; gap: 10px; padding: 14px 20px; background: #f0f4f8; border-bottom: 1px solid #ddd; }
  .btn-print { padding: 9px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
  .btn-print.blue { background: #007bff; color: #fff; }
  .btn-print.gray { background: #6c757d; color: #fff; text-decoration: none; }
  @media print { .no-print { display: none !important; } body { padding: 0; } }

  /* Layout do documento */
  .doc { max-width: 800px; margin: 0 auto; padding: 30px 30px 40px; }

  /* Cabeçalho */
  .doc-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 18px; border-bottom: 3px solid #0066cc; margin-bottom: 22px; }
  .doc-header .brand { display: flex; align-items: center; gap: 14px; }
  .doc-header .brand img { height: 60px; }
  .doc-header .brand-name { font-size: 22px; font-weight: 700; color: #0066cc; }
  .doc-header .brand-sub  { font-size: 11px; color: #666; margin-top: 2px; }
  .doc-header .pedido-info { text-align: right; }
  .doc-header .pedido-num  { font-size: 24px; font-weight: 700; color: #0066cc; font-family: monospace; }
  .doc-header .pedido-data { font-size: 12px; color: #666; margin-top: 3px; }
  .status-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #fff; margin-top: 6px; background: <?= $status_color ?>; }

  /* Seções */
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #666; border-bottom: 1px solid #e0e0e0; padding-bottom: 5px; margin-bottom: 10px; font-weight: 600; }
  .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .info-item .label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .4px; }
  .info-item .value { font-size: 13px; font-weight: 600; margin-top: 2px; }

  /* Tabela de itens */
  .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .items-table thead tr { background: #0066cc; color: #fff; }
  .items-table thead th { padding: 9px 10px; text-align: left; font-weight: 600; }
  .items-table tbody tr:nth-child(even) { background: #f5f8ff; }
  .items-table tbody td { padding: 8px 10px; border-bottom: 1px solid #eee; }
  .items-table tfoot tr { background: #f0f4f8; font-weight: 700; }
  .items-table tfoot td { padding: 8px 10px; }

  /* Totais */
  .totals-box { margin-left: auto; width: 280px; background: linear-gradient(135deg, #0066cc, #004499); color: #fff; border-radius: 10px; padding: 16px 20px; margin-top: 16px; }
  .total-line { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
  .total-final { display: flex; justify-content: space-between; padding-top: 10px; margin-top: 8px; border-top: 1px solid rgba(255,255,255,.3); font-size: 18px; font-weight: 700; }

  /* Entrega */
  .entrega-box { background: #e8f5e9; border-left: 4px solid #28a745; border-radius: 6px; padding: 12px 16px; }

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

    <!-- Cabeçalho do documento -->
    <div class="doc-header">
        <div class="brand">
            <?php if ($logo_b64): ?>
            <img src="<?= $logo_b64 ?>" alt="Choppon">
            <?php else: ?>
            <div style="width:60px;height:60px;background:#0066cc;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">C</div>
            <?php endif; ?>
            <div>
                <div class="brand-name">Chopp On Tap</div>
                <div class="brand-sub">Sistema de Gestão ERP</div>
                <div class="brand-sub">ochoppoficial.com.br</div>
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

    <!-- Dados do destinatário -->
    <div class="section">
        <div class="section-title">
            <?= $pedido['tipo_destinatario'] === 'estabelecimento' ? '🏪 Estabelecimento' : '👤 Cliente Final' ?>
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

    <!-- Entrega -->
    <?php if ($pedido['entrega'] && $entrega_str): ?>
    <div class="section">
        <div class="section-title">🚚 Endereço de Entrega</div>
        <div class="entrega-box">
            <strong><?= htmlspecialchars($entrega_str) ?></strong>
            <span style="margin-left:16px;color:#28a745;font-weight:700;">
                Taxa: R$ <?= number_format($pedido['entrega_taxa'], 2, ',', '.') ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Itens do pedido -->
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
                    <?php if ($item['observacoes']): ?>
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

    <!-- Totais -->
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
            <span>TOTAL</span>
            <span>R$ <?= number_format($pedido['total'], 2, ',', '.') ?></span>
        </div>
    </div>

    <!-- Observações -->
    <?php if ($pedido['observacoes']): ?>
    <div class="section" style="margin-top:20px;">
        <div class="section-title">💬 Observações</div>
        <p style="font-size:13px;line-height:1.6;color:#444;"><?= nl2br(htmlspecialchars($pedido['observacoes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Rodapé -->
    <div class="doc-footer">
        <span>Chopp On Tap — Sistema ERP | ochoppoficial.com.br</span>
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
