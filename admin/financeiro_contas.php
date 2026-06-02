<?php
$page_title = 'Financeiro - Contas a Pagar';
$current_page = 'financeiro_contas';

require_once '../includes/config.php';
require_once '../includes/auth.php';

$conn = getDBConnection();

// ─── Processar ações POST ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];

            if ($action === 'add' || $action === 'edit') {
                $estabelecimento_id = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
                $descricao          = sanitize($_POST['descricao']);
                $tipo               = sanitize($_POST['tipo']);
                $valor              = numberToFloat($_POST['valor']);
                $data_vencimento    = $_POST['data_vencimento'];
                $codigo_barras      = !empty($_POST['codigo_barras']) ? sanitize($_POST['codigo_barras']) : null;
                $link_pagamento     = !empty($_POST['link_pagamento']) ? sanitize($_POST['link_pagamento']) : null;
                $observacoes        = !empty($_POST['observacoes']) ? sanitize($_POST['observacoes']) : null;
                $recorrencia        = isset($_POST['recorrencia']) ? intval($_POST['recorrencia']) : 1;
                if ($recorrencia < 1)  $recorrencia = 1;
                if ($recorrencia > 24) $recorrencia = 24;

                if ($action === 'edit') {
                    // ── Edição: atualiza apenas o registro individual ──────
                    $id = intval($_POST['id']);
                    $stmt = $conn->prepare("SELECT valor_protegido, valor FROM contas_pagar WHERE id = ?");
                    $stmt->execute([$id]);
                    $conta_atual = $stmt->fetch();

                    if ($conta_atual && $conta_atual['valor_protegido'] && !isAdminGeral()) {
                        $valor = $conta_atual['valor'];
                    }

                    $stmt = $conn->prepare("
                        UPDATE contas_pagar
                        SET descricao = ?, tipo = ?, valor = ?, data_vencimento = ?,
                            codigo_barras = ?, link_pagamento = ?, observacoes = ?
                        WHERE id = ? AND estabelecimento_id = ?
                    ");
                    $stmt->execute([$descricao, $tipo, $valor, $data_vencimento,
                                    $codigo_barras, $link_pagamento, $observacoes,
                                    $id, $estabelecimento_id]);
                    $_SESSION['success'] = 'Conta atualizada com sucesso!';

                } else {
                    // ── Inserção: gera 1 ou N parcelas mensais ────────────
                    if ($recorrencia <= 1) {
                        // Lançamento único (sem recorrência)
                        $stmt = $conn->prepare("
                            INSERT INTO contas_pagar
                            (estabelecimento_id, descricao, tipo, valor, data_vencimento,
                             codigo_barras, link_pagamento, observacoes,
                             valor_protegido, origem,
                             recorrencia_total, recorrencia_parcela, recorrencia_grupo)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, 'manual', 1, 1, NULL)
                        ");
                        $stmt->execute([$estabelecimento_id, $descricao, $tipo, $valor,
                                        $data_vencimento, $codigo_barras, $link_pagamento, $observacoes]);
                        $_SESSION['success'] = 'Conta cadastrada com sucesso!';
                    } else {
                        // Recorrência: gera N parcelas mensais
                        $grupo_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0x0fff) | 0x4000,
                            mt_rand(0, 0x3fff) | 0x8000,
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                        );

                        $base_date = new DateTime($data_vencimento);

                        for ($i = 1; $i <= $recorrencia; $i++) {
                            // Descrição com sufixo de parcela: "ALUGUEL (1/12)"
                            $desc_parcela = $descricao . ' (' . $i . '/' . $recorrencia . ')';
                            $venc_parcela = clone $base_date;
                            if ($i > 1) {
                                $venc_parcela->modify('+' . ($i - 1) . ' month');
                            }

                            $stmt = $conn->prepare("
                                INSERT INTO contas_pagar
                                (estabelecimento_id, descricao, tipo, valor, data_vencimento,
                                 codigo_barras, link_pagamento, observacoes,
                                 valor_protegido, origem,
                                 recorrencia_total, recorrencia_parcela, recorrencia_grupo)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, 'manual', ?, ?, ?)
                            ");
                            $stmt->execute([
                                $estabelecimento_id,
                                $desc_parcela,
                                $tipo,
                                $valor,
                                $venc_parcela->format('Y-m-d'),
                                $codigo_barras,
                                $link_pagamento,
                                $observacoes,
                                $recorrencia,
                                $i,
                                $grupo_uuid
                            ]);
                        }
                        $_SESSION['success'] = $recorrencia . ' parcelas criadas com sucesso!';
                    }
                }

            } elseif ($action === 'delete') {
                $id                 = intval($_POST['id']);
                $estabelecimento_id = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
                $del_grupo          = isset($_POST['del_grupo']) && $_POST['del_grupo'] === '1';

                if ($del_grupo && !empty($_POST['recorrencia_grupo'])) {
                    // Excluir todas as parcelas pendentes do mesmo grupo
                    $grupo = sanitize($_POST['recorrencia_grupo']);
                    $stmt  = $conn->prepare("
                        DELETE FROM contas_pagar
                        WHERE recorrencia_grupo = ? AND estabelecimento_id = ? AND status = 'pendente'
                    ");
                    $stmt->execute([$grupo, $estabelecimento_id]);
                    $_SESSION['success'] = 'Parcelas pendentes do grupo excluídas!';
                } else {
                    $stmt = $conn->prepare("DELETE FROM contas_pagar WHERE id = ? AND estabelecimento_id = ?");
                    $stmt->execute([$id, $estabelecimento_id]);
                    $_SESSION['success'] = 'Conta excluída com sucesso!';
                }

            } elseif ($action === 'pagar') {
                $id                 = intval($_POST['id']);
                $estabelecimento_id = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
                $valor_pago         = numberToFloat($_POST['valor_pago']);
                $data_pagamento     = $_POST['data_pagamento'];

                $stmt = $conn->prepare("
                    UPDATE contas_pagar
                    SET status = 'pago', data_pagamento = ?, valor_pago = ?
                    WHERE id = ? AND estabelecimento_id = ?
                ");
                $stmt->execute([$data_pagamento, $valor_pago, $id, $estabelecimento_id]);
                $_SESSION['success'] = 'Conta marcada como paga!';
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Erro ao processar ação: ' . $e->getMessage();
    }
}

// ─── Filtros ─────────────────────────────────────────────────
$filtro_status = isset($_GET['status']) ? $_GET['status'] : 'pendente';
$filtro_mes    = isset($_GET['mes'])    ? $_GET['mes']    : date('Y-m');

// ─── Buscar contas ────────────────────────────────────────────
$base_select = "
    SELECT c.*, e.name AS estabelecimento_nome,
           DATEDIFF(c.data_vencimento, CURDATE()) AS dias_para_vencer
    FROM contas_pagar c
    INNER JOIN estabelecimentos e ON c.estabelecimento_id = e.id
    WHERE 1=1
";
$params = [];

if (!isAdminGeral()) {
    $base_select .= " AND c.estabelecimento_id = ?";
    $params[] = getEstabelecimentoId();
}
if ($filtro_status !== 'todos') {
    $base_select .= " AND c.status = ?";
    $params[] = $filtro_status;
}
if ($filtro_mes) {
    $base_select .= " AND DATE_FORMAT(c.data_vencimento, '%Y-%m') = ?";
    $params[] = $filtro_mes;
}
$base_select .= " ORDER BY c.data_vencimento ASC, c.created_at DESC";

$stmt   = $conn->prepare($base_select);
$stmt->execute($params);
$contas = $stmt->fetchAll();

// ─── Totais ───────────────────────────────────────────────────
$total_pendente = 0;
$total_pago     = 0;
$total_vencido  = 0;

foreach ($contas as $conta) {
    if ($conta['status'] === 'pendente') {
        $total_pendente += $conta['valor'];
        if ($conta['dias_para_vencer'] < 0) {
            $total_vencido += $conta['valor'];
        }
    } elseif ($conta['status'] === 'pago') {
        $total_pago += $conta['valor_pago'] ?? $conta['valor'];
    }
}

// ─── Estabelecimentos (admin) ─────────────────────────────────
if (isAdminGeral()) {
    $stmt_est       = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos = $stmt_est->fetchAll();
}

// ─── Tipos únicos para datalist ──────────────────────────────
$stmt_tipos  = $conn->query("SELECT DISTINCT tipo FROM contas_pagar ORDER BY tipo");
$tipos_conta = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN);

