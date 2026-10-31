<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/node_settings.php';
require_once __DIR__ . '/subscription_formats.php';
require_once __DIR__ . '/subscription_controls.php';
require_once __DIR__ . '/subscription_preferences.php';
require_once __DIR__ . '/user_subscription.php';

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

        CREATE TABLE IF NOT EXISTS subscription_lines (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            upstream_url TEXT NOT NULL,
            clashverge_url TEXT NULL,
            shadowrocket_url TEXT NULL,
            expire_time DATETIME NULL,
            total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            upload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            download_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_auto_sync_at DATETIME NULL,
            last_sync_at DATETIME NULL,
            sync_status ENUM('never','success','failed') NOT NULL DEFAULT 'never',
            sync_error VARCHAR(255) NULL,
            auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
            allow_insecure_tls TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('available','maintenance','disabled') NOT NULL DEFAULT 'available',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_subscription_lines_status_sort (status, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS subscription_line_caches (
            line_id INT UNSIGNED PRIMARY KEY,
            raw_yaml MEDIUMTEXT NOT NULL,
            node_count INT UNSIGNED NOT NULL DEFAULT 0,
            fetched_at DATETIME NOT NULL,
            CONSTRAINT fk_subscription_line_cache_line FOREIGN KEY (line_id) REFERENCES subscription_lines(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS subscription_node_overrides (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            line_id INT UNSIGNED NOT NULL,
            node_key CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            custom_name VARCHAR(255) NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NOT NULL,
            UNIQUE KEY uniq_subscription_node (line_id, node_key),
            INDEX idx_subscription_nodes_line_sort (line_id, is_present, sort_order, id),
            CONSTRAINT fk_subscription_node_line FOREIGN KEY (line_id) REFERENCES subscription_lines(id) ON DELETE CASCADE
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
    ensure_column($pdo, 'users', 'default_line_id', 'ALTER TABLE users ADD COLUMN default_line_id INT UNSIGNED NULL');
    ensure_column($pdo, 'subscription_lines', 'clashverge_url', 'ALTER TABLE subscription_lines ADD COLUMN clashverge_url TEXT NULL AFTER upstream_url');
    ensure_column($pdo, 'subscription_lines', 'shadowrocket_url', 'ALTER TABLE subscription_lines ADD COLUMN shadowrocket_url TEXT NULL AFTER clashverge_url');
    ensure_column($pdo, 'subscription_lines', 'upload_bytes', 'ALTER TABLE subscription_lines ADD COLUMN upload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_bytes');
    ensure_column($pdo, 'subscription_lines', 'download_bytes', 'ALTER TABLE subscription_lines ADD COLUMN download_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER upload_bytes');
    ensure_column($pdo, 'subscription_lines', 'last_auto_sync_at', 'ALTER TABLE subscription_lines ADD COLUMN last_auto_sync_at DATETIME NULL');
    ensure_column($pdo, 'subscription_lines', 'last_sync_at', 'ALTER TABLE subscription_lines ADD COLUMN last_sync_at DATETIME NULL AFTER download_bytes');
    ensure_column($pdo, 'subscription_lines', 'sync_status', "ALTER TABLE subscription_lines ADD COLUMN sync_status ENUM('never','success','failed') NOT NULL DEFAULT 'never' AFTER last_sync_at");
    ensure_column($pdo, 'subscription_lines', 'sync_error', 'ALTER TABLE subscription_lines ADD COLUMN sync_error VARCHAR(255) NULL AFTER sync_status');
    ensure_column($pdo, 'subscription_lines', 'auto_sync_enabled', 'ALTER TABLE subscription_lines ADD COLUMN auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER sync_error');
    ensure_column($pdo, 'subscription_lines', 'allow_insecure_tls', 'ALTER TABLE subscription_lines ADD COLUMN allow_insecure_tls TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_sync_enabled');
    ensure_user_card_nullable($pdo);

    $defaults = [
        'site_notice' => '<p><strong>欢迎使用 sed机场管理程序。</strong></p><p>请妥善保管你的订阅链接，卡密到期后订阅将自动失效。</p>',
        'notice_popup_enabled' => '1',
        'faq_enabled' => '1',
        'faq_content' => '<p><strong>Q：为什么线路显示可用，节点却全部超时？</strong></p><p>A：部分客户端不支持某些节点协议或配置格式，建议更换客户端后重新导入订阅。目前 iOS 端 Shadowrocket（小火箭）的兼容性相对最好。</p><p><strong>Q：为什么价格这么便宜？会不会网速慢或跑路？</strong></p><p>A：平台聚合其他机场的节点资源，节点服务器并非由平台直接运营。平台主要负责维护节点可用性，并及时更新和替换失效线路，因此能够降低使用成本。</p>',
        'docs_url' => '',
        'popup_notice_enabled' => '1',
        'popup_notice_once' => '1',
        'popup_notice_title' => '服务公告',
        'popup_notice_content' => "欢迎使用 sed机场管理程序。\n请妥善保管你的订阅链接，卡密到期后订阅将自动失效。",
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
        'purchase_mode' => 'direct',
        'alipay_app_id' => '',
        'alipay_private_key' => '',
        'alipay_public_key' => '',
        'alipay_gateway' => 'https://openapi.alipay.com/gateway.do',
        'paid_daily_pull_limit' => '10',
        'sub_clash_url' => '',
        'sub_shadowrocket_url' => '',
        'sub_v2ray_url' => '',
        'sub_surge_url' => '',
        'site_theme' => 'ocean',
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
    $pdo->prepare("UPDATE settings SET setting_value = REPLACE(setting_value, 'MoFa共享节点服务', 'sed机场管理程序'), updated_at = ? WHERE setting_key IN ('site_notice', 'popup_notice_content') AND setting_value LIKE ?")
        ->execute([now(), '%MoFa共享节点服务%']);

    $pdo->prepare("UPDATE settings SET setting_value = REPLACE(setting_value, 'sed机场管理程序', 'sed机场管理程序'), updated_at = ? WHERE setting_key IN ('site_notice', 'popup_notice_content') AND setting_value LIKE ?")
        ->execute([now(), '%sed机场管理程序%']);


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

function site_themes(): array
{
    return [
        'default' => ['name'=>'默认浅色', 'description'=>'清晰浅色 · 默认主题'],
        'dark' => ['name'=>'深色', 'description'=>'石墨深色 · 柔和对比'],
        'translucent-green' => ['name'=>'半透明绿色', 'description'=>'绿色渐变 · 轻量叠色'],
        'translucent-blue' => ['name'=>'半透明蓝色', 'description'=>'蓝色渐变 · 轻量叠色'],
        'translucent-yellow' => ['name'=>'半透明黄色', 'description'=>'黄色渐变 · 轻量叠色'],
        'translucent-red' => ['name'=>'半透明红色', 'description'=>'红色渐变 · 轻量叠色'],
        'translucent-pink' => ['name'=>'半透明粉色', 'description'=>'粉色渐变 · 轻量叠色'],
        'translucent-cyan' => ['name'=>'半透明青色', 'description'=>'青色渐变 · 轻量叠色'],
    ];
}

function site_theme(): string
{
    $theme = get_setting('site_theme', 'default');
    $aliases = ['ocean'=>'default','mono'=>'default','mint'=>'translucent-green','violet'=>'translucent-pink','amber'=>'translucent-yellow'];
    $theme = $aliases[$theme] ?? $theme;
    return isset(site_themes()[$theme]) ? $theme : 'default';
}

function site_theme_class(): string
{
    return 'theme-' . site_theme();
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

        $expireBefore = !empty($user['expire_time']) ? (string)$user['expire_time'] : now();
        $baseTs = ($user['plan'] ?? 'free') === 'paid' ? max(time(), strtotime($expireBefore) ?: time()) : time();
        $newExpire = date('Y-m-d H:i:s', $baseTs + (int)$card['duration_days'] * 86400);
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

function subscription_url(array $user, string $type = '', int $lineId = 0): string
{
    $url = base_url()
        . '/sub.php?user=' . (int)$user['id']
        . '&token=' . rawurlencode((string)$user['token']);
    if ($type !== '') {
        $url .= '&type=' . rawurlencode($type);
    }
    if ($lineId > 0) {
        $url .= '&line=' . $lineId;
    }
    return $url;
}

function subscription_lines(): array
{
    return db()->query('SELECT * FROM subscription_lines ORDER BY sort_order ASC, id ASC')->fetchAll();
}

function subscription_line(int $lineId): ?array
{
    if ($lineId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM subscription_lines WHERE id = ?');
    $stmt->execute([$lineId]);
    $line = $stmt->fetch();
    return $line ?: null;
}

function subscription_line_upstream(array $line, string $type): string
{
    // Legacy URLs are only fallback values for records without a common source.
    return trim((string)($line['upstream_url'] ?? ''))
        ?: trim((string)($line['clashverge_url'] ?? ''))
        ?: trim((string)($line['shadowrocket_url'] ?? ''));
}

function subscription_line_status_label(string $status): string
{
    return [
        'available' => '可用',
        'maintenance' => '维护中',
        'disabled' => '停用',
    ][$status] ?? '停用';
}

function format_traffic_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '不限';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float)$bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$index];
}

function subscription_source_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function validate_subscription_source_url(string $url): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('订阅链接格式无效。');
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = trim((string)($parts['host'] ?? ''), '[]');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('订阅链接只允许使用 HTTP 或 HTTPS。');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('订阅链接不能包含 URL 登录凭据。');
    }

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } else {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records ?: [] as $record) {
            if (!empty($record['ip'])) $addresses[] = $record['ip'];
            if (!empty($record['ipv6'])) $addresses[] = $record['ipv6'];
        }
    }
    if (!$addresses) {
        throw new RuntimeException('订阅域名无法解析。');
    }
    foreach ($addresses as $address) {
        if (!subscription_source_ip_is_public($address)) {
            throw new RuntimeException('订阅链接不能指向本机、内网或保留地址。');
        }
    }
    return [$host, $addresses[0], $scheme, (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
}

function fetch_subscription_userinfo(string $url, bool $allowInsecureTls = false): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('服务器未安装 PHP cURL 扩展。');
    }
    [$host, $ip, $scheme, $port] = validate_subscription_source_url($url);
    $headers = [];
    $received = 0;
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'ClashVerge/1.7 Subscription-Metadata-Sync',
        CURLOPT_HTTPHEADER => ['Accept: */*'],
        CURLOPT_SSL_VERIFYPEER => !$allowInsecureTls,
        CURLOPT_SSL_VERIFYHOST => $allowInsecureTls ? 0 : 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = strtolower(trim(substr($line, 0, $position)));
                $headers[$name] = trim(substr($line, $position + 1));
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$received): int {
            $received += strlen($data);
            return $received > 5242880 ? 0 : strlen($data);
        },
    ];
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($ok === false && $received <= 5242880) {
        throw new RuntimeException('获取订阅失败：' . ($error ?: '网络请求失败'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('上游订阅返回 HTTP ' . $status . '。');
    }
    $raw = trim((string)($headers['subscription-userinfo'] ?? ''));
    if ($raw === '') {
        throw new RuntimeException('上游未返回 Subscription-Userinfo 流量信息。');
    }

    $values = [];
    foreach (explode(';', $raw) as $item) {
        $pair = explode('=', trim($item), 2);
        if (count($pair) === 2) $values[strtolower(trim($pair[0]))] = trim($pair[1]);
    }
    foreach (['upload', 'download', 'total'] as $required) {
        if (!isset($values[$required]) || !is_numeric($values[$required])) {
            throw new RuntimeException('上游流量响应缺少 ' . $required . ' 字段。');
        }
    }
    $expireTime = null;
    if (!empty($values['expire']) && is_numeric($values['expire']) && (int)$values['expire'] > 0) {
        $expireTime = date('Y-m-d H:i:s', (int)$values['expire']);
    }
    return [
        'upload_bytes' => max(0, (int)$values['upload']),
        'download_bytes' => max(0, (int)$values['download']),
        'total_bytes' => max(0, (int)$values['total']),
        'expire_time' => $expireTime,
    ];
}

function sync_subscription_line(int $lineId): array
{
    $line = subscription_line($lineId);
    if (!$line) throw new RuntimeException('机场线路不存在。');
    $url = trim((string)($line['upstream_url'] ?: $line['clashverge_url'] ?: $line['shadowrocket_url']));
    try {
        $info = fetch_subscription_userinfo($url, !empty($line['allow_insecure_tls']));
        db()->prepare("UPDATE subscription_lines SET upload_bytes = ?, download_bytes = ?, total_bytes = ?, expire_time = ?, last_sync_at = ?, sync_status = 'success', sync_error = NULL, updated_at = ? WHERE id = ?")
            ->execute([$info['upload_bytes'], $info['download_bytes'], $info['total_bytes'], $info['expire_time'], now(), now(), $lineId]);
        return $info;
    } catch (Throwable $e) {
        $syncError = $e->getMessage();
        if (function_exists('mb_substr')) {
            $syncError = mb_substr($syncError, 0, 255, 'UTF-8');
        }
        db()->prepare("UPDATE subscription_lines SET last_sync_at = ?, sync_status = 'failed', sync_error = ?, updated_at = ? WHERE id = ?")
            ->execute([now(), $syncError, now(), $lineId]);
        throw $e;
    }
}

function upstream_sync_hours(): int
{
    return max(1, min(168, (int)get_setting('upstream_sync_hours', '12')));
}

function sync_subscription_lines_after_login(?int $onlyLineId = null): array
{
    $pdo = db();
    try {
        $lock = $pdo->query("SELECT GET_LOCK('subscription_metadata_auto_sync', 0) AS acquired")->fetch();
    } catch (Throwable $e) {
        return ['synced' => 0, 'failed' => 0, 'skipped' => true];
    }
    if (!$lock || (int)$lock['acquired'] !== 1) {
        return ['synced' => 0, 'failed' => 0, 'skipped' => true];
    }
    try {
        $cutoff = date('Y-m-d H:i:s', time() - upstream_sync_hours() * 60 * 60);
        $sql = "SELECT id FROM subscription_lines
                WHERE status <> 'disabled' AND auto_sync_enabled = 1
                  AND (last_auto_sync_at IS NULL OR last_auto_sync_at <= ?)";
        $params = [$cutoff];
        if ($onlyLineId !== null && $onlyLineId > 0) { $sql .= ' AND id = ?'; $params[] = $onlyLineId; }
        $sql .= ' ORDER BY COALESCE(last_auto_sync_at, \'1970-01-01\'), id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $synced = 0;
        $failed = 0;
        foreach ($stmt->fetchAll() as $row) {
            $pdo->prepare('UPDATE subscription_lines SET last_auto_sync_at = ? WHERE id = ?')->execute([now(), (int)$row['id']]);
            $lineSucceeded = false;
            try {
                sync_subscription_line((int)$row['id']);
            } catch (Throwable $e) {
            }
            try {
                sync_clash_line_nodes((int)$row['id']);
                $lineSucceeded = true;
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE subscription_lines SET sync_status = 'failed', sync_error = ?, updated_at = ? WHERE id = ?")
                    ->execute(['节点解析或匹配失败，已保留原节点缓存及自定义名称；请手动同步检查。', now(), (int)$row['id']]);
            }
            if ($lineSucceeded) {
                $synced++;
            } else {
                $failed++;
            }
        }
        return ['synced' => $synced, 'failed' => $failed, 'skipped' => false];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('subscription_metadata_auto_sync')");
    }
}

function fetch_clash_subscription_document(string $url, bool $allowInsecureTls = false): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('服务器未安装 PHP cURL 扩展，无法解析订阅。');
    }
    [$host, $ip, $scheme, $port] = validate_subscription_source_url($url);
    $body = '';
    $limit = 10 * 1024 * 1024;
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'ClashVerge/1.7 MoFa-Subscription-Sync',
        CURLOPT_HTTPHEADER => ['Accept: text/yaml, text/plain, application/yaml, */*'],
        CURLOPT_SSL_VERIFYPEER => !$allowInsecureTls,
        CURLOPT_SSL_VERIFYHOST => $allowInsecureTls ? 0 : 2,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false) {
        throw new RuntimeException(strlen($body) >= $limit ? '订阅文件超过 10MB，已拒绝解析。' : '下载订阅失败：' . ($error ?: '网络请求失败'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('上游订阅返回 HTTP ' . $status . '。');
    }
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    if (trim($body) === '') {
        throw new RuntimeException('上游订阅内容为空。');
    }
    return str_replace(["\r\n", "\r"], "\n", $body);
}

function clash_yaml_scalar_decode(string $value): string
{
    $value = trim($value);
    $length = strlen($value);
    if ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") {
        return str_replace("''", "'", substr($value, 1, -1));
    }
    if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
        $decoded = json_decode($value, true);
        return is_string($decoded) ? $decoded : stripcslashes(substr($value, 1, -1));
    }
    return trim($value);
}

