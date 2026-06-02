<?php
$page_title    = 'Financeiro - Contas Bancárias';
$current_page  = 'financeiro_contas_bancarias';
require_once '../includes/config.php';
require_once '../includes/auth.php';
$conn = getDBConnection();

// ─── Helpers ─────────────────────────────────────────────────
$estab_id = isAdminGeral() ? null : getEstabelecimentoId();

// ─── Processar ações POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        // ── CRUD de Contas Bancárias ───────────────────────────
        if ($action === 'add_conta' || $action === 'edit_conta') {
            $eid         = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
            $nome        = sanitize($_POST['nome']);
            $banco       = !empty($_POST['banco'])   ? sanitize($_POST['banco'])   : null;
            $agencia     = !empty($_POST['agencia']) ? sanitize($_POST['agencia']) : null;
            $conta_num   = !empty($_POST['conta'])   ? sanitize($_POST['conta'])   : null;
            $tipo        = sanitize($_POST['tipo_conta']);
            $saldo_ini   = numberToFloat($_POST['saldo_inicial'] ?? '0');
            $obs         = !empty($_POST['observacoes']) ? sanitize($_POST['observacoes']) : null;
            $ativa       = isset($_POST['ativa']) ? 1 : 0;

            if ($action === 'edit_conta') {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("
                    UPDATE contas_bancarias
                    SET nome=?, banco=?, agencia=?, conta=?, tipo=?, ativa=?, observacoes=?
                    WHERE id=? AND estabelecimento_id=?
                ");
                $stmt->execute([$nome, $banco, $agencia, $conta_num, $tipo, $ativa, $obs, $id, $eid]);
                $_SESSION['success'] = 'Conta atualizada com sucesso!';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO contas_bancarias
                    (estabelecimento_id, nome, banco, agencia, conta, tipo, saldo_inicial, saldo_atual, ativa, observacoes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$eid, $nome, $banco, $agencia, $conta_num, $tipo, $saldo_ini, $saldo_ini, $ativa, $obs]);
                $_SESSION['success'] = 'Conta cadastrada com sucesso!';
            }

        } elseif ($action === 'delete_conta') {
            $id  = intval($_POST['id']);
            $eid = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
            // Verificar se tem movimentações
            $chk = $conn->prepare("SELECT COUNT(*) FROM movimentacoes_bancarias WHERE conta_bancaria_id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $_SESSION['error'] = 'Não é possível excluir uma conta com movimentações registradas.';
            } else {
                $stmt = $conn->prepare("DELETE FROM contas_bancarias WHERE id=? AND estabelecimento_id=?");
                $stmt->execute([$id, $eid]);
                $_SESSION['success'] = 'Conta excluída com sucesso!';
            }

        // ── CRUD de Movimentações ──────────────────────────────
        } elseif ($action === 'add_mov' || $action === 'edit_mov') {
            $eid          = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
            $conta_id     = intval($_POST['conta_bancaria_id']);
            $tipo_mov     = sanitize($_POST['tipo_mov']);
            $descricao    = sanitize($_POST['descricao']);
            $valor        = numberToFloat($_POST['valor']);
            $data_mov     = $_POST['data_movimentacao'];
            $categoria    = !empty($_POST['categoria'])     ? sanitize($_POST['categoria'])     : null;
            $centro_custo = !empty($_POST['centro_custo'])  ? sanitize($_POST['centro_custo'])  : null;
            $classif      = !empty($_POST['classificacao']) ? sanitize($_POST['classificacao']) : null;
            $dest_id      = !empty($_POST['conta_destino_id']) ? intval($_POST['conta_destino_id']) : null;
            $obs          = !empty($_POST['observacoes'])   ? sanitize($_POST['observacoes'])   : null;
            $user_id      = $_SESSION['user_id'] ?? null;

            if ($action === 'edit_mov') {
                $id = intval($_POST['id']);
                // Reverter saldo anterior
                $old = $conn->prepare("SELECT tipo, valor, conta_bancaria_id FROM movimentacoes_bancarias WHERE id=?");
                $old->execute([$id]);
                $old_mov = $old->fetch();
                if ($old_mov) {
                    $delta_old = ($old_mov['tipo'] === 'entrada') ? -$old_mov['valor'] : $old_mov['valor'];
                    $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id=?")
                         ->execute([$delta_old, $old_mov['conta_bancaria_id']]);
                }
                $stmt = $conn->prepare("
                    UPDATE movimentacoes_bancarias
                    SET conta_bancaria_id=?, tipo=?, descricao=?, valor=?, data_movimentacao=?,
                        categoria=?, centro_custo=?, classificacao=?, conta_destino_id=?, observacoes=?
                    WHERE id=? AND estabelecimento_id=?
                ");
                $stmt->execute([$conta_id, $tipo_mov, $descricao, $valor, $data_mov,
                                $categoria, $centro_custo, $classif, $dest_id, $obs,
                                $id, $eid]);
                $_SESSION['success'] = 'Movimentação atualizada!';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO movimentacoes_bancarias
                    (conta_bancaria_id, estabelecimento_id, tipo, descricao, valor, data_movimentacao,
                     categoria, centro_custo, classificacao, conta_destino_id, observacoes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$conta_id, $eid, $tipo_mov, $descricao, $valor, $data_mov,
                                $categoria, $centro_custo, $classif, $dest_id, $obs, $user_id]);
                $_SESSION['success'] = 'Movimentação registrada!';
            }
            // Atualizar saldo da conta
            $delta = ($tipo_mov === 'entrada') ? $valor : -$valor;
            $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id=?")
                 ->execute([$delta, $conta_id]);
            // Se transferência, debitar da origem e creditar no destino
            if ($tipo_mov === 'transferencia' && $dest_id) {
                $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id=?")
                     ->execute([$valor, $dest_id]);
            }

        } elseif ($action === 'delete_mov') {
            $id  = intval($_POST['id']);
            $eid = isAdminGeral() ? intval($_POST['estabelecimento_id']) : getEstabelecimentoId();
            $mov = $conn->prepare("SELECT tipo, valor, conta_bancaria_id FROM movimentacoes_bancarias WHERE id=? AND estabelecimento_id=?");
            $mov->execute([$id, $eid]);
            $m = $mov->fetch();
            if ($m) {
                $delta = ($m['tipo'] === 'entrada') ? -$m['valor'] : $m['valor'];
                $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id=?")
                     ->execute([$delta, $m['conta_bancaria_id']]);
                $conn->prepare("DELETE FROM movimentacoes_bancarias WHERE id=? AND estabelecimento_id=?")
                     ->execute([$id, $eid]);
                $_SESSION['success'] = 'Movimentação excluída!';
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['conta']) ? '?conta=' . intval($_GET['conta']) : ''));
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Erro: ' . $e->getMessage();
    }
}

