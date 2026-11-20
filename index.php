<?php
/*
Document : index.php
Description : Root Redirect - Mengarahkan user yang sudah login ke views/dashboard.php, dan yang belum ke login.php
*/
if (!isset($_SESSION)) { session_start(); }

if (isset($_SESSION['user_id']) && !is_null($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    header('Location: views/dashboard.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}