function clash_yaml_quote(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? trim($value);
    return "'" . str_replace("'", "''", $value) . "'";
}

function clash_proxy_name_pattern(): string
{
    return "/\\bname\\s*:\\s*('(?:[^']|'')*'|\"(?:\\\\.|[^\"])*\"|[^,}\\r\\n]+)/u";
}

function clash_extract_proxy_items(string $yaml): array
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));
    $inside = false;
    $itemIndent = null;
    $current = [];
    $rawItems = [];
    foreach ($lines as $line) {
        if (!$inside) {
            if (preg_match('/^proxies:\s*(?:#.*)?$/', $line)) {
                $inside = true;
            }
            continue;
        }
        if (preg_match('/^[^\s#][^:]*:\s*/', $line)) {
            if ($current) $rawItems[] = implode("\n", $current);
            break;
        }
        if (preg_match('/^(\s*)-\s+/', $line, $listMatch)) {
            $indent = strlen($listMatch[1]);
            if ($itemIndent === null) $itemIndent = $indent;
            if ($indent === $itemIndent) {
                if ($current) $rawItems[] = implode("\n", $current);
                $current = [$line];
            } elseif ($current) {
                $current[] = $line;
            }
        } elseif ($current) {
            $current[] = $line;
        }
    }
    if ($inside && $current && (!$rawItems || end($rawItems) !== implode("\n", $current))) {
        $rawItems[] = implode("\n", $current);
    }

    $items = [];
    $occurrences = [];
    foreach ($rawItems as $sourceOrder => $raw) {
        if (!preg_match(clash_proxy_name_pattern(), $raw, $match)) {
            continue;
        }
        $name = clash_yaml_scalar_decode($match[1]);
        if ($name === '') continue;
        $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
        $items[] = [
            'node_key' => hash('sha256', $name . "\0" . $occurrences[$name]),
            'original_name' => $name,
            'raw' => rtrim($raw),
            'source_order' => $sourceOrder,
        ];
    }
    if (!$items) {
        throw new RuntimeException('没有在订阅中找到 Clash proxies 节点；当前版本仅支持完整 Clash YAML。');
    }
    return $items;
}