// ─── Buscar contas bancárias ──────────────────────────────────
$sql_contas = "SELECT cb.*, e.name AS estab_nome
               FROM contas_bancarias cb
               INNER JOIN estabelecimentos e ON cb.estabelecimento_id = e.id
               WHERE 1=1";
$p_contas = [];
if (!isAdminGeral()) {
    $sql_contas .= " AND cb.estabelecimento_id = ?";
    $p_contas[] = $estab_id;
}
$sql_contas .= " ORDER BY e.name, cb.nome";
$stmt_c = $conn->prepare($sql_contas);
$stmt_c->execute($p_contas);
$contas_bancarias = $stmt_c->fetchAll();

// ─── Conta selecionada para ver movimentações ─────────────────
$conta_sel_id  = isset($_GET['conta']) ? intval($_GET['conta']) : null;
$conta_sel     = null;
$movimentacoes = [];
$saldo_periodo = 0;

if ($conta_sel_id) {
    $stmt_cs = $conn->prepare("SELECT cb.*, e.name AS estab_nome FROM contas_bancarias cb
                                INNER JOIN estabelecimentos e ON cb.estabelecimento_id = e.id
                                WHERE cb.id = ?" . (!isAdminGeral() ? " AND cb.estabelecimento_id = ?" : ""));
    $p_cs = [$conta_sel_id];
    if (!isAdminGeral()) $p_cs[] = $estab_id;
    $stmt_cs->execute($p_cs);
    $conta_sel = $stmt_cs->fetch();

    if ($conta_sel) {
        $filtro_mes = isset($_GET['mes']) ? $_GET['mes'] : date('Y-m');
        $sql_mov = "SELECT m.*, cb.nome AS conta_nome
                    FROM movimentacoes_bancarias m
                    INNER JOIN contas_bancarias cb ON m.conta_bancaria_id = cb.id
                    WHERE m.conta_bancaria_id = ?
                    AND DATE_FORMAT(m.data_movimentacao, '%Y-%m') = ?
                    ORDER BY m.data_movimentacao DESC, m.created_at DESC";
        $stmt_m = $conn->prepare($sql_mov);
        $stmt_m->execute([$conta_sel_id, $filtro_mes]);
        $movimentacoes = $stmt_m->fetchAll();

        foreach ($movimentacoes as $mov) {
            $saldo_periodo += ($mov['tipo'] === 'entrada') ? $mov['valor'] : -$mov['valor'];
        }
    }
}

// ─── Buscar estabelecimentos para selects ─────────────────────
$stmt_est = $conn->prepare("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
$stmt_est->execute();
$estabelecimentos = $stmt_est->fetchAll();

// ─── Totais gerais ────────────────────────────────────────────
$total_saldo = array_sum(array_column($contas_bancarias, 'saldo_atual'));

require_once '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-university"></i> Contas Bancárias</h1>
        <p class="page-subtitle">Gestão de contas, saldos e movimentações financeiras</p>
    </div>
    <button class="btn btn-primary" onclick="openModalConta()">
        <i class="fas fa-plus"></i> Nova Conta
    </button>
</div>

<!-- Tabs de navegação -->
<div class="tabs-navigation">
    <a href="financeiro_taxas.php"          class="tab-link">📊 Taxas de Juros</a>
    <a href="financeiro_contas.php"         class="tab-link">📋 Contas a Pagar</a>
    <a href="financeiro_contas_bancarias.php" class="tab-link active">🏦 Contas Bancárias</a>
    <a href="financeiro_royalties.php"      class="tab-link">👑 Royalties</a>
    <a href="financeiro_faturamento.php"    class="tab-link">🧾 Faturamento</a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- ─── Cards de resumo ──────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;"><i class="fas fa-university" style="color:#1976d2;"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo count($contas_bancarias); ?></div>
            <div class="stat-label">Contas Cadastradas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;"><i class="fas fa-dollar-sign" style="color:#388e3c;"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo formatMoney($total_saldo); ?></div>
            <div class="stat-label">Saldo Total</div>
        </div>
    </div>
    <?php
    $contas_ativas   = count(array_filter($contas_bancarias, fn($c) => $c['ativa'] == 1));
    $contas_inativas = count($contas_bancarias) - $contas_ativas;
    ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f3e5f5;"><i class="fas fa-check-circle" style="color:#7b1fa2;"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo $contas_ativas; ?></div>
            <div class="stat-label">Contas Ativas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff3e0;"><i class="fas fa-pause-circle" style="color:#f57c00;"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo $contas_inativas; ?></div>
            <div class="stat-label">Contas Inativas</div>
        </div>
    </div>
</div>

<!-- ─── Lista de contas ──────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-list"></i> Contas Cadastradas</h3>
        <span class="badge badge-primary"><?php echo count($contas_bancarias); ?> conta(s)</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($contas_bancarias)): ?>
            <div style="padding:40px; text-align:center; color:#999;">
                <i class="fas fa-university" style="font-size:48px; margin-bottom:12px; display:block;"></i>
                Nenhuma conta cadastrada. Clique em <strong>+ Nova Conta</strong> para começar.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Conta</th>
                        <th>Banco / Tipo</th>
                        <?php if (isAdminGeral()): ?><th>Estabelecimento</th><?php endif; ?>
                        <th>Agência / Nº</th>
                        <th style="text-align:right;">Saldo Atual</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas_bancarias as $cb): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($cb['nome']); ?></strong>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($cb['banco'] ?? '—'); ?>
                            <br><small class="text-muted"><?php echo ucfirst($cb['tipo']); ?></small>
                        </td>
                        <?php if (isAdminGeral()): ?>
                        <td><small><?php echo htmlspecialchars($cb['estab_nome']); ?></small></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($cb['agencia']): ?>
                                Ag: <?php echo htmlspecialchars($cb['agencia']); ?><br>
                            <?php endif; ?>
                            <?php if ($cb['conta']): ?>
                                CC: <?php echo htmlspecialchars($cb['conta']); ?>
                            <?php endif; ?>
                            <?php if (!$cb['agencia'] && !$cb['conta']): ?>—<?php endif; ?>
                        </td>
                        <td style="text-align:right; font-weight:bold;
                            color:<?php echo $cb['saldo_atual'] >= 0 ? '#388e3c' : '#c62828'; ?>;">
                            <?php echo formatMoney($cb['saldo_atual']); ?>
                        </td>
                        <td>
                            <?php if ($cb['ativa']): ?>
                                <span class="badge badge-success">Ativa</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="?conta=<?php echo $cb['id']; ?>" class="btn btn-sm btn-info"
                                   title="Ver Movimentações">
                                    <i class="fas fa-exchange-alt"></i>
                                </a>
                                <button class="btn btn-sm btn-warning"
                                        onclick='editConta(<?php echo json_encode($cb); ?>)'
                                        title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Excluir esta conta? Só é possível se não houver movimentações.');">
                                    <input type="hidden" name="action" value="delete_conta">
                                    <input type="hidden" name="id" value="<?php echo $cb['id']; ?>">
                                    <input type="hidden" name="estabelecimento_id" value="<?php echo $cb['estabelecimento_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Movimentações da conta selecionada ───────────────────── -->
