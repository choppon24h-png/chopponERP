<?php
/**
 * Configurações de Pagamento + Gestão de Leitoras SumUp Solo
 * v2.0 — Botão "Excluir Todas", Seleção de Estabelecimento, Diagnóstico Offline melhorado
 */
$page_title   = 'Configurações de Pagamento';
$current_page = 'pagamentos';

require_once '../includes/config.php';
require_once '../includes/auth.php';

requireAuth();

$conn    = getDBConnection();
$success = '';
$error   = '';

// ─── Garantir colunas extras na tabela payment ────────────────
try {
    $conn->exec("ALTER TABLE `payment` ADD COLUMN IF NOT EXISTS `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `debit`");
    $conn->exec("ALTER TABLE `payment` ADD COLUMN IF NOT EXISTS `affiliate_key` VARCHAR(255) NULL DEFAULT NULL AFTER `estabelecimento_id`");
} catch (Exception $e) { /* ignora se já existir */ }

// ─── Garantir colunas extras na tabela sumup_readers ─────────
try {
    $conn->exec("ALTER TABLE `sumup_readers` ADD COLUMN IF NOT EXISTS `estabelecimento_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `last_activity`");
} catch (Exception $e) { /* ignora se já existir */ }

// ─── Buscar estabelecimentos disponíveis ─────────────────────
if (isAdminGeral()) {
    $stmt_estabs = $conn->query("SELECT id, name, document FROM estabelecimentos WHERE status = 1 ORDER BY name");
} else {
    $stmt_estabs = $conn->prepare("
        SELECT e.id, e.name, e.document
        FROM estabelecimentos e
        INNER JOIN user_estabelecimento ue ON e.id = ue.estabelecimento_id
        WHERE ue.user_id = ? AND ue.status = 1 AND e.status = 1
        ORDER BY e.name
    ");
    $stmt_estabs->execute([$_SESSION['user_id']]);
}
$estabelecimentos = $stmt_estabs->fetchAll(PDO::FETCH_ASSOC);

// ─── Processar salvar configuração de pagamento ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $token_sumup        = sanitize($_POST['token_sumup']);
    $affiliate_key      = sanitize($_POST['affiliate_key'] ?? '');
    $affiliate_app_id   = sanitize($_POST['affiliate_app_id'] ?? '');  // ← NOVO
    $estabelecimento_id = !empty($_POST['estabelecimento_id']) ? intval($_POST['estabelecimento_id']) : null;
    $pix                = isset($_POST['pix'])    ? 1 : 0;
    $credit             = isset($_POST['credit']) ? 1 : 0;
    $debit              = isset($_POST['debit'])  ? 1 : 0;

    // Garantir que a coluna affiliate_app_id existe (migração automática)
    try {
        $conn->exec("ALTER TABLE `payment` ADD COLUMN IF NOT EXISTS `affiliate_app_id` VARCHAR(120) NULL DEFAULT NULL COMMENT 'App Identifier da Affiliate Key SumUp'");
    } catch (Exception $e) { /* ignora se já existir */ }

    $stmt     = $conn->query("SELECT id FROM payment LIMIT 1");
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE payment SET token_sumup = ?, affiliate_key = ?, affiliate_app_id = ?, estabelecimento_id = ?, pix = ?, credit = ?, debit = ? WHERE id = ?");
        if ($stmt->execute([$token_sumup, $affiliate_key ?: null, $affiliate_app_id ?: null, $estabelecimento_id, $pix, $credit, $debit, $existing['id']])) {
            $success = 'Configurações atualizadas com sucesso!';
        } else {
            $error = 'Erro ao atualizar configurações.';
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO payment (token_sumup, affiliate_key, affiliate_app_id, estabelecimento_id, pix, credit, debit) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$token_sumup, $affiliate_key ?: null, $affiliate_app_id ?: null, $estabelecimento_id, $pix, $credit, $debit])) {
            $success = 'Configurações salvas com sucesso!';
        } else {
            $error = 'Erro ao salvar configurações.';
        }
    }
}

// ─── Buscar configurações atuais ─────────────────────────────
$stmt    = $conn->query("SELECT * FROM payment LIMIT 1");
$payment = $stmt->fetch();

