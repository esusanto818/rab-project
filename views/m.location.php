<?php
  /*
  Document      : Master Location
  Created on    : Oct 27, 2016
  Author        : Eddy Susanto
  Description   : Entry master of location
  File related  : - m.locationCRD.php (CRUD)
                  - m.location.php (Printing)
  */
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
<html>
  <head>
    <link rel="stylesheet" type="text/css" href="../css/header.black.css"/>
    <style>
      #editorForm {top: 50%; width: 98%;}
    </style>
    <title id="page-title">Location Name</title>
  </head>
  <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="../js/m.location.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
  </script>
</html>
