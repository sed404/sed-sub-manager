<?php
require_once __DIR__ . '/lib.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $accountError = validate_register_account($username);

    if ($accountError) {
        $error = $accountError;
    } elseif (strlen($password) < 6) {
        $error = '密码至少 6 位。';
    } else {
        $exists = db()->prepare('SELECT COUNT(*) AS total FROM users WHERE username = ?');
        $exists->execute([$username]);
        $usernameTaken = (int)$exists->fetch()['total'] > 0;

        if ($usernameTaken) {
            $error = '账号已存在，请换一个账号。';
        } else {
            $pdo = db();
            $token = bin2hex(random_bytes(24));
            $qqNumber = account_kind($username) === 'qq' ? $username : null;
            $expireTime = '2099-12-31 23:59:59';
            $pdo->prepare("INSERT INTO users (username, password_hash, card_id, qq_number, plan, expire_time, status, token, created_at) VALUES (?, ?, NULL, ?, 'free', ?, 'active', ?, ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $qqNumber, $expireTime, $token, now()]);
            $userId = (int)$pdo->lastInsertId();
            $_SESSION['user_id'] = $userId;
            redirect('/dashboard.php');
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>注册 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> auth-page">
<?php ui_auth_visual(); ?>
<main class="auth-shell">
    <div class="brand-mark auth-brand"><img class="brand-logo" src="/assets/mofa-hat.svg" alt=""><span class="brand-name"><?= h(APP_NAME) ?></span></div>
    <h1>创建账号</h1>
    <p class="muted">账号请填写 QQ、手机号或邮箱；注册后可在线选择套餐，支付后自动开通订阅。</p>
    <?php if ($error): ?><div class="alert bad"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>账号<input name="username" required autocomplete="username" placeholder="QQ号 / 手机号 / 邮箱"></label>
        <label>密码<input name="password" type="password" required minlength="6" autocomplete="new-password" placeholder="请设置至少 6 位密码"></label>
        <button class="primary" type="submit">免费注册并进入</button>
    </form>
    <p class="switch">已有账号？<a href="/login.php">返回登录</a></p>
    <a class="auth-back" href="/index.php">← 返回首页</a>
</main>
</body>
</html>
