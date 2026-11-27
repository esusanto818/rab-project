<?php
    /*
    Document        : Master Proyek Entry
    Description     : Pengelolaan Data Proyek (terhubung ke tabel projects, clients, & pics)
    File related    : - m.master_proyek.js
                      - master_proyekCRD.php
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        
        <title id="page-title">Client</title>
<script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
        <div id="divMainGrid"></div>
        <div id="editorWdw" style="display:none">
        <div id="editorFormHeaderText"></div>
        <div id="editorFormMain"></div>
        </div>
    </body>
    <script type="text/javascript" src="/rab-project/dev/js/clients.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Master Data <em>Client & List</em>";
    </script>
</html>