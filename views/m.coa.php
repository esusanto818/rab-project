<?php
    /*
    Document        : Chart of Accounts
    Description     : Master data akun (COA) dengan struktur hierarkis (induk-anak)
    File related    : - m.coaCRD.php (CRUD)
    */
  
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 70%;}
        </style>
        <title id="page-title">COA</title>
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />    </head>
    <body>
    </body>
    <!-- 1. DEKLARASIKAN DAHULU VARIABEL SAKRAL DI WINDOW/GLOBAL -->
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    
    <!-- 2. BARU PANGGIL SKRIP EXTERNAL JS -->
    <script type="text/javascript" src="/univ/js/m.coa.js"></script>
    
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Chart of <em>Accounts</em>";
    </script>
    </html>
