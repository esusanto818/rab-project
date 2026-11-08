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
      <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
      <style>#editorForm {top: 30%; width: 60%;}</style>
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
      <title id="page-title">Currency</title>
    </head>
    <script type="text/javascript" src="../js/m.currency.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
<html>
