<?php
/**
 * Roteamento de Meios de Pagamento por Conta Bancária — ChoppOnTap
 *
 * Regra de negócio:
 *   - A CONTA BANCÁRIA é o elemento central
 *   - Para cada conta, define-se quais meios de pagamento são aceitos/abatidos
 *   - Ao finalizar um pedido (SUCCESSFUL + FINISHED), o sistema busca qual
 *     conta bancária aceita o meio de pagamento usado e lança a entrada
 */
$page_title   = 'Roteamento de Meios de Pagamento';
$current_page = 'meios_pagamento_contas';
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireAuth();

$conn    = getDBConnection();
$success = '';
$error   = '';

// ── Verificar se tabela contas_bancarias existe ───────────────────────────────
$has_cb = false;
try { $conn->query("SELECT id FROM contas_bancarias LIMIT 1"); $has_cb = true; } catch (Exception $e) {}

// ── Verificar se coluna meios_pagamento_aceitos existe ────────────────────────
$has_col_meios = false;
if ($has_cb) {
    try { $conn->query("SELECT meios_pagamento_aceitos FROM contas_bancarias LIMIT 1"); $has_col_meios = true; } catch (Exception $e) {}
}

// ── Buscar estabelecimentos disponíveis ──────────────────────────────────────
if (isAdminGeral()) {
    $stmt_estabs = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
} else {
    $stmt_estabs = $conn->prepare("
        SELECT e.id, e.name
        FROM estabelecimentos e
        INNER JOIN user_estabelecimento ue ON e.id = ue.estabelecimento_id
        WHERE ue.user_id = ? AND ue.status = 1 AND e.status = 1
        ORDER BY e.name
    ");
    $stmt_estabs->execute([$_SESSION['user_id']]);
}
$estabelecimentos = $stmt_estabs->fetchAll(PDO::FETCH_ASSOC);

// ── Determinar estabelecimento selecionado ────────────────────────────────────
$estab_id = isAdminGeral()
    ? intval($_GET['estab'] ?? $_POST['estab_id'] ?? ($estabelecimentos[0]['id'] ?? 0))
    : intval(getEstabelecimentoId());

$estab_nome = '';
foreach ($estabelecimentos as $e) {
    if ($e['id'] == $estab_id) { $estab_nome = $e['name']; break; }
}

// ── Processar salvar meios aceitos por conta ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_meios'])) {
    if (!$has_col_meios) {
        $error = 'Execute a migração SQL antes de salvar. Coluna meios_pagamento_aceitos não existe.';
    } else {
        $post_estab = isAdminGeral() ? intval($_POST['estab_id'] ?? $estab_id) : intval(getEstabelecimentoId());
        $contas_post = $_POST['conta'] ?? [];

        // Primeiro, limpar todos os meios das contas deste estabelecimento
        $conn->prepare("UPDATE contas_bancarias SET meios_pagamento_aceitos = NULL WHERE estabelecimento_id = ?")
             ->execute([$post_estab]);

        // Depois, salvar os novos vínculos
        $saved = 0;
        foreach ($contas_post as $cb_id => $dados) {
            $cb_id = intval($cb_id);
            if (!$cb_id) continue;
            $meios = isset($dados['meios']) && is_array($dados['meios'])
                ? implode(',', array_map('trim', $dados['meios']))
                : '';
            if ($meios) {
                $conn->prepare("UPDATE contas_bancarias SET meios_pagamento_aceitos = ? WHERE id = ? AND estabelecimento_id = ?")
                     ->execute([$meios, $cb_id, $post_estab]);
                $saved++;
            }
        }
        $success = "Roteamento salvo com sucesso! $saved conta(s) configurada(s).";
        $estab_id = $post_estab;
    }
}

// ── Buscar contas bancárias do estabelecimento ────────────────────────────────
$contas = [];
if ($has_cb && $estab_id) {
    $cb_cols = "id, nome, banco, tipo, saldo_atual, ativa";
    if ($has_col_meios) $cb_cols .= ", meios_pagamento_aceitos";
    $stmt_cb = $conn->prepare("SELECT $cb_cols FROM contas_bancarias WHERE estabelecimento_id = ? ORDER BY ativa DESC, nome");
    $stmt_cb->execute([$estab_id]);
    $contas = $stmt_cb->fetchAll(PDO::FETCH_ASSOC);
}

