<?php
    /*
    Document        : Master Form Name
    Created on      : Jan 20, 2016
    Author          : Eddy Susanto
    Description     : Entry master of form name
    File related    : - m.form.name.php (CRUD)
                      - m.form.name.pdf.php (Printing)
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";*/
  include __DIR__ . "/../php-func/user.valid.php";
  include __DIR__ . "/../includes/many-template-icn.php";
?>
</html>
    <head>
      <style>
        #editorWdw {height: auto !important; min-height: 420px !important; max-height: 85vh !important; overflow-y: auto !important;}
        #editorForm {top: 50%; width: 70%; height: auto !important; min-height: 380px !important; max-height: 80vh !important; overflow-y: auto !important;}
        #editorFormMain {padding-bottom: 35px; overflow: visible !important;}
        #AddActive {
          margin-top: -32px !important;
          position: relative !important;
          margin-left: 60px !important;        
        }
        #addForm {top: 50%; width: 70%;}
        #addFormHeaderMenu, #addFormHeaderSearch, #addFormHeaderText {width: 32%;}
      </style>
      <title id="page-title">Form Group</title>
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
      <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
    </head>
    <body>
      <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
      <script type="text/javascript" src="../js/m.form.group.js"></script>
      <script type="text/javascript">
        document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
        var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
      </script>
  </body>
</html>