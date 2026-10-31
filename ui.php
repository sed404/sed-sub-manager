<?php
/** Shared presentation only. Call after the page's existing authentication checks. */
function ui_head(): void
{
    echo '<meta name="sed-default-theme" content="' . h(site_theme()) . '">';
    echo '<script src="/assets/theme.js?v=20261011"></script>';
    echo '<link rel="stylesheet" href="/assets/style.css?v=20261011">';
    echo '<link rel="stylesheet" href="/assets/ui.css?v=20261011">';
    echo '<link rel="stylesheet" href="/assets/sinking.css?v=20261011-brand2">';
    echo '<script defer src="/assets/ui.js?v=20261011"></script>';
    echo '<script defer src="/assets/sinking.js?v=20261011"></script>';
}

function ui_logo(): string
{
    return '<span class="sed-brand-lockup" role="img" aria-label="sed机场管理程序"><i class="sed-brand-symbol" aria-hidden="true"></i><strong aria-hidden="true">sed机场管理程序</strong></span>';
}

function ui_auth_visual(): void
{
    echo '<aside class="auth-visual" aria-label="欢迎使用"><a class="sinking-brand" href="/index.php">' . ui_logo() . '</a><div class="auth-visual-copy"><p class="sinking-kicker">YOUR SPACE. YOUR CONNECTION.</p><h2>让连接变得简单</h2><p>连接你的数字生活，探索更多可能。</p><img class="auth-orbit" src="/assets/sinking-orbit.svg" alt=""><div class="sinking-benefits"><span>便捷订阅</span><span>统一管理</span><span>安心连接</span></div></div><span class="auth-visual-caption">CONNECTED TO MORE POSSIBILITIES.</span></aside>';
}

function ui_icon(string $name): string
{
    $paths = [
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 4a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'settings' => 'M4 7h16M4 17h16M8 4v6M16 14v6',
        'card' => 'M3 5h18v14H3zM3 10h18M7 15h4',
        'help' => 'M9 9a3 3 0 0 1 6 0c0 2-3 2-3 5M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        'book' => 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
        'exit' => 'M10 4H4v16h6M14 8l4 4-4 4M8 12h13',
        'chevron' => 'm9 5 7 7-7 7',
        'network' => 'M8 4h8v5H8zM3 16h6v5H3zM15 16h6v5h-6zM12 9v4M6 16v-3h12v3',
        'ticket' => 'M4 4h16v12H9l-5 4V4zM8 8h8M8 12h5',
        'gift' => 'M3 9h18v4H3zM5 13v8h14v-8M12 9v12M12 9H8a3 3 0 1 1 3-3l1 3zm0 0h4a3 3 0 1 0-3-3l-1 3z',
        'notice' => 'M4 10v4h4l11 5V5L8 10H4zM8 14l2 6',
        'palette' => 'M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h3a3 3 0 0 0 3-3c0-4-4-7-9-7zM7 10h.01M9 6h.01M15 6h.01M18 9h.01',
    ];
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($paths[$name] ?? $paths['grid']) . '"/></svg>';
}

function ui_navigation(string $role): array
{
    if ($role === 'admin') return [
        ['cards','卡密管理','/admin/index.php?tab=cards','card'],
        ['subs','订阅线路','/admin/index.php?tab=subs','network'],
        ['orders','支付订单','/admin/index.php?tab=orders','book'],
        ['payment','支付与套餐','/admin/index.php?tab=payment','card'],
        ['users','用户管理','/admin/index.php?tab=users','users'],
        ['agents','代理管理','/admin/index.php?tab=agents','users'],
        ['compensation','订阅补偿','/admin/index.php?tab=compensation','gift'],
        ['tickets','工单管理','/admin/index.php?tab=tickets','ticket'],
        ['notice','公告与下载','/admin/index.php?tab=notice','notice'],
        ['appearance','外观主题','/admin/index.php?tab=appearance','palette'],
        ['settings','账户设置','/admin/index.php?tab=settings','settings'],
    ];
    if ($role === 'agent') return [['cards','卡密管理','/agent/index.php','card']];
    return [
        ['home','订阅概览','/dashboard.php','grid'],
        ['renew','订购套餐','/dashboard.php?tab=renew','card'],
        ['docs','使用教程','/dashboard.php?tab=docs','book'],
        ['tickets','我的工单','/dashboard.php?tab=tickets','ticket'],
        ['profile','账号设置','/dashboard.php?tab=profile','users'],
    ];
}

function ui_shell(string $role, string $active, string $username): void
{
    $navigation = ui_navigation($role);
    $roleLabel = ['admin'=>'管理控制台','agent'=>'代理工作台','user'=>'个人中心'][$role] ?? '个人中心';
    $title = '工作台';
    foreach ($navigation as $item) if ($active === $item[0]) $title = $item[1];
    $home = $role === 'admin' ? '/admin/index.php' : ($role === 'agent' ? '/agent/index.php' : '/dashboard.php');
    $logout = $role === 'admin' ? '/admin/logout.php' : ($role === 'agent' ? '/agent/logout.php' : '/logout.php');
    echo '<a class="ui-skip" href="#main-content">跳至主内容</a><aside class="ui-sidebar" id="ui-sidebar" aria-label="主导航">';
    echo '<a class="ui-brand" href="' . $home . '">' . ui_logo() . '</a>';
    echo '<nav class="ui-navigation" aria-label="' . h($roleLabel) . '栏目">';
    foreach ($navigation as $item) {
        echo '<a class="ui-nav-link' . ($active === $item[0] ? ' active' : '') . '" href="' . h($item[2]) . '" title="' . h($item[1]) . '" aria-label="' . h($item[1]) . '"' . ($active === $item[0] ? ' aria-current="page"' : '') . '>' . ui_icon($item[3]) . '<span>' . h($item[1]) . '</span></a>';
    }
    echo '</nav><div class="ui-sidebar-footer"><a href="' . $logout . '" title="退出登录">' . ui_icon('exit') . '<span>退出登录</span></a></div></aside>';
    echo '<header class="ui-topbar"><button class="ui-icon-button" type="button" data-ui-toggle aria-label="折叠导航" aria-controls="ui-sidebar" aria-expanded="true">' . ui_icon('menu') . '</button>';
    echo '<nav class="ui-breadcrumb" aria-label="面包屑"><span>' . h($roleLabel) . '</span><span aria-hidden="true">/</span><strong>' . h($title) . '</strong></nav>';
    echo '<div class="ui-toolbar-actions"><button type="button" class="ui-icon-button" data-theme-open aria-label="主题设置">' . ui_icon('settings') . '<span class="ui-tool-label">主题</span></button>';
    echo '<details class="ui-user-menu"><summary>' . ui_icon('users') . '<span>' . h($username) . '</span></summary><div class="ui-user-dropdown">';
    if ($role !== 'agent') echo '<a href="' . ($role === 'admin' ? '/admin/index.php?tab=settings' : '/dashboard.php?tab=profile') . '">账号设置</a>';
    echo '<a href="' . $logout . '">' . ui_icon('exit') . '退出登录</a></div></details></div></header>';
    echo '<div class="ui-backdrop" data-ui-close hidden></div>';
}

function ui_tutorial_content(): void { require __DIR__ . "/tutorials/content.php"; }

