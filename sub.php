<?php
require_once __DIR__ . '/lib.php';

$userId = (int)($_GET['user'] ?? 0);
$token = trim($_GET['token'] ?? '');
$type = infer_client_type($_GET['type'] ?? '');

$stmt = db()->prepare('
    SELECT users.*, cards.status AS card_status
    FROM users LEFT JOIN cards ON cards.id = users.card_id
    WHERE users.id = ? AND users.token = ?
');
$stmt->execute([$userId, $token]);
$user = $stmt->fetch();

if ($user && ($user['plan'] ?? 'paid') === 'free' && !free_subscription_enabled()) {
    log_subscription_access($user, $type, false);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Free subscriptions are currently disabled. Upgrade with a card to continue.');
}

if (!$user || !user_can_access($user)) {
    if ($user) {
        log_subscription_access($user, $type, false);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Subscription unavailable, banned, or expired.');
}

$isPaid = ($user['plan'] ?? 'paid') === 'paid';
if ($isPaid && !enforce_paid_pull_limit($user)) {
    log_subscription_access($user, $type, false);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Subscription blocked because the 24-hour pull limit was exceeded.');
}

$upstreamUrl = $isPaid ? upstream_url_for_type($type) : trim(get_setting('free_sub_url', ''));
if ($upstreamUrl === '') {
    log_subscription_access($user, $type, false);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Subscription upstream is not configured.');
}

[$body, $headers] = remote_get($upstreamUrl);
if ($body === false || $body === null) {
    log_subscription_access($user, $type, false);
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Failed to fetch remote subscription.');
}

log_subscription_access($user, $type, true);

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="zero-sub-' . ($isPaid ? $type : 'free') . '.txt"');
header('Profile-Update-Interval: 24');

$hasUserInfo = false;
foreach ($headers as $header) {
    if (stripos($header, 'subscription-userinfo:') === 0) {
        $hasUserInfo = true;
        header($header);
    }
}

if (!$hasUserInfo) {
    $expire = $isPaid ? strtotime((string)$user['expire_time']) : strtotime('+30 days');
    if ($expire !== false) {
        header('Subscription-Userinfo: upload=0; download=0; total=107374182400; expire=' . $expire);
    }
}

echo $body;
