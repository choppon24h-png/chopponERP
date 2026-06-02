<?php
/**
 * ESTOQUE - INVENTÁRIO / PATRIMÔNIO
 * Módulo completo de gestão patrimonial
 */
$page_title    = 'Estoque - Inventário';
$current_page  = 'estoque_inventario';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/PatrimonioManager.php';

requireAuth();

$conn          = getDBConnection();
$user_estab_id = isAdminGeral() ? null : getEstabelecimentoId();
$pm            = new PatrimonioManager($conn, $user_estab_id);
$success       = '';
$error         = '';

// ── Processar ações POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_SESSION['user_id'] ?? 0);

    // ── Criar patrimônio (individual ou lote) ─────────────────────────────────
    if ($action === 'criar') {
        if (empty(trim($_POST['descricao'] ?? ''))) {
            $error = 'A descrição do patrimônio é obrigatória.';
        } else {
            // Garantir que o patrimônio seja vinculado ao estabelecimento do usuário
            if ($user_estab_id && empty($_POST['estabelecimento_id'])) {
                $_POST['estabelecimento_id'] = $user_estab_id;
            }
            $resultado = $pm->criar($_POST, $_FILES, $user_id);
            if ($resultado['success']) {
                $success = $resultado['message'];
            } else {
                $error = $resultado['message'];
            }
        }
    }

    // ── Atualizar patrimônio ──────────────────────────────────────────────────
    if ($action === 'atualizar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error = 'ID inválido.';
        } elseif (empty(trim($_POST['descricao'] ?? ''))) {
            $error = 'A descrição do patrimônio é obrigatória.';
        } else {
            $resultado = $pm->atualizar($id, $_POST, $_FILES, $user_id);
            if ($resultado['success']) {
                $success = $resultado['message'];
            } else {
                $error = $resultado['message'];
            }
        }
    }

    // ── Registrar preventiva ──────────────────────────────────────────────────
    if ($action === 'registrar_preventiva') {
        $patrimonio_id = (int)($_POST['patrimonio_id'] ?? 0);
        if ($patrimonio_id <= 0 || empty($_POST['data_realizada'])) {
            $error = 'Dados da preventiva incompletos.';
        } else {
            $resultado = $pm->registrarPreventiva($patrimonio_id, $_POST, $user_id);
            if ($resultado['success']) {
                $success = $resultado['message'];
            } else {
                $error = $resultado['message'];
            }
        }
    }

    // Redirecionar para evitar resubmissão
    if ($success) {
        header("Location: estoque_inventario.php?msg=" . urlencode($success));
        exit;
    }
}

