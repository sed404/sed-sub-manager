<?php
declare(strict_types=1);

function selectable_subscription_lines(array $lines, ?int $timestamp = null): array
{
    $timestamp ??= time();
    return array_values(array_filter($lines, static function (array $line) use ($timestamp): bool {
        if (($line['status'] ?? '') !== 'available') return false;
        if (empty($line['expire_time'])) return true;
        $expiry = strtotime((string)$line['expire_time']);
        return $expiry !== false && $expiry >= $timestamp;
    }));
}

/** Invalid or retired preferences fall back to the first available sorted line. */
function preferred_subscription_line_id(array $user, array $lines): int
{
    $available = selectable_subscription_lines($lines);
    $preferred = (int)($user['default_line_id'] ?? 0);
    foreach ($available as $line) if ((int)$line['id'] === $preferred) return $preferred;
    return (int)($available[0]['id'] ?? 0);
}

function save_default_subscription_line(PDO $pdo, array $user, int $lineId, array $lines): void
{
    if (($user['plan'] ?? 'free') !== 'paid') throw new InvalidArgumentException('付费订阅才可选择默认线路。');
    $ids = array_map('intval', array_column(selectable_subscription_lines($lines), 'id'));
    if ($lineId < 0 || ($lineId !== 0 && !in_array($lineId, $ids, true))) {
        throw new InvalidArgumentException('这条线路当前不可用，请重新选择。');
    }
    $pdo->prepare('UPDATE users SET default_line_id = ? WHERE id = ?')->execute([$lineId ?: null, (int)$user['id']]);
}