<?php if ($conta_sel): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <h3><i class="fas fa-exchange-alt"></i>
            Movimentações — <?php echo htmlspecialchars($conta_sel['nome']); ?>
            <small style="font-weight:normal; color:#666;">
                (Saldo: <strong style="color:<?php echo $conta_sel['saldo_atual'] >= 0 ? '#388e3c' : '#c62828'; ?>;">
                    <?php echo formatMoney($conta_sel['saldo_atual']); ?>
                </strong>)
            </small>
        </h3>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="conta" value="<?php echo $conta_sel_id; ?>">
                <input type="month" name="mes" class="form-control" style="width:160px;"
                       value="<?php echo $_GET['mes'] ?? date('Y-m'); ?>" onchange="this.form.submit()">
            </form>
            <button class="btn btn-primary" onclick="openModalMov()">
                <i class="fas fa-plus"></i> Nova Movimentação
            </button>
        </div>
    </div>

    <!-- Resumo do período -->
    <?php
    $entradas = array_sum(array_map(fn($m) => $m['tipo'] === 'entrada' ? $m['valor'] : 0, $movimentacoes));
    $saidas   = array_sum(array_map(fn($m) => $m['tipo'] !== 'entrada' ? $m['valor'] : 0, $movimentacoes));
    ?>
    <div style="display:flex; gap:16px; padding:16px 20px; background:#f8f9fa; border-bottom:1px solid #e9ecef; flex-wrap:wrap;">
        <div style="flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Entradas no período</div>
            <div style="font-size:20px; font-weight:bold; color:#388e3c;"><?php echo formatMoney($entradas); ?></div>
        </div>
        <div style="flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Saídas no período</div>
            <div style="font-size:20px; font-weight:bold; color:#c62828;"><?php echo formatMoney($saidas); ?></div>
        </div>
        <div style="flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Resultado do período</div>
            <div style="font-size:20px; font-weight:bold; color:<?php echo $saldo_periodo >= 0 ? '#388e3c' : '#c62828'; ?>;">
                <?php echo formatMoney($saldo_periodo); ?>
            </div>
        </div>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($movimentacoes)): ?>
            <div style="padding:40px; text-align:center; color:#999;">
                Nenhuma movimentação neste período.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Centro de Custo</th>
                        <th>Classificação</th>
                        <th style="text-align:right;">Valor</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentacoes as $mov): ?>
                    <tr>
                        <td><?php echo formatDateBR($mov['data_movimentacao']); ?></td>
                        <td>
                            <?php if ($mov['tipo'] === 'entrada'): ?>
                                <span class="badge badge-success"><i class="fas fa-arrow-up"></i> Entrada</span>
                            <?php elseif ($mov['tipo'] === 'saida'): ?>
                                <span class="badge badge-danger"><i class="fas fa-arrow-down"></i> Saída</span>
                            <?php else: ?>
                                <span class="badge badge-info"><i class="fas fa-exchange-alt"></i> Transferência</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($mov['descricao']); ?></td>
                        <td><small><?php echo htmlspecialchars($mov['categoria'] ?? '—'); ?></small></td>
                        <td><small><?php echo htmlspecialchars($mov['centro_custo'] ?? '—'); ?></small></td>
                        <td><small><?php echo htmlspecialchars($mov['classificacao'] ?? '—'); ?></small></td>
                        <td style="text-align:right; font-weight:bold;
                            color:<?php echo $mov['tipo'] === 'entrada' ? '#388e3c' : '#c62828'; ?>;">
                            <?php echo ($mov['tipo'] === 'entrada' ? '+' : '-') . formatMoney($mov['valor']); ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-warning"
                                        onclick='editMov(<?php echo json_encode($mov); ?>)'
                                        title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Excluir esta movimentação? O saldo da conta será revertido.');">
                                    <input type="hidden" name="action" value="delete_mov">
                                    <input type="hidden" name="id" value="<?php echo $mov['id']; ?>">
                                    <input type="hidden" name="estabelecimento_id" value="<?php echo $conta_sel['estabelecimento_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Nova / Editar Conta Bancária
