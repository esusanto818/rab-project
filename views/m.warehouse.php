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
      
      <title id="page-title">Warehouse</title>
      <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    <script type="text/javascript" src="../js/m.warehouse.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>






