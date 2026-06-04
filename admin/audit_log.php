<?php
$page_title = 'Log de Auditoria';
$current_page = 'usuarios';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
requireAdminGeral();

$conn = getDBConnection();

// ── Filtros ────────────────────────────────────────────────────────────────
$filtro_user_id   = isset($_GET['user_id'])   ? (int)$_GET['user_id']   : 0;
$filtro_estab_id  = isset($_GET['estab_id'])  ? (int)$_GET['estab_id']  : 0;
$filtro_evento    = isset($_GET['evento'])    ? trim($_GET['evento'])    : '';
$filtro_ip        = isset($_GET['ip'])        ? trim($_GET['ip'])        : '';
$filtro_data_ini  = isset($_GET['data_ini'])  ? trim($_GET['data_ini'])  : date('Y-m-d', strtotime('-7 days'));
$filtro_data_fim  = isset($_GET['data_fim'])  ? trim($_GET['data_fim'])  : date('Y-m-d');
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 50;
$offset           = ($page - 1) * $per_page;

// ── Construir WHERE dinâmico ───────────────────────────────────────────────
$where  = [];
$params = [];

if ($filtro_user_id > 0) {
    $where[]  = 'al.user_id = ?';
    $params[] = $filtro_user_id;
}
if ($filtro_estab_id > 0) {
    $where[]  = 'al.estabelecimento_id = ?';
    $params[] = $filtro_estab_id;
}
if ($filtro_evento !== '') {
    $where[]  = 'al.evento = ?';
    $params[] = $filtro_evento;
}
if ($filtro_ip !== '') {
    $where[]  = 'al.ip LIKE ?';
    $params[] = '%' . $filtro_ip . '%';
}
if ($filtro_data_ini !== '') {
    $where[]  = 'DATE(al.created_at) >= ?';
    $params[] = $filtro_data_ini;
}
if ($filtro_data_fim !== '') {
    $where[]  = 'DATE(al.created_at) <= ?';
    $params[] = $filtro_data_fim;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Total de registros (paginação) ─────────────────────────────────────────
$stmt_cnt = $conn->prepare("SELECT COUNT(*) FROM audit_log al {$where_sql}");
$stmt_cnt->execute($params);
$total = (int)$stmt_cnt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ── Buscar registros ───────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT al.*
    FROM audit_log al
    {$where_sql}
    ORDER BY al.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// ── Cards de resumo (últimos 30 dias) ─────────────────────────────────────
$stmt_res = $conn->query("
    SELECT
        COUNT(*) AS total_eventos,
        SUM(evento = 'login_ok')        AS logins_ok,
        SUM(evento = 'login_falha')     AS logins_falha,
        SUM(evento = 'logout')          AS logouts,
        COUNT(DISTINCT user_id)         AS usuarios_ativos,
        COUNT(DISTINCT ip)              AS ips_distintos
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$resumo = $stmt_res->fetch();

// ── Listar usuários para filtro ────────────────────────────────────────────
$usuarios_lista = $conn->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();

// ── Listar estabelecimentos para filtro ───────────────────────────────────
$estabs_lista = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name")->fetchAll();

// ── Mapa de cores/ícones por evento ───────────────────────────────────────
$evento_meta = [
    'login_ok'              => ['badge' => 'badge-success', 'icon' => 'fa-sign-in-alt',    'label' => 'Login OK'],
    'login_falha'           => ['badge' => 'badge-danger',  'icon' => 'fa-times-circle',   'label' => 'Login Falhou'],
    'logout'                => ['badge' => 'badge-secondary','icon' => 'fa-sign-out-alt',  'label' => 'Logout'],
    'troca_estabelecimento' => ['badge' => 'badge-info',    'icon' => 'fa-exchange-alt',   'label' => 'Troca Estab.'],
    'acesso_pagina'         => ['badge' => 'badge-light',   'icon' => 'fa-eye',            'label' => 'Acesso Página'],
    'criar'                 => ['badge' => 'badge-primary', 'icon' => 'fa-plus-circle',    'label' => 'Criar'],
    'editar'                => ['badge' => 'badge-warning', 'icon' => 'fa-edit',           'label' => 'Editar'],
    'excluir'               => ['badge' => 'badge-danger',  'icon' => 'fa-trash-alt',      'label' => 'Excluir'],
    'exportar'              => ['badge' => 'badge-info',    'icon' => 'fa-file-export',    'label' => 'Exportar'],
    'visualizar_relatorio'  => ['badge' => 'badge-secondary','icon' => 'fa-chart-bar',     'label' => 'Relatório'],
];

require_once '../includes/header.php';
?>

<style>
/* ── Estilos específicos do Log de Auditoria ─────────────────────────── */
.audit-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.audit-card {
    background: var(--white);
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.audit-card .ac-label {
    font-size: 11px;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: .5px;
}
.audit-card .ac-value {
    font-size: 26px;
    font-weight: 700;
    color: var(--gray-800);
    line-height: 1;
}
.audit-card .ac-icon {
    font-size: 20px;
    margin-bottom: 4px;
}
.audit-card.card-green  .ac-icon { color: #22c55e; }
.audit-card.card-red    .ac-icon { color: #ef4444; }
.audit-card.card-blue   .ac-icon { color: #3b82f6; }
.audit-card.card-orange .ac-icon { color: #f97316; }
.audit-card.card-purple .ac-icon { color: #8b5cf6; }
.audit-card.card-gray   .ac-icon { color: #6b7280; }

.audit-filters {
    background: var(--white);
    border-radius: 10px;
    padding: 18px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    margin-bottom: 20px;
}
.audit-filters form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.audit-filters .fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
}
.audit-filters label {
    font-size: 11px;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
}
.audit-filters select,
.audit-filters input[type="text"],
.audit-filters input[type="date"] {
    padding: 7px 10px;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 13px;
    background: var(--white);
    color: var(--gray-800);
}
.audit-filters .btn-filtrar {
    padding: 8px 20px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}
.audit-filters .btn-limpar {
    padding: 8px 14px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}

.audit-table-wrap {
    background: var(--white);
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    overflow: hidden;
}
.audit-table-wrap .table-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.audit-table-wrap .table-header h3 {
    font-size: 15px;
    font-weight: 700;
    color: var(--gray-800);
    margin: 0;
}
.audit-table-wrap .table-header .total-badge {
    background: var(--gray-100);
    color: var(--gray-600);
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 20px;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.audit-table th {
    background: var(--gray-50);
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 1px solid var(--gray-200);
    white-space: nowrap;
}
.audit-table td {
    padding: 9px 14px;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
    color: var(--gray-700);
}
.audit-table tr:last-child td { border-bottom: none; }
.audit-table tr:hover td { background: var(--gray-50); }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.badge-success   { background: #dcfce7; color: #16a34a; }
.badge-danger    { background: #fee2e2; color: #dc2626; }
.badge-warning   { background: #fef3c7; color: #d97706; }
.badge-info      { background: #dbeafe; color: #2563eb; }
.badge-primary   { background: #ede9fe; color: #7c3aed; }
.badge-secondary { background: #f1f5f9; color: #475569; }
.badge-light     { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

.ua-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--gray-500);
    font-size: 11px;
    cursor: help;
}
.desc-cell {
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: help;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    padding: 16px;
    border-top: 1px solid var(--gray-200);
}
.pagination a, .pagination span {
    padding: 5px 11px;
    border-radius: 6px;
    font-size: 13px;
    text-decoration: none;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
}
.pagination a:hover { background: var(--gray-100); }
.pagination .active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pagination .disabled { color: var(--gray-400); pointer-events: none; }

.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--gray-500);
}
.empty-state i { font-size: 40px; margin-bottom: 12px; display: block; }
</style>

<!-- ── Cards de Resumo ─────────────────────────────────────────────────── -->
<div class="audit-cards">
    <div class="audit-card card-blue">
        <i class="fas fa-list-alt ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['total_eventos']); ?></div>
        <div class="ac-label">Eventos (30 dias)</div>
    </div>
    <div class="audit-card card-green">
        <i class="fas fa-sign-in-alt ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['logins_ok']); ?></div>
        <div class="ac-label">Logins OK</div>
    </div>
    <div class="audit-card card-red">
        <i class="fas fa-times-circle ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['logins_falha']); ?></div>
        <div class="ac-label">Logins Falhos</div>
    </div>
    <div class="audit-card card-gray">
        <i class="fas fa-sign-out-alt ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['logouts']); ?></div>
        <div class="ac-label">Logouts</div>
    </div>
    <div class="audit-card card-purple">
        <i class="fas fa-users ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['usuarios_ativos']); ?></div>
        <div class="ac-label">Usuários Ativos</div>
    </div>
    <div class="audit-card card-orange">
        <i class="fas fa-globe ac-icon"></i>
        <div class="ac-value"><?php echo number_format($resumo['ips_distintos']); ?></div>
        <div class="ac-label">IPs Distintos</div>
    </div>
</div>

<!-- ── Filtros ─────────────────────────────────────────────────────────── -->
<div class="audit-filters">
    <form method="GET" action="audit_log.php">
        <div class="fg">
            <label>Usuário</label>
            <select name="user_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios_lista as $u): ?>
                <option value="<?php echo $u['id']; ?>" <?php echo $filtro_user_id == $u['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Estabelecimento</label>
            <select name="estab_id">
                <option value="">Todos</option>
                <?php foreach ($estabs_lista as $e): ?>
                <option value="<?php echo $e['id']; ?>" <?php echo $filtro_estab_id == $e['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($e['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Evento</label>
            <select name="evento">
                <option value="">Todos</option>
                <?php foreach ($evento_meta as $ev => $meta): ?>
                <option value="<?php echo $ev; ?>" <?php echo $filtro_evento === $ev ? 'selected' : ''; ?>>
                    <?php echo $meta['label']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>IP</label>
            <input type="text" name="ip" value="<?php echo htmlspecialchars($filtro_ip); ?>" placeholder="Ex: 192.168">
        </div>
        <div class="fg">
            <label>De</label>
            <input type="date" name="data_ini" value="<?php echo $filtro_data_ini; ?>">
        </div>
        <div class="fg">
            <label>Até</label>
            <input type="date" name="data_fim" value="<?php echo $filtro_data_fim; ?>">
        </div>
        <button type="submit" class="btn-filtrar"><i class="fas fa-search"></i> Filtrar</button>
        <a href="audit_log.php" class="btn-limpar">Limpar</a>
    </form>
</div>

<!-- ── Tabela de Logs ──────────────────────────────────────────────────── -->
<div class="audit-table-wrap">
    <div class="table-header">
        <h3><i class="fas fa-shield-alt" style="color:var(--primary);margin-right:8px;"></i>Log de Auditoria</h3>
        <span class="total-badge"><?php echo number_format($total); ?> registros</span>
    </div>

    <?php if (empty($logs)): ?>
    <div class="empty-state">
        <i class="fas fa-search"></i>
        Nenhum registro encontrado para os filtros selecionados.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Evento</th>
                    <th>Usuário</th>
                    <th>Tipo</th>
                    <th>Estabelecimento</th>
                    <th>Descrição</th>
                    <th>IP</th>
                    <th>Dispositivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log):
                    $meta = $evento_meta[$log['evento']] ?? ['badge' => 'badge-secondary', 'icon' => 'fa-circle', 'label' => $log['evento']];
                    $dt   = new DateTime($log['created_at']);
                    $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
                ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px;">
                        <strong><?php echo $dt->format('d/m/Y'); ?></strong><br>
                        <span style="color:var(--gray-500);"><?php echo $dt->format('H:i:s'); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $meta['badge']; ?>">
                            <i class="fas <?php echo $meta['icon']; ?>"></i>
                            <?php echo $meta['label']; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($log['user_id']): ?>
                        <a href="audit_log.php?user_id=<?php echo $log['user_id']; ?><?php echo $filtro_data_ini ? '&data_ini='.$filtro_data_ini : ''; ?><?php echo $filtro_data_fim ? '&data_fim='.$filtro_data_fim : ''; ?>"
                           style="color:var(--primary);text-decoration:none;font-weight:600;">
                            <?php echo htmlspecialchars($log['user_name'] ?? "#{$log['user_id']}"); ?>
                        </a><br>
                        <span style="font-size:11px;color:var(--gray-500);"><?php echo htmlspecialchars($log['user_email'] ?? ''); ?></span>
                        <?php else: ?>
                        <span style="color:var(--gray-400);font-style:italic;">Desconhecido</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php echo $log['user_type'] ? getUserType($log['user_type']) : '—'; ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php if ($log['estabelecimento_id']): ?>
                        <a href="audit_log.php?estab_id=<?php echo $log['estabelecimento_id']; ?><?php echo $filtro_data_ini ? '&data_ini='.$filtro_data_ini : ''; ?><?php echo $filtro_data_fim ? '&data_fim='.$filtro_data_fim : ''; ?>"
                           style="color:var(--primary);text-decoration:none;">
                            <?php echo htmlspecialchars($log['estabelecimento_nome'] ?? "#{$log['estabelecimento_id']}"); ?>
                        </a>
                        <?php else: ?>
                        <span style="color:var(--gray-400);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="desc-cell" title="<?php echo htmlspecialchars($log['descricao'] ?? ''); ?>">
                            <?php echo htmlspecialchars($log['descricao'] ?? '—'); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;white-space:nowrap;">
                        <a href="audit_log.php?ip=<?php echo urlencode($log['ip']); ?>"
                           style="color:var(--primary);text-decoration:none;font-family:monospace;">
                            <?php echo htmlspecialchars($log['ip']); ?>
                        </a>
                    </td>
                    <td>
                        <span class="ua-cell" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>">
                            <?php echo htmlspecialchars($log['user_agent'] ?? '—'); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Paginação ──────────────────────────────────────────────────── -->
    <?php if ($total_pages > 1):
        // Montar query string sem o parâmetro page
        $qs_base = http_build_query(array_filter([
            'user_id'  => $filtro_user_id  ?: null,
            'estab_id' => $filtro_estab_id ?: null,
            'evento'   => $filtro_evento   ?: null,
            'ip'       => $filtro_ip       ?: null,
            'data_ini' => $filtro_data_ini ?: null,
            'data_fim' => $filtro_data_fim ?: null,
        ]));
        $qs_base = $qs_base ? $qs_base . '&' : '';
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="audit_log.php?<?php echo $qs_base; ?>page=<?php echo $page - 1; ?>">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>

        <?php
        $range = 2;
        for ($p = max(1, $page - $range); $p <= min($total_pages, $page + $range); $p++):
        ?>
        <?php if ($p === $page): ?>
        <span class="active"><?php echo $p; ?></span>
        <?php else: ?>
        <a href="audit_log.php?<?php echo $qs_base; ?>page=<?php echo $p; ?>"><?php echo $p; ?></a>
        <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
        <a href="audit_log.php?<?php echo $qs_base; ?>page=<?php echo $page + 1; ?>">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>

        <span style="color:var(--gray-500);font-size:12px;border:none;">
            Página <?php echo $page; ?> de <?php echo $total_pages; ?>
        </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