════════════════════════════════════════════════════════════ -->
<div id="modalConta" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-box-header">
            <h3 id="modalContaTitulo"><i class="fas fa-university"></i> Nova Conta Bancária</h3>
            <button class="btn-close-modal" onclick="closeModalConta()">×</button>
        </div>
        <form method="POST" id="contaForm">
            <input type="hidden" name="action" id="contaAction" value="add_conta">
            <input type="hidden" name="id"     id="contaId">
            <?php if (!isAdminGeral()): ?>
            <input type="hidden" name="estabelecimento_id" value="<?php echo getEstabelecimentoId(); ?>">
            <?php endif; ?>
            <div class="modal-box-body">
                <?php if (isAdminGeral()): ?>
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" id="contaEstab" class="form-control" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Nome / Apelido da Conta *</label>
                    <input type="text" name="nome" id="contaNome" class="form-control" required
                           placeholder="Ex: Caixa, Bradesco PJ, Nubank Corrente">
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Banco</label>
                        <input type="text" name="banco" id="contaBanco" class="form-control" list="bancosList"
                               placeholder="Ex: Bradesco, Nubank...">
                        <datalist id="bancosList">
                            <option value="Banco do Brasil">
                            <option value="Bradesco">
                            <option value="Caixa Econômica Federal">
                            <option value="Itaú">
                            <option value="Santander">
                            <option value="Nubank">
                            <option value="Inter">
                            <option value="Sicoob">
                            <option value="Sicredi">
                            <option value="C6 Bank">
                            <option value="PicPay">
                        </datalist>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Tipo *</label>
                        <select name="tipo_conta" id="contaTipo" class="form-control" required>
                            <option value="corrente">Conta Corrente</option>
                            <option value="poupanca">Poupança</option>
                            <option value="caixa">Caixa (dinheiro físico)</option>
                            <option value="pix">PIX / Carteira Digital</option>
                            <option value="investimento">Investimento</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Agência</label>
                        <input type="text" name="agencia" id="contaAgencia" class="form-control"
                               placeholder="Ex: 1234-5">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Número da Conta</label>
                        <input type="text" name="conta" id="contaNumero" class="form-control"
                               placeholder="Ex: 00012345-6">
                    </div>
                </div>
                <div id="saldoInicialGroup" class="form-group">
                    <label>Saldo Inicial (R$)</label>
                    <input type="text" name="saldo_inicial" id="contaSaldoInicial" class="form-control"
                           placeholder="0,00" value="0,00">
                    <small class="text-muted">Informe o saldo atual da conta ao cadastrá-la.</small>
                </div>
                <div class="form-group">
                    <label>Observações</label>
                    <textarea name="observacoes" id="contaObs" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="ativa" id="contaAtiva" value="1" checked>
                        Conta ativa
                    </label>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModalConta()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnSalvarConta">Salvar Conta</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Nova / Editar Movimentação
