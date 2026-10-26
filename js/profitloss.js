/*
  Document      : profitloss.js
  Description   : Profit & Loss (Laporan Rugi Laba) - Read-only report.
                  Kolom ganda: "Periode Ini" (mutasi dalam periode) dan "Kumulatif s/d Periode" (dari awal s/d akhir periode).
                  Periode default = periode yang memuat tanggal hari ini.
  Backend       : /rab-project/dev/php-crud/profitlossCRD.php
  CATATAN       : File ini disusun ulang mengikuti pola balancesheet.js dan tampilan layar Profit & Loss sebelumnya.
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    var CRD = main_url + '/php-crud/profitlossCRD.php';

    document.getElementById("mainHeaderText").innerHTML = "Profit &amp; <em>Loss</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // 0. Container filter & report
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'plFilterBar';
    filterBarDiv.style.padding = '0 10px';

    var reportDiv = document.createElement('div');
    reportDiv.id = 'plReportContainer';
    reportDiv.style.cssText = 'padding:15px; background:rgba(255,255,255,0.03); color:#fff; margin:0 10px 10px 10px; border-radius:4px; border:1px solid rgba(255,255,255,0.1); font-size:13px; overflow-y:auto; overflow-x:hidden;';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(reportDiv, gridContainer);
    }

    // Tinggi kontainer report = sisa tinggi layar (scrollbar muncul bila isi lebih panjang)
    function fitReport() {
        var top = reportDiv.getBoundingClientRect().top;
        var h = (window.innerHeight || document.documentElement.clientHeight) - top - 20;
        reportDiv.style.maxHeight = Math.max(h, 200) + 'px';
    }
    window.onresize = fitReport;

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
                    loadProfitLoss();
                }
            },
            exception: function () {
                reportDiv.innerHTML = '<div style="color:yellow;">Gagal memuat daftar periode.</div>';
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
        renderTo: 'plFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.35, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow }
        ]
    });

    function loadProfitLoss() {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }

        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_profit_loss', period_id: perId },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { reportDiv.innerHTML = '<div style="color:yellow;">Respons server tidak valid.</div>'; return; }
                if (!res.success) {
                    reportDiv.innerHTML = '<div style="color:yellow;">' + (res.message || 'Gagal memuat data.') + '</div>';
                    return;
                }
                renderPLView(res);
            },
            failure: function () {
                reportDiv.innerHTML = '<div style="color:yellow;">Gagal menghubungi server.</div>';
            }
        });
    }

    function renderPLView(res) {
        var fmt = function (val) { return Ext.util.Format.number(val || 0, '0,000.00'); };
        var enc = Ext.util.Format.htmlEncode;
        var cellPad = 'padding:3px 6px;';
        // [HEADER] gaya sel header kolom ditetapkan di SETIAP sel (CSS template menimpa font-size yang hanya di <tr>): 16px, tebal, putih
        var hdrCell = 'padding:6px 6px; font-size:16px; font-weight:bold; color:#ffffff;';
        var zebra = function (i) { return (i % 2 === 0) ? 'background:rgba(255,255,255,0.00);' : 'background:rgba(15, 113, 194, 0.2);'; };

        // satu blok seksi (pendapatan / beban) dengan dua kolom nilai
        function section(title, color, items, totalLabel, total, totalCum) {
            // [HEADER] judul seksi 18px; baris header kolom 14px, tebal, putih, berlatar agar terbaca jelas
            var h = '<h3 style="color:' + color + '; margin:18px 0 8px 0; font-size:18px;">' + title + '</h3>' +
                    '<table width="100%" style="border-collapse:collapse; color:' + color + ';">' +
                    '<tr style="color:#ffffff; font-size:14px; font-weight:bold; background:rgba(255,255,255,0.10); border-bottom:1px solid rgba(255,255,255,0.35);">' +
                    '<td style="' + hdrCell + '" width="90">Kode</td><td style="' + hdrCell + '">Nama Akun</td>' +
                    '<td align="right" style="' + hdrCell + '" width="170">Periode Ini</td>' +
                    '<td align="right" style="' + hdrCell + '" width="190">Kumulatif s/d Periode</td></tr>';
            Ext.each(items, function (a, i) {
                h += '<tr style="' + zebra(i) + '">' +
                     '<td style="' + cellPad + '">' + enc(a.account_code) + '</td>' +
                     '<td style="' + cellPad + '">' + enc(a.account_name) + '</td>' +
                     '<td align="right" style="' + cellPad + '">' + fmt(a.amount) + '</td>' +
                     '<td align="right" style="' + cellPad + '">' + fmt(a.amount_cum) + '</td></tr>';
            });
            h += '<tr style="font-weight:bold; font-size:15px; background:rgba(255,255,255,0.05); border-top:1px solid rgba(255,255,255,0.25);">' +
                 '<td colspan="2" style="padding:8px 6px;">' + totalLabel + '</td>' +
                 '<td align="right" style="padding:8px 6px;">' + fmt(total) + '</td>' +
                 '<td align="right" style="padding:8px 6px;">' + fmt(totalCum) + '</td></tr></table>';
            return h;
        }

        var netColor = function (v) { return v >= 0 ? '#4ade80' : '#f87171'; };

        var html = '<h2 style="text-align:center; margin-bottom:4px; font-weight:normal;">LAPORAN RUGI LABA</h2>' +
                   '<h4 style="text-align:center; color:#bbb; margin-top:0; font-weight:normal;">Periode: ' + enc(res.period_name) +
                   ' (' + enc(res.start_date) + ' s/d ' + enc(res.end_date) + ')</h4>' +
                   '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.15); margin:15px 0;" />';

        // [CUM-CHECK] Bila server masih memakai profitlossCRD.php lama (tanpa amount_cum), beri tahu -- jangan tampil 0.00 yang menyesatkan
        if (res.total_revenue_cum === undefined) {
            html += '<div style="padding:8px 10px; margin-bottom:8px; background:rgba(255,235,59,0.12); color:#ffeb3b; border-radius:3px;">' +
                    'Kolom kumulatif belum tersedia: file php-crud/profitlossCRD.php di server belum versi terbaru (tanpa field amount_cum).</div>';
        }

        html += section('PENDAPATAN', '#7cdc2f', res.revenues, 'TOTAL PENDAPATAN', res.total_revenue, res.total_revenue_cum);
        html += section('BEBAN / BIAYA', '#e8554a', res.expenses, 'TOTAL BEBAN', res.total_expense, res.total_expense_cum);

        html += '<table width="100%" style="border-collapse:collapse; margin-top:20px;">' +
                '<tr style="font-weight:bold; font-size:18px; color:#ffffff; border-top:2px solid rgba(255,255,255,0.3);">' +
                '<td style="padding:10px 6px; color:#ffffff;">' + (res.net_profit_loss >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH') + '</td>' +
                '<td align="right" style="padding:10px 6px; color:' + netColor(res.net_profit_loss) + ';" width="170">' + fmt(res.net_profit_loss) + '</td>' +
                '<td align="right" style="padding:10px 6px; color:' + netColor(res.net_profit_loss_cum) + ';" width="190">' + fmt(res.net_profit_loss_cum) + '</td>' +
                '</tr></table>';

        reportDiv.innerHTML = html;
        fitReport();
    }

    btnShow.on('click', loadProfitLoss);

    document.getElementById('printData').onclick = function () {
        var perId = fldPeriod.getValue();
        if (!perId) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih periode terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/profitloss.php?period_id=' + perId, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    };

    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () {
            document.getElementById('popWdw').style.display = 'none';
        };
    }
});