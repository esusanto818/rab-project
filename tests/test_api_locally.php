<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Include file
require_once __DIR__ . '/../php-crud/dashboardCRD.php';
