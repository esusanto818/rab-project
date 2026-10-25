/*
  Document      : warehouse.issuing.js
  Description   : Warehouse Issuing (material out ke proyek). ExtJS 3.4.1
                  Pola template (sama dengan po.js / purchase.return.js):
                  grid utama = daftar issue (#divMainGrid); klik baris -> panel detail di bawah grid;
                  Add membuka editor (#editorWdw: header form + grid item).
  Aturan        : material harus sudah RCV di W/H; qty <= stok W/H dan
                  total issue proyek <= RAB +5% (kuota dihitung server, 'max_issue').
                  Gudang = semua gudang yang MASIH ADA material (gudang utama dipakai bersama).
                  Issue jenis SUPPLIER_RETURN dibuat dari modul Retur Pembelian (Perintahkan W/H) dan hanya bisa
                  dibatalkan lewat Batal Kirim / Batalkan Perintah di modul itu.
                  [NEW 2026-10-11] Alur gudang untuk issue retur: pending -> Siap Kirim (prepared) -> Konfirmasi Dikirim (posted).
                  Stok keluar + jurnal baru dibuat saat Konfirmasi Dikirim (gudang yang menyatakan barang benar-benar keluar).
<<<<<<< Updated upstream
  Backend       : /proyek/php-crud/warehouse.issuingCRD.php
=======
  Backend       : /rab-project/live/php-crud/warehouse.issuingCRD.php
>>>>>>> Stashed changes
  PERUBAHAN     : [REBUILD] disusun ulang mengikuti pola template (versi standalone Ext.Window sebelumnya salah)
                  [FIX-DATE] tanggal dikirim lewat ymd() (Date.format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini)
                  [CHANGED] gudang = gudang yang masih ada material (bukan lagi per-proyek)
                  [NEW 2026-10-11 #2] No. Surat Jalan otomatis dari sistem (dialog Konfirmasi Dikirim tidak lagi punya isian SJ);
                  kolom Pengiriman di grid utama menampilkan tgl kirim, no. SJ, kurir -> penerima.
                  [NEW 2026-10-12] Cetak Surat Jalan: setelah Konfirmasi Dikirim sukses, sistem menawarkan cetak -> hasil cetak berwatermark ORIGINAL.
                  Setelah status posted, tombol 'Cetak Ulang (COPY)' di panel detail -> hasil cetak berwatermark COPY.
                  Tidak ada lagi tombol cetak saat status prepared (surat jalan dicetak setelah barang dikonfirmasi dikirim).
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    // [NEW] gaya dialog konfirmasi pengiriman agar terbaca di tema gelap
    (function () {
        if (document.getElementById('whDarkWinCss')) { return; }
        var st = document.createElement('style');
        st.id = 'whDarkWinCss';
        st.innerHTML = '.whDarkWin .x-window-body, .whDarkWin .x-panel-body { background:#0d2233 !important; color:#e6f1f5 !important; }' +
                       '.whDarkWin .x-form-item label { color:#9fc3d1 !important; }' +
                       '.whDarkWin .x-form-field { background:#14324a !important; color:#ffffff !important; border-color:#2b5d7a !important; }' +
                       '.whDarkWin .x-form-display-field{color:#e2e8f0 !important;}' +
                       '.whDarkWin .x-btn button{color:#ffffff !important;font-weight:bold !important;opacity:1 !important;}' +
                       '.whDarkWin .x-btn-tl,.whDarkWin .x-btn-tr,.whDarkWin .x-btn-tc,.whDarkWin .x-btn-ml,.whDarkWin .x-btn-mr,' +
                       '.whDarkWin .x-btn-bl,.whDarkWin .x-btn-br,.whDarkWin .x-btn-bc{background:none !important;background-image:none !important;}' +
                       '.whDarkWin .x-btn-mc{background-color:#2e7dd1 !important;background-image:none !important;border-radius:4px;}' +
                       '.whDarkWin .x-btn-over .x-btn-mc{background-color:#4695e6 !important;}' +
                       '.whDarkWin .x-btn-mc button{padding:7px 18px !important;height:auto !important;line-height:18px !important;font-size:13px !important;}';      // [CHANGED] tombol lebih tebal
        document.getElementsByTagName('head')[0].appendChild(st);
    })();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Warehouse <em>Issuing</em>";
    }

    var CRD =  main_url + '/php-crud/warehouse.issuingCRD.php';     // relatif dari /proyek/views/

    var ACCESS_ADD = 2;      // level akses minimal: buat issue
    var ACCESS_EDIT = 3;     // level akses minimal: reverse issue
    var EPS = 0.00005;       // toleransi qty

    var dataRecord = null;   // baris issue terpilih di grid utama
    var hdrForm = null, matBar = null;
    var fProject, fWh, fDate, fMandor, fRemark, fMat;
    var fSpk;                // [SPK][NEW] pilihan SPK (wajib untuk issue ke proyek)

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    // [FIX-DATE] format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini -> susun YYYY-MM-DD manual
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }

    // Exception store: tampilkan pesan backend / HTTP
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else {
            try { var o = Ext.decode(res.responseText); if (o && o.message) { m = o.message; } }
            catch (e) { if (res && res.status) { m += ' (HTTP ' + res.status + ')'; } }
        }
        showErr(m);
    }

    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respon server tidak valid (cek error_log PHP).'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }

    // [CHANGED] tambah status alur retur: pending (kuning), prepared (biru)
    function statusColor(v) {
        if (v === 'posted') { return '#2e9e4f !important'; }
        if (v === 'pending') { return '#e6a817 !important'; }
        if (v === 'prepared') { return '#2bc1e2 !important'; }
        return '#c0392b !important';
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama) -- pola sama dengan po.js
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'whDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.max(DtlHeight, 220) + 'px';      // wajib pakai 'px'
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetailPanel(rec) {
        ajax({ action: 'detail', id: rec.id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(rec, res.data || []);
        });
    }

    function proofLinks(v) {
        if (!v) { return ''; }
        var out = [], parts = String(v).split(/\r?\n/);
        for (var i = 0; i < parts.length; i++) {
            var u = parts[i].replace(/^\s+|\s+$/g, '');
            if (!u) { continue; }
            if (!/^(https?:\/\/|\/)/i.test(u)) { out.push(enc(u)); continue; }
            var label = /\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(u) ? 'Foto' : (/\.pdf(\?.*)?$/i.test(u) ? 'PDF' : 'Link');
            out.push('<a href="' + enc(u) + '" target="_blank" style="color:#7fe0a0;">' + label + ' ' + (out.length + 1) + '</a>');
        }
        return out.join(' &nbsp;|&nbsp; ');
    }

    function box(bg, border, fg, inner) {
        return '<div style="margin:0 2px 8px 2px; padding:8px 12px; background:' + bg + '; border-left:4px solid ' + border + '; color:' + fg + ';">' + inner + '</div>';
    }
    function returnInfoBanner(h) {
        var ref = h.return_ref_no ? ' &nbsp;|&nbsp; No. Retur: <b>' + enc(h.return_ref_no) + '</b> (' + enc(h.return_status || '-') + ')' : '';
        var sup = h.return_supplier_name ? ' &nbsp;|&nbsp; Ke supplier: <b>' + enc(h.return_supplier_name) + '</b>' : '';
        if (h.status === 'pending') {
            return box('#4a3a0b', '#e6a817', '#ffe9a8',
                '<b>Menunggu gudang menyiapkan barang</b>' + sup + ref +
                '<br>Stok belum keluar. Setelah barang siap, klik <b>Siap Kirim</b>; setelah benar-benar dikirim, klik <b>Konfirmasi Dikirim</b>.');
        }
        if (h.status === 'prepared') {
            return box('#0b3550', '#2bc1e2', '#cdeefa',
                '<b>Barang sudah disiapkan, menunggu pengiriman</b> &nbsp;|&nbsp; Disiapkan: <b>' + enc(h.prepared_at || '-') + '</b> &nbsp;|&nbsp; No. Surat Jalan: <b>' + enc(h.delivery_note_no || '-') + '</b>' + sup + ref +
                '<br>Stok belum keluar. Klik <b>Konfirmasi Dikirim</b> setelah barang meninggalkan gudang.');
        }
        if (h.status === 'posted') {
            return box('#0f3d2a', '#2e9e4f', '#c9f2d9',
                '<b style="color:#4be08a;">&#10004; Barang sudah dikirim dari W/H</b> &nbsp;|&nbsp; Tgl kirim: <b>' + enc(h.delivery_date || h.sent_date || h.issue_date) + '</b>' +
                ' &nbsp;|&nbsp; Kurir / Kendaraan: <b>' + enc(h.received_by_mandor || h.carrier || '-') + '</b>' +
                ' &nbsp;|&nbsp; Penerima: <b>' + enc(h.delivery_receiver || '-') + '</b>' +
                ' &nbsp;|&nbsp; No. Surat Jalan: <b>' + enc(h.delivery_note_no || '-') + '</b>' + sup + ref +
                (h.delivery_proof ? '<br>Bukti pengiriman: ' + proofLinks(h.delivery_proof) : '') +
                '<br><span style="color:#9fc3d1;">Pembatalan lewat Batal Kirim di modul Retur Pembelian.</span>');
        }
        return box('#4a3a0b', '#e6a817', '#ffe9a8', '<b>' + (h.status === 'cancelled' ? 'Perintah dibatalkan' : 'Pengiriman dibatalkan') +
            '</b> &mdash; ' + (h.status === 'cancelled' ? 'perintah issue dibatalkan oleh Pembelian.' : 'issue di-reverse dan stok sudah kembali ke gudang.'));
    }

    var COMPANY_NAME = '';
    var COMPANY_ADDR = '';
    function parseYmd(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(str || '');
        return m ? new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10)) : null;
    }
    function fmtDmy(str) {
        var d = parseYmd(str);
        if (!d) { return enc(str || ''); }
        var m = d.getMonth() + 1, dd = d.getDate();
        return (dd < 10 ? '0' + dd : dd) + '/' + (m < 10 ? '0' + m : m) + '/' + d.getFullYear();
    }
    function qtyPrint(v) {
        var n = Math.round((parseFloat(v) || 0) * 10000) / 10000;
        return Ext.util.Format.number(n, '0,000.0000').replace(/\.?0+$/, '');
    }
    function printSuratJalan(h, items, preW, mark) {
        mark = mark || 'COPY';
        var w = preW || window.open('', '_blank', 'width=900,height=700');
        if (!w) { showErr('Popup diblokir browser. Izinkan popup untuk situs ini lalu coba lagi.'); return; }
        var delivered = (h.status === 'posted');
        var BLANK = '..............................';
        var agg = [], idx = {};
        Ext.each(items, function (i) {
            var key = i.material_id + '|' + (i.notes || '');
            if (idx[key] === undefined) { idx[key] = agg.length; agg.push({ code: i.material_code, name: i.material_name, unit: i.unit, qty: 0, note: i.notes || '' }); }
            agg[idx[key]].qty += parseFloat(i.qty_out) || 0;
        });
        var rows = '', no = 0;
        Ext.each(agg, function (a) {
            no++;
            rows += '<tr><td class="c">' + no + '</td><td>' + enc(a.code || '') + '</td><td>' + enc(a.name) + '</td>' +
                    '<td class="r">' + qtyPrint(a.qty) + '</td><td class="c">' + enc(a.unit || '') + '</td><td>' + enc(a.note) + '</td></tr>';
        });
        var css = '@page{size:A4;margin:12mm;}body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0;}' +
                  'h1{font-size:18px;margin:0 0 2px 0;text-align:center;letter-spacing:1px;}.sub{text-align:center;margin-bottom:10px;font-size:11px;}' +
                  'table{border-collapse:collapse;width:100%;}.meta td{padding:2px 4px;vertical-align:top;}' +
                  '.items th,.items td{border:1px solid #000;padding:5px 6px;}.items th{background:#eee;}' +
                  '.c{text-align:center;}.r{text-align:right;}.sign td{width:33%;text-align:center;padding-top:6px;}' +
                  '.sign .box{height:70px;}hr{border:0;border-top:2px solid #000;margin:8px 0;}' +
                  '.wm{position:fixed;top:38%;left:0;width:100%;text-align:center;font-size:120px;font-weight:bold;letter-spacing:10px;' +
                  'color:rgba(0,0,0,0.10);transform:rotate(-30deg);z-index:0;pointer-events:none;}' +
                  'body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
        var html = '<html><head><meta charset="utf-8"><title>Surat Jalan ' + enc(h.delivery_note_no || h.ref_no) + ' - ' + enc(mark) + '</title><style>' + css + '</style></head><body>' +
                   '<div class="wm">' + enc(mark) + '</div>' +
                   (COMPANY_NAME ? '<div style="font-size:15px;font-weight:bold;text-align:center;">' + enc(COMPANY_NAME) + '</div>' : '') +
                   (COMPANY_ADDR ? '<div class="sub">' + enc(COMPANY_ADDR) + '</div>' : '') +
                   '<hr/><h1>SURAT JALAN RETUR PEMBELIAN</h1><div class="sub">Pengembalian barang cacat / rusak ke supplier</div>' +
                   '<table class="meta"><tr>' +
                   '<td width="16%">No. Surat Jalan</td><td width="34%">: <b>' + enc(h.delivery_note_no || h.ref_no) + '</b></td>' +
                   '<td width="16%">Tanggal Kirim</td><td width="34%">: ' + (delivered ? fmtDmy(h.delivery_date || h.sent_date || h.issue_date) : '....../....../............') + '</td></tr><tr>' +
                   '<td>Kepada (Supplier)</td><td>: <b>' + enc(h.return_supplier_name || '-') + '</b></td>' +
                   '<td>No. Retur</td><td>: ' + enc(h.return_ref_no || '-') + '</td></tr><tr>' +
                   '<td>Proyek</td><td>: ' + enc(h.project_name) + '</td>' +
                   '<td>Gudang Asal</td><td>: ' + enc(h.warehouse_name) + '</td></tr><tr>' +
                   '<td>Kurir / Kendaraan</td><td>: ' + (delivered ? enc(h.received_by_mandor || h.carrier || '-') : BLANK) + '</td>' +
                   '<td>Penerima</td><td>: ' + (delivered ? enc(h.delivery_receiver || '-') : BLANK) + '</td></tr>' +
                   '</table>' +
                   '<br/><table class="items"><thead><tr><th width="4%">No</th><th width="14%">Kode</th><th>Material</th><th width="12%">Qty</th>' +
                   '<th width="8%">Sat</th><th width="28%">Keterangan</th></tr></thead><tbody>' + rows +
                   '</tbody></table><div style="margin-top:6px;">Total item: <b>' + agg.length + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div><br/>' +
                   '<table class="sign"><tr><td>Dibuat oleh<br/>(Gudang)</td><td>Pengirim / Kurir</td><td>Diterima oleh<br/>(Supplier)</td></tr>' +
                   '<tr><td class="box"></td><td class="box"></td><td class="box"></td></tr>' +
                   '<tr><td>(................................)</td><td>(................................)</td><td>(................................)</td></tr></table>' +
                   '<div style="margin-top:14px;font-size:10px;color:#444;">Dicetak: ' + fmtDmy(ymd(new Date())) + '</div></body></html>';
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); }, 300);
    }

    function renderDetailPanel(h, items) {
        var grand = 0;
        var isReturn = (h.issue_type === 'SUPPLIER_RETURN');
        var actHtml = '';
        if (h.status === 'posted' && !isReturn) {
            actHtml = '<div style="float:right; margin:2px 4px 0 0;">' +
                      '<button type="button" id="whReverseBtn" style="padding:8px 16px; background:#8e2b22; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Reverse Issue</button></div>';
        }
        if (isReturn && (h.status === 'pending' || h.status === 'prepared' || h.status === 'posted')) {
            var bs = 'padding:8px 16px; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold; margin-left:6px;';
            var rb2 = '';
            if (h.status === 'posted') {
                rb2 += '<button type="button" id="whPrintBtn" style="' + bs + ' background:#2e6da4;">Cetak Ulang Surat Jalan (COPY)</button>';
            }
            if (h.status === 'pending' || h.status === 'prepared') {
                rb2 += '<button type="button" id="whRetBtn" style="' + bs + ' background:' + (h.status === 'pending' ? '#2e6da4' : '#03615e') + ';">' +
                       (h.status === 'pending' ? 'Siap Kirim (Barang Sudah Disiapkan)' : 'Konfirmasi Dikirim') + '</button>';
            }
            actHtml = '<div style="float:right; margin:2px 4px 0 0;">' + rb2 + '</div>';
        }
        var html = actHtml + '<h3 class="detail-title">Detail Issue: ' + enc(h.ref_no) +
                   ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.issue_date) + '</b> &nbsp;|&nbsp; Jenis: <b>' +
                   (isReturn ? 'Retur Supplier' : 'Proyek') + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Gudang: <b>' + enc(h.warehouse_name) + '</b> &nbsp;|&nbsp; Mandor / Penerima: <b>' + enc(h.received_by_mandor || '-') + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   (isReturn ? returnInfoBanner(h) : '') +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty</th><th align="right">Biaya Satuan (FIFO)</th>' +
                   '<th align="right">Nilai</th><th align="left">Catatan</th></tr></thead><tbody>';
        if (!items.length) {
            html += '<tr><td colspan="5" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>';
        }
        Ext.each(items, function (i) {
            var amt = parseFloat(i.amount) || 0;
            grand += amt;
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.material_name) + ' <span style="color:#668894;">(' + enc(i.material_code || '') + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty_out) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right">' + fmtAmt(i.unit_cost) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(amt) + '</td>' +
                    '<td align="left">' + enc(i.overbudget_reason || i.notes || '') + '</td></tr>';
        });
        html += '<tr class="data-row"><td colspan="3" align="right"><b>Total</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grand) + '</b></td><td></td></tr>';
        html += '</tbody></table>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var rb = document.getElementById('whReverseBtn');
        if (rb) { rb.onclick = function () { reverseDoc(h); }; }
        var pb = document.getElementById('whPrintBtn');
        if (pb) { pb.onclick = function () { printSuratJalan(h, items, null, 'COPY'); }; }
        var rtb = document.getElementById('whRetBtn');
        if (rtb) { rtb.onclick = function () { if (h.status === 'pending') { prepareDoc(h); } else { openDeliver(h); } }; }
    }

    // [NEW] Helper pesan dialog kustom agar selaras dengan tema gelap (menggantikan Ext.Msg.alert standar)
    function showWhAlert(title, msg, onOk) {
        var win = new Ext.Window({
            title: enc(title),
            width: 480,
            height: 180,
            modal: true,
            layout: 'fit',
            cls: 'whDarkWin',
            minButtonWidth: 120,
            items: new Ext.form.FormPanel({
                border: false,
                bodyStyle: 'padding:15px; background:transparent;',
                items: [{
                    xtype: 'displayfield',
                    hideLabel: true,
                    value: '<span style="font-size:13px; color:#e2e8f0;">' + msg + '</span>'
                }]
            }),
            buttons: [
                {
                    text: 'OK',
                    handler: function () {
                        win.close();
                        if (onOk) { onOk(); }
                    }
                }
            ]
        });
        win.show();
    }

    // [NEW] hasil aksi gudang menggunakan dialog jendela kustom
    function afterWhAction(res) {
        var msg = res.message || '';
        var title = res.success ? 'Sukses' : 'Gagal';
        
        showWhAlert(title, msg, function() {
            if (!res.success) { return; }
            var id = dataRecord ? dataRecord.id : 0;
            dtlDiv.style.display = 'none';
            dataRecord = null;
            updateMainButtons();
            mainDs.load({ callback: function () {
                var idx = mainDs.find('id', id);
                if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; updateMainButtons(); loadDetailPanel(dataRecord); }
            } });
        });
    }

    function afterDeliver(res) {
        var id = dataRecord ? dataRecord.id : 0;
        afterWhAction(res);
        if (!res.success || !id) { return; }
        Ext.Msg.confirm('Cetak Surat Jalan', 'Pengiriman tersimpan' + (res.data && res.data.delivery_note_no ? ' (No. SJ: <b>' + enc(res.data.delivery_note_no) + '</b>)' : '') +
                        '.<br>Cetak surat jalan sekarang?', function (b) {
            if (b !== 'yes') { return; }
            var w = window.open('', '_blank', 'width=900,height=700');
            if (!w) { showErr('Popup diblokir browser. Izinkan popup, lalu klik Cetak Surat Jalan di panel detail.'); return; }
            var idx = mainDs.find('id', id);
            if (idx < 0) { w.close(); showErr('Dokumen tidak ditemukan di daftar. Klik barisnya lalu pilih Cetak Surat Jalan.'); return; }
            var rec = mainDs.getAt(idx).data;
            ajax({ action: 'detail', id: id }, function (r2) {
                if (!r2.success) { w.close(); showErr(r2.message || 'Gagal memuat detail.'); return; }
                printSuratJalan(rec, r2.data || [], w, 'ORIGINAL');
            });
        });
    }

    // [NEW] Siap Kirim menggunakan Ext.Window kustom (menggantikan Gambar 1)
    function prepareDoc(rec) {
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk memproses issue retur.')) { return; }
        
        var win = new Ext.Window({
            title: 'Siap Kirim - ' + enc(rec.ref_no),
            width: 480,
            height: 180,
            modal: true,
            layout: 'fit',
            cls: 'whDarkWin',
            minButtonWidth: 120,
            items: new Ext.form.FormPanel({
                border: false,
                bodyStyle: 'padding:15px; background:transparent;',
                items: [{
                    xtype: 'displayfield',
                    hideLabel: true,
                    value: '<span style="color:#e6a817; font-size:13px;">Tandai barang pada <b>' + enc(rec.ref_no) + '</b> sudah disiapkan (Siap Kirim)?</span>'
                }]
            }),
            buttons: [
                {
                    text: 'Ya, Siap Kirim',
                    handler: function () {
                        win.close();
                        ajax({ action: 'prepare', id: rec.id }, afterWhAction);
                    }
                },
                {
                    text: 'Batal',
                    handler: function () { win.close(); }
                }
            ]
        });
        win.show();
    }

    function openDeliver(rec) {
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk memproses issue retur.')) { return; }
        var wDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Kirim', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var wCarrier = new Ext.form.TextField({ fieldLabel: 'Kurir / Kendaraan', anchor: '95%', allowBlank: false });
        var wReceiver = new Ext.form.TextField({ fieldLabel: 'Nama Penerima', anchor: '95%', allowBlank: false });
        var wNote = new Ext.form.DisplayField({ fieldLabel: 'No. Surat Jalan', anchor: '95%',
            value: rec.delivery_note_no ? '<b>' + enc(rec.delivery_note_no) + '</b>' : '<span style="color:#9fc3d1;">Dibuat otomatis oleh sistem</span>' });
        var proofs = [], uploading = 0;
        var wProofList = new Ext.form.DisplayField({ fieldLabel: 'Bukti', anchor: '95%', value: '' });
        function renderProofs() {
            var h = '';
            for (var i = 0; i < proofs.length; i++) {
                h += '<div>' + (i + 1) + '. ' + proofLinks(proofs[i]) + ' &nbsp;<span style="color:#e05353;cursor:pointer;font-weight:bold;" title="Hapus" onclick="window.whProofRemove(' + i + ');">&#10005;</span></div>';
            }
            if (uploading > 0) { h += '<div style="color:#9fc3d1;">Mengunggah ' + uploading + ' file...</div>'; }
            wProofList.setValue(h || '<span style="color:#668894;">Belum ada file (opsional)</span>');
        }
        window.whProofRemove = function (i) { proofs.splice(i, 1); renderProofs(); };
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,.pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
        fileInput.onchange = function () {
            var f = fileInput.files && fileInput.files[0];
            if (!f) { return; }
            if (proofs.length + uploading >= 5) { showErr('Bukti maksimal 5 file.'); fileInput.value = ''; return; }
            if (f.size > 5 * 1024 * 1024) { showErr('Ukuran file maksimal 5 MB.'); fileInput.value = ''; return; }
            var fd = new FormData();
            fd.append('action', 'upload_proof');
            fd.append('file', f);
            fileInput.value = '';
            uploading++; renderProofs();
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CRD);
            xhr.onload = function () {
                uploading--;
                var o;
                try { o = Ext.decode(xhr.responseText); } catch (e) { renderProofs(); showErr('Respon server tidak valid (cek error_log PHP).'); return; }
                if (!o.success) { renderProofs(); showErr(o.message || 'Unggah gagal.'); return; }
                proofs.push(o.url); renderProofs();
            };
            xhr.onerror = function () { uploading--; renderProofs(); showErr('Koneksi ke server gagal saat mengunggah.'); };
            xhr.send(fd);
        };
        var wProofBtn = new Ext.Button({ text: '+ Unggah Foto / PDF Bukti...', minWidth: 220, handler: function () { fileInput.click(); } });
        renderProofs();
        var win = new Ext.Window({
            title: 'Konfirmasi Dikirim ' + rec.ref_no, width: 500, height: 400, modal: true, layout: 'fit', cls: 'whDarkWin', minButtonWidth: 150,
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 120, bodyStyle: 'padding:12px; background:transparent;',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: '<span style="color:#e6a817;">Setelah dikonfirmasi, stok keluar dari gudang (FIFO) dan jurnal dibuat. Pastikan barang benar-benar sudah dikirim.</span>' },
                        wDate, wCarrier, wReceiver, wNote, wProofList, wProofBtn]
            }),
            buttons: [
                { text: 'Konfirmasi Dikirim', handler: function () {
                    if (!wDate.getValue()) { showErr('Tanggal kirim wajib diisi.'); return; }
                    if (!wCarrier.getValue()) { showErr('Kurir / kendaraan wajib diisi.'); return; }
                    if (!wReceiver.getValue()) { showErr('Nama penerima wajib diisi.'); return; }
                    if (uploading > 0) { showErr('Masih ada file yang sedang diunggah, tunggu sampai selesai.'); return; }
                    var params = {
                        action: 'deliver', id: rec.id, delivery_date: ymd(wDate.getValue()),
                        carrier: wCarrier.getValue(), receiver: wReceiver.getValue(),
                        delivery_proof: proofs.join('\n')
                    };
                    win.close();
                    ajax(params, afterDeliver);
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ],
            listeners: { close: function () {
                if (fileInput.parentNode) { fileInput.parentNode.removeChild(fileInput); }
                window.whProofRemove = null;
            } }
        });
        win.show();
    }

    function reverseDoc(rec) {
        if (!rec) { showErr('Pilih dokumen terlebih dahulu.'); return; }
        if (rec.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
        if (rec.issue_type === 'SUPPLIER_RETURN') { showErr('Ini issue Retur Supplier. Gunakan Batal Kirim di modul Retur Pembelian.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk me-reverse issue.')) return;
        Ext.Msg.confirm('Reverse Issuing', 'Reverse dokumen <b>' + enc(rec.ref_no) + '</b>? Stok akan dikembalikan.', function (b) {
            if (b !== 'yes') return;
            ajax({ action: 'reverse', id: rec.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                    mainDs.load();
                    updateMainButtons();
                }
            });
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list', start: 0, limit: 250 },
        root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name', 'issue_date',
                 'received_by_mandor', 'status', 'remark', 'issue_type', 'spk_no',     // [SPK] + spk_no
                 'return_ref_no', 'return_status', 'sent_date', 'carrier', 'return_supplier_name',
                 'prepared_at', 'delivery_date', 'delivery_note_no', 'delivery_receiver', 'delivery_proof',
                 { name: 'total_amount', type: 'float' }, { name: 'line_count', type: 'int' }, 'has_overbudget'],
        listeners: { exception: storeError }
    });
    mainDs.load();

    var projectDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    // [SPK][NEW] SPK berstatus Diterbitkan milik proyek terpilih
    var spkDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_spk' }, root: 'data', fields: ['id', 'spk_no', 'foreman', 'label'],
        listeners: { exception: storeError }
    });
    var warehouseDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'code', 'name'],
        listeners: { exception: storeError }
    });
    var materialDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit',
                 { name: 'onhand', type: 'float' }, { name: 'rab_qty', type: 'float' },
                 { name: 'issued', type: 'float' }, { name: 'limit_qty', type: 'float' }, { name: 'max_issue', type: 'float' },
                 { name: 'spk_remaining', type: 'float' }],      // [SPK] sisa jatah SPK
        listeners: { exception: storeError }
    });
    var itemField = ['material_id', 'material_code', 'material_name', 'unit',
                     { name: 'qty_out', type: 'float' }, { name: 'onhand', type: 'float' }, { name: 'max_issue', type: 'float' },
                     { name: 'rab_qty', type: 'float' }, { name: 'issued', type: 'float' }];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Issue)
    // ------------------------------------------------------------
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. Issue', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'issue_date', width: 85, align: 'center' },
            { header: 'Jenis', dataIndex: 'issue_type', width: 95, align: 'center',
              renderer: function (v) { return v === 'SUPPLIER_RETURN' ? '<span style="color:#2bc1e2;font-weight:bold;">Retur Supplier</span>' : 'Proyek'; } },
            { header: 'Proyek', dataIndex: 'project_name', width: 200, renderer: CellWrap },
            { header: 'SPK', dataIndex: 'spk_no', width: 115, align: 'center', renderer: function (v) { return enc(v || '-'); } },     // [SPK][NEW]
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 140, renderer: CellWrap },
            { header: 'Mandor Penerima', dataIndex: 'received_by_mandor', width: 130, renderer: function (v) { return enc(v || '-'); } },
            { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Allowance', dataIndex: 'has_overbudget', width: 80, align: 'center',
              renderer: function (v) { return (v === true || v === 'true') ? '<span style="color:#e6a817;font-weight:bold;">+5%</span>' : '-'; } },
            { header: 'Pengiriman', dataIndex: 'return_ref_no', width: 200, align: 'center',
              renderer: function (v, meta, rec) {
                  if (rec.get('issue_type') !== 'SUPPLIER_RETURN') { return '-'; }
                  var st = rec.get('status'), sj = rec.get('delivery_note_no');
                  var sub = v ? '<br><span style="color:#9fc3d1;font-size:11px;">' + enc(v) + '</span>' : '';
                  var sjLine = sj ? '<br><span style="color:#e2e8f0;font-size:11px;">SJ: <b>' + enc(sj) + '</b></span>' : '';
                  if (st === 'pending') { return '<span style="color:#e6a817;font-weight:bold;">Menunggu disiapkan</span>' + sub; }
                  if (st === 'prepared') { return '<span style="color:#2bc1e2;font-weight:bold;">Siap kirim</span>' + sjLine + sub; }
                  if (st === 'posted') {
                      return '<span style="color:#2e9e4f;font-weight:bold;">Terkirim ' + enc(rec.get('delivery_date') || rec.get('sent_date') || rec.get('issue_date') || '') + '</span>' +
                             sjLine + '<br><span style="color:#9fc3d1;font-size:11px;">' + enc(rec.get('received_by_mandor') || rec.get('carrier') || '-') +
                             ' &rarr; ' + enc(rec.get('delivery_receiver') || '-') + '</span>' + sub;
                  }
                  return '<span style="color:#e6a817;">Dibatalkan</span>';
              } },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } }
        ],
        stripeRows : true,
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord);
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        mainBtnDisable('#editData');
        if (dataRecord && dataRecord.status === 'posted' && dataRecord.issue_type !== 'SUPPLIER_RETURN') {
            mainBtnEnable('#deleteData');
        } else {
            mainBtnDisable('#deleteData');
        }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        enableHdMenu: false,
        enableColumnMove: false,
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'material_code', width: 90 },
            { sortable: false, menuDisabled: true, header: 'Material', dataIndex: 'material_name', width: 200 },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Stok W/H', dataIndex: 'onhand', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Sudah Issue', dataIndex: 'issued', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Maks Issue', dataIndex: 'max_issue', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty Issue', dataIndex: 'qty_out', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }),
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { id: 'whDeleteCol', sortable: false, menuDisabled: true, header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
              renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>'; } }
        ],
        listeners: {
            validateedit: function (e) {
                if (e.field === 'qty_out' && parseFloat(e.value) > parseFloat(e.record.get('max_issue')) + EPS) {
                    showErr('Qty melebihi batas issue (' + fmtQty(e.record.get('max_issue')) + ').');
                    e.cancel = true;
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'whDeleteCol') {
                    grid.getStore().removeAt(rowIndex);
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('whHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'whHdrForm';
            hdr.style.cssText = 'margin-bottom:2px;';
            g.parentNode.insertBefore(hdr, g);

            var bar = document.createElement('div');
            bar.id = 'whMatBar';
            bar.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(bar, g);
        }
    })();

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); matBar.doLayout(); return; }

        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fWh = new myComboField({
            fieldLabel: 'Gudang', store: warehouseDs, displayField: 'name', valueField: 'id', mode: 'local',
            triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', emptyText: 'Pilih proyek dulu',
            listeners: { select: function () { itemDs.removeAll(); fMat.clearValue(); } }
        });
        // [SPK][NEW] No. SPK wajib: material yang boleh di-issue & batas qty mengikuti SPK (kuota = % pembayaran termin x 2)
        fSpk = new myComboField({
            fieldLabel: 'No. SPK', store: spkDs, displayField: 'label', valueField: 'id', mode: 'local',
            triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', emptyText: 'Pilih proyek dulu (SPK Diterbitkan)',
            listeners: {
                select: function (combo, rec) {
                    itemDs.removeAll(); fMat.clearValue();
                    if (rec && !fMandor.getValue()) { fMandor.setValue(rec.get('foreman') || ''); }      // mandor default dari SPK
                }
            }
        });
        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fMandor = new myTextField({ fieldLabel: 'Mandor Penerima', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'whHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.5, [fProject, fSpk, fWh]), col(0.5, [fDate, fMandor, fRemark])]
        });

        fMat = new myComboField({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, anchor: '98%', listWidth: 520,
            emptyText: 'Cari material yang sudah RCV di gudang...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(stok {onhand}, RAB {rab_qty}, sisa SPK {spk_remaining}, maks issue {max_issue} {unit})</b></div></tpl>',     // [SPK] + sisa SPK
            listeners: {
                beforequery: function () {
                    if (!fProject.getValue() || !fWh.getValue()) { showErr('Pilih proyek dan gudang terlebih dahulu.'); return false; }
                    if (!fSpk.getValue()) { showErr('Pilih SPK terlebih dahulu.'); return false; }                 // [SPK]
                    materialDs.baseParams.project_id = fProject.getValue();
                    materialDs.baseParams.warehouse_id = fWh.getValue();
                    materialDs.baseParams.spk_id = fSpk.getValue();                                                  // [SPK]
                }
            }
        });
        matBar = new Ext.Panel({
            renderTo: 'whMatBar',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:2px;',
            items: [
                { columnWidth: 0.4, layout: 'form', border: false, hideLabels: true, bodyStyle: 'background:transparent;', items: [fMat] },
                { width: 150, border: false, bodyStyle: 'background:transparent; padding-left:6px;',
                  items: [new Ext.Button({ text: '+ Tambah Material', handler: addMaterial })] },
                { columnWidth: 0.6, border: false, bodyStyle: 'background:transparent; padding:6px 0 0 8px; color:#9fc3d1; font-size:11px;',
                  html: 'Batas issue = min(stok gudang, RAB +5% - sudah di-issue). Qty melewati RAB (tapi masih +5%) ditandai allowance.' }
            ]
        });
    }

    function onProjectChange() {
        fSpk.clearValue();                                                    // [SPK] muat SPK Diterbitkan milik proyek ini
        spkDs.removeAll();
        spkDs.load({ params: { project_id: fProject.getValue() } });
        fWh.clearValue();
        itemDs.removeAll();
        fMat.clearValue();
        warehouseDs.load({ params: { project_id: fProject.getValue() } });
    }

    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        if (itemDs.findBy(function (r) { return String(r.get('material_id')) === String(rec.get('material_id')); }) >= 0) {
            showErr('Material sudah ada di daftar.');
            return;
        }
        if (rec.get('max_issue') <= 0) {
            showErr('Tidak ada kuota issue untuk material ini (stok kosong / sudah mencapai batas RAB +5% / tidak ada di RAB).');
            return;
        }
        itemDs.add(new itemDs.recordType({
            material_id: rec.get('material_id'), material_code: rec.get('material_code'),
            material_name: rec.get('material_name'), unit: rec.get('unit'),
            qty_out: rec.get('max_issue'), onhand: rec.get('onhand'), max_issue: rec.get('max_issue'),
            rab_qty: rec.get('rab_qty'), issued: rec.get('issued')
        }));
        fMat.clearValue();
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureHdrForm();
        itemGrid.doLayout();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat issue.')) return;
        showEditor('Warehouse Issuing Baru');
        fProject.clearValue();
        fWh.clearValue();
        warehouseDs.removeAll();
        fMat.clearValue();
        fDate.setValue(new Date()); fMandor.setValue(''); fRemark.setValue('');
        fSpk.clearValue(); spkDs.removeAll();       // [SPK]
        itemDs.removeAll();
        projectDs.load();
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue() || !fWh.getValue() || !fDate.getValue()) {
            showErr('Proyek, gudang, dan tanggal wajib diisi.');
            return;
        }
        if (!fSpk.getValue()) { showErr('SPK wajib dipilih untuk issue material ke proyek.'); return; }      // [SPK]
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty_out')) || 0;
            if (q <= 0) { err = 'Qty issue ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
            if (q > parseFloat(r.get('max_issue')) + EPS) { err = 'Qty issue ' + r.get('material_name') + ' melebihi batas issue (' + fmtQty(r.get('max_issue')) + ').'; return false; }
            arr.push({ material_id: r.get('material_id'), qty_out: q });
        });
        if (err) { showErr(err); return; }
        if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }

        ajax({
            action: 'save', project_id: fProject.getValue(), warehouse_id: fWh.getValue(), spk_id: fSpk.getValue(),     // [SPK] + spk_id
            issue_date: ymd(fDate.getValue()),
            received_by_mandor: fMandor.getValue(), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'Issuing berhasil diposting.');
                document.getElementById('editorWdw').style.display = 'none';
                dataRecord = null;
                dtlDiv.style.display = 'none';
                mainDs.load();
                updateMainButtons();
            } else {
                showErr(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'posted' || dataRecord.issue_type === 'SUPPLIER_RETURN') {
            showErr('Pilih issue proyek berstatus posted terlebih dahulu. Issue Retur Supplier dibatalkan lewat Batal Kirim di modul Retur Pembelian.');
            return;
        }
        reverseDoc(dataRecord);
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';

    function refreshList() {
        var ed = document.getElementById('editorWdw');
        if (ed && ed.style.display === 'block') { return; }
        if (Ext.WindowMgr && Ext.WindowMgr.getActive && Ext.WindowMgr.getActive()) { return; }
        var id = dataRecord ? dataRecord.id : 0, oldStatus = dataRecord ? dataRecord.status : null;
        mainDs.load({ callback: function () {
            if (!id) { return; }
            var idx = mainDs.find('id', id);
            if (idx < 0) { return; }
            dataRecord = mainDs.getAt(idx).data;
            gridMain.getSelectionModel().selectRow(idx, false);
            updateMainButtons();
            if (dataRecord.status !== oldStatus) { loadDetailPanel(dataRecord); }
        } });
    }
    setInterval(refreshList, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshList(); } });

});