<?php
  /*
  Document      : Master Department
  Created on    : Jan 22, 2016
  Author        : Eddy Susanto
  Description   : Entry master of form name
  File related  : - m.Department.php (CRUD)
                  - m.Department.php (Printing)
  */
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
</html>
    <head>
    <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
    <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />      
   <style> 
        #editorForm {top: 50%; width: 98%; height: 90%;} 
      </style>
      <title id="page-title">Department Name</title>
    </head>
    <body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="../js/m.department.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      let formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
      let UserCurrId = '<?php echo $_SESSION["user_id"] ?>';
      let myDepartment = '<?php echo $_SESSION["department_id"] ?>';
      let myLocation = '<?php echo $_SESSION["location_id"] ?>';
      let myLocationInit = '<?php echo $_SESSION["location_init"] ?>';
    </script>
  </body>

</html>
