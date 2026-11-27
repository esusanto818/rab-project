<?php
/*
Document     : views/m.expenses.php
Description  : View Master Expense
File related : - m.expenses.js
               - m.expensesCRD.php
*/
require_once __DIR__ . "/../php-func/app.url.php";
include "../php-func/user.valid.php";
include "../includes/many-template-icn.php";
$B = rtrim(BASE_URL, "/");

?>
<html>
<head>
<title id="page-title">Expense</title>
<link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
    <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
<body>
</body>
<script type="text/javascript">
window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
</script>
<script type="text/javascript"
src="<?php echo htmlspecialchars($B, ENT_QUOTES, 'UTF-8'); ?>/js/m.expenses.js"></script>
<script type="text/javascript">
if (document.getElementById('menu')) {
    document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
}
</script>
</html>