// ─── Buscar leitoras cadastradas ─────────────────────────────
$conn->exec("CREATE TABLE IF NOT EXISTS `sumup_readers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reader_id` VARCHAR(60) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `serial` VARCHAR(100) NULL DEFAULT NULL,
    `model` VARCHAR(50) NULL DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'processing',
    `battery_level` INT NULL DEFAULT NULL,
    `connection_type` VARCHAR(30) NULL DEFAULT NULL,
    `firmware_version` VARCHAR(50) NULL DEFAULT NULL,
    `last_activity` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reader_id_unique` (`reader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Buscar leitoras com nome do estabelecimento vinculado
$stmt_readers = $conn->query("
    SELECT sr.*, e.name AS estabelecimento_nome, e.id AS estab_id
    FROM sumup_readers sr
    LEFT JOIN estabelecimentos e ON sr.estabelecimento_id = e.id
    ORDER BY sr.created_at DESC
");
$readers = $stmt_readers->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Configurações de Pagamento</h1>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     SEÇÃO 1 — Token SumUp e Métodos de Pagamento
     ════════════════════════════════════════════════════════════ -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-key"></i> Integração SumUp</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="save_payment" value="1">

                    <!-- Seleção de Estabelecimento -->
                    <div class="form-group">
                        <label for="estabelecimento_id">
                            <i class="fas fa-store"></i> Estabelecimento Vinculado
                        </label>
                        <select name="estabelecimento_id" id="estabelecimento_id" class="form-control">
                            <option value="">— Selecione o Estabelecimento —</option>
                            <?php foreach ($estabelecimentos as $estab): ?>
                                <option value="<?php echo $estab['id']; ?>"
                                    <?php echo ($payment['estabelecimento_id'] ?? '') == $estab['id'] ? 'selected' : ''; ?>>
                                    #<?php echo $estab['id']; ?> — <?php echo htmlspecialchars($estab['name']); ?>
                                    <?php if (!empty($estab['document'])): ?>
                                        (<?php echo htmlspecialchars($estab['document']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:var(--gray-600);">
                            Vincule estas configurações de pagamento a um estabelecimento específico.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="token_sumup">Token SumUp (API Key) *</label>
                        <input type="text"
                               name="token_sumup"
                               id="token_sumup"
                               class="form-control"
                               value="<?php echo htmlspecialchars($payment['token_sumup'] ?? ''); ?>"
                               required>
                        <small style="color:var(--gray-600);">Token de autenticação da API SumUp (sup_sk_...)</small>
                    </div>

                    <div class="form-group">
                        <label for="affiliate_key">
                            <i class="fas fa-link"></i> Affiliate Key
                            <span style="font-size:11px;color:#e67e22;font-weight:normal;">(obrigatório para Cloud API)</span>
                        </label>
                        <input type="text"
                               name="affiliate_key"
                               id="affiliate_key"
                               class="form-control"
                               value="<?php echo htmlspecialchars($payment['affiliate_key'] ?? ''); ?>"
                               placeholder="Ex: sup_afk_...">
                        <small style="color:var(--gray-600);">
                            Chave de afiliado obrigatória para transações via Cloud API (leitoras SumUp Solo).
                            Crie em: <a href="https://developer.sumup.com" target="_blank">developer.sumup.com</a> → Settings → For Developers → Toolkit → Affiliate Keys.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="affiliate_app_id">
                            <i class="fas fa-fingerprint"></i> Affiliate App ID
                            <span style="font-size:11px;color:#e74c3c;font-weight:bold;">(obrigatório — sem isso o checkout retorna erro 422)</span>
                        </label>
                        <input type="text"
                               name="affiliate_app_id"
                               id="affiliate_app_id"
                               class="form-control"
                               value="<?php echo htmlspecialchars($payment['affiliate_app_id'] ?? ''); ?>"
                               placeholder="Ex: com.ochoppo.app  ou  ochoppo.com.br">
                        <small style="color:var(--gray-600);">
                            <strong>Application Identifier</strong> cadastrado na sua Affiliate Key.
                            Para criar: <a href="https://me.sumup.com" target="_blank">me.sumup.com</a> → Settings → For Developers → Toolkit → Affiliate Keys → campo "Application identifier".
                            Pode ser qualquer string única (ex: <code>com.ochoppo.erp</code> ou <code>ochoppoficial.com.br</code>).
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Métodos de Pagamento Habilitados</label>
                        <div class="checkbox-label">
                            <input type="checkbox" name="pix" id="pix" value="1"
                                <?php echo ($payment['pix'] ?? 1) ? 'checked' : ''; ?>>
                            <span>PIX</span>
                        </div>
                        <div class="checkbox-label">
                            <input type="checkbox" name="credit" id="credit" value="1"
                                <?php echo ($payment['credit'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Cartão de Crédito</span>
                        </div>
                        <div class="checkbox-label">
                            <input type="checkbox" name="debit" id="debit" value="1"
                                <?php echo ($payment['debit'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Cartão de Débito</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Configurações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-info-circle"></i> Informações</h4>
            </div>
            <div class="card-body">
                <p><strong>Merchant Code:</strong> <?php echo SUMUP_MERCHANT_CODE; ?></p>
                <p><strong>Webhook URL:</strong></p>
                <code style="font-size:11px;word-break:break-all;">
                    <?php echo SITE_URL; ?>/api/webhook.php
                </code>
                <hr>
                <p style="font-size:13px;color:var(--gray-600);">
                    Configure este webhook no painel SumUp para receber notificações de status de pagamento.
                </p>
                <hr>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px;font-size:12px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Affiliate Key obrigatória</strong> para transações via Cloud API.
                    Sem ela, checkouts serão rejeitados pela SumUp.
                </div>
            </div>
        </div>

        <!-- Card de status da conexão SumUp -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h4><i class="fas fa-plug"></i> Status da API</h4>
            </div>
            <div class="card-body" id="statusApiCard">
                <div style="text-align:center;padding:12px;">
                    <i class="fas fa-spinner fa-spin"></i> Verificando...
                </div>
            </div>
            <div class="card-body" style="border-top:1px solid #eee;padding-top:10px;">
                <a href="sumup_cloud_tester.php" class="btn btn-sm btn-outline-primary" style="width:100%;">
                    <i class="fas fa-vial"></i> Abrir Tester Cloud API
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     SEÇÃO 2 — Leitoras de Cartão SumUp Solo
     ════════════════════════════════════════════════════════════ -->
<div class="row" style="margin-top:24px;">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h4><i class="fas fa-credit-card"></i> Leitoras de Cartão SumUp Solo</h4>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-danger btn-sm" onclick="confirmarExcluirTodas()"
                            id="btnExcluirTodas" <?php echo empty($readers) ? 'disabled' : ''; ?>>
                        <i class="fas fa-trash-alt"></i> Excluir Todas
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="abrirModalNovaLeitora()">
                        <i class="fas fa-plus"></i> Nova Leitora
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Aviso sobre leitora offline -->
                <div style="background:#e8f4fd;border:1px solid #3498db;border-radius:6px;padding:12px;font-size:13px;margin-bottom:16px;">
                    <i class="fas fa-info-circle" style="color:#3498db;"></i>
                    <strong>Como manter a leitora ONLINE:</strong>
                    Ligue o SumUp Solo → <em>Connections</em> → <em>Wi-Fi</em> (conecte) → <em>API</em> → <em>Connect</em>.
                    O dispositivo exibirá "<strong>Connected — Ready to transact</strong>".
                    O status OFFLINE indica que o dispositivo está desligado ou sem conexão ativa com a API SumUp.
                </div>

                <div class="table-responsive">
                    <table class="table" id="tabelaLeitoras">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Nome / Código</th>
                                <th>Estabelecimento</th>
                                <th>Serial</th>
                                <th>Modelo</th>
                                <th>Bateria</th>
                                <th>Conexão</th>
                                <th>Última Atividade</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyLeitoras">
                            <?php if (empty($readers)): ?>
                                <tr id="trVazio">
                                    <td colspan="9" style="text-align:center;color:var(--gray-500);padding:32px;">
                                        <i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>
                                        Nenhuma leitora cadastrada. Clique em <strong>Nova Leitora</strong> para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($readers as $r): ?>
                                    <tr id="row-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                        <td>
                                            <span class="badge badge-secondary" id="badge-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                                <i class="fas fa-spinner fa-spin"></i> Verificando...
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                                        <td id="estab-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php if (!empty($r['estabelecimento_nome'])): ?>
                                                <span class="badge badge-info" style="font-size:11px;">
                                                    #<?php echo $r['estab_id']; ?> <?php echo htmlspecialchars($r['estabelecimento_nome']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:var(--gray-500);font-size:12px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($r['serial'] ?? '—'); ?></code></td>
                                        <td><?php echo htmlspecialchars($r['model'] ?? '—'); ?></td>
                                        <td id="bat-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo $r['battery_level'] !== null ? $r['battery_level'].'%' : '—'; ?>
                                        </td>
                                        <td id="conn-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo htmlspecialchars($r['connection_type'] ?? '—'); ?>
                                        </td>
                                        <td id="act-<?php echo htmlspecialchars($r['reader_id']); ?>">
                                            <?php echo $r['last_activity'] ? date('d/m/Y H:i', strtotime($r['last_activity'])) : '—'; ?>
                                        </td>
                                        <td class="action-buttons">
                                            <button class="btn btn-sm btn-secondary"
                                                    onclick="testarLeitora('<?php echo htmlspecialchars($r['reader_id']); ?>', '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
                                                <i class="fas fa-wifi"></i> Testar
                                            </button>
                                            <button class="btn btn-sm btn-info"
                                                    onclick="abrirModalVincularEstab('<?php echo htmlspecialchars($r['reader_id']); ?>', '<?php echo htmlspecialchars(addslashes($r['name'])); ?>', '<?php echo $r['estab_id'] ?? ''; ?>')"
                                                    title="Vincular a estabelecimento">
                                                <i class="fas fa-store"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger"
                                                    onclick="confirmarExcluir('<?php echo htmlspecialchars($r['reader_id']); ?>', '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
                                                <i class="fas fa-trash"></i> Excluir
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Nova Leitora
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalNovaLeitora">
    <div class="modal-content" style="max-width:540px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Cadastrar Nova Leitora</h3>
            <button class="modal-close" onclick="fecharModalNovaLeitora()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="inputPairingCode">Código de Pareamento *</label>
                <input type="text"
                       id="inputPairingCode"
                       class="form-control"
                       placeholder="Ex: A4RZALFHY"
                       maxlength="9"
                       style="text-transform:uppercase;font-size:20px;letter-spacing:3px;font-weight:bold;text-align:center;"
                       oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                <small style="color:var(--gray-600);">
                    Ligue o SumUp Solo → <em>Connections</em> → <em>Wi-Fi</em> → <em>API</em> → <em>Connect</em>.
                    O código de 8 ou 9 caracteres aparecerá na tela.
                </small>
            </div>
            <div class="form-group">
                <label for="inputNomeLeitora">Nome / Identificação da Unidade</label>
                <input type="text"
                       id="inputNomeLeitora"
                       class="form-control"
                       placeholder="Ex: TAP 01 ALMEIDA">
                <small style="color:var(--gray-600);">Nome para identificar esta leitora no sistema.</small>
            </div>
            <div class="form-group">
                <label for="inputEstabNovaLeitora">Estabelecimento (opcional)</label>
                <select id="inputEstabNovaLeitora" class="form-control">
                    <option value="">— Sem vínculo —</option>
                    <?php foreach ($estabelecimentos as $estab): ?>
                        <option value="<?php echo $estab['id']; ?>">
                            #<?php echo $estab['id']; ?> — <?php echo htmlspecialchars($estab['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Resultado do teste -->
            <div id="resultadoTeste" style="display:none;margin-top:16px;padding:14px;border-radius:6px;font-size:13px;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalNovaLeitora()">Cancelar</button>
            <button class="btn btn-warning" id="btnTestarNova" onclick="testarNovaLeitora()">
                <i class="fas fa-wifi"></i> Testar Vinculação
            </button>
            <button class="btn btn-success" id="btnGravar" onclick="gravarLeitora()" style="display:none;">
                <i class="fas fa-save"></i> Gravar Leitora
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Confirmar Exclusão Individual
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalExcluir">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i> Confirmar Exclusão</h3>
            <button class="modal-close" onclick="fecharModalExcluir()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Tem certeza que deseja excluir a leitora <strong id="nomeExcluir"></strong>?</p>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;font-size:13px;margin-top:8px;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Atenção:</strong> A leitora será desvinculada de todas as TAPs e o SumUp Solo exibirá um novo código de pareamento.
                Para completar a desvinculação, acesse o dispositivo físico: <em>Connections → API → Disconnect</em>.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalExcluir()">Cancelar</button>
            <button class="btn btn-danger" id="btnConfirmarExcluir" onclick="excluirLeitora()">
                <i class="fas fa-trash"></i> Excluir
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Confirmar Exclusão de TODAS as Leitoras
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalExcluirTodas">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header" style="background:#c0392b;color:#fff;border-radius:8px 8px 0 0;">
            <h3 style="color:#fff;"><i class="fas fa-exclamation-triangle"></i> ATENÇÃO — Excluir TODAS as Leitoras</h3>
            <button class="modal-close" onclick="fecharModalExcluirTodas()" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background:#fdecea;border:2px solid #e74c3c;border-radius:6px;padding:16px;margin-bottom:16px;">
                <p style="font-size:15px;font-weight:bold;color:#c0392b;margin-bottom:8px;">
                    <i class="fas fa-exclamation-circle"></i> Esta ação é irreversível!
                </p>
                <p style="font-size:13px;margin-bottom:0;">
                    Ao confirmar, <strong>TODAS as leitoras SumUp Solo</strong> vinculadas a este token serão:
                </p>
                <ul style="font-size:13px;margin-top:8px;padding-left:20px;">
                    <li>Excluídas da <strong>Cloud API SumUp</strong> (desvinculadas da conta)</li>
                    <li>Removidas do banco de dados do sistema</li>
                    <li>Desvinculadas de todas as TAPs associadas</li>
                </ul>
            </div>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;font-size:13px;">
                <i class="fas fa-mobile-alt"></i>
                <strong>Nos dispositivos físicos:</strong> Após a exclusão, cada SumUp Solo exibirá um novo código de pareamento.
                Para completar a desvinculação nos dispositivos, acesse: <em>Connections → API → Disconnect</em>.
            </div>
            <div style="margin-top:16px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:13px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:bold;">
                    <input type="checkbox" id="checkConfirmarTodas" onchange="toggleBtnExcluirTodas()">
                    Entendo que todos os acessos vinculados na Cloud API serão desconectados.
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalExcluirTodas()">Cancelar</button>
            <button class="btn btn-danger" id="btnConfirmarExcluirTodas" onclick="excluirTodasLeitoras()" disabled>
                <i class="fas fa-trash-alt"></i> Sim, Excluir TODAS
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Resultado do Teste
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalTeste">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-wifi"></i> Teste de Comunicação</h3>
            <button class="modal-close" onclick="document.getElementById('modalTeste').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body" id="modalTesteBody">
            <div style="text-align:center;padding:24px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--primary);"></i>
                <p style="margin-top:12px;">Testando comunicação com a leitora...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="document.getElementById('modalTeste').classList.remove('active')">Fechar</button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Vincular Estabelecimento à Leitora
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalVincularEstab">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fas fa-store"></i> Vincular Estabelecimento</h3>
            <button class="modal-close" onclick="fecharModalVincularEstab()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Selecione o estabelecimento para vincular à leitora <strong id="nomeLeitVincular"></strong>:</p>
            <div class="form-group" style="margin-top:12px;">
                <label for="selectEstabVincular">Estabelecimento</label>
                <select id="selectEstabVincular" class="form-control">
                    <option value="">— Sem vínculo —</option>
                    <?php foreach ($estabelecimentos as $estab): ?>
                        <option value="<?php echo $estab['id']; ?>">
                            #<?php echo $estab['id']; ?> — <?php echo htmlspecialchars($estab['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="fecharModalVincularEstab()">Cancelar</button>
            <button class="btn btn-primary" id="btnSalvarVinculo" onclick="salvarVinculoEstab()">
                <i class="fas fa-save"></i> Salvar Vínculo
            </button>
        </div>
    </div>
</div>

<script>
// ─── Estado global ────────────────────────────────────────────
var readerIdParaExcluir  = '';
var readerIdParaVincular = '';
var dadosNovaLeitora     = null;
var API_URL = '<?php echo SITE_URL; ?>/api/manage_readers.php';

// ─── Verificar status da API SumUp ao carregar ───────────────
document.addEventListener('DOMContentLoaded', function () {
    verificarStatusApi();

    // Verificar status de todas as leitoras
    var badges = document.querySelectorAll('[id^="badge-"]');
    badges.forEach(function(badge) {
        var readerId = badge.id.replace('badge-', '');
        var fd = new FormData();
        fd.append('action', 'test');
        fd.append('reader_id', readerId);
        fetch(API_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                badge.className = 'badge ' + (data.online ? 'badge-success' : 'badge-warning');
                badge.innerHTML = '<i class="fas fa-circle"></i> ' + (data.online ? 'ONLINE' : 'OFFLINE');
                var batEl  = document.getElementById('bat-'  + readerId);
                var connEl = document.getElementById('conn-' + readerId);
                var actEl  = document.getElementById('act-'  + readerId);
                if (batEl  && data.battery !== null && data.battery !== undefined) batEl.textContent  = data.battery + '%';
                if (connEl && data.connection)  connEl.textContent = data.connection;
                if (actEl  && data.last_activity) actEl.textContent = new Date(data.last_activity).toLocaleString('pt-BR');
            })
            .catch(function() {
                badge.className = 'badge badge-secondary';
                badge.innerHTML = '<i class="fas fa-circle"></i> Erro';
            });
    });
});

function verificarStatusApi() {
    var card = document.getElementById('statusApiCard');
    var fd = new FormData();
    fd.append('action', 'check_api');
    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.api_ok) {
                card.innerHTML =
                    '<div style="color:#27ae60;font-weight:bold;"><i class="fas fa-check-circle"></i> API SumUp: ATIVA</div>' +
                    '<small style="color:var(--gray-600);">Token válido. Merchant: ' + escHtml(data.merchant || '') + '</small>';
            } else {
                card.innerHTML =
                    '<div style="color:#e74c3c;font-weight:bold;"><i class="fas fa-times-circle"></i> API SumUp: INATIVA</div>' +
                    '<small style="color:#e74c3c;">' + escHtml(data.error || 'Token inválido ou expirado') + '</small>';
            }
        })
        .catch(function() {
            card.innerHTML = '<small style="color:var(--gray-500);">Não foi possível verificar o status da API.</small>';
        });
}

// ─── Abrir/fechar modal Nova Leitora ─────────────────────────
function abrirModalNovaLeitora() {
    document.getElementById('inputPairingCode').value = '';
    document.getElementById('inputNomeLeitora').value = '';
    document.getElementById('inputEstabNovaLeitora').value = '';
    document.getElementById('resultadoTeste').style.display = 'none';
    document.getElementById('btnGravar').style.display = 'none';
    dadosNovaLeitora = null;
    document.getElementById('modalNovaLeitora').classList.add('active');
    setTimeout(function(){ document.getElementById('inputPairingCode').focus(); }, 100);
}

function fecharModalNovaLeitora() {
    document.getElementById('modalNovaLeitora').classList.remove('active');
}

// ─── Testar nova leitora (antes de gravar) ───────────────────
function testarNovaLeitora() {
    var code  = document.getElementById('inputPairingCode').value.trim().toUpperCase();
    var name  = document.getElementById('inputNomeLeitora').value.trim() || code;
    var estab = document.getElementById('inputEstabNovaLeitora').value;

    if (code.length < 8 || code.length > 9) {
        mostrarResultado('danger', '<i class="fas fa-times-circle"></i> O código deve ter 8 ou 9 caracteres alfanuméricos.');
        return;
    }

    var btn = document.getElementById('btnTestarNova');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
    document.getElementById('btnGravar').style.display = 'none';
    mostrarResultado('info', '<i class="fas fa-spinner fa-spin"></i> Conectando ao SumUp Solo... Aguarde até 20 segundos.');

    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('pairing_code', code);
    fd.append('name', name);
    if (estab) fd.append('estabelecimento_id', estab);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wifi"></i> Testar Vinculação';

            if (data.success) {
                dadosNovaLeitora = data;
                dadosNovaLeitora.estabelecimento_id = estab;
                var statusIcon = data.online ? '🟢' : '🟡';
                var sumupStatus = (data.sumup_status || data.status || '').toLowerCase();
                var statusTxt = 'OFFLINE';
                if (data.online) {
                    statusTxt = 'ONLINE';
                } else if (sumupStatus === 'processing') {
                    statusTxt = 'PROCESSING — Aguardando confirmação no dispositivo';
                } else if (sumupStatus === 'paired') {
                    statusTxt = 'PAIRED — Pareado, porém sem conexão ativa com a API';
                }
                var html = '<strong>' + statusIcon + ' Leitora vinculada com sucesso!</strong><br>';
                html += '<table style="margin-top:10px;font-size:12px;width:100%;border-collapse:collapse;">';
                html += '<tr><td style="padding:3px 8px 3px 0;width:130px;"><strong>Reader ID:</strong></td><td><code>' + escHtml(data.reader_id) + '</code></td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Serial:</strong></td><td><code>' + escHtml(data.serial || '—') + '</code></td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Modelo:</strong></td><td>' + escHtml(data.model || '—') + '</td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Status SumUp:</strong></td><td>' + escHtml(data.sumup_status || data.status || '—') + '</td></tr>';
                html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Conectividade:</strong></td><td>' + statusTxt + '</td></tr>';
                if (data.battery !== null && data.battery !== undefined) html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Bateria:</strong></td><td>' + data.battery + '%</td></tr>';
                if (data.connection) html += '<tr><td style="padding:3px 8px 3px 0;"><strong>Conexão:</strong></td><td>' + escHtml(data.connection) + '</td></tr>';
                html += '</table>';
                if (!data.online) {
                    html += '<div style="margin-top:10px;padding:8px;background:#fff3cd;border-radius:4px;font-size:12px;">';
                    html += '<i class="fas fa-info-circle"></i> <strong>Para ficar ONLINE:</strong> No SumUp Solo → ';
                    html += '<em>Connections → API → Connect</em>. O dispositivo exibirá "Connected — Ready to transact".';
                    html += '</div>';
                }
                html += '<p style="margin-top:10px;font-size:12px;color:#555;">' + escHtml(data.mensagem) + '</p>';
                mostrarResultado('success', html);
                document.getElementById('btnGravar').style.display = 'inline-block';
            } else {
                mostrarResultado('danger',
                    '<strong><i class="fas fa-times-circle"></i> Erro ao vincular leitora</strong><br>' +
                    escHtml(data.error || 'Erro desconhecido')
                );
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wifi"></i> Testar Vinculação';
            mostrarResultado('danger', '<i class="fas fa-times-circle"></i> Erro de comunicação com o servidor.');
        });
}

// ─── Gravar leitora após teste bem-sucedido ──────────────────
function gravarLeitora() {
    if (!dadosNovaLeitora) return;
    fecharModalNovaLeitora();
    adicionarLinhaTabela(dadosNovaLeitora);
    var trVazio = document.getElementById('trVazio');
    if (trVazio) trVazio.remove();
    // Habilitar botão excluir todas
    document.getElementById('btnExcluirTodas').disabled = false;
    mostrarToast('Leitora gravada com sucesso!', 'success');
}

// ─── Adicionar linha na tabela ───────────────────────────────
function adicionarLinhaTabela(data) {
    var tbody      = document.getElementById('tbodyLeitoras');
    var badgeClass = data.online ? 'badge-success' : 'badge-warning';
    var badgeLabel = data.online ? 'ONLINE' : 'Aguardando';
    var lastAct    = data.last_activity ? new Date(data.last_activity).toLocaleString('pt-BR') : '—';
    var rid        = escHtml(data.reader_id);
    var rname      = escHtml(data.name);

    var tr = document.createElement('tr');
    tr.id  = 'row-' + rid;
    tr.innerHTML =
        '<td><span class="badge ' + badgeClass + '" id="badge-' + rid + '"><i class="fas fa-circle"></i> ' + badgeLabel + '</span></td>' +
        '<td><strong>' + rname + '</strong></td>' +
        '<td id="estab-' + rid + '"><span style="color:var(--gray-500);font-size:12px;">—</span></td>' +
        '<td><code>' + escHtml(data.serial || '—') + '</code></td>' +
        '<td>' + escHtml(data.model || '—') + '</td>' +
        '<td id="bat-' + rid + '">' + (data.battery !== null && data.battery !== undefined ? data.battery + '%' : '—') + '</td>' +
        '<td id="conn-' + rid + '">' + escHtml(data.connection || '—') + '</td>' +
        '<td id="act-' + rid + '">' + lastAct + '</td>' +
        '<td class="action-buttons">' +
            '<button class="btn btn-sm btn-secondary" onclick="testarLeitora(\'' + rid + '\', \'' + rname + '\')">' +
                '<i class="fas fa-wifi"></i> Testar</button> ' +
            '<button class="btn btn-sm btn-info" onclick="abrirModalVincularEstab(\'' + rid + '\', \'' + rname + '\', \'\')" title="Vincular estabelecimento">' +
                '<i class="fas fa-store"></i></button> ' +
            '<button class="btn btn-sm btn-danger" onclick="confirmarExcluir(\'' + rid + '\', \'' + rname + '\')">' +
                '<i class="fas fa-trash"></i> Excluir</button>' +
        '</td>';
    tbody.appendChild(tr);
}

// ─── Testar leitora existente ─────────────────────────────────
function testarLeitora(readerId, nome) {
    document.getElementById('modalTesteBody').innerHTML =
        '<div style="text-align:center;padding:24px;">' +
        '<i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--primary);"></i>' +
        '<p style="margin-top:12px;">Testando comunicação com <strong>' + escHtml(nome) + '</strong>...</p></div>';
    document.getElementById('modalTeste').classList.add('active');

    var fd = new FormData();
    fd.append('action', 'test');
    fd.append('reader_id', readerId);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var cor     = data.online ? '#27ae60' : '#e67e22';
            var icone   = data.online ? 'check-circle' : 'exclamation-triangle';
            var lastAct = data.last_activity ? new Date(data.last_activity).toLocaleString('pt-BR') : '—';

            var html = '<div style="border-left:4px solid ' + cor + ';padding:12px 16px;background:#f9f9f9;border-radius:4px;">';
            html += '<p style="font-size:15px;font-weight:bold;color:' + cor + ';margin-bottom:10px;">';
            html += '<i class="fas fa-' + icone + '"></i> ' + escHtml(data.status_label || (data.online ? 'ONLINE' : 'OFFLINE')) + '</p>';
            html += '<table style="font-size:13px;width:100%;border-collapse:collapse;">';
            html += '<tr><td style="width:150px;padding:3px 0;"><strong>Leitora:</strong></td><td>' + escHtml(nome) + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Serial:</strong></td><td><code>' + escHtml(data.serial || '—') + '</code></td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Modelo:</strong></td><td>' + escHtml(data.model || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Status SumUp:</strong></td><td>' + escHtml(data.sumup_status || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Bateria:</strong></td><td>' + (data.battery !== null && data.battery !== undefined ? data.battery + '%' : '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Conexão:</strong></td><td>' + escHtml(data.connection || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Firmware:</strong></td><td>' + escHtml(data.firmware || '—') + '</td></tr>';
            html += '<tr><td style="padding:3px 0;"><strong>Última Atividade:</strong></td><td>' + lastAct + '</td></tr>';
            html += '</table>';
            html += '<p style="margin-top:12px;font-size:13px;">' + escHtml(data.mensagem || '') + '</p>';

            // Guia de resolução se offline
            if (!data.online) {
                html += '<div style="margin-top:12px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:12px;">';
                html += '<strong><i class="fas fa-tools"></i> Como resolver o status OFFLINE:</strong><ul style="margin:6px 0 0 16px;padding:0;">';
                html += '<li>Certifique-se que o SumUp Solo está <strong>ligado e com bateria</strong></li>';
                html += '<li>Conecte ao Wi-Fi: <em>Connections → Wi-Fi → selecione a rede</em></li>';
                html += '<li>Ative a conexão API: <em>Connections → API → Connect</em></li>';
                html += '<li>O dispositivo deve exibir "<strong>Connected — Ready to transact</strong>"</li>';
                html += '<li>Se o status SumUp for "paired", o pareamento está OK — apenas a conexão está inativa</li>';
                html += '</ul></div>';
            }

            html += '</div>';
            document.getElementById('modalTesteBody').innerHTML = html;

            // Atualizar badge e campos na tabela
            var badge = document.getElementById('badge-' + readerId);
            if (badge) {
                badge.className = 'badge ' + (data.online ? 'badge-success' : 'badge-warning');
                badge.innerHTML = '<i class="fas fa-circle"></i> ' + (data.online ? 'ONLINE' : 'OFFLINE');
            }
            var batEl  = document.getElementById('bat-'  + readerId);
            var connEl = document.getElementById('conn-' + readerId);
            var actEl  = document.getElementById('act-'  + readerId);
            if (batEl  && data.battery !== null && data.battery !== undefined) batEl.textContent  = data.battery + '%';
            if (connEl && data.connection)  connEl.textContent = data.connection;
            if (actEl  && data.last_activity) actEl.textContent = lastAct;
        })
        .catch(function() {
            document.getElementById('modalTesteBody').innerHTML =
                '<p style="color:#e74c3c;"><i class="fas fa-times-circle"></i> Erro de comunicação com o servidor.</p>';
        });
}

// ─── Confirmar exclusão individual ───────────────────────────
function confirmarExcluir(readerId, nome) {
    readerIdParaExcluir = readerId;
    document.getElementById('nomeExcluir').textContent = nome;
    document.getElementById('modalExcluir').classList.add('active');
}

function fecharModalExcluir() {
    document.getElementById('modalExcluir').classList.remove('active');
    readerIdParaExcluir = '';
}

function excluirLeitora() {
    if (!readerIdParaExcluir) return;
    var btn = document.getElementById('btnConfirmarExcluir');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';

    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('reader_id', readerIdParaExcluir);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Excluir';
            fecharModalExcluir();

            if (data.success) {
                var row = document.getElementById('row-' + readerIdParaExcluir);
                if (row) row.remove();
                mostrarToast(data.mensagem, 'success');
                if (!document.querySelector('#tbodyLeitoras tr:not(#trVazio)')) {
                    document.getElementById('tbodyLeitoras').innerHTML =
                        '<tr id="trVazio"><td colspan="9" style="text-align:center;color:var(--gray-500);padding:32px;">' +
                        '<i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>' +
                        'Nenhuma leitora cadastrada.</td></tr>';
                    document.getElementById('btnExcluirTodas').disabled = true;
                }
            } else {
                mostrarToast(data.error || 'Erro ao excluir leitora', 'danger');
            }
        })
        .catch(function(){ mostrarToast('Erro de comunicação com o servidor', 'danger'); });
}

