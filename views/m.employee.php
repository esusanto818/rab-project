<?php
  /*
    Document      : Master Employee
    Created on    : Oct 09, 2016
    Author        : Eddy Susanto
    Description   : Entry master of form name
    File related  : - m.employee.php (CRUD)
                    - m.employee.php (Printing)

  include "../php-func/user.valid.php";
  include('../includes/many-template-icn.php');*/
	  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
<!DOCTYPE html>
<html>
  <head>
    <title id="page-title">Employee List</title>
    <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
  <body>
    <script type="text/javascript" src="/rab-project/js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/js-ext/ux/RowExpander.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/m.employee.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>