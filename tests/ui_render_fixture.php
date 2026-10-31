<?php
/** CLI-only visual fixtures: actual presentation sections, synthetic data, no DB or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../subscription_controls.php';
require_once __DIR__ . '/../subscription_preferences.php';
define('APP_NAME', 'sed机场管理程序');
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function site_theme(): string { return 'default'; }
function site_theme_class(): string { return 'theme-default'; }
function csrf_token(): string { return 'fixture-csrf-not-a-session'; }
function qq_avatar_url($qq): string { return '/assets/mofa-hat.svg'; }
function admin_qq_number(): string { return ''; }
function base_url(): string { return 'http://127.0.0.1:8765'; }
function card_state($card): string { return '未使用'; }
function plan_label($plan): string { return $plan === 'free' ? '免费用户' : '付费订阅'; }
function admin_card_status_options(): array { return [''=>'全部','unused'=>'未使用','used'=>'已使用','disabled'=>'停用']; }
function admin_plan_options(): array { return ['free'=>'免费','paid'=>'付费']; }
function upstream_sync_hours(): int { return 12; }
function safe_notice_html($s): string { return h($s); }
function subscription_url($user,$format='',$line=0): string { return 'https://example.invalid/sub.php?user=fixture&type=' . $format; }
function subscription_lines(): array { return array_map(fn($id)=>['id'=>$id,'name'=>'线路 '.$id,'status'=>'available','expire_time'=>null,'upload_bytes'=>0,'download_bytes'=>0,'total_bytes'=>0], [11,12]); }
function format_traffic_bytes($bytes): string { return '0 B'; }
function site_themes(): array {
    $names = ['default'=>'默认浅色','dark'=>'深色','translucent-green'=>'半透明绿色','translucent-blue'=>'半透明蓝色','translucent-yellow'=>'半透明黄色','translucent-red'=>'半透明红色','translucent-pink'=>'半透明粉色','translucent-cyan'=>'半透明青色'];
    return array_map(fn($name)=>['name'=>$name,'description'=>'站点默认主题'], $names);
}
function db(): object { return new class { public function query($sql): object { return new class {public function fetchAll(): array {return [];}};}}; }
function render_fixture(string $path, string $tab, string $output, bool $auth=false): void {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    $offset = $auth ? strpos($source, '<!doctype html>') : strrpos($source, '<!doctype html>');
    $source = substr($source, $offset);
    if ($auth) $source = substr($source, 0, strpos($source, '</html>') + 7);
    // Initialize presentation-only values; strict warnings catch missing fixture fields.
    preg_match_all('/\$([A-Za-z_]\w*)/', $source, $vars);
    foreach (array_unique($vars[1]) as $name) if (!in_array($name,['source','path','tab','output','auth','offset','vars','name'],true)) $$name = '';
    preg_match_all('/(?:foreach\s*\(\s*|count\s*\(\s*)\$(\w+)/', $source, $lists);
    foreach ($lists[1] as $name) $$name = [];
    $user=['username'=>'预览用户','plan'=>'free','expire_time'=>'2027-10-01','qq_number'=>'','created_at'=>'2026-10-01'];
    $admin=['username'=>'管理员'];$agent=['username'=>'代理预览','balance'=>0,'card_price'=>0];
    $siteTheme='default';$isFree=true;$isExpired=false;$needsQqBind=false;$freeSubscriptionEnabled=true;
    $pendingCompensation=null;$showLoginAnnouncement=false;$flash=null;$error='';$bannedReason='';
    $purchaseMode='direct';$alipayGateway='https://openapi.alipay.com/gateway.do';
    $noticeEnabled=true;$noticeTitle='使用说明';$noticeContent='此页面使用测试数据，仅用于界面验收。';
    $downloadEnabled=false;$paidCardBuyUrl='';$createdOrder=null;$myOrders=[];
    $cards = $tab === 'cards' && !$auth ? [['id'=>1,'card_key'=>'FIXTURE-ONLY-0001','status'=>'unused','duration_days'=>30,'valid_until'=>'','used_username'=>'','used_qq_number'=>'','created_at'=>'2026-10-04']] : [];
    if (str_contains($output, 'user-paid')) { $isFree=false; $user['plan']='paid'; $user['default_line_id']=12; $subscriptionLines=subscription_lines(); $pullLimit=20; $pullCount24h=0; $daysLeft=30; }
    $paymentPlans=[['id'=>1,'name'=>'界面测试套餐','price'=>10,'duration_days'=>30,'status'=>'active']];
    if ($path==='pay.php') $plans=$paymentPlans;
    if (str_contains($output,'paged')) $cards=array_map(fn($id)=>array_merge($cards[0],['id'=>$id,'card_key'=>'FIXTURE-'.$id]),range(1,25));
    if (str_contains($output,'pay-pending')) $createdOrder=['order_no'=>'UI-FIXTURE','plan_name'=>'测试订单','duration_days'=>30,'amount'=>10,'qr_code'=>'https://example.invalid/pay-fixture'];
    if (str_contains($output,'announcement')) {$showLoginAnnouncement=true;$popupNoticeEnabled=true;$popupNoticeTitle='测试公告';$popupNoticeContent='用于验证弹窗焦点、键盘与主题。';}
    set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
    $temporary=tempnam(sys_get_temp_dir(),'sed-ui-');file_put_contents($temporary,$source);
    ob_start();
    try { include $temporary; $html=ob_get_clean();file_put_contents($output,$html); }
    catch(Throwable $e) {ob_end_clean();throw $e;}
    finally {unlink($temporary);restore_error_handler();}
}
$directory=$argv[1]??sys_get_temp_dir().'/sed-ui-fixtures';
if(!is_dir($directory))mkdir($directory,0777,true);
foreach(['cards','subs','agents','users','compensation','tickets','payment','orders','notice','appearance','settings'] as $tab) render_fixture('admin/index.php',$tab,$directory.'/admin-'.$tab.'.html');
foreach(['home','renew','docs','tickets','profile'] as $tab) render_fixture('dashboard.php',$tab,$directory.'/user-'.$tab.'.html');
render_fixture('agent/index.php','cards',$directory.'/agent.html');
render_fixture('pay.php','renew',$directory.'/pay.html');
render_fixture('login.php','',$directory.'/login.html');
render_fixture('register.php','',$directory.'/register.html');
render_fixture('index.php','',$directory.'/landing.html');
render_fixture('admin/index.php','',$directory.'/admin-login.html',true);
render_fixture('agent/index.php','',$directory.'/agent-login.html',true);
render_fixture('admin/index.php','cards',$directory.'/admin-paged.html');
render_fixture('pay.php','renew',$directory.'/pay-pending.html');
render_fixture('dashboard.php','home',$directory.'/user-announcement.html');
render_fixture('dashboard.php','home',$directory.'/user-paid.html');
echo "Rendered UI fixtures to $directory\n";
