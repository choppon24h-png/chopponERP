<?php
$page_title = 'Pedidos';
$current_page = 'pedidos';
require_once '../includes/config.php';
require_once '../includes/auth.php';
$conn = getDBConnection();

// ── Controle de acesso por estabelecimento ────────────────────────────────────
// Admin Geral (master): vê todos os estabelecimentos
// Franqueado / outros: vê apenas os estabelecimentos vinculados ao seu usuário
$user_id = $_SESSION['user_id'] ?? 0;

if (!isAdminGeral()) {
    // Buscar todos os estabelecimentos liberados para este usuário
    $stmt_ue = $conn->prepare("
        SELECT ue.estabelecimento_id
        FROM user_estabelecimento ue
        WHERE ue.user_id = ? AND ue.status = 1
    ");
    $stmt_ue->execute([$user_id]);
    $ids_liberados = $stmt_ue->fetchAll(PDO::FETCH_COLUMN);

    // Se não tiver nenhum estabelecimento vinculado, usar o da sessão como fallback
    if (empty($ids_liberados)) {
        $eid = getEstabelecimentoId();
        $ids_liberados = $eid ? [$eid] : [0];
    }
} else {
    $ids_liberados = []; // admin vê tudo — não aplica restrição
}

// ── Filtros da URL ────────────────────────────────────────────────────────────
$data_inicio        = $_GET['data_inicio']        ?? date('Y-m-01');
$data_fim           = $_GET['data_fim']            ?? date('Y-m-d');
$status             = $_GET['status']              ?? '';
$metodo             = $_GET['metodo']              ?? '';
$filtro_estab_id    = (int)($_GET['estabelecimento_id'] ?? 0);

// ── Verificar se a coluna is_matriz existe no banco ──────────────────────────
try {
    $check = $conn->query("SELECT is_matriz FROM estabelecimentos LIMIT 1");
    $has_is_matriz = true;
} catch (Exception $e) {
    $has_is_matriz = false;
}
$col_is_matriz = $has_is_matriz ? 'COALESCE(e.is_matriz,0)' : '0';
$col_is_matriz_plain = $has_is_matriz ? 'COALESCE(is_matriz,0)' : '0';
$order_is_matriz = $has_is_matriz ? 'is_matriz DESC,' : '';

// ── Verificar se as colunas de lançamento bancário existem ───────────────────
$has_lancamento_id = false;
$has_taxa_aplicada = false;
try {
    $conn->query("SELECT lancamento_bancario_id FROM `order` LIMIT 1");
    $has_lancamento_id = true;
} catch (Exception $e) {}
try {
    $conn->query("SELECT taxa_aplicada FROM `order` LIMIT 1");
    $has_taxa_aplicada = true;
} catch (Exception $e) {}

// ── Buscar lista de estabelecimentos para o filtro (admin) ────────────────────
if (isAdminGeral()) {
    $estabs_lista = $conn->query("
        SELECT id, name, {$col_is_matriz_plain} as is_matriz
        FROM estabelecimentos
        WHERE status = 1
        ORDER BY {$order_is_matriz} name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Franqueado: lista apenas os estabelecimentos liberados para ele
    if (!empty($ids_liberados)) {
        $in_ph = implode(',', array_fill(0, count($ids_liberados), '?'));
        $stmt_el = $conn->prepare("
            SELECT id, name, {$col_is_matriz_plain} as is_matriz
            FROM estabelecimentos
            WHERE id IN ($in_ph) AND status = 1
            ORDER BY name ASC
        ");
        $stmt_el->execute($ids_liberados);
        $estabs_lista = $stmt_el->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $estabs_lista = [];
    }
}

// ── Construir WHERE ───────────────────────────────────────────────────────────
$where  = ["DATE(o.created_at) BETWEEN ? AND ?"];
$params = [$data_inicio, $data_fim];

if (!isAdminGeral()) {
    // Franqueado: restringir aos estabelecimentos liberados
    if (!empty($ids_liberados)) {
        $in_ph = implode(',', array_fill(0, count($ids_liberados), '?'));
        $where[] = "o.estabelecimento_id IN ($in_ph)";
        foreach ($ids_liberados as $eid) {
            $params[] = $eid;
        }
    } else {
        $where[] = "1=0"; // sem acesso
    }
} else {
    // Admin: aplicar filtro de estabelecimento se selecionado
    if ($filtro_estab_id > 0) {
        $where[] = "o.estabelecimento_id = ?";
        $params[] = $filtro_estab_id;
    }
}

if (!empty($status)) {
    $where[] = "o.checkout_status = ?";
    $params[] = $status;
}
if (!empty($metodo)) {
    $where[] = "o.method = ?";
    $params[] = $metodo;
}

$where_clause = implode(' AND ', $where);

// JOIN com movimentacoes_bancarias e contas_bancarias para mostrar conta e valor líquido
$join_lancamento = '';
$col_mov = '';
if ($has_lancamento_id) {
    $join_lancamento = "
        LEFT JOIN movimentacoes_bancarias mb ON mb.id = o.lancamento_bancario_id
        LEFT JOIN contas_bancarias cb        ON cb.id = mb.conta_bancaria_id
    ";
    // Nota: a vírgula inicial separa de t.android_id
    $col_mov = ",
        mb.valor       AS lancamento_valor,
        cb.nome        AS conta_nome,
        cb.banco       AS conta_banco
    ";
}

// ── Buscar pedidos ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        o.*,
        b.name                          AS bebida_name,
        e.name                          AS estabelecimento_name,
        {$col_is_matriz}                AS estab_is_matriz,
        t.android_id
        {$col_mov}
    FROM `order` o
    INNER JOIN bebidas b          ON o.bebida_id         = b.id
    INNER JOIN estabelecimentos e ON o.estabelecimento_id = e.id
    INNER JOIN tap t              ON o.tap_id            = t.id
    {$join_lancamento}
    WHERE $where_clause
    ORDER BY o.created_at DESC
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Estatísticas ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                                                              AS total_pedidos,
        SUM(CASE WHEN o.checkout_status IN ('SUCCESSFUL','PAID','APPROVED') THEN o.valor    ELSE 0 END) AS total_vendas,
        SUM(CASE WHEN o.checkout_status IN ('SUCCESSFUL','PAID','APPROVED') THEN o.quantidade ELSE 0 END) AS total_litros
    FROM `order` o
    WHERE $where_clause
");
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Relatório de Pedidos</h1>
</div>

<!-- ── Filtros ── -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET">
            <div class="row align-items-end g-2">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($data_inicio) ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($data_fim) ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">Todos</option>
                        <option value="PAID"       <?= $status === 'PAID'       ? 'selected' : '' ?>>Pago (PAID)</option>
                        <option value="SUCCESSFUL" <?= $status === 'SUCCESSFUL' ? 'selected' : '' ?>>Sucesso</option>
                        <option value="APPROVED"   <?= $status === 'APPROVED'   ? 'selected' : '' ?>>Aprovado</option>
                        <option value="PENDING"    <?= $status === 'PENDING'    ? 'selected' : '' ?>>Pendente</option>
                        <option value="CANCELLED"  <?= $status === 'CANCELLED'  ? 'selected' : '' ?>>Cancelado</option>
                        <option value="FAILED"     <?= $status === 'FAILED'     ? 'selected' : '' ?>>Falhou</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Método</label>
                    <select name="metodo" class="form-control">
                        <option value="">Todos</option>
                        <option value="pix"    <?= $metodo === 'pix'    ? 'selected' : '' ?>>PIX</option>
                        <option value="credit" <?= $metodo === 'credit' ? 'selected' : '' ?>>Crédito</option>
                        <option value="debit"  <?= $metodo === 'debit'  ? 'selected' : '' ?>>Débito</option>
                    </select>
                </div>
                <?php if (!empty($estabs_lista) && (isAdminGeral() || count($estabs_lista) > 1)): ?>
                <div class="col-md-3 col-sm-12">
                    <label class="form-label">Estabelecimento</label>
                    <select name="estabelecimento_id" class="form-control">
                        <option value="0">Todos</option>
                        <?php foreach ($estabs_lista as $el): ?>
                        <option value="<?= $el['id'] ?>" <?= $filtro_estab_id == $el['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($el['name']) ?><?= $el['is_matriz'] ? ' ★' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 col-sm-12">
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
                <?php else: ?>
                <div class="col-md-4 col-sm-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Estatísticas ── -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="stats-card">
            <p>Total de Pedidos</p>
            <h3><?= (int)$stats['total_pedidos'] ?></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card">
            <p>Total em Vendas (pagos)</p>
            <h3><?= formatMoney($stats['total_vendas'] ?? 0) ?></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card">
            <p>Total em Litros</p>
            <h3><?= number_format(($stats['total_litros'] ?? 0) / 1000, 2, ',', '.') ?> L</h3>
        </div>
    </div>
</div>

<!-- ── Tabela de Pedidos ── -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data/Hora</th>
                        <th>Bebida</th>
                        <?php if (isAdminGeral() || count($estabs_lista) > 1): ?>
                        <th>Estabelecimento</th>
                        <th>Tipo</th>
                        <?php endif; ?>
                        <th>Qtd</th>
                        <th>Valor Bruto</th>
                        <th>Método</th>
                        <th>Status Pgto</th>
                        <th>Status Lib.</th>
                        <?php if ($has_lancamento_id): ?>
                        <th title="Conta bancária onde o valor foi lançado">Conta Bancária</th>
                        <th title="Valor líquido lançado (bruto - taxa)">Valor Líquido</th>
                        <?php if ($has_taxa_aplicada): ?>
                        <th title="Taxa descontada do valor bruto">Taxa</th>
                        <?php endif; ?>
                        <?php endif; ?>
                        <th>CPF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pedidos)): ?>
                    <tr>
                        <?php
                        $colspan = 9;
                        if (isAdminGeral() || count($estabs_lista) > 1) $colspan += 2;
                        if ($has_lancamento_id) $colspan += 2;
                        if ($has_taxa_aplicada) $colspan += 1;
                        ?>
                        <td colspan="<?= $colspan ?>" class="text-center text-muted py-3">
                            Nenhum pedido encontrado para o período selecionado.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($pedidos as $pedido): ?>
                        <?php
                            // Determinar tipo do estabelecimento
                            $is_matriz = (int)($pedido['estab_is_matriz'] ?? 0);
                            if ($is_matriz) {
                                $tipo_badge = '<span class="badge" style="background:#1e3a8a;color:#fff;font-size:0.75em;">Matriz</span>';
                            } else {
                                $tipo_badge = '<span class="badge" style="background:#0ea5e9;color:#fff;font-size:0.75em;">Franqueado</span>';
                            }

                            // Status do lançamento bancário
                            $lancado = $has_lancamento_id && !empty($pedido['lancamento_bancario_id']);
                            $valor_bruto = floatval($pedido['valor'] ?? 0);
                            $taxa_val    = $has_taxa_aplicada ? floatval($pedido['taxa_aplicada'] ?? 0) : 0;
                            $valor_liq   = $lancado && isset($pedido['lancamento_valor'])
                                           ? floatval($pedido['lancamento_valor'])
                                           : ($valor_bruto - $taxa_val);
                        ?>
                        <tr>
                            <td><?= $pedido['id'] ?></td>
                            <td><?= formatDateTimeBR($pedido['created_at']) ?></td>
                            <td><?= htmlspecialchars($pedido['bebida_name']) ?></td>
                            <?php if (isAdminGeral() || count($estabs_lista) > 1): ?>
                            <td><?= htmlspecialchars($pedido['estabelecimento_name']) ?></td>
                            <td><?= $tipo_badge ?></td>
                            <?php endif; ?>
                            <td><?= $pedido['quantidade'] ?> ml</td>
                            <td><?= formatMoney($pedido['valor']) ?></td>
                            <td><?= getPaymentMethod($pedido['method']) ?></td>
                            <td>
                                <span class="badge badge-<?= getOrderStatusClass($pedido['checkout_status']) ?>">
                                    <?= htmlspecialchars($pedido['checkout_status']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= getOrderStatusClass($pedido['status_liberacao']) ?>">
                                    <?= htmlspecialchars($pedido['status_liberacao']) ?>
                                </span>
                            </td>
                            <?php if ($has_lancamento_id): ?>
                            <td>
                                <?php if ($lancado): ?>
                                    <?php
                                    $conta_label = '';
                                    if (!empty($pedido['conta_nome'])) {
                                        $conta_label = htmlspecialchars($pedido['conta_nome']);
                                        if (!empty($pedido['conta_banco'])) {
                                            $conta_label .= '<br><small class="text-muted">' . htmlspecialchars($pedido['conta_banco']) . '</small>';
                                        }
                                    } else {
                                        $conta_label = '<span class="text-muted">—</span>';
                                    }
                                    ?>
                                    <span class="badge badge-success" title="Lançamento #<?= (int)$pedido['lancamento_bancario_id'] ?>">
                                        ✓ Lançado
                                    </span>
                                    <br><?= $conta_label ?>
                                <?php else: ?>
                                    <?php
                                    $status_pago = in_array(strtoupper($pedido['checkout_status'] ?? ''), ['PAID','SUCCESSFUL','APPROVED','COMPLETED']);
                                    if ($status_pago): ?>
                                        <span class="badge badge-warning" title="Pagamento aprovado mas lançamento pendente">
                                            ⚠ Pendente
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lancado): ?>
                                    <strong><?= formatMoney($valor_liq) ?></strong>
                                <?php elseif (in_array(strtoupper($pedido['checkout_status'] ?? ''), ['PAID','SUCCESSFUL','APPROVED','COMPLETED'])): ?>
                                    <span class="text-muted"><?= formatMoney($valor_bruto) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($has_taxa_aplicada): ?>
                            <td>
                                <?php if ($lancado && $taxa_val > 0): ?>
                                    <span class="text-danger">- <?= formatMoney($taxa_val) ?></span>
                                <?php elseif ($lancado): ?>
                                    <span class="text-muted">R$ 0,00</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($pedido['cpf'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($pedidos)): ?>
        <div class="mt-2 text-muted small">
            Exibindo <?= count($pedidos) ?> pedido(s)
            <?php if (!isAdminGeral() && count($estabs_lista) === 1): ?>
            — <?= htmlspecialchars($estabs_lista[0]['name'] ?? '') ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
