<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
requireAuth();

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
                    <div class="user-info">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-details">
                            <div class="user-name"><?php echo $_SESSION['user_name']; ?></div>
                            <div class="user-role"><?php echo getUserType($_SESSION['user_type']); ?></div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Content Area -->
            <main class="content-area">
