<?php
$page_title   = 'Dashboard';
$current_page = 'dashboard';
require_once '../includes/config.php';
require_once '../includes/auth.php';
$conn = getDBConnection();

$ano_atual    = date('Y');
$ano_anterior = $ano_atual - 1;
$mes_atual    = date('n');

// ─── Helper: cláusula WHERE multi-tenant ─────────────────────
$eid = isAdminGeral() ? null : getEstabelecimentoId();
function eid_where(string $alias = 'o'): string {
    global $eid;
    return $eid ? " AND {$alias}.estabelecimento_id = {$eid}" : '';
}
function eid_param(): array {
    global $eid;
    return $eid ? [$eid] : [];
}

// ═══════════════════════════════════════════════════════════════
// BLOCO 1 — Cards de topo
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(valor), 0)      AS vendas_totais,
        COALESCE(SUM(quantidade), 0) AS consumo_total,
        COUNT(*)                     AS total_pedidos
    FROM \`order\`
    WHERE checkout_status = 'SUCCESSFUL'
    AND YEAR(created_at) = ?" . eid_where());
$stmt->execute(array_merge([$ano_atual], eid_param()));
$stats = $stmt->fetch();

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(valor), 0)      AS vendas_mensais,
        COALESCE(SUM(quantidade), 0) AS consumo_mensal
    FROM \`order\`
    WHERE checkout_status = 'SUCCESSFUL'
    AND YEAR(created_at) = ?
    AND MONTH(created_at) = ?" . eid_where());
$stmt->execute(array_merge([$ano_atual, $mes_atual], eid_param()));
$stats_mensal = $stmt->fetch();

// TAPs
if ($eid) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tap WHERE estabelecimento_id = ?");
    $stmt->execute([$eid]);
    $total_taps = $stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tap WHERE status = 1 AND estabelecimento_id = ?");
    $stmt->execute([$eid]);
    $taps_ativas = $stmt->fetchColumn();
} else {
    $total_taps  = $conn->query("SELECT COUNT(*) FROM tap")->fetchColumn();
    $taps_ativas = $conn->query("SELECT COUNT(*) FROM tap WHERE status = 1")->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════
// BLOCO 2 — Evolução de vendas (12 meses) — dados para Chart.js
// ═══════════════════════════════════════════════════════════════
$vendas_por_mes = [];
for ($m = 1; $m <= 12; $m++) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(valor), 0) AS total
        FROM \`order\`
        WHERE checkout_status = 'SUCCESSFUL'
        AND YEAR(created_at) = ? AND MONTH(created_at) = ?" . eid_where());
    $stmt->execute(array_merge([$ano_atual, $m], eid_param()));
    $vendas_por_mes['atual'][$m] = (float)$stmt->fetchColumn();

    $stmt->execute(array_merge([$ano_anterior, $m], eid_param()));
    $vendas_por_mes['anterior'][$m] = (float)$stmt->fetchColumn();
}

// Dados diários — últimos 30 dias
$vendas_diarias = [];
$stmt = $conn->prepare("
    SELECT DATE(created_at) AS dia, COALESCE(SUM(valor), 0) AS total
    FROM \`order\`
    WHERE checkout_status = 'SUCCESSFUL'
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . eid_where() . "
    GROUP BY DATE(created_at)
    ORDER BY dia ASC");
$stmt->execute(eid_param());
foreach ($stmt->fetchAll() as $row) {
    $vendas_diarias[$row['dia']] = (float)$row['total'];
}
// Preencher dias sem venda
$dias_labels = [];
$dias_valores = [];
for ($d = 29; $d >= 0; $d--) {
    $dia = date('Y-m-d', strtotime("-{$d} days"));
    $dias_labels[]  = date('d/m', strtotime($dia));
    $dias_valores[] = $vendas_diarias[$dia] ?? 0;
}

// ═══════════════════════════════════════════════════════════════
// BLOCO 3 — Bebidas mais vendidas (top 5, últimos 30 dias)
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT b.name AS bebida,
           COUNT(*) AS pedidos,
           COALESCE(SUM(o.valor), 0) AS total_valor
    FROM \`order\` o
    INNER JOIN bebidas b ON o.bebida_id = b.id
    WHERE o.checkout_status = 'SUCCESSFUL'
    AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . eid_where() . "
    GROUP BY o.bebida_id, b.name
    ORDER BY pedidos DESC
    LIMIT 5");
$stmt->execute(eid_param());
$bebidas_top = $stmt->fetchAll();
$max_pedidos = !empty($bebidas_top) ? max(array_column($bebidas_top, 'pedidos')) : 1;

// ═══════════════════════════════════════════════════════════════
// BLOCO 4 — Faturamento por estabelecimento em TAPs
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT e.name AS estab_nome,
           COUNT(DISTINCT t.id) AS total_taps,
           COUNT(DISTINCT CASE WHEN t.status = 1 THEN t.id END) AS taps_ativas,
           COALESCE(SUM(o.valor), 0) AS faturamento_mes,
           COALESCE(SUM(o.valor), 0) AS faturamento_total
    FROM estabelecimentos e
    LEFT JOIN tap t ON t.estabelecimento_id = e.id
    LEFT JOIN \`order\` o ON o.estabelecimento_id = e.id
        AND o.checkout_status = 'SUCCESSFUL'
        AND YEAR(o.created_at) = ?
        AND MONTH(o.created_at) = ?
    WHERE e.status = 1" . ($eid ? " AND e.id = {$eid}" : '') . "
    GROUP BY e.id, e.name
    ORDER BY faturamento_mes DESC");
$stmt->execute([$ano_atual, $mes_atual]);
$fat_por_estab = $stmt->fetchAll();
$max_fat = !empty($fat_por_estab) ? max(array_column($fat_por_estab, 'faturamento_mes')) : 1;

// ═══════════════════════════════════════════════════════════════
// BLOCO 5 — Contas próximas a vencer (próximos 7 dias)
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT c.*, e.name AS estab_nome,
           DATEDIFF(c.data_vencimento, CURDATE()) AS dias_restantes
    FROM contas_pagar c
    INNER JOIN estabelecimentos e ON c.estabelecimento_id = e.id
    WHERE c.status = 'pendente'
    AND c.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)" .
    ($eid ? " AND c.estabelecimento_id = {$eid}" : '') . "
    ORDER BY c.data_vencimento ASC
    LIMIT 8");
$stmt->execute();
$contas_vencer = $stmt->fetchAll();

// ═══════════════════════════════════════════════════════════════
// BLOCO 6 — TAPs com vencimento nos próximos 10 dias
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT t.*, b.name AS bebida_name, e.name AS estabelecimento_name,
           (t.volume - t.volume_consumido) AS volume_restante
    FROM tap t
    INNER JOIN bebidas b ON t.bebida_id = b.id
    INNER JOIN estabelecimentos e ON t.estabelecimento_id = e.id
    WHERE t.vencimento <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)
    AND t.vencimento >= CURDATE()" .
    ($eid ? " AND t.estabelecimento_id = {$eid}" : '') . "
    ORDER BY t.vencimento ASC
    LIMIT 6");
$stmt->execute();
$taps_vencimento = $stmt->fetchAll();

// ═══════════════════════════════════════════════════════════════
// BLOCO 7 — Royalties últimos 12 meses por estabelecimento
// ═══════════════════════════════════════════════════════════════
$stmt = $conn->prepare("
    SELECT e.name AS estab_nome,
           DATE_FORMAT(r.periodo_inicial, '%Y-%m') AS mes_ref,
           COALESCE(SUM(r.valor_faturamento_bruto), 0) AS fat_bruto,
           COALESCE(SUM(r.valor_royalties), 0) AS royalties,
           MAX(r.status) AS status_ultimo
    FROM royalties r
    INNER JOIN estabelecimentos e ON r.estabelecimento_id = e.id
    WHERE r.periodo_inicial >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND r.status != 'cancelado'" .
    ($eid ? " AND r.estabelecimento_id = {$eid}" : '') . "
    GROUP BY e.id, e.name, DATE_FORMAT(r.periodo_inicial, '%Y-%m')
    ORDER BY e.name, mes_ref ASC");
$stmt->execute();
$royalties_rows = $stmt->fetchAll();

// Organizar por estabelecimento
$royalties_por_estab = [];
$meses_12 = [];
for ($i = 11; $i >= 0; $i--) {
    $meses_12[] = date('Y-m', strtotime("-{$i} months"));
}
foreach ($royalties_rows as $r) {
    $royalties_por_estab[$r['estab_nome']][$r['mes_ref']] = [
        'fat'       => (float)$r['fat_bruto'],
        'royalties' => (float)$r['royalties'],
        'status'    => $r['status_ultimo'],
    ];
}

// Totais de royalties para cards
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status IN ('pendente','link_gerado','enviado') THEN valor_royalties ELSE 0 END), 0) AS pendente,
        COALESCE(SUM(CASE WHEN status IN ('pago','conciliado','pagamento_manual') THEN valor_royalties ELSE 0 END), 0) AS pago
    FROM royalties
    WHERE periodo_inicial >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND status != 'cancelado'" .
    ($eid ? " AND estabelecimento_id = {$eid}" : ''));
$stmt->execute();
$royalties_totais = $stmt->fetch();

require_once '../includes/header.php';
?>

<!-- ─── Estilos específicos do dashboard ─────────────────────── -->
<style>
/* Cards de topo */
.dash-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
.dash-card  { background:#1565c0; color:#fff; border-radius:12px; padding:20px 24px; }
.dash-card.light { background:#fff; color:#333; border:1px solid #e0e0e0; }
.dash-card .label { font-size:13px; opacity:.85; margin-bottom:8px; }
.dash-card .value { font-size:26px; font-weight:700; line-height:1.2; }
.dash-card .sub   { font-size:12px; opacity:.7; margin-top:4px; }

/* Seção de dois painéis lado a lado */
.dash-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
@media(max-width:900px){ .dash-row { grid-template-columns:1fr; } }
.dash-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:24px; }
@media(max-width:1100px){ .dash-row-3 { grid-template-columns:1fr 1fr; } }
@media(max-width:700px) { .dash-row-3 { grid-template-columns:1fr; } }

/* Gráfico evolução */
.chart-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px; }
.chart-header h3 { margin:0; font-size:16px; font-weight:600; }
.chart-tabs { display:flex; gap:4px; }
.chart-tab  { padding:5px 14px; border-radius:20px; border:1px solid #1565c0; background:#fff;
              color:#1565c0; font-size:13px; cursor:pointer; transition:.2s; }
.chart-tab.active { background:#1565c0; color:#fff; }
.chart-wrap { position:relative; height:260px; }

/* Bebidas ranking */
.bebida-rank-item { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
.bebida-rank-pos  { font-size:13px; font-weight:700; color:#999; min-width:24px; }
.bebida-rank-name { font-size:14px; font-weight:600; flex:1; }
.bebida-rank-bar  { height:6px; background:#ff6b00; border-radius:3px; min-width:4px; }
.bebida-rank-val  { font-size:12px; color:#666; min-width:50px; text-align:right; }

/* Faturamento por estabelecimento */
.fat-estab-item { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.fat-estab-name { font-size:13px; font-weight:600; flex:1; min-width:100px; }
.fat-estab-bar-wrap { flex:2; background:#f0f0f0; border-radius:4px; height:10px; overflow:hidden; }
.fat-estab-bar  { height:10px; background:linear-gradient(90deg,#1565c0,#42a5f5); border-radius:4px; }
.fat-estab-val  { font-size:13px; font-weight:700; color:#1565c0; min-width:90px; text-align:right; }
.fat-estab-taps { font-size:11px; color:#888; min-width:60px; text-align:right; }

/* Contas a vencer */
.conta-vencer-list { display:flex; flex-direction:column; gap:8px; }
.conta-vencer-item { display:flex; align-items:center; justify-content:space-between;
                     padding:10px 14px; border-radius:8px; background:#fff8e1;
                     border-left:4px solid #ffc107; gap:8px; flex-wrap:wrap; }
.conta-vencer-item.urgente { background:#ffebee; border-left-color:#f44336; }
.conta-vencer-item.hoje    { background:#fff3e0; border-left-color:#ff9800; }
.conta-vencer-desc { font-size:13px; font-weight:600; flex:1; }
.conta-vencer-meta { font-size:12px; color:#666; }
.conta-vencer-valor { font-size:14px; font-weight:700; color:#c62828; white-space:nowrap; }
.conta-vencer-dias  { font-size:11px; padding:2px 8px; border-radius:10px; background:#ffc107; color:#333; white-space:nowrap; }
.conta-vencer-dias.urgente { background:#f44336; color:#fff; }
.conta-vencer-dias.hoje    { background:#ff9800; color:#fff; }

/* TAPs vencimento */
.tap-cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
.tap-card-item  { background:#fff; border:1px solid #e3f2fd; border-radius:10px; padding:14px;
                  border-top:3px solid #1565c0; }
.tap-card-item h4 { color:#1565c0; font-size:15px; margin:0 0 8px; }
.tap-card-item p  { margin:3px 0; font-size:12px; color:#555; }
.tap-card-item .consumo-badge { margin-top:8px; background:#1565c0; color:#fff;
                                 border-radius:20px; padding:4px 12px; font-size:12px;
                                 font-weight:600; display:inline-block; }

/* Royalties tabela */
.royalties-table-wrap { overflow-x:auto; }
.royalties-table { width:100%; border-collapse:collapse; font-size:13px; }
.royalties-table th { background:#f5f5f5; padding:8px 10px; text-align:center;
                       font-weight:600; border-bottom:2px solid #e0e0e0; white-space:nowrap; }
.royalties-table td { padding:7px 10px; border-bottom:1px solid #f0f0f0; text-align:center; }
.royalties-table td:first-child { text-align:left; font-weight:600; white-space:nowrap; }
.royalties-table tr:hover td { background:#f9f9f9; }
.roy-cell { font-size:12px; }
.roy-cell.pago    { color:#388e3c; font-weight:600; }
.roy-cell.pend    { color:#f57c00; font-weight:600; }
.roy-cell.vazio   { color:#ccc; }
.roy-total-row td { background:#e3f2fd; font-weight:700; }
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
        <p class="page-subtitle">Visão geral do sistema — <?php echo date('d/m/Y'); ?></p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CARDS DE TOPO
════════════════════════════════════════════════════════════ -->
<div class="dash-cards">
    <div class="dash-card">
        <div class="label">Vendas Totais (<?php echo $ano_atual; ?>)</div>
        <div class="value"><?php echo formatMoney($stats['vendas_totais'] ?? 0); ?></div>
        <div class="sub"><?php echo number_format($stats['total_pedidos'] ?? 0, 0, ',', '.'); ?> pedidos</div>
    </div>
    <div class="dash-card">
        <div class="label">Consumo Total (<?php echo $ano_atual; ?>)</div>
        <div class="value"><?php echo number_format(($stats['consumo_total'] ?? 0) / 1000, 2, ',', '.'); ?> L</div>
        <div class="sub">litros consumidos no ano</div>
    </div>
    <div class="dash-card">
        <div class="label">Consumo Mensal</div>
        <div class="value"><?php echo number_format(($stats_mensal['consumo_mensal'] ?? 0) / 1000, 2, ',', '.'); ?> L</div>
        <div class="sub"><?php echo date('F', mktime(0,0,0,$mes_atual,1)); ?></div>
    </div>
    <div class="dash-card light">
        <div class="label">Vendas Mensais</div>
        <div class="value" style="color:#1565c0;"><?php echo formatMoney($stats_mensal['vendas_mensais'] ?? 0); ?></div>
        <div class="sub" style="color:#666;"><?php echo date('F/Y', mktime(0,0,0,$mes_atual,1)); ?></div>
    </div>
    <div class="dash-card light">
        <div class="label">TAPs</div>
        <div class="value" style="color:#1565c0;"><?php echo $total_taps; ?></div>
        <div class="sub" style="color:#388e3c;"><strong><?php echo $taps_ativas; ?> ativas</strong></div>
    </div>
    <div class="dash-card light">
        <div class="label">Royalties Pendentes</div>
        <div class="value" style="color:#f57c00;"><?php echo formatMoney($royalties_totais['pendente'] ?? 0); ?></div>
        <div class="sub" style="color:#388e3c;">Pago: <?php echo formatMoney($royalties_totais['pago'] ?? 0); ?></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     EVOLUÇÃO DE VENDAS + BEBIDAS MAIS VENDIDAS
════════════════════════════════════════════════════════════ -->
<div class="dash-row" style="margin-bottom:24px;">

    <!-- Gráfico de evolução -->
    <div class="card" style="padding:20px;">
        <div class="chart-header">
            <h3><i class="fas fa-chart-line" style="color:#1565c0;"></i> Evolução das Vendas</h3>
            <div class="chart-tabs">
                <button class="chart-tab active" onclick="switchChart('diario', this)">Diário</button>
                <button class="chart-tab" onclick="switchChart('mensal', this)">Mensal</button>
                <button class="chart-tab" onclick="switchChart('anual', this)">Anual</button>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="evolucaoChart"></canvas>
        </div>
    </div>

    <!-- Bebidas mais vendidas -->
    <div class="card" style="padding:20px;">
        <div class="chart-header">
            <h3><i class="fas fa-beer" style="color:#ff6b00;"></i> Bebidas Mais Vendidas</h3>
            <small style="color:#999;">Últimos 30 dias</small>
        </div>
        <?php if (empty($bebidas_top)): ?>
            <div style="text-align:center; padding:40px; color:#999;">
                <i class="fas fa-beer" style="font-size:36px; margin-bottom:8px; display:block;"></i>
                Nenhum dado disponível
            </div>
        <?php else: ?>
            <?php foreach ($bebidas_top as $idx => $bev): ?>
            <?php $pct = $max_pedidos > 0 ? round(($bev['pedidos'] / $max_pedidos) * 100) : 0; ?>
            <div class="bebida-rank-item">
                <div class="bebida-rank-pos"><?php echo $idx + 1; ?>°</div>
                <div class="bebida-rank-name"><?php echo htmlspecialchars(strtoupper($bev['bebida'])); ?></div>
                <div class="bebida-rank-bar" style="width:<?php echo max(20, $pct); ?>px;"></div>
                <div class="bebida-rank-val"><?php echo $bev['pedidos']; ?> ped.</div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     FATURAMENTO POR ESTABELECIMENTO EM TAPs
════════════════════════════════════════════════════════════ -->
<div class="card" style="padding:20px; margin-bottom:24px;">
    <div class="chart-header">
        <h3><i class="fas fa-store" style="color:#1565c0;"></i> Faturamento por Estabelecimento em TAPs</h3>
        <small style="color:#999;"><?php echo date('F/Y', mktime(0,0,0,$mes_atual,1)); ?></small>
    </div>
    <?php if (empty($fat_por_estab)): ?>
        <div style="text-align:center; padding:30px; color:#999;">Nenhum dado disponível</div>
    <?php else: ?>
        <?php foreach ($fat_por_estab as $fe): ?>
        <?php $pct_bar = $max_fat > 0 ? round(($fe['faturamento_mes'] / $max_fat) * 100) : 0; ?>
        <div class="fat-estab-item">
            <div class="fat-estab-name"><?php echo htmlspecialchars($fe['estab_nome']); ?></div>
            <div class="fat-estab-bar-wrap">
                <div class="fat-estab-bar" style="width:<?php echo max(2, $pct_bar); ?>%;"></div>
            </div>
            <div class="fat-estab-val"><?php echo formatMoney($fe['faturamento_mes']); ?></div>
            <div class="fat-estab-taps">
                <i class="fas fa-faucet" style="color:#1565c0;"></i>
                <?php echo $fe['taps_ativas']; ?>/<?php echo $fe['total_taps']; ?> TAPs
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CONTAS PRÓXIMAS A VENCER + TAPs COM VENCIMENTO
════════════════════════════════════════════════════════════ -->
<div class="dash-row" style="margin-bottom:24px;">

    <!-- Contas a vencer -->
    <div class="card" style="padding:20px;">
        <div class="chart-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#f57c00;"></i> Contas Próximas a Vencer</h3>
            <small style="color:#999;">Próximos 7 dias</small>
        </div>
        <?php if (empty($contas_vencer)): ?>
            <div style="text-align:center; padding:30px; color:#999;">
                <i class="fas fa-check-circle" style="font-size:36px; color:#388e3c; display:block; margin-bottom:8px;"></i>
                Nenhuma conta vencendo nos próximos 7 dias
            </div>
        <?php else: ?>
        <div class="conta-vencer-list">
            <?php foreach ($contas_vencer as $cv): ?>
            <?php
            $cls = '';
            if ($cv['dias_restantes'] == 0)      $cls = 'hoje';
            elseif ($cv['dias_restantes'] <= 2)  $cls = 'urgente';
            $dias_txt = $cv['dias_restantes'] == 0 ? 'Hoje!' : 'em ' . $cv['dias_restantes'] . 'd';
            ?>
            <div class="conta-vencer-item <?php echo $cls; ?>">
                <div>
                    <div class="conta-vencer-desc"><?php echo htmlspecialchars($cv['descricao']); ?></div>
                    <div class="conta-vencer-meta">
                        <?php echo htmlspecialchars($cv['tipo']); ?>
                        <?php if (isAdminGeral()): ?> · <?php echo htmlspecialchars($cv['estab_nome']); ?><?php endif; ?>
                        · <?php echo formatDateBR($cv['data_vencimento']); ?>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
                    <div class="conta-vencer-valor"><?php echo formatMoney($cv['valor']); ?></div>
                    <div class="conta-vencer-dias <?php echo $cls; ?>"><?php echo $dias_txt; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; text-align:right;">
            <a href="financeiro_contas.php" class="btn btn-sm btn-outline-primary">Ver todas as contas →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAPs com vencimento próximo -->
    <div class="card" style="padding:20px;">
        <div class="chart-header">
            <h3><i class="fas fa-faucet" style="color:#1565c0;"></i> TAPs com Vencimento nos Próximos 10 Dias</h3>
        </div>
        <?php if (empty($taps_vencimento)): ?>
            <div style="text-align:center; padding:30px; color:#999;">
                <i class="fas fa-check-circle" style="font-size:36px; color:#388e3c; display:block; margin-bottom:8px;"></i>
                Nenhuma TAP vencendo em breve
            </div>
        <?php else: ?>
        <div class="tap-cards-grid">
            <?php foreach ($taps_vencimento as $tap): ?>
            <div class="tap-card-item">
                <h4>Tap <?php echo $tap['id']; ?></h4>
                <?php if (isAdminGeral()): ?>
                <p><strong><?php echo htmlspecialchars($tap['estabelecimento_name']); ?></strong></p>
                <?php endif; ?>
                <p><strong>Bebida:</strong> <?php echo htmlspecialchars($tap['bebida_name']); ?></p>
                <p><strong>Vencimento:</strong> <?php echo formatDateBR($tap['vencimento']); ?></p>
                <p><strong>Volume:</strong> <?php echo number_format($tap['volume'], 2, ',', '.'); ?>L</p>
                <div class="consumo-badge">
                    Consumo: <?php echo number_format($tap['volume_consumido'], 2, ',', '.'); ?>L
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     ROYALTIES — ÚLTIMOS 12 MESES POR ESTABELECIMENTO
════════════════════════════════════════════════════════════ -->
<div class="card" style="padding:20px; margin-bottom:24px;">
    <div class="chart-header">
        <h3><i class="fas fa-crown" style="color:#f9a825;"></i> Royalties — Últimos 12 Meses por Estabelecimento</h3>
        <a href="financeiro_royalties.php" class="btn btn-sm btn-outline-primary">Ver detalhes →</a>
    </div>

    <?php if (empty($royalties_por_estab)): ?>
        <div style="text-align:center; padding:30px; color:#999;">Nenhum dado de royalties disponível</div>
    <?php else: ?>
    <div class="royalties-table-wrap">
        <table class="royalties-table">
            <thead>
                <tr>
                    <th>Estabelecimento</th>
                    <?php foreach ($meses_12 as $m): ?>
                    <th><?php echo date('M/y', strtotime($m . '-01')); ?></th>
                    <?php endforeach; ?>
                    <th>Total 12m</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totais_mes = array_fill_keys($meses_12, 0);
                $grand_total = 0;
                foreach ($royalties_por_estab as $estab => $meses_data):
                    $total_estab = 0;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($estab); ?></td>
                    <?php foreach ($meses_12 as $m): ?>
                    <?php
                    $d = $meses_data[$m] ?? null;
                    if ($d && $d['royalties'] > 0):
                        $total_estab += $d['royalties'];
                        $totais_mes[$m] += $d['royalties'];
                        $cls_status = in_array($d['status'], ['pago','conciliado','pagamento_manual']) ? 'pago' : 'pend';
                    ?>
                    <td class="roy-cell <?php echo $cls_status; ?>">
                        <?php echo 'R$' . number_format($d['royalties'], 0, ',', '.'); ?>
                        <?php if ($cls_status === 'pago'): ?><br><small>✓</small><?php endif; ?>
                    </td>
                    <?php else: ?>
                    <td class="roy-cell vazio">—</td>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <td style="font-weight:700; color:#1565c0;"><?php echo formatMoney($total_estab); ?></td>
                    <?php $grand_total += $total_estab; ?>
                </tr>
                <?php endforeach; ?>
                <!-- Linha de totais -->
                <tr class="roy-total-row">
                    <td>TOTAL</td>
                    <?php foreach ($meses_12 as $m): ?>
                    <td><?php echo $totais_mes[$m] > 0 ? formatMoney($totais_mes[$m]) : '—'; ?></td>
                    <?php endforeach; ?>
                    <td><?php echo formatMoney($grand_total); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     SCRIPTS — Chart.js
════════════════════════════════════════════════════════════ -->
<?php
$meses_labels_js   = json_encode(['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']);
$vendas_atual_js   = json_encode(array_values($vendas_por_mes['atual']));
$vendas_ant_js     = json_encode(array_values($vendas_por_mes['anterior']));
$dias_labels_js    = json_encode($dias_labels);
$dias_valores_js   = json_encode($dias_valores);
?>
<script>
// ─── Dados ────────────────────────────────────────────────────
const mesesLabels  = <?php echo $meses_labels_js; ?>;
const vendasAtual  = <?php echo $vendas_atual_js; ?>;
const vendasAnt    = <?php echo $vendas_ant_js; ?>;
const diasLabels   = <?php echo $dias_labels_js; ?>;
const diasValores  = <?php echo $dias_valores_js; ?>;

// ─── Gráfico de evolução ──────────────────────────────────────
let evolucaoChart = null;

function buildDiarioData() {
    return {
        labels: diasLabels,
        datasets: [{
            label: 'Vendas (R$)',
            data: diasValores,
            borderColor: '#1565c0',
            backgroundColor: 'rgba(21,101,192,0.08)',
            borderWidth: 2.5,
            pointRadius: 3,
            pointBackgroundColor: '#1565c0',
            tension: 0.3,
            fill: true
        }]
    };
}
function buildMensalData() {
    return {
        labels: mesesLabels,
        datasets: [
            {
                label: '<?php echo $ano_anterior; ?>',
                data: vendasAnt,
                borderColor: '#bdbdbd',
                backgroundColor: 'rgba(189,189,189,0.15)',
                borderWidth: 2,
                pointRadius: 4,
                tension: 0.3,
                fill: false
            },
            {
                label: '<?php echo $ano_atual; ?>',
                data: vendasAtual,
                borderColor: '#1565c0',
                backgroundColor: 'rgba(21,101,192,0.08)',
                borderWidth: 2.5,
                pointRadius: 5,
                pointBackgroundColor: '#1565c0',
                tension: 0.3,
                fill: true
            }
        ]
    };
}
function buildAnualData() {
    // Agrupa por semestre para visão anual
    const semestres = ['1º Sem <?php echo $ano_anterior; ?>', '2º Sem <?php echo $ano_anterior; ?>',
                       '1º Sem <?php echo $ano_atual; ?>',    '2º Sem <?php echo $ano_atual; ?>'];
    function somaRange(arr, de, ate) {
        return arr.slice(de-1, ate).reduce((a,b) => a+b, 0);
    }
    return {
        labels: semestres,
        datasets: [{
            label: 'Faturamento (R$)',
            data: [
                somaRange(vendasAnt, 1, 6),
                somaRange(vendasAnt, 7, 12),
                somaRange(vendasAtual, 1, 6),
                somaRange(vendasAtual, 7, 12)
            ],
            backgroundColor: ['#90caf9','#64b5f6','#1565c0','#0d47a1'],
            borderRadius: 6
        }]
    };
}

function switchChart(tipo, btn) {
    document.querySelectorAll('.chart-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    let data, type = 'line';
    if (tipo === 'diario')  { data = buildDiarioData(); }
    else if (tipo === 'mensal') { data = buildMensalData(); }
    else { data = buildAnualData(); type = 'bar'; }

    if (evolucaoChart) evolucaoChart.destroy();
    evolucaoChart = new Chart(
        document.getElementById('evolucaoChart').getContext('2d'), {
        type: type,
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits:2})
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:0})
                    }
                }
            }
        }
    });
}

// Inicializar com visão diária
document.addEventListener('DOMContentLoaded', function() {
    switchChart('diario', document.querySelector('.chart-tab.active'));
});
</script>

<?php require_once '../includes/footer.php'; ?>
