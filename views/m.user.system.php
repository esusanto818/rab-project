<?php
  /*
    Document     : Master User
    Created on   : Feb 12, 2016
    Author       : Eddy Susanto
    Description  : Entry User
    File related : - m.user.system.php (CRUD)
                   - m.user.system.php (Printing)
	include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";*/
	include __DIR__ . "/../php-func/user.valid.php";
	include __DIR__ . "/../includes/many-template-icn.php";
	?>
	<!DOCTYPE html>
	<html>
	  <head>
		<style>
			#editorForm {top: 50%; width: 95%; height: 90%;}
			#addForm {top: 50%; width: 90%; height: 80%;}
		</style>
		<title id="page-title">User List</title>
<<<<<<< Updated upstream
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
      p<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
=======
      <link rel="stylesheet" type="text/css" href="/rab-project/live/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/live/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
      p<link rel="stylesheet" type="text/css" href="/rab-project/live/css/grddetail.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/live/css/frm.css" />
>>>>>>> Stashed changes
	</head>
	<body>
  <script type="text/javascript" src="../live/js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="../live/js/m.user.system.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    var UserCurrId = '<?php echo $_SESSION["user_id"] ?>';
  </script>
	</body>
</html>
