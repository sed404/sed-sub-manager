<?php
require_once __DIR__ . '/../lib.php';
require_admin();
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $body = build_clash_subscription((int)($_GET['line'] ?? 0));
    if ($body === null) throw new RuntimeException('请先同步解析该线路。');
    echo $body;
} catch (Throwable $e) {
    http_response_code(422);
    echo $e->getMessage();
}
