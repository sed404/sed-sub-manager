<?php
 declare(strict_types=1);
define('APP_NAME', 'sed机场管理程序');
date_default_timezone_set('Asia/Shanghai');
if (!defined('APP_INSTALLING')) {
    if (!is_file(__DIR__ . '/config.local.php')) {
        if (PHP_SAPI === 'cli') { exit("请先通过网站 /install.php 完成安装。\n"); }
        header('Location: /install.php'); exit;
    }
    require __DIR__ . '/config.local.php';
}
