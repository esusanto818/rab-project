<?php
  /*
  Document      : Master Color
    Created on    : Feb 17, 2016
    Author        : Eddy Susanto
    Description   : Entry master of color
    File related  : - m.colorCRD.php (CRUD)
                    - m.color.pdf.php (Printing)
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";

  */
  include __DIR__ . "/../php-func/user.valid.php";
  include __DIR__ . "/../includes/many-template-icn.php";
?>
</html>
  <head>
    <link rel="stylesheet" type="text/css" href="../css/header.black.css"/>
    <style> #editorForm {top: 30%; width: 60%;} </style>
    <title id="page-title">Color</title>
  </head>
  <body>
    <script type="text/javascript" src="../js/m.color.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>
