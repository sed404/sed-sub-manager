<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS cards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            card_key VARCHAR(32) NOT NULL UNIQUE,
            duration_days INT UNSIGNED NOT NULL,
            valid_until DATETIME NULL,
            status ENUM('unused','used','disabled') NOT NULL DEFAULT 'unused',
            used_by INT UNSIGNED NULL,
            used_time DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_cards_status (status),
            INDEX idx_cards_used_by (used_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            card_id INT UNSIGNED NULL,
            qq_number VARCHAR(20) NULL,
            plan ENUM('free','paid') NOT NULL DEFAULT 'paid',
            expire_time DATETIME NOT NULL,
            status ENUM('active','banned') NOT NULL DEFAULT 'active',
            ban_reason VARCHAR(255) NULL,
            banned_at DATETIME NULL,
            token VARCHAR(96) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            INDEX idx_users_token (token),
            INDEX idx_users_qq_number (qq_number),
            INDEX idx_users_plan (plan),
            INDEX idx_users_status (status),
            CONSTRAINT fk_users_card FOREIGN KEY (card_id) REFERENCES cards(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(140) NOT NULL UNIQUE,
            url TEXT NOT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS agents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            card_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_agents_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS agent_card_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT UNSIGNED NOT NULL,
            card_id INT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            balance_before DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_agent_card_logs_agent (agent_id),
            INDEX idx_agent_card_logs_card (card_id),
            CONSTRAINT fk_agent_logs_agent FOREIGN KEY (agent_id) REFERENCES agents(id),
            CONSTRAINT fk_agent_logs_card FOREIGN KEY (card_id) REFERENCES cards(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS renew_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            card_id INT UNSIGNED NOT NULL,
            duration_days INT UNSIGNED NOT NULL,
            expire_before DATETIME NOT NULL,
            expire_after DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_renew_logs_user (user_id),
            INDEX idx_renew_logs_card (card_id),
            INDEX idx_renew_logs_created_at (created_at),
            CONSTRAINT fk_renew_logs_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_renew_logs_card FOREIGN KEY (card_id) REFERENCES cards(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS card_claims (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            order_no VARCHAR(120) NOT NULL,
            order_image VARCHAR(255) NOT NULL,
            activate_image VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reward_card_id INT UNSIGNED NULL,
            admin_note VARCHAR(255) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_card_claims_user (user_id),
            INDEX idx_card_claims_status (status),
            INDEX idx_card_claims_order_no (order_no),
            CONSTRAINT fk_card_claims_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_card_claims_reward_card FOREIGN KEY (reward_card_id) REFERENCES cards(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS subscription_access_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            user_plan ENUM('free','paid') NOT NULL,
            client_type VARCHAR(30) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            user_agent VARCHAR(255) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX idx_sub_logs_user_created (user_id, created_at),
            INDEX idx_sub_logs_created_at (created_at),
            CONSTRAINT fk_sub_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS compensation_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            reason TEXT NOT NULL,
            duration_days INT UNSIGNED NOT NULL,
            target_plan ENUM('all','free','paid') NOT NULL DEFAULT 'all',
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            INDEX idx_compensation_campaigns_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS compensation_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            claimed_at DATETIME NULL,
            expire_before DATETIME NULL,
            expire_after DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_compensation_recipient (campaign_id, user_id),
            INDEX idx_compensation_recipient_user (user_id, claimed_at),
            CONSTRAINT fk_compensation_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES compensation_campaigns(id),
            CONSTRAINT fk_compensation_recipient_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            subject VARCHAR(160) NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            INDEX idx_tickets_user (user_id, updated_at),
            INDEX idx_tickets_status (status, updated_at),
            CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS ticket_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            sender_type ENUM('user','admin') NOT NULL,
            message TEXT NOT NULL,
            attachment VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_ticket_messages_ticket (ticket_id, id),
            CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS payment_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, price DECIMAL(10,2) NOT NULL, duration_days INT UNSIGNED NOT NULL, status ENUM('active','disabled') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS payment_orders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(40) NOT NULL UNIQUE, user_id INT UNSIGNED NOT NULL, plan_id INT UNSIGNED NOT NULL, plan_name VARCHAR(80) NOT NULL, amount DECIMAL(10,2) NOT NULL, duration_days INT UNSIGNED NOT NULL, status ENUM('pending','paid','closed','failed') NOT NULL DEFAULT 'pending', alipay_trade_no VARCHAR(64) NULL, qr_code TEXT NULL, paid_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_payment_user(user_id,created_at), INDEX idx_payment_status(status), CONSTRAINT fk_payment_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    ensure_column($pdo, 'cards', 'agent_id', 'ALTER TABLE cards ADD COLUMN agent_id INT UNSIGNED NULL AFTER used_time, ADD INDEX idx_cards_agent_id (agent_id)');
    ensure_column($pdo, 'users', 'qq_number', 'ALTER TABLE users ADD COLUMN qq_number VARCHAR(20) NULL AFTER card_id, ADD INDEX idx_users_qq_number (qq_number)');
    ensure_column($pdo, 'users', 'plan', "ALTER TABLE users ADD COLUMN plan ENUM('free','paid') NOT NULL DEFAULT 'paid' AFTER qq_number, ADD INDEX idx_users_plan (plan)");
    ensure_column($pdo, 'users', 'ban_reason', 'ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) NULL AFTER status');
    ensure_column($pdo, 'users', 'banned_at', 'ALTER TABLE users ADD COLUMN banned_at DATETIME NULL AFTER ban_reason');
    ensure_user_card_nullable($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM admins WHERE username = ?');
    $stmt->execute([ADMIN_DEFAULT_USER]);
    if ((int)$stmt->fetch()['total'] === 0) {
        $weakPasswords = ['admin', 'admin123', '123456', 'password', 'replace-with-a-random-password-at-least-12-characters'];
        if (strlen(ADMIN_DEFAULT_PASS) < 12 || in_array(strtolower(ADMIN_DEFAULT_PASS), $weakPasswords, true)) {
            throw new RuntimeException('首次安装前，请在 config.php 中设置至少 12 位的随机管理员密码。');
        }
        $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)')
            ->execute([ADMIN_DEFAULT_USER, password_hash(ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT), now()]);
    }

    $phoneCardClaimRulesDefault = "手机卡免费领取年卡规则：\n"
        . "1. 请先通过本站提供的专属手机卡办理链接下单，非本站链接办理的订单无法参与活动。\n"
        . "2. 办理完成后，请提交手机卡平台订单号、办理订单截图、激活成功并完成预存话费的截图。\n"
        . "3. 截图需清晰展示订单号、手机号或套餐信息、激活/预存状态等关键内容，遮挡关键信息可能导致审核不通过。\n"
        . "4. 每个真实订单仅可领取一次 360 天付费节点卡密，重复提交、虚假截图、盗用他人订单将直接驳回。\n"
        . "5. 管理员会人工核验资料，审核通过后系统自动发放卡密；领取到卡密后可在账号设置中升级或续费。\n"
        . "6. 如审核未通过，请根据管理员备注重新补充资料后再提交。";

    $defaults = [
        'site_notice' => '<p><strong>欢迎使用 MoFa共享节点订阅。</strong></p><p>请妥善保管你的订阅链接，卡密到期后订阅将自动失效。</p>',
        'notice_popup_enabled' => '1',
        'popup_notice_enabled' => '1',
        'popup_notice_once' => '1',
        'popup_notice_title' => '服务公告',
        'popup_notice_content' => "欢迎使用 MoFa共享节点订阅。\n请妥善保管你的订阅链接，卡密到期后订阅将自动失效。",
        'notice_popup_once' => '1',
        'download_block_enabled' => '1',
        'download_app_name' => 'Clash Verge',
        'download_app_desc' => '推荐使用的订阅客户端',
        'download_icon_url' => '',
        'download_url' => '',
        'admin_qq_number' => '10019',
        'free_sub_url' => '',
        'free_subscription_enabled' => '1',
        'paid_card_buy_url' => '',
        'purchase_mode' => 'link',
        'alipay_app_id' => '',
        'alipay_private_key' => '',
        'alipay_public_key' => '',
        'alipay_gateway' => 'https://openapi.alipay.com/gateway.do',
        'paid_daily_pull_limit' => '10',
        'sub_clash_url' => '',
        'sub_shadowrocket_url' => '',
        'sub_v2ray_url' => '',
        'sub_surge_url' => '',
        'phone_card_apply_url' => '',
        'phone_card_claim_enabled' => '1',
        'phone_card_claim_rules' => $phoneCardClaimRulesDefault,
        'login_background_enabled' => '0',
        'login_background_desktop_url' => '',
        'login_background_mobile_url' => '',
    ];
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM settings WHERE setting_key = ?');
    $insert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key]);
        if ((int)$stmt->fetch()['total'] === 0) {
            $insert->execute([$key, $value, now()]);
        }
    }

    $pdo->prepare("UPDATE settings SET setting_value = REPLACE(setting_value, 'Zero 高速节点服务', 'MoFa共享节点服务'), updated_at = ? WHERE setting_key IN ('site_notice', 'popup_notice_content') AND setting_value LIKE ?")
        ->execute([now(), '%Zero 高速节点服务%']);
    $pdo->prepare("UPDATE settings SET setting_value = REPLACE(setting_value, 'MoFa共享节点服务', 'MoFa共享节点订阅'), updated_at = ? WHERE setting_key IN ('site_notice', 'popup_notice_content') AND setting_value LIKE ?")
        ->execute([now(), '%MoFa共享节点服务%']);

    $oldPhoneCardRules = "Use the phone-card apply link on this site, then submit the order screenshot, activation/prepaid screenshot, and platform order number. After admin approval, you can receive one 360-day paid node card. Screenshots must clearly show order details. Duplicate or non-site orders may be rejected.";
    $ruleStmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $ruleStmt->execute(['phone_card_claim_rules']);
    $currentRules = $ruleStmt->fetch();
    if ($currentRules && trim((string)$currentRules['setting_value']) === $oldPhoneCardRules) {
        update_setting('phone_card_claim_rules', $phoneCardClaimRulesDefault);
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function css_background_url(string $url): string
{
    return "url('" . str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", '', ''], $url) . "')";
}

function get_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['setting_value'] : $default;
}

function update_setting(string $key, string $value): void
{
    db()->prepare('
        INSERT INTO settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)
    ')->execute([$key, $value, now()]);
}

function safe_notice_html(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', (string)$html);
    return $html ?? '';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF token mismatch.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT users.*, cards.card_key, cards.duration_days, cards.status AS card_status
        FROM users
        LEFT JOIN cards ON cards.id = users.card_id
        WHERE users.id = ?
    ');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    if (!user_can_login($user)) {
        unset($_SESSION['user_id']);
        redirect('/login.php?expired=1');
    }
    return $user;
}

function require_admin(): array
{
    if (empty($_SESSION['admin_id'])) {
        redirect('/admin/index.php');
    }

    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        session_destroy();
        redirect('/admin/index.php');
    }
    return $admin;
}

function current_agent(): ?array
{
    if (empty($_SESSION['agent_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM agents WHERE id = ?');
    $stmt->execute([$_SESSION['agent_id']]);
    $agent = $stmt->fetch();
    return $agent ?: null;
}

function require_agent(): array
{
    $agent = current_agent();
    if (!$agent) {
        redirect('/agent/index.php');
    }
    if (($agent['status'] ?? '') !== 'active') {
        unset($_SESSION['agent_id']);
        redirect('/agent/index.php?disabled=1');
    }
    return $agent;
}

function ensure_column(PDO $pdo, string $table, string $column, string $alterSql): void
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetch()['total'] === 0) {
        $pdo->exec($alterSql);
    }
}

function ensure_user_card_nullable(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        SELECT IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'card_id'
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || strtoupper((string)$row['IS_NULLABLE']) === 'YES') {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_card');
    } catch (Throwable $e) {
    }
    $pdo->exec('ALTER TABLE users MODIFY card_id INT UNSIGNED NULL');
    try {
        $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_card FOREIGN KEY (card_id) REFERENCES cards(id)');
    } catch (Throwable $e) {
    }
}

