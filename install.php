<?php
 declare(strict_types=1);
define('APP_INSTALLING', true);
if (is_file(__DIR__.'/config.local.php')) { http_response_code(403); exit('已安装，安装入口已锁定。'); }
session_start();
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
$error = ''; $success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $guard = fopen(__DIR__.'/data/install.guard', 'c');
    try {
        if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) throw new RuntimeException('已有安装正在进行，请稍后再试。');
        if (is_file(__DIR__.'/config.local.php')) throw new RuntimeException('系统已安装。');
        if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('页面已过期，请刷新。');
        foreach (['pdo_mysql','curl','openssl','mbstring'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException('缺少扩展：'.$ext);
        $host = trim($_POST['host'] ?? ''); $port = (string)($_POST['port'] ?? '3306'); $name = trim($_POST['database'] ?? '');
        $user = trim($_POST['user'] ?? ''); $pass = (string)($_POST['password'] ?? '');
        $admin = trim($_POST['admin'] ?? ''); $secret = (string)($_POST['admin_password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $host) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('数据库主机、端口或库名格式无效。');
        if (!preg_match('/^[a-zA-Z0-9_]{3,60}$/D', $admin) || strlen($secret) < 12 || strlen($secret) > 72) throw new RuntimeException('管理员账号需 3–60 位字母数字或下划线，密码需 12–72 字节。');
        if ($secret !== ($_POST['confirm'] ?? '')) throw new RuntimeException('两次管理员密码不一致。');
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('请使用空数据库，已有数据不会被覆盖。若上次失败，请另建空库后重试。');
        require __DIR__.'/lib.php';
        init_db($pdo);
        $pdo->prepare('INSERT INTO admins (username,password_hash,created_at) VALUES (?,?,?)')->execute([$admin,password_hash($secret,PASSWORD_DEFAULT),date('Y-m-d H:i:s')]);
        $config = "<?php\n";
        foreach (['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_NAME'=>$name,'DB_USER'=>$user,'DB_PASS'=>$pass,'DB_CHARSET'=>'utf8mb4'] as $key=>$value) $config .= 'define('.var_export($key,true).', '.var_export($value,true).");\n";
        $temp = __DIR__.'/data/install-config.php';
        if (file_put_contents($temp,$config,LOCK_EX) === false) throw new RuntimeException('配置写入失败，请检查目录权限。');
        @chmod($temp,0600);
        if (!rename($temp,__DIR__.'/config.local.php')) { @unlink($temp); throw new RuntimeException('配置保存失败，请检查网站根目录权限。'); }
        $success = true;
    } catch (PDOException $e) { $error = '数据库连接或建表失败，请检查数据库信息、权限和版本。使用空数据库重试。'; }
      catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '安装失败，请检查服务器环境并使用空数据库重试。'; }
    finally { if (is_resource($guard)) { flock($guard,LOCK_UN); fclose($guard); } }
}
?>
<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>安装sed机场管理程序</title>
<style>body{font:16px system-ui;background:#eef3fa;margin:32px auto;padding:20px;max-width:600px}form{background:white;padding:28px;border-radius:16px}label{display:block;margin:14px 0}input{box-sizing:border-box;width:100%;padding:10px;border:1px solid #bbc;border-radius:6px}button{background:#2463eb;color:white;padding:14px;border:0;border-radius:8px}p{line-height:1.7}</style>
<h1>安装sed机场管理程序</h1>
<?php if ($success): ?><p>安装完成。安装入口已自动锁定。</p><a href="/admin/">登录管理后台</a>
<?php else: ?><p>先创建空 MySQL 数据库，填写连接信息并设置管理员账号。请在自己的服务器上通过 HTTPS 安装。</p><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'],ENT_QUOTES,'UTF-8') ?>">
<?php foreach (['host'=>'数据库主机','port'=>'数据库端口','database'=>'数据库名称','user'=>'数据库用户名','password'=>'数据库密码','admin'=>'管理员账号','admin_password'=>'管理员密码（至少12位）','confirm'=>'确认管理员密码'] as $key=>$label): ?>
<label><?= $label ?><input name="<?= $key ?>" type="<?= in_array($key,['password','admin_password','confirm'],true)?'password':'text' ?>" value="<?= $key==='host'?'127.0.0.1':($key==='port'?'3306':'') ?>" required></label>
<?php endforeach; ?><button>开始安装</button></form><?php endif; ?></html>
