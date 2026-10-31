<?php
require_once __DIR__ . '/../subscription_preferences.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, default_line_id INTEGER NULL)');
$pdo->exec('INSERT INTO users VALUES (1,NULL),(2,NULL)');
$lines = [
    ['id'=>11,'status'=>'available','expire_time'=>null],
    ['id'=>12,'status'=>'available','expire_time'=>'2099-01-01'],
    ['id'=>13,'status'=>'disabled','expire_time'=>null],
    ['id'=>14,'status'=>'available','expire_time'=>'2000-01-01'],
];
$user=['id'=>1,'plan'=>'paid'];
save_default_subscription_line($pdo,$user,12,$lines);
$saved=$pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(preferred_subscription_line_id($saved,$lines)===12,'Saved selection must survive reload');
check($pdo->query('SELECT default_line_id FROM users WHERE id=2')->fetchColumn()===null,'Must not modify another user');
foreach ([13,14,999,-1] as $id) {
    try {save_default_subscription_line($pdo,$user,$id,$lines);throw new RuntimeException('Accepted unavailable line');}
    catch(InvalidArgumentException $e) {}
}
try {save_default_subscription_line($pdo,['id'=>1,'plan'=>'free'],12,$lines);throw new RuntimeException('Accepted free user');}
catch(InvalidArgumentException $e) {}
check(preferred_subscription_line_id(['default_line_id'=>13],$lines)===11,'Disabled preference must fall back');
check(preferred_subscription_line_id(['default_line_id'=>14],$lines)===11,'Expired preference must fall back');
check(preferred_subscription_line_id(['default_line_id'=>999],$lines)===11,'Deleted preference must fall back');
check(preferred_subscription_line_id($saved,[])===0,'Empty lines must return zero');
save_default_subscription_line($pdo,$user,0,$lines);
check($pdo->query('SELECT default_line_id FROM users WHERE id=1')->fetchColumn()===null,'Auto selection must clear preference');
echo "Subscription preference persistence and fallback tests passed.\n";
