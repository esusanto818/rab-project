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
        <style>
            #editorForm {top: 50%; width: 90%; height: 75%;}
            #addForm {top: 50%; width: 85%; height: 70%;}
        </style>
        <title id="page-title">Payment Entry</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
>>>>>>> Stashed changes
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
    </head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/payment.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/payment.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Payment <em>Entry</em>";
    </script>
</html>