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
<<<<<<< Updated upstream
    <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
      <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />
>>>>>>> Stashed changes
    <style>
      #editorForm {top: 50%; width: 98%; height: 98%;}
      #editorFormMain, #addFormMain {padding: 0px 0px 0px 0px;}
      #dataPicture {top: 15px; right: 25px;}
      .custom-input::before {content: 'Select Photo';}
      /* Reserve a fixed strip on the right of the whole employee form for the photo box,
         so every row (Personal Info, ID Information, etc.) automatically avoids it instead
         of needing a manual spacer column per row. */
      #editorFormMain .x-panel-body,
      #editorFormMain .x-form-item-body,
      #editorFormMain form.x-form {padding-right: 160px; box-sizing: border-box;}
    </style>
  </head>
  <body>
    <script type="text/javascript" src="/rab-project/js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/js-ext/ux/RowExpander.js"></script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/m.employee.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/m.employee.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>