function clash_replace_proxy_name(string $raw, string $name): string
{
    return preg_replace_callback(
        clash_proxy_name_pattern(),
        static fn(array $match): string => 'name: ' . clash_yaml_quote($name),
        $raw,
        1
    ) ?? $raw;
}

function sync_clash_line_nodes(int $lineId): array
{
    $line = subscription_line($lineId);
    if (!$line) throw new RuntimeException('机场线路不存在。');
    $url = subscription_line_upstream($line, 'clash');
    if ($url === '') throw new RuntimeException('该线路没有配置 Clash 订阅链接。');
    $yaml = normalize_upstream_subscription(fetch_clash_subscription_document($url, !empty($line['allow_insecure_tls'])));
    $items = clash_extract_proxy_items($yaml);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM subscription_lines WHERE id = ? FOR UPDATE');
        $lock->execute([$lineId]);
        if (!$lock->fetch()) throw new RuntimeException('线路已删除。');
        $oldCacheStmt = $pdo->prepare('SELECT raw_yaml FROM subscription_line_caches WHERE line_id = ?');
        $oldCacheStmt->execute([$lineId]);
        $oldCache = $oldCacheStmt->fetch();
        $oldItems = $oldCache ? clash_extract_proxy_items(normalize_upstream_subscription($oldCache['raw_yaml'])) : [];
        $retained = retain_node_settings($oldItems, $items, clash_nodes_for_line($lineId, false));
        $pdo->prepare('INSERT INTO subscription_line_caches (line_id, raw_yaml, node_count, fetched_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE raw_yaml = VALUES(raw_yaml), node_count = VALUES(node_count), fetched_at = VALUES(fetched_at)')
            ->execute([$lineId, $yaml, count($items), now()]);
        $pdo->prepare('UPDATE subscription_node_overrides SET is_present = 0 WHERE line_id = ?')->execute([$lineId]);
        $upsert = $pdo->prepare('INSERT INTO subscription_node_overrides (line_id, node_key, original_name, custom_name, enabled, sort_order, is_present, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), custom_name = VALUES(custom_name), enabled = VALUES(enabled), sort_order = VALUES(sort_order), is_present = 1, last_seen_at = VALUES(last_seen_at)');
        foreach ($items as $index => $item) {
            $saved = $retained[$item['node_key']] ?? [];
            $upsert->execute([$lineId, $item['node_key'], $item['original_name'], $saved['custom_name'] ?? null, $saved['enabled'] ?? 1, $saved['sort_order'] ?? $index, now()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['node_count' => count($items), 'fetched_at' => now()];
}

function clash_nodes_for_line(int $lineId, bool $presentOnly = true): array
{
    $sql = 'SELECT * FROM subscription_node_overrides WHERE line_id = ?';
    if ($presentOnly) $sql .= ' AND is_present = 1';
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute([$lineId]);
    return $stmt->fetchAll();
}

function build_clash_subscription(int $lineId): ?string
{
    $cacheStmt = db()->prepare('SELECT raw_yaml FROM subscription_line_caches WHERE line_id = ?');
    $cacheStmt->execute([$lineId]);
    $cache = $cacheStmt->fetch();
    if (!$cache) return null;
    return render_clash_subscription((string)$cache['raw_yaml'], clash_nodes_for_line($lineId));
}

function render_clash_subscription(string $yaml, array $overrideRows): string
{
    $items = clash_extract_proxy_items($yaml);
    $overrides = [];
    foreach ($overrideRows as $row) $overrides[$row['node_key']] = $row;
    $selected = [];
    foreach ($items as $item) {
        $override = $overrides[$item['node_key']] ?? null;
        if ($override && empty($override['enabled'])) continue;
        $item['sort_order'] = $override ? (int)$override['sort_order'] : (int)$item['source_order'];
        $custom = trim((string)($override['custom_name'] ?? ''));
        $item['output_name'] = $custom !== '' ? $custom : $item['original_name'];
        $selected[] = $item;
    }
    usort($selected, static fn(array $a, array $b): int => [$a['sort_order'], $a['source_order']] <=> [$b['sort_order'], $b['source_order']]);
    if (!$selected) throw new RuntimeException('该线路没有启用的节点。');

    $usedNames = ['DIRECT' => true, 'REJECT' => true, 'sed机场管理程序' => true, '自动选择' => true, '故障转移' => true];
    $proxyYaml = [];
    $names = [];
    foreach ($selected as $item) {
        $baseName = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $item['output_name']) ?? $item['output_name'];
        $baseName = trim($baseName);
        if ($baseName === '') $baseName = $item['original_name'];
        $name = $baseName;
        $suffix = 2;
        while (isset($usedNames[$name])) $name = $baseName . ' #' . $suffix++;
        $usedNames[$name] = true;
        $names[] = $name;
        $proxyYaml[] = clash_replace_proxy_name($item['raw'], $name);
    }

    $nodeList = '';
    foreach ($names as $name) $nodeList .= '      - ' . clash_yaml_quote($name) . "\n";
    $groups = "  - name: 'sed机场管理程序'\n"
        . "    type: select\n"
        . "    proxies:\n"
        . "      - '自动选择'\n"
        . "      - '故障转移'\n"
        . $nodeList
        . "  - name: '自动选择'\n"
        . "    type: url-test\n"
        . "    url: 'http://www.gstatic.com/generate_204'\n"
        . "    interval: 86400\n"
        . "    proxies:\n"
        . $nodeList
        . "  - name: '故障转移'\n"
        . "    type: fallback\n"
        . "    url: 'http://www.gstatic.com/generate_204'\n"
        . "    interval: 7200\n"
        . "    proxies:\n"
        . $nodeList;
    $templatePath = __DIR__ . '/data/clash_yiyuan_template.yml';
    $template = @file_get_contents($templatePath);
    if ($template === false || strpos($template, '{{PROXIES}}') === false || strpos($template, '{{PROXY_GROUPS}}') === false) {
        throw new RuntimeException('Clash 默认模板不存在或格式无效。');
    }
    return str_replace(['{{PROXIES}}', '{{PROXY_GROUPS}}'], [implode("\n", $proxyYaml), rtrim($groups)], $template);
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
        'clashmeta' => ['name' => 'Clash Meta / Verge Rev', 'setting' => 'sub_clash_url'],
        'shadowrocket' => ['name' => '小火箭', 'setting' => 'sub_shadowrocket_url'],
        'v2ray' => ['name' => 'V2Ray', 'setting' => 'sub_v2ray_url'],
        'quantumultx' => ['name' => 'Quantumult X', 'setting' => 'sub_clash_url'],
        'singbox' => ['name' => 'sing-box', 'setting' => 'sub_clash_url'],
    ];
}

function infer_client_type(string $requestedType = ''): string
{
    $types = client_types();
    $requestedType = strtolower(trim($requestedType));
    $aliases = ['clashverge'=>'clashmeta','clash-verge'=>'clashmeta','clash-meta'=>'clashmeta','mihomo'=>'clashmeta','flclash'=>'clashmeta',
        'verge'=>'clashmeta','v2rayn'=>'v2ray','v2rayng'=>'v2ray','sing-box'=>'singbox','quantumult-x'=>'quantumultx','qx'=>'quantumultx'];
    $requestedType = $aliases[$requestedType] ?? $requestedType;
    if (isset($types[$requestedType])) {
        return $requestedType;
    }
    if ($requestedType !== '' && $requestedType !== 'auto') throw new InvalidArgumentException('Unknown subscription format.');

    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (str_contains($ua, 'quantumult')) return 'quantumultx';
    if (str_contains($ua, 'sing-box') || str_contains($ua, 'singbox') || str_contains($ua, 'sfi/') || str_contains($ua, 'sfa/')) return 'singbox';
    if (strpos($ua, 'shadowrocket') !== false) {
        return 'shadowrocket';
    }
    if (strpos($ua, 'v2ray') !== false || strpos($ua, 'v2rayng') !== false || strpos($ua, 'v2rayn') !== false) {
        return 'v2ray';
    }
    if (str_contains($ua, 'clash') && !preg_match('/meta|mihomo|verge|flclash/', $ua)) return 'clash';
    return 'clashmeta';
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