function card_available(array $card): bool
{
    $validUntil = $card['valid_until'] ?? null;
    $withinValidTime = !$validUntil || strtotime((string)$validUntil) >= time();
    return ($card['status'] ?? '') === 'unused' && empty($card['used_by']) && $withinValidTime;
}

function card_state(array $card): string
{
    if (($card['status'] ?? '') === 'disabled') {
        return '已停用';
    }
    if (($card['status'] ?? '') === 'used') {
        return '已使用';
    }
    if (!empty($card['valid_until']) && strtotime((string)$card['valid_until']) < time()) {
        return '已过期';
    }
    return '未使用';
}

function user_can_access(array $user): bool
{
    if (($user['status'] ?? '') !== 'active') {
        return false;
    }
    if (($user['plan'] ?? 'paid') === 'free') {
        return free_subscription_enabled();
    }
    return strtotime((string)$user['expire_time']) >= time()
        && ($user['card_status'] ?? '') === 'used';
}

function free_subscription_enabled(): bool
{
    return get_setting('free_subscription_enabled', '1') === '1';
}

function user_can_login(array $user): bool
{
    if (($user['status'] ?? '') !== 'active') {
        return false;
    }
    if (($user['plan'] ?? 'paid') === 'free') {
        return true;
    }
    return ($user['card_status'] ?? '') === 'used';
}

