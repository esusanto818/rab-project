/*
  Document      : m.trialbalance.js
  Description   : Trial Balance - Rekap saldo semua akun per periode,
                   dengan indikator total Debit = Kredit.
  Backend       : /rab-project/dev/php-crud/trialbalanceCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Trial <em>Balance</em>";
    var CRD = main_url + '/php-crud/trialbalanceCRD.php';

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // ============================================================
    // 0. Buat elemen filter bar & summary secara dinamis
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'tbFilterBar';
    filterBarDiv.style.padding = '0 10px';
    var summaryDiv = document.createElement('div');
    summaryDiv.id = 'tbSummary';
    summaryDiv.style.cssText = 'padding:8px 12px; font-size:13px; margin:0 10px 10px 10px; border-radius:4px;';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(summaryDiv, gridContainer);
    }

    // ============================================================
    // 1. Field definitions & stores
    // ============================================================
    var tbField = [
        { name: 'account_code' }, { name: 'account_name' }, { name: 'account_type' },
        { name: 'debit', type: 'float' }, { name: 'credit', type: 'float' }
    ];

    // [DEFAULT-PERIOD][NEW] Periode default = periode yang memuat tanggal hari ini; bila tidak ada -> periode terbaru (baris pertama)
    function pickDefaultPeriod(store) {
        var d = new Date(), m = d.getMonth() + 1, day = d.getDate();
        var today = d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
        var idx = store.findBy(function (r) { return r.get('start_date') <= today && today <= r.get('end_date'); });
        return store.getAt(idx >= 0 ? idx : 0).get('id');
    }

    var periodField = [{ name: 'id' }, { name: 'period_name' }, { name: 'start_date' }, { name: 'end_date' }];
    var periodDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_periods' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField),
        listeners: {
            load: function (store) {
                // [DEFAULT-PERIOD] Auto-pilih periode yang memuat hari ini (bila tidak ada: periode terbaru)
                if (store.getCount() > 0 && !fldPeriod.getValue()) {
                    fldPeriod.setValue(pickDefaultPeriod(store));
                    loadTrialBalance();
                }
            }
        }
    });
    periodDs.load();

    var tbDs = new Ext.data.Store({
        url: CRD,
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, tbField)
    });

    // ============================================================
    // 2. Filter bar
    // ============================================================
    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', width: 200, store: periodDs, displayField: 'period_name', valueField: 'id', allowBlank: false
    });
    var fldHideZero = new Ext.form.Checkbox({ boxLabel: 'Sembunyikan saldo nol', checked: true });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });

    var filterPanel = new Ext.Panel({
        renderTo: 'tbFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.25, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.25, style: 'padding-top:6px;', items: fldHideZero },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    // [FIT-GRID][NEW] Tinggi grid = sisa tinggi layar di bawah posisi grid (setelah filter bar & summary), sisakan ruang
    //                 untuk toolbar paging di bawah agar tidak terpotong. gridMain dibuat di bawah (dipakai setelah ada).
    function fitGrid() {
        if (typeof gridMain === 'undefined' || !gridMain || !gridMain.rendered) return;
        var top = gridContainer.getBoundingClientRect().top;
        var h = (window.innerHeight || document.documentElement.clientHeight) - top - 24;
        gridMain.setHeight(Math.max(h, 220));
    }
    window.onresize = fitGrid;

    function loadTrialBalance() {
        var perId = fldPeriod.getValue();
        if (!perId) return;

        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_trial_balance', period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    summaryDiv.innerHTML = res.message || 'Gagal memuat data.';
                    return;
                }

                var rows = res.data;
                if (fldHideZero.getValue()) {
                    rows = rows.filter(function (r) { return r.debit != 0 || r.credit != 0; });
                }
                var reader = tbDs.reader;
                tbDs.loadData({ data: rows, totalCount: rows.length }, false);

                var balanceColor = res.is_balance ? '#2e9e4f' : '#c0392b';
                var balanceText = res.is_balance ? 'BALANCE ✓' : 'TIDAK BALANCE ✗';
                summaryDiv.style.background = res.is_balance ? 'rgba(255, 0, 255, 0.29)' : 'rgb(255, 255, 0)';
                 summaryDiv.style.color ='yellow'; 
                summaryDiv.innerHTML =
                    '<b>Periode:</b> ' + res.period_name + ' (per ' + res.as_of_date + ')' +
                    ' &nbsp;|&nbsp; <b>Total Debit:</b> ' + Ext.util.Format.number(res.total_debit, '0,000.00') +
                    ' &nbsp;|&nbsp; <b>Total Kredit:</b> ' + Ext.util.Format.number(res.total_credit, '0,000.00') +
                    ' &nbsp;|&nbsp; <b style="color:' + balanceColor + ';">' + balanceText + '</b>';
                fitGrid();     // [FIT-GRID] summary mengubah posisi grid -> sesuaikan tinggi
            }
        });
    }

    btnShow.on('click', loadTrialBalance);
    fldHideZero.on('check', loadTrialBalance);

    // ============================================================
    // 3. Grid Trial Balance
    // ============================================================
    let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));

    var gridMain = new stdGridPaging({
        store: tbDs,
        renderTo: 'divMainGrid',
        height : mainHeight,

        columns: [
            { header: 'Kode Akun', dataIndex: 'account_code', width: 100, renderer: CellWrap },
            { header: 'Nama Akun', dataIndex: 'account_name', width: 260, renderer: CellWrap },
            { header: 'Tipe', dataIndex: 'account_type', width: 100, renderer: function (v) { return v ? v.charAt(0).toUpperCase() + v.slice(1) : ''; } },
            {
                header: 'Debit', dataIndex: 'debit', width: 140, align: 'right',
                renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
            },
            {
                header: 'Kredit', dataIndex: 'credit', width: 140, align: 'right',
                renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
            }
        ],
        pageSize: 500,
        pageStore: tbDs
    });
    fitGrid();     // [FIT-GRID] tinggi awal sesuai layar

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/trialbalance.php?period_id=' + perId, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };
       document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';

    }
});