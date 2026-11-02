<?php
/*
Document     : views/m.expenses.php
Description  : View Master Expense
File related : - m.expenses.js
               - m.expensesCRD.php
*/
include "../php-func/user.valid.php";
include "../includes/many-template-icn.php";
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
?>
<html>
<head>
<title id="page-title">Expense</title>
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/mainCSS.css" />
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/header.black.css" />
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/grddetail.css" />
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/frm.css" />
<style>
#editorForm { top: 30%; width: 85%; height: 30%; }
</style>
</head>
<body>
</body>
<script type="text/javascript">
window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
</script>
<script type="text/javascript" src="../js/m.expenses.js"></script>
<script type="text/javascript">
if (document.getElementById('menu')) {
    document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
}
</script>
</html>
