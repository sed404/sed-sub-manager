<?php
require_once __DIR__ . '/lib.php';

$error = '';
$bannedReason = '';
if (isset($_GET['expired'])) {
    $error = '账号已被停用或绑定卡密异常，请联系管理员处理。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare('
        SELECT users.*, cards.status AS card_status
        FROM users LEFT JOIN cards ON cards.id = users.card_id
        WHERE users.username = ?
    ');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (($user['status'] ?? '') === 'banned') {
            $bannedReason = trim((string)($user['ban_reason'] ?? '')) ?: '管理员未填写封禁原因，请联系管理员。';
        } elseif (user_can_login($user)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['show_login_announcement'] = true;
            $_SESSION['sync_upstream_after_login'] = true;
            redirect('/dashboard.php');
        } else {
            $error = '账号已停用或绑定卡密异常，请联系管理员处理。';
        }
    } else {
        $error = '账号或密码错误。';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> auth-page login-page">
<?php if ($bannedReason !== ''): ?>
    <div class="announcement-backdrop" data-ban-modal>
        <section class="announcement-modal ban-modal" role="alertdialog" aria-modal="true" aria-labelledby="ban-title">
            <p class="eyebrow">账号状态</p>
            <h2 id="ban-title">账号已封禁</h2>
            <div class="announcement-content"><strong>封禁原因：</strong><br><?= nl2br(h($bannedReason)) ?></div>
            <button class="primary" type="button" onclick="this.closest('[data-ban-modal]').remove()">我知道了</button>
        </section>
    </div>
<?php endif; ?>
<?php ui_auth_visual(); ?>
<main class="auth-shell">
    <div class="brand-mark auth-brand"><img class="brand-logo" src="/assets/mofa-hat.svg" alt=""><span class="brand-name"><?= h(APP_NAME) ?></span></div>
    <div class="auth-heading">
        <p class="eyebrow">Secure Access</p>
        <h1>欢迎登录</h1>
    </div>
    <p class="muted">登录后查看你的个人订阅入口和服务状态。</p>
    <div class="auth-tabs"><span>密码登录</span><a href="/register.php">创建账号</a></div>
    <?php if ($error): ?><div class="alert bad"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>账号<input name="username" required autocomplete="username" placeholder="QQ号 / 手机号 / 邮箱"></label>
        <label>密码<input name="password" type="password" required autocomplete="current-password" placeholder="请输入密码"></label>
        <button class="primary" type="submit">登录</button>
    </form>
    <p class="switch">还没有账号？<a href="/register.php">免费注册</a></p>
    <a class="auth-back" href="/index.php">← 返回首页</a>
</main>
</body>
</html>
