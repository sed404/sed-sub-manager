<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();
$error = '';
$createdOrder = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if (($_POST['action'] ?? '') === 'cancel_order') {
            $cancelOrderNo = trim((string)($_POST['order_no'] ?? ''));
            $cancelStmt = $pdo->prepare("SELECT * FROM payment_orders WHERE order_no = ? AND user_id = ? AND status = 'pending'");
            $cancelStmt->execute([$cancelOrderNo, (int)$user['id']]);
            $cancelOrder = $cancelStmt->fetch();
            if (!$cancelOrder) throw new RuntimeException('订单不存在、已支付或已关闭。');
            try { alipay_request('alipay.trade.close', ['out_trade_no' => $cancelOrderNo]); } catch (Throwable $e) { /* 本地关闭作为兜底 */ }
            $pdo->prepare("UPDATE payment_orders SET status='closed',updated_at=? WHERE order_no=? AND user_id=?")->execute([now(), $cancelOrderNo, (int)$user['id']]);
            header('Location: /pay.php?cancelled=1'); exit;
        }
        $planId = (int)($_POST['plan_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE id = ? AND status = 'active'");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) throw new RuntimeException('套餐不存在或已下架，请刷新页面后重试。');
        $orderNo = 'MF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
        $subject = trim((string)$plan['name']);
        $pdo->prepare("INSERT INTO payment_orders (order_no,user_id,plan_id,plan_name,amount,duration_days,status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'pending',?,?)")
            ->execute([$orderNo, (int)$user['id'], (int)$plan['id'], $subject, $plan['price'], (int)$plan['duration_days'], now(), now()]);
        try {
            $response = alipay_request('alipay.trade.precreate', [
                'out_trade_no' => $orderNo,
                'total_amount' => number_format((float)$plan['price'], 2, '.', ''),
                'subject' => $subject,
            ]);
            $qrCode = trim((string)($response['qr_code'] ?? ''));
            if ($qrCode === '') throw new RuntimeException('支付宝没有返回二维码地址。');
            $pdo->prepare('UPDATE payment_orders SET qr_code=?,updated_at=? WHERE order_no=?')->execute([$qrCode, now(), $orderNo]);
            $createdOrder = ['order_no' => $orderNo, 'qr_code' => $qrCode, 'plan_name' => $subject, 'amount' => $plan['price'], 'duration_days' => $plan['duration_days']];
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE payment_orders SET status='failed',updated_at=? WHERE order_no=?")->execute([now(), $orderNo]);
            throw $e;
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$plans = $pdo->query("SELECT * FROM payment_plans WHERE status = 'active' ORDER BY duration_days ASC, id ASC")->fetchAll();
$myOrderStmt = $pdo->prepare('SELECT * FROM payment_orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$myOrderStmt->execute([(int)$user['id']]);
$myOrders = $myOrderStmt->fetchAll();
?>
<!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>订购套餐 - <?= h(APP_NAME) ?></title><?php ui_head(); ?>
</head><body class="<?= h(site_theme_class()) ?> payment-page ui-page">
<?php ui_shell('user', 'renew', (string)$user['username']); ?>
<main id="main-content" class="ui-content" tabindex="-1">
<section class="payment-hero"><div><p class="eyebrow">SECURE PAYMENT</p><h1>选择套餐，立即开通</h1><p class="hero-desc">使用支付宝扫码完成支付，支付成功后账号时长会自动增加。</p></div><div class="payment-trust"><span>✓ RSA2 安全签名</span><span>✓ 支付结果自动同步</span></div></section>
<?php if ($error): ?><div class="alert bad"><?= h($error) ?></div><?php endif; ?>
<?php if (isset($_GET['cancelled'])): ?><div class="alert ok">订单已取消，未产生扣款。</div><?php endif; ?>
<?php if ($createdOrder): ?>
<section class="payment-order panel"><div class="payment-summary"><p class="eyebrow">ORDER <?= h($createdOrder['order_no']) ?></p><h2><?= h($createdOrder['plan_name']) ?> · <?= h((string)$createdOrder['duration_days']) ?> 天</h2><p class="muted">应付金额 <strong class="payment-amount">￥<?= h(number_format((float)$createdOrder['amount'], 2)) ?></strong></p><div class="payment-features"><span>即时到账</span><span>自动延长订阅有效期</span><span>订单状态可追踪</span></div><div class="payment-actions"><a class="primary button-link" href="<?= h($createdOrder['qr_code']) ?>" target="_blank" rel="noopener">打开支付宝</a><button type="button" class="ghost-link" data-copy="<?= h($createdOrder['order_no']) ?>">复制订单号</button><form method="post" onsubmit="return confirm('确认取消这个未支付订单？')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="order_no" value="<?= h($createdOrder['order_no']) ?>"><button type="submit" class="danger-button">取消订单</button></form></div></div><div class="payment-qr-wrap"><img class="payment-qr" src="https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=<?= rawurlencode($createdOrder['qr_code']) ?>" alt="支付宝支付二维码"><p class="muted">请使用支付宝扫一扫</p><small class="muted">二维码有效期内请完成支付</small></div><div class="payment-status" data-order="<?= h($createdOrder['order_no']) ?>">等待支付…</div></section>
<?php else: ?>
<section class="payment-plans"><?php if (!$plans): ?><div class="panel empty-state"><h2>暂无可购买套餐</h2><p class="muted">请联系管理员在后台添加并启用套餐。</p></div><?php endif; ?><?php foreach ($plans as $plan): ?><form method="post" class="plan-card panel"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>"><p class="eyebrow">PLAN</p><h2><?= h($plan['name']) ?></h2><div class="plan-price">￥<?= h(number_format((float)$plan['price'], 2)) ?></div><p class="muted">有效期 <?= (int)$plan['duration_days'] ?> 天 · 支付后自动到账</p><button class="primary" type="submit">开通套餐</button></form><?php endforeach; ?></section>
<?php endif; ?>
<?php if (!$createdOrder && $myOrders): ?><section class="panel recent-orders"><div class="section-head"><div><p class="eyebrow">ORDER HISTORY</p><h2>最近订单</h2></div></div><div class="recent-order-list"><?php foreach ($myOrders as $myOrder): ?><div class="recent-order-row"><div><strong><?= h($myOrder['plan_name']) ?></strong><small><?= h($myOrder['order_no']) ?> · <?= h($myOrder['created_at']) ?></small></div><div><strong>￥<?= h(number_format((float)$myOrder['amount'], 2)) ?></strong><span class="order-state order-state-<?= h($myOrder['status']) ?>"><?= ['pending'=>'待支付','paid'=>'已支付','closed'=>'已关闭','failed'=>'失败'][$myOrder['status']] ?? h($myOrder['status']) ?></span></div></div><?php endforeach; ?></div></section><?php endif; ?></main>
<script src="/assets/app.js?v=20261004-zibi2"></script>
<script src="/assets/payment.js?v=20261004-zibi2"></script>
</body></html>
