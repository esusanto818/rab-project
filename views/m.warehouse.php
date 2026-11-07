<!--
	Document   		: Master warehouse
    Created on 		: Feb 17, 2016
    Author     		: Eddy Susanto
    Description		: Entry master of warehouse
    File related 	: - m.warehouseCRD.php (CRUD)
					          - m.warehouse.pdf.php (Printing)
-->
<?php
  include "../php-func/user.valid.php";
  include('../includes/many-template-icn.php');
?>
<html>
    <head>
      <style>#editorForm {top: 40%; width: 85%;}</style>
      <title id="page-title">Warehouse</title>
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/header.black.css" />
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/grddetail.css" />
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/frm.css" />
    </head>
    <body>
    <script type="text/javascript" src="../js/m.warehouse.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>






