<?php
    /*
    Document        : Terima Barang (W/H Received)
    Description     : Konfirmasi fisik penerimaan barang oleh staf gudang -- tahap ke-2 alur GRN.
    File related    : - wh_received.js
                      - grnCRD.php (task: read, read_edit, confirm_receipt)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
         
        <title id="page-title">W/H RCV</title>
        <link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/rab-project/dev/js/whrcv.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Terima <em>Barang(WMS)</em>";
    </script>
</html>