// ── Definição dos meios disponíveis ──────────────────────────────────────────
$meios_disponiveis = [
    'pix'    => ['label' => 'PIX',           'icon' => 'fas fa-qrcode',       'color' => '#27ae60'],
    'credit' => ['label' => 'Cartão Crédito','icon' => 'fas fa-credit-card',  'color' => '#2980b9'],
    'debit'  => ['label' => 'Cartão Débito', 'icon' => 'fas fa-credit-card',  'color' => '#8e44ad'],
    'cash'   => ['label' => 'Dinheiro',      'icon' => 'fas fa-money-bill',   'color' => '#e67e22'],
    'sumup'  => ['label' => 'SumUp',         'icon' => 'fas fa-mobile-alt',   'color' => '#e74c3c'],
];

$tipo_labels = [
    'corrente'    => 'Conta Corrente',
    'poupanca'    => 'Poupança',
    'caixa'       => 'Caixa Físico',
    'pix'         => 'PIX / Carteira',
    'investimento'=> 'Investimento',
    'outro'       => 'Outro',
];

require_once '../includes/header.php';
?>

<style>
.mp-conta-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 100%;
    transition: box-shadow .2s;
}
.mp-conta-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); }
.mp-conta-card.inativa { opacity: .65; }

.mp-conta-header {
    padding: 14px 16px;
    color: #fff;
}
.mp-conta-header.ativa  { background: var(--primary, #1a56db); }
.mp-conta-header.inativa{ background: #6c757d; }

.mp-conta-header .conta-nome { font-weight: 700; font-size: 15px; }
.mp-conta-header .conta-info { font-size: 12px; opacity: .85; margin-top: 3px; }
.mp-conta-header .conta-saldo{ font-size: 13px; margin-top: 8px; opacity: .9; }
.mp-conta-header .badge-status {
    background: rgba(255,255,255,.2);
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 11px;
}

.mp-meios-body { padding: 16px; flex: 1; }
.mp-meios-body .label-section {
    font-size: 11px;
    font-weight: 700;
    color: var(--gray-500, #6b7280);
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 10px;
}

.mp-meio-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 7px;
    border: 1.5px solid #e5e7eb;
    background: #fff;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all .18s;
    user-select: none;
}
.mp-meio-item.checked {
    border-color: var(--meio-color);
    background: color-mix(in srgb, var(--meio-color) 10%, #fff);
}
.mp-meio-item:hover { border-color: var(--meio-color); }
.mp-meio-item input[type=checkbox] {
    width: 16px; height: 16px;
    cursor: pointer;
    flex-shrink: 0;
}
.mp-meio-item .meio-icon {
    width: 18px;
    text-align: center;
    flex-shrink: 0;
}
.mp-meio-item .meio-label { font-size: 13px; font-weight: 500; flex: 1; }
.mp-meio-item .meio-badge {
    font-size: 10px;
    background: var(--meio-color);
    color: #fff;
    border-radius: 8px;
    padding: 1px 8px;
}

.mp-conta-footer {
    border-top: 1px solid #f0f0f0;
    padding: 10px 16px;
}

/* Tabela de roteamento */
.roteamento-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.roteamento-table th {
    background: #f8fafc;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}
.roteamento-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}
.roteamento-table tr:hover td { background: #f9fafb; }

.tag-conta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #e8f4fd;
    color: #2980b9;
    border-radius: 8px;
    padding: 3px 10px;
    font-size: 12px;
    margin: 2px;
}
.tag-sem-conta {
    color: #e74c3c;
    font-size: 13px;
}

/* Botão salvar fixo */
.btn-salvar-fixo {
    position: sticky;
    bottom: 20px;
    text-align: right;
    z-index: 100;
    margin-top: 12px;
    pointer-events: none;
}
.btn-salvar-fixo button { pointer-events: all; box-shadow: 0 4px 16px rgba(0,0,0,.2); }
</style>

<div class="page-header">
    <h1><i class="fas fa-route"></i> Roteamento de Meios de Pagamento</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$has_cb): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;">
        <i class="fas fa-database" style="font-size:48px;color:#e67e22;margin-bottom:16px;display:block;"></i>
        <h3>Migração necessária</h3>
        <p>Execute <code>sql/migration_financeiro_contas_bancarias.sql</code> no phpMyAdmin para criar as tabelas de contas bancárias.</p>
    </div>
</div>
<?php require_once '../includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if (!$has_col_meios): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Migração necessária.</strong> Execute o SQL abaixo no phpMyAdmin:
    <pre style="background:#fff3cd;padding:10px;border-radius:4px;margin-top:8px;font-size:13px;white-space:pre-wrap;">ALTER TABLE `contas_bancarias` ADD COLUMN `meios_pagamento_aceitos` VARCHAR(255) NULL DEFAULT NULL AFTER `ativa`;</pre>
</div>
<?php endif; ?>

<!-- Seletor de Estabelecimento -->
<?php if (isAdminGeral() && count($estabelecimentos) > 1): ?>
<div class="card mb-3">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="margin:0;font-weight:600;white-space:nowrap;"><i class="fas fa-store"></i> Estabelecimento:</label>
            <select name="estab" class="form-control" style="max-width:340px;" onchange="this.form.submit()">
                <?php foreach ($estabelecimentos as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $e['id'] == $estab_id ? 'selected' : '' ?>>
                    #<?= $e['id'] ?> — <?= htmlspecialchars($e['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($estab_nome): ?>
            <span class="badge badge-primary" style="font-size:13px;padding:6px 12px;"><?= htmlspecialchars($estab_nome) ?></span>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Explicação -->
<div class="card mb-3" style="border-left:4px solid #3498db;">
    <div class="card-body" style="padding:14px 20px;">
        <p style="margin:0;font-size:14px;color:var(--gray-700);">
            <i class="fas fa-info-circle" style="color:#3498db;"></i>
            <strong>Como funciona:</strong> Selecione a <strong>conta bancária</strong> e marque quais <strong>meios de pagamento</strong> serão abatidos (lançados como entrada) nela.
            Ao finalizar um pedido, o sistema identifica automaticamente a conta correta pelo meio de pagamento utilizado e registra a entrada com atualização de saldo.
            Um mesmo meio de pagamento pode estar vinculado a apenas uma conta por estabelecimento.
        </p>
    </div>
</div>

<?php if (empty($contas)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;">
        <i class="fas fa-university" style="font-size:48px;color:var(--gray-400);margin-bottom:16px;display:block;"></i>
        <h4 style="color:var(--gray-500);">Nenhuma conta bancária cadastrada</h4>
        <p style="color:var(--gray-500);">Cadastre contas bancárias primeiro para configurar o roteamento.</p>
        <a href="financeiro_contas_bancarias.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Cadastrar Conta Bancária
        </a>
    </div>
</div>
<?php else: ?>

<form method="POST" id="formMeios">
    <input type="hidden" name="save_meios" value="1">
    <input type="hidden" name="estab_id" value="<?= $estab_id ?>">

    <div class="row">
        <?php foreach ($contas as $cb):
            $meios_ativos = !empty($cb['meios_pagamento_aceitos'])
                ? array_map('trim', explode(',', $cb['meios_pagamento_aceitos']))
                : [];
            $tipo_label = $tipo_labels[$cb['tipo']] ?? ucfirst($cb['tipo']);
            $is_ativa   = $cb['ativa'] == 1;
            $card_class = $is_ativa ? '' : ' inativa';
            $header_class = $is_ativa ? 'ativa' : 'inativa';
        ?>
        <div class="col-md-6 col-lg-4" style="margin-bottom:20px;">
            <div class="mp-conta-card<?= $card_class ?>">
                <!-- Cabeçalho -->
                <div class="mp-conta-header <?= $header_class ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="conta-nome">
                                <i class="fas fa-university"></i> <?= htmlspecialchars($cb['nome']) ?>
                            </div>
                            <div class="conta-info">
                                <?= htmlspecialchars($tipo_label) ?>
                                <?php if (!empty($cb['banco'])): ?> &middot; <?= htmlspecialchars($cb['banco']) ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="badge-status"><?= $is_ativa ? 'Ativa' : 'Inativa' ?></span>
                    </div>
                    <div class="conta-saldo">
                        Saldo: <strong>R$ <?= number_format((float)$cb['saldo_atual'], 2, ',', '.') ?></strong>
                    </div>
                </div>

                <!-- Meios aceitos -->
                <div class="mp-meios-body">
                    <div class="label-section">Meios de pagamento aceitos nesta conta</div>

                    <?php if (!$has_col_meios): ?>
                    <p style="color:var(--gray-500);font-size:13px;font-style:italic;">Execute a migração SQL para habilitar.</p>
                    <?php else: ?>

                    <?php foreach ($meios_disponiveis as $key => $meio):
                        $checked = in_array($key, $meios_ativos);
                    ?>
                    <label class="mp-meio-item <?= $checked ? 'checked' : '' ?>"
                           style="--meio-color:<?= $meio['color'] ?>;"
                           onclick="toggleMeio(this, '<?= $meio['color'] ?>')">
                        <input type="checkbox"
                               name="conta[<?= $cb['id'] ?>][meios][]"
                               value="<?= $key ?>"
                               <?= $checked ? 'checked' : '' ?>
                               onclick="event.stopPropagation();"
                               onchange="toggleMeioCheck(this, '<?= $meio['color'] ?>')">
                        <span class="meio-icon"><i class="<?= $meio['icon'] ?>" style="color:<?= $meio['color'] ?>;"></i></span>
                        <span class="meio-label"><?= $meio['label'] ?></span>
                        <?php if ($checked): ?>
                        <span class="meio-badge">Ativo</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </div>

                <!-- Rodapé -->
                <div class="mp-conta-footer">
                    <a href="financeiro_contas_bancarias.php?conta_id=<?= $cb['id'] ?>"
                       class="btn btn-sm btn-outline-primary" style="width:100%;font-size:12px;">
                        <i class="fas fa-list-alt"></i> Ver Movimentações
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Botão salvar fixo -->
    <?php if ($has_col_meios): ?>
    <div class="btn-salvar-fixo">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Salvar Roteamento
        </button>
    </div>
    <?php endif; ?>
</form>

<!-- Tabela de Roteamento (resumo) -->
<?php if ($has_col_meios): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;">
        <i class="fas fa-map-signs" style="color:var(--primary);"></i>
        <h4 style="margin:0;">Resumo do Roteamento de Pagamentos</h4>
        <small style="color:var(--gray-500);margin-left:auto;">Atualizado ao salvar</small>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive"><table class="roteamento-table">
            <thead>
                <tr>
                    <th>Meio de Pagamento</th>
                    <th>Conta Bancária Destino</th>
                    <th>Saldo Atual</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Montar mapa: meio → conta(s)
            $roteamento = [];
            foreach ($meios_disponiveis as $key => $meio) {
                $roteamento[$key] = [];
            }
            foreach ($contas as $cb) {
                if (empty($cb['meios_pagamento_aceitos'])) continue;
                $meios_cb = array_map('trim', explode(',', $cb['meios_pagamento_aceitos']));
                foreach ($meios_cb as $m) {
                    if (isset($roteamento[$m])) {
                        $roteamento[$m][] = $cb;
                    }
                }
            }
            foreach ($meios_disponiveis as $key => $meio):
                $destinos = $roteamento[$key];
            ?>
            <tr>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:7px;font-weight:600;">
                        <i class="<?= $meio['icon'] ?>" style="color:<?= $meio['color'] ?>;"></i>
                        <?= $meio['label'] ?>
                    </span>
                </td>
                <td>
                    <?php if (empty($destinos)): ?>
                    <span class="tag-sem-conta"><i class="fas fa-exclamation-circle"></i> Sem conta vinculada</span>
                    <?php else: ?>
                    <?php foreach ($destinos as $d): ?>
                    <span class="tag-conta">
                        <i class="fas fa-university"></i>
                        <?= htmlspecialchars($d['nome']) ?>
                        <?php if (!empty($d['banco'])): ?><small>(<?= htmlspecialchars($d['banco']) ?>)</small><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($destinos)): ?>
                    <?php foreach ($destinos as $d): ?>
                    <span style="font-size:13px;font-weight:600;">R$ <?= number_format((float)$d['saldo_atual'], 2, ',', '.') ?></span>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <span style="color:var(--gray-400);">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($destinos)): ?>
                    <span class="badge badge-success" style="font-size:11px;"><i class="fas fa-check"></i> Configurado</span>
                    <?php else: ?>
                    <span class="badge badge-warning" style="font-size:11px;"><i class="fas fa-exclamation"></i> Não configurado</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<?php endif; // fim !empty($contas) ?>

<script>
function toggleMeio(label, color) {
    var cb = label.querySelector('input[type=checkbox]');
    if (!cb) return;
    cb.checked = !cb.checked;
    atualizarEstiloLabel(label, cb.checked, color);
}

function toggleMeioCheck(checkbox, color) {
    var label = checkbox.closest('label');
    if (!label) return;
    atualizarEstiloLabel(label, checkbox.checked, color);
}

function atualizarEstiloLabel(label, checked, color) {
    var badge = label.querySelector('.meio-badge');
    if (checked) {
        label.classList.add('checked');
        label.style.setProperty('--meio-color', color);
        if (!badge) {
            var sp = document.createElement('span');
            sp.className = 'meio-badge';
            sp.textContent = 'Ativo';
            label.appendChild(sp);
        }
    } else {
        label.classList.remove('checked');
        if (badge) badge.remove();
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
