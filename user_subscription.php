<?php
declare(strict_types=1);

// Free accounts use a 2099 placeholder. Accounts without a valid paid card
// include legacy manual upgrades and must not carry that placeholder forward.
function subscription_expiry_after_days(array $user, int $days, int $now): string
{
    if ($days === 0 || abs($days) > 3650) {
        throw new InvalidArgumentException('天数必须是 1 至 3650 的整数。');
    }
    $expiry = strtotime((string)($user['expire_time'] ?? ''));
    $validPaid = ($user['plan'] ?? 'free') === 'paid'
        && ($user['card_status'] ?? '') === 'used';
    $base = $validPaid && $expiry !== false ? $expiry : $now;
    if ($days > 0) $base = max($now, $base);
    return date('Y-m-d H:i:s', $base + $days * 86400);
}

function admin_change_subscription(PDO $pdo, int $userId, string $action, array $input): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) throw new RuntimeException('用户不存在。');
        $stmt = $pdo->prepare('SELECT status FROM cards WHERE id = ? FOR UPDATE');
        $stmt->execute([$user['card_id']]);
        $user['card_status'] = $stmt->fetchColumn() ?: null;
        if ($action === 'save_user_plan' && ($input['payment_plan_id'] ?? '') === 'free') {
            $pdo->prepare("UPDATE users SET plan = 'free', expire_time = '2099-12-31 23:59:59' WHERE id = ?")->execute([$userId]);
            $message = '已保存为免费用户。';
        } else {
            if ($action === 'save_user_plan') {
                $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE id = ? AND status = 'active' FOR UPDATE");
                $stmt->execute([(int)($input['payment_plan_id'] ?? 0)]);
                $plan = $stmt->fetch();
                if (!$plan) throw new RuntimeException('请选择已启用的套餐。');
                $days = (int)$plan['duration_days'];
                if ($days < 1 || $days > 3650) throw new RuntimeException('套餐天数必须为 1 至 3650 天。');
                $message = '已开通 ' . $plan['name'] . '，增加 ' . $days . ' 天。';
            } else {
                if ($user['plan'] !== 'paid') throw new RuntimeException('请先选择套餐开通，再调整天数。');
                $days = filter_var($input['days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
                if ($days === false) throw new RuntimeException('请输入 1 至 3650 的整数天数。');
                if ($action === 'subtract_user_days') $days = -$days;
                $message = ($days > 0 ? '已增加 ' : '已减少 ') . abs($days) . ' 天。';
            }
            $expiry = subscription_expiry_after_days($user, $days, time());
            $cardId = $user['card_id'];
            if ($user['plan'] !== 'paid' || $user['card_status'] !== 'used') {
                $pdo->prepare("INSERT INTO cards (card_key, duration_days, status, used_by, used_time, created_at) VALUES (?, ?, 'used', ?, ?, ?)")
                    ->execute(['ADMIN-' . strtoupper(bin2hex(random_bytes(12))), abs($days), $userId, now(), now()]);
                $cardId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE users SET plan = 'paid', card_id = ?, expire_time = ? WHERE id = ?")
                ->execute([$cardId, $expiry, $userId]);
            $message .= ' 到期时间：' . $expiry;
        }
        $pdo->commit();
        return $message;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