function account_kind(string $account): string
{
    $account = trim($account);
    if (preg_match('/^1[3-9][0-9]{9}$/', $account)) {
        return 'phone';
    }
    if (preg_match('/^[1-9][0-9]{4,19}$/', $account)) {
        return 'qq';
    }
    if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
        return 'email';
    }
    return 'invalid';
}

function validate_register_account(string $account): ?string
{
    $account = trim($account);
    if ($account === '') {
        return '请输入 QQ、手机号或邮箱。';
    }
    if (strlen($account) > 60) {
        return '账号不能超过 60 个字符。';
    }
    if (preg_match('/^[a-zA-Z]+$/', $account)) {
        return '账号不能使用纯英文，请填写 QQ、手机号或邮箱。';
    }
    if (account_kind($account) === 'invalid') {
        return '账号格式不正确，请填写 QQ、手机号或邮箱。';
    }
    return null;
}

function qq_avatar_url(?string $qq, int $size = 100): string
{
    $qq = preg_replace('/\D+/', '', (string)$qq);
    if ($qq === '') {
        $qq = '10019';
    }
    return 'https://q1.qlogo.cn/g?b=qq&nk=' . rawurlencode($qq) . '&s=' . (int)$size;
}

function admin_qq_number(): string
{
    return trim(get_setting('admin_qq_number', '10019')) ?: '10019';
}

