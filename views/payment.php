<?php
    /*
    Document        : Payment Entry
    Description     : Form penerimaan pembayaran pelanggan atas proyekoice (CRUD Lengkap).
    File related    : - m.payment_entry.js
                      - paymentEntryCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        
        <title id="page-title">Payment Entry</title>
<script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/payment.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Payment <em>Entry</em>";
    </script>
</html>