<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
requireAuth();

// Calcular tempo restante de sessão
$login_time       = $_SESSION['login_time'] ?? time();
$session_timeout  = SESSION_TIMEOUT; // 7200 segundos (2h)
$elapsed          = time() - $login_time;
$remaining        = max(0, $session_timeout - $elapsed);
$remaining_minutes = (int)floor($remaining / 60);
$remaining_seconds = (int)($remaining % 60);

// Definir estrutura do menu
$menu_structure = [
    'dashboard' => [
        'title' => 'Dashboard',
        'icon' => 'fas fa-chart-line',
        'url' => 'admin/dashboard.php',
        'page_key' => 'dashboard'
    ],
    'bebidas' => [
        'title' => 'Bebidas',
        'icon' => 'fas fa-beer',
        'url' => 'admin/bebidas.php',
        'page_key' => 'bebidas'
    ],
    'taps' => [
        'title' => 'TAPs',
        'icon' => 'fas fa-faucet',
        'url' => 'admin/taps.php',
        'page_key' => 'taps'
    ],
    'pagamentos' => [
        'title' => 'Pagamentos',
        'icon' => 'fas fa-credit-card',
        'url' => 'admin/pagamentos.php',
        'page_key' => 'pagamentos'
    ],
    'pedidos' => [
        'title' => 'Pedidos',
        'icon' => 'fas fa-shopping-cart',
        'url' => 'admin/pedidos.php',
        'page_key' => 'pedidos'
    ],
    'usuarios' => [
        'title' => 'Usuários',
        'icon' => 'fas fa-users',
        'url' => 'admin/usuarios.php',
        'page_key' => 'usuarios',
        'admin_only' => false
    ],
    'estabelecimentos' => [
        'title' => 'Estabelecimentos',
        'icon' => 'fas fa-store',
        'url' => 'admin/estabelecimentos.php',
        'page_key' => 'estabelecimentos',
        'admin_only' => false
    ],
    'promocoes' => [
        'title' => 'Promoções',
        'icon' => 'fas fa-tags',
        'url' => 'admin/promocoes.php',
        'page_key' => 'promocoes'
    ],
    'permissoes' => [
        'title' => 'Permissões',
        'icon' => 'fas fa-user-lock',
        'url' => 'admin/permissoes.php',
        'page_key' => 'permissoes',
        'admin_only' => true
    ],
    'logs' => [
        'title' => 'Logs',
        'icon' => 'fas fa-file-alt',
        'url' => 'admin/logs.php',
        'page_key' => 'logs',
        'admin_only' => true
    ],
    'estoque' => [
        'title' => 'Estoque',
        'icon' => 'fas fa-boxes',
        'submenu' => [
            'estoque_produtos' => [
                'title' => 'Produtos',
                'icon' => 'fas fa-box',
                'url' => 'admin/estoque_produtos.php',
                'page_key' => 'estoque_produtos'
            ],
            'estoque_visao' => [
                'title' => 'Visão Geral',
                'icon' => 'fas fa-warehouse',
                'url' => 'admin/estoque_visao.php',
                'page_key' => 'estoque_visao'
            ],
            'estoque_movimentacoes' => [
                'title' => 'Movimentações',
                'icon' => 'fas fa-exchange-alt',
                'url' => 'admin/estoque_movimentacoes.php',
                'page_key' => 'estoque_movimentacoes'
            ],
            'estoque_relatorios' => [
                'title' => 'Relatórios',
                'icon' => 'fas fa-chart-bar',
                'url' => 'admin/estoque_relatorios.php',
                'page_key' => 'estoque_relatorios'
            ]
        ]
    ],
    'financeiro' => [
        'title' => 'Financeiro',
        'icon' => 'fas fa-wallet',
        'submenu' => [
            'financeiro_taxas' => [
                'title' => 'Taxas de Juros',
                'icon' => 'fas fa-percentage',
                'url' => 'admin/financeiro_taxas.php',
                'page_key' => 'financeiro_taxas'
            ],
            'financeiro_contas' => [
                'title' => 'Contas a Pagar',
                'icon' => 'fas fa-file-invoice-dollar',
                'url' => 'admin/financeiro_contas.php',
                'page_key' => 'financeiro_contas'
            ],
            'financeiro_royalties' => [
                'title' => 'Royalties',
                'icon' => 'fas fa-crown',
                'url' => 'admin/financeiro_royalties.php',
                'page_key' => 'financeiro_royalties'
            ],
            'financeiro_faturamento' => [
                'title' => 'Faturamento',
                'icon' => 'fas fa-file-invoice',
                'url' => 'admin/financeiro_faturamento.php',
                'page_key' => 'financeiro_faturamento'
            ]
        ]
    ],
    'clientes' => [
        'title' => 'Clientes',
        'icon' => 'fas fa-users',
        'submenu' => [
            'clientes_lista' => [
                'title' => 'Lista de Clientes',
                'icon' => 'fas fa-list',
                'url' => 'admin/clientes.php',
                'page_key' => 'clientes'
            ],
            'cashback_regras' => [
                'title' => 'Regras de Cashback',
                'icon' => 'fas fa-coins',
                'url' => 'admin/cashback_regras.php',
                'page_key' => 'cashback'
            ]
        ]
    ],
    'integracoes' => [
        'title' => 'Integrações',
        'icon' => 'fas fa-plug',
        'admin_only' => true,
        'submenu' => [
            'email_config' => [
                'title' => 'Config. E-mail',
                'icon' => 'fas fa-envelope',
                'url' => 'admin/email_config.php',
                'page_key' => 'email_config'
            ],
            'telegram' => [
                'title' => 'Telegram',
                'icon' => 'fab fa-telegram',
                'url' => 'admin/telegram.php',
                'page_key' => 'telegram'
            ],
            'stripe_config' => [
                'title' => 'Stripe Pagamentos',
                'icon' => 'fab fa-stripe',
                'url' => 'admin/stripe_config.php',
                'page_key' => 'stripe_config'
            ],
            'cora_config' => [
                'title' => 'Banco Cora',
                'icon' => 'fas fa-university',
                'url' => 'admin/cora_config.php',
                'page_key' => 'cora_config'
            ],
            'mercadopago_config' => [
                'title' => 'Mercado Pago',
                'icon' => 'fab fa-cc-mastercard',
                'url' => 'admin/mercadopago_config.php',
                'page_key' => 'mercadopago_config'
            ],
            'asaas_config' => [
                'title' => 'Asaas',
                'icon' => 'fas fa-dollar-sign',
                'url' => 'admin/asaas_config.php',
                'page_key' => 'asaas_config'
            ]
        ]
    ]
];

