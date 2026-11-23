<?php
/**
    Document        : Master User Role
    Created on      : Nov 22, 2026
    Author          : System Agent
    Description     : Dynamic User Role & Access Matrix Management
    File related    : - roleCRD.php (CRUD Backend)
                      - m.user.role.js (ExtJS UI)
*/
include __DIR__ . "/../php-func/user.valid.php";
include __DIR__ . "/../includes/many-template-icn.php";
?>
<!DOCTYPE html>
<html>
    <head>
      <style>
        #editorForm {top: 50%; width: 95%; height: 85%;}
        #addForm {top: 50%; width: 70%; height: 75%;}
        #matrixWdw {position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 96%; height: 90%; background: #fff; z-index: 9999; display: none; border-radius: 6px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); overflow: hidden;}
      </style>
      <title id="page-title">User Role & Access Matrix</title>
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
    </head>
    <body>
      <script type="text/javascript" src="/rab-project/dev/js-ext/ux/CheckColumn.js"></script>
      <script type="text/javascript" src="/rab-project/dev/js/m.user.role.js"></script>
      <script type="text/javascript">
        document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
        var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
        var UserCurrId = '<?php echo $_SESSION["user_id"] ?>';
      </script>
  </body>
</html>
