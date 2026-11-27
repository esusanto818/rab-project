<?php
	/*
	Document        : Supplier List
	Created on      : Mar 7, 2016
	Author          : Eddy Susanto
	Description     : Entry supplier list
	File related    : - m.supplier.php (CRUD)
					  				- m.supplier.php (Printing)
	*/
	//include "../php-func/user.valid.php";
  	//include('../includes/many-template-icn.php');
	include __DIR__ . '/../php-func/user.valid.php';
  	include __DIR__ . '/../includes/many-template-icn.php';
?>
<html>
	<head>
		
		<title id="page-title">Supplier List</title>
    <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
	<body>
	</body>
  <script type="text/javascript" src="/rab-project/js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="/rab-project/dev/js/m.supplier.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    document.getElementById("mainHeaderText").innerHTML = "Supplier <em>List</em>";
  </script>
</html>
