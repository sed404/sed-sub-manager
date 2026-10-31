<?php
declare(strict_types=1);

/** Subscription providers expose changing account notices as proxy entries. */
function subscription_notice_kind(string $name): ?string
{
    $patterns = [
        'traffic' => '/^剩余流量\s*[:：]\s*\d+(?:\.\d+)?\s*(?:[KMGTPE]?B)(?:\s.*)?$/iu',
        'reset' => '/^距离下次重置剩余\s*[:：]\s*\d+\s*天$/u',
        'expiry' => '/^套餐到期\s*[:：]\s*\d{4}-\d{1,2}-\d{1,2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/u',
    ];
    foreach ($patterns as $kind => $pattern) {
        if (preg_match($pattern, trim($name)) === 1) return $kind;
    }
    return null;
}

/** Ignore only a trailing provider website advertisement, not region or index. */
function subscription_node_identity_name(string $name): string
{
    return preg_replace(
        '~\s*-\s*国内官网\s*[:：]\s*(?:https?://)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}/?\s*$~iu',
        '',
        $name
    ) ?? $name;
}

/**
 * Preserve overrides by original identity, then by unchanged connection config.
 * If a customized node cannot be identified safely, retain the previous cache.
 */
function retain_node_settings(array $oldItems, array $newItems, array $rows): array
{
    $saved = [];
    foreach ($rows as $row) $saved[$row['node_key']] = $row;
    $remaining = [];
    foreach ($oldItems as $item) $remaining[$item['node_key']] = $item;
    $result = [];
    foreach ($newItems as $item) {
        $key = $item['node_key'];
        // Historical rows remain in the database. A reused notice name (for
        // example 23 days in a later billing cycle) must not claim the new item
        // before settings from the current cache have been migrated.
        if (isset($remaining[$key])) {
            $result[$key] = $saved[$key] ?? [];
            unset($remaining[$key]);
        }
    }
    // Match notice types before connection fingerprints: providers often clone
    // the same proxy for traffic, reset, expiry and several real nodes.
    // Only a unique old/new pair of the same notice type is eligible.
    $oldNotices = [];
    $newNotices = [];
    foreach ($oldItems as $item) {
        $kind = subscription_notice_kind($item['original_name']);
        if ($kind !== null) $oldNotices[$kind][] = $item['node_key'];
    }
    foreach ($newItems as $item) {
        $kind = subscription_notice_kind($item['original_name']);
        if ($kind !== null) $newNotices[$kind][] = $item['node_key'];
    }
    foreach ($newNotices as $kind => $keys) {
        if (count($keys) !== 1 || count($oldNotices[$kind] ?? []) !== 1) continue;
        $key = $keys[0];
        $oldKey = $oldNotices[$kind][0];
        if (isset($result[$key]) || !isset($remaining[$oldKey])) continue;
        $result[$key] = $saved[$oldKey] ?? [];
        unset($remaining[$oldKey]);
    }
    // Match the complete stable name, including flag, region and node number.
    // Count all entries, even already matched ones, to reject ambiguous bases.
    $oldNames = [];
    $newNames = [];
    foreach ($oldItems as $item) {
        if (subscription_notice_kind($item['original_name']) !== null) continue;
        $name = subscription_node_identity_name($item['original_name']);
        if ($name !== '') $oldNames[$name][] = $item['node_key'];
    }
    foreach ($newItems as $item) {
        if (subscription_notice_kind($item['original_name']) !== null) continue;
        $name = subscription_node_identity_name($item['original_name']);
        if ($name !== '') $newNames[$name][] = $item['node_key'];
    }
    foreach ($newNames as $name => $keys) {
        if (count($keys) !== 1 || count($oldNames[$name] ?? []) !== 1) continue;
        $key = $keys[0];
        $oldKey = $oldNames[$name][0];
        if (isset($result[$key]) || !isset($remaining[$oldKey])) continue;
        $result[$key] = $saved[$oldKey] ?? [];
        unset($remaining[$oldKey]);
    }
    $fingerprint = static function (array $item): string {
        return hash('sha256', trim(clash_replace_proxy_name($item['raw'], '__identity__')));
    };
    foreach ($newItems as $item) {
        $key = $item['node_key'];
        if (isset($result[$key])) continue;
        $matches = [];
        foreach ($remaining as $oldKey => $old) {
            if ($fingerprint($old) === $fingerprint($item)) $matches[] = $oldKey;
        }
        $newMatches = array_filter($newItems, static fn(array $candidate): bool =>
            !isset($result[$candidate['node_key']]) && $fingerprint($candidate) === $fingerprint($item));
        if (count($matches) === 1 && count($newMatches) === 1) {
            $oldKey = $matches[0];
            $result[$key] = $saved[$oldKey] ?? [];
            unset($remaining[$oldKey]);
        }
    }
    foreach ($remaining as $key => $item) {
        $row = $saved[$key] ?? [];
        if (trim((string)($row['custom_name'] ?? '')) !== '' || (isset($row['enabled']) && !$row['enabled'])) {
            // Keep diagnostics in the authenticated sync result, without URLs,
            // credentials, raw proxy configuration or database contents.
            $kind = subscription_notice_kind($item['original_name']);
            $noticeNames = [];
            foreach ($newItems as $candidate) {
                if (subscription_notice_kind($candidate['original_name']) !== null) {
                    $noticeNames[] = $candidate['original_name'];
                }
            }
            $diagnostic = '诊断 NS-20261024-4；旧/新节点数=' . count($oldItems) . '/' . count($newItems)
                . '；提示类型=' . ($kind ?? '未识别')
                . '；同类旧/新数量=' . count($oldNotices[$kind ?? ''] ?? []) . '/' . count($newNotices[$kind ?? ''] ?? [])
                . '；新提示=' . json_encode(array_slice($noticeNames, 0, 6), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($kind === null) $diagnostic .= '；旧名称UTF8=' . bin2hex(substr($item['original_name'], 0, 160));
            throw new RuntimeException('上游节点发生变化，无法可靠匹配节点「' . $item['original_name'] . '」已有的改名或禁用设置；已保留原节点缓存与名称，流量信息可独立更新。[' . $diagnostic . ']');
        }
    }
    // Restore historical settings only when no current identity matched.
    foreach ($newItems as $item) {
        $key = $item['node_key'];
        if (!isset($result[$key]) && isset($saved[$key])) $result[$key] = $saved[$key];
    }
    return $result;
}

/** Validate the entire submission before writing any row. Missing rows mean a truncated/stale form. */
function validate_node_settings(array $nodes, array $submitted): array
{
    if (!$nodes || count($submitted) !== count($nodes)) {
        throw new RuntimeException('节点列表已变化或提交不完整，请刷新页面后重试。');
    }
    $updates = [];
    foreach ($nodes as $node) {
        $key = (string)$node['node_key'];
        $entry = $submitted[$key] ?? null;
        if (!is_array($entry) || !isset($entry['name'], $entry['sort'], $entry['enabled']) || !is_string($entry['name'])) {
            throw new RuntimeException('节点设置提交不完整，请刷新后重新保存。');
        }
        $name = trim($entry['name']);
        if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $name) || preg_match_all('/./us', $name) > 255) {
            throw new RuntimeException('名称须为有效文本，最多 255 个字符，不能包含换行。');
        }
        $sort = filter_var($entry['sort'], FILTER_VALIDATE_INT);
        if ($sort === false || $sort < -100000 || $sort > 100000 || !is_bool($entry['enabled'])) {
            throw new RuntimeException('节点状态或排序无效。');
        }
        $updates[] = ['node_key' => $key, 'custom_name' => $name === '' ? null : $name, 'enabled' => $entry['enabled'] ? 1 : 0, 'sort_order' => $sort];
    }
    return $updates;
}