// Mensagem de sucesso via redirect
if (!empty($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
}

// ── Carregar dados ────────────────────────────────────────────────────────────
$filtros   = [
    'busca'          => $_GET['busca']          ?? '',
    'classificacao'  => $_GET['classificacao']  ?? '',
    'status'         => $_GET['status']         ?? '',
    'categoria'      => $_GET['categoria']      ?? '',
    'tem_preventiva' => $_GET['tem_preventiva'] ?? '',
];
$patrimonios  = $pm->listar($filtros);
$categorias   = $pm->listarCategorias();
$stats        = $pm->estatisticas();

// Patrimônio para edição (via GET ?editar=ID)
$pat_editar   = null;
$prev_editar  = [];
if (!empty($_GET['editar'])) {
    $pat_editar  = $pm->buscarPorId((int)$_GET['editar']);
    if ($pat_editar) {
        $prev_editar = $pm->listarPreventivas((int)$_GET['editar']);
    }
}

require_once '../includes/header.php';
?>

<!-- ── Estilos específicos do módulo ──────────────────────────────────────── -->
<style>
/* stat-card/stat-icon: definidos em assets/css/style.css */
.stat-icon.blue   { background: #e3f0ff; color: var(--primary-color); }
.stat-icon.green  { background: #e6f9ee; color: var(--success-color); }
.stat-icon.orange { background: #fff3e0; color: var(--secondary-color); }
.stat-icon.red    { background: #fdecea; color: var(--danger-color); }
.stat-icon.purple { background: #f3e5f5; color: #9c27b0; }
.stat-icon.teal   { background: #e0f7fa; color: var(--info-color); }

/* Tabela de patrimônio */
.table-patrimonio th { white-space: nowrap; font-size: 13px; }
.table-patrimonio td { font-size: 13px; vertical-align: middle; }
.badge-imobilizado { background: #e3f0ff; color: var(--primary-color); }
.badge-ativo-class { background: #e6f9ee; color: var(--success-color); }
.badge-status-ativo       { background: #e6f9ee; color: var(--success-color); }
.badge-status-inativo     { background: #f8f9fa; color: var(--gray-600); }
.badge-status-manutencao  { background: #fff3e0; color: var(--secondary-color); }
.badge-status-baixado     { background: #fdecea; color: var(--danger-color); }
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.pat-number {
    font-family: monospace;
    font-weight: 700;
    color: var(--primary-color);
    font-size: 13px;
}
.foto-thumb {
    width: 44px;
    height: 44px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    border: 1px solid var(--gray-300);
}
.foto-placeholder {
    width: 44px;
    height: 44px;
    background: var(--gray-200);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-600);
    font-size: 18px;
}
.preventiva-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.preventiva-ok      { background: #e6f9ee; color: var(--success-color); }
.preventiva-vencida { background: #fdecea; color: var(--danger-color); }
.preventiva-sem     { background: #f8f9fa; color: var(--gray-600); }

/* Painel de edição inline */
.edit-panel {
    background: #fff;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    margin-bottom: 24px;
    border-left: 4px solid var(--primary-color);
    overflow: hidden;
}
.edit-panel-header {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: #fff;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.edit-panel-header h3 { margin: 0; font-size: 16px; }
.edit-panel-body { padding: 20px; }

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 1050;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff;
    border-radius: var(--border-radius);
    width: 95%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
}
.modal-box-header {
    padding: 18px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid var(--gray-300);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    position: sticky;
    top: 0;
    z-index: 10;
}
.modal-box-header h4 { margin: 0; font-size: 16px; }
.modal-box-body { padding: 20px; }
.modal-box-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid var(--gray-300);
    text-align: right;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
}
.btn-close-modal {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--gray-600);
    line-height: 1;
}
.btn-close-modal:hover { color: var(--dark-color); }

/* Foto preview */
.foto-preview-wrap {
    width: 100%;
    height: 160px;
    border: 2px dashed var(--gray-300);
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    position: relative;
    background: var(--gray-100);
    transition: border-color .2s;
}
.foto-preview-wrap:hover { border-color: var(--primary-color); }
.foto-preview-wrap img { width: 100%; height: 100%; object-fit: cover; }
.foto-preview-wrap .foto-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,102,204,.7);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #fff;
    opacity: 0;
    transition: opacity .2s;
}
.foto-preview-wrap:hover .foto-overlay { opacity: 1; }
.foto-preview-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    color: var(--gray-600);
    gap: 8px;
}
.foto-preview-placeholder i { font-size: 36px; }

/* Histórico preventivas */
.prev-history-item {
    border-left: 3px solid var(--success-color);
    padding: 10px 14px;
    margin-bottom: 10px;
    background: #f8f9fa;
    border-radius: 0 var(--border-radius) var(--border-radius) 0;
}
.prev-history-item .prev-date { font-weight: 700; color: var(--primary-color); }
.prev-history-item .prev-desc { color: var(--gray-800); margin-top: 4px; }
.prev-history-item .prev-meta { font-size: 12px; color: var(--gray-600); margin-top: 4px; }

/* Tabs dentro do painel de edição */
.edit-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--gray-300); margin-bottom: 20px; }
.edit-tab {
    padding: 10px 20px;
    cursor: pointer;
    border: none;
    background: none;
    font-size: 14px;
    color: var(--gray-600);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all .2s;
}
.edit-tab.active { color: var(--primary-color); border-bottom-color: var(--primary-color); font-weight: 600; }
.edit-tab:hover { color: var(--primary-color); }
.edit-tab-content { display: none; }
.edit-tab-content.active { display: block; }

/* Quantidade badge */
.qty-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    background: var(--primary-color);
    color: #fff;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 700;
}
/* tabs-navigation, tab-link: definidos em assets/css/style.css */
.tab-link.active {
    color: #007bff;
    border-bottom-color: #007bff;
    font-weight: bold;
}
</style>

<!-- ── Conteúdo ────────────────────────────────────────────────────────────── -->
<div class="container-fluid">

    <!-- Cabeçalho da página -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-archive"></i> Inventário / Patrimônio</h1>
            <p class="text-muted">Gestão de bens patrimoniais, imobilizados e ativos</p>
        </div>
        <?php if (!$pat_editar): ?>
        <button type="button" class="btn btn-primary" id="btnNovoPatrimonio" onclick="abrirPainelCadastro()">
            <i class="fas fa-plus-circle"></i> Novo Patrimônio
        </button>
        <?php endif; ?>
    </div>

    <!-- Abas de navegação do módulo Estoque -->
    <div class="tabs-navigation">
        <a href="estoque_produtos.php"      class="tab-link"><i class="fas fa-box"></i> Produtos</a>
        <a href="estoque_visao.php"         class="tab-link"><i class="fas fa-warehouse"></i> Estoque</a>
        <a href="estoque_movimentacoes.php" class="tab-link"><i class="fas fa-exchange-alt"></i> Movimentações</a>
        <a href="estoque_relatorios.php"    class="tab-link"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="estoque_inventario.php"    class="tab-link active"><i class="fas fa-archive"></i> Inventário</a>
        <a href="estoque_pedidos.php" class="tab-link">
            <i class="fas fa-shopping-bag"></i> Pedidos
        </a>
    </div>

    <!-- Alertas -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="fas fa-check-circle"></i> <?= $success ?>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    </div>
    <?php endif; ?>

    <!-- Cards de estatísticas -->
    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-archive"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
                <div class="stat-label">Total de Patrimônios</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-building"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stats['total_imobilizado'] ?? 0) ?></div>
                <div class="stat-label">Imobilizados</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stats['total_ativos'] ?? 0) ?></div>
                <div class="stat-label">Em Uso</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-tools"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stats['total_manutencao'] ?? 0) ?></div>
                <div class="stat-label">Em Manutenção</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stats['preventivas_vencidas'] ?? 0) ?></div>
                <div class="stat-label">Preventivas Vencidas</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon teal"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-info">
                <div class="stat-value">R$ <?= number_format($stats['valor_total'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label">Valor Total</div>
            </div>
        </div>
    </div>
    <!-- ── Painel de Cadastro / Edição Inline ────────────────────────────────────── -->
    <div class="edit-panel" id="painelCadastro" <?php if (!$pat_editar): ?>style="display:none"<?php endif; ?>>
    <div class="edit-panel-header">
            <h3>
                <?php if ($pat_editar): ?>
                    <i class="fas fa-edit"></i> Editar Patrimônio — <?= htmlspecialchars($pat_editar['numero_pat']) ?>
                <?php else: ?>
                    <i class="fas fa-plus-circle"></i> Novo Patrimônio
                <?php endif; ?>
            </h3>
            <?php if ($pat_editar): ?>
            <a href="estoque_inventario.php" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);">
                <i class="fas fa-times"></i> Cancelar Edição
            </a>
            <?php else: ?>
            <button type="button" onclick="togglePainel()" id="btnTogglePainel"
                    style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;">
                <i class="fas fa-times"></i> Fechar
            </button>
            <?php endif; ?>
        </div>

        <div class="edit-panel-body" id="painelBody">
            <!-- Tabs do formulário -->
            <div class="edit-tabs">
                <button class="edit-tab active" onclick="mudarTab(this,'tab-dados')"><i class="fas fa-info-circle"></i> Dados Gerais</button>
                <button class="edit-tab" onclick="mudarTab(this,'tab-financeiro')"><i class="fas fa-dollar-sign"></i> Financeiro / NF</button>
                <button class="edit-tab" onclick="mudarTab(this,'tab-preventiva')"><i class="fas fa-tools"></i> Preventiva</button>
                <?php if ($pat_editar): ?>
                <button class="edit-tab" onclick="mudarTab(this,'tab-historico')"><i class="fas fa-history"></i> Histórico</button>
                <?php endif; ?>
            </div>

            <form method="POST" enctype="multipart/form-data" id="formPatrimonio">
                <input type="hidden" name="action" value="<?= $pat_editar ? 'atualizar' : 'criar' ?>">
                <?php if ($pat_editar): ?>
                <input type="hidden" name="id" value="<?= $pat_editar['id'] ?>">
                <?php endif; ?>

                <!-- ── Tab: Dados Gerais ──────────────────────────────────── -->
                <div class="edit-tab-content active" id="tab-dados">
                    <div class="row g-3">

                        <!-- Foto -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-camera"></i> Foto do Patrimônio</label>
                            <div class="foto-preview-wrap" onclick="document.getElementById('inputFoto').click()">
                                <?php if ($pat_editar && $pat_editar['foto']): ?>
                                <img src="<?= SITE_URL . '/' . htmlspecialchars($pat_editar['foto']) ?>" id="fotoPreviewImg" alt="Foto">
                                <?php else: ?>
                                <div class="foto-preview-placeholder" id="fotoPlaceholder">
                                    <i class="fas fa-camera"></i>
                                    <span style="font-size:12px">Clique para adicionar foto</span>
                                </div>
                                <img src="" id="fotoPreviewImg" alt="Foto" style="display:none;width:100%;height:100%;object-fit:cover;">
                                <?php endif; ?>
                                <div class="foto-overlay">
                                    <i class="fas fa-camera" style="font-size:28px"></i>
                                    <span style="font-size:12px;margin-top:6px">Alterar foto</span>
                                </div>
                            </div>
                            <input type="file" id="inputFoto" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
                            <div style="font-size:11px;color:var(--gray-600);margin-top:4px;text-align:center">JPG, PNG, WEBP</div>
                        </div>

                        <!-- Campos principais -->
                        <div class="col-md-9">
                            <div class="row g-3">

                                <div class="col-md-8">
                                    <label class="form-label required">Descrição do Bem</label>
                                    <input type="text" name="descricao" class="form-control"
                                           placeholder="Ex: Notebook Dell Inspiron 15"
                                           value="<?= htmlspecialchars($pat_editar['descricao'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label required">Classificação</label>
                                    <select name="classificacao" class="form-select" required>
                                        <option value="ativo"       <?= ($pat_editar['classificacao'] ?? 'ativo') === 'ativo'       ? 'selected' : '' ?>>Ativo</option>
                                        <option value="imobilizado" <?= ($pat_editar['classificacao'] ?? '')       === 'imobilizado' ? 'selected' : '' ?>>Imobilizado</option>
                                    </select>
                                    <div style="font-size:11px;color:var(--gray-600);margin-top:3px">
                                        <b>Ativo</b> = circulante &nbsp;|&nbsp; <b>Imobilizado</b> = bem fixo
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Categoria</label>
                                    <input type="text" name="categoria" class="form-control" list="lista-categorias"
                                           placeholder="Ex: Equipamento, Móvel..."
                                           value="<?= htmlspecialchars($pat_editar['categoria'] ?? '') ?>">
                                    <datalist id="lista-categorias">
                                        <option value="Equipamento">
                                        <option value="Móvel">
                                        <option value="Informática">
                                        <option value="Veículo">
                                        <option value="Ferramenta">
                                        <option value="Eletrodoméstico">
                                        <option value="Infraestrutura">
                                        <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Marca</label>
                                    <input type="text" name="marca" class="form-control"
                                           placeholder="Ex: Dell, Samsung..."
                                           value="<?= htmlspecialchars($pat_editar['marca'] ?? '') ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Modelo</label>
                                    <input type="text" name="modelo" class="form-control"
                                           placeholder="Ex: Inspiron 15 3000"
                                           value="<?= htmlspecialchars($pat_editar['modelo'] ?? '') ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Número de Série</label>
                                    <input type="text" name="numero_serie" class="form-control"
                                           placeholder="S/N do fabricante"
                                           value="<?= htmlspecialchars($pat_editar['numero_serie'] ?? '') ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Localização</label>
                                    <input type="text" name="localizacao" class="form-control"
                                           placeholder="Ex: Sala 3, Depósito..."
                                           value="<?= htmlspecialchars($pat_editar['localizacao'] ?? '') ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Responsável</label>
                                    <input type="text" name="responsavel" class="form-control"
                                           placeholder="Nome do responsável"
                                           value="<?= htmlspecialchars($pat_editar['responsavel'] ?? '') ?>">
                                </div>

                            </div>
                        </div>

                        <!-- Quantidade (apenas no cadastro) -->
                        <?php if (!$pat_editar): ?>
                        <div class="col-12">
                            <div class="card" style="border-left:4px solid var(--secondary-color);background:#fffbf0;">
                                <div class="card-body py-3">
                                    <div class="row align-items-center g-3">
                                        <div class="col-auto">
                                            <i class="fas fa-layer-group" style="font-size:28px;color:var(--secondary-color)"></i>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold mb-1">Quantidade de Unidades</label>
                                            <input type="number" name="quantidade" id="inputQtd" class="form-control"
                                                   value="1" min="1" max="999" onchange="atualizarPreviewPat(this.value)">
                                        </div>
                                        <div class="col">
                                            <div id="previewPat" style="font-size:13px;color:var(--gray-600);">
                                                <i class="fas fa-info-circle"></i>
                                                Será gerado <strong>1 patrimônio</strong> com número sequencial automático.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="ativo"          <?= ($pat_editar['status'] ?? 'ativo') === 'ativo'          ? 'selected' : '' ?>>Ativo</option>
                                <option value="inativo"        <?= ($pat_editar['status'] ?? '')       === 'inativo'        ? 'selected' : '' ?>>Inativo</option>
                                <option value="em_manutencao"  <?= ($pat_editar['status'] ?? '')       === 'em_manutencao'  ? 'selected' : '' ?>>Em Manutenção</option>
                                <option value="baixado"        <?= ($pat_editar['status'] ?? '')       === 'baixado'        ? 'selected' : '' ?>>Baixado</option>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"
                                      placeholder="Observações adicionais..."><?= htmlspecialchars($pat_editar['observacoes'] ?? '') ?></textarea>
                        </div>

                    </div>
                </div>

                <!-- ── Tab: Financeiro / NF ───────────────────────────────── -->
                <div class="edit-tab-content" id="tab-financeiro">
                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Valor de Compra (R$)</label>
                            <input type="text" name="valor_compra" class="form-control money-mask"
                                   placeholder="0,00"
                                   value="<?= $pat_editar ? number_format((float)$pat_editar['valor_compra'], 2, ',', '.') : '' ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Data de Compra</label>
                            <input type="date" name="data_compra" class="form-control"
                                   value="<?= htmlspecialchars($pat_editar['data_compra'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Fornecedor</label>
                            <input type="text" name="fornecedor" class="form-control"
                                   placeholder="Nome do fornecedor"
                                   value="<?= htmlspecialchars($pat_editar['fornecedor'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-file-invoice"></i> Número da NF</label>
                            <input type="text" name="numero_nf" class="form-control"
                                   placeholder="Ex: 000123456"
                                   value="<?= htmlspecialchars($pat_editar['numero_nf'] ?? '') ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label"><i class="fas fa-file-upload"></i> Importar Arquivo da NF</label>
                            <div style="border:2px dashed var(--gray-300);border-radius:var(--border-radius);padding:16px;background:var(--gray-100);">
                                <input type="file" name="arquivo_nf" class="form-control" accept=".pdf,.jpg,.jpeg,.png"
                                       onchange="previewNF(this)">
                                <div style="font-size:11px;color:var(--gray-600);margin-top:6px">
                                    <i class="fas fa-info-circle"></i> Aceito: PDF, JPG, PNG (máx. 10MB)
                                </div>
                                <?php if ($pat_editar && $pat_editar['arquivo_nf']): ?>
                                <div style="margin-top:8px;">
                                    <i class="fas fa-file-alt" style="color:var(--primary-color)"></i>
                                    <a href="<?= SITE_URL . '/' . htmlspecialchars($pat_editar['arquivo_nf']) ?>" target="_blank" style="font-size:13px;">
                                        Ver NF atual
                                    </a>
                                </div>
                                <?php endif; ?>
                                <div id="nfPreview" style="margin-top:8px;display:none;font-size:13px;color:var(--success-color);">
                                    <i class="fas fa-check-circle"></i> <span id="nfFileName"></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Tab: Preventiva ────────────────────────────────────── -->
                <div class="edit-tab-content" id="tab-preventiva">
                    <div class="row g-3">

                        <div class="col-12">
                            <div class="form-check form-switch" style="font-size:15px;">
                                <input class="form-check-input" type="checkbox" id="checkPreventiva" name="tem_preventiva"
                                       value="1" onchange="togglePreventiva(this)"
                                       <?= ($pat_editar['tem_preventiva'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="checkPreventiva">
                                    <i class="fas fa-tools"></i> Este patrimônio possui manutenção preventiva programada
                                </label>
                            </div>
                        </div>

                        <div id="camposPreventiva" style="<?= ($pat_editar['tem_preventiva'] ?? 0) ? '' : 'display:none' ?>;width:100%">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Periodicidade</label>
                                    <select name="periodicidade_preventiva" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php
                                        $periodos = ['mensal'=>'Mensal','bimestral'=>'Bimestral','trimestral'=>'Trimestral','semestral'=>'Semestral','anual'=>'Anual'];
                                        foreach ($periodos as $val => $label):
                                        ?>
                                        <option value="<?= $val ?>" <?= ($pat_editar['periodicidade_preventiva'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($pat_editar): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Próxima Preventiva</label>
                                    <input type="date" name="proxima_preventiva_manual" class="form-control"
                                           value="<?= htmlspecialchars($pat_editar['proxima_preventiva'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Última Preventiva</label>
                                    <input type="date" class="form-control" disabled
                                           value="<?= htmlspecialchars($pat_editar['ultima_preventiva'] ?? '') ?>">
                                </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <div class="alert" style="background:#e3f0ff;border:none;color:var(--primary-color);">
                                        <i class="fas fa-info-circle"></i>
                                        A data da próxima preventiva será calculada automaticamente a partir da data de compra e da periodicidade selecionada.
                                        <?php if ($pat_editar && $pat_editar['proxima_preventiva']): ?>
                                        <br><strong>Próxima:</strong> <?= date('d/m/Y', strtotime($pat_editar['proxima_preventiva'])) ?>
                                        <?php if (strtotime($pat_editar['proxima_preventiva']) < time()): ?>
                                        <span class="badge" style="background:#fdecea;color:var(--danger-color);margin-left:8px;"><i class="fas fa-exclamation-triangle"></i> VENCIDA</span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Registrar nova preventiva (apenas na edição) -->
                        <?php if ($pat_editar && $pat_editar['tem_preventiva']): ?>
                        <div class="col-12">
                            <hr>
                            <h5 style="color:var(--primary-color);"><i class="fas fa-plus-circle"></i> Registrar Manutenção Realizada</h5>
                            <form method="POST" id="formPreventiva">
                                <input type="hidden" name="action" value="registrar_preventiva">
                                <input type="hidden" name="patrimonio_id" value="<?= $pat_editar['id'] ?>">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label required">Data Realizada</label>
                                        <input type="date" name="data_realizada" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Técnico Responsável</label>
                                        <input type="text" name="tecnico" class="form-control" placeholder="Nome do técnico">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Custo (R$)</label>
                                        <input type="text" name="custo" class="form-control money-mask" placeholder="0,00">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Próxima Preventiva</label>
                                        <input type="date" name="proxima_data" class="form-control">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">O que foi feito</label>
                                        <textarea name="descricao" class="form-control" rows="2" placeholder="Descreva a manutenção realizada..."></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Observações</label>
                                        <textarea name="observacoes" class="form-control" rows="2" placeholder="Observações adicionais..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-save"></i> Registrar Preventiva
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- ── Tab: Histórico de Preventivas ─────────────────────── -->
                <?php if ($pat_editar): ?>
                <div class="edit-tab-content" id="tab-historico">
                    <?php if (empty($prev_editar)): ?>
                    <div style="text-align:center;padding:30px;color:var(--gray-600);">
                        <i class="fas fa-tools" style="font-size:36px;opacity:.4;"></i>
                        <p style="margin-top:12px;">Nenhuma manutenção preventiva registrada ainda.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($prev_editar as $prev): ?>
                    <div class="prev-history-item">
                        <div class="prev-date">
                            <i class="fas fa-calendar-check"></i>
                            <?= date('d/m/Y', strtotime($prev['data_realizada'])) ?>
                            <?php if ($prev['custo'] > 0): ?>
                            <span style="margin-left:12px;font-size:12px;background:#e6f9ee;color:var(--success-color);padding:2px 8px;border-radius:20px;">
                                R$ <?= number_format($prev['custo'], 2, ',', '.') ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($prev['descricao']): ?>
                        <div class="prev-desc"><?= htmlspecialchars($prev['descricao']) ?></div>
                        <?php endif; ?>
                        <div class="prev-meta">
                            <?php if ($prev['tecnico']): ?>
                            <i class="fas fa-user-cog"></i> <?= htmlspecialchars($prev['tecnico']) ?> &nbsp;
                            <?php endif; ?>
                            <?php if ($prev['proxima_data']): ?>
                            <i class="fas fa-calendar-alt"></i> Próxima: <?= date('d/m/Y', strtotime($prev['proxima_data'])) ?> &nbsp;
                            <?php endif; ?>
                            <?php if ($prev['registrado_nome']): ?>
                            <i class="fas fa-user"></i> Registrado por: <?= htmlspecialchars($prev['registrado_nome']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Botões de ação do formulário principal -->
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--gray-300);display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $pat_editar ? 'Salvar Alterações' : 'Cadastrar Patrimônio' ?>
                    </button>
                    <?php if ($pat_editar): ?>
                    <a href="estoque_inventario.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <?php else: ?>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-eraser"></i> Limpar
                    </button>
                    <?php endif; ?>
                </div>

            </form>
        </div><!-- /edit-panel-body -->
    </div><!-- /edit-panel -->

    <!-- ── Listagem de Patrimônios ─────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h5 style="margin:0;"><i class="fas fa-list"></i> Patrimônios Cadastrados</h5>
            <span style="font-size:13px;color:var(--gray-600);"><?= count($patrimonios) ?> registro(s)</span>
        </div>

        <!-- Filtros -->
        <div class="card-body" style="border-bottom:1px solid var(--gray-300);">
            <form method="GET" id="formFiltrosInventario">
                <div class="filter-grid">
                    <div class="filter-item filter-item-wide">
                        <label class="filter-label">Busca</label>
                        <input type="text" name="busca" class="form-control"
                               placeholder="Buscar por PAT, descrição, série, NF..."
                               value="<?= htmlspecialchars($filtros['busca']) ?>">
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Classificação</label>
                        <select name="classificacao" class="form-control">
                            <option value="">Todas</option>
                            <option value="ativo"       <?= $filtros['classificacao'] === 'ativo'       ? 'selected' : '' ?>>Ativo</option>
                            <option value="imobilizado" <?= $filtros['classificacao'] === 'imobilizado' ? 'selected' : '' ?>>Imobilizado</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">Todos</option>
                            <option value="ativo"         <?= $filtros['status'] === 'ativo'         ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo"       <?= $filtros['status'] === 'inativo'       ? 'selected' : '' ?>>Inativo</option>
                            <option value="em_manutencao" <?= $filtros['status'] === 'em_manutencao' ? 'selected' : '' ?>>Em Manutenção</option>
                            <option value="baixado"       <?= $filtros['status'] === 'baixado'       ? 'selected' : '' ?>>Baixado</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Categoria</label>
                        <select name="categoria" class="form-control">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $filtros['categoria'] === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label class="filter-label">Preventiva</label>
                        <select name="tem_preventiva" class="form-control">
                            <option value="">Todas</option>
                            <option value="1" <?= $filtros['tem_preventiva'] === '1' ? 'selected' : '' ?>>Com preventiva</option>
                            <option value="0" <?= $filtros['tem_preventiva'] === '0' ? 'selected' : '' ?>>Sem preventiva</option>
                        </select>
                    </div>
                    <div class="filter-item filter-item-btn">
                        <label class="filter-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                            <?php if (array_filter($filtros)): ?>
                            <a href="estoque_inventario.php" class="btn btn-outline-secondary" title="Limpar"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tabela -->
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <?php if (empty($patrimonios)): ?>
            <div style="text-align:center;padding:48px;color:var(--gray-600);">
                <i class="fas fa-archive" style="font-size:48px;opacity:.3;"></i>
                <p style="margin-top:16px;font-size:16px;">Nenhum patrimônio cadastrado ainda.</p>
                <p style="font-size:13px;">Use o formulário acima para cadastrar o primeiro patrimônio.</p>
            </div>
            <?php else: ?>
            <table class="table table-hover table-patrimonio" style="margin:0;">
                <thead style="background:var(--gray-100);">
                    <tr>
                        <th style="width:50px;">Foto</th>
                        <th>Nº PAT</th>
                        <th>Descrição</th>
                        <th>Classificação</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>NF</th>
                        <th>Preventiva</th>
                        <th>Status</th>
                        <th style="width:100px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($patrimonios as $p): ?>
                <?php
                    // Classe de status
                    $status_class = match($p['status']) {
                        'ativo'         => 'badge-status-ativo',
                        'inativo'       => 'badge-status-inativo',
                        'em_manutencao' => 'badge-status-manutencao',
                        'baixado'       => 'badge-status-baixado',
                        default         => 'badge-status-inativo',
                    };
                    $status_label = match($p['status']) {
                        'ativo'         => 'Ativo',
                        'inativo'       => 'Inativo',
                        'em_manutencao' => 'Manutenção',
                        'baixado'       => 'Baixado',
                        default         => $p['status'],
                    };
                    // Preventiva
                    $prev_class = 'preventiva-sem';
                    $prev_label = 'Sem preventiva';
                    $prev_icon  = 'fa-minus';
                    if ($p['tem_preventiva']) {
                        if ($p['proxima_preventiva'] && strtotime($p['proxima_preventiva']) < time()) {
                            $prev_class = 'preventiva-vencida';
                            $prev_label = 'Vencida: ' . date('d/m/Y', strtotime($p['proxima_preventiva']));
                            $prev_icon  = 'fa-exclamation-triangle';
                        } else {
                            $prev_class = 'preventiva-ok';
                            $prev_label = $p['proxima_preventiva'] ? 'Próx: ' . date('d/m/Y', strtotime($p['proxima_preventiva'])) : 'Programada';
                            $prev_icon  = 'fa-check';
                        }
                    }
                ?>
                <tr>
                    <td>
                        <?php if ($p['foto']): ?>
                        <img src="<?= SITE_URL . '/' . htmlspecialchars($p['foto']) ?>"
                             class="foto-thumb"
                             onclick="abrirFoto('<?= SITE_URL . '/' . htmlspecialchars($p['foto']) ?>')"
                             alt="Foto">
                        <?php else: ?>
                        <div class="foto-placeholder"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pat-number"><?= htmlspecialchars($p['numero_pat']) ?></span>
                        <?php if ($p['quantidade_lote'] > 1): ?>
                        <br><small style="color:var(--gray-600);">
                            <?= $p['sequencia_lote'] ?>/<?= $p['quantidade_lote'] ?> do lote
                        </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($p['descricao']) ?></strong>
                        <?php if ($p['marca'] || $p['modelo']): ?>
                        <br><small style="color:var(--gray-600);">
                            <?= htmlspecialchars(trim(($p['marca'] ?? '') . ' ' . ($p['modelo'] ?? ''))) ?>
                        </small>
                        <?php endif; ?>
                        <?php if ($p['numero_serie']): ?>
                        <br><small style="color:var(--gray-600);">S/N: <?= htmlspecialchars($p['numero_serie']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $p['classificacao'] === 'imobilizado' ? 'badge-imobilizado' : 'badge-ativo-class' ?>">
                            <?= $p['classificacao'] === 'imobilizado' ? 'Imobilizado' : 'Ativo' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($p['categoria'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['valor_compra'] > 0): ?>
                        R$ <?= number_format($p['valor_compra'], 2, ',', '.') ?>
                        <?php else: ?>
                        <span style="color:var(--gray-600)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['numero_nf']): ?>
                        <span title="NF: <?= htmlspecialchars($p['numero_nf']) ?>">
                            <?php if ($p['arquivo_nf']): ?>
                            <a href="<?= SITE_URL . '/' . htmlspecialchars($p['arquivo_nf']) ?>" target="_blank"
                               style="color:var(--primary-color);">
                                <i class="fas fa-file-alt"></i> <?= htmlspecialchars($p['numero_nf']) ?>
                            </a>
                            <?php else: ?>
                            <i class="fas fa-file-alt" style="color:var(--gray-600)"></i> <?= htmlspecialchars($p['numero_nf']) ?>
                            <?php endif; ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--gray-600)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="preventiva-badge <?= $prev_class ?>">
                            <i class="fas <?= $prev_icon ?>"></i> <?= $prev_label ?>
                        </span>
                    </td>
                    <td><span class="badge <?= $status_class ?>"><?= $status_label ?></span></td>
                    <td>
                        <a href="estoque_inventario.php?editar=<?= $p['id'] ?>"
                           class="btn btn-sm btn-primary" title="Editar" style="padding:4px 8px;">
                            <i class="fas fa-edit"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-danger" title="Excluir"
                                style="padding:4px 8px;"
                                onclick="confirmarExclusao(<?= $p['id'] ?>, '<?= htmlspecialchars($p['numero_pat']) ?>')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div><!-- /card-body tabela -->
    </div><!-- /card listagem -->

</div><!-- /container-fluid -->

<!-- ── Modal Foto Ampliada ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalFoto" onclick="fecharModalFoto()">
    <div style="max-width:90vw;max-height:90vh;position:relative;" onclick="event.stopPropagation()">
        <button onclick="fecharModalFoto()" style="position:absolute;top:-14px;right:-14px;background:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:18px;box-shadow:0 2px 8px rgba(0,0,0,.3);">×</button>
        <img id="modalFotoImg" src="" alt="Foto do patrimônio"
             style="max-width:90vw;max-height:90vh;border-radius:var(--border-radius);box-shadow:0 10px 40px rgba(0,0,0,.4);">
    </div>
</div>

<!-- ── Modal Confirmação Exclusão ─────────────────────────────────────────── -->
<div class="modal-overlay" id="modalExclusao">
    <div class="modal-box" style="max-width:420px;" onclick="event.stopPropagation()">
        <div class="modal-box-header">
            <h4><i class="fas fa-trash" style="color:var(--danger-color)"></i> Confirmar Exclusão</h4>
            <button class="btn-close-modal" onclick="fecharModalExclusao()">×</button>
        </div>
        <div class="modal-box-body">
            <p>Tem certeza que deseja excluir o patrimônio <strong id="excluirPat"></strong>?</p>
            <p style="color:var(--danger-color);font-size:13px;"><i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita.</p>
        </div>
        <div class="modal-box-footer">
            <form method="POST" id="formExcluir">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" id="excluirId">
                <button type="button" class="btn btn-secondary" onclick="fecharModalExclusao()">Cancelar</button>
                <button type="submit" class="btn btn-danger" style="margin-left:8px;">
                    <i class="fas fa-trash"></i> Excluir
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ── Scripts ────────────────────────────────────────────────────────────── -->
<script>
// ── Toggle painel de cadastro ─────────────────────────────────────────────
var painelAberto = false;

// Abre o painel de cadastro (chamado pelo botão "+ Novo Patrimônio")
function abrirPainelCadastro() {
    var painel = document.getElementById('painelCadastro');
    var btnNovo = document.getElementById('btnNovoPatrimonio');
    painel.style.display = 'block';
    painelAberto = true;
    if (btnNovo) btnNovo.style.display = 'none';
    painel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Fecha/recolhe o corpo do painel (botão "Recolher" dentro do header do painel)
function togglePainel() {
    var painel = document.getElementById('painelCadastro');
    var btnNovo = document.getElementById('btnNovoPatrimonio');
    // Fecha o painel inteiro
    painel.style.display = 'none';
    painelAberto = false;
    if (btnNovo) btnNovo.style.display = '';
}

// ── Tabs do formulário ────────────────────────────────────────────────────
function mudarTab(btn, tabId) {
    document.querySelectorAll('.edit-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.edit-tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

// ── Preview de foto ───────────────────────────────────────────────────────
function previewFoto(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('fotoPreviewImg');
            var ph  = document.getElementById('fotoPlaceholder');
            img.src = e.target.result;
            img.style.display = 'block';
            if (ph) ph.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Preview de NF ─────────────────────────────────────────────────────────
function previewNF(input) {
    if (input.files && input.files[0]) {
        document.getElementById('nfPreview').style.display = 'block';
        document.getElementById('nfFileName').textContent = input.files[0].name;
    }
}

// ── Toggle campos preventiva ──────────────────────────────────────────────
function togglePreventiva(cb) {
    document.getElementById('camposPreventiva').style.display = cb.checked ? '' : 'none';
}

// ── Preview quantidade PAT ────────────────────────────────────────────────
function atualizarPreviewPat(qty) {
    qty = parseInt(qty) || 1;
    var el = document.getElementById('previewPat');
    if (qty <= 1) {
        el.innerHTML = '<i class="fas fa-info-circle"></i> Será gerado <strong>1 patrimônio</strong> com número sequencial automático.';
    } else {
        el.innerHTML = '<i class="fas fa-layer-group" style="color:var(--secondary-color)"></i> Serão gerados <strong>' + qty + ' patrimônios</strong> com números PAT sequenciais automáticos (ex: PAT-0010, PAT-0011, ..., PAT-00' + (9 + qty) + ').';
    }
}

// ── Modal foto ampliada ───────────────────────────────────────────────────
function abrirFoto(src) {
    document.getElementById('modalFotoImg').src = src;
    document.getElementById('modalFoto').classList.add('active');
}
function fecharModalFoto() {
    document.getElementById('modalFoto').classList.remove('active');
}

// ── Modal exclusão ────────────────────────────────────────────────────────
function confirmarExclusao(id, pat) {
    document.getElementById('excluirId').value = id;
    document.getElementById('excluirPat').textContent = pat;
    document.getElementById('modalExclusao').classList.add('active');
}
function fecharModalExclusao() {
    document.getElementById('modalExclusao').classList.remove('active');
}

// ── Máscara monetária simples ─────────────────────────────────────────────
document.querySelectorAll('.money-mask').forEach(function(el) {
    el.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '');
        if (!v) { this.value = ''; return; }
        v = (parseInt(v, 10) / 100).toFixed(2);
        this.value = v.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});

// Fechar modais com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fecharModalFoto();
        fecharModalExclusao();
    }
});

// ── Auto-scroll para painel de edição se estiver editando ─────────────────
<?php if ($pat_editar): ?>
window.addEventListener('load', function() {
    document.getElementById('painelCadastro').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
