<?php
require_once __DIR__ . '/../lib.php';

$pdo = db();
$error = '';
$admin = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    verify_csrf();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $row = $stmt->fetch();
    if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
        $_SESSION['admin_id'] = $row['id'];
        redirect('/admin/index.php');
    }
    $error = '管理员账号或密码错误。';
}

if (!empty($_SESSION['admin_id'])) {
    $admin = require_admin();
}

if (!$admin):
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理员登录 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> auth-page">
<?php ui_auth_visual(); ?>
<main class="auth-shell">
    <div class="brand-mark auth-brand"><img class="brand-logo" src="/assets/mofa-hat.svg" alt=""><span class="brand-name"><?= h(APP_NAME) ?></span></div>
    <div class="auth-avatar-wrap"><img class="auth-avatar" src="<?= h(qq_avatar_url(admin_qq_number())) ?>" alt="管理员头像"></div>
    <h1>后台登录</h1>
    <p class="muted">请使用管理员账号登录管理控制台。</p>
    <?php if ($error): ?><div class="alert bad"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="login" value="1">
        <label>管理员账号<input name="username" required autocomplete="username"></label>
        <label>管理员密码<input name="password" type="password" required autocomplete="current-password"></label>
        <button class="primary" type="submit">进入后台</button>
    </form>
</main>
</body>
</html>
<?php exit; endif;

function admin_card_status_options(): array
{
    return [
        '' => '全部',
        'unused' => '未使用',
        'used' => '已使用',
        'disabled' => '已停用',
    ];
}

function admin_user_status_label(string $status): string
{
    return $status === 'banned' ? '已封禁' : '正常';
}

function admin_plan_options(): array
{
    return [
        '' => '全部',
        'free' => '免费用户',
        'paid' => '付费用户',
    ];
}

