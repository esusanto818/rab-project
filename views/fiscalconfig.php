<?php
    /*
    Document        : Fiscal Config
    Description     : Rule mapping akun debit/kredit untuk auto-posting jurnal
    File related    : - m.fiscalconfigCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
         <style>
            #editorForm {top: 30%; width: 80%;}
        </style>
        <title id="page-title">Fiscal Config</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />

=======
        <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />
>>>>>>> Stashed changes
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/fiscalconfig.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/fiscalconfig.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Fiscal <em>Config</em>";
    </script>
</html>