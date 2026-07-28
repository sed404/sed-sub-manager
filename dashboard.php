<?php
require_once __DIR__ . '/lib.php';

$user = require_user();

function save_claim_upload(string $field, int $userId): string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload both required screenshots.');
    }

    $file = $_FILES[$field];
    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Each screenshot must be 5MB or smaller.');
    }

    $info = @getimagesize((string)$file['tmp_name']);
    $allowed = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPG, PNG, and WebP screenshots are allowed.');
    }

    $dir = __DIR__ . '/data/card_claims';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Upload directory could not be created.');
    }

    $name = 'u' . $userId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$info[2]][0];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Screenshot upload failed.');
    }

    return $name;
}

function claim_status_label(string $status): string
{
    return ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'][$status] ?? 'Unknown';
}

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

    if ($action === 'submit_phone_card_claim') {
        try {
            if (get_setting('phone_card_claim_enabled', '1') !== '1') {
                throw new RuntimeException('手机卡领取活动暂未开放。');
            }
            $orderNo = trim((string)($_POST['order_no'] ?? ''));
            if ($orderNo === '' || strlen($orderNo) > 120) {
                throw new RuntimeException('Please enter a valid order number.');
            }

            $exists = db()->prepare("SELECT COUNT(*) AS total FROM card_claims WHERE user_id = ? AND status = 'pending'");
            $exists->execute([(int)$user['id']]);
            if ((int)$exists->fetch()['total'] > 0) {
                throw new RuntimeException('You already have a pending claim.');
            }

            $orderImage = save_claim_upload('order_image', (int)$user['id']);
            $activateImage = save_claim_upload('activate_image', (int)$user['id']);
            db()->prepare('INSERT INTO card_claims (user_id, order_no, order_image, activate_image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$user['id'], $orderNo, $orderImage, $activateImage, 'pending', now(), now()]);
            flash('Claim submitted. The admin will issue a 360-day card after approval.');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('/dashboard.php?tab=phone-card');
    }
}

$user = require_user();
$notice = trim(get_setting('site_notice'));
$noticeEnabled = get_setting('notice_popup_enabled', '1') === '1';
$popupNoticeEnabled = get_setting('popup_notice_enabled', '1') === '1';
$popupNoticeTitle = trim(get_setting('popup_notice_title', '服务公告'));
$popupNoticeContent = trim(get_setting('popup_notice_content', ''));
$showLoginAnnouncement = !empty($_SESSION['show_login_announcement']);
$downloadEnabled = get_setting('download_block_enabled', '1') === '1';
$downloadName = trim(get_setting('download_app_name', 'Clash Verge'));
$downloadDesc = trim(get_setting('download_app_desc', '推荐使用的订阅客户端'));
$downloadIcon = trim(get_setting('download_icon_url'));
$downloadUrl = trim(get_setting('download_url'));
$paidCardBuyUrl = trim(get_setting('paid_card_buy_url', ''));
if (get_setting('purchase_mode', 'link') === 'direct') $paidCardBuyUrl = '/pay.php';
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
$phoneCardApplyUrl = trim(get_setting('phone_card_apply_url', ''));
$phoneCardClaimEnabled = get_setting('phone_card_claim_enabled', '1') === '1';
$phoneCardClaimRules = trim(get_setting('phone_card_claim_rules', ''));
$claimStmt = db()->prepare('
    SELECT card_claims.*, cards.card_key
    FROM card_claims
    LEFT JOIN cards ON cards.id = card_claims.reward_card_id
    WHERE card_claims.user_id = ?
    ORDER BY card_claims.id DESC
    LIMIT 10
');
$claimStmt->execute([(int)$user['id']]);
$phoneCardClaims = $claimStmt->fetchAll();
$baseSubUrl = subscription_url($user);
$clientTypes = client_types();
$expireTs = strtotime((string)$user['expire_time']);
$daysLeft = $expireTs ? max(0, (int)ceil(($expireTs - time()) / 86400)) : 0;
$isFree = ($user['plan'] ?? 'paid') === 'free';
$isExpired = !$isFree && (!$expireTs || $expireTs < time());
$needsQqBind = trim((string)($user['qq_number'] ?? '')) === '';
if (!$needsQqBind && $showLoginAnnouncement) {
    unset($_SESSION['show_login_announcement']);
}
$tab = $_GET['tab'] ?? 'home';
if ($tab === 'phone-card' && !$phoneCardClaimEnabled) {
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
    <link rel="stylesheet" href="/assets/style.css?v=20260728-support-v1">
</head>
<body>
<header class="topbar user-topbar">
    <a class="brand-mark" href="/dashboard.php">
        <img class="brand-logo" src="/assets/mofa-hat.svg" alt=""><span class="brand-name"><?= h(APP_NAME) ?></span>
    </a>
    <div class="user-nav">
        <button class="menu-toggle" type="button" aria-label="打开导航" aria-expanded="false" data-menu-toggle>
            <span></span><span></span><span></span>
        </button>
        <?php if ($phoneCardClaimEnabled): ?><a class="ghost-link" href="/dashboard.php?tab=phone-card">免费领年卡</a><?php endif; ?>
        <img class="mini-avatar" src="<?= h(qq_avatar_url($user['qq_number'] ?? null)) ?>" alt="QQ头像">
        <a class="ghost-link" href="/dashboard.php?tab=profile">账号设置</a>
        <a class="ghost-link" href="/logout.php">退出登录</a>
    </div>
</header>

<aside class="user-sidebar" data-user-sidebar>
    <div class="sidebar-user">
        <img class="auth-avatar" src="<?= h(qq_avatar_url($user['qq_number'] ?? null)) ?>" alt="QQ Avatar">
        <strong><?= h($user['username']) ?></strong>
        <span><?= h(plan_label($user['plan'] ?? 'paid')) ?></span>
    </div>
    <nav class="user-sidebar-nav" aria-label="User navigation">
        <p class="nav-label">订阅概览</p>
        <a class="<?= $tab === 'home' ? 'active' : '' ?>" href="/dashboard.php">
            <span class="nav-icon">⌂</span><span>订阅概览</span>
        </a>
        <?php if ($phoneCardClaimEnabled): ?>
        <p class="nav-label">活动中心</p>
        <a class="<?= $tab === 'phone-card' ? 'active' : '' ?>" href="/dashboard.php?tab=phone-card">
            <span class="nav-icon">✦</span><span>活动中心</span>
        </a>
        <?php endif; ?>
        <p class="nav-label">订阅续费</p>
        <a class="<?= $tab === 'renew' ? 'active' : '' ?>" href="/dashboard.php?tab=renew">
            <span class="nav-icon">↻</span><span>订阅续费</span>
        </a>
        <p class="nav-label">账户管理</p>
        <a class="<?= $tab === 'tickets' ? 'active' : '' ?>" href="/dashboard.php?tab=tickets">
            <span class="nav-icon">?</span><span>我的工单</span>
        </a>
        <a class="<?= $tab === 'profile' ? 'active' : '' ?>" href="/dashboard.php?tab=profile">
            <span class="nav-icon">◎</span><span>账号设置</span>
        </a>
        <a href="/logout.php">
            <span class="nav-icon">↪</span><span>退出登录</span>
        </a>
    </nav>
</aside>
<div class="sidebar-backdrop" data-menu-close></div>

<main class="container user-main">
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

    <?php if ($isExpired && $tab !== 'tickets'): ?>
        <section class="hero-panel">
            <div class="hero-copy">
                <p class="eyebrow">账号 <?= h($user['username']) ?></p>
                <h1>卡密已到期，请先续费</h1>
                <p class="hero-desc">账号密码仍可登录，但付费订阅入口会暂停显示。输入一张未使用卡密后，系统会从当前时间开始增加对应时长。</p>
            </div>
            <div class="metric-strip">
                <div><span>到期时间</span><strong><?= h($user['expire_time']) ?></strong></div>
                <div><span>当前状态</span><strong>待续费</strong></div>
            </div>
        </section>
        <section class="panel renew-only">
            <div class="section-head">
                <div><p class="eyebrow">Renew</p><h2>卡密续费</h2></div>
            </div>
            <form method="post" class="form-row">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="renew_card">
                <label>续费卡密<input name="card_key" required placeholder="XXXX-XXXX-XXXX"></label>
                <button class="primary" type="submit">立即续费</button>
            </form>
        </section>
    <?php elseif ($tab === 'renew'): ?>
        <section class="hero-panel renew-hero">
            <div class="hero-copy">
                <p class="eyebrow">Subscription Renewal</p>
                <h1>订阅续费</h1>
                <p class="hero-desc">使用未使用的付费卡密即可升级或延长当前订阅。续费时长会从当前到期时间继续累计。</p>
            </div>
            <div class="metric-strip">
                <div><span>当前套餐</span><strong><?= h(plan_label($user['plan'] ?? 'paid')) ?></strong></div>
                <div><span>到期时间</span><strong><?= h($isFree ? '免费用户' : $user['expire_time']) ?></strong></div>
            </div>
        </section>
        <section class="main-grid renew-grid">
            <article class="panel primary-panel">
                <div class="section-head">
                    <div><p class="eyebrow">Renew</p><h2>使用卡密续费</h2></div>
                    <span class="status-badge">安全续费</span>
                </div>
                <p class="muted">输入一张未使用的卡密，系统会自动增加对应时长。卡密只能使用一次，请确认输入无误。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="renew_card">
                    <label>付费卡密
                        <input name="card_key" required placeholder="XXXX-XXXX-XXXX">
                    </label>
                    <button class="primary" type="submit">立即续费</button>
                </form>
            </article>
            <article class="panel side-panel">
                <div class="section-head">
                    <div><p class="eyebrow">Upgrade</p><h2>还没有卡密？</h2></div>
                </div>
                <p class="muted"><?= $phoneCardClaimEnabled ? '可以通过手机卡活动免费领取 360 天卡密，也可以联系管理员购买付费卡密。' : '可以联系管理员购买付费卡密。' ?></p>
                <div class="actions">
                    <?php if ($phoneCardClaimEnabled): ?><a class="download-button" href="/dashboard.php?tab=phone-card">手机卡领年卡</a><?php endif; ?>
                    <?php if ($paidCardBuyUrl !== ''): ?>
                        <a class="button-link" href="<?= h($paidCardBuyUrl) ?>" target="_blank" rel="noopener">购买付费卡密</a>
                    <?php endif; ?>
                </div>
            </article>
        </section>
    <?php elseif ($tab === 'phone-card'): ?>
        <section class="hero-panel claim-hero">
            <div class="hero-copy">
                <p class="eyebrow">Phone Card Reward</p>
                <h1>办理手机卡，免费领取一年付费节点</h1>
                <p class="hero-desc">通过本站专属链接办理手机卡，按要求提交订单资料。管理员审核通过后，系统会发放一张 360 天付费节点卡密，可在账号设置里用于升级或续费。</p>
            </div>
            <div class="metric-strip">
                <div><span>奖励</span><strong>360 天付费卡密</strong></div>
                <div><span>审核材料</span><strong>订单截图 + 激活预存截图</strong></div>
            </div>
        </section>

        <section class="main-grid claim-grid">
            <article class="panel primary-panel">
                <div class="section-head">
                    <div><p class="eyebrow">Submit</p><h2>提交审核资料</h2></div>
                    <span class="status-badge">人工审核</span>
                </div>
                <?php if ($phoneCardClaimRules !== ''): ?>
                    <div class="rule-box"><?= nl2br(h($phoneCardClaimRules)) ?></div>
                <?php endif; ?>
                <div class="actions actions-two claim-actions">
                    <?php if ($phoneCardApplyUrl !== ''): ?>
                        <a class="primary" href="<?= h($phoneCardApplyUrl) ?>" target="_blank" rel="noopener">打开手机卡办理链接</a>
                    <?php else: ?>
                        <div class="download-empty">管理员尚未配置手机卡办理链接</div>
                    <?php endif; ?>
                    <a class="button-link" href="/dashboard.php?tab=profile">已有卡密去升级</a>
                </div>
                <form method="post" class="stack claim-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="submit_phone_card_claim">
                    <label>手机卡平台订单号<input name="order_no" required maxlength="120" placeholder="请输入办理平台订单号"></label>
                    <div class="form-row-grid">
                        <label>办理手机卡订单截图<input name="order_image" type="file" accept="image/jpeg,image/png,image/webp" required></label>
                        <label>激活预存话费截图<input name="activate_image" type="file" accept="image/jpeg,image/png,image/webp" required></label>
                    </div>
                    <button class="primary" type="submit">提交给管理员审核</button>
                </form>
            </article>

            <article class="panel side-panel">
                <div class="section-head">
                    <div><p class="eyebrow">Records</p><h2>我的申请记录</h2></div>
                    <span class="status-badge"><?= count($phoneCardClaims) ?> 条</span>
                </div>
                <?php if (!$phoneCardClaims): ?>
                    <div class="download-empty">暂无申请记录</div>
                <?php else: ?>
                    <div class="claim-records">
                        <?php foreach ($phoneCardClaims as $claim): ?>
                            <div class="claim-record">
                                <div>
                                    <strong><?= h($claim['order_no']) ?></strong>
                                    <span><?= h(claim_status_label($claim['status'])) ?> · <?= h($claim['created_at']) ?></span>
                                </div>
                                <?php if (!empty($claim['card_key'])): ?>
                                    <button type="button" data-copy="<?= h($claim['card_key']) ?>">复制卡密 <?= h($claim['card_key']) ?></button>
                                <?php endif; ?>
                                <?php if (!empty($claim['admin_note'])): ?>
                                    <p class="muted"><?= h($claim['admin_note']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
                <p class="hero-desc">这里可以修改登录密码，也可以用新的未使用卡密升级或续费当前账号。</p>
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
                <div class="section-head"><div><p class="eyebrow">Upgrade</p><h2>卡密升级 / 续费</h2></div></div>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="renew_card">
                    <label>续费卡密<input name="card_key" required placeholder="XXXX-XXXX-XXXX"></label>
                    <button class="primary" type="submit"><?= $isFree ? '升级为付费用户' : '续费当前账号' ?></button>
                    <?php if ($isFree && $paidCardBuyUrl !== ''): ?>
                        <a class="button-link" href="<?= h($paidCardBuyUrl) ?>" target="_blank" rel="noopener">购买付费卡密</a>
                    <?php endif; ?>
                </form>
            </article>
        </section>
    <?php else: ?>
    <section class="hero-panel">
        <div class="hero-copy">
            <p class="eyebrow">账号 <?= h($user['username']) ?> · <?= h(plan_label($user['plan'] ?? 'paid')) ?></p>
            <h1><?= $isFree ? ($freeSubscriptionEnabled ? '免费订阅入口，随时可升级。' : '使用卡密升级后即可使用订阅。') : '一个订阅入口，自动匹配你的客户端。' ?></h1>
            <p class="hero-desc"><?= $isFree ? ($freeSubscriptionEnabled ? '当前为免费用户，订阅会拉取后台配置的免费上游。输入卡密后可升级为付费用户，解锁付费线路。' : '免费订阅当前已关闭。你的账号可以正常登录，请使用卡密升级为付费用户后拉取订阅。') : '复制主订阅链接即可使用；Clash、小火箭、V2Ray 等客户端会拉取后台配置的专属上游订阅，提升节点兼容性。' ?></p>
        </div>
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
                    <a class="metric-cta" href="/dashboard.php?tab=profile">使用卡密升级</a>
                    <?php if ($paidCardBuyUrl !== ''): ?>
                        <a class="metric-cta secondary" href="<?= h($paidCardBuyUrl) ?>" target="_blank" rel="noopener">购买付费卡密</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="main-grid">
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
                <div class="download-empty">免费订阅已关闭，请前往账号设置使用卡密升级。</div>
            <?php else: ?>
            <input class="copy-input" readonly value="<?= h($baseSubUrl) ?>">
            <div class="actions actions-two">
                <button class="primary" type="button" data-copy="<?= h($baseSubUrl) ?>">复制通用链接</button>
                <a class="button-link" href="clash://install-config?url=<?= rawurlencode(subscription_url($user, 'clash')) ?>&name=<?= rawurlencode(APP_NAME) ?>">导入 Clash</a>
            </div>
            <?php if (!$isFree): ?>
            <div class="client-grid">
                <?php foreach ($clientTypes as $type => $client): ?>
                    <?php $clientUrl = subscription_url($user, $type); ?>
                    <button type="button" data-copy="<?= h($clientUrl) ?>">
                        <span><?= h($client['name']) ?></span>
                        <small>复制专用链接</small>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="download-empty">免费用户统一使用免费订阅上游；升级后将显示各客户端专用链接。</div>
            <?php endif; ?>
            <?php endif; ?>
        </article>

        <?php if ($phoneCardClaimEnabled): ?>
        <article class="panel side-panel">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Reward</p>
                    <h2>办手机卡免费领年卡</h2>
                </div>
                <span class="status-badge">360 天</span>
            </div>
            <p class="muted">通过本站手机卡链接办卡，提交订单截图、激活预存话费截图和订单号，审核通过后领取一年付费节点卡密。</p>
            <a class="download-button" href="/dashboard.php?tab=phone-card">去提交资料</a>
        </article>
        <?php endif; ?>

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

        <?php if ($noticeEnabled && $notice !== ''): ?>
            <article class="panel notice-card">
                <p class="eyebrow">服务公告</p>
                <div class="notice-content"><?= safe_notice_html($notice) ?></div>
            </article>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>

<script src="/assets/app.js?v=20260728-copy-v2"></script>
</body>
</html>