/** Apply the same saved names to native URI subscriptions (including base64 envelopes). */
function rename_native_subscription(string $body, array $nodes): string
{
    $map = [];
    foreach ($nodes as $node) $map[(string)$node['original_name']] = $node;
    $encoded = false;
    $text = trim($body);
    if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $text)) {
        $decoded = base64_decode(strtr(preg_replace('/\s+/', '', $text), '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', trim($decoded))) return $body;
        $text = trim($decoded);
        $encoded = true;
    }
    $lines = [];
    $used = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $vmess = null;
        if (stripos($line, 'vmess://') === 0) {
            $raw = base64_decode(strtr(substr($line, 8), '-_', '+/'), true);
            $vmess = $raw === false ? null : json_decode($raw, true);
        }
        $fragment = strpos($line, '#');
        $original = is_array($vmess) ? (string)($vmess['ps'] ?? '') : ($fragment === false ? '' : rawurldecode(substr($line, $fragment + 1)));
        $node = $map[$original] ?? null;
        if (!$node) { $lines[] = $line; continue; }
        if (empty($node['enabled'])) continue;
        $custom = trim((string)($node['custom_name'] ?? ''));
        $name = $custom !== '' ? $custom : $original;
        $base = $name; $n = 2;
        while (isset($used[$name])) $name = $base . ' #' . $n++;
        $used[$name] = true;
        if (is_array($vmess)) {
            $vmess['ps'] = $name;
            $line = 'vmess://' . base64_encode(json_encode($vmess, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $line = ($fragment === false ? $line : substr($line, 0, $fragment)) . '#' . rawurlencode($name);
        }
        $lines[] = $line;
    }
    if (!$lines) throw new RuntimeException('该线路没有启用的节点。');
    $result = implode("\n", $lines) . "\n";
    return $encoded ? base64_encode($result) : $result;
}