function bind_user_qq(int $userId, string $qq): ?string
{
    $qq = trim($qq);
    if (!preg_match('/^[1-9][0-9]{4,19}$/', $qq)) {
        return '请输入正确的 QQ 号。';
    }
    db()->prepare('UPDATE users SET qq_number = ? WHERE id = ?')->execute([$qq, $userId]);
    return null;
}

function renew_user_with_card(array $user, string $cardCode): ?string
{
    $cardCode = strtoupper(trim($cardCode));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM cards WHERE card_key = ? FOR UPDATE');
        $stmt->execute([$cardCode]);
        $card = $stmt->fetch();
        if (!$card || !card_available($card)) {
            throw new RuntimeException('卡密不存在、已使用或已到期。');
        }

        $baseTs = max(time(), strtotime((string)($user['expire_time'] ?? '')) ?: time());
        $expireBefore = !empty($user['expire_time']) ? (string)$user['expire_time'] : now();
        $newExpire = date('Y-m-d H:i:s', strtotime('+' . (int)$card['duration_days'] . ' days', $baseTs));
        $pdo->prepare("UPDATE users SET plan = 'paid', expire_time = ?, card_id = ? WHERE id = ?")
            ->execute([$newExpire, (int)$card['id'], (int)$user['id']]);
        $pdo->prepare("UPDATE cards SET status = 'used', used_by = ?, used_time = ? WHERE id = ?")
            ->execute([(int)$user['id'], now(), (int)$card['id']]);
        $pdo->prepare('INSERT INTO renew_logs (user_id, card_id, duration_days, expire_before, expire_after, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$user['id'], (int)$card['id'], (int)$card['duration_days'], $expireBefore, $newExpire, now()]);
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $e->getMessage();
    }
}

