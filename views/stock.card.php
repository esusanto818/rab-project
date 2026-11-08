<?php
    /*
    Document        : Kartu Stok (Stock Card)
    Description     : Laporan saldo & mutasi stok per item + gudang (read-only)
    File related    : - stock_cardCRD.php (report handler)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Kartu Stok</title>
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/rab-project/dev/js/stock.card.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Kartu <em>Stok</em>";
        if (document.getElementById('editorWdw')) {
            document.getElementById('editorWdw').style.display = 'none';
        }
    </script>
</html>