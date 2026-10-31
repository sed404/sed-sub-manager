<?php
require_once __DIR__ . '/../lib.php';
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$noticeYaml = "proxies:\n  - {name: '距离下次重置剩余：30 天', type: trojan, server: example.org, port: 443, password: test}\n";
$oldNotices = clash_extract_proxy_items($noticeYaml);
$newNotices = clash_extract_proxy_items(str_replace('30 天', '23 天', $noticeYaml));
$currentRow = $oldNotices[0] + ['custom_name'=>'重置提示', 'enabled'=>0, 'sort_order'=>7];
$historicalRow = $newNotices[0] + ['custom_name'=>'历史名称', 'enabled'=>1, 'sort_order'=>1, 'is_present'=>0];
$retained = retain_node_settings($oldNotices, $newNotices, [$currentRow, $historicalRow]);
check($retained[$newNotices[0]['node_key']]['custom_name'] === '重置提示', 'Historical countdown stole current alias');
check($retained[$newNotices[0]['node_key']]['enabled'] === 0, 'Historical countdown re-enabled notice');
check($retained[$newNotices[0]['node_key']]['sort_order'] === 7, 'Countdown order lost');
$retained = retain_node_settings($oldNotices, $newNotices, [$currentRow]);
check($retained[$newNotices[0]['node_key']]['custom_name'] === '重置提示', 'First countdown change failed');
$retained = retain_node_settings([], $newNotices, [$historicalRow]);
check($retained[$newNotices[0]['node_key']]['custom_name'] === '历史名称', 'Historical fallback lost');
echo "PASS: countdown changes and reused historical names preserve current settings\n";

// Ambiguous notices must still fail safely, with actionable diagnostics.
$duplicate = clash_extract_proxy_items(str_replace("proxies:\n", "proxies:\n  - {name: '距离下次重置剩余：22 天', type: trojan, server: example.org, port: 443, password: test}\n", str_replace('30 天', '23 天', $noticeYaml)));
$message = '';
try { retain_node_settings($oldNotices, $duplicate, [$currentRow]); }
catch (RuntimeException $e) { $message = $e->getMessage(); }
check(str_contains($message, 'NS-20261024-4'), 'Missing deployed revision diagnostic');
check(str_contains($message, '同类旧/新数量=1/2'), 'Duplicate count not diagnosed');
check(!str_contains($message, 'example.org') && !str_contains($message, 'password'), 'Diagnostics leaked connection configuration');
echo "PASS: ambiguous notices stay protected with sanitized diagnostics\n";

// Provider website suffix changes must not erase current settings, even if
// the connection changes too or other nodes share its connection config.
$hk = '🇭🇰 HK香港-1';
$websiteYaml = "proxies:\n  - {name: '$hk-国内官网：ktm.ooo', type: trojan, server: old.example.org, port: 443, password: test}\n";
$websiteOld = clash_extract_proxy_items($websiteYaml);
$websiteNew = clash_extract_proxy_items(str_replace(['ktm.ooo', 'old.example.org'], ['ktm01.com', 'new.example.org'], $websiteYaml));
$websiteRow = $websiteOld[0] + ['custom_name'=>'我的香港', 'enabled'=>0, 'sort_order'=>8];
$websiteHistory = $websiteNew[0] + ['custom_name'=>'旧设置', 'enabled'=>1, 'sort_order'=>99];
$retained = retain_node_settings($websiteOld, $websiteNew, [$websiteRow, $websiteHistory]);
check($retained[$websiteNew[0]['node_key']]['custom_name'] === '我的香港', 'Website change lost alias');
check($retained[$websiteNew[0]['node_key']]['enabled'] === 0, 'Website change lost disabled state');
check($retained[$websiteNew[0]['node_key']]['sort_order'] === 8, 'Website change lost order');
$withoutWebsite = clash_extract_proxy_items(str_replace('-国内官网：ktm01.com', '', str_replace(['ktm.ooo', 'old.example.org'], ['ktm01.com', 'new.example.org'], $websiteYaml)));
check(retain_node_settings($websiteOld, $withoutWebsite, [$websiteRow])[$withoutWebsite[0]['node_key']]['enabled'] === 0, 'Website removal failed');
check(subscription_node_identity_name('🇭🇰 HK香港-01-国内官网：ktm01.com') !== $hk, 'Node numbers collapsed');
check(subscription_node_identity_name('🇸🇬 SG新加坡-1-国内官网：ktm01.com') !== $hk, 'Regions collapsed');
check(subscription_node_identity_name($hk . '-国内官网：ktm01.com-专线') === $hk . '-国内官网：ktm01.com-专线', 'Non-trailing suffix stripped');
$ambiguousWebsite = array_merge($websiteNew, $withoutWebsite);
$message = '';
try { retain_node_settings($websiteOld, $ambiguousWebsite, [$websiteRow]); }
catch (RuntimeException $e) { $message = $e->getMessage(); }
check(str_contains($message, '无法可靠匹配'), 'Ambiguous website identities accepted');
$message = '';
try { retain_node_settings($websiteOld, [], [$websiteRow]); }
catch (RuntimeException $e) { $message = $e->getMessage(); }
check(str_contains($message, '无法可靠匹配'), 'Removed customized node silently dropped');
// A consumed current item without an override must not pick up a stale row.
$retained = retain_node_settings($websiteOld, $websiteNew, [$websiteHistory]);
check($retained[$websiteNew[0]['node_key']] === [], 'Historical row claimed current node with default settings');
echo "PASS: website suffix migration, ambiguous names, removed nodes and historical defaults\n";

// Optional live response supplied through stdin; no subscription secrets stored.
if (in_array('--stdin', $argv, true)) {
    $live = clash_extract_proxy_items(normalize_upstream_subscription(stream_get_contents(STDIN)));
    $old = [];
    $rows = [];
    foreach ($live as $i => $item) {
        $previous = $item;
        if (subscription_notice_kind($item['original_name']) === 'reset') {
            $previous['original_name'] = '距离下次重置剩余：30 天';
            $previous['node_key'] = hash('sha256', $previous['original_name'] . "\0" . '1');
            $previous['raw'] = clash_replace_proxy_name($item['raw'], $previous['original_name']);
            $rows[] = $item + ['custom_name'=>'历史设置', 'enabled'=>1, 'sort_order'=>999, 'is_present'=>0];
        }
        if (str_contains($item['original_name'], 'HK香港-1-国内官网：')) {
            $previous['original_name'] = '🇭🇰 HK香港-1-国内官网：ktm.ooo';
            $previous['node_key'] = hash('sha256', $previous['original_name'] . "\0" . '1');
            $previous['raw'] = str_replace('443', '8443', clash_replace_proxy_name($item['raw'], $previous['original_name']));
            $rows[] = $item + ['custom_name'=>'历史香港', 'enabled'=>1, 'sort_order'=>999, 'is_present'=>0];
        }
        $old[] = $previous;
        $rows[] = $previous + ['custom_name'=>'当前设置 ' . $i, 'enabled'=>0, 'sort_order'=>$i];
    }
    $retained = retain_node_settings($old, $live, $rows);
    foreach ($live as $i => $item) {
        check(($retained[$item['node_key']]['custom_name'] ?? '') === '当前设置 ' . $i, 'Live alias migration failed');
        check($retained[$item['node_key']]['enabled'] === 0, 'Live disable state lost');
        check($retained[$item['node_key']]['sort_order'] === $i, 'Live sort order lost');
    }
    echo 'PASS: live response migration with simulated old cache and historical rows (' . count($live) . " nodes)\n";
}
