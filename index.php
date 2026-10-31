<?php
require_once __DIR__ . '/lib.php';

if (current_user()) {
    redirect('/dashboard.php');
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="便捷管理订阅、选择套餐、查看使用教程，让每一次连接更简单。">
    <title><?= h(APP_NAME) ?> - 让连接变得简单</title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> landing-page">
<a class="ui-skip" href="#main-content">跳至主内容</a>
<header class="landing-header">
    <a class="sinking-brand" href="/index.php"><?= ui_logo() ?></a>
    <nav class="landing-nav" aria-label="首页导航">
        <a href="#main-content">首页</a><a href="#advantages">平台优势</a><a href="#capabilities">产品能力</a>
        <button class="ui-icon-button" type="button" data-theme-open aria-label="主题设置">☼</button>
        <a href="/login.php">登录</a><a class="button-link primary" href="/register.php">开始使用 <span aria-hidden="true">→</span></a>
    </nav>
</header>
<main class="landing-main" id="main-content">
    <section class="landing-hero">
        <div class="landing-copy">
            <p class="sinking-kicker">A NEW WAY TO CONNECT</p>
            <h1>让连接变得简单<em>让可能发生</em></h1>
            <p class="landing-description">一个入口，管理你的全部订阅。<br>从订购到连接，让数字生活更加从容。</p>
            <div class="landing-actions"><a class="button-link primary" href="/login.php">开始体验 <span aria-hidden="true">→</span></a><a class="button-link" href="#capabilities">探索产品 <span aria-hidden="true">↓</span></a></div>
            <div class="sinking-benefits"><span>轻盈体验</span><span>便捷管理</span><span>多端连接</span></div>
        </div>
        <figure class="landing-art"><img src="/assets/sinking-platform.svg" alt="蓝色透明平台与连接线路组成的分层几何插画" fetchpriority="high"><figcaption><span>IDEAS. CONNECTED.</span><span>连接更多可能</span></figcaption></figure>
    </section>
    <div class="landing-strip"><span>TECHNOLOGY, MADE HUMAN.</span><span><strong>01</strong>高效连接</span><span><strong>02</strong>清晰管理</span><span><strong>03</strong>多端使用</span></div>
    <section class="landing-section" id="advantages">
        <p class="sinking-kicker">BUILT AROUND YOU</p><h2>把复杂留给系统，把简单留给你</h2><p class="muted">订阅、套餐与服务支持，在一个清晰的工作台中完成。</p>
        <div class="landing-features">
            <article class="landing-feature"><?= ui_icon('network') ?><h3>一个入口，多端连接</h3><p>查看可用线路，复制订阅地址，按教程导入你常用的客户端。</p></article>
            <article class="landing-feature"><?= ui_icon('grid') ?><h3>服务状态，一目了然</h3><p>集中查看订阅信息与有效期，随时管理自己的套餐与账号。</p></article>
            <article class="landing-feature"><?= ui_icon('ticket') ?><h3>遇到问题，随时反馈</h3><p>从使用教程到在线工单，让配置和日常使用都有迹可循。</p></article>
        </div>
    </section>
    <section class="landing-section" id="capabilities">
        <p class="sinking-kicker">START WITH SOMETHING SIMPLE</p><h2>开启连接，只需三步</h2>
        <div class="landing-steps"><article><h3>创建你的账号</h3><p>使用 QQ、手机号或邮箱注册，进入个人中心。</p></article><article><h3>选择适合的套餐</h3><p>查看可用套餐与订阅信息，按需订购或续费。</p></article><article><h3>连接常用客户端</h3><p>跟随使用教程导入订阅，开始你的连接体验。</p></article></div>
        <div class="landing-cta"><div><h2>下一次连接，从这里开始</h2><p>你的订阅工作台，已经准备就绪。</p></div><a class="button-link primary" href="/register.php">创建账号 →</a></div>
    </section>
</main>
<footer class="landing-footer"><span>© <?= date('Y') ?> <?= h(APP_NAME) ?></span><div><a href="/login.php">账号登录</a><a href="/agent/">代理入口</a></div></footer>
</body>
</html>


