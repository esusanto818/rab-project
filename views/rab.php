<?php
    /*
    Document        : RAB Material per Proyek
    Description     : Kelola baris RAB (budget qty, harga satuan,
                      toleransi) per proyek -- link ke Master Proyek
                      (public.projects) & Master Material (m_materials),
                      digabung data monitoring real-time.
    File related    : - rab.js
                      - rabCRD.php (task: list_projects, list_materials,
                        load_rab, save_rab)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">RAB Material</title>
        <<link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <style>
            #editorForm {top: 50%; width: 80%; height: auto !important; min-height: 420px !important; max-height: 80vh !important; overflow-y: auto !important;}
            #addFormHeaderText, #editorFormHeaderText{font-size: 1.3rem;text-align: center;}
        </style>
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />

    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/rab-project/dev/js/rab.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "RAB <em>Material</em>";
    </script>
</html>