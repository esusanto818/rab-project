<?php
    /*
    Document        : Stock Transfer (Pindah Stok Antar Gudang/Bin)
    Description     : Langsung posting saat disimpan, catat sepasang stock
                       movement (OUT asal, IN tujuan) per item.
    File related    : - stock_transfer.js
                      - stock_transferCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        
        <title id="page-title">Stock Transfer</title>
<script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/stock.transfer.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Stock <em>Transfer</em>";
    </script>
</html>