require_once '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Contas a Pagar</h1>
    </div>
    <button class="btn btn-primary" onclick="openModalConta()">
        ➕ Nova Conta
    </button>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Cards de resumo -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background-color: #ffc107;">⏳</div>
        <div class="stat-info">
            <div class="stat-label">Contas Pendentes</div>
            <div class="stat-number"><?php echo formatMoney($total_pendente); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background-color: #dc3545;">⚠️</div>
        <div class="stat-info">
            <div class="stat-label">Contas Vencidas</div>
            <div class="stat-number"><?php echo formatMoney($total_vencido); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background-color: #28a745;">✅</div>
        <div class="stat-info">
            <div class="stat-label">Contas Pagas</div>
            <div class="stat-number"><?php echo formatMoney($total_pago); ?></div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-item">
                    <label class="filter-label">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="todos"    <?php echo $filtro_status === 'todos'     ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendente" <?php echo $filtro_status === 'pendente'  ? 'selected' : ''; ?>>Pendente</option>
                        <option value="pago"     <?php echo $filtro_status === 'pago'      ? 'selected' : ''; ?>>Pago</option>
                        <option value="vencido"  <?php echo $filtro_status === 'vencido'   ? 'selected' : ''; ?>>Vencido</option>
                        <option value="cancelado"<?php echo $filtro_status === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label class="filter-label">Mês de Vencimento</label>
                    <input type="month" name="mes" class="form-control"
                           value="<?php echo $filtro_mes; ?>" onchange="this.form.submit()">
                </div>
                <div class="filter-item">
                    <label class="filter-label">&nbsp;</label>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">Limpar Filtros</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Tabela de contas -->
