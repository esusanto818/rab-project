<?php
    /*
    Document        : Stock Opname
    Description     : Hitung fisik stok per gudang, selisih, persetujuan, posting penyesuaian stok & jurnal.
    File related    : - stock.opname.js
                      - stock.opnameCRD.php
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Stock Opname</title>
        <link rel="stylesheet" type="text/css" href="/proyek/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/frm.css" />
        <style>
            #editorForm { top: 50%; width: 90%; height: 85%; }
            #addForm { top: 50%; width: 85%; height: 70%; }
        </style>
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/proyek/js/stock.opname.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Stock <em>Opname</em>";
        }
    </script>
</html>