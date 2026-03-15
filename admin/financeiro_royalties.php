<?php
/**
 * ========================================
 * FINANCEIRO - ROYALTIES
 * Sistema de Gestão de Royalties
 * Versão: 4.0 - Reescrita Completa
 * Data: 2025-12-04
 * ========================================
 */

$page_title = 'Financeiro - Royalties';
$current_page = 'financeiro_royalties';

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/RoyaltiesManager.php';
require_once '../includes/EmailTemplate.php';

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

// ===== PROCESSAMENTO DE AÇÕES =====

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'criar') {
        $resultado = $royaltiesManager->criar($_POST);
        
        if ($resultado['success']) {
            $success = $resultado['message'];
            $_SESSION['royalty_criado_id'] = $resultado['royalty_id'];
        } else {
            $error = $resultado['message'];
        }
    }
}

// ===== BUSCAR DADOS PARA LISTAGEM =====

$filtros = [
    'estabelecimento_id' => $_GET['estabelecimento_id'] ?? null,
    'status' => $_GET['status'] ?? null,
    'data_inicial' => $_GET['data_inicial'] ?? null,
    'data_final' => $_GET['data_final'] ?? null
];

$royalties = $royaltiesManager->listar($filtros);

// Calcular totais
$total_pendente = 0;
$total_link_gerado = 0;
$total_enviado = 0;
$total_pago = 0;

foreach ($royalties as $r) {
    switch ($r['status']) {
        case 'pendente':
            $total_pendente += $r['valor_royalties'];
            break;
        case 'link_gerado':
            $total_link_gerado += $r['valor_royalties'];
            break;
        case 'enviado':
            $total_enviado += $r['valor_royalties'];
            break;
        case 'pago':
            $total_pago += $r['valor_royalties'];
            break;
    }
}

