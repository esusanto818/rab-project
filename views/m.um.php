<?php
  /*
  Document      : Master unit measurement
  Created on    : Feb 17, 2016
  Author        : Eddy Susanto
  Description   : Entry master of unit measurement
  File related  : - m.umCRD.php (CRUD)
                  - m.um.pdf.php (Printing)
  */
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
</html>
  <head>
    <link rel="stylesheet" type="text/css" href="../css/header.black.css"/>
    <style> #editorForm {top: 30%; width: 60%;} </style>
    <title id="page-title">Unit Measurement</title>
  </head>
  <body>
    <script type="text/javascript" src="../js/m.um.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      let formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>






