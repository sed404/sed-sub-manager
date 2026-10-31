<?php
require_once __DIR__ . '/../lib.php';

unset($_SESSION['agent_id']);
redirect('/agent/index.php');