<div class="card">
    <div class="card-header">
        <h3>Contas Cadastradas</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <?php if (isAdminGeral()): ?><th>Estabelecimento</th><?php endif; ?>
                        <th>Descrição</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Vencimento</th>
                        <th>Parcela</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contas)): ?>
                        <tr>
                            <td colspan="<?php echo isAdminGeral() ? '8' : '7'; ?>" class="text-center">
                                Nenhuma conta encontrada
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contas as $conta): ?>
                            <?php
                            $status_class = 'secondary';
                            $status_label = $conta['status'];

                            if ($conta['status'] === 'pago') {
                                $status_class = 'success';
                                $status_label = '✅ Pago';
                            } elseif ($conta['status'] === 'pendente') {
                                if ($conta['dias_para_vencer'] < 0) {
                                    $status_class = 'danger';
                                    $status_label = '⚠️ Vencido';
                                } elseif ($conta['dias_para_vencer'] == 0) {
                                    $status_class = 'warning';
                                    $status_label = '⏰ Vence Hoje';
                                } elseif ($conta['dias_para_vencer'] <= 3) {
                                    $status_class = 'warning';
                                    $status_label = '⏳ ' . $conta['dias_para_vencer'] . ' dia(s)';
                                } else {
                                    $status_class = 'info';
                                    $status_label = '📅 Pendente';
                                }
                            } elseif ($conta['status'] === 'cancelado') {
                                $status_class = 'dark';
                                $status_label = '❌ Cancelado';
                            }

                            // Parcela info
                            $parcela_info = '';
                            if (!empty($conta['recorrencia_total']) && $conta['recorrencia_total'] > 1) {
                                $parcela_info = $conta['recorrencia_parcela'] . '/' . $conta['recorrencia_total'];
                            }
                            ?>
                            <tr>
                                <?php if (isAdminGeral()): ?>
                                <td><?php echo htmlspecialchars($conta['estabelecimento_nome']); ?></td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo htmlspecialchars($conta['descricao']); ?></strong>
                                    <?php if ($conta['observacoes']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($conta['observacoes']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($conta['tipo']); ?></td>
                                <td><?php echo formatMoney($conta['valor']); ?></td>
                                <td><?php echo formatDateBR($conta['data_vencimento']); ?></td>
                                <td>
                                    <?php if ($parcela_info): ?>
                                        <span class="badge badge-secondary"><?php echo $parcela_info; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $status_class; ?>">
                                        <?php echo $status_label; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($conta['status'] === 'pendente'): ?>
                                        <button class="btn btn-sm btn-success"
                                                onclick='pagarConta(<?php echo json_encode($conta); ?>)'
                                                title="Marcar como Pago">💰</button>
                                        <?php endif; ?>

                                        <?php if (!empty($conta['payment_link_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($conta['payment_link_url']); ?>"
                                           target="_blank" class="btn btn-sm btn-primary" title="Link de Pagamento">🔗</a>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-info"
                                                onclick='verDetalhes(<?php echo json_encode($conta); ?>)'
                                                title="Ver Detalhes">👁️</button>

                                        <?php if ($conta['status'] !== 'pago' && (isAdminGeral() || !$conta['valor_protegido'])): ?>
                                        <button class="btn btn-sm btn-warning"
                                                onclick='editConta(<?php echo json_encode($conta); ?>)'
                                                title="Editar">✏️</button>
                                        <?php elseif (!empty($conta['valor_protegido']) && !isAdminGeral()): ?>
                                        <button class="btn btn-sm btn-secondary" disabled title="Conta protegida">🔒</button>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-danger"
                                                onclick="deleteConta(<?php echo $conta['id']; ?>,
                                                         <?php echo $conta['estabelecimento_id']; ?>,
                                                         '<?php echo addslashes($conta['recorrencia_grupo'] ?? ''); ?>',
                                                         <?php echo intval($conta['recorrencia_total'] ?? 1); ?>)"
                                                title="Excluir">🗑️</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── Modal: Nova / Editar Conta ─────────────────────────── -->
<div id="contaModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="modalTitle">Nova Conta a Pagar</h2>
            <span class="close" onclick="closeModalConta()">&times;</span>
        </div>
        <form method="POST" id="contaForm">
            <input type="hidden" name="action"  id="formAction" value="add">
            <input type="hidden" name="id"      id="contaId">
            <?php if (!isAdminGeral()): ?>
            <input type="hidden" name="estabelecimento_id" value="<?php echo getEstabelecimentoId(); ?>">
            <?php endif; ?>

            <div class="modal-body">
                <?php if (isAdminGeral()): ?>
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" id="estabelecimento_id" class="form-control" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $est): ?>
                            <option value="<?php echo $est['id']; ?>"><?php echo htmlspecialchars($est['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Descrição *</label>
                    <input type="text" name="descricao" id="descricao" class="form-control" required
                           placeholder="Ex: Conta de Luz - Novembro/2025">
                </div>

                <div class="form-group">
                    <label>Tipo *</label>
                    <input type="text" name="tipo" id="tipo" class="form-control" list="tiposList" required
                           placeholder="Ex: Água, Luz, Aluguel...">
                    <datalist id="tiposList">
                        <option value="Água">
                        <option value="Luz">
                        <option value="Aluguel">
                        <option value="Internet">
                        <option value="Telefone">
                        <option value="Fornecedor">
                        <option value="Impostos">
                        <option value="Salários">
                        <option value="Manutenção">
                        <?php foreach ($tipos_conta as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Valor (R$) *</label>
                        <input type="text" name="valor" id="valor" class="form-control" required
                               placeholder="Ex: 350,00">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Data de Vencimento *</label>
                        <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                    </div>
                </div>

                <!-- Campo de Recorrência (só aparece no modo "add") -->
                <div class="form-group" id="recorrenciaGroup">
                    <label>Recorrência (parcelas mensais)</label>
                    <select name="recorrencia" id="recorrencia" class="form-control"
                            onchange="atualizarAvisoRecorrencia()">
                        <option value="1">Sem recorrência (lançamento único)</option>
                        <?php for ($n = 2; $n <= 24; $n++): ?>
                            <option value="<?php echo $n; ?>"><?php echo $n; ?>x — <?php echo $n; ?> parcelas mensais</option>
                        <?php endfor; ?>
                    </select>
                    <div id="avisoRecorrencia" style="display:none; margin-top:6px;
                         padding:8px 12px; background:#fff3cd; border-left:4px solid #ffc107;
                         border-radius:4px; font-size:13px; color:#856404;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Código de Barras</label>
                    <textarea name="codigo_barras" id="codigo_barras" class="form-control" rows="2"
                              placeholder="Cole aqui o código de barras (se houver)"></textarea>
                </div>

                <div class="form-group">
                    <label>Link de Pagamento</label>
                    <input type="url" name="link_pagamento" id="link_pagamento" class="form-control"
                           placeholder="https://...">
                </div>

                <div class="form-group">
                    <label>Observações</label>
                    <textarea name="observacoes" id="observacoes" class="form-control" rows="2"
                              placeholder="Observações adicionais..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModalConta()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnSalvar">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal: Marcar como Pago ────────────────────────────── -->
<div id="pagarModal" class="modal">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <h2>Marcar Conta como Paga</h2>
            <span class="close" onclick="closePagarModal()">&times;</span>
        </div>
        <form method="POST" id="pagarForm">
            <input type="hidden" name="action" value="pagar">
            <input type="hidden" name="id" id="pagarId">
            <input type="hidden" name="estabelecimento_id" id="pagarEstabelecimentoId">
            <div class="modal-body">
                <p id="pagarDescricao"></p>
                <div class="form-group">
                    <label>Valor Pago (R$) *</label>
                    <input type="text" name="valor_pago" id="valor_pago" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Data do Pagamento *</label>
                    <input type="date" name="data_pagamento" id="data_pagamento" class="form-control" required
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePagarModal()">Cancelar</button>
                <button type="submit" class="btn btn-success">Confirmar Pagamento</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal: Detalhes ─────────────────────────────────────── -->
<div id="detalhesModal" class="modal">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Detalhes da Conta</h2>
            <span class="close" onclick="closeDetalhesModal()">&times;</span>
        </div>
        <div class="modal-body" id="detalhesContent"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDetalhesModal()">Fechar</button>
        </div>
    </div>
</div>

<style>
.stat-info  { flex: 1; }
.stat-label { font-size: 14px; color: #666; margin-bottom: 5px; }
.stat-value { font-size: 22px; font-weight: bold; color: #333; }
.badge-dark { background-color: #343a40; color: #fff; }
.btn-group  { display: flex; gap: 4px; flex-wrap: wrap; }
</style>

<script>
// ── Abrir modal de nova conta ──────────────────────────────────
function openModalConta() {
    document.getElementById('modalTitle').textContent  = 'Nova Conta a Pagar';
    document.getElementById('formAction').value        = 'add';
    document.getElementById('contaForm').reset();
    document.getElementById('contaId').value           = '';
    document.getElementById('recorrenciaGroup').style.display = '';
    document.getElementById('btnSalvar').textContent   = 'Salvar';
    document.getElementById('avisoRecorrencia').style.display = 'none';
    openModal('contaModal');
}

function closeModalConta() { closeModal('contaModal'); }

// ── Editar conta ───────────────────────────────────────────────
function editConta(conta) {
    document.getElementById('modalTitle').textContent  = 'Editar Conta a Pagar';
    document.getElementById('formAction').value        = 'edit';
    document.getElementById('contaId').value           = conta.id;

    <?php if (isAdminGeral()): ?>
    document.getElementById('estabelecimento_id').value = conta.estabelecimento_id;
    <?php endif; ?>

    document.getElementById('descricao').value         = conta.descricao;
    document.getElementById('tipo').value              = conta.tipo;
    document.getElementById('valor').value             = parseFloat(conta.valor).toFixed(2).replace('.', ',');
    document.getElementById('data_vencimento').value   = conta.data_vencimento;
    document.getElementById('codigo_barras').value     = conta.codigo_barras || '';
    document.getElementById('link_pagamento').value    = conta.payment_link_url || conta.link_pagamento || '';
    document.getElementById('observacoes').value       = conta.observacoes || '';

    // Ocultar campo recorrência na edição
    document.getElementById('recorrenciaGroup').style.display = 'none';
    document.getElementById('btnSalvar').textContent   = 'Atualizar';

    <?php if (!isAdminGeral()): ?>
    if (conta.valor_protegido) {
        document.getElementById('valor').readOnly = true;
        document.getElementById('valor').style.backgroundColor = '#e9ecef';
    } else {
        document.getElementById('valor').readOnly = false;
        document.getElementById('valor').style.backgroundColor = '';
    }
    <?php endif; ?>

    openModal('contaModal');
}

// ── Aviso de recorrência ──────────────────────────────────────
function atualizarAvisoRecorrencia() {
    const n     = parseInt(document.getElementById('recorrencia').value);
    const aviso = document.getElementById('avisoRecorrencia');
    const btn   = document.getElementById('btnSalvar');

    if (n > 1) {
        const valorRaw = document.getElementById('valor').value.replace(',', '.');
        const valor    = parseFloat(valorRaw) || 0;
        const total    = (valor * n).toFixed(2).replace('.', ',');
        aviso.innerHTML = `Serão criadas <strong>${n} parcelas mensais</strong> a partir da data de vencimento informada.<br>
                           Valor por parcela: <strong>R$ ${valor.toFixed(2).replace('.', ',')}</strong> &nbsp;|&nbsp;
                           Total: <strong>R$ ${total}</strong>`;
        aviso.style.display = '';
        btn.textContent = 'Criar ' + n + ' Parcelas';
    } else {
        aviso.style.display = 'none';
        btn.textContent = 'Salvar';
    }
}

// Atualizar aviso ao mudar o valor também
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('valor').addEventListener('input', function () {
        const n = parseInt(document.getElementById('recorrencia').value);
        if (n > 1) atualizarAvisoRecorrencia();
    });
});

// ── Excluir conta ─────────────────────────────────────────────
function deleteConta(id, estabelecimentoId, grupo, recorrenciaTotal) {
    let msg = 'Tem certeza que deseja excluir esta conta?';
    let delGrupo = '0';

    if (grupo && recorrenciaTotal > 1) {
        const opcao = confirm(
            'Esta conta faz parte de uma recorrência de ' + recorrenciaTotal + ' parcelas.\n\n' +
            'Clique OK para excluir TODAS as parcelas pendentes deste grupo.\n' +
            'Clique Cancelar para excluir SOMENTE esta parcela.'
        );
        if (opcao === null) return; // usuário fechou o dialog
        delGrupo = opcao ? '1' : '0';
    } else {
        if (!confirm(msg)) return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="${id}">
        <input type="hidden" name="estabelecimento_id" value="${estabelecimentoId}">
        <input type="hidden" name="del_grupo" value="${delGrupo}">
        <input type="hidden" name="recorrencia_grupo" value="${grupo}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ── Pagar conta ───────────────────────────────────────────────
function pagarConta(conta) {
    openModal('pagarModal');
    document.getElementById('pagarId').value               = conta.id;
    document.getElementById('pagarEstabelecimentoId').value = conta.estabelecimento_id;
    document.getElementById('pagarDescricao').innerHTML    = `
        <strong>Descrição:</strong> ${conta.descricao}<br>
        <strong>Tipo:</strong> ${conta.tipo}<br>
        <strong>Valor:</strong> R$ ${parseFloat(conta.valor).toFixed(2).replace('.', ',')}
    `;
    document.getElementById('valor_pago').value = parseFloat(conta.valor).toFixed(2).replace('.', ',');
}
function closePagarModal() { closeModal('pagarModal'); }

// ── Ver detalhes ──────────────────────────────────────────────
function verDetalhes(conta) {
    let parcela = '';
    if (conta.recorrencia_total > 1) {
        parcela = `<p><strong>Recorrência:</strong> Parcela ${conta.recorrencia_parcela} de ${conta.recorrencia_total}</p>`;
    }

    let html = `<div style="line-height:1.8;">
        <p><strong>Descrição:</strong> ${conta.descricao}</p>
        <p><strong>Tipo:</strong> ${conta.tipo}</p>
        <p><strong>Valor:</strong> R$ ${parseFloat(conta.valor).toFixed(2).replace('.', ',')}</p>
        <p><strong>Data de Vencimento:</strong> ${formatDateBR(conta.data_vencimento)}</p>
        <p><strong>Status:</strong> ${conta.status}</p>
        ${parcela}
    `;

    if (conta.codigo_barras) {
        html += `<p><strong>Código de Barras:</strong><br>
                 <code style="background:#f4f4f4;padding:5px;display:block;margin-top:5px;word-break:break-all;">
                 ${conta.codigo_barras}</code></p>`;
    }
    if (conta.link_pagamento || conta.payment_link_url) {
        const link = conta.payment_link_url || conta.link_pagamento;
        html += `<p><strong>Link de Pagamento:</strong><br>
                 <a href="${link}" target="_blank">${link}</a></p>`;
    }
    if (conta.observacoes) {
        html += `<p><strong>Observações:</strong><br>${conta.observacoes}</p>`;
    }
    if (conta.data_pagamento) {
        html += `<p><strong>Data de Pagamento:</strong> ${formatDateBR(conta.data_pagamento)}</p>`;
        html += `<p><strong>Valor Pago:</strong> R$ ${parseFloat(conta.valor_pago).toFixed(2).replace('.', ',')}</p>`;
    }
    html += '</div>';

    document.getElementById('detalhesContent').innerHTML = html;
    openModal('detalhesModal');
}
function closeDetalhesModal() { closeModal('detalhesModal'); }

function formatDateBR(date) {
    if (!date) return '';
    const p = date.split('-');
    return `${p[2]}/${p[1]}/${p[0]}`;
}

// Fechar modais ao clicar fora
window.onclick = function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
};
</script>

<?php require_once '../includes/footer.php'; ?>
