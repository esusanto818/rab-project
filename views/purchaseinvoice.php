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
        
        <title id="page-title">Purchase Invoice</title>
<script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
<link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/purchaseinvoice.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Purchase <em>Invoice</em>";
    </script>
</html>