<?php
require_once __DIR__ . '/../lib.php';

$pdo = db();
$error = '';
$agent = null;

if (isset($_GET['disabled'])) {
    $error = '代理账号已停用，请联系管理员。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    verify_csrf();
    $stmt = $pdo->prepare('SELECT * FROM agents WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $row = $stmt->fetch();
    if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash']) && $row['status'] === 'active') {
        $_SESSION['agent_id'] = $row['id'];
        redirect('/agent/index.php');
    }
    $error = '代理账号或密码错误，或账号已停用。';
}

if (!empty($_SESSION['agent_id'])) {
    $agent = require_agent();
}

if (!$agent):
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>代理登录 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> auth-page">
<?php ui_auth_visual(); ?>
<main class="auth-shell">
    <div class="brand-mark auth-brand"><img class="brand-logo" src="/assets/mofa-hat.svg" alt=""><span class="brand-name"><?= h(APP_NAME) ?></span></div>
    <h1>代理后台</h1>
    <p class="muted">使用管理员分配的代理账号登录，余额充足时可生成 360 天年付卡密。</p>
    <?php if ($error): ?><div class="alert bad"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="login" value="1">
        <label>代理账号<input name="username" required autocomplete="username"></label>
        <label>代理密码<input name="password" type="password" required autocomplete="current-password"></label>
        <button class="primary" type="submit">进入代理后台</button>
    </form>
</main>
</body>
</html>
<?php exit; endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_agent_cards') {
        $count = max(1, min(500, (int)($_POST['count'] ?? 1)));

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([(int)$agent['id']]);
            $lockedAgent = $stmt->fetch();

            if (!$lockedAgent) {
                throw new RuntimeException('代理账号不可用。');
            }

            $price = round((float)$lockedAgent['card_price'], 2);
            $balanceBefore = round((float)$lockedAgent['balance'], 2);
            $total = round($price * $count, 2);

            if ($balanceBefore < $total) {
                throw new RuntimeException('余额不足，无法生成卡密。');
            }

            $cardStmt = $pdo->prepare('INSERT INTO cards (card_key, duration_days, valid_until, status, used_by, used_time, agent_id, created_at) VALUES (?, 360, NULL, ?, NULL, NULL, ?, ?)');
            $logStmt = $pdo->prepare('INSERT INTO agent_card_logs (agent_id, card_id, price, balance_before, balance_after, created_at) VALUES (?, ?, ?, ?, ?, ?)');

            $runningBalance = $balanceBefore;
            for ($i = 0; $i < $count; $i++) {
                $runningBalance = round($runningBalance - $price, 2);
                $cardStmt->execute([generate_card_key(), 'unused', (int)$lockedAgent['id'], now()]);
                $cardId = (int)$pdo->lastInsertId();
                $logStmt->execute([(int)$lockedAgent['id'], $cardId, $price, $runningBalance + $price, $runningBalance, now()]);
            }

            $pdo->prepare('UPDATE agents SET balance = ?, updated_at = ? WHERE id = ?')
                ->execute([$runningBalance, now(), (int)$lockedAgent['id']]);
            $pdo->commit();
            flash('已生成 ' . $count . ' 张 360 天年付卡密，扣款 ￥' . number_format($total, 2) . '。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e->getMessage(), 'bad');
        }
        redirect('/agent/index.php');
    }
}

$agent = require_agent();
$generatedCards = $pdo->prepare('
    SELECT cards.card_key, cards.status, cards.used_time, cards.created_at, users.username AS used_username
    FROM cards
    LEFT JOIN users ON users.id = cards.used_by
    WHERE cards.agent_id = ?
    ORDER BY cards.id DESC
    LIMIT 200
');
$generatedCards->execute([(int)$agent['id']]);
$cards = $generatedCards->fetchAll();

if (in_array($_GET['export_cards'] ?? '', ['txt', 'csv'], true)) {
    $format = $_GET['export_cards'];
    $filename = 'agent-cards-' . date('Ymd-His') . '.' . $format;
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if ($format === 'csv') {
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['卡密', '状态', '绑定用户', '生成时间']);
        foreach ($cards as $card) {
            fputcsv($out, [
                $card['card_key'],
                card_state($card),
                $card['used_username'] ?: '未绑定',
                $card['created_at'],
            ]);
        }
    } else {
        foreach ($cards as $card) {
            echo $card['card_key'] . "\r\n";
        }
    }
    exit;
}

$flash = flash();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>代理后台 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> ui-page">
<?php ui_shell('agent', 'cards', (string)$agent['username']); ?>
<main id="main-content" class="ui-content" tabindex="-1">
    <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <section class="hero-panel">
        <div class="hero-copy">
            <p class="eyebrow">代理账号 <?= h($agent['username']) ?></p>
            <h1>生成 360 天年付卡密</h1>
            <p class="hero-desc">代理只能生成年付卡密，默认有效期 360 天。生成时会按管理员设置的单价从后台余额中扣除。</p>
        </div>
        <div class="metric-strip">
            <div><span>后台余额</span><strong>￥<?= number_format((float)$agent['balance'], 2) ?></strong></div>
            <div><span>年卡单价</span><strong>￥<?= number_format((float)$agent['card_price'], 2) ?></strong></div>
        </div>
    </section>

    <section class="main-grid">
        <article class="panel">
            <div class="section-head">
                <div><p class="eyebrow">Generate</p><h2>生成卡密</h2></div>
                <span class="status-badge">360 天</span>
            </div>
            <form method="post" class="form-row">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_agent_cards">
                <label>生成数量<input name="count" type="number" min="1" max="500" value="1"></label>
                <button class="primary" type="submit">扣款生成</button>
            </form>
        </article>

        <article class="panel table-wrap primary-panel">
            <div class="section-head">
                <div><p class="eyebrow">Cards</p><h2>最近生成卡密</h2></div>
                <span class="status-badge"><?= count($cards) ?> 张</span>
            </div>
            <div class="actions actions-two export-actions">
                <a class="button-link" href="/agent/index.php?export_cards=txt">导出卡密 TXT</a>
                <a class="button-link" href="/agent/index.php?export_cards=csv">导出明细 CSV</a>
            </div>
            <table>
                <thead><tr><th>卡密</th><th>状态</th><th>绑定用户</th><th>生成时间</th></tr></thead>
                <tbody>
                <?php foreach ($cards as $card): ?>
                    <tr>
                        <td><span class="badge"><?= h($card['card_key']) ?></span></td>
                        <td><?= h(card_state($card)) ?></td>
                        <td><?= h($card['used_username'] ?: '未绑定') ?></td>
                        <td><?= h($card['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </article>
    </section>
</main>
</body>
</html>
