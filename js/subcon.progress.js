/*
Document      : subcon.progress.js
Description   : Modul Prestasi Subkontraktor (Halaman Mandiri)
Backend       : ../php-crud/subcon.progressCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Progress <em>Subkontraktor</em>";

    var CRD = main_url + '/php-crud/subcon.progressCRD.php';

    // Store utama untuk grid daftar klaim
    var progressStore = new Ext.data.JsonStore({
        url: CRD,
        baseParams: { task: 'list_inspections' },
        root: 'data',
        fields: ['id', 'inspection_no', 'inspection_date', 'status', 'progress_no', 'contract_no', 'subcon_name']
    });

    var grid = new Ext.grid.GridPanel({
        store: progressStore,
        renderTo: 'divMainGrid',
        height: 500,
        columns: [
            { header: 'No. Inspeksi', dataIndex: 'inspection_no', width: 120 },
            { header: 'Tanggal', dataIndex: 'inspection_date', width: 100 },
            { header: 'No. Progress', dataIndex: 'progress_no', width: 120 },
            { header: 'No. Kontrak', dataIndex: 'contract_no', width: 120 },
            { header: 'Subkontraktor', dataIndex: 'subcon_name', width: 200 },
            { header: 'Status', dataIndex: 'status', width: 100 }
        ],
        viewConfig: { forceFit: true },
        loadMask: true
    });

    progressStore.load();
});
