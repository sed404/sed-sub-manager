<?php
require_once __DIR__ . '/lib.php';

$user = require_user();

function save_ticket_attachment(string $field, int $userId): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('截图上传失败，请重试。');
    if ((int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('截图不能超过 5MB。');
    $info = @getimagesize((string)$file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) throw new RuntimeException('截图仅支持 JPG、PNG 或 WebP。');
    $dir = __DIR__ . '/data/tickets';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('工单附件目录创建失败。');
    $name = 'u' . $userId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('截图保存失败。');
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_default_line') {
        $lineId = filter_var($_POST['default_line_id'] ?? '', FILTER_VALIDATE_INT);
        try {
            if ($lineId === false) throw new InvalidArgumentException('请选择有效线路。');
            save_default_subscription_line(db(), $user, $lineId, subscription_lines());
            flash('默认订阅线路已保存，客户端下次更新通用订阅时生效。');
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('/dashboard.php#my-subscription');
    }

    if ($action === 'bind_qq') {
        $error = bind_user_qq((int)$user['id'], (string)($_POST['qq_number'] ?? ''));
        flash($error ?: 'QQ 号已绑定。', $error ? 'bad' : 'ok');
        redirect('/dashboard.php');
    }

    if ($action === 'change_password') {
        $oldPassword = (string)($_POST['old_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (!password_verify($oldPassword, (string)$user['password_hash'])) {
            flash('原密码不正确。', 'bad');
        } elseif (strlen($newPassword) < 6) {
            flash('新密码至少 6 位。', 'bad');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('密码已修改，请使用新密码登录。');
        }
        redirect('/dashboard.php?tab=profile');
    }

    if ($action === 'renew_card') {
        $error = renew_user_with_card($user, (string)($_POST['card_key'] ?? ''));
        flash($error ?: '续费成功，账号时长已更新。', $error ? 'bad' : 'ok');
        redirect('/dashboard.php');
    }

    if ($action === 'claim_compensation') {
        try {
            $days = claim_compensation((int)$user['id'], (int)($_POST['campaign_id'] ?? 0));
            flash('补偿领取成功，已增加 ' . $days . ' 天付费订阅。');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('/dashboard.php');
    }

    if ($action === 'create_ticket') {
        try {
            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            if ($subject === '' || strlen($subject) > 480 || $message === '') throw new RuntimeException('请填写工单标题和问题描述。');
            $attachment = save_ticket_attachment('attachment', (int)$user['id']);
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO tickets (user_id, subject, status, created_at, updated_at) VALUES (?, ?, 'open', ?, ?)")->execute([(int)$user['id'], $subject, now(), now()]);
            $ticketId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, attachment, created_at) VALUES (?, 'user', ?, ?, ?)")->execute([$ticketId, $message, $attachment, now()]);
            $pdo->commit();
            flash('工单已提交，管理员回复后会显示在这里。');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            flash($e->getMessage(), 'bad');
        }
        redirect('/dashboard.php?tab=tickets');
    }

    if ($action === 'reply_ticket') {
        try {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $message = trim((string)($_POST['message'] ?? ''));
            $stmt = db()->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ? AND status = 'open'");
            $stmt->execute([$ticketId, (int)$user['id']]);
            if (!$stmt->fetch() || $message === '') throw new RuntimeException('工单已结束或回复内容为空。');
            $attachment = save_ticket_attachment('attachment', (int)$user['id']);
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, attachment, created_at) VALUES (?, 'user', ?, ?, ?)")->execute([$ticketId, $message, $attachment, now()]);
            db()->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([now(), $ticketId]);
            flash('回复已提交。');
        } catch (Throwable $e) { flash($e->getMessage(), 'bad'); }
        redirect('/dashboard.php?tab=tickets');
    }


}

$user = require_user();
$notice = trim(get_setting('site_notice'));
$noticeEnabled = get_setting('notice_popup_enabled', '1') === '1';
$faqEnabled = get_setting('faq_enabled', '1') === '1';
$faqContent = trim(get_setting('faq_content', ''));
$docsUrl = trim(get_setting('docs_url', ''));
if (preg_match('#<strong[^>]*>\s*常见问题\s*</strong>\s*<br\s*/?>\s*<br\s*/?>#iu', $notice, $legacyFaq, PREG_OFFSET_CAPTURE)) {
    $faqOffset = (int)$legacyFaq[0][1];
    $legacyFaqBody = substr($notice, $faqOffset + strlen($legacyFaq[0][0]));
    $legacyFaqBody = preg_replace('#</div>\s*$#i', '', $legacyFaqBody) ?? $legacyFaqBody;
    $faqContent = '<div>' . trim($legacyFaqBody) . '</div>';
    $notice = rtrim(substr($notice, 0, $faqOffset)) . '</div>';
}
$popupNoticeEnabled = get_setting('popup_notice_enabled', '1') === '1';
$popupNoticeTitle = trim(get_setting('popup_notice_title', '服务公告'));
$popupNoticeContent = trim(get_setting('popup_notice_content', ''));
$showLoginAnnouncement = !empty($_SESSION['show_login_announcement']);
$downloadEnabled = get_setting('download_block_enabled', '1') === '1';
$downloadName = trim(get_setting('download_app_name', 'Clash Verge'));
$downloadDesc = trim(get_setting('download_app_desc', '推荐使用的订阅客户端'));
$downloadIcon = trim(get_setting('download_icon_url'));
$downloadUrl = trim(get_setting('download_url'));
$freeSubscriptionEnabled = free_subscription_enabled();
$pullCount24h = subscription_pull_count_24h((int)$user['id']);
$pullLimit = paid_pull_limit();
$pendingCompensation = pending_compensation((int)$user['id']);
$ticketStmt = db()->prepare('SELECT * FROM tickets WHERE user_id = ? ORDER BY updated_at DESC');
$ticketStmt->execute([(int)$user['id']]);
$userTickets = $ticketStmt->fetchAll();
$ticketMessages = [];
if ($userTickets) {
    $ids = array_column($userTickets, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $messageStmt = db()->prepare("SELECT * FROM ticket_messages WHERE ticket_id IN ($placeholders) ORDER BY id ASC");
    $messageStmt->execute($ids);
    foreach ($messageStmt->fetchAll() as $ticketMessage) $ticketMessages[(int)$ticketMessage['ticket_id']][] = $ticketMessage;
}
$baseSubUrl = subscription_url($user);
$expireTs = strtotime((string)$user['expire_time']);
$daysLeft = $expireTs ? max(0, (int)ceil(($expireTs - time()) / 86400)) : 0;
$isFree = ($user['plan'] ?? 'paid') === 'free';
{ // Check the shared 12-hour window on each user-center visit.
    unset($_SESSION['sync_upstream_after_login']);
    try {
        sync_subscription_lines_after_login();
    } catch (Throwable $e) {
        // Upstream metadata must never prevent the user center from loading.
    }
}
$subscriptionLines = $isFree ? [] : subscription_lines();
$isExpired = !$isFree && (!$expireTs || $expireTs < time());
$needsQqBind = trim((string)($user['qq_number'] ?? '')) === '';
if (!$needsQqBind && $showLoginAnnouncement) {
    unset($_SESSION['show_login_announcement']);
}
$tab = $_GET['tab'] ?? 'home';
if (!in_array($tab, ['home','renew','docs','tickets','profile'], true)) {
    $tab = 'home';
}
$flash = flash();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>用户中心 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
    <?php if ($tab === 'docs'): ?><link rel="stylesheet" href="/assets/tutorials.css?v=20261004-classic"><?php endif; ?>
</head>
<body class="<?= h(site_theme_class()) ?> ui-page">
<?php ui_shell('user', $tab, (string)$user['username']); ?>

<main id="main-content" class="ui-content" tabindex="-1">
    <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if (!$needsQqBind && $pendingCompensation): ?>
        <div class="announcement-backdrop" data-compensation>
            <section class="announcement-modal" role="dialog" aria-modal="true" aria-labelledby="compensation-title">
                <p class="eyebrow">订阅补偿</p>
                <h2 id="compensation-title"><?= h($pendingCompensation['title']) ?></h2>
                <div class="announcement-content"><?= nl2br(h($pendingCompensation['reason'])) ?></div>
                <p class="compensation-days">赠送 <?= (int)$pendingCompensation['duration_days'] ?> 天付费订阅</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="claim_compensation">
                    <input type="hidden" name="campaign_id" value="<?= (int)$pendingCompensation['id'] ?>">
                    <button class="primary" type="submit">立即领取</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
    <?php if (!$needsQqBind && !$pendingCompensation && $showLoginAnnouncement && $popupNoticeEnabled && $popupNoticeContent !== ''): ?>
        <div class="announcement-backdrop" data-announcement>
            <section class="announcement-modal" role="dialog" aria-modal="true" aria-labelledby="announcement-title">
                <button class="announcement-close" type="button" aria-label="关闭公告" data-announcement-close>×</button>
                <p class="eyebrow">Announcement</p>
                <h2 id="announcement-title"><?= h($popupNoticeTitle ?: '服务公告') ?></h2>
                <div class="announcement-content"><?= nl2br(h($popupNoticeContent)) ?></div>
                <button class="primary" type="button" data-announcement-close>我知道了</button>
            </section>
        </div>
    <?php endif; ?>
    <?php if ($needsQqBind): ?>
        <div class="modal-backdrop">
            <section class="panel bind-modal">
                <div class="auth-avatar-wrap"><img class="auth-avatar" data-qq-avatar src="<?= h(qq_avatar_url(null)) ?>" alt="QQ头像"></div>
                <h2>绑定 QQ 号</h2>
                <p class="muted">为了显示头像并方便管理员按 QQ 查询卡密，请先绑定 QQ 号。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="bind_qq">
                    <label>QQ 号<input name="qq_number" required placeholder="请输入 QQ 号" data-avatar-input></label>
                    <button class="primary" type="submit">绑定并继续</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'docs'): ?>
        <?php ui_tutorial_content(); ?>
    <?php elseif ($tab === 'renew' || ($isExpired && $tab !== 'tickets' && $tab !== 'profile')): ?>
        <section class="hero-panel renew-hero">
            <div class="hero-copy">
                <p class="eyebrow">SUBSCRIPTION PLANS</p>
                <h1><?= $isExpired ? '订阅已到期，开通套餐继续使用' : '订购套餐' ?></h1>
                <p class="hero-desc">选择适合你的套餐，在线支付后自动开通或延长订阅。已生效的付费订阅会在原到期时间上累加时长。</p>
            </div>
            <div class="metric-strip">
                <div><span>当前套餐</span><strong><?= h(plan_label($user['plan'] ?? 'paid')) ?></strong></div>
                <div><span>到期时间</span><strong><?= h($isFree ? '尚未开通' : $user['expire_time']) ?></strong></div>
            </div>
        </section>
        <section class="purchase-stack">
            <article class="panel online-plan-panel">
                <div class="section-head"><div><p class="eyebrow">ONLINE SUBSCRIPTION</p><h2>开通套餐</h2></div><span class="status-badge">在线支付 · 自动开通</span></div>
                <p class="muted">在线选择套餐并完成支付，即可使用付费线路，无需购买或填写卡密。</p>
                <?php $availablePlans = db()->query("SELECT id, name, price, duration_days FROM payment_plans WHERE status = 'active' ORDER BY duration_days, id")->fetchAll(); ?>
                <div class="payment-plans">
                <?php foreach ($availablePlans as $plan): ?>
                    <form method="post" action="/pay.php" class="plan-card panel">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <h3><?= h($plan['name']) ?></h3>
                        <div class="plan-price">￥<?= h(number_format((float)$plan['price'], 2)) ?></div>
                        <p class="muted"><?= (int)$plan['duration_days'] ?> 天订阅 · 支付后自动到账</p>
                        <button class="primary" type="submit">开通套餐</button>
                    </form>
                <?php endforeach; ?>
                </div>
                <?php if (!$availablePlans): ?><p class="download-empty">套餐正在准备中，请联系管理员添加在线套餐。</p><?php endif; ?>
                <a class="plan-order-link" href="/pay.php">查看套餐与最近订单 →</a>
            </article>
            <article class="panel card-renew-panel" id="card-renew">
                <div class="section-head"><div><p class="eyebrow">REDEEM A CODE</p><h2>使用卡密续费</h2></div></div>
                <p class="muted">已有卡密？在这里兑换对应的订阅时长。</p>
                <form method="post" class="form-row">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="renew_card">
                    <label>续费卡密<input name="card_key" required placeholder="XXXX-XXXX-XXXX"></label>
                    <button type="submit">兑换卡密</button>
                </form>
            </article>
        </section>
    <?php elseif ($tab === 'tickets'): ?>
        <section class="hero-panel">
            <div class="hero-copy"><p class="eyebrow">Support</p><h1>我的工单</h1><p class="hero-desc">提交使用问题并查看管理员回复。截图可选，单张不能超过 5MB。</p></div>
            <div class="metric-strip"><div><span>工单总数</span><strong><?= count($userTickets) ?></strong></div><div><span>处理中</span><strong><?= count(array_filter($userTickets, fn($item) => $item['status'] === 'open')) ?></strong></div></div>
        </section>
        <section class="main-grid ticket-grid">
            <article class="panel">
                <div class="section-head"><div><p class="eyebrow">New Ticket</p><h2>提交问题</h2></div></div>
                <form method="post" enctype="multipart/form-data" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_ticket">
                    <label>问题标题<input name="subject" maxlength="160" required placeholder="简要说明遇到的问题"></label>
                    <label>问题描述<textarea name="message" required placeholder="请描述客户端、错误提示和发生时间"></textarea></label>
                    <label>上传截图（可选）<input name="attachment" type="file" accept="image/jpeg,image/png,image/webp"></label>
                    <button class="primary" type="submit">提交工单</button>
                </form>
            </article>
            <div class="ticket-list">
                <?php foreach ($userTickets as $ticket): ?>
                    <article class="panel ticket-card">
                        <div class="section-head"><div><p class="eyebrow">#<?= (int)$ticket['id'] ?></p><h2><?= h($ticket['subject']) ?></h2></div><span class="status-badge"><?= $ticket['status'] === 'open' ? '处理中' : '已结束' ?></span></div>
                        <div class="ticket-thread">
                            <?php foreach ($ticketMessages[(int)$ticket['id']] ?? [] as $message): ?>
                                <div class="ticket-message <?= h($message['sender_type']) ?>"><strong><?= $message['sender_type'] === 'admin' ? '管理员' : '我' ?></strong><p><?= nl2br(h($message['message'])) ?></p><?php if ($message['attachment']): ?><a href="/data/tickets/<?= rawurlencode($message['attachment']) ?>" target="_blank" rel="noopener"><img src="/data/tickets/<?= rawurlencode($message['attachment']) ?>" alt="工单截图"></a><?php endif; ?><small><?= h($message['created_at']) ?></small></div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ticket['status'] === 'open'): ?><form method="post" enctype="multipart/form-data" class="stack ticket-reply"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reply_ticket"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label>补充说明<textarea name="message" required></textarea></label><label>补充截图（可选）<input name="attachment" type="file" accept="image/jpeg,image/png,image/webp"></label><button type="submit">追加回复</button></form><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$userTickets): ?><div class="panel download-empty">暂时没有工单</div><?php endif; ?>
            </div>
        </section>
    <?php elseif ($tab === 'profile'): ?>
        <section class="hero-panel">
            <div class="hero-copy">
                <p class="eyebrow">账号 <?= h($user['username']) ?></p>
                <h1>账号设置</h1>
                <p class="hero-desc">在这里管理账号和密码。需要开通或续费订阅，请前往订购套餐。</p>
            </div>
            <div class="metric-strip">
                <div><span>当前套餐</span><strong><?= h(plan_label($user['plan'] ?? 'paid')) ?></strong></div>
                <div><span>绑定 QQ</span><strong><?= h($user['qq_number'] ?: '未绑定') ?></strong></div>
                <?php if (!$isFree): ?><div><span>剩余天数</span><strong><?= (int)$daysLeft ?> 天</strong></div><?php endif; ?>
            </div>
        </section>

        <section class="main-grid">
            <article class="panel">
                <div class="section-head"><div><p class="eyebrow">Password</p><h2>修改密码</h2></div></div>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <label>原密码<input name="old_password" type="password" required autocomplete="current-password"></label>
                    <label>新密码<input name="new_password" type="password" minlength="6" required autocomplete="new-password"></label>
                    <button class="primary" type="submit">保存新密码</button>
                </form>
            </article>
            <article class="panel">
                <div class="section-head"><div><p class="eyebrow">Subscription</p><h2>订阅管理</h2></div></div>
                <a class="button-link primary" href="/dashboard.php?tab=renew">开通套餐 / 在线续费</a>
                <p class="muted">已有卡密也可在下方兑换。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="renew_card">
                    <label>续费卡密<input name="card_key" required placeholder="XXXX-XXXX-XXXX"></label>
                    <button class="primary" type="submit"><?= $isFree ? '升级为付费用户' : '续费当前账号' ?></button>
                    
                </form>
            </article>
        </section>
    <?php else: ?>
    <section class="hero-panel dashboard-subscription-hero">
        <div class="ui-account-heading"><span class="ui-account-avatar" aria-hidden="true"><?= ui_icon('users') ?></span><div class="hero-copy">
            <p class="eyebrow">账号 <?= h($user['username']) ?> · <?= h(plan_label($user['plan'] ?? 'paid')) ?></p>
            <h1>订阅概览</h1>
            <p class="hero-desc"><?= $isFree ? ($freeSubscriptionEnabled ? '当前使用免费订阅。在线开通套餐，即可解锁更多付费线路。' : '免费订阅当前已关闭。请选择在线套餐，支付后即可使用付费订阅。') : '从下方选择任意可用机场线路，可复制订阅链接，或一键导入 Clash Verge 和 Shadowrocket。' ?></p>
        </div></div>
        <div class="metric-strip">
            <div>
                <span>当前套餐</span>
                <strong><?= h(plan_label($user['plan'] ?? 'paid')) ?></strong>
            </div>
            <?php if (!$isFree): ?>
            <div>
                <span>到期时间</span>
                <strong><?= h($user['expire_time']) ?></strong>
            </div>
            <div>
                <span>剩余天数</span>
                <strong><?= (int)$daysLeft ?> 天</strong>
            </div>
            <div>
                <span>24小时拉取</span>
                <strong><?= (int)$pullCount24h ?> / <?= (int)$pullLimit ?> 次</strong>
            </div>
            <?php else: ?>
            <div>
                <span>升级入口</span>
                <div class="metric-actions">
                    <a class="metric-cta" href="/dashboard.php?tab=renew">开通套餐</a>
                    <a class="metric-cta secondary" href="/dashboard.php?tab=renew#card-renew">兑换已有卡密</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$isFree): ?>
    <section class="panel universal-subscription-panel" id="my-subscription">
        <div class="section-head"><div><p class="eyebrow">SUBSCRIPTION</p><h2>我的订阅</h2></div><button type="button" data-line-picker aria-expanded="false" aria-controls="default-line-form" hidden>切换订阅</button></div>
        <form method="post" class="default-line-form" id="default-line-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_default_line">
            <label>默认订阅线路<select name="default_line_id">
                <option value="0">自动选择可用线路</option>
                <?php foreach (selectable_subscription_lines($subscriptionLines) as $option): ?>
                <option value="<?= (int)$option['id'] ?>" <?= (int)($user['default_line_id'] ?? 0) === (int)$option['id'] ? 'selected' : '' ?>><?= h($option['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <button type="submit" class="primary">保存默认线路</button>
        </form>
        <?php render_subscription_controls($user); ?>
        <?php $activeLineId = preferred_subscription_line_id($user, $subscriptionLines); $activeLineName = '暂无可用线路'; foreach ($subscriptionLines as $currentLine) { if ((int)$currentLine['id'] === $activeLineId) $activeLineName = (string)$currentLine['name']; } ?>
        <p class="muted">当前线路：<strong><?= h($activeLineName) ?></strong><?= (int)($user['default_line_id'] ?? 0) > 0 && (int)$user['default_line_id'] !== $activeLineId ? '（默认线路不可用，已自动回退）' : '' ?></p>
        <p class="muted subscription-hint">通用订阅跟随你保存的默认线路，无需重新复制链接；默认线路不可用时自动使用其他可用线路。下方固定线路入口不受影响。</p>
    </section>
    <?php endif; ?>
    <section class="main-grid">
        <?php if (!$isFree): ?>
        <article class="panel primary-panel subscription-lines-panel">
            <div class="section-head">
                <div><p class="eyebrow">Subscription Lines</p><h2>选择机场线路</h2></div>
                <span class="status-badge"><?= count($subscriptionLines) ?> 条线路</span>
            </div>
            <?php if ($pullCount24h >= max(1, $pullLimit - 2)): ?>
                <div class="alert bad">最近 24 小时已拉取 <?= (int)$pullCount24h ?> 次，上限 <?= (int)$pullLimit ?> 次，请避免频繁更新。</div>
            <?php endif; ?>
            <?php if (!$subscriptionLines): ?>
                <div class="download-empty">管理员尚未添加机场线路。</div>
            <?php else: ?>
                <div class="subscription-line-grid">
                    <?php foreach ($subscriptionLines as $line): ?>
                        <?php
                        $lineAvailable = $line['status'] === 'available'
                            && (empty($line['expire_time']) || strtotime((string)$line['expire_time']) >= time());
                        $displayStatus = $lineAvailable ? 'available' : ($line['status'] === 'available' ? 'disabled' : $line['status']);
                        $displayLabel = $lineAvailable ? '可用' : ($line['status'] === 'available' ? '已到期' : subscription_line_status_label($line['status']));
                        $clashLineUrl = subscription_url($user, 'clash', (int)$line['id']);
                        $shadowrocketLineUrl = subscription_url($user, 'shadowrocket', (int)$line['id']);
                        $clashVergeUrl = 'clash://install-config?url=' . rawurlencode($clashLineUrl) . '&name=' . rawurlencode((string)$line['name']);
                        $shadowrocketUrl = 'shadowrocket://add/sub://' . base64_encode($shadowrocketLineUrl);
                        $usedBytes = max(0, (int)$line['upload_bytes'] + (int)$line['download_bytes']);
                        $totalBytes = max(0, (int)$line['total_bytes']);
                        $remainingBytes = $totalBytes > 0 ? max(0, $totalBytes - $usedBytes) : 0;
                        $trafficPercent = $totalBytes > 0 ? min(100, round($usedBytes / $totalBytes * 100, 1)) : 0;
                        ?>
                        <article class="subscription-line-card line-card-<?= h($displayStatus) ?>">
                            <div class="subscription-line-head">
                                <div><small>线路 <?= (int)$line['id'] ?></small><h3><?= h($line['name']) ?></h3></div>
                                <span class="line-status line-status-<?= h($displayStatus) ?>"><?= h($displayLabel) ?></span>
                            </div>
                            <dl class="subscription-line-meta">
                                <div><dt>到期时间</dt><dd><?= h($line['expire_time'] ?: '不限') ?></dd></div>
                                <div><dt>流量总量</dt><dd><?= h(format_traffic_bytes((int)$line['total_bytes'])) ?></dd></div>
                                <div><dt>已用流量</dt><dd><?= $usedBytes > 0 ? h(format_traffic_bytes($usedBytes)) : '0 B' ?></dd></div>
                                <div><dt>剩余流量</dt><dd><?= $totalBytes > 0 ? h(format_traffic_bytes($remainingBytes)) : '不限' ?></dd></div>
                                <div><dt>上传 / 下载</dt><dd><?= (int)$line['upload_bytes'] > 0 ? h(format_traffic_bytes((int)$line['upload_bytes'])) : '0 B' ?> / <?= (int)$line['download_bytes'] > 0 ? h(format_traffic_bytes((int)$line['download_bytes'])) : '0 B' ?></dd></div>
                            </dl>
                            <div class="traffic-usage" aria-label="流量已使用 <?= h((string)$trafficPercent) ?>%">
                                <div class="traffic-usage-label"><span>流量使用</span><strong><?= h((string)$trafficPercent) ?>%</strong></div>
                                <div class="traffic-progress"><span style="width: <?= h((string)$trafficPercent) ?>%"></span></div>
                            </div>
                            <?php if ($lineAvailable): ?>
                                <details class="line-client-details"><summary>复制订阅 / 选择客户端</summary>
                                    <?php render_subscription_controls($user, (int)$line['id'], (string)$line['name']); ?>
                                </details>
                            <?php else: ?>
                                <div class="line-unavailable">该线路当前不可使用</div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($isFree): ?>
        <article class="panel primary-panel">
            <div class="section-head">
                <div>
                    <p class="eyebrow">订阅链接</p>
                    <h2><?= $isFree ? '免费个人订阅' : '通用个人订阅' ?></h2>
                </div>
                <span class="status-badge"><?= $isFree && !$freeSubscriptionEnabled ? '需升级' : '可用' ?></span>
            </div>
            <?php if (!$isFree && $pullCount24h >= max(1, $pullLimit - 2)): ?><div class="alert bad">你最近 24 小时已拉取 <?= (int)$pullCount24h ?> 次，上限为 <?= (int)$pullLimit ?> 次。请暂时不要重复更新，超限会触发风控。</div><?php endif; ?>
            <?php if ($isFree && !$freeSubscriptionEnabled): ?>
                <div class="download-empty">免费订阅已关闭，请前往订购套餐页面开通。</div>
            <?php else: ?>
            <?php render_subscription_controls($user); ?>
            <div class="download-empty">免费用户使用统一免费订阅；升级后可自由选择多条机场线路。</div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <div class="home-secondary-grid">
        <div class="home-primary-column">

        <?php if ($downloadEnabled): ?>
            <article class="panel side-panel">
                <div class="download-head">
                    <div class="download-icon">
                        <?php if ($downloadIcon !== ''): ?>
                            <img src="<?= h($downloadIcon) ?>" alt="<?= h($downloadName) ?>">
                        <?php else: ?>
                            <span>APP</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="eyebrow">客户端下载</p>
                        <h2><?= h($downloadName ?: '客户端软件') ?></h2>
                    </div>
                </div>
                <?php if ($downloadDesc !== ''): ?><p class="muted"><?= h($downloadDesc) ?></p><?php endif; ?>
                <?php if ($downloadUrl !== ''): ?>
                    <a class="download-button" href="<?= h($downloadUrl) ?>" target="_blank" rel="noopener">立即下载</a>
                <?php else: ?>
                    <div class="download-empty">管理员尚未配置下载地址</div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <?php if ($faqEnabled && $faqContent !== ''): ?>
            <article class="panel faq-card">
                <div class="faq-heading"><small>Help Center</small><h2>常见问题</h2></div>
                <div class="notice-content faq-content"><?= safe_notice_html($faqContent) ?></div>
            </article>
        <?php endif; ?>
        </div>

        <div class="home-support-column">
            <?php if ($noticeEnabled && $notice !== ''): ?>
                <article class="panel notice-card">
                    <p class="eyebrow">服务公告</p>
                    <div class="notice-content"><?= safe_notice_html($notice) ?></div>
                </article>
            <?php endif; ?>
        </div>
        </div>
    </section>
    <?php endif; ?>
</main>

<script src="/assets/app.js?v=20261004-zibi2"></script>
</body>
</html>