function admin_agent_status_label(string $status): string
{
    return $status === 'disabled' ? '已停用' : '正常';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_appearance') {
        $theme = $_POST['site_theme'] ?? '';
        if (!is_string($theme) || !isset(site_themes()[$theme])) {
            flash('请选择有效的主题。', 'bad');
        } else {
            update_setting('site_theme', $theme);
            flash('已将「' . site_themes()[$theme]['name'] . '」设为站点默认主题；浏览器个人主题偏好优先。');
        }
        redirect('/admin/index.php?tab=appearance');
    }

    if ($action === 'create_cards') {
        $count = max(1, min(500, (int)($_POST['count'] ?? 1)));
        $duration = max(1, (int)($_POST['duration_days'] ?? 30));
        $validDays = max(0, (int)($_POST['valid_days'] ?? 0));
        $validUntil = $validDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days')) : null;
        $stmt = $pdo->prepare('INSERT INTO cards (card_key, duration_days, valid_until, status, created_at) VALUES (?, ?, ?, ?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([generate_card_key(), $duration, $validUntil, 'unused', now()]);
        }
        flash('已生成 ' . $count . ' 张卡密。');
        redirect('/admin/index.php?tab=cards');
    }

    if ($action === 'update_card') {
        $status = in_array($_POST['status'] ?? '', ['unused', 'used', 'disabled'], true) ? $_POST['status'] : 'unused';
        $validUntil = trim($_POST['valid_until'] ?? '') ?: null;
        $pdo->prepare('UPDATE cards SET duration_days = ?, valid_until = ?, status = ? WHERE id = ?')
            ->execute([max(1, (int)$_POST['duration_days']), $validUntil, $status, (int)$_POST['id']]);
        flash('卡密已更新。');
        redirect('/admin/index.php?tab=cards');
    }

    if ($action === 'delete_card') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare('SELECT used_by FROM cards WHERE id = ?');
        $stmt->execute([$id]);
        $card = $stmt->fetch();
        if ($card && empty($card['used_by'])) {
            $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]);
            flash('卡密已删除。');
        } else {
            flash('已绑定用户的卡密不能直接删除，请先处理用户。', 'bad');
        }
        redirect('/admin/index.php?tab=cards');
    }

    if ($action === 'save_client_subscriptions') {
        $syncHours = filter_var($_POST['upstream_sync_hours'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>168]]);
        if ($syncHours === false) {
            flash('自动获取间隔请输入 1 至 168 小时的整数。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        $freeSubUrl = trim((string)($_POST['free_sub_url'] ?? ''));
        if ($freeSubUrl !== '' && !filter_var($freeSubUrl, FILTER_VALIDATE_URL)) {
            flash('免费用户订阅链接必须是完整 URL。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        $paidCardBuyUrl = trim((string)($_POST['paid_card_buy_url'] ?? ''));
        if ($paidCardBuyUrl !== '' && !filter_var($paidCardBuyUrl, FILTER_VALIDATE_URL)) {
            flash('购买付费卡密按钮链接必须是完整 URL。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        update_setting('free_sub_url', $freeSubUrl);
        update_setting('upstream_sync_hours', (string)$syncHours);
        update_setting('free_subscription_enabled', isset($_POST['free_subscription_enabled']) ? '1' : '0');
        update_setting('paid_card_buy_url', $paidCardBuyUrl);
        update_setting('paid_daily_pull_limit', (string)max(1, (int)($_POST['paid_daily_pull_limit'] ?? 10)));
        flash('订阅全局配置已保存。');
        redirect('/admin/index.php?tab=subs');
    }

    if ($action === 'save_subscription_line') {
        $id = max(0, (int)($_POST['id'] ?? 0));
        $name = trim((string)($_POST['name'] ?? ''));
        $upstreamInput = trim((string)($_POST['upstream_url'] ?? ''));
        $expireTime = trim((string)($_POST['expire_time'] ?? '')) ?: null;
        $totalGb = max(0, (float)($_POST['total_gb'] ?? 0));
        $uploadGb = max(0, (float)($_POST['upload_gb'] ?? 0));
        $downloadGb = max(0, (float)($_POST['download_gb'] ?? 0));
        $autoSyncEnabled = isset($_POST['auto_sync_enabled']) ? 1 : 0;
        $allowInsecureTls = isset($_POST['allow_insecure_tls']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['available', 'maintenance', 'disabled'], true)
            ? (string)$_POST['status'] : 'available';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') {
            flash('线路名称不能为空。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        $upstreamUrl = $upstreamInput;
        if ($upstreamUrl === '' || !filter_var($upstreamUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($upstreamUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            flash('上游链接必须是有效的 HTTP 或 HTTPS URL。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        if ($expireTime !== null && strtotime($expireTime) === false) {
            flash('线路到期时间格式无效。', 'bad');
            redirect('/admin/index.php?tab=subs');
        }
        if ($expireTime !== null) {
            $expireTime = date('Y-m-d H:i:s', (int)strtotime($expireTime));
        }
        $totalBytes = (int)round($totalGb * 1024 * 1024 * 1024);
        $uploadBytes = (int)round($uploadGb * 1024 * 1024 * 1024);
        $downloadBytes = (int)round($downloadGb * 1024 * 1024 * 1024);
        if ($id > 0) {
            $pdo->prepare('UPDATE subscription_lines SET name = ?, upstream_url = ?, expire_time = ?, total_bytes = ?, upload_bytes = ?, download_bytes = ?, auto_sync_enabled = ?, allow_insecure_tls = ?, status = ?, sort_order = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $upstreamUrl, $expireTime, $totalBytes, $uploadBytes, $downloadBytes, $autoSyncEnabled, $allowInsecureTls, $status, $sortOrder, now(), $id]);
            flash('机场线路已更新。');
        } else {
            $pdo->prepare('INSERT INTO subscription_lines (name, upstream_url, expire_time, total_bytes, upload_bytes, download_bytes, auto_sync_enabled, allow_insecure_tls, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $upstreamUrl, $expireTime, $totalBytes, $uploadBytes, $downloadBytes, $autoSyncEnabled, $allowInsecureTls, $status, $sortOrder, now(), now()]);
            $id = (int)$pdo->lastInsertId();
            flash('机场线路已添加。');
        }
        if (isset($_POST['sync_now'])) {
            $syncMessages = [];
            $syncFailed = false;
            try {
                sync_subscription_line($id);
                $syncMessages[] = '流量信息已更新';
            } catch (Throwable $e) {
                $syncFailed = true;
                $syncMessages[] = '流量信息失败：' . $e->getMessage();
            }
            try {
                $nodeResult = sync_clash_line_nodes($id);
                $syncMessages[] = '已解析 ' . (int)$nodeResult['node_count'] . ' 个 Clash 节点';
            } catch (Throwable $e) {
                $syncFailed = true;
                $syncMessages[] = '节点解析失败：' . $e->getMessage();
            }
            flash('线路已保存；' . implode('；', $syncMessages) . '。', $syncFailed ? 'bad' : 'ok');
        }
        redirect('/admin/index.php?tab=subs');
    }

    if ($action === 'sync_subscription_line') {
        $syncMessages = [];
        $syncFailed = false;
        try {
            sync_subscription_line((int)($_POST['id'] ?? 0));
            $syncMessages[] = '流量和到期信息已更新';
        } catch (Throwable $e) {
            $syncFailed = true;
            $syncMessages[] = '流量信息失败：' . $e->getMessage();
        }
        try {
            $nodeResult = sync_clash_line_nodes((int)($_POST['id'] ?? 0));
            $syncMessages[] = '已解析 ' . (int)$nodeResult['node_count'] . ' 个 Clash 节点';
        } catch (Throwable $e) {
            $syncFailed = true;
            $syncMessages[] = '节点解析失败：' . $e->getMessage();
        }
        flash(implode('；', $syncMessages) . '。', $syncFailed ? 'bad' : 'ok');
        redirect('/admin/index.php?tab=subs');
    }

    if ($action === 'save_node_overrides') {
        $lineId = (int)($_POST['line_id'] ?? 0);
        try {
            $submitted = json_decode((string)($_POST['node_payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($submitted)) throw new RuntimeException('节点表单无效，请刷新重试。');
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM subscription_lines WHERE id = ? FOR UPDATE');
            $lock->execute([$lineId]);
            if (!$lock->fetch()) throw new RuntimeException('线路不存在。');
            $updates = validate_node_settings(clash_nodes_for_line($lineId), $submitted);
            $update = $pdo->prepare('UPDATE subscription_node_overrides SET custom_name = ?, enabled = ?, sort_order = ? WHERE line_id = ? AND node_key = ? AND is_present = 1');
            foreach ($updates as $row) {
                $update->execute([$row['custom_name'], $row['enabled'], $row['sort_order'], $lineId, $row['node_key']]);
            }
            // Validate the actual generated document before committing the submitted settings.
            if (build_clash_subscription($lineId) === null) throw new RuntimeException('请先同步解析节点。');
            $pdo->commit();
            flash('已保存 ' . count($updates) . ' 个节点设置，可点击“预览生成订阅”核对；客户端需更新本站订阅。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('保存失败：' . $e->getMessage(), 'bad');
        }
        redirect('/admin/index.php?tab=subs#nodes-' . $lineId);
    }

    if ($action === 'delete_subscription_line') {
        $pdo->prepare('DELETE FROM subscription_lines WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('机场线路已删除。');
        redirect('/admin/index.php?tab=subs');
    }

    if ($action === 'save_agent') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $balance = max(0, round((float)($_POST['balance'] ?? 0), 2));
        $cardPrice = max(0, round((float)($_POST['card_price'] ?? 0), 2));
        $status = in_array($_POST['status'] ?? '', ['active', 'disabled'], true) ? $_POST['status'] : 'active';

        if (strlen($username) < 3) {
            flash('代理账号至少 3 位。', 'bad');
        } elseif ($id === 0 && strlen($password) < 6) {
            flash('新代理密码至少 6 位。', 'bad');
        } elseif ($id > 0) {
            try {
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        flash('代理新密码至少 6 位。', 'bad');
                        redirect('/admin/index.php?tab=agents');
                    }
                    $pdo->prepare('UPDATE agents SET username = ?, password_hash = ?, balance = ?, card_price = ?, status = ?, updated_at = ? WHERE id = ?')
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $balance, $cardPrice, $status, now(), $id]);
                } else {
                    $pdo->prepare('UPDATE agents SET username = ?, balance = ?, card_price = ?, status = ?, updated_at = ? WHERE id = ?')
                        ->execute([$username, $balance, $cardPrice, $status, now(), $id]);
                }
                flash('代理账号已更新。');
            } catch (PDOException $e) {
                flash('代理账号已存在，请换一个账号。', 'bad');
            }
        } else {
            try {
                $pdo->prepare('INSERT INTO agents (username, password_hash, balance, card_price, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $balance, $cardPrice, $status, now(), now()]);
                flash('代理账号已创建。');
            } catch (PDOException $e) {
                flash('代理账号已存在，请换一个账号。', 'bad');
            }
        }
        redirect('/admin/index.php?tab=agents');
    }

    if ($action === 'delete_agent') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM agent_card_logs WHERE agent_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetch()['total'] > 0) {
            $pdo->prepare("UPDATE agents SET status = 'disabled', updated_at = ? WHERE id = ?")->execute([now(), $id]);
            flash('该代理已有生成记录，已改为停用。');
        } else {
            $pdo->prepare('DELETE FROM agents WHERE id = ?')->execute([$id]);
            flash('代理账号已删除。');
        }
        redirect('/admin/index.php?tab=agents');
    }

    if (in_array($action, ['save_user_plan', 'add_user_days', 'subtract_user_days'], true)) {
        try {
            flash(admin_change_subscription($pdo, (int)($_POST['id'] ?? 0), $action, $_POST));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect('/admin/index.php?tab=users');
    }

    if ($action === 'update_user_status') {
        $status = in_array($_POST['status'] ?? '', ['active', 'banned'], true) ? $_POST['status'] : 'active';
        $banReason = $status === 'banned' ? trim((string)($_POST['ban_reason'] ?? '管理员手动封禁')) : null;
        $bannedAt = $status === 'banned' ? now() : null;
        $userId = (int)$_POST['id'];
        $previousStatusStmt = $pdo->prepare('SELECT status, expire_time FROM users WHERE id = ?');
        $previousStatusStmt->execute([$userId]);
        $previousUser = $previousStatusStmt->fetch();
        $previousStatus = (string)($previousUser['status'] ?? '');
        $pdo->prepare('UPDATE users SET status = ?, ban_reason = ?, banned_at = ? WHERE id = ?')
            ->execute([$status, $banReason, $bannedAt, $userId]);
        if ($status === 'active' && $previousStatus === 'banned') {
            $pdo->prepare("DELETE FROM subscription_access_logs WHERE user_id = ? AND user_plan = 'paid' AND success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")
                ->execute([$userId]);
        }
        flash('用户状态已更新。');
        redirect('/admin/index.php?tab=users');
    }

    if ($action === 'create_compensation') {
        $title = trim((string)($_POST['title'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $days = max(1, min(3650, (int)($_POST['duration_days'] ?? 1)));
        $targetPlan = in_array($_POST['target_plan'] ?? '', ['all', 'free', 'paid'], true) ? $_POST['target_plan'] : 'all';
        if ($title === '' || $reason === '') {
            flash('请填写补偿标题和补偿原因。', 'bad');
            redirect('/admin/index.php?tab=compensation');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO compensation_campaigns (title, reason, duration_days, target_plan, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$title, $reason, $days, $targetPlan, 'active', now()]);
            $campaignId = (int)$pdo->lastInsertId();
            $sql = 'INSERT INTO compensation_recipients (campaign_id, user_id, created_at) SELECT ?, id, ? FROM users';
            $params = [$campaignId, now()];
            if ($targetPlan !== 'all') { $sql .= ' WHERE plan = ?'; $params[] = $targetPlan; }
            $recipientStmt = $pdo->prepare($sql);
            $recipientStmt->execute($params);
            $recipientCount = $recipientStmt->rowCount();
            $pdo->commit();
            flash('补偿活动已创建，共 ' . $recipientCount . ' 名现有用户可领取。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('创建补偿失败：' . $e->getMessage(), 'bad');
        }
        redirect('/admin/index.php?tab=compensation');
    }

    if ($action === 'toggle_compensation') {
        $pdo->prepare("UPDATE compensation_campaigns SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ?")
            ->execute([(int)($_POST['id'] ?? 0)]);
        flash('补偿活动状态已更新。');
        redirect('/admin/index.php?tab=compensation');
    }

    if ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND status = 'open'");
        $stmt->execute([$ticketId]);
        if (!$stmt->fetch()) flash('工单不存在或已经结束。', 'bad');
        elseif ($message === '') flash('回复内容不能为空。', 'bad');
        else {
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, attachment, created_at) VALUES (?, 'admin', ?, NULL, ?)")->execute([$ticketId, $message, now()]);
            $pdo->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([now(), $ticketId]);
            flash('工单回复已发送。');
        }
        redirect('/admin/index.php?tab=tickets');
    }

    if ($action === 'toggle_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT status FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if ($ticket) {
            $next = $ticket['status'] === 'open' ? 'closed' : 'open';
            $pdo->prepare('UPDATE tickets SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?')->execute([$next, $next === 'closed' ? now() : null, now(), $ticketId]);
            flash($next === 'closed' ? '工单已结束。' : '工单已重新打开。');
        }
        redirect('/admin/index.php?tab=tickets');
    }

    if ($action === 'reset_user_pull_count') {
        $userId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM subscription_access_logs WHERE user_id = ? AND user_plan = 'paid' AND success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")
            ->execute([$userId]);
        flash('该用户的 24 小时订阅拉取次数已重置。');
        redirect('/admin/index.php?tab=users');
    }

    if ($action === 'reset_user_password') {
        $password = trim((string)($_POST['password'] ?? ''));
        if (strlen($password) < 6) {
            flash('用户新密码至少 6 位。', 'bad');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$_POST['id']]);
            flash('用户密码已重置。');
        }
        redirect('/admin/index.php?tab=users');
    }

    if ($action === 'save_notice') {
        $docsUrl = trim((string)($_POST['docs_url'] ?? ''));
        $docsScheme = strtolower((string)parse_url($docsUrl, PHP_URL_SCHEME));
        if ($docsUrl !== '' && (!filter_var($docsUrl, FILTER_VALIDATE_URL) || !in_array($docsScheme, ['http', 'https'], true))) {
            flash('使用文档必须填写完整有效的 HTTP 或 HTTPS URL。', 'bad');
            redirect('/admin/index.php?tab=notice');
        }
        update_setting('site_notice', trim((string)($_POST['site_notice'] ?? '')));
        update_setting('notice_popup_enabled', isset($_POST['notice_popup_enabled']) ? '1' : '0');
        update_setting('faq_enabled', isset($_POST['faq_enabled']) ? '1' : '0');
        update_setting('faq_content', trim((string)($_POST['faq_content'] ?? '')));
        update_setting('docs_url', $docsUrl);
        update_setting('popup_notice_enabled', isset($_POST['popup_notice_enabled']) ? '1' : '0');
        update_setting('popup_notice_title', trim((string)($_POST['popup_notice_title'] ?? '服务公告')));
        update_setting('popup_notice_content', trim((string)($_POST['popup_notice_content'] ?? '')));
        update_setting('download_block_enabled', isset($_POST['download_block_enabled']) ? '1' : '0');
        update_setting('download_app_name', trim((string)($_POST['download_app_name'] ?? '')));
        update_setting('download_app_desc', trim((string)($_POST['download_app_desc'] ?? '')));
        update_setting('download_icon_url', trim((string)($_POST['download_icon_url'] ?? '')));
        update_setting('download_url', trim((string)($_POST['download_url'] ?? '')));
        flash('公告与软件下载设置已保存。');
        redirect('/admin/index.php?tab=notice');
    }
    if ($action === 'save_payment_settings') { foreach(['purchase_mode','alipay_app_id','alipay_private_key','alipay_public_key','alipay_gateway','paid_card_buy_url'] as $k) { $value = trim((string)($_POST[$k]??'')); if ($k === 'alipay_private_key' && $value === '') continue; update_setting($k, $value); } flash('支付设置已保存。');redirect('/admin/index.php?tab=payment'); }
    if ($action === 'save_payment_plan') { $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$price=round((float)($_POST['price']??0),2);$days=(int)($_POST['duration_days']??0);$status=($_POST['status']??'active')==='disabled'?'disabled':'active';if($name===''||$price<=0||$days<=0||$days>3650)flash('套餐名称、价格必须有效，天数必须为 1 至 3650 天。','bad');elseif($id){$pdo->prepare('UPDATE payment_plans SET name=?,price=?,duration_days=?,status=?,updated_at=? WHERE id=?')->execute([$name,$price,$days,$status,now(),$id]);flash('套餐已更新。');}else{$pdo->prepare('INSERT INTO payment_plans(name,price,duration_days,status,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute([$name,$price,$days,$status,now(),now()]);flash('套餐已添加。');}redirect('/admin/index.php?tab=payment'); }
    if ($action === 'delete_payment_plan') { $pdo->prepare("UPDATE payment_plans SET status='disabled',updated_at=? WHERE id=?")->execute([now(),(int)$_POST['id']]);flash('套餐已停用。');redirect('/admin/index.php?tab=payment'); }
    if ($action === 'admin_payment_order') {
        $orderNo = trim((string)($_POST['order_no'] ?? ''));
        $decision = (string)($_POST['decision'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM payment_orders WHERE order_no = ?');
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();
        if (!$order) {
            flash('订单不存在。', 'bad');
        } elseif ($decision === 'fulfill' && in_array($order['status'], ['pending', 'failed'], true)) {
            try {
                fulfill_payment_order($orderNo, 'MANUAL-' . date('YmdHis'), (string)$order['amount']);
                flash('订单已补单，用户时长已发放。');
            } catch (Throwable $e) { flash('补单失败：' . $e->getMessage(), 'bad'); }
        } elseif ($decision === 'close' && $order['status'] === 'pending') {
            try { alipay_request('alipay.trade.close', ['out_trade_no' => $orderNo]); } catch (Throwable $e) { /* 本地仍允许关闭，避免订单卡死。 */ }
            $pdo->prepare("UPDATE payment_orders SET status='closed',updated_at=? WHERE order_no=?")->execute([now(), $orderNo]);
            flash('订单已关闭。');
        } else { flash('当前订单状态不允许执行该操作。', 'bad'); }
        redirect('/admin/index.php?tab=orders');
    }

    if ($action === 'save_popup_notice') {
        update_setting('popup_notice_enabled', isset($_POST['popup_notice_enabled']) ? '1' : '0');
        update_setting('popup_notice_once', isset($_POST['popup_notice_once']) ? '1' : '0');
        update_setting('popup_notice_title', trim((string)($_POST['popup_notice_title'] ?? '服务公告')));
        update_setting('popup_notice_content', trim((string)($_POST['popup_notice_content'] ?? '')));
        flash('弹窗公告设置已保存。');
        redirect('/admin/index.php?tab=popup');
    }

    if ($action === 'save_admin_profile') {
        $adminQq = trim((string)($_POST['admin_qq_number'] ?? ''));
        if ($adminQq !== '' && !preg_match('/^[1-9][0-9]{4,19}$/', $adminQq)) {
            flash('管理员 QQ 号格式不正确。', 'bad');
        } else {
            update_setting('admin_qq_number', $adminQq ?: '10019');
            flash('管理员头像设置已保存。');
        }
        redirect('/admin/index.php?tab=settings');
    }

    if ($action === 'change_password') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            flash('新密码至少 8 位。', 'bad');
        } else {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            flash('管理员密码已修改。');
        }
        redirect('/admin/index.php?tab=settings');
    }
}

$tab = $_GET['tab'] ?? 'cards';
if ($tab === 'phone_claims') redirect('/admin/index.php?tab=cards');
if ($tab === 'popup') {
    $tab = 'notice';
}
$cardStatusFilter = $_GET['card_status'] ?? '';
$cardQqFilter = trim((string)($_GET['card_qq'] ?? ''));
$cardExport = $_GET['export_cards'] ?? '';
$orderStatusFilter = in_array($_GET['order_status'] ?? '', ['pending', 'paid', 'closed', 'failed'], true) ? (string)$_GET['order_status'] : '';
$orderKeyword = trim((string)($_GET['order_keyword'] ?? ''));
$orderWhere = [];
$orderParams = [];
if ($orderStatusFilter !== '') { $orderWhere[] = 'payment_orders.status = ?'; $orderParams[] = $orderStatusFilter; }
if ($orderKeyword !== '') { $orderWhere[] = '(payment_orders.order_no LIKE ? OR payment_orders.plan_name LIKE ? OR users.username LIKE ? OR users.qq_number LIKE ?)'; array_push($orderParams, '%' . $orderKeyword . '%', '%' . $orderKeyword . '%', '%' . $orderKeyword . '%', '%' . $orderKeyword . '%'); }
$orderSql = 'SELECT payment_orders.*, users.username, users.qq_number FROM payment_orders JOIN users ON users.id = payment_orders.user_id';
if ($orderWhere) $orderSql .= ' WHERE ' . implode(' AND ', $orderWhere);
$orderSql .= ' ORDER BY payment_orders.id DESC LIMIT 300';
$orderStmt = $pdo->prepare($orderSql); $orderStmt->execute($orderParams); $paymentOrders = $orderStmt->fetchAll();
$cardSql = 'SELECT cards.*, users.username AS used_username, users.qq_number AS used_qq_number FROM cards LEFT JOIN users ON users.id = cards.used_by';
$cardWhere = [];
$cardParams = [];
if (in_array($cardStatusFilter, ['unused', 'used', 'disabled'], true)) {
    $cardWhere[] = 'cards.status = ?';
    $cardParams[] = $cardStatusFilter;
    if ($cardStatusFilter === 'unused') {
        $cardWhere[] = 'cards.used_by IS NULL';
    }
}
if ($cardQqFilter !== '') {
    $cardWhere[] = '(users.qq_number = ? OR users.username = ?)';
    $cardParams[] = $cardQqFilter;
    $cardParams[] = $cardQqFilter;
}
if ($cardWhere) {
    $cardSql .= ' WHERE ' . implode(' AND ', $cardWhere);
}
$cardSql .= ' ORDER BY cards.id DESC LIMIT 500';
$cardStmt = $pdo->prepare($cardSql);
$cardStmt->execute($cardParams);
$cards = $cardStmt->fetchAll();

if (in_array($cardExport, ['csv', 'txt'], true)) {
    $filename = 'cards-' . date('Ymd-His') . '.' . $cardExport;
    header('Content-Type: ' . ($cardExport === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if ($cardExport === 'csv') {
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['卡密', '状态', '账号时长', '未使用有效期', '绑定用户', '绑定QQ', '生成时间']);
        foreach ($cards as $card) {
            fputcsv($out, [
                $card['card_key'],
                card_state($card),
                (int)$card['duration_days'] . '天',
                $card['valid_until'] ?: '不限',
                $card['used_username'] ?: '未绑定',
                $card['used_qq_number'] ?: '未绑定',
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

$userKeyword = trim((string)($_GET['user_keyword'] ?? ''));
$userStatusFilter = $_GET['user_status'] ?? '';
$userExpireFilter = $_GET['user_expire'] ?? '';
$userPlanFilter = $_GET['user_plan'] ?? '';
$userSql = "
    SELECT users.*, cards.card_key, cards.status AS card_status,
        COALESCE(pull_stats.pull_count_24h, 0) AS pull_count_24h,
        pull_stats.last_pull_at
    FROM users
    LEFT JOIN cards ON cards.id = users.card_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS pull_count_24h, MAX(created_at) AS last_pull_at
        FROM subscription_access_logs
        WHERE user_plan = 'paid' AND success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY user_id
    ) AS pull_stats ON pull_stats.user_id = users.id
";
$userWhere = [];
$userParams = [];
if ($userKeyword !== '') {
    $userWhere[] = '(users.username LIKE ? OR users.qq_number LIKE ? OR cards.card_key LIKE ?)';
    $like = '%' . $userKeyword . '%';
    $userParams[] = $like;
    $userParams[] = $like;
    $userParams[] = $like;
}
if (in_array($userStatusFilter, ['active', 'banned'], true)) {
    $userWhere[] = 'users.status = ?';
    $userParams[] = $userStatusFilter;
}
if (in_array($userPlanFilter, ['free', 'paid'], true)) {
    $userWhere[] = 'users.plan = ?';
    $userParams[] = $userPlanFilter;
}
if ($userExpireFilter === 'expired') {
    $userWhere[] = "users.plan = 'paid' AND users.expire_time < ?";
    $userParams[] = now();
} elseif ($userExpireFilter === 'valid') {
    $userWhere[] = "users.plan = 'paid' AND users.expire_time >= ?";
    $userParams[] = now();
}
if ($userWhere) {
    $userSql .= ' WHERE ' . implode(' AND ', $userWhere);
}
$userSql .= ' ORDER BY users.id DESC LIMIT 500';
$userStmt = $pdo->prepare($userSql);
$userStmt->execute($userParams);
$users = $userStmt->fetchAll();
$agents = $pdo->query('
    SELECT agents.*,
        COALESCE(stats.generated_cards, 0) AS generated_cards,
        COALESCE(stats.spent_total, 0) AS spent_total
    FROM agents
    LEFT JOIN (
        SELECT agent_id, COUNT(*) AS generated_cards, SUM(price) AS spent_total
        FROM agent_card_logs
        GROUP BY agent_id
    ) AS stats ON stats.agent_id = agents.id
    ORDER BY agents.id DESC
')->fetchAll();
$notice = get_setting('site_notice');
$noticeEnabled = get_setting('notice_popup_enabled', '1') === '1';
$faqEnabled = get_setting('faq_enabled', '1') === '1';
$faqContent = get_setting('faq_content', '');
$docsUrl = get_setting('docs_url', '');
$popupNoticeEnabled = get_setting('popup_notice_enabled', '1') === '1';
$popupNoticeOnce = get_setting('popup_notice_once', '1') === '1';
$popupNoticeTitle = get_setting('popup_notice_title', '服务公告');
$popupNoticeContent = get_setting('popup_notice_content', '');
$downloadEnabled = get_setting('download_block_enabled', '1') === '1';
$downloadName = get_setting('download_app_name', 'Clash Verge');
$downloadDesc = get_setting('download_app_desc', '推荐使用的订阅客户端');
$downloadIcon = get_setting('download_icon_url');
$downloadUrl = get_setting('download_url');
$adminQq = admin_qq_number();
$freeSubUrl = get_setting('free_sub_url', '');
$paidCardBuyUrl = get_setting('paid_card_buy_url', '');
$paidDailyPullLimit = paid_pull_limit();
$freeSubscriptionEnabled = free_subscription_enabled();
$compensations = $pdo->query("SELECT c.*, COUNT(r.id) AS recipient_count, SUM(r.claimed_at IS NOT NULL) AS claimed_count FROM compensation_campaigns c LEFT JOIN compensation_recipients r ON r.campaign_id = c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 30")->fetchAll();
$tickets = $pdo->query('SELECT tickets.*, users.username, users.qq_number FROM tickets JOIN users ON users.id = tickets.user_id ORDER BY (tickets.status = \'open\') DESC, tickets.updated_at DESC LIMIT 200')->fetchAll();
$adminTicketMessages = [];
if ($tickets) {
    $ticketIds = array_column($tickets, 'id');
    $ticketPlaceholders = implode(',', array_fill(0, count($ticketIds), '?'));
    $ticketMessageStmt = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id IN ($ticketPlaceholders) ORDER BY id ASC");
    $ticketMessageStmt->execute($ticketIds);
    foreach ($ticketMessageStmt->fetchAll() as $ticketMessage) $adminTicketMessages[(int)$ticketMessage['ticket_id']][] = $ticketMessage;
}
$openTicketCount = count(array_filter($tickets, fn($ticket) => $ticket['status'] === 'open'));
$paymentPlans=$pdo->query('SELECT * FROM payment_plans ORDER BY id DESC')->fetchAll();
$purchaseMode=get_setting('purchase_mode','link');$alipayAppId=get_setting('alipay_app_id');$alipayPrivateKey=get_setting('alipay_private_key');$alipayPublicKey=get_setting('alipay_public_key');$alipayGateway=get_setting('alipay_gateway','https://openapi.alipay.com/gateway.do');
$subscriptionLines = subscription_lines();
$subscriptionNodesByLine = [];
$subscriptionCacheByLine = [];
foreach ($pdo->query('SELECT line_id, node_count, fetched_at FROM subscription_line_caches')->fetchAll() as $cacheRow) {
    $subscriptionCacheByLine[(int)$cacheRow['line_id']] = $cacheRow;
}
foreach ($pdo->query('SELECT * FROM subscription_node_overrides WHERE is_present = 1 ORDER BY line_id ASC, sort_order ASC, id ASC')->fetchAll() as $nodeRow) {
    $subscriptionNodesByLine[(int)$nodeRow['line_id']][] = $nodeRow;
}
$siteTheme = site_theme();
$renewLogs = $pdo->query('
    SELECT renew_logs.*, users.username, users.qq_number, cards.card_key
    FROM renew_logs
    JOIN users ON users.id = renew_logs.user_id
    JOIN cards ON cards.id = renew_logs.card_id
    ORDER BY renew_logs.id DESC
    LIMIT 100
')->fetchAll();
$flash = flash();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台管理 - <?= h(APP_NAME) ?></title>
    <?php ui_head(); ?>
</head>
<body class="<?= h(site_theme_class()) ?> ui-page">
<?php ui_shell('admin', $tab, (string)$admin['username']); ?>
<main id="main-content" class="ui-content" tabindex="-1">
        <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

        <?php if ($tab === 'cards'): ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Cards</p><h1>卡密管理</h1></div>
                    <span class="status-badge"><?= count($cards) ?> 张</span>
                </div>
                <form method="post" class="form-row">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_cards">
                    <label>生成数量<input name="count" type="number" min="1" max="500" value="10"></label>
                    <label>账号有效天数<input name="duration_days" type="number" min="1" value="30"></label>
                    <label>未使用有效天数<input name="valid_days" type="number" min="0" value="0"></label>
                    <button class="primary" type="submit">批量生成</button>
                </form>
                <form method="get" class="form-row filter-row">
                    <input type="hidden" name="tab" value="cards">
                    <label>绑定 QQ 搜索<input name="card_qq" value="<?= h($cardQqFilter) ?>" placeholder="输入 QQ 号"></label>
                    <label>卡密状态<select name="card_status">
                        <?php foreach (admin_card_status_options() as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $cardStatusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <button type="submit">筛选</button>
                    <a class="button-link" href="/admin/index.php?tab=cards">重置</a>
                </form>
                <?php $cardExportQuery = http_build_query(['tab' => 'cards', 'card_qq' => $cardQqFilter, 'card_status' => $cardStatusFilter]); ?>
                <div class="actions actions-two export-actions">
                    <a class="button-link" href="/admin/index.php?<?= h($cardExportQuery) ?>&export_cards=txt">导出卡密 TXT</a>
                    <a class="button-link" href="/admin/index.php?<?= h($cardExportQuery) ?>&export_cards=csv">导出明细 CSV</a>
                </div>
            </section>
            <section class="panel table-wrap">
                <table>
                    <thead><tr><th>卡密</th><th>状态</th><th>账号时长</th><th>未使用有效期</th><th>绑定用户</th><th>绑定 QQ</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $card): ?>
                        <tr>
                            <td><span class="badge"><?= h($card['card_key']) ?></span></td>
                            <td><?= h(card_state($card)) ?></td>
                            <td><?= (int)$card['duration_days'] ?> 天</td>
                            <td><?= h($card['valid_until'] ?: '不限') ?></td>
                            <td><?= h($card['used_username'] ?: '未绑定') ?></td>
                            <td><?= h($card['used_qq_number'] ?: '未绑定') ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_card">
                                    <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">
                                    <input name="duration_days" type="number" min="1" value="<?= (int)$card['duration_days'] ?>">
                                    <input name="valid_until" placeholder="YYYY-mm-dd HH:ii:ss" value="<?= h($card['valid_until']) ?>">
                                    <select name="status">
                                        <option value="unused" <?= $card['status'] === 'unused' ? 'selected' : '' ?>>未使用</option>
                                        <option value="used" <?= $card['status'] === 'used' ? 'selected' : '' ?>>已使用</option>
                                        <option value="disabled" <?= $card['status'] === 'disabled' ? 'selected' : '' ?>>已停用</option>
                                    </select>
                                    <button type="submit">保存</button>
                                </form>
                                <form method="post" class="inline-form compact-form" onsubmit="return confirm('确定删除这张未使用卡密？')">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_card">
                                    <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">
                                    <button class="danger" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($tab === 'subs'): ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Subscription Lines</p><h1>机场线路管理</h1></div>
                    <span class="status-badge"><?= count($subscriptionLines) ?> 条线路</span>
                </div>
                <p class="muted">每条线路只需填写一个上游链接；自动同步时保留自定义名称、启停状态和排序。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_subscription_line">
                    <div class="form-row-grid">
                        <label>线路名称<input name="name" required maxlength="120" placeholder="例如：香港高速线路"></label>
                        <label>上游链接<input name="upstream_url" type="url" required placeholder="https://example.com/sub?token=..."><small>统一用于节点解析和流量同步</small></label>
                        <label>到期时间<input name="expire_time" type="datetime-local"></label>
                        <label>流量总量（GB，0 表示不限）<input name="total_gb" type="number" min="0" step="0.01" value="0"></label>
                        <label>已上传流量（GB）<input name="upload_gb" type="number" min="0" step="0.01" value="0"></label>
                        <label>已下载流量（GB）<input name="download_gb" type="number" min="0" step="0.01" value="0"></label>
                        <label>线路状态<select name="status"><option value="available">可用（绿色）</option><option value="maintenance">维护中（黄色）</option><option value="disabled">停用（红色）</option></select></label>
                        <label>排序<input name="sort_order" type="number" value="0"><small>数字越小越靠前</small></label>
                    </div>
                    <label class="toggle-row"><input type="checkbox" name="auto_sync_enabled" value="1" checked><span>自动更新节点和流量信息（间隔在下方订阅配置中设置）</span></label>
                    <label class="toggle-row"><input type="checkbox" name="allow_insecure_tls" value="1"><span>兼容异常 HTTPS 证书（仅在证书校验失败时开启）</span></label>
                    <label class="toggle-row"><input type="checkbox" name="sync_now" value="1" checked><span>保存后立即从上游获取流量和到期信息</span></label>
                    <button class="primary" type="submit">添加机场线路</button>
                </form>
            </section>

            <?php foreach ($subscriptionLines as $line): ?>
            <section class="panel subscription-line-admin">
                <div class="section-head">
                    <div><p class="eyebrow">Line #<?= (int)$line['id'] ?></p><h2><?= h($line['name']) ?></h2></div>
                    <span class="line-status line-status-<?= h($line['status']) ?>"><?= h(subscription_line_status_label($line['status'])) ?></span>
                </div>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_subscription_line">
                    <input type="hidden" name="id" value="<?= (int)$line['id'] ?>">
                    <div class="form-row-grid">
                        <label>线路名称<input name="name" required maxlength="120" value="<?= h($line['name']) ?>"></label>
                        <label>上游链接<input name="upstream_url" type="url" required value="<?= h(subscription_line_upstream($line, 'clash')) ?>"></label>
                        <label>到期时间<input name="expire_time" type="datetime-local" value="<?= $line['expire_time'] ? h(date('Y-m-d\TH:i', strtotime($line['expire_time']))) : '' ?>"></label>
                        <label>流量总量（GB）<input name="total_gb" type="number" min="0" step="0.01" value="<?= h(number_format((int)$line['total_bytes'] / 1073741824, 2, '.', '')) ?>"></label>
                        <label>已上传流量（GB）<input name="upload_gb" type="number" min="0" step="0.01" value="<?= h(number_format((int)$line['upload_bytes'] / 1073741824, 2, '.', '')) ?>"></label>
                        <label>已下载流量（GB）<input name="download_gb" type="number" min="0" step="0.01" value="<?= h(number_format((int)$line['download_bytes'] / 1073741824, 2, '.', '')) ?>"></label>
                        <label>线路状态<select name="status"><option value="available" <?= $line['status'] === 'available' ? 'selected' : '' ?>>可用（绿色）</option><option value="maintenance" <?= $line['status'] === 'maintenance' ? 'selected' : '' ?>>维护中（黄色）</option><option value="disabled" <?= $line['status'] === 'disabled' ? 'selected' : '' ?>>停用（红色）</option></select></label>
                        <label>排序<input name="sort_order" type="number" value="<?= (int)$line['sort_order'] ?>"></label>
                    </div>
                    <label class="toggle-row"><input type="checkbox" name="auto_sync_enabled" value="1" <?= (int)($line['auto_sync_enabled'] ?? 1) === 1 ? 'checked' : '' ?>><span>自动更新节点和流量信息（间隔在下方订阅配置中设置）</span></label>
                    <label class="toggle-row"><input type="checkbox" name="allow_insecure_tls" value="1" <?= !empty($line['allow_insecure_tls']) ? 'checked' : '' ?>><span>兼容异常 HTTPS 证书（会降低该线路的连接安全性）</span></label>
                    <label class="toggle-row"><input type="checkbox" name="sync_now" value="1"><span>保存后立即同步流量信息并重新解析 Clash 节点</span></label>
                    <button class="primary" type="submit">保存线路</button>
                </form>
                <div class="line-sync-summary">
                    <span>上次同步：<?= h($line['last_sync_at'] ?: '尚未同步') ?></span>
                    <span>状态：<?= $line['sync_status'] === 'success' ? '成功' : ($line['sync_status'] === 'failed' ? '失败' : '未同步') ?></span>
                    <?php if (!empty($line['sync_error'])): ?><span class="sync-error"><?= h($line['sync_error']) ?></span><?php endif; ?>
                </div>
                <form method="post" class="compact-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="sync_subscription_line">
                    <input type="hidden" name="id" value="<?= (int)$line['id'] ?>">
                    <button type="submit">同步上游并解析节点</button>
                </form>
                <?php
                    $lineId = (int)$line['id'];
                    $lineNodes = $subscriptionNodesByLine[$lineId] ?? [];
                    $lineCache = $subscriptionCacheByLine[$lineId] ?? null;
                ?>
                <section class="clash-node-editor" id="nodes-<?= $lineId ?>">
                    <div class="section-head compact-head">
                        <div>
                            <p class="eyebrow">Clash Nodes</p>
                            <h3>节点名称与输出顺序</h3>
                        </div>
                        <span class="status-badge"><?= count($lineNodes) ?> 个节点</span>
                    </div>
                    <?php if ($lineCache): ?>
                        <p class="muted">节点缓存更新于 <?= h($lineCache['fetched_at']) ?>。生成订阅时使用“一元机场”模板；上游暂时不可用时仍可使用这份缓存。</p>
                    <?php else: ?>
                        <p class="muted">尚未解析节点。点击“同步上游并解析节点”，系统会从 Clash 订阅的 <code>proxies</code> 区段读取节点。</p>
                    <?php endif; ?>
                    <?php if ($lineNodes): ?>
                        <form method="post" class="stack node-override-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_node_overrides">
                            <input type="hidden" name="line_id" value="<?= $lineId ?>">
                            <input type="hidden" name="node_payload" value="">
                            <p class="muted">保存后请在客户端更新本站订阅。<a href="/admin/preview_subscription.php?line=<?= $lineId ?>" target="_blank" rel="noopener">预览生成订阅</a></p>
                            <noscript><p class="alert bad">请启用 JavaScript 后保存节点设置。</p></noscript>
                            <div class="table-wrap">
                                <table class="node-override-table">
                                    <thead><tr><th>启用</th><th>上游名称</th><th>自定义名称</th><th>排序</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lineNodes as $node): $nodeKey = (string)$node['node_key']; ?>
                                        <tr>
                                            <td><input type="checkbox" name="node_enabled[<?= h($nodeKey) ?>]" value="1" <?= !empty($node['enabled']) ? 'checked' : '' ?> aria-label="启用节点"></td>
                                            <td class="node-original-name"><?= h($node['original_name']) ?></td>
                                            <td><input name="custom_name[<?= h($nodeKey) ?>]" maxlength="255" value="<?= h($node['custom_name']) ?>" placeholder="留空则使用上游名称"></td>
                                            <td><input class="node-sort-input" name="node_sort_order[<?= h($nodeKey) ?>]" type="number" value="<?= (int)$node['sort_order'] ?>"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="primary" type="submit">保存节点设置</button>
                        </form>
                    <?php endif; ?>
                </section>
                <form method="post" class="compact-form" onsubmit="return confirm('确定删除这条机场线路？')">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_subscription_line">
                    <input type="hidden" name="id" value="<?= (int)$line['id'] ?>">
                    <button class="danger" type="submit">删除线路</button>
                </form>
            </section>
            <?php endforeach; ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Global Settings</p><h1>订阅全局配置</h1></div>
                </div>
                <p class="muted">这里保留免费用户订阅、购买入口及付费用户拉取风控；付费机场源请在上方按线路管理。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_client_subscriptions">
                    <label>免费用户上游订阅 URL
                        <input name="free_sub_url" type="url" value="<?= h($freeSubUrl) ?>" placeholder="https://example.com/free-sub">
                    </label>
                    <label class="toggle-row"><input type="checkbox" name="free_subscription_enabled" value="1" <?= $freeSubscriptionEnabled ? 'checked' : '' ?>><span>允许免费用户拉取订阅</span></label>
                    <p class="muted">关闭后免费用户仍可登录，也可以使用卡密升级，但不能拉取免费订阅。</p>
                    <label>购买付费卡密按钮链接
                        <input name="paid_card_buy_url" type="url" value="<?= h($paidCardBuyUrl) ?>" placeholder="https://example.com/buy">
                    </label>
                    <label>付费用户 24 小时拉取上限
                        <input name="paid_daily_pull_limit" type="number" min="1" value="<?= (int)$paidDailyPullLimit ?>">
                    </label>
                    <label>上游自动获取间隔（小时）<input name="upstream_sync_hours" type="number" min="1" max="168" step="1" value="<?= upstream_sync_hours() ?>" required></label>
                    <p class="muted">按此间隔更新已开启自动更新的线路节点和流量，保留节点改名、启停与排序。用户访问或更新订阅时触发检查；如需无人访问时也准时更新，请在服务器计划任务中每 5 分钟执行一次 <code>php /网站实际目录/sync_upstreams.php</code>。</p>
                    <button class="primary" type="submit">保存订阅配置</button>
                </form>
            </section>
        <?php elseif ($tab === 'agents'): ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Agents</p><h1>代理管理</h1></div>
                    <span class="status-badge"><?= count($agents) ?> 个代理</span>
                </div>
                <p class="muted">代理登录 `/agent/` 后，可使用后台余额按设定价格生成卡密。代理只能生成 360 天年付卡密，不能选择其它时长。</p>
                <form method="post" class="form-row">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_agent">
                    <label>代理账号<input name="username" required placeholder="agent001"></label>
                    <label>登录密码<input name="password" type="password" minlength="6" required placeholder="至少 6 位"></label>
                    <label>后台余额<input name="balance" type="number" min="0" step="0.01" value="0.00"></label>
                    <label>年卡价格<input name="card_price" type="number" min="0" step="0.01" value="0.00"></label>
                    <label>状态<select name="status"><option value="active">正常</option><option value="disabled">已停用</option></select></label>
                    <button class="primary" type="submit">添加代理</button>
                </form>
            </section>
            <section class="panel table-wrap">
                <table>
                    <thead><tr><th>代理账号</th><th>余额</th><th>年卡价格</th><th>状态</th><th>已生成</th><th>累计消费</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td><?= h($agent['username']) ?></td>
                            <td>￥<?= number_format((float)$agent['balance'], 2) ?></td>
                            <td>￥<?= number_format((float)$agent['card_price'], 2) ?></td>
                            <td><?= h(admin_agent_status_label($agent['status'])) ?></td>
                            <td><?= (int)$agent['generated_cards'] ?> 张</td>
                            <td>￥<?= number_format((float)$agent['spent_total'], 2) ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="save_agent">
                                    <input type="hidden" name="id" value="<?= (int)$agent['id'] ?>">
                                    <input name="username" value="<?= h($agent['username']) ?>" required>
                                    <input name="password" type="password" placeholder="留空不改">
                                    <input name="balance" type="number" min="0" step="0.01" value="<?= h((string)$agent['balance']) ?>">
                                    <input name="card_price" type="number" min="0" step="0.01" value="<?= h((string)$agent['card_price']) ?>">
                                    <select name="status">
                                        <option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>正常</option>
                                        <option value="disabled" <?= $agent['status'] === 'disabled' ? 'selected' : '' ?>>已停用</option>
                                    </select>
                                    <button type="submit">保存</button>
                                </form>
                                <form method="post" class="inline-form compact-form" onsubmit="return confirm('确定删除或停用这个代理？')">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_agent">
                                    <input type="hidden" name="id" value="<?= (int)$agent['id'] ?>">
                                    <button class="danger" type="submit">删除/停用</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($tab === 'popup'): ?>
            <section class="panel popup-settings-panel">
                <div class="section-head">
                    <div><p class="eyebrow">Popup Announcement</p><h1>弹窗公告</h1></div>
                    <span class="status-badge"><?= $popupNoticeEnabled ? '已开启' : '已关闭' ?></span>
                </div>
                <p class="muted">用户进入用户中心时显示弹窗公告。可以选择每个浏览器只显示一次，也可以让公告每次进入页面都显示。</p>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_popup_notice">
                    <div class="switch-grid">
                        <label class="toggle-row">
                            <input type="checkbox" name="popup_notice_enabled" value="1" <?= $popupNoticeEnabled ? 'checked' : '' ?>>
                            <span>启用登录后弹窗公告</span>
                        </label>
                        <label class="toggle-row">
                            <input type="checkbox" name="popup_notice_once" value="1" <?= $popupNoticeOnce ? 'checked' : '' ?>>
                            <span>每个浏览器只显示一次</span>
                        </label>
                    </div>
                    <label>公告标题
                        <input name="popup_notice_title" value="<?= h($popupNoticeTitle) ?>" maxlength="120" placeholder="服务公告">
                    </label>
                    <label>公告内容
                        <textarea name="popup_notice_content" class="popup-content-area" placeholder="请输入公告内容"><?= h($popupNoticeContent) ?></textarea>
                    </label>
                    <button class="primary" type="submit">保存弹窗公告</button>
                </form>
            </section>
        <?php elseif ($tab === 'compensation'): ?>
            <section class="panel">
                <div class="section-head"><div><p class="eyebrow">Compensation</p><h1>订阅补偿</h1></div></div>
                <p class="muted">活动创建时固定现有用户名单。新注册用户不会看到，名单内用户点击弹窗按钮后只能领取一次。</p>
                <form method="post" class="form-row form-row-grid">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_compensation">
                    <label>弹窗标题<input name="title" maxlength="120" required placeholder="线路故障补偿"></label>
                    <label>补偿对象<select name="target_plan"><option value="all">全部现有用户</option><option value="free">现有免费用户</option><option value="paid">现有付费用户</option></select></label>
                    <label>赠送天数<input name="duration_days" type="number" min="1" max="3650" value="3" required></label>
                    <label class="wide-field">补偿原因<textarea name="reason" required placeholder="说明受影响原因和补偿内容"></textarea></label>
                    <button class="primary" type="submit">创建领取活动</button>
                </form>
            </section>
            <section class="panel table-wrap">
                <table><thead><tr><th>标题</th><th>对象</th><th>天数</th><th>领取进度</th><th>状态</th><th>操作</th></tr></thead><tbody>
                <?php foreach ($compensations as $campaign): ?>
                    <tr><td><strong><?= h($campaign['title']) ?></strong><small class="table-note"><?= h($campaign['reason']) ?></small></td><td><?= h($campaign['target_plan'] === 'all' ? '全部' : plan_label($campaign['target_plan'])) ?></td><td><?= (int)$campaign['duration_days'] ?> 天</td><td><?= (int)$campaign['claimed_count'] ?> / <?= (int)$campaign['recipient_count'] ?></td><td><?= $campaign['status'] === 'active' ? '领取中' : '已关闭' ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_compensation"><input type="hidden" name="id" value="<?= (int)$campaign['id'] ?>"><button type="submit"><?= $campaign['status'] === 'active' ? '关闭' : '重新开启' ?></button></form></td></tr>
                <?php endforeach; ?>
                <?php if (!$compensations): ?><tr><td colspan="6" class="muted">暂无补偿活动</td></tr><?php endif; ?>
                </tbody></table>
            </section>
        <?php elseif ($tab === 'tickets'): ?>
            <section class="panel">
                <div class="section-head"><div><p class="eyebrow">Support</p><h1>用户工单</h1></div><span class="status-badge"><?= (int)$openTicketCount ?> 个处理中</span></div>
                <p class="muted">查看用户问题和截图，回复后可以结束工单。已结束的工单可重新打开。</p>
            </section>
            <div class="ticket-list admin-ticket-list">
                <?php foreach ($tickets as $ticket): ?>
                    <section class="panel ticket-card">
                        <div class="section-head"><div><p class="eyebrow">#<?= (int)$ticket['id'] ?> · <?= h($ticket['username']) ?><?= $ticket['qq_number'] ? ' · QQ ' . h($ticket['qq_number']) : '' ?></p><h2><?= h($ticket['subject']) ?></h2></div><span class="status-badge"><?= $ticket['status'] === 'open' ? '处理中' : '已结束' ?></span></div>
                        <div class="ticket-thread">
                            <?php foreach ($adminTicketMessages[(int)$ticket['id']] ?? [] as $message): ?>
                                <div class="ticket-message <?= h($message['sender_type']) ?>"><strong><?= $message['sender_type'] === 'admin' ? '管理员' : h($ticket['username']) ?></strong><p><?= nl2br(h($message['message'])) ?></p><?php if ($message['attachment']): ?><a href="/data/tickets/<?= rawurlencode($message['attachment']) ?>" target="_blank" rel="noopener"><img src="/data/tickets/<?= rawurlencode($message['attachment']) ?>" alt="用户上传截图"></a><?php endif; ?><small><?= h($message['created_at']) ?></small></div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ticket['status'] === 'open'): ?><form method="post" class="stack ticket-reply"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reply_ticket"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label>回复用户<textarea name="message" required></textarea></label><button class="primary" type="submit">发送回复</button></form><?php endif; ?>
                        <form method="post" class="inline-form compact-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_ticket"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><button type="submit"><?= $ticket['status'] === 'open' ? '结束工单' : '重新打开' ?></button></form>
                    </section>
                <?php endforeach; ?>
                <?php if (!$tickets): ?><div class="panel download-empty">暂无用户工单</div><?php endif; ?>
            </div>
        <?php elseif ($tab === 'users'): ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Users</p><h1>用户管理</h1></div>
                    <span class="status-badge"><?= count($users) ?> 人</span>
                </div>
                <form method="get" class="form-row user-filter-row">
                    <input type="hidden" name="tab" value="users">
                    <label>关键词<input name="user_keyword" value="<?= h($userKeyword) ?>" placeholder="账号 / QQ / 卡密"></label>
                    <label>用户状态<select name="user_status">
                        <option value="" <?= $userStatusFilter === '' ? 'selected' : '' ?>>全部</option>
                        <option value="active" <?= $userStatusFilter === 'active' ? 'selected' : '' ?>>正常</option>
                        <option value="banned" <?= $userStatusFilter === 'banned' ? 'selected' : '' ?>>已封禁</option>
                    </select></label>
                    <label>套餐<select name="user_plan">
                        <?php foreach (admin_plan_options() as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $userPlanFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>到期状态<select name="user_expire">
                        <option value="" <?= $userExpireFilter === '' ? 'selected' : '' ?>>全部</option>
                        <option value="valid" <?= $userExpireFilter === 'valid' ? 'selected' : '' ?>>未到期</option>
                        <option value="expired" <?= $userExpireFilter === 'expired' ? 'selected' : '' ?>>已到期</option>
                    </select></label>
                    <button type="submit">搜索</button>
                    <a class="button-link" href="/admin/index.php?tab=users">重置</a>
                </form>
            </section>
            <section class="panel table-wrap">
                <table>
                    <thead><tr><th>账号</th><th>套餐</th><th>状态</th><th>订阅剩余</th><th>24小时拉取</th><th>管理</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td><?= h($row['username']) ?></td>
                            <td><?= h(plan_label($row['plan'] ?? 'paid')) ?></td>
                            <td><?= h(admin_user_status_label($row['status'])) ?>
                                <?php if ($row['status'] === 'banned'): ?><small class="table-note"><?= h($row['ban_reason']) ?></small><?php endif; ?>
                                <?php if ($row['plan'] === 'paid' && $row['card_status'] !== 'used'): ?><small class="table-note">付费凭据异常，请重新保存套餐</small><?php endif; ?>
                            </td>
                            <td><?= ($row['plan'] ?? 'paid') === 'free' ? '免费' : max(0, (int)ceil((strtotime($row['expire_time']) - time()) / 86400)) . ' 天' ?>
                                <?php if ($row['plan'] === 'paid'): ?><small class="table-note"><?= h($row['expire_time']) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int)$row['pull_count_24h'] ?> / <?= (int)$paidDailyPullLimit ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="save_user_plan">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <select name="payment_plan_id" aria-label="选择开通套餐" required>
                                        <option value="" selected disabled>请选择套餐</option>
                                        <option value="free">免费用户</option>
                                        <?php foreach ($paymentPlans as $availablePlan): if ($availablePlan['status'] !== 'active') continue; ?>
                                        <option value="<?= (int)$availablePlan['id'] ?>"><?= h($availablePlan['name']) ?> · <?= (int)$availablePlan['duration_days'] ?> 天</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">保存套餐</button>
                                </form>
                                <form method="post" class="inline-form compact-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input name="days" type="number" min="1" max="3650" step="1" required aria-label="调整天数" placeholder="输入天数">
                                    <button type="submit" name="action" value="add_user_days">增加天数</button>
                                    <button type="submit" name="action" value="subtract_user_days">减少天数</button>
                                </form>
                                <form method="post" class="inline-form compact-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_user_status">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <select name="status">
                                        <option value="active" <?= $row['status'] === 'active' ? 'selected' : '' ?>>正常</option>
                                        <option value="banned" <?= $row['status'] === 'banned' ? 'selected' : '' ?>>已封禁</option>
                                    </select>
                                    <input name="ban_reason" placeholder="封禁原因" value="<?= h($row['ban_reason']) ?>">
                                    <button type="submit">保存账号状态</button>
                                </form>
                                <form method="post" class="inline-form compact-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reset_user_password">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input name="password" placeholder="新密码" minlength="6">
                                    <button type="submit">重置密码</button>
                                </form>
                                <form method="post" class="inline-form compact-form" onsubmit="return confirm('确定重置该用户最近 24 小时的订阅拉取次数？')">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reset_user_pull_count">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit">重置拉取次数</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($tab === 'orders'): ?>
            <section class="panel">
                <div class="section-head"><div><p class="eyebrow">Order Center</p><h1>订单管理</h1><p class="muted">查询、补单、关闭和跟踪所有支付宝订单。</p></div><span class="status-badge"><?= count($paymentOrders) ?> 条记录</span></div>
                <form method="get" class="filter-row order-filter"><input type="hidden" name="tab" value="orders"><label>关键词<input name="order_keyword" value="<?= h($orderKeyword) ?>" placeholder="订单号 / 用户 / QQ / 套餐"></label><label>订单状态<select name="order_status"><option value="">全部状态</option><?php foreach (['pending'=>'待支付','paid'=>'已支付','closed'=>'已关闭','failed'=>'失败'] as $value => $label): ?><option value="<?= $value ?>" <?= $orderStatusFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="primary" type="submit">查询订单</button><a class="ghost-link" href="/admin/index.php?tab=orders">重置</a></form>
            </section>
            <section class="panel table-wrap order-table-panel"><table class="order-table"><thead><tr><th>订单号</th><th>用户</th><th>套餐</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody><?php if (!$paymentOrders): ?><tr><td colspan="7"><div class="empty-state">暂无符合条件的订单</div></td></tr><?php endif; ?><?php foreach ($paymentOrders as $order): ?><tr><td><strong><?= h($order['order_no']) ?></strong><small class="table-subtext"><?= h($order['qq_number'] ?: '未绑定 QQ') ?></small></td><td><?= h($order['username']) ?></td><td><?= h($order['plan_name']) ?><small class="table-subtext"><?= (int)$order['duration_days'] ?> 天</small></td><td><strong>￥<?= h(number_format((float)$order['amount'], 2)) ?></strong></td><td><span class="order-state order-state-<?= h($order['status']) ?>"><?= ['pending'=>'待支付','paid'=>'已支付','closed'=>'已关闭','failed'=>'失败'][$order['status']] ?? h($order['status']) ?></span></td><td><?= h($order['created_at']) ?></td><td><div class="order-actions"><?php if (in_array($order['status'], ['pending','failed'], true)): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="admin_payment_order"><input type="hidden" name="order_no" value="<?= h($order['order_no']) ?>"><input type="hidden" name="decision" value="fulfill"><button type="submit" class="small-button" onclick="return confirm('确认给该订单补单并发放时长？')">补单</button></form><?php endif; ?><?php if ($order['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="admin_payment_order"><input type="hidden" name="order_no" value="<?= h($order['order_no']) ?>"><input type="hidden" name="decision" value="close"><button type="submit" class="small-button danger-button" onclick="return confirm('确认关闭该订单？')">关闭</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></section>
        <?php elseif ($tab === 'payment'): ?>
            <section class="panel"><div class="section-head"><div><p class="eyebrow">Alipay</p><h1>购买与支付宝当面付</h1></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_payment_settings"><label>购买方式<select name="purchase_mode"><option value="link" <?=$purchaseMode==='link'?'selected':''?>>跳转链接购买</option><option value="direct" <?=$purchaseMode==='direct'?'selected':''?>>支付宝当面付</option></select></label><label>跳转购买链接<input name="paid_card_buy_url" type="url" value="<?=h($paidCardBuyUrl)?>"></label><label>支付宝应用 APPID<input name="alipay_app_id" value="<?=h($alipayAppId)?>"></label><label>应用 RSA2 私钥<textarea name="alipay_private_key" class="code-area" autocomplete="off" spellcheck="false" placeholder="留空保留已配置私钥；填写新私钥后保存替换"></textarea></label><label>支付宝公钥<textarea name="alipay_public_key" class="code-area"><?=h($alipayPublicKey)?></textarea></label><label>网关<input name="alipay_gateway" type="url" value="<?=h($alipayGateway)?>"></label><p class="muted">异步通知地址：<?=h(base_url())?>/alipay_notify.php（须在支付宝开放平台配置公网 HTTPS 地址）</p><button class="primary">保存支付设置</button></form></section>
            <section class="panel"><div class="section-head"><div><p class="eyebrow">Plans</p><h1>套餐时间与价格</h1></div></div><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_payment_plan"><label>套餐名称<input name="name" required></label><label>价格<input name="price" type="number" step="0.01" min="0.01" required></label><label>订阅天数<input name="duration_days" type="number" min="1" max="3650" value="365" required></label><input type="hidden" name="status" value="active"><button class="primary">添加套餐</button></form></section><section class="panel table-wrap"><table><thead><tr><th>套餐</th><th>价格</th><th>天数</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach($paymentPlans as $plan):?><tr><td><form id="plan-<?=(int)$plan['id']?>" method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_payment_plan"><input type="hidden" name="id" value="<?=(int)$plan['id']?>"></form><input form="plan-<?=(int)$plan['id']?>" name="name" value="<?=h($plan['name'])?>"></td><td><input form="plan-<?=(int)$plan['id']?>" name="price" type="number" step="0.01" value="<?=h($plan['price'])?>"></td><td><input form="plan-<?=(int)$plan['id']?>" name="duration_days" type="number" value="<?=(int)$plan['duration_days']?>"></td><td><select form="plan-<?=(int)$plan['id']?>" name="status"><option value="active" <?=$plan['status']==='active'?'selected':''?>>启用</option><option value="disabled" <?=$plan['status']==='disabled'?'selected':''?>>停用</option></select></td><td><button form="plan-<?=(int)$plan['id']?>">保存</button></td></tr><?php endforeach;?></tbody></table></section>
        <?php elseif ($tab === 'notice'): ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Notice</p><h1>公告与软件下载</h1></div>
                </div>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_notice">
                    <div class="switch-grid">
                        <label class="toggle-row"><input type="checkbox" name="notice_popup_enabled" value="1" <?= $noticeEnabled ? 'checked' : '' ?>><span>显示公告区块</span></label>
                        <label class="toggle-row"><input type="checkbox" name="faq_enabled" value="1" <?= $faqEnabled ? 'checked' : '' ?>><span>显示常见问题区块</span></label>
                        <label class="toggle-row"><input type="checkbox" name="popup_notice_enabled" value="1" <?= $popupNoticeEnabled ? 'checked' : '' ?>><span>每次登录显示弹窗公告</span></label>
                        <label class="toggle-row"><input type="checkbox" name="download_block_enabled" value="1" <?= $downloadEnabled ? 'checked' : '' ?>><span>显示软件下载区块</span></label>
                    </div>
                    <div class="form-row-grid">
                        <label>服务公告 HTML<textarea class="code-area" name="site_notice"><?= h($notice) ?></textarea></label>
                        <label>常见问题 HTML<textarea class="code-area" name="faq_content"><?= h($faqContent) ?></textarea></label>
                    </div>
                    <label>使用文档跳转链接<input name="docs_url" type="url" value="<?= h($docsUrl) ?>" placeholder="https://example.com/docs"></label>
                    <div class="form-row-grid">
                        <label>弹窗公告标题<input name="popup_notice_title" value="<?= h($popupNoticeTitle) ?>" maxlength="120" placeholder="服务公告"></label>
                        <label>弹窗公告内容<textarea name="popup_notice_content" class="popup-content-area" placeholder="仅在用户登录成功后显示"><?= h($popupNoticeContent) ?></textarea></label>
                    </div>
                    <div class="form-row-grid">
                        <label>软件名称<input name="download_app_name" value="<?= h($downloadName) ?>" placeholder="Clash Verge"></label>
                        <label>软件图标 URL<input name="download_icon_url" type="url" value="<?= h($downloadIcon) ?>" placeholder="https://example.com/icon.png"></label>
                    </div>
                    <label>下载地址<input name="download_url" type="url" value="<?= h($downloadUrl) ?>" placeholder="https://example.com/download"></label>
                    <label>软件说明<textarea name="download_app_desc"><?= h($downloadDesc) ?></textarea></label>
                    <button class="primary" type="submit">保存设置</button>
                </form>
            </section>
        <?php elseif ($tab === 'appearance'): ?>
            <section class="panel appearance-settings">
                <div class="section-head"><div><p class="eyebrow">APPEARANCE</p><h1>外观主题</h1></div><span class="status-badge">当前：<?= h(site_themes()[$siteTheme]['name']) ?></span></div>
                <p class="muted">设置未保存个人偏好的浏览器所使用的默认主题。右上角「主题」可单独设置当前浏览器的整体、品牌、顶栏和侧栏配色。</p>
                <form method="post" class="stack" data-theme-form>
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_appearance">
                    <fieldset class="theme-picker">
                        <legend>选择整站风格</legend>
                        <?php foreach (site_themes() as $key => $theme): ?>
                        <label class="theme-option theme-swatch-<?= h($key) ?>">
                            <input type="radio" name="site_theme" value="<?= h($key) ?>" <?= $siteTheme === $key ? 'checked' : '' ?> required>
                            <span><strong><?= h($theme['name']) ?></strong><small><?= h($theme['description']) ?></small></span>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="ambient-preview"><strong>sed机场管理程序</strong><span>整站默认主题预览</span><span class="theme-preview-status" aria-live="polite">当前已保存：<?= h(site_themes()[$siteTheme]['name']) ?></span></div>
                    <div class="inline-form"><button class="primary" type="submit">保存主题</button><button type="reset">恢复已保存主题</button></div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Profile</p><h1>管理员头像</h1></div>
                </div>
                <div class="settings-avatar-row">
                    <img class="auth-avatar" data-qq-avatar src="<?= h(qq_avatar_url($adminQq)) ?>" alt="管理员头像">
                    <form method="post" class="stack settings-inline-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_admin_profile">
                        <label>管理员 QQ 号<input name="admin_qq_number" value="<?= h($adminQq) ?>" placeholder="10019" data-avatar-input></label>
                        <button class="primary" type="submit">保存头像设置</button>
                    </form>
                </div>
            </section>
            <section class="panel">
                <div class="section-head">
                    <div><p class="eyebrow">Security</p><h1>安全设置</h1></div>
                </div>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <label>新管理员密码<input name="password" type="password" minlength="8" required></label>
                    <button class="primary" type="submit">修改密码</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
<script src="/assets/app.js?v=20261004-zibi2"></script>
</body>
</html>
