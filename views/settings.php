<?php
    /*
    Document        : Pengaturan Sistem
    Description     : Profil perusahaan (nama, alamat, kontak, NPWP), logo dokumen & aplikasi,
                      pelengkap dokumen cetak, dan pengaturan keuangan (mata uang dasar & mata uang
                      laporan keuangan final). Transaksi (RAB, PO, dst) boleh multi currency;
                      laporan keuangan final mengacu ke pengaturan ini.
    File related    : - settings.js
                      - settingsCRD.php (task: load_settings, save_company, save_document,
                        save_finance, upload_logo, delete_logo, logo)
                      - ../php-func/settings.func.php (dipakai modul lain untuk membaca pengaturan)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">SETTINGS</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/frm.css" />
>>>>>>> Stashed changes
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/settings.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/settings.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Pengaturan <em>Sistem</em>";
    </script>
</html>