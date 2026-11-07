<?php
    /*
    Document        : Supplier Payment
    Description     : Form pembayaran Hutang Usaha ke Supplier atas Purchase Invoice (CRUD Lengkap).
    File related    : supplier.payment.js
                      supplier.paymentCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <style>
            #editorForm {top: 50%; width: 90%; height: 85%;}
            #addForm {top: 50%; width: 85%; height: 70%;}
        </style>
        <title id="page-title">Supplier Payment</title>
        <link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="../css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="../css/frm.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="../js/supplier.payment.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Supplier <em>Payment</em>";
    </script>
</html>
