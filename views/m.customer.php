<?php
	/*
	Document        : Customer List
	Created on      : Mar 7, 2016
	Author          : Eddy Susanto
	Description     : Entry customer list
	File related    : - m.customer.php (CRUD)
					  				- m.customer.php (Printing)

	include "../php-func/user.valid.php";
  include('../includes/many-template-icn.php');*/
	  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
<html>
	<head>
    <style>
      #editorForm {top: 50%; width: 98%; height: 90%}
      #addForm {top: 40%; width: 90%;}
    </style>
		<title id="page-title">Customer List</title>
<<<<<<< Updated upstream
    <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
	</head>
	<body>
	</body>
  <script type="text/javascript" src="/rab-project/dev/js/m.customer.js"></script>
=======
	<link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
	<link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
	<link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
	<link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
	<link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />
	</head>
	<body>
	</body>
  <script type="text/javascript" src="/rab-project/live/js/m.customer.js"></script>
>>>>>>> Stashed changes
  <script type="text/javascript">
    document.getElementById("mainHeaderText").innerHTML = "Customer <em>List</em> ";
		document.getElementById('menu').innerHTML = '<?php echo $_SESSION["linkMenu"] ?? ""; ?>';
		var formAccess = '<?php echo $_SESSION["FormAccess"] ?? ""; ?>';
  </script>
</html>
