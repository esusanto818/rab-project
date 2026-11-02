<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
include "../php-func/user.valid.php";
include "../includes/many-template-icn.php";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
<style>
#editorForm {
    top: 40%;
    width: 75%;
}
.x-form-item {
    margin-bottom: 8px;
}

/* Password Toggle Button */
.pwd-toggle-btn {
    position: absolute;
    right: 130px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
    z-index: 10;
}

.pwd-toggle-btn:hover {
    background: rgba(34, 211, 238, 0.1);
}

.pwd-toggle-btn .eye-icon {
    width: 18px;
    height: 18px;
    color: #64748b;
    transition: color 0.2s ease;
}

.pwd-toggle-btn:hover .eye-icon {
    color: #22d3ee;
}

.x-form-field-wrap {
    position: relative;
}

.pwd-toggle-btn.hidden .eye-icon {
    color: #ef4444;
}
</style>
<script type="text/javascript">
window.UserCurrId = <?php echo intval($_SESSION["user_id"] ?? 0); ?>;
</script>
<title id="page-title">Change Password</title>
</head>
<body>
<script type="text/javascript" src="/rab-project/dev/js/change.pass.js"></script>
</body>
</html>