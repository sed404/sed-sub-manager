<?php
declare(strict_types=1);

define('APP_NAME', 'MoFa共享节点订阅');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'mofa_subscription');
define('DB_USER', 'mofa_user');
define('DB_PASS', 'replace-with-a-strong-database-password');
define('DB_CHARSET', 'utf8mb4');

// Used only when the admins table is empty. Change both values before first visit.
define('ADMIN_DEFAULT_USER', 'admin');
define('ADMIN_DEFAULT_PASS', 'replace-with-a-random-password-at-least-12-characters');

date_default_timezone_set('Asia/Shanghai');

