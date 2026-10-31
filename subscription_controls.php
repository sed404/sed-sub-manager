<?php
declare(strict_types=1);

function subscription_apps(): array
{
    return [
        'shadowrocket'=>['name'=>'小火箭','format'=>'shadowrocket','label'=>'Shadowrocket'],
        'verge'=>['name'=>'Clash Verge','format'=>'clashmeta','label'=>'Clash Verge Rev'],
        'v2rayn'=>['name'=>'v2rayN','format'=>'v2ray','label'=>'v2rayN'],
        'meta'=>['name'=>'Clash Meta','format'=>'clashmeta','label'=>'Clash Meta'],
    ];
}

function subscription_import_url(string $app, string $url, string $name): ?string
{
    if (in_array($app, ['clash','meta','verge'], true)) return 'clash://install-config?url=' . rawurlencode($url) . '&name=' . rawurlencode($name);
    if ($app === 'shadowrocket') return 'shadowrocket://add/sub://' . base64_encode($url) . '?remark=' . rawurlencode($name);
    if ($app === 'singbox') return 'sing-box://import-remote-profile?url=' . rawurlencode($url) . '#' . rawurlencode($name);
    if ($app === 'quantumultx') {
        $tag = str_replace([',',"\r","\n"], ['，',' ',' '], $name);
        return 'quantumult-x:///add-resource?remote-resource=' . rawurlencode(json_encode(['server_remote'=>[$url . ', tag=' . $tag . ', enabled=true']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    // v2rayN uses copy/paste because there is no verified cross-version import scheme.
    return null;
}

function subscription_app_icon(string $app): string
{
    $files = ['shadowrocket'=>'shadowrocket.jpg', 'verge'=>'verge.png', 'v2rayn'=>'v2rayn.png', 'meta'=>'meta.png'];
    if (!isset($files[$app])) return '';
    return '<span class="client-icon" aria-hidden="true">&#9670;</span>';
}

function subscription_client_availability(int $lineId): array
{
    static $cache = [];
    if ($lineId <= 0) {
        $lineId = (int)db()->query("SELECT id FROM subscription_lines WHERE status = 'available' AND (expire_time IS NULL OR expire_time >= NOW()) ORDER BY sort_order ASC, id ASC LIMIT 1")->fetchColumn();
    }
    if ($lineId <= 0) return [];
    if (isset($cache[$lineId])) return $cache[$lineId];
    $cache[$lineId] = [];
    try { $yaml = build_clash_subscription($lineId); } catch (Throwable $e) { $yaml = null; }
    if ($yaml === null) return [];
    foreach (array_unique(array_column(subscription_apps(), 'format')) as $format) {
        try { $result = convert_client_subscription($yaml, $format); $cache[$lineId][$format] = ['count'=>$result['count'], 'skipped'=>$result['skipped']]; }
        catch (Throwable $e) { $cache[$lineId][$format] = ['count'=>0, 'skipped'=>0]; }
    }
    return $cache[$lineId];
}

function render_subscription_controls(array $user, int $lineId = 0, string $name = APP_NAME): void
{
    $url = subscription_url($user, '', $lineId);
    $availabilityLine = $lineId;
    if ($lineId <= 0 && ($user['plan'] ?? 'free') === 'paid') $availabilityLine = preferred_subscription_line_id($user, subscription_lines());
    $availability = ($user['plan'] ?? 'free') === 'paid' ? subscription_client_availability($availabilityLine) : [];
    ?>
    <div class="subscription-controls">
        <label class="subscription-link-label">通用订阅链接
            <span class="subscription-copy-row"><input class="copy-input" readonly value="<?= h($url) ?>" aria-label="通用订阅链接" onclick="this.select()"><button type="button" class="primary" data-copy="<?= h($url) ?>">一键复制</button></span>
        </label>
        <p class="muted subscription-hint">选择你使用的客户端。无法打开应用时，可复制专用链接到客户端添加订阅；不同客户端可用的节点数量可能不同。</p>
        <div class="subscription-clients">
        <?php foreach (subscription_apps() as $key => $app):
            $clientUrl = subscription_url($user, $app['format'], $lineId);
            $import = subscription_import_url($key, $clientUrl, $name);
            $supported = !isset($availability[$app['format']]) || $availability[$app['format']]['count'] > 0;
        ?>
            <div class="subscription-client">
                <button type="button" class="client-copy" <?= $supported ? 'data-copy="' . h($clientUrl) . '"' : 'disabled' ?> aria-label="复制 <?= h($app['name']) ?> 订阅链接">
                    <?= subscription_app_icon($key) ?><span><strong><?= h($app['name']) ?></strong><small><?= $supported ? '复制链接' : '暂无兼容节点' ?></small></span>
                </button>
                <?php if (!$supported): ?><span class="client-import-note">请使用其他客户端</span><?php elseif ($import !== null): ?><a class="client-import" href="<?= h($import) ?>" aria-label="导入 <?= h($app['name']) ?>">一键导入 <span aria-hidden="true">↗</span></a><?php else: ?><span class="client-import-note">复制后在应用内添加订阅</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php
}
