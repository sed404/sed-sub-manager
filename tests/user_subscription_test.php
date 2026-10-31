<?php
require_once __DIR__ . '/../lib.php';

function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$now = strtotime('2026-09-09 12:00:00');
$free = ['plan' => 'free', 'expire_time' => '2099-12-31 23:59:59'];
expect(subscription_expiry_after_days($free, 365, $now) === date('Y-m-d H:i:s', $now + 365 * 86400), 'Free upgrade must ignore 2099');
$legacy = ['plan' => 'paid', 'expire_time' => '2100-01-10 23:59:59', 'card_status' => null];
expect(subscription_expiry_after_days($legacy, 10, $now) === date('Y-m-d H:i:s', $now + 10 * 86400), 'Repair legacy manual upgrade');
$paid = ['plan' => 'paid', 'expire_time' => '2026-10-09 12:00:00', 'card_status' => 'used'];
foreach ([10, -10, -100, 365] as $days) {
    expect(strtotime(subscription_expiry_after_days($paid, $days, $now)) === strtotime($paid['expire_time']) + $days * 86400, 'Exact day adjustment');
}
$paid['expire_time'] = '2026-01-01 12:00:00';
expect(strtotime(subscription_expiry_after_days($paid, 10, $now)) === $now + 10 * 86400, 'Expired renewal starts now');

// SQLite exercises real SQL and rollback offline; MySQL row locks are omitted.
class SubscriptionTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$pdo = new SubscriptionTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, plan TEXT, expire_time TEXT, card_id INTEGER, status TEXT, ban_reason TEXT);
CREATE TABLE cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_key TEXT UNIQUE, duration_days INTEGER, status TEXT, used_by INTEGER, used_time TEXT, created_at TEXT);
CREATE TABLE payment_plans (id INTEGER PRIMARY KEY, name TEXT, duration_days INTEGER, status TEXT);
INSERT INTO users VALUES (1, 'free', '2099-12-31 23:59:59', NULL, 'active', NULL);
INSERT INTO payment_plans VALUES (1, 'VIP', 365, 'active'), (2, 'Disabled', 30, 'disabled');");
function test_user(PDO $pdo): array {
    return $pdo->query('SELECT users.*, cards.status AS card_status FROM users LEFT JOIN cards ON cards.id = users.card_id WHERE users.id = 1')->fetch();
}
$start = time();
admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => '1']);
$user = test_user($pdo);
expect(abs(strtotime($user['expire_time']) - ($start + 365 * 86400)) < 3, 'VIP gives 365 days');
expect(user_can_login($user) && user_can_access($user), 'Granted user can log in and pull subscriptions');
$expiry = strtotime($user['expire_time']);
admin_change_subscription($pdo, 1, 'add_user_days', ['days' => '10']);
expect(strtotime(test_user($pdo)['expire_time']) === $expiry + 10 * 86400, 'Add ten');
admin_change_subscription($pdo, 1, 'subtract_user_days', ['days' => '10']);
expect(strtotime(test_user($pdo)['expire_time']) === $expiry, 'Subtract ten');
admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => '1']);
expect(strtotime(test_user($pdo)['expire_time']) === $expiry + 365 * 86400, 'Renew VIP');
foreach (['0', '-1', '1.5', '10000', 'abc', ''] as $days) {
    $before = test_user($pdo);
    try { admin_change_subscription($pdo, 1, 'add_user_days', ['days' => $days]); throw new LogicException('Accepted invalid days'); }
    catch (RuntimeException $e) {}
    expect(test_user($pdo) === $before && !$pdo->inTransaction(), 'Invalid input rolls back');
}
try { admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => 2]); throw new LogicException('Accepted disabled plan'); }
catch (RuntimeException $e) {}
$pdo->exec("UPDATE users SET status = 'banned', ban_reason = 'Manual ban' WHERE id = 1");
admin_change_subscription($pdo, 1, 'add_user_days', ['days' => 1]);
expect(test_user($pdo)['status'] === 'banned' && test_user($pdo)['ban_reason'] === 'Manual ban', 'Day changes preserve status');
admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => 'free']);
expect(test_user($pdo)['plan'] === 'free', 'Downgrade');
admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => '1']);
expect(abs(strtotime(test_user($pdo)['expire_time']) - (time() + 365 * 86400)) < 3, 'Re-upgrade ignores free sentinel even with old card');
// Force a write failure after card creation to verify atomic grants.
$pdo->exec("UPDATE users SET plan = 'free', card_id = NULL WHERE id = 1");
$count = $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try { admin_change_subscription($pdo, 1, 'save_user_plan', ['payment_plan_id' => 1]); throw new LogicException('Expected failure'); }
catch (RuntimeException $e) {}
expect($pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn() === $count, 'Grant rollback removes inserted card');
echo "Subscription tests passed\n";
