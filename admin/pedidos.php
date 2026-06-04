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

// ── Buscar pedidos ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        o.*,
        b.name                          AS bebida_name,
        e.name                          AS estabelecimento_name,
        {$col_is_matriz}                AS estab_is_matriz,
        t.android_id
    FROM `order` o
    INNER JOIN bebidas b          ON o.bebida_id         = b.id
    INNER JOIN estabelecimentos e ON o.estabelecimento_id = e.id
    INNER JOIN tap t              ON o.tap_id            = t.id
    WHERE $where_clause
    ORDER BY o.created_at DESC
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Estatísticas ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                                                              AS total_pedidos,
        SUM(CASE WHEN o.checkout_status = 'SUCCESSFUL' THEN o.valor    ELSE 0 END) AS total_vendas,
        SUM(CASE WHEN o.checkout_status = 'SUCCESSFUL' THEN o.quantidade ELSE 0 END) AS total_litros
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
                        <option value="SUCCESSFUL" <?= $status === 'SUCCESSFUL' ? 'selected' : '' ?>>Sucesso</option>
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
            <p>Total em Vendas</p>
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
                        <th>Quantidade</th>
                        <th>Valor</th>
                        <th>Método</th>
                        <th>Status Pagamento</th>
                        <th>Status Liberação</th>
                        <th>CPF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pedidos)): ?>
                    <tr>
                        <td colspan="<?= (isAdminGeral() || count($estabs_lista) > 1) ? '11' : '9' ?>" class="text-center text-muted py-3">
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
