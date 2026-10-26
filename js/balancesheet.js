/*
  Document      : financial_balance_sheet.js
  Description   : Balance Sheet (Neraca Keuangan) - Read-only report
  Backend       : /rab-project/dev/php-crud/balancesheetCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    var CRD = main_url + '/php-crud/balancesheetCRD.php';

    document.getElementById("mainHeaderText").innerHTML = "Balance <em>Sheet</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // 0. Container filter & report
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'bsFilterBar';
    filterBarDiv.style.padding = '0 10px';

    var reportDiv = document.createElement('div');
    reportDiv.id = 'bsReportContainer';
    reportDiv.style.cssText = 'padding:15px; background:rgba(255,255,255,0.03); color:#fff; margin:0 10px 10px 10px; border-radius:4px; border:1px solid rgba(255,255,255,0.1); font-size:13px; overflow-y:auto; overflow-x:hidden;';   // [SCROLL] kontainer report scroll sendiri bila lebih tinggi dari layar

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(reportDiv, gridContainer);
    }

    // 1. Store Periode
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
                if (store.getCount() > 0 && !fldPeriod.getValue()) {
                    fldPeriod.setValue(pickDefaultPeriod(store));     // [DEFAULT-PERIOD]
                    loadBalanceSheet();
                }
            }
        }
    });
    periodDs.load();

    // 2. Filter Bar
    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', width: 220, store: periodDs, displayField: 'period_name', valueField: 'id', allowBlank: false
    });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });

    var filterPanel = new Ext.Panel({
        renderTo: 'bsFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.35, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    // [SCROLL][NEW] Tinggi kontainer report = sisa tinggi layar di bawahnya, sehingga scrollbar muncul bila isi lebih panjang
    function fitReport() {
        var top = reportDiv.getBoundingClientRect().top;
        var h = (window.innerHeight || document.documentElement.clientHeight) - top - 20;
        reportDiv.style.maxHeight = Math.max(h, 200) + 'px';
    }
    window.onresize = fitReport;

    function loadBalanceSheet() {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }

        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_balance_sheet', period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    reportDiv.innerHTML = '<div style="color:yellow;">' + (res.message || 'Gagal memuat data.') + '</div>';
                    return;
                }
                renderBSView(res);
            }
        });
    }

    function renderBSView(res) {
        var fmt = function (val) { return Ext.util.Format.number(val || 0, '0,000.00'); };

        // [ZEBRA] warna baris selang-seling agar mudah dibaca (baris genap lebih gelap/transparan, ganjil sedikit terang)
        var zebra = function (i) {
            return (i % 2 === 0) ? 'background:rgba(255,255,255,0.00);' : 'background:rgba(15, 113, 194, 0.2);';
        };
        var cellPad = 'padding:4px 6px;';   // [ZEBRA] padding sel supaya blok warna baris terlihat lega

        var balanceColor = res.is_balanced ? '#2e9e4f' : '#c0392b';
        var balanceText  = res.is_balanced ? 'BALANCE ✓' : 'TIDAK BALANCE ✗';
        // [SELISIH][NEW] tampilkan selisih Aset - (Liabilitas + Ekuitas) bila tidak balance, untuk membantu penelusuran
        var selisih = (parseFloat(res.total_asset) || 0) - (parseFloat(res.total_liability_equity) || 0);
        if (!res.is_balanced) { balanceText += ' &nbsp;(selisih ' + fmt(selisih) + ')'; }

        var html = '<h2 style="text-align:center; margin-bottom:4px; font-weight:normal;">NERACA KEUANGAN</h2>' +
                   '<h4 style="text-align:center; color:#bbb; margin-top:0; font-weight:normal;">Per Tanggal: ' + res.as_of_date + ' (' + res.period_name + ')</h4>' +
                   '<div style="text-align:center; margin-bottom:15px;"><b style="color:' + balanceColor + '; font-size:14px;">[ STATUS: ' + balanceText + ' ]</b></div>' +
                   '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.15); margin:15px 0;" />';

        html += '<table width="100%" style="border-collapse:collapse; font-size:13px;" cellpadding="6">' +     // [ZEBRA] atribut tag dirapikan
                '<tr style="vertical-align:top;">' +

                // KOLOM KIRI: ASET
                '<td width="50%" style="border-right:1px solid rgb(138, 0, 0); padding-right:15px;">' +
                    '<h3 style="color:#98db34; margin-bottom:8px; font-size:18px;">ASET (AKTIVA)</h3>' +
                    '<table width="100%" style="border-collapse:collapse;">';
        
        Ext.each(res.assets, function (a, i) {     // [ZEBRA] index baris untuk warna selang-seling
            html += '<tr style="' + zebra(i) + ' color:#ffeb3b;">' +
                    '<td style="' + cellPad + '">' + a.account_code + '</td>' +
                    '<td style="' + cellPad + '">' + a.account_name + '</td>' +
                    '<td align="right" style="' + cellPad + '">' + fmt(a.balance) + '</td>' +
                    '</tr>';
        });

        html += '</table>' +
                '<div style="margin-top:20px; padding:10px; background:rgba(234, 0, 255, 0.05);color: #98db34; font-weight:bold; font-size:18px; display:flex; justify-content:space-between;">' +     // [FONT18]
                '<span>TOTAL ASET</span>' +
                '<span style="color:#98db34; font-size:18px;">' + fmt(res.total_asset) + '</span>' +
                '</div>' +
                '</td>' +

                // KOLOM KANAN: LIABILITAS & EKUITAS
                '<td width="50%" style="padding-left:15px;">' +
                    '<h3 style="color:#f39c12; margin-bottom:8px; font-size:18px;">LIABILITAS (KEWAJIBAN)</h3>' +
                    '<table width="100%" style="border-collapse:collapse; margin-bottom:15px;color: #f39c12;">';
        
        Ext.each(res.liabilities, function (l, i) {     // [ZEBRA]
            html += '<tr style="' + zebra(i) + '">' +
                    '<td style="' + cellPad + '">' + l.account_code + '</td>' +
                    '<td style="' + cellPad + '">' + l.account_name + '</td>' +
                    '<td align="right" style="' + cellPad + '">' + fmt(l.balance) + '</td>' +
                    '</tr>';
        });

        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.03); border-top:1px solid rgba(255,255,255,0.25);">' +     // [ZEBRA] garis atas pada baris total
                '<td colspan="2" style="' + cellPad + '">TOTAL LIABILITAS</td>' +
                '<td align="right" style="' + cellPad + '">' + fmt(res.total_liability) + '</td>' +
                '</tr></table>' +

                '<h3 style="color:#f39c12; margin-bottom:8px; font-size:18px;">EKUITAS (MODAL)</h3>' +
                '<table width="100%" style="border-collapse:collapse;color: #f39c12;">';

        Ext.each(res.equities, function (eq, i) {     // [ZEBRA]
            html += '<tr style="' + zebra(i) + '">' +
                    '<td style="' + cellPad + '">' + eq.account_code + '</td>' +
                    '<td style="' + cellPad + '">' + eq.account_name + '</td>' +
                    '<td align="right" style="' + cellPad + '">' + fmt(eq.balance) + '</td>' +
                    '</tr>';
        });

        html += '<tr style="font-weight:bold; background:rgba(255,255,255,0.03); border-top:1px solid rgba(255,255,255,0.25);">' +     // [ZEBRA]
                '<td colspan="2" style="' + cellPad + '">TOTAL EKUITAS</td>' +
                '<td align="right" style="' + cellPad + '">' + fmt(res.total_equity) + '</td>' +
                '</tr></table>' +

                '<div style="margin-top:20px; padding:10px; background:rgba(255,255,255,0.05); font-weight:bold; font-size:18px; display:flex; justify-content:space-between;color: #f39c12;">' +     // [FONT18]
                '<span>TOTAL LIABILITAS & EKUITAS</span>' +
                '<span style="color:#f39c12; font-size:18px;">' + fmt(res.total_liability_equity) + '</span>' +
                '</div>' +

                '</td>' +
                '</tr>' +
                '</table>';

        reportDiv.innerHTML = html;
        fitReport();     // [SCROLL] hitung ulang tinggi maksimum setelah isi berubah
    }

    btnShow.on('click', loadBalanceSheet);

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/balancesheet.php?period_id=' + perId, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');     // [PRINT-BS] argumen fitur window.open dijadikan satu string (sebelumnya terpisah & diabaikan)
    };

    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () {
            document.getElementById('popWdw').style.display = 'none';
        };
    }
});