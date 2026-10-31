<?php
// Server cron entry. HTTP requests must never trigger this administrative job.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib.php';
try {
    $result = sync_subscription_lines_after_login();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Upstream sync failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
