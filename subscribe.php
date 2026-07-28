<?php
require_once __DIR__ . '/lib.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
redirect('/sub.php' . ($query ? '?' . $query : ''));
