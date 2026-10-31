<?php
require_once __DIR__.'/lib.php';
try{if(!alipay_verify($_POST))throw new RuntimeException('bad sign');if(!hash_equals(get_setting('alipay_app_id'),(string)($_POST['app_id']??'')))throw new RuntimeException('bad app');if(!in_array($_POST['trade_status']??'', ['TRADE_SUCCESS','TRADE_FINISHED'],true))throw new RuntimeException('not paid');fulfill_payment_order((string)$_POST['out_trade_no'],(string)($_POST['trade_no']??''),(string)$_POST['total_amount']);echo 'success';}catch(Throwable $e){http_response_code(400);echo 'failure';}