// ─── Confirmar exclusão de TODAS as leitoras ─────────────────
function confirmarExcluirTodas() {
    document.getElementById('checkConfirmarTodas').checked = false;
    document.getElementById('btnConfirmarExcluirTodas').disabled = true;
    document.getElementById('modalExcluirTodas').classList.add('active');
}

function fecharModalExcluirTodas() {
    document.getElementById('modalExcluirTodas').classList.remove('active');
}

function toggleBtnExcluirTodas() {
    var check = document.getElementById('checkConfirmarTodas').checked;
    document.getElementById('btnConfirmarExcluirTodas').disabled = !check;
}

function excluirTodasLeitoras() {
    var btn = document.getElementById('btnConfirmarExcluirTodas');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo todas...';

    var fd = new FormData();
    fd.append('action', 'delete_all');

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Sim, Excluir TODAS';
            fecharModalExcluirTodas();

            if (data.success) {
                document.getElementById('tbodyLeitoras').innerHTML =
                    '<tr id="trVazio"><td colspan="9" style="text-align:center;color:var(--gray-500);padding:32px;">' +
                    '<i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>' +
                    'Nenhuma leitora cadastrada.</td></tr>';
                document.getElementById('btnExcluirTodas').disabled = true;
                mostrarToast(
                    data.mensagem || ('Todas as leitoras foram excluídas. ' + (data.excluidas || 0) + ' leitoras removidas.'),
                    'success'
                );
            } else {
                mostrarToast(data.error || 'Erro ao excluir leitoras', 'danger');
            }
        })
        .catch(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Sim, Excluir TODAS';
            mostrarToast('Erro de comunicação com o servidor', 'danger');
        });
}

