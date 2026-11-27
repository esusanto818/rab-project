<?php
  /*
  Document      : Purchase Order
  Created on    : Jun 21, 2016
  Author        : Eddy Susanto
  Description   : Entry Sales Forecast
  File related  : - purchase.order.php (CRUD)
                  - purchase.order.php (Printing)
  */
	require "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
<html>
  <!DOCTYPE html>
	<head>
		<title id="page-title">Puchase Order</title>
    <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
	<body>
	</body>
  <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="../js/purchase.order.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
  </script>
</html>