// Buscar estabelecimentos do usuário para o menu QR (se não for Admin Geral)
$user_estabelecimentos = [];
if (!isAdminGeral()) {
    $conn_hdr = getDBConnection();
    $stmt_hdr = $conn_hdr->prepare("
        SELECT e.id, e.name
        FROM estabelecimentos e
        INNER JOIN user_estabelecimento ue ON e.id = ue.estabelecimento_id
        WHERE ue.user_id = ? AND ue.status = 1
        ORDER BY e.name ASC
    ");
    $stmt_hdr->execute([$_SESSION['user_id']]);
    $user_estabelecimentos = $stmt_hdr->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? SITE_NAME; ?> - <?php echo SITE_NAME; ?></title>
    
    <!-- Font Awesome para ícones profissionais -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php if (isset($extra_head)) echo $extra_head; ?>

    <style>
    /* ============================================================
       TOPBAR — Sessão de Tempo Restante
       ============================================================ */
    .session-timer {
        display: flex;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
        border: 1px solid #b8d9f5;
        border-radius: 20px;
        padding: 5px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #0066CC;
        white-space: nowrap;
        transition: all 0.3s ease;
    }
    .session-timer i {
        font-size: 13px;
    }
    .session-timer.warning {
        background: linear-gradient(135deg, #fff8e1 0%, #fff3cd 100%);
        border-color: #ffc107;
        color: #856404;
    }
    .session-timer.danger {
        background: linear-gradient(135deg, #fff5f5 0%, #ffe0e0 100%);
        border-color: #dc3545;
        color: #dc3545;
        animation: pulse-danger 1s infinite;
    }
    @keyframes pulse-danger {
        0%, 100% { opacity: 1; }
        50%       { opacity: 0.65; }
    }

    /* ============================================================
       TOPBAR — Perfil com Dropdown
       ============================================================ */
    .profile-wrapper {
        position: relative;
    }
    .user-info {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 8px;
        transition: background 0.2s ease;
        user-select: none;
    }
    .user-info:hover {
        background: #f0f4f8;
    }
    .user-info .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: var(--primary-color);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
        transition: box-shadow 0.2s;
    }
    .user-info:hover .user-avatar {
        box-shadow: 0 0 0 3px rgba(0,102,204,0.25);
    }
    .user-details .user-name {
        font-weight: 600;
        font-size: 14px;
        color: #343a40;
    }
    .user-details .user-role {
        font-size: 12px;
        color: #6c757d;
    }
    .profile-caret {
        font-size: 11px;
        color: #6c757d;
        margin-left: 2px;
        transition: transform 0.2s;
    }
    .profile-wrapper.open .profile-caret {
        transform: rotate(180deg);
    }

    /* Dropdown do perfil */
    .profile-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        min-width: 240px;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        z-index: 1050;
        overflow: hidden;
        animation: dropdown-in 0.18s ease;
    }
    @keyframes dropdown-in {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .profile-wrapper.open .profile-dropdown {
        display: block;
    }
    .profile-dropdown-header {
        padding: 14px 16px;
        background: linear-gradient(135deg, #0066CC 0%, #0052A3 100%);
        color: #fff;
    }
    .profile-dropdown-header .pd-name {
        font-weight: 700;
        font-size: 14px;
    }
    .profile-dropdown-header .pd-role {
        font-size: 12px;
        opacity: 0.85;
        margin-top: 2px;
    }
    .profile-dropdown-header .pd-session {
        font-size: 11px;
        margin-top: 6px;
        opacity: 0.9;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .profile-dropdown-divider {
        height: 1px;
        background: #e9ecef;
        margin: 4px 0;
    }
    .profile-dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        font-size: 13px;
        color: #343a40;
        text-decoration: none;
        cursor: pointer;
        transition: background 0.15s;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
    }
    .profile-dropdown-item:hover {
        background: #f0f4f8;
        color: #0066CC;
    }
    .profile-dropdown-item i {
        width: 18px;
        text-align: center;
        color: #6c757d;
        font-size: 14px;
    }
    .profile-dropdown-item:hover i {
        color: #0066CC;
    }
    .profile-dropdown-item.danger {
        color: #dc3545;
    }
    .profile-dropdown-item.danger i {
        color: #dc3545;
    }
    .profile-dropdown-item.danger:hover {
        background: #fff5f5;
    }
    .profile-dropdown-item .item-badge {
        margin-left: auto;
        background: #0066CC;
        color: #fff;
        font-size: 10px;
        padding: 2px 7px;
        border-radius: 10px;
        font-weight: 700;
    }

    /* ============================================================
       MODAL — Scanner QR Code (Menu Perfil)
       ============================================================ */
    #modalQrPerfil {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.72);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
    }
    #modalQrPerfil.active {
        display: flex;
    }
    .qrp-box {
        background: #fff;
        border-radius: 14px;
        width: 100%;
        max-width: 520px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        overflow: hidden;
        animation: modal-in 0.22s ease;
    }
    @keyframes modal-in {
        from { opacity: 0; transform: scale(0.93); }
        to   { opacity: 1; transform: scale(1); }
    }
    .qrp-header {
        background: linear-gradient(135deg, #0066CC 0%, #0052A3 100%);
        color: #fff;
        padding: 18px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .qrp-header h4 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .qrp-close {
        background: rgba(255,255,255,0.2);
        border: none;
        color: #fff;
        width: 30px; height: 30px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }
    .qrp-close:hover { background: rgba(255,255,255,0.35); }
    .qrp-body {
        padding: 20px 22px;
    }
    .qrp-desc {
        font-size: 13px;
        color: #555;
        margin-bottom: 16px;
        line-height: 1.6;
    }
    /* Seletor de estabelecimento */
    .qrp-estab-select {
        margin-bottom: 16px;
    }
    .qrp-estab-select label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #555;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .qrp-estab-select select {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        font-size: 13px;
        color: #343a40;
        background: #f8f9fa;
        outline: none;
        transition: border 0.2s;
    }
    .qrp-estab-select select:focus {
        border-color: #0066CC;
        background: #fff;
    }
    /* Vídeo scanner */
    .qrp-video-wrapper {
        position: relative;
        border-radius: 10px;
        overflow: hidden;
        background: #000;
        margin-bottom: 14px;
    }
    .qrp-video-wrapper video {
        width: 100%;
        display: block;
        border-radius: 10px;
    }
    .qrp-scanner-overlay {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }
    .qrp-scanner-frame {
        width: 180px; height: 180px;
        border: 3px solid rgba(255,255,255,0.85);
        border-radius: 12px;
        box-shadow: 0 0 0 9999px rgba(0,0,0,0.35);
    }
    .qrp-status {
        text-align: center;
        font-size: 13px;
        font-weight: 600;
        color: #E87722;
        padding: 6px 0;
        min-height: 28px;
    }
    .qrp-result {
        display: none;
        border-radius: 8px;
        padding: 12px 16px;
        font-size: 13px;
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .qrp-result.success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }
    .qrp-result.error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
    .qrp-result { display: none; }
    .qrp-btn-start {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #0066CC;
        color: #fff;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s, transform 0.1s;
        width: 100%;
        justify-content: center;
        margin-bottom: 10px;
    }
    .qrp-btn-start:hover { background: #0052A3; transform: translateY(-1px); }
    .qrp-btn-start:disabled { background: #6c757d; cursor: not-allowed; transform: none; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="<?php echo SITE_NAME; ?>">
            </div>
            <nav>
                <ul class="sidebar-menu">
                    <?php foreach ($menu_structure as $key => $item): ?>
                        <?php
                        // Verificar se é admin_only
                        $is_admin_only = isset($item['admin_only']) && $item['admin_only'];
                        
                        // Se for admin_only e usuário não for Admin Geral, pular
                        if ($is_admin_only && !isAdminGeral()) {
                            continue;
                        }
                        
                        // Se não for admin_only, verificar permissão
                        if (!$is_admin_only && isset($item['page_key']) && !hasPagePermission($item['page_key'], 'view')) {
                            continue;
                        }
                        ?>
                        
                        <?php if (isset($item['submenu'])): ?>
                            <!-- Item com submenu -->
                            <?php
                            $has_submenu_access = false;
                            $submenu_active = false;
                            foreach ($item['submenu'] as $sub_key => $sub_item) {
                                if (hasPagePermission($sub_item['page_key'], 'view')) {
                                    $has_submenu_access = true;
                                }
                                if (($current_page ?? '') == $sub_item['page_key']) {
                                    $submenu_active = true;
                                }
                            }
                            ?>
                            
                            <?php if ($has_submenu_access): ?>
                            <li class="menu-item-has-children <?php echo $submenu_active ? 'open' : ''; ?>">
                                <a href="#" class="<?php echo $submenu_active ? 'active' : ''; ?>" onclick="toggleSubmenu(event, this)">
                                    <i class="<?php echo $item['icon']; ?>"></i>
                                    <span><?php echo $item['title']; ?></span>
                                    <i class="fas fa-chevron-down arrow"></i>
                                </a>
                                <ul class="submenu <?php echo $submenu_active ? 'show' : ''; ?>">
                                    <?php foreach ($item['submenu'] as $sub_key => $sub_item): ?>
                                        <?php if (hasPagePermission($sub_item['page_key'], 'view')): ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>/<?php echo $sub_item['url']; ?>" 
                                               class="<?php echo ($current_page ?? '') == $sub_item['page_key'] ? 'active' : ''; ?>">
                                                <i class="<?php echo $sub_item['icon']; ?>"></i>
                                                <span><?php echo $sub_item['title']; ?></span>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Item simples -->
                            <li>
                                <a href="<?php echo SITE_URL; ?>/<?php echo $item['url']; ?>" 
                                   class="<?php echo ($current_page ?? '') == $item['page_key'] ? 'active' : ''; ?>">
                                    <i class="<?php echo $item['icon']; ?>"></i>
                                    <span><?php echo $item['title']; ?></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <li>
                        <a href="<?php echo SITE_URL; ?>/admin/logout.php" class="logout-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Sair</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2><?php echo $page_title ?? 'Dashboard'; ?></h2>
                </div>
                <div class="topbar-right">

                    <!-- ── Contador de Sessão ─────────────────────────── -->
                    <div class="session-timer" id="sessionTimer"
                         title="Tempo restante de sessão">
                        <i class="fas fa-clock"></i>
                        <span id="sessionTimerText">
                            <?php printf('%02d:%02d', $remaining_minutes, $remaining_seconds); ?>
                        </span>
                    </div>

                    <!-- ── Perfil com Dropdown ────────────────────────── -->
                    <div class="profile-wrapper" id="profileWrapper">
                        <div class="user-info" id="profileToggle"
                             onclick="toggleProfileDropdown()"
                             title="Clique para opções de perfil">
                            <div class="user-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                                <div class="user-role"><?php echo getUserType($_SESSION['user_type']); ?></div>
                            </div>
                            <i class="fas fa-chevron-down profile-caret"></i>
                        </div>

                        <!-- Dropdown Menu -->
                        <div class="profile-dropdown" id="profileDropdown">
                            <!-- Cabeçalho do dropdown -->
                            <div class="profile-dropdown-header">
                                <div class="pd-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                                <div class="pd-role"><?php echo getUserType($_SESSION['user_type']); ?></div>
                                <div class="pd-session">
                                    <i class="fas fa-clock"></i>
                                    <span>Sessão restante: <strong id="dropdownSessionText">
                                        <?php printf('%02d:%02d', $remaining_minutes, $remaining_seconds); ?>
                                    </strong></span>
                                </div>
                            </div>

                            <!-- Opção: Acesso QR CODE -->
                            <button class="profile-dropdown-item"
                                    onclick="abrirQrPerfil(); fecharProfileDropdown();">
                                <i class="fas fa-qrcode"></i>
                                <span>Acesso QR CODE</span>
                                <span class="item-badge">MASTER</span>
                            </button>

                            <div class="profile-dropdown-divider"></div>

                            <!-- Opção: Sair -->
                            <a href="<?php echo SITE_URL; ?>/admin/logout.php"
                               class="profile-dropdown-item danger">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Sair do Sistema</span>
                            </a>
                        </div>
                    </div>
                    <!-- ── Fim Perfil ─────────────────────────────────── -->

                </div>
            </header>
            
            <!-- Content Area -->
            <main class="content-area">

    <!-- ================================================================
         MODAL — Acesso QR CODE (abre pelo menu do perfil)
         ================================================================ -->
    <div id="modalQrPerfil" onclick="fecharQrPerfilFora(event)">
        <div class="qrp-box">
            <div class="qrp-header">
                <h4><i class="fas fa-qrcode"></i> Acesso Master via QR Code</h4>
                <button class="qrp-close" onclick="fecharQrPerfil()" title="Fechar">&times;</button>
            </div>
            <div class="qrp-body">
                <p class="qrp-desc">
                    Aponte a câmera deste computador para o QR Code exibido na tela do
                    <strong>tablet Android</strong>. O sistema validará o código e liberará
                    o acesso master automaticamente.
                </p>

                <?php if (isAdminGeral()): ?>
                <!-- Admin Geral: selecionar usuário para liberar acesso -->
                <div class="qrp-estab-select">
                    <label><i class="fas fa-user"></i> Usuário que receberá o acesso master</label>
                    <select id="qrpUserId">
                        <option value="">-- Selecione o usuário --</option>
                        <?php
                        $conn_qr = getDBConnection();
                        $stmt_qr = $conn_qr->prepare("
                            SELECT u.id, u.name, u.type,
                                   GROUP_CONCAT(e.name SEPARATOR ', ') AS estabelecimentos
                            FROM users u
                            LEFT JOIN user_estabelecimento ue ON u.id = ue.user_id AND ue.status = 1
                            LEFT JOIN estabelecimentos e ON ue.estabelecimento_id = e.id
                            WHERE u.id != ?
                            GROUP BY u.id
                            ORDER BY u.type ASC, u.name ASC
                        ");
                        $stmt_qr->execute([$_SESSION['user_id']]);
                        $usuarios_qr = $stmt_qr->fetchAll();
                        foreach ($usuarios_qr as $uq):
                            $label = htmlspecialchars($uq['name']);
                            if ($uq['estabelecimentos']) $label .= ' — ' . htmlspecialchars($uq['estabelecimentos']);
                        ?>
                        <option value="<?php echo $uq['id']; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <!-- Usuário de unidade: selecionar estabelecimento vinculado -->
                <?php if (!empty($user_estabelecimentos)): ?>
                <div class="qrp-estab-select">
                    <label><i class="fas fa-store"></i> Estabelecimento (Tablet vinculado)</label>
                    <select id="qrpEstabelecimentoId">
                        <?php foreach ($user_estabelecimentos as $estab): ?>
                        <option value="<?php echo $estab['id']; ?>"><?php echo htmlspecialchars($estab['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <!-- Usuário não-admin libera acesso para si mesmo -->
                <input type="hidden" id="qrpUserId" value="<?php echo $_SESSION['user_id']; ?>">
                <?php endif; ?>

                <!-- Botão para iniciar câmera -->
                <button class="qrp-btn-start" id="qrpBtnStart" onclick="qrpIniciarCamera()">
                    <i class="fas fa-camera"></i> Iniciar Câmera e Escanear QR Code
                </button>

                <!-- Área de vídeo (oculta até iniciar) -->
                <div class="qrp-video-wrapper" id="qrpVideoWrapper" style="display:none;">
                    <video id="qrpVideo" autoplay playsinline muted></video>
                    <canvas id="qrpCanvas" style="display:none;"></canvas>
                    <div class="qrp-scanner-overlay">
                        <div class="qrp-scanner-frame"></div>
                    </div>
                </div>

                <div class="qrp-status" id="qrpStatus"></div>
                <div class="qrp-result" id="qrpResult"></div>
            </div>
        </div>
    </div>
    <!-- ── Fim Modal QR Perfil ── -->

    <script>
    /* ============================================================
       SESSÃO — Contador Regressivo
       ============================================================ */
    (function() {
        var remaining  = <?php echo (int)$remaining; ?>; // segundos restantes
        var timerEl    = document.getElementById('sessionTimerText');
        var dropdownEl = document.getElementById('dropdownSessionText');
        var wrapperEl  = document.getElementById('sessionTimer');

        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        function updateTimer() {
            if (remaining <= 0) {
                if (timerEl)    timerEl.textContent    = '00:00';
                if (dropdownEl) dropdownEl.textContent = '00:00';
                if (wrapperEl)  wrapperEl.className    = 'session-timer danger';
                // Redirecionar para logout quando sessão expirar
                window.location.href = '<?php echo SITE_URL; ?>/admin/logout.php?expired=1';
                return;
            }

            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            var text = pad(mins) + ':' + pad(secs);

            if (timerEl)    timerEl.textContent    = text;
            if (dropdownEl) dropdownEl.textContent = text;

            // Alterar cor conforme urgência
            if (wrapperEl) {
                if (remaining <= 300) {          // ≤ 5 min → perigo
                    wrapperEl.className = 'session-timer danger';
                } else if (remaining <= 900) {   // ≤ 15 min → aviso
                    wrapperEl.className = 'session-timer warning';
                } else {
                    wrapperEl.className = 'session-timer';
                }
            }

            remaining--;
        }

        updateTimer();
        setInterval(updateTimer, 1000);
    })();

    /* ============================================================
       PERFIL — Toggle Dropdown
       ============================================================ */
    function toggleProfileDropdown() {
        var wrapper = document.getElementById('profileWrapper');
        wrapper.classList.toggle('open');
    }
    function fecharProfileDropdown() {
        var wrapper = document.getElementById('profileWrapper');
        wrapper.classList.remove('open');
    }
    // Fechar ao clicar fora
    document.addEventListener('click', function(e) {
        var wrapper = document.getElementById('profileWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            wrapper.classList.remove('open');
        }
    });

    /* ============================================================
       MODAL QR CODE — Perfil
       ============================================================ */
    var qrpStream   = null;
    var qrpInterval = null;
    var qrpAtivo    = false;

    function abrirQrPerfil() {
        var modal = document.getElementById('modalQrPerfil');
        modal.classList.add('active');
        // Resetar estado
        document.getElementById('qrpStatus').textContent = '';
        var result = document.getElementById('qrpResult');
        result.style.display = 'none';
        result.className = 'qrp-result';
        document.getElementById('qrpVideoWrapper').style.display = 'none';
        var btn = document.getElementById('qrpBtnStart');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Iniciar Câmera e Escanear QR Code'; }
    }

    function fecharQrPerfil() {
        qrpPararCamera();
        var modal = document.getElementById('modalQrPerfil');
        modal.classList.remove('active');
    }

    function fecharQrPerfilFora(e) {
        if (e.target === document.getElementById('modalQrPerfil')) fecharQrPerfil();
    }

    function qrpIniciarCamera() {
        // Validar seleção de usuário (Admin Geral)
        var userSelect = document.getElementById('qrpUserId');
        if (userSelect && userSelect.tagName === 'SELECT' && !userSelect.value) {
            alert('Selecione o usuário que receberá o acesso master antes de escanear.');
            return;
        }

        var btn = document.getElementById('qrpBtnStart');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Iniciando câmera...'; }

        // Carregar jsQR se necessário
        if (typeof jsQR === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
            script.onload = function() { qrpAbrirCamera(); };
            script.onerror = function() {
                document.getElementById('qrpStatus').textContent = 'Erro ao carregar biblioteca de scanner.';
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Tentar Novamente'; }
            };
            document.head.appendChild(script);
        } else {
            qrpAbrirCamera();
        }
    }

    function qrpAbrirCamera() {
        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } }
        }).then(function(stream) {
            qrpStream = stream;
            qrpAtivo  = true;
            var video = document.getElementById('qrpVideo');
            video.srcObject = stream;
            video.play();
            document.getElementById('qrpVideoWrapper').style.display = 'block';
            document.getElementById('qrpStatus').textContent = 'Câmera ativa. Aponte para o QR Code do tablet...';
            var btn = document.getElementById('qrpBtnStart');
            if (btn) { btn.style.display = 'none'; }
            qrpIniciarLeitura();
        }).catch(function(err) {
            console.error('Camera error:', err);
            document.getElementById('qrpStatus').textContent = 'Erro ao acessar câmera: ' + err.message;
            var btn = document.getElementById('qrpBtnStart');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Tentar Novamente'; }
        });
    }

    function qrpIniciarLeitura() {
        var video  = document.getElementById('qrpVideo');
        var canvas = document.getElementById('qrpCanvas');
        var ctx    = canvas.getContext('2d');

        qrpInterval = setInterval(function() {
            if (!qrpAtivo || !video || video.readyState !== video.HAVE_ENOUGH_DATA) return;

            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });

            if (code && code.data) {
                if (/^CHOPPON_MASTER:[0-9a-f]{64}$/.test(code.data)) {
                    qrpAtivo = false;
                    clearInterval(qrpInterval);
                    document.getElementById('qrpStatus').innerHTML =
                        '<i class="fas fa-check-circle" style="color:#28a745"></i> QR Code lido! Validando acesso...';
                    qrpAprovar(code.data);
                } else {
                    document.getElementById('qrpStatus').textContent =
                        'QR Code inválido. Aponte para o QR Code do tablet ChoppOn.';
                }
            }
        }, 200);
    }

    function qrpPararCamera() {
        qrpAtivo = false;
        clearInterval(qrpInterval);
        if (qrpStream) {
            qrpStream.getTracks().forEach(function(t) { t.stop(); });
            qrpStream = null;
        }
    }

    function qrpAprovar(qrData) {
        var userIdEl = document.getElementById('qrpUserId');
        var userId   = userIdEl ? userIdEl.value : '';

        // Determinar URL base para o AJAX (relativo ao root do site)
        var ajaxUrl = '<?php echo SITE_URL; ?>/admin/ajax/aprovar_master_qr_unidade.php';

        // Capturar estabelecimento_id se disponível (usuários de unidade)
        var estabEl = document.getElementById('qrpEstabelecimentoId');
        var estabId = estabEl ? estabEl.value : '';

        var fd = new FormData();
        fd.append('qr_data', qrData);
        fd.append('user_id', userId);
        if (estabId) fd.append('estabelecimento_id', estabId);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                qrpPararCamera();
                document.getElementById('qrpVideoWrapper').style.display = 'none';
                document.getElementById('qrpStatus').textContent = '';

                var result = document.getElementById('qrpResult');
                result.style.display = 'flex';

                if (data.success) {
                    result.className = 'qrp-result success';
                    result.innerHTML =
                        '<i class="fas fa-check-circle" style="font-size:22px"></i>' +
                        '<div><strong>Acesso Liberado!</strong><br>' + (data.message || '') + '</div>';
                    setTimeout(function() { fecharQrPerfil(); }, 5000);
                } else {
                    result.className = 'qrp-result error';
                    result.innerHTML =
                        '<i class="fas fa-times-circle" style="font-size:22px"></i>' +
                        '<div><strong>Falha na validação</strong><br>' + (data.message || 'Erro desconhecido.') + '</div>';
                    // Reabilitar botão para nova tentativa
                    var btn = document.getElementById('qrpBtnStart');
                    if (btn) { btn.style.display = 'flex'; btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Tentar Novamente'; }
                }
            })
            .catch(function(err) {
                console.error(err);
                qrpPararCamera();
                document.getElementById('qrpVideoWrapper').style.display = 'none';
                var result = document.getElementById('qrpResult');
                result.style.display = 'flex';
                result.className = 'qrp-result error';
                result.innerHTML =
                    '<i class="fas fa-times-circle" style="font-size:22px"></i>' +
                    '<div><strong>Erro de conexão</strong><br>Verifique a conexão e tente novamente.</div>';
                var btn = document.getElementById('qrpBtnStart');
                if (btn) { btn.style.display = 'flex'; btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Tentar Novamente'; }
            });
    }
    </script>
