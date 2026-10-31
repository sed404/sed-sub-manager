<?php
require_once __DIR__ . '/lib.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$userId = (int)($_GET['user'] ?? 0);
$token = trim($_GET['token'] ?? '');
try { $type = infer_client_type((string)($_GET['type'] ?? '')); } catch (InvalidArgumentException $e) { http_response_code(400); exit('Unknown subscription format.'); }
$lineId = (int)($_GET['line'] ?? 0);

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

$line = null;
if ($isPaid) {
    sync_subscription_lines_after_login($lineId > 0 ? $lineId : null);
    if ($lineId <= 0) $lineId = preferred_subscription_line_id($user, subscription_lines());
    $line = subscription_line($lineId);
    if (!$line && $lineId <= 0) {
        $line = db()->query("SELECT * FROM subscription_lines WHERE status = 'available' AND (expire_time IS NULL OR expire_time >= NOW()) ORDER BY sort_order ASC, id ASC LIMIT 1")->fetch() ?: null;
    }
    if (!$line) {
        log_subscription_access($user, $type, false);
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Subscription line not found.');
    }
    if (($line['status'] ?? '') !== 'available') {
        log_subscription_access($user, $type, false);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Subscription line is under maintenance or disabled.');
    }
    if (!empty($line['expire_time']) && strtotime((string)$line['expire_time']) < time()) {
        log_subscription_access($user, $type, false);
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Subscription line has expired.');
    }
}

$upstreamUrl = $isPaid ? subscription_line_upstream($line, $type) : trim(get_setting('free_sub_url', ''));
if ($upstreamUrl === '') {
    log_subscription_access($user, $type, false);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Subscription upstream is not configured.');
}

$body = null;
$headers = [];
if ($isPaid) {
    try {
        $body = build_clash_subscription((int)$line['id']);
    } catch (Throwable $e) {
        log_subscription_access($user, $type, false);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The generated Clash subscription is currently unavailable.');
    }
}
$cachedBody = $body !== null;
if ($body === null) {
    try {
        $body = normalize_upstream_subscription(fetch_clash_subscription_document($upstreamUrl, $isPaid && !empty($line['allow_insecure_tls'])));
    } catch (Throwable $e) {
        log_subscription_access($user, $type, false);
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Failed to fetch remote subscription.');
    }
}

try {
    if (!preg_match('/^proxies:\s*$/m', $body)) {
        $body = normalize_upstream_subscription($body);
    }
    // Cached paid YAML has already applied aliases, disabled nodes and ordering.
    // Free subscriptions and uncached upstreams go through the same renderer.
    if (!$isPaid || !$cachedBody) {
        $body = render_clash_subscription($body, $isPaid ? clash_nodes_for_line((int)$line['id']) : []);
    }
    $output = convert_client_subscription($body, $type);
} catch (Throwable $e) {
    log_subscription_access($user, $type, false);
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    exit('当前订阅无法生成此客户端格式，请尝试 Clash Meta / Verge Rev 或联系管理员。');
}
log_subscription_access($user, $type, true);
header('Content-Type: ' . $output['mime']);
header('Content-Disposition: inline; filename="sed-sub.' . $output['extension'] . '"');
header('Profile-Update-Interval: ' . upstream_sync_hours());
header('X-Subscription-Node-Count: ' . $output['count']);
header('X-Subscription-Skipped-Nodes: ' . $output['skipped']);
$body = $output['body'];
$hasUserInfo = false;
foreach ($headers as $header) {
    if (stripos($header, 'subscription-userinfo:') === 0) {
        $hasUserInfo = true;
        header($header);
    }
}

if (!$hasUserInfo) {
    $expire = $isPaid ? strtotime((string)$user['expire_time']) : strtotime('+30 days');
    $total = $isPaid && (int)($line['total_bytes'] ?? 0) > 0 ? (int)$line['total_bytes'] : 107374182400;
    if ($expire !== false) {
        header('Subscription-Userinfo: upload=0; download=0; total=' . $total . '; expire=' . $expire);
    }
}

echo $body;
