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
<link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
<script type="text/javascript">
window.UserCurrId = <?php echo intval($_SESSION["user_id"] ?? 0); ?>;
</script>
<title id="page-title">Change Password</title>
</head>
<body>
<script type="text/javascript" src="/rab-project/dev/js/change.pass.js"></script>
</body>
</html>