════════════════════════════════════════════════════════════ -->
<div id="modalMov" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:620px;">
        <div class="modal-box-header">
            <h3 id="modalMovTitulo"><i class="fas fa-exchange-alt"></i> Nova Movimentação</h3>
            <button class="btn-close-modal" onclick="closeModalMov()">×</button>
        </div>
        <form method="POST" id="movForm">
            <input type="hidden" name="action" id="movAction" value="add_mov">
            <input type="hidden" name="id"     id="movId">
            <?php if (!isAdminGeral()): ?>
            <input type="hidden" name="estabelecimento_id" value="<?php echo getEstabelecimentoId(); ?>">
            <?php endif; ?>
            <?php if ($conta_sel_id): ?>
            <input type="hidden" name="conta_bancaria_id" value="<?php echo $conta_sel_id; ?>">
            <?php endif; ?>
            <div class="modal-box-body">
                <?php if (isAdminGeral()): ?>
                <div class="form-group">
                    <label>Estabelecimento *</label>
                    <select name="estabelecimento_id" id="movEstab" class="form-control" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($estabelecimentos as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!$conta_sel_id): ?>
                <div class="form-group">
                    <label>Conta Bancária *</label>
                    <select name="conta_bancaria_id" id="movContaId" class="form-control" required>
                        <option value="">Selecione a conta...</option>
                        <?php foreach ($contas_bancarias as $cb): ?>
                            <option value="<?php echo $cb['id']; ?>">
                                <?php echo htmlspecialchars($cb['nome']); ?>
                                (<?php echo formatMoney($cb['saldo_atual']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Tipo *</label>
                        <select name="tipo_mov" id="movTipo" class="form-control" required onchange="toggleTransferencia()">
                            <option value="entrada">↑ Entrada</option>
                            <option value="saida">↓ Saída</option>
                            <option value="transferencia">⇄ Transferência</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Data *</label>
                        <input type="date" name="data_movimentacao" id="movData" class="form-control" required
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Descrição *</label>
                    <input type="text" name="descricao" id="movDescricao" class="form-control" required
                           placeholder="Ex: Pagamento fornecedor, Receita de vendas...">
                </div>
                <div class="form-group">
                    <label>Valor (R$) *</label>
                    <input type="text" name="valor" id="movValor" class="form-control" required
                           placeholder="Ex: 1.500,00">
                </div>
                <!-- Conta destino (apenas para transferência) -->
                <div class="form-group" id="contaDestinoGroup" style="display:none;">
                    <label>Conta Destino *</label>
                    <select name="conta_destino_id" id="movContaDestino" class="form-control">
                        <option value="">Selecione a conta destino...</option>
                        <?php foreach ($contas_bancarias as $cb): ?>
                            <?php if ($cb['id'] != $conta_sel_id): ?>
                            <option value="<?php echo $cb['id']; ?>">
                                <?php echo htmlspecialchars($cb['nome']); ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Campos de classificação -->
                <div style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Categoria</label>
                        <input type="text" name="categoria" id="movCategoria" class="form-control"
                               list="categoriasList" placeholder="Ex: Receita Operacional">
                        <datalist id="categoriasList">
                            <option value="Receita Operacional">
                            <option value="Receita Financeira">
                            <option value="Despesa Fixa">
                            <option value="Despesa Variável">
                            <option value="Investimento">
                            <option value="Retirada de Sócio">
                            <option value="Transferência Interna">
                        </datalist>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Centro de Custo</label>
                        <input type="text" name="centro_custo" id="movCentroCusto" class="form-control"
                               list="centroCustoList" placeholder="Ex: Operacional">
                        <datalist id="centroCustoList">
                            <option value="Operacional">
                            <option value="Administrativo">
                            <option value="Marketing">
                            <option value="Comercial">
                            <option value="Financeiro">
                            <option value="RH">
                            <option value="TI">
                        </datalist>
                    </div>
                </div>
                <div class="form-group">
                    <label>Classificação Financeira</label>
                    <select name="classificacao" id="movClassificacao" class="form-control">
                        <option value="">Selecione...</option>
                        <option value="Receita Bruta">Receita Bruta</option>
                        <option value="Receita Líquida">Receita Líquida</option>
                        <option value="Despesa Fixa">Despesa Fixa</option>
                        <option value="Despesa Variável">Despesa Variável</option>
                        <option value="Custo Operacional">Custo Operacional</option>
                        <option value="Investimento">Investimento</option>
                        <option value="Imposto">Imposto / Tributo</option>
                        <option value="Retirada">Retirada de Sócio / Pro-labore</option>
                        <option value="Transferência">Transferência Interna</option>
                        <option value="Outro">Outro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Observações</label>
                    <textarea name="observacoes" id="movObs" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModalMov()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnSalvarMov">Registrar</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal Conta Bancária ──────────────────────────────────────
function openModalConta() {
    document.getElementById('modalContaTitulo').innerHTML = '<i class="fas fa-university"></i> Nova Conta Bancária';
    document.getElementById('contaAction').value = 'add_conta';
    document.getElementById('contaId').value     = '';
    document.getElementById('contaForm').reset();
    document.getElementById('saldoInicialGroup').style.display = '';
    document.getElementById('btnSalvarConta').textContent = 'Salvar Conta';
    document.getElementById('modalConta').style.display = 'flex';
}
function closeModalConta() {
    document.getElementById('modalConta').style.display = 'none';
}
function editConta(cb) {
    document.getElementById('modalContaTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Conta Bancária';
    document.getElementById('contaAction').value = 'edit_conta';
    document.getElementById('contaId').value     = cb.id;
    document.getElementById('contaNome').value   = cb.nome;
    document.getElementById('contaBanco').value  = cb.banco  || '';
    document.getElementById('contaAgencia').value = cb.agencia || '';
    document.getElementById('contaNumero').value = cb.conta  || '';
    document.getElementById('contaTipo').value   = cb.tipo;
    document.getElementById('contaAtiva').checked = cb.ativa == 1;
    document.getElementById('contaObs').value    = cb.observacoes || '';
    document.getElementById('saldoInicialGroup').style.display = 'none'; // não edita saldo inicial
    document.getElementById('btnSalvarConta').textContent = 'Atualizar Conta';
    <?php if (isAdminGeral()): ?>
    document.getElementById('contaEstab').value = cb.estabelecimento_id;
    <?php endif; ?>
    document.getElementById('modalConta').style.display = 'flex';
}

// ── Modal Movimentação ────────────────────────────────────────
function openModalMov() {
    document.getElementById('modalMovTitulo').innerHTML = '<i class="fas fa-exchange-alt"></i> Nova Movimentação';
    document.getElementById('movAction').value = 'add_mov';
    document.getElementById('movId').value     = '';
    document.getElementById('movForm').reset();
    document.getElementById('movData').value   = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('btnSalvarMov').textContent = 'Registrar';
    toggleTransferencia();
    document.getElementById('modalMov').style.display = 'flex';
}
function closeModalMov() {
    document.getElementById('modalMov').style.display = 'none';
}
function editMov(mov) {
    document.getElementById('modalMovTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Movimentação';
    document.getElementById('movAction').value     = 'edit_mov';
    document.getElementById('movId').value         = mov.id;
    document.getElementById('movTipo').value       = mov.tipo;
    document.getElementById('movData').value       = mov.data_movimentacao;
    document.getElementById('movDescricao').value  = mov.descricao;
    document.getElementById('movValor').value      = mov.valor.replace('.', ',');
    document.getElementById('movCategoria').value  = mov.categoria  || '';
    document.getElementById('movCentroCusto').value = mov.centro_custo || '';
    document.getElementById('movClassificacao').value = mov.classificacao || '';
    document.getElementById('movObs').value        = mov.observacoes || '';
    document.getElementById('btnSalvarMov').textContent = 'Atualizar';
    toggleTransferencia();
    document.getElementById('modalMov').style.display = 'flex';
}
function toggleTransferencia() {
    const tipo = document.getElementById('movTipo').value;
    const grp  = document.getElementById('contaDestinoGroup');
    if (grp) grp.style.display = (tipo === 'transferencia') ? '' : 'none';
}

// Fechar modais clicando fora
document.getElementById('modalConta').addEventListener('click', function(e) {
    if (e.target === this) closeModalConta();
});
document.getElementById('modalMov').addEventListener('click', function(e) {
    if (e.target === this) closeModalMov();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModalConta(); closeModalMov(); }
});
</script>

<?php require_once '../includes/footer.php'; ?>
