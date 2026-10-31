<?php
require_once __DIR__ . '/../lib.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rejects(callable $fn): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Expected rejection');
}
$yaml = "proxies:\n  - {name: 原名, type: vmess, server: example.com, port: 443, uuid: test}\n  - {name: 禁用节点, type: trojan, server: example.org, port: 443, password: test}\n";
$items = clash_extract_proxy_items($yaml);
$nodes = [];
foreach ($items as $i => $item) $nodes[] = $item + ['custom_name'=>null, 'enabled'=>1, 'sort_order'=>$i];
$payload = [];
foreach ($nodes as $i => $node) $payload[$node['node_key']] = ['name'=>$i === 0 ? "自定义 ' 香港" : '', 'enabled'=>$i === 0, 'sort'=>0];
$updates = validate_node_settings($nodes, $payload);
foreach ($updates as $i => $update) $nodes[$i] = array_merge($nodes[$i], $update);
$output = render_clash_subscription($yaml, $nodes);
check(str_contains($output, "name: '自定义 '' 香港'"), 'Clash node was not renamed');
check(substr_count($output, "- '自定义 '' 香港'") === 3, 'All three groups must reference renamed node');
check(!str_contains($output, '禁用节点'), 'Disabled node leaked');
check(str_contains($output, 'MATCH,一元机场'), 'Template lost rules');
$uri = 'trojan://test@example.com:443#' . rawurlencode('原名');
$renamed = rename_native_subscription($uri, $nodes);
check(str_contains($renamed, rawurlencode("自定义 ' 香港")), 'Native URI rename failed');
check(base64_decode(rename_native_subscription(base64_encode($uri), $nodes)) === $renamed, 'Base64 envelope failed');
$vmess = 'vmess://' . base64_encode(json_encode(['ps'=>'原名','id'=>'test','add'=>'example.com']));
$renamedVmess = json_decode(base64_decode(substr(trim(rename_native_subscription($vmess, $nodes)), 8)), true);
check($renamedVmess['ps'] === "自定义 ' 香港" && $renamedVmess['id'] === 'test', 'VMess rename changed credentials');
rejects(fn() => validate_node_settings($nodes, []));
$bad = $payload; unset($bad[$nodes[0]['node_key']]['enabled']);
rejects(fn() => validate_node_settings($nodes, $bad));
$bad = $payload; $bad[$nodes[0]['node_key']]['name'] = "bad\nname";
rejects(fn() => validate_node_settings($nodes, $bad));
$zero = $nodes; $zero[0]['custom_name'] = '0';
check(str_contains(render_clash_subscription($yaml, $zero), "name: '0'"), 'Zero-valued name lost');
echo "PASS: rename, strategy groups, disable, URI, base64, VMess, incomplete forms, invalid names, zero name\n";
$changed = clash_extract_proxy_items(str_replace('example.com', 'updated.example.com', $yaml));
$retained = retain_node_settings($items, $changed, $nodes);
check($retained[$items[0]['node_key']]['custom_name'] === "自定义 ' 香港", 'Refresh erased alias');
$renamedItems = clash_extract_proxy_items(str_replace('原名', '上游新名', $yaml));
$retained = retain_node_settings($items, $renamedItems, $nodes);
check($retained[$renamedItems[0]['node_key']]['custom_name'] === "自定义 ' 香港", 'Upstream rename erased alias');
check($retained[$renamedItems[1]['node_key']]['enabled'] === 0, 'Refresh re-enabled disabled node');
$ambiguous = clash_extract_proxy_items(str_replace(['原名', 'example.com'], ['未知节点', 'unknown.example.net'], $yaml));
rejects(fn() => retain_node_settings($items, $ambiguous, $nodes));
echo "PASS: refresh preserves overrides; ambiguous changes rejected\n";
foreach (array_slice($argv, 1) as $file) {
    $source = file_get_contents($file);
    $parsed = clash_extract_proxy_items($source);
    $overrides = [];
    foreach ($parsed as $i => $item) $overrides[] = $item + ['custom_name'=>'回归节点 ' . $i, 'enabled'=>1, 'sort_order'=>$i];
    $result = render_clash_subscription($source, $overrides);
    $generated = clash_extract_proxy_items($result);
    check(count($parsed) === count($generated), 'Node count changed');
    foreach ($generated as $i => $item) check($item['original_name'] === '回归节点 ' . $i, 'Sample rename failed');
    echo basename($file) . ': PASS ' . count($parsed) . " nodes\n";
}
