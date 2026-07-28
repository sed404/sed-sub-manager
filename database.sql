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
    agent_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_cards_status (status),
    INDEX idx_cards_used_by (used_by),
    INDEX idx_cards_agent_id (agent_id)
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
CREATE TABLE IF NOT EXISTS payment_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    duration_days INT UNSIGNED NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(40) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    plan_name VARCHAR(80) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    duration_days INT UNSIGNED NOT NULL,
    status ENUM('pending','paid','closed','failed') NOT NULL DEFAULT 'pending',
    alipay_trade_no VARCHAR(64) NULL,
    qr_code TEXT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_payment_user (user_id, created_at),
    INDEX idx_payment_status (status),
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 新版订阅配置存放在 settings 表：
-- sub_clash_url、sub_shadowrocket_url、sub_v2ray_url、sub_surge_url。
-- subscriptions 表仅保留用于兼容旧部署数据。
-- admin_qq_number 用于后台管理员头像，默认值会由程序自动写入 settings。
-- free_sub_url 用于免费用户订阅引流；paid_card_buy_url 用于前台购买付费卡密按钮；paid_daily_pull_limit 控制付费用户24小时拉取上限。
-- 代理功能会使用 agents、agent_card_logs，并在 cards.agent_id 记录卡密来源。
-- 用户续费功能会使用 renew_logs 记录续费前后的到期时间。
-- 订阅拉取风控会使用 subscription_access_logs 记录付费用户访问次数。
