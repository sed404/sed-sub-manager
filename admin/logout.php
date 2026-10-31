<?php
require_once __DIR__ . '/../lib.php';
unset($_SESSION['admin_id']);
redirect('/admin/index.php');