// ─── Vincular estabelecimento à leitora ──────────────────────
function abrirModalVincularEstab(readerId, nome, estabAtual) {
    readerIdParaVincular = readerId;
    document.getElementById('nomeLeitVincular').textContent = nome;
    document.getElementById('selectEstabVincular').value = estabAtual || '';
    document.getElementById('modalVincularEstab').classList.add('active');
}

function fecharModalVincularEstab() {
    document.getElementById('modalVincularEstab').classList.remove('active');
    readerIdParaVincular = '';
}

function salvarVinculoEstab() {
    if (!readerIdParaVincular) return;
    var estabId = document.getElementById('selectEstabVincular').value;
    var btn = document.getElementById('btnSalvarVinculo');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    var fd = new FormData();
    fd.append('action', 'update_estab');
    fd.append('reader_id', readerIdParaVincular);
    fd.append('estabelecimento_id', estabId);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Salvar Vínculo';
            fecharModalVincularEstab();
            if (data.success) {
                var estabEl = document.getElementById('estab-' + readerIdParaVincular);
                if (estabEl) {
                    if (data.estabelecimento_nome) {
                        estabEl.innerHTML = '<span class="badge badge-info" style="font-size:11px;">#' +
                            escHtml(String(estabId)) + ' ' + escHtml(data.estabelecimento_nome) + '</span>';
                    } else {
                        estabEl.innerHTML = '<span style="color:var(--gray-500);font-size:12px;">—</span>';
                    }
                }
                mostrarToast(data.mensagem || 'Vínculo atualizado!', 'success');
            } else {
                mostrarToast(data.error || 'Erro ao salvar vínculo', 'danger');
            }
        })
        .catch(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Salvar Vínculo';
            mostrarToast('Erro de comunicação com o servidor', 'danger');
        });
}

// ─── Helpers ─────────────────────────────────────────────────
function mostrarResultado(tipo, html) {
    var el    = document.getElementById('resultadoTeste');
    var cores  = { success: '#d4edda', danger: '#f8d7da', info: '#d1ecf1', warning: '#fff3cd' };
    var bordas = { success: '#28a745', danger: '#dc3545', info: '#17a2b8', warning: '#ffc107' };
    el.style.display    = 'block';
    el.style.background = cores[tipo]  || '#f9f9f9';
    el.style.border     = '1px solid ' + (bordas[tipo] || '#ccc');
    el.innerHTML        = html;
}

function mostrarToast(msg, tipo) {
    var toast = document.createElement('div');
    var cores  = { success: '#28a745', danger: '#dc3545', info: '#17a2b8' };
    toast.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:6px;' +
        'background:' + (cores[tipo] || '#333') + ';color:#fff;font-size:14px;' +
        'box-shadow:0 4px 12px rgba(0,0,0,.2);max-width:400px;';
    toast.innerHTML = msg;
    document.body.appendChild(toast);
    setTimeout(function(){ toast.remove(); }, 5000);
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}
</script>

<?php require_once '../includes/footer.php'; ?>