// Buscar estabelecimentos para dropdown
if (isAdminGeral()) {
    $stmt = $conn->query("SELECT id, name FROM estabelecimentos WHERE status = 1 ORDER BY name");
    $estabelecimentos = $stmt->fetchAll();
} else {
    $estabelecimento_id = getEstabelecimentoId();
    $stmt = $conn->prepare("SELECT id, name, email_alerta FROM estabelecimentos WHERE id = ?");
    $stmt->execute([$estabelecimento_id]);
    $estabelecimento_atual = $stmt->fetch();
}

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-header">
                <h1><i class="fas fa-coins"></i> Royalties</h1>
                <p class="text-muted">Gestão de cobranças de royalties</p>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Cards de Resumo -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-clock"></i> Pendentes</h5>
                    <h3>R$ <?= number_format($total_pendente, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-link"></i> Link Gerado</h5>
                    <h3>R$ <?= number_format($total_link_gerado, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-envelope"></i> Enviados</h5>
                    <h3>R$ <?= number_format($total_enviado, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-check"></i> Pagos</h5>
                    <h3>R$ <?= number_format($total_pago, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Botão Novo Lançamento -->
    <?php if (isAdminGeral()): ?>
    <div class="row mb-3">
        <div class="col-12">
            <button type="button" class="btn btn-primary" onclick="openModal('modalNovoRoyalty')">
                <i class="fas fa-plus"></i> Novo Lançamento
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-filter"></i> Filtros
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if (isAdminGeral()): ?>
                <div class="col-md-3">
                    <label class="form-label">Estabelecimento</label>
                    <select name="estabelecimento_id" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($estabelecimentos as $est): ?>
                        <option value="<?= $est['id'] ?>" <?= $filtros['estabelecimento_id'] == $est['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($est['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="pendente" <?= $filtros['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                        <option value="link_gerado" <?= $filtros['status'] === 'link_gerado' ? 'selected' : '' ?>>Link Gerado</option>
                        <option value="enviado" <?= $filtros['status'] === 'enviado' ? 'selected' : '' ?>>Enviado</option>
                        <option value="pago" <?= $filtros['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                        <option value="cancelado" <?= $filtros['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Data Inicial</label>
                    <input type="date" name="data_inicial" class="form-control" value="<?= $filtros['data_inicial'] ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Data Final</label>
                    <input type="date" name="data_final" class="form-control" value="<?= $filtros['data_final'] ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de Royalties -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Lançamentos de Royalties
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Estabelecimento</th>
                            <th>Período</th>
                            <th>Faturamento Bruto</th>
                            <th>Royalties (7%)</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($royalties)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <i class="fas fa-inbox"></i> Nenhum royalty encontrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($royalties as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['estabelecimento_nome']) ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($r['periodo_inicial'])) ?> a 
                                <?= date('d/m/Y', strtotime($r['periodo_final'])) ?>
                            </td>
                            <td>R$ <?= number_format($r['valor_faturamento_bruto'], 2, ',', '.') ?></td>
                            <td><strong>R$ <?= number_format($r['valor_royalties'], 2, ',', '.') ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($r['data_vencimento'])) ?></td>
                            <td><?php echo getStatusBadge($r['status']); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-info" onclick="visualizarRoyalty(<?= $r['id'] ?>)" title="Visualizar">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($r['status'] === 'pendente'): ?>
                                    <button class="btn btn-success" onclick="pagarRoyalty(<?= $r['id'] ?>)" title="Pagar">
                                        <i class="fas fa-credit-card"></i> Pagar
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($r['status'] === 'link_gerado' || $r['status'] === 'enviado'): ?>
                                    
                                    <?php if ($r['tipo_cobranca'] === 'cora' && !empty($r['boleto_url'])): ?>
                                    <button class="btn btn-warning" onclick="window.open('<?= htmlspecialchars($r['boleto_url']) ?>', '_blank')" title="Visualizar Boleto">
                                        <i class="fas fa-barcode"></i> Boleto
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-primary" onclick="reenviarEmail(<?= $r['id'] ?>)" title="Enviar/Reenviar E-mail">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($r['status'] !== 'pago' && $r['status'] !== 'cancelado'): ?>
                                    <button class="btn btn-danger" onclick="cancelarRoyalty(<?= $r['id'] ?>)" title="Cancelar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
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
</div>

<?php
// Função auxiliar para exibir badge de status
function getStatusBadge($status) {
    $badges = [
        'pendente' => '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pendente</span>',
        'link_gerado' => '<span class="badge bg-info"><i class="fas fa-link"></i> Link Gerado</span>',
        'enviado' => '<span class="badge bg-primary"><i class="fas fa-envelope"></i> Enviado</span>',
        'pago' => '<span class="badge bg-success"><i class="fas fa-check"></i> Pago</span>',
        'cancelado' => '<span class="badge bg-danger"><i class="fas fa-times"></i> Cancelado</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">' . $status . '</span>';
}
?>


<!-- Modal: Novo Lançamento de Royalty -->
<?php if (isAdminGeral()): ?>
<div id="modalNovoRoyalty" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Novo Lançamento de Royalty</h2>
            <span class="close" onclick="closeModal('modalNovoRoyalty')">&times;</span>
        </div>
        <div class="modal-body">
            <form id="formNovoRoyalty" method="POST" action="">
                <input type="hidden" name="action" value="criar">
                
                <div class="row">
                    <!-- Estabelecimento -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Estabelecimento</label>
                        <select name="estabelecimento_id" id="estabelecimento_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($estabelecimentos as $est): ?>
                            <option value="<?= $est['id'] ?>"><?= htmlspecialchars($est['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- CNPJ (preenchido automaticamente) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">CNPJ do Estabelecimento</label>
                        <input type="text" id="cnpj_estabelecimento" class="form-control" readonly 
                               placeholder="Selecione um estabelecimento">
                        <small class="text-muted">CNPJ preenchido automaticamente</small>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Período Inicial -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Período Inicial</label>
                        <input type="date" name="periodo_inicial" id="periodo_inicial" class="form-control" required>
                    </div>
                    
                    <!-- Período Final -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Período Final</label>
                        <input type="date" name="periodo_final" id="periodo_final" class="form-control" required>
                    </div>
                </div>
                
                <!-- Descrição -->
                <div class="mb-3">
                    <label class="form-label required">Descrição da Cobrança</label>
                    <textarea name="descricao" id="descricao" class="form-control" rows="2" required
                              placeholder="Ex: Royalties referente ao mês de Dezembro/2024"></textarea>
                </div>
                
                <div class="row">
                    <!-- Valor Faturamento Bruto -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Valor do Faturamento Bruto</label>
                        <input type="text" name="valor_faturamento_bruto" id="valor_faturamento_bruto" 
                               class="form-control money" required placeholder="R$ 0,00">
                    </div>
                    
                    <!-- Valor Royalties (calculado automaticamente) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Valor dos Royalties (7%)</label>
                        <input type="text" id="valor_royalties_display" class="form-control" readonly 
                               value="R$ 0,00" style="background: #e9ecef; font-weight: bold; color: #28a745;">
                        <small class="text-muted">Calculado automaticamente</small>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Forma de Pagamento (Apenas para referência) -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Forma de Pagamento</label>
                        <select name="forma_pagamento" id="forma_pagamento" class="form-select">
                            <option value="boleto_pix">Boleto + PIX</option>
                            <option value="cartao_pix">Cartão + PIX</option>
                            <option value="todos">Todos os métodos</option>
                        </select>
                    </div>
                </div>
                
                <!-- E-mail para Cobrança -->
                <div class="mb-3">
                    <label class="form-label required">E-mail para Cobrança</label>
                    <input type="email" name="email_cobranca" id="email_cobranca" class="form-control" required
                           placeholder="email@exemplo.com">
                    <small class="text-muted">Preenchido automaticamente do cadastro do estabelecimento</small>
                </div>
                
                <!-- E-mails Adicionais -->
                <div class="mb-3">
                    <label class="form-label">E-mails Adicionais (opcional)</label>
                    <textarea name="emails_adicionais" id="emails_adicionais" class="form-control" rows="2"
                              placeholder="email1@exemplo.com, email2@exemplo.com, email3@exemplo.com"></textarea>
                    <small class="text-muted">Separe múltiplos e-mails por vírgula</small>
                </div>
                
                <div class="row">
                    <!-- Data de Vencimento -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Data de Vencimento</label>
                        <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                        <small class="text-muted">Padrão: 30 dias após hoje</small>
                    </div>
                </div>
                
                <!-- Observações -->
                <div class="mb-3">
                    <label class="form-label">Observações (opcional)</label>
                    <textarea name="observacoes" id="observacoes" class="form-control" rows="2"
                              placeholder="Informações adicionais sobre esta cobrança"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalNovoRoyalty')">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Criar Royalty
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Conferência antes de Gerar Link -->
<div id="modalConferencia" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h2><i class="fas fa-clipboard-check"></i> Conferência - Gerar Link de Pagamento</h2>
            <span class="close" onclick="closeModal('modalConferencia')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="row">
                <!-- Coluna Esquerda: Dados Informados -->
                <div class="col-md-6">
                    <h4 class="mb-3"><i class="fas fa-info-circle"></i> Dados Informados</h4>
                    <div class="card">
                        <div class="card-body">
                            <div id="conferencia_dados"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Coluna Direita: Preview -->
                <div class="col-md-6">
                    <h4 class="mb-3"><i class="fas fa-eye"></i> Preview</h4>
                    
                    <!-- Preview do Link -->
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-link"></i> Link de Pagamento
                        </div>
                        <div class="card-body">
                            <p class="text-muted"><small>O link será gerado após confirmação</small></p>
                            <div class="alert alert-info">
                                <strong>🔗 Link Stripe</strong><br>
                                <span id="preview_link">https://buy.stripe.com/...</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preview do E-mail -->
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <i class="fas fa-envelope"></i> Preview do E-mail
                        </div>
                        <div class="card-body">
                            <p><strong>Assunto:</strong></p>
                            <p id="preview_assunto" class="text-muted"></p>
                            
                            <p><strong>Destinatários:</strong></p>
                            <p id="preview_destinatarios" class="text-muted"></p>
                            
                            <p><strong>Corpo:</strong></p>
                            <div id="preview_corpo" class="border p-3" style="max-height: 300px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer mt-4">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalConferencia')">
                    <i class="fas fa-arrow-left"></i> Voltar para Edição
                </button>
                <button type="button" class="btn btn-success" onclick="gerarLink()">
                    <i class="fas fa-link"></i> Gerar Link Stripe
                </button>
                <button type="button" class="btn btn-primary" onclick="gerarEEnviar()">
                    <i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Visualizar Royalty -->
<div id="modalVisualizarRoyalty" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice-dollar"></i> Detalhes do Royalty</h2>
            <span class="close" onclick="closeModal('modalVisualizarRoyalty')">&times;</span>
        </div>
        <div class="modal-body">
            <div id="visualizar_conteudo"></div>
        </div>
    </div>
</div>


<script>
// ===== VARIÁVEIS GLOBAIS =====
let royaltyAtual = null;

// ===== INICIALIZAÇÃO =====
document.addEventListener('DOMContentLoaded', function() {
    // Aplicar máscara de moeda
    aplicarMascaraMoeda();
    
    // Configurar data de vencimento padrão (30 dias)
    const dataVencimento = document.getElementById('data_vencimento');
    if (dataVencimento) {
        const hoje = new Date();
        hoje.setDate(hoje.getDate() + 30);
        dataVencimento.value = hoje.toISOString().split('T')[0];
    }
    
    // Event listeners
    const estabelecimentoSelect = document.getElementById('estabelecimento_id');
    if (estabelecimentoSelect) {
        estabelecimentoSelect.addEventListener('change', carregarDadosEstabelecimento);
    }
    
    const valorFaturamento = document.getElementById('valor_faturamento_bruto');
    if (valorFaturamento) {
        valorFaturamento.addEventListener('input', calcularRoyalties);
        valorFaturamento.addEventListener('blur', calcularRoyalties);
    }
    
    // Validação de período
    const periodoInicial = document.getElementById('periodo_inicial');
    const periodoFinal = document.getElementById('periodo_final');
    if (periodoInicial && periodoFinal) {
        periodoFinal.addEventListener('change', function() {
            if (periodoInicial.value && periodoFinal.value) {
                if (new Date(periodoFinal.value) < new Date(periodoInicial.value)) {
                    alert('O período final deve ser maior que o período inicial!');
                    periodoFinal.value = '';
                }
            }
        });
    }
});

// ===== CARREGAR DADOS DO ESTABELECIMENTO =====
function carregarDadosEstabelecimento() {
    const estabelecimentoId = document.getElementById('estabelecimento_id').value;
    
    if (!estabelecimentoId) {
        document.getElementById('cnpj_estabelecimento').value = '';
        document.getElementById('email_cobranca').value = '';
        return;
    }
    
    fetch('ajax/get_estabelecimento_email.php?id=' + estabelecimentoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cnpj_estabelecimento').value = data.cnpj || '';
                document.getElementById('email_cobranca').value = data.email || '';
            }
        })
        .catch(error => {
            console.error('Erro ao buscar dados do estabelecimento:', error);
        });
}

// ===== CALCULAR ROYALTIES (7%) =====
function calcularRoyalties() {
    const valorInput = document.getElementById('valor_faturamento_bruto');
    const valorDisplay = document.getElementById('valor_royalties_display');
    
    if (!valorInput || !valorDisplay) return;
    
    // Remover formatação e converter para número (usando parseBRToFloat para evitar bug de separador)
    let valor = parseFloat(parseBRToFloat(valorInput.value.replace(/[R$\s]/g, ''))) || 0;
    
    // Calcular 7%
    const royalties = valor * 0.07;
    
    // Exibir formatado
    valorDisplay.value = 'R$ ' + royalties.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ===== APLICAR MÁSCARA DE MOEDA =====
function aplicarMascaraMoeda() {
    const inputs = document.querySelectorAll('.money');
    inputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = (parseInt(value) / 100).toFixed(2);
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            e.target.value = 'R$ ' + value;
            
            // Calcular royalties se for o campo de faturamento
            if (e.target.id === 'valor_faturamento_bruto') {
                calcularRoyalties();
            }
        });
    });
}

// ===== CONFERIR ROYALTY (ABRIR MODAL DE CONFERÊNCIA) =====
function conferirRoyalty(id) {
    fetch('ajax/royalties_actions.php?action=buscar&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                royaltyAtual = data.royalty;
                preencherModalConferencia(data.royalty);
                openModal('modalConferencia');
            } else {
                alert('Erro ao buscar royalty: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao buscar dados do royalty');
        });
}

// ===== PREENCHER MODAL DE CONFERÊNCIA =====
function preencherModalConferencia(royalty) {
    // Dados informados
    const dadosHTML = `
        <table class="table table-bordered">
            <tr>
                <th>Estabelecimento:</th>
                <td>${royalty.estabelecimento_nome}</td>
            </tr>
            <tr>
                <th>Período:</th>
                <td>${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}</td>
            </tr>
            <tr>
                <th>Faturamento Bruto:</th>
                <td>R$ ${formatarValor(royalty.valor_faturamento_bruto)}</td>
            </tr>
            <tr>
                <th>Royalties (7%):</th>
                <td class="text-success"><strong>R$ ${formatarValor(royalty.valor_royalties)}</strong></td>
            </tr>
            <tr>
                <th>Data Vencimento:</th>
                <td>${formatarData(royalty.data_vencimento)}</td>
            </tr>
            <tr>
                <th>E-mail Principal:</th>
                <td>${royalty.email_cobranca}</td>
            </tr>
            ${royalty.emails_adicionais ? `
            <tr>
                <th>E-mails Adicionais:</th>
                <td>${royalty.emails_adicionais}</td>
            </tr>
            ` : ''}
            <tr>
                <th>Forma Pagamento:</th>
                <td>${formatarFormaPagamento(royalty.forma_pagamento)}</td>
            </tr>
        </table>
    `;
    document.getElementById('conferencia_dados').innerHTML = dadosHTML;
    
    // Preview do e-mail
    const assunto = `Cobrança de Royalties - ${royalty.estabelecimento_nome} - ${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}`;
    document.getElementById('preview_assunto').textContent = assunto;
    
    const destinatarios = royalty.emails_adicionais 
        ? `${royalty.email_cobranca}, ${royalty.emails_adicionais}`
        : royalty.email_cobranca;
    document.getElementById('preview_destinatarios').textContent = destinatarios;
    
    const corpoEmail = `
        <p>Prezado(a) <strong>${royalty.estabelecimento_nome}</strong>,</p>
        <p>Segue link para pagamento dos royalties referente ao período ${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}.</p>
        <ul>
            <li><strong>Valor:</strong> R$ ${formatarValor(royalty.valor_royalties)}</li>
            <li><strong>Vencimento:</strong> ${formatarData(royalty.data_vencimento)}</li>
            <li><strong>Forma de Pagamento:</strong> ${formatarFormaPagamento(royalty.forma_pagamento)}</li>
        </ul>
        <p><strong>Descrição:</strong> ${royalty.descricao}</p>
    `;
    document.getElementById('preview_corpo').innerHTML = corpoEmail;
}

// ===== GERAR LINK =====
function gerarLink() {
    if (!royaltyAtual) return;
    
    if (!confirm('Confirma a geração do link de pagamento via Stripe?')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=gerar_link&id=${royaltyAtual.id}`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Gerar Link Stripe';
        
        if (data.success) {
            alert('Link gerado com sucesso!');
            closeModal('modalConferencia');
            location.reload();
        } else {
            alert('Erro ao gerar link: ' + data.message);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Gerar Link Stripe';
        console.error('Erro:', error);
        alert('Erro ao gerar link');
    });
}

// ===== GERAR E ENVIAR TUDO =====
function gerarEEnviar() {
    if (!royaltyAtual) return;
    
    if (!confirm('Confirma a geração do link E envio do e-mail?')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=gerar_e_enviar&id=${royaltyAtual.id}`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo';
        
        if (data.success) {
            alert('Link gerado e e-mail enviado com sucesso!');
            closeModal('modalConferencia');
            location.reload();
        } else {
            alert('Erro: ' + data.message);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gerar & Enviar Tudo';
        console.error('Erro:', error);
        alert('Erro ao processar');
    });
}

// ===== REENVIAR E-MAIL =====
function reenviarEmail(id) {
    if (!confirm('Confirma o reenvio do e-mail de cobrança?')) {
        return;
    }
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=enviar_email&id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('E-mail enviado com sucesso!');
            location.reload();
        } else {
            alert('Erro ao enviar e-mail: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao enviar e-mail');
    });
}