function plan_label(string $plan): string
{
    return $plan === 'free' ? '免费用户' : '付费用户';
}

function paid_pull_limit(): int
{
    return max(1, (int)get_setting('paid_daily_pull_limit', '10'));
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return trim(explode(',', $value)[0]);
        }
    }
    return 'unknown';
}

function log_subscription_access(array $user, string $type, bool $success): void
{
    db()->prepare('INSERT INTO subscription_access_logs (user_id, user_plan, client_type, ip_address, user_agent, success, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            (int)$user['id'],
            (($user['plan'] ?? 'paid') === 'free') ? 'free' : 'paid',
            substr($type, 0, 30),
            substr(client_ip(), 0, 64),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $success ? 1 : 0,
            now(),
        ]);
}

function paid_pull_count_24h(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) AS total FROM subscription_access_logs WHERE user_id = ? AND user_plan = 'paid' AND success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['total'];
}

function subscription_pull_count_24h(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) AS total FROM subscription_access_logs WHERE user_id = ? AND success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['total'];
}

function pending_compensation(int $userId): ?array
{
    $stmt = db()->prepare("SELECT c.*, r.id AS recipient_id FROM compensation_recipients r JOIN compensation_campaigns c ON c.id = r.campaign_id WHERE r.user_id = ? AND r.claimed_at IS NULL AND c.status = 'active' ORDER BY c.id ASC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function claim_compensation(int $userId, int $campaignId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT c.duration_days, r.id AS recipient_id FROM compensation_recipients r JOIN compensation_campaigns c ON c.id = r.campaign_id WHERE r.user_id = ? AND r.campaign_id = ? AND r.claimed_at IS NULL AND c.status = 'active' FOR UPDATE");
        $stmt->execute([$userId, $campaignId]);
        $campaign = $stmt->fetch();
        if (!$campaign) {
            throw new RuntimeException('该补偿已领取或已结束。');
        }
        $userStmt = $pdo->prepare('SELECT plan, expire_time FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            throw new RuntimeException('用户不存在。');
        }
        $days = max(1, (int)$campaign['duration_days']);
        $before = (string)$user['expire_time'];
        $base = (($user['plan'] ?? 'free') === 'paid' && strtotime($before) > time()) ? strtotime($before) : time();
        $after = date('Y-m-d H:i:s', $base + $days * 86400);
        if (($user['plan'] ?? 'free') === 'free') {
            $cardKey = 'GIFT-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            $pdo->prepare("INSERT INTO cards (card_key, duration_days, valid_until, status, used_by, used_time, created_at) VALUES (?, ?, NULL, 'used', ?, ?, ?)")
                ->execute([$cardKey, $days, $userId, now(), now()]);
            $cardId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE users SET plan = 'paid', expire_time = ?, card_id = ? WHERE id = ?")->execute([$after, $cardId, $userId]);
        } else {
            $pdo->prepare('UPDATE users SET expire_time = ? WHERE id = ?')->execute([$after, $userId]);
        }
        $pdo->prepare('UPDATE compensation_recipients SET claimed_at = ?, expire_before = ?, expire_after = ? WHERE id = ?')->execute([now(), $before, $after, (int)$campaign['recipient_id']]);
        $pdo->commit();
        return $days;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function enforce_paid_pull_limit(array $user): bool
{
    if (($user['plan'] ?? 'paid') !== 'paid') {
        return true;
    }
    if (paid_pull_count_24h((int)$user['id']) < paid_pull_limit()) {
        return true;
    }
    db()->prepare("UPDATE users SET status = 'banned', ban_reason = ?, banned_at = ? WHERE id = ?")
        ->execute(['24小时订阅拉取次数超过 ' . paid_pull_limit() . ' 次', now(), (int)$user['id']]);
    return false;
}

function generate_card_key(): string
{
    return strtoupper(substr(bin2hex(random_bytes(3)), 0, 4) . '-' . substr(bin2hex(random_bytes(3)), 0, 4) . '-' . substr(bin2hex(random_bytes(3)), 0, 4));
}

function make_slug(string $name): string
{
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $base = trim($base ?: 'sub', '-');
    return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function subscription_url(array $user, string $type = ''): string
{
    $url = base_url()
        . '/sub.php?user=' . (int)$user['id']
        . '&token=' . rawurlencode((string)$user['token']);
    if ($type !== '') {
        $url .= '&type=' . rawurlencode($type);
    }
    return $url;
}

function alipay_key(string $key, bool $private = false): string { $key=trim($key); if(strpos($key,'-----BEGIN')!==false) return $key; $key=preg_replace('/\s+/', '', $key)??''; return "-----BEGIN " . ($private ? 'PRIVATE' : 'PUBLIC') . " KEY-----\n" . chunk_split($key,64,"\n") . "-----END " . ($private ? 'PRIVATE' : 'PUBLIC') . " KEY-----"; }
function alipay_sign(array $params): string { unset($params['sign']); ksort($params); $parts=[]; foreach($params as $k=>$v) if($v!==''&&$v!==null) $parts[]=$k.'='.$v; $data=implode('&',$parts); if(!openssl_sign($data,$sig,alipay_key(get_setting('alipay_private_key'),true),OPENSSL_ALGO_SHA256)) throw new RuntimeException('支付宝私钥签名失败。'); return base64_encode($sig); }
function alipay_verify(array $params): bool { $sign=(string)($params['sign']??''); unset($params['sign'],$params['sign_type']); ksort($params); $parts=[]; foreach($params as $k=>$v) if($v!==''&&$v!==null) $parts[]=$k.'='.$v; return openssl_verify(implode('&',$parts),base64_decode($sign),alipay_key(get_setting('alipay_public_key')),OPENSSL_ALGO_SHA256)===1; }
function alipay_request(string $method,array $biz): array { if(!extension_loaded('openssl')) throw new RuntimeException('服务器未启用 OpenSSL 扩展。'); if(trim(get_setting('alipay_app_id'))===''||trim(get_setting('alipay_private_key'))===''||trim(get_setting('alipay_public_key'))==='') throw new RuntimeException('请先在后台完整填写 APPID、应用私钥和支付宝公钥。'); $params=['app_id'=>get_setting('alipay_app_id'),'method'=>$method,'format'=>'JSON','charset'=>'utf-8','sign_type'=>'RSA2','timestamp'=>now(),'version'=>'1.0','notify_url'=>base_url().'/alipay_notify.php','biz_content'=>json_encode($biz,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]; $params['sign']=alipay_sign($params); $body=http_build_query($params,'','&',PHP_QUERY_RFC3986); $url=get_setting('alipay_gateway','https://openapi.alipay.com/gateway.do'); $status=0;$type='';$raw=false; if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded; charset=utf-8','Accept: application/json','User-Agent: MoFaSubscription/1.0']]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('无法连接支付宝网关：'.$error);}else{$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\nAccept: application/json\r\nUser-Agent: MoFaSubscription/1.0",'content'=>$body,'timeout'=>20,'ignore_errors'=>true]]);$raw=@file_get_contents($url,false,$ctx);$headers=$http_response_header??[];foreach($headers as $h){if(stripos($h,'HTTP/')===0)$status=(int)preg_replace('/.*\s(\d{3}).*/','$1',$h);if(stripos($h,'Content-Type:')===0)$type=trim(substr($h,13));}if($raw===false)throw new RuntimeException('无法连接支付宝网关，请检查服务器外网、TLS 和网关地址。');}$json=json_decode((string)$raw,true);if(!is_array($json))throw new RuntimeException('支付宝网关返回非 JSON：HTTP '.$status.'，类型 '.($type?:'未知').'。请核对正式/沙箱网关地址和服务器网络。');$key=str_replace('.','_',$method).'_response';if(isset($json['error_response'])){$e=$json['error_response'];throw new RuntimeException('支付宝错误 '.($e['code']??'').' / '.($e['sub_code']??'').'：'.($e['sub_msg']??$e['msg']??'未知错误'));}return $json[$key]??[];}
function fulfill_payment_order(string $orderNo,string $tradeNo,string $amount): bool { $pdo=db(); $pdo->beginTransaction(); try{$s=$pdo->prepare('SELECT * FROM payment_orders WHERE order_no=? FOR UPDATE');$s->execute([$orderNo]);$o=$s->fetch();if(!$o||$o['status']==='paid'){ $pdo->commit(); return (bool)$o;} if(abs((float)$o['amount']-(float)$amount)>0.001) throw new RuntimeException('订单金额不一致'); $u=$pdo->prepare('SELECT plan,expire_time FROM users WHERE id=? FOR UPDATE');$u->execute([$o['user_id']]);$user=$u->fetch();$base=(($user['plan']??'free')==='paid'&&strtotime($user['expire_time'])>time())?strtotime($user['expire_time']):time();$after=date('Y-m-d H:i:s',$base+(int)$o['duration_days']*86400); if(($user['plan']??'free')==='free'){ $key='PAY-'.strtoupper(substr(bin2hex(random_bytes(8)),0,16));$pdo->prepare("INSERT INTO cards(card_key,duration_days,status,used_by,used_time,created_at) VALUES(?,?,'used',?,?,?)")->execute([$key,$o['duration_days'],$o['user_id'],now(),now()]);$card=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE users SET plan='paid',expire_time=?,card_id=? WHERE id=?")->execute([$after,$card,$o['user_id']]);}else{$pdo->prepare('UPDATE users SET expire_time=? WHERE id=?')->execute([$after,$o['user_id']]);}$pdo->prepare("UPDATE payment_orders SET status='paid',alipay_trade_no=?,paid_at=?,updated_at=? WHERE id=?")->execute([$tradeNo,now(),now(),$o['id']]);$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;} }

function client_types(): array
{
    return [
        'clash' => ['name' => 'Clash', 'setting' => 'sub_clash_url'],
        'shadowrocket' => ['name' => '小火箭', 'setting' => 'sub_shadowrocket_url'],
        'v2ray' => ['name' => 'V2Ray', 'setting' => 'sub_v2ray_url'],
        'surge' => ['name' => 'Surge', 'setting' => 'sub_surge_url'],
    ];
}

function infer_client_type(string $requestedType = ''): string
{
    $types = client_types();
    $requestedType = strtolower(trim($requestedType));
    if (isset($types[$requestedType])) {
        return $requestedType;
    }

    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strpos($ua, 'shadowrocket') !== false) {
        return 'shadowrocket';
    }
    if (strpos($ua, 'v2ray') !== false || strpos($ua, 'v2rayng') !== false || strpos($ua, 'v2rayn') !== false) {
        return 'v2ray';
    }
    if (strpos($ua, 'surge') !== false) {
        return 'surge';
    }
    return 'clash';
}

function upstream_url_for_type(string $type): string
{
    $types = client_types();
    $orderedTypes = array_unique([$type, 'clash', 'shadowrocket', 'v2ray', 'surge']);
    foreach ($orderedTypes as $clientType) {
        if (!isset($types[$clientType])) {
            continue;
        }
        $url = trim(get_setting($types[$clientType]['setting'], ''));
        if ($url !== '') {
            return $url;
        }
    }
    return '';
}

function remote_get(string $url): array
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'header' => "User-Agent: ZeroSub/1.0\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    return [$body, $headers];
}
