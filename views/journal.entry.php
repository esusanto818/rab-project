<?php
    /*
    Document        : Purchase Invoice
    Description     : Form entry proyekoice ASLI dari supplier, di-match ke satu/lebih GRN (CRUD Lengkap).
    File related    : - m.purchase_proyekoice.js
                      - purchase_proyekoiceCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <style>
            #editorForm {top: 50%; width: 90%; height: 75%;}
            #addForm {top: 50%; width: 85%; height: 70%;}
          


        </style>
        <title id="page-title">JOURNAL Entry</title>
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
    </head>
    <body>
    </body>
    <script type="text/javascript" src="../dev/js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/journal.entry.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Journal <em>Entry</em>";
    </script>
</html>