<?php
  /*
  Document      : Master currency
    Created on    : Feb 17, 2016
    Author        : Eddy Susanto
    Description   : Entry master of currency
    File related  : - m.currencyCRD.php (CRUD)
                    - m.currency.pdf.php (Printing)
  */
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>

</html>
    <head>
<title id="page-title">Currency</title>
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <script type="text/javascript" src="../js/m.currency.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
<html>
