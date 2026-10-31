<?php
require_once __DIR__ . '/lib.php';
unset($_SESSION['user_id']);
redirect('/login.php');