// ===== VISUALIZAR ROYALTY =====
function visualizarRoyalty(id) {
    fetch('ajax/royalties_actions.php?action=buscar&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const royalty = data.royalty;
                const html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Informações Gerais</h5>
                            <table class="table table-bordered">
                                <tr><th>Estabelecimento:</th><td>${royalty.estabelecimento_nome}</td></tr>
                                <tr><th>CNPJ:</th><td>${royalty.cnpj || '-'}</td></tr>
                                <tr><th>Período:</th><td>${formatarData(royalty.periodo_inicial)} a ${formatarData(royalty.periodo_final)}</td></tr>
                                <tr><th>Descrição:</th><td>${royalty.descricao}</td></tr>
                                <tr><th>Status:</th><td>${royalty.status}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Valores</h5>
                            <table class="table table-bordered">
                                <tr><th>Faturamento Bruto:</th><td>R$ ${formatarValor(royalty.valor_faturamento_bruto)}</td></tr>
                                <tr><th>Royalties (7%):</th><td class="text-success"><strong>R$ ${formatarValor(royalty.valor_royalties)}</strong></td></tr>
                                <tr><th>Vencimento:</th><td>${formatarData(royalty.data_vencimento)}</td></tr>
                                <tr><th>Forma Pagamento:</th><td>${formatarFormaPagamento(royalty.forma_pagamento)}</td></tr>
                            </table>
                        </div>
                    </div>
                    ${royalty.payment_link_url ? `
                    <div class="alert alert-info mt-3">
                        <strong>🔗 Link de Pagamento:</strong><br>
                        <a href="${royalty.payment_link_url}" target="_blank">${royalty.payment_link_url}</a>
                    </div>
                    ` : ''}
                    ${royalty.observacoes ? `
                    <div class="mt-3">
                        <strong>Observações:</strong><br>
                        ${royalty.observacoes}
                    </div>
                    ` : ''}
                `;
                document.getElementById('visualizar_conteudo').innerHTML = html;
                openModal('modalVisualizarRoyalty');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao buscar dados');
        });
}

// ===== CANCELAR ROYALTY =====
function cancelarRoyalty(id) {
    if (!confirm('Tem certeza que deseja CANCELAR este royalty?')) {
        return;
    }
    
    fetch('ajax/royalties_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=cancelar&id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Royalty cancelado com sucesso!');
            location.reload();
        } else {
            alert('Erro ao cancelar: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao cancelar');
    });
}

// ===== FUNÇÃO PAGAR ROYALTY =====
function pagarRoyalty(id) {
    // Redirecionar para página de seleção de método de pagamento
    window.location.href = `royalty_selecionar_pagamento.php?id=${id}`;
}

// ===== FUNÇÕES AUXILIARES =====
function formatarData(data) {
    if (!data) return '-';
    const d = new Date(data + 'T00:00:00');
    return d.toLocaleDateString('pt-BR');
}

function formatarValor(valor) {
    return parseFloat(valor).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatarFormaPagamento(forma) {
    const formas = {
        'boleto_pix': 'Boleto + PIX',
        'cartao_pix': 'Cartão + PIX',
        'todos': 'Todos os métodos'
    };
    return formas[forma] || forma;
}
</script>

<?php require_once '../includes/footer.php'; ?>
