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
        <style>
            #editorForm {top: 30%; width: 80%; }
        </style>
        <title id="page-title">Client</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />        
>>>>>>> Stashed changes
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
    </head>
    <body>
        <div id="divMainGrid"></div>
        <div id="editorWdw" style="display:none;">
        <div id="editorFormHeaderText"></div>
        <div id="editorFormMain"></div>
        </div>
    </body>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/clients.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/clients.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Master Data <em>Client & List</em>";
    </script>
</html>