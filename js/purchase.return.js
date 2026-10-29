/*
  Document      : purchase.return.js
  Description   : Retur Pembelian / Retur Supplier untuk material cacat / rusak. ExtJS 3.4.1
                  Mengikuti pola po.js: grid utama (#divMainGrid) + panel detail di bawah grid dengan
                  tombol aksi status, editor memakai #editorWdw bawaan template.
                  Alur: Diajukan -> (Perintahkan W/H) Diperintahkan -> (gudang: Siap Kirim) Disiapkan
                        -> (gudang: Konfirmasi Dikirim) Dikirim -> (Selesaikan) Diganti | Kredit
                  [CHANGED 2026-10-11] Pembelian hanya memerintahkan issue; yang menyatakan barang SUDAH DIKIRIM adalah gudang
                  (Warehouse Issuing). Stok keluar + jurnal baru dibuat saat gudang konfirmasi dikirim.
                  Tombol template: Add = retur baru | Edit = ubah retur Diajukan | Delete = Batalkan retur Diajukan
                  Tombol panel detail: Perintahkan W/H / Batalkan (Diajukan), Batalkan Perintah (Diperintahkan/Disiapkan),
                                       Cetak SJ / Batal Kirim / Selesaikan (Dikirim)
  Entry retur    : pilih dokumen W/H RCV -> proyek, gudang, supplier, PO terisi otomatis; item = item RCV (isi qty + jenis cacat)
  Backend       : ../php-crud/purchase.returnCRD.php
  [CHANGED]     : seluruh file ditulis ulang -- sebelumnya membuat grid/toolbar/window sendiri ke body,
                  sehingga #editorWdw template tampil kosong dan grid tidak muncul; URL AJAX juga relatif
                  ke /views/ (kini '../php-crud/').
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    // [FIX-UI] Style khusus halaman Retur Pembelian (disuntik dari JS agar CSS global tidak berubah).
    //  1) Header grid editor seragam: <td> dan .x-grid3-hd-inner sama-sama biru #194a82 (sebelumnya inner
    //     kena warna gelap grddetail.css sedangkan td biru -> muncul garis/pita tidak sejajar),
    //     teks rata tengah vertikal, tinggi satu baris & dua baris ('Sudah<br>Diretur') tidak lagi beda.
    //  2) Window bantu (Bukti / Kirim / Selesaikan, class rtDarkWin): tombol & label terbaca di tema dark.
    (function () {
        var old = document.getElementById('gridEditorHdrCss');
        if (old) old.parentNode.removeChild(old);
        var H = 'body .x-grid3-gridEditor ';
        var css = [
            H + '.x-grid3-header,' + H + '.x-grid3-header-offset,' + H + '.x-grid3-header-inner,' + H + '.x-grid3-hd-row,',
            H + '.x-grid3-hd-row td.x-grid3-hd,' + H + '.x-grid3-hd-row td.x-grid3-hd-over,' + H + '.x-grid3-hd-row td.x-grid3-hd-click,',
            H + '.x-grid3-hd-row td.sort-asc,' + H + '.x-grid3-hd-row td.sort-desc,' + H + '.x-grid3-hd-row td.x-grid3-hd-menu-open{',
            '  background:#194a82 !important;background-image:none !important;border-top:none !important;',
            '  border-bottom:2px solid #2b6cb0 !important;border-left:none !important;border-right:1px solid rgba(255,255,255,.12) !important;',
            '  vertical-align:middle !important;}',
            H + '.x-grid3-hd-row td.x-grid3-hd:last-child{border-right:none !important;}',
            H + '.x-grid3-hd-inner,' + H + 'td.x-grid3-hd-over .x-grid3-hd-inner,' + H + 'td.sort-asc .x-grid3-hd-inner,' + H + 'td.sort-desc .x-grid3-hd-inner{',
            '  background:transparent !important;background-image:none !important;border:none !important;',
            '  padding:8px 8px !important;white-space:normal !important;text-overflow:clip !important;',
            '  line-height:14px !important;height:auto !important;color:#ffffff !important;}',
            H + '.x-grid3-hd-btn,' + H + 'img.x-grid3-sort-icon{display:none !important;}',
            '.rtDarkWin .x-form-item label{color:#9fc3d1 !important;}',
            '.rtDarkWin .x-form-display-field{color:#e2e8f0 !important;}',
            '.rtDarkWin .x-form-empty-field{color:#9fb4c7 !important;}',
            '.rtDarkWin .x-btn button{color:#ffffff !important;font-weight:bold !important;opacity:1 !important;}',
            '.rtDarkWin .x-btn-tl,.rtDarkWin .x-btn-tr,.rtDarkWin .x-btn-tc,.rtDarkWin .x-btn-ml,.rtDarkWin .x-btn-mr,',
            '.rtDarkWin .x-btn-bl,.rtDarkWin .x-btn-br,.rtDarkWin .x-btn-bc{background:none !important;background-image:none !important;}',
            '.rtDarkWin .x-btn-mc{background-color:#2e7dd1 !important;background-image:none !important;border-radius:4px;}',
            '.rtDarkWin .x-btn-over .x-btn-mc{background-color:#4695e6 !important;}',
            '.rtDarkWin .x-btn-mc button{padding:2px 8px;}'
        ].join('\n');
        var st = document.createElement('style');
        st.id = 'gridEditorHdrCss';
        st.type = 'text/css';
        st.innerHTML = css;
        document.getElementsByTagName('head')[0].appendChild(st);
    })();
    document.getElementById("mainHeaderText").innerHTML = "Retur <em>Pembelian</em>";

    var CRD = main_url + '/php-crud/purchase.returnCRD.php';

    var ACCESS_ADD = 2;      // level akses minimal: buat retur
    var ACCESS_EDIT = 3;     // level akses minimal: ubah / batalkan / kirim / selesaikan
    var EPS = 0.00005;       // toleransi qty
    var DEFECT_TYPES = ['Cacat Produksi', 'Rusak Saat Pengiriman', 'Tidak Sesuai Spesifikasi', 'Kualitas Di Bawah Standar', 'Lainnya'];

    var dataRecord = null;   // baris retur terpilih di grid utama
    var editId = 0;          // 0 = retur baru, >0 = edit retur tsb
    var hdrForm = null;
    var fRcv, fProjInfo, fWhInfo, fSupInfo, fPoInfo, fDate, fDo, fRemark;     // [CHANGED] dokumen RCV sebagai dasar retur

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    // [FIX-DATE] format('Y-m-d') Ext menghasilkan 'Y-10-10' di browser ini (huruf Y tidak diganti tahun) -> backend menolak.
    //            Susun string YYYY-MM-DD manual, tidak bergantung Date.format / Date.parseDate Ext.
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function parseYmd(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(str || '');
        return m ? new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10)) : null;
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }

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

    function statusColor(v) {
        switch (v) {
            case 'Diajukan': return '#e6a817 !important';
            case 'Diperintahkan': return '#b48ef0 !important';      // [NEW]
            case 'Disiapkan': return '#4fa3ff !important';          // [NEW]
            case 'Dikirim': return '#2bc1e2 !important';
            case 'Diganti': return '#00ff4c !important';
            case 'Kredit': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
    }

    // Jalankan aksi status lalu segarkan grid + panel detail
    function doAction(params, okTitle) {
        ajax(params, function (res) {
            Ext.Msg.alert(res.success ? (okTitle || 'Sukses') : 'Gagal', res.message || '');
            if (res.success) {
                var id = dataRecord ? dataRecord.id : 0;
                dtlDiv.style.display = 'none';
                dataRecord = null;
                updateMainButtons();
                mainDs.load({
                    callback: function () {             // pilih ulang baris yang sama agar panel detail tampil status baru
                        var idx = mainDs.find('id', id);
                        if (idx >= 0) {
                            dataRecord = mainDs.getAt(idx).data;
                            updateMainButtons();
                            loadDetailPanel(dataRecord);
                        }
                    }
                });
            }
        });
    }


    // [CHANGED] bukti retur: link/foto/PDF. Hanya http(s) atau path lokal yang dijadikan link (cegah javascript: URL)
    function evidenceLink(v) {
        if (!v) return '';
        if (!/^(https?:\/\/|\/)/i.test(v)) return enc(v);
        var label = /\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(v) ? 'Foto' : (/\.pdf(\?.*)?$/i.test(v) ? 'PDF' : 'Link');
        return '<a href="' + enc(v) + '" target="_blank" onclick="event.stopPropagation();" style="color:#2bc1e2;">' + label + '</a>';
    }
    function evidenceRenderer(v) {
        if (!v) return '<span style="color:#668894;cursor:pointer;">+ Foto / Link</span>';
        return evidenceLink(v) + ' <span style="color:#668894;cursor:pointer;" title="Ubah bukti">&#9998;</span>';
    }

    // Window isi bukti: tempel link ATAU unggah foto/PDF (maks 5 MB) -> URL tersimpan di kolom bukti baris tsb
    function openEvidence(rec) {
        var fUrl = new Ext.form.TextField({ fieldLabel: 'Link', anchor: '98%', value: rec.get('evidence_ref') || '',
            emptyText: 'Tempel link foto / dokumen (https://...) atau unggah file' });
        var info = new Ext.form.DisplayField({ hideLabel: true, value: '' });
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,.pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
        fileInput.onchange = function () {
            var f = fileInput.files && fileInput.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024) { showErr('Ukuran file maksimal 5 MB.'); fileInput.value = ''; return; }
            var fd = new FormData();
            fd.append('action', 'upload_evidence');
            fd.append('file', f);
            info.setValue('Mengunggah ' + enc(f.name) + '...');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CRD);
            xhr.onload = function () {
                var o;
                try { o = Ext.decode(xhr.responseText); } catch (e) { info.setValue(''); showErr('Respon server tidak valid (cek error_log PHP).'); return; }
                if (!o.success) { info.setValue(''); showErr(o.message || 'Unggah gagal.'); return; }
                fUrl.setValue(o.url);
                info.setValue('<span style="color:#2e9e4f;">Terunggah: ' + enc(o.name) + '</span>');
            };
            xhr.onerror = function () { info.setValue(''); showErr('Koneksi ke server gagal saat mengunggah.'); };
            xhr.send(fd);
        };
        var win = new Ext.Window({
            title: 'Bukti Retur - ' + rec.get('material_name'), width: 520, height: 210, modal: true, layout: 'fit', cls: 'rtDarkWin',      // [FIX-UI] cls
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 50, bodyStyle: 'padding:12px;',
                items: [fUrl,
                        { xtype: 'button', text: 'Pilih Foto / PDF dari komputer...', handler: function () { fileInput.click(); } },
                        { xtype: 'spacer', height: 8 }, info]
            }),
            buttons: [
                { text: 'OK', handler: function () {
                    var v = (fUrl.getValue() || '').trim();
                    if (v && !/^(https?:\/\/|\/)/i.test(v)) { showErr('Link harus diawali http:// atau https://'); return; }
                    rec.set('evidence_ref', v);
                    win.close();
                } },
                { text: 'Hapus Bukti', handler: function () { rec.set('evidence_ref', ''); win.close(); } },
                { text: 'Batal', handler: function () { win.close(); } }
            ],
            listeners: { close: function () { if (fileInput.parentNode) fileInput.parentNode.removeChild(fileInput); } }
        });
        win.show();
    }

    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama) -- pola sama dengan po.js
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'rtDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.max(DtlHeight, 220) + 'px';      // [FIX-UI] wajib pakai 'px' (angka polos diabaikan browser, panel keluar layar)
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

    // [NEW] Cetak Surat Jalan retur (client-side, tanpa ubah backend). Data: header baris grid + item dari action 'detail'.
    //       Isi COMPANY_NAME / COMPANY_ADDR sesuai perusahaan; kosong = baris itu tidak dicetak.
    var COMPANY_NAME = '';      // [NEW] contoh: 'PT. NAMA KONTRAKTOR'
    var COMPANY_ADDR = '';      // [NEW] contoh: 'Jl. ... No. ..., Surabaya'
    function fmtDmy(str) {
        var d = parseYmd(str);
        if (!d) return enc(str || '');
        var m = d.getMonth() + 1, dd = d.getDate();
        return (dd < 10 ? '0' + dd : dd) + '/' + (m < 10 ? '0' + m : m) + '/' + d.getFullYear();
    }
    function qtyPrint(v) {
        var n = Math.round((parseFloat(v) || 0) * 10000) / 10000;
        return Ext.util.Format.number(n, '0,000.0000').replace(/\.?0+$/, '');
    }
    function printSuratJalan(h, items) {
        if (!h.issue_ref_no) { showErr('Surat jalan baru tersedia setelah retur dikirim (Issue W/H dibuat).'); return; }
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) { showErr('Popup diblokir browser. Izinkan popup untuk situs ini lalu coba lagi.'); return; }
        var rows = '', no = 0, totalLine = 0;
        Ext.each(items, function (i) {
            no++;
            rows += '<tr><td class="c">' + no + '</td><td>' + enc(i.material_code || '') + '</td><td>' + enc(i.material_name) + '</td>' +
                    '<td class="r">' + qtyPrint(i.qty_claim) + '</td><td class="c">' + enc(i.unit || '') + '</td>' +
                    '<td>' + enc(i.defect_type || '') + '</td><td>' + enc(i.defect_note || '') + '</td></tr>';
        });
        totalLine = items.length;
        var css = '@page{size:A4;margin:12mm;}body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0;}' +
                  'h1{font-size:18px;margin:0 0 2px 0;text-align:center;letter-spacing:1px;}.sub{text-align:center;margin-bottom:10px;font-size:11px;}' +
                  'table{border-collapse:collapse;width:100%;}.meta td{padding:2px 4px;vertical-align:top;}' +
                  '.items th,.items td{border:1px solid #000;padding:5px 6px;}.items th{background:#eee;}' +
                  '.c{text-align:center;}.r{text-align:right;}.sign td{width:33%;text-align:center;padding-top:6px;}' +
                  '.sign .box{height:70px;}hr{border:0;border-top:2px solid #000;margin:8px 0;}';
        var html = '<html><head><meta charset="utf-8"><title>Surat Jalan ' + enc(h.issue_ref_no) + '</title><style>' + css + '</style></head><body>' +
                   (COMPANY_NAME ? '<div style="font-size:15px;font-weight:bold;text-align:center;">' + enc(COMPANY_NAME) + '</div>' : '') +
                   (COMPANY_ADDR ? '<div class="sub">' + enc(COMPANY_ADDR) + '</div>' : '') +
                   '<hr/><h1>SURAT JALAN RETUR PEMBELIAN</h1><div class="sub">Pengembalian barang cacat / rusak ke supplier</div>' +
                   '<table class="meta"><tr>' +
                   '<td width="16%">No. Surat Jalan</td><td width="34%">: <b>' + enc(h.issue_ref_no) + '</b></td>' +
                   '<td width="16%">Tanggal Kirim</td><td width="34%">: ' + fmtDmy(h.sent_date) + '</td></tr><tr>' +
                   '<td>Kepada (Supplier)</td><td>: <b>' + enc(h.supplier_name) + '</b></td>' +
                   '<td>No. Retur</td><td>: ' + enc(h.ref_no) + '</td></tr><tr>' +
                   '<td>Proyek</td><td>: ' + enc(h.project_name) + '</td>' +
                   '<td>No. PO</td><td>: ' + enc(h.po_number || '-') + '</td></tr><tr>' +
                   '<td>Gudang Asal</td><td>: ' + enc(h.warehouse_name) + '</td>' +
                   '<td>No. DO Asal</td><td>: ' + enc(h.supplier_do_no || '-') + '</td></tr><tr>' +
                   '<td>Kurir / Penerima</td><td colspan="3">: ' + enc(h.carrier || '-') + '</td></tr></table>' +
                   '<br/><table class="items"><thead><tr><th width="4%">No</th><th width="12%">Kode</th><th>Material</th><th width="10%">Qty</th>' +
                   '<th width="8%">Sat</th><th width="18%">Jenis Cacat</th><th width="20%">Keterangan</th></tr></thead><tbody>' + rows +
                   '</tbody></table><div style="margin-top:6px;">Total item: <b>' + totalLine + '</b>' +
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

    // [NEW] bukti pengiriman = satu atau lebih URL (satu per baris). Hanya http(s)/path lokal yang dijadikan link.
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

    // [NEW] Banner status pengiriman dari W/H di panel detail retur (read-only; aksi gudang ada di Warehouse Issuing)
    function dispatchBanner(h) {
        function box(bg, border, fg, inner) {
            return '<div style="margin:0 2px 8px 2px; padding:8px 12px; background:' + bg + '; border-left:4px solid ' + border + '; color:' + fg + ';">' + inner + '</div>';
        }
        if (h.status === 'Diperintahkan') {
            return box('#4a3a0b', '#e6a817', '#ffe9a8', '<b>Perintah issue W/H sudah dibuat</b> (' + enc(h.issue_ref_no || '-') +
                ') &mdash; menunggu gudang menyiapkan barang. Stok belum keluar.');
        }
        if (h.status === 'Disiapkan') {
            return box('#0b3550', '#2bc1e2', '#cdeefa', '<b>Barang sudah disiapkan gudang</b> (' + enc(h.issue_ref_no || '-') +
                ', ' + enc(h.prepared_at || '-') + ') &mdash; menunggu konfirmasi pengiriman. Stok belum keluar.');
        }
        if (h.issue_ref_no && (h.status === 'Dikirim' || h.status === 'Diganti' || h.status === 'Kredit')) {
            return box('#0f3d2a', '#2e9e4f', '#c9f2d9', '<b style="color:#4be08a;">&#10004; Barang sudah dikirim dari W/H</b> &nbsp;|&nbsp; Issue W/H: <b>' + enc(h.issue_ref_no) + '</b>' +
                ' &nbsp;|&nbsp; Tgl kirim: <b>' + enc(h.sent_date || '-') + '</b> &nbsp;|&nbsp; Kurir / Kendaraan: <b>' + enc(h.carrier || '-') + '</b>' +
                ' &nbsp;|&nbsp; Penerima: <b>' + enc(h.delivery_receiver || '-') + '</b> &nbsp;|&nbsp; No. Surat Jalan: <b>' + enc(h.delivery_note_no || '-') + '</b>' +
                (h.delivery_proof ? '<br>Bukti pengiriman: ' + proofLinks(h.delivery_proof) : ''));      // [NEW] foto / PDF dari gudang
        }
        return '';
    }

    function renderDetailPanel(h, items) {
        var grand = 0;
        // [FIX-UI] tombol aksi sesuai status dipindah ke baris judul (sebelumnya di dasar panel -> tidak terlihat)
        var actions = [];
        if (h.status === 'Diajukan') { actions = [['order', 'Perintahkan W/H (Issue)', '#03615e'], ['cancel', 'Batalkan Retur', '#8e2b22']]; }      // [CHANGED] send -> order
        else if (h.status === 'Diperintahkan' || h.status === 'Disiapkan') { actions = [['cancel_order', 'Batalkan Perintah W/H', '#8e2b22']]; }      // [NEW]
        else if (h.status === 'Dikirim') { actions = [['print', 'Cetak Surat Jalan', '#2e6da4'], ['unsend', 'Batal Kirim', '#555555'], ['resolve', 'Selesaikan', '#03615e']]; }
        else if (h.issue_ref_no) { actions = [['print', 'Cetak Surat Jalan', '#2e6da4']]; }      // [NEW] Diganti / Kredit: surat jalan tetap bisa dicetak ulang
        var actHtml = '';
        if (actions.length) {
            actHtml = '<div style="float:right; margin:2px 4px 0 0;">';
            Ext.each(actions, function (a) {
                actHtml += '<button type="button" class="rtActionBtn" data-act="' + a[0] + '" style="margin-left:8px; padding:8px 16px; background:' + a[2] +
                           '; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">' + enc(a[1]) + '</button>';
            });
            actHtml += '</div>';
        }
        var html = actHtml + '<h3 class="detail-title">Detail Retur: ' + enc(h.ref_no) +
                   ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.return_date) + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Gudang: <b>' + enc(h.warehouse_name) + '</b> &nbsp;|&nbsp; Supplier: <b>' + enc(h.supplier_name) +
                   '</b> &nbsp;|&nbsp; PO: <b>' + enc(h.po_number || '-') + '</b> &nbsp;|&nbsp; DO Asal: <b>' + enc(h.supplier_do_no || '-') + '</b>' +
                   (h.issue_ref_no ? ' &nbsp;|&nbsp; Issue W/H: <b>' + enc(h.issue_ref_no) + '</b> (' + enc(h.sent_date) + ', ' + enc(h.carrier || '-') + ')' : '') +
                   (h.resolution_type ? ' &nbsp;|&nbsp; Penyelesaian: <b>' + enc(h.resolution_type) + '</b> ' + enc(h.resolution_date) +
                       (h.credit_note_no ? ' (NK ' + enc(h.credit_note_no) + ', ' + fmtAmt(h.credit_amount) + ')' : '') +
                       (h.resolution_note ? ' - ' + enc(h.resolution_note) : '') : '') +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   dispatchBanner(h) +      // [CHANGED] info status pengiriman dari W/H (gudang yang konfirmasi)
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty Retur</th><th align="right">Biaya Satuan (FIFO)</th>' +
                   '<th align="right">Nilai</th><th align="left">Jenis Cacat</th><th align="left">Keterangan</th><th align="left">Bukti</th>' +
                   '</tr></thead><tbody>';
        if (!items.length) {
            html += '<tr><td colspan="7" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>';
        }
        Ext.each(items, function (i) {
            var amt = parseFloat(i.amount) || 0;
            grand += amt;
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.material_name) + ' <span style="color:#668894;">(' + enc(i.material_code || '') + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty_claim) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right">' + fmtAmt(i.unit_cost) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(amt) + '</td>' +
                    '<td align="left">' + enc(i.defect_type) + '</td>' +
                    '<td align="left">' + enc(i.defect_note || '') + '</td>' +
                    '<td align="left">' + evidenceLink(i.evidence_ref) + '</td></tr>';
        });
        html += '<tr class="data-row"><td colspan="3" align="right"><b>Total</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grand) + '</b></td><td colspan="3"></td></tr>';
        html += '</tbody></table>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var btns = dtlDiv.querySelectorAll('.rtActionBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                var act = this.getAttribute('data-act');
                if (act === 'print') { printSuratJalan(h, items); return; }      // [NEW] cetak tidak butuh hak edit
                runAction(h, act);
            };
        }
    }

    function runAction(rec, act) {
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengubah status retur.')) return;
        if (act === 'order') { openOrder(rec); }      // [CHANGED] send -> order
        else if (act === 'resolve') { openResolve(rec); }
        else if (act === 'cancel') {
            Ext.Msg.confirm('Batalkan Retur', 'Batalkan retur <b>' + enc(rec.ref_no) + '</b>?', function (b) {
                if (b === 'yes') doAction({ action: 'cancel', id: rec.id });
            });
        } else if (act === 'cancel_order') {      // [NEW]
            Ext.Msg.confirm('Batalkan Perintah W/H', 'Batalkan perintah issue W/H untuk <b>' + enc(rec.ref_no) + '</b>? Retur kembali berstatus Diajukan.', function (b) {
                if (b === 'yes') doAction({ action: 'cancel_order', id: rec.id });
            });
        } else if (act === 'unsend') {
            Ext.Msg.confirm('Batal Kirim', 'Batalkan pengiriman <b>' + enc(rec.ref_no) + '</b>? Issue W/H di-reverse dan stok kembali ke gudang.', function (b) {
                if (b === 'yes') doAction({ action: 'unsend', id: rec.id });
            });
        }
    }

    // ---- [CHANGED] Perintahkan W/H: buat dokumen issue (pending). Stok belum keluar; gudang yang konfirmasi dikirim ----
    function openOrder(rec) {
        var wDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Perintah', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var win = new Ext.Window({
            title: 'Perintahkan W/H untuk Retur ' + rec.ref_no, width: 460, height: 190, modal: true, layout: 'fit', cls: 'rtDarkWin',      // [FIX-UI] cls
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 120, bodyStyle: 'padding:12px;',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: 'Dokumen issue W/H dibuat berstatus pending. Stok BELUM keluar sampai gudang menyiapkan barang dan mengonfirmasi pengiriman di Warehouse Issuing.' },
                        wDate]
            }),
            buttons: [
                { text: 'Perintahkan', handler: function () {
                    if (!wDate.getValue()) { showErr('Tanggal perintah wajib diisi.'); return; }
                    // [FIX-CLOSE] ambil nilai field SEBELUM window ditutup (win.close() menghancurkan field -> getValue() error, request tidak terkirim)
                    var params = { action: 'order', id: rec.id, order_date: ymd(wDate.getValue()) };
                    win.close();
                    doAction(params);
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    // ---- Selesaikan (ganti barang / kredit) ----
    function openResolve(rec) {
        var wType = new Ext.form.ComboBox({
            fieldLabel: 'Penyelesaian', store: ['Ganti Barang', 'Kredit'], triggerAction: 'all', editable: false,
            forceSelection: true, allowBlank: false, anchor: '95%', value: 'Ganti Barang'
        });
        var wDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var wCnNo = new Ext.form.TextField({ fieldLabel: 'No. Nota Kredit', anchor: '95%', disabled: true });
        var wCnAmt = new Ext.form.NumberField({ fieldLabel: 'Nilai Kredit', decimalPrecision: 2, allowNegative: false, width: 160,
            disabled: true, value: Math.round((rec.total_amount || 0) * 100) / 100 });
        var wNote = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });
        wType.on('select', function () {
            var kredit = (wType.getValue() === 'Kredit');
            wCnNo.setDisabled(!kredit);
            wCnAmt.setDisabled(!kredit);
        });
        var win = new Ext.Window({
            title: 'Selesaikan Retur ' + rec.ref_no, width: 460, height: 290, modal: true, layout: 'fit', cls: 'rtDarkWin',      // [FIX-UI] cls
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 110, bodyStyle: 'padding:12px;',
                items: [wType, wDate, wCnNo, wCnAmt, wNote,
                        { xtype: 'displayfield', hideLabel: true,
                          value: '<span style="color:#888;">Ganti Barang: barang pengganti diterima lewat RCV biasa.</span>' }]
            }),
            buttons: [
                { text: 'Simpan', handler: function () {
                    if (!wDate.getValue()) { showErr('Tanggal wajib diisi.'); return; }
                    var kredit = (wType.getValue() === 'Kredit');
                    if (kredit && (!wCnNo.getValue() || !(parseFloat(wCnAmt.getValue()) > 0))) {
                        showErr('Kredit wajib mengisi no. nota kredit dan nilai kredit (> 0).'); return;
                    }
                    // [FIX-CLOSE] ambil nilai field SEBELUM window ditutup
                    var params = {
                        action: 'resolve', id: rec.id, resolution_type: wType.getValue(),
                        resolution_date: ymd(wDate.getValue()),
                        credit_note_no: kredit ? wCnNo.getValue() : '', credit_amount: kredit ? wCnAmt.getValue() : 0,
                        resolution_note: wNote.getValue()
                    };
                    win.close();
                    doAction(params);
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list', start: 0, limit: 250 },
        root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'return_date', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name',
                 'supplier_id', 'supplier_name', 'po_id', 'po_number', 'supplier_do_no', 'status', 'remark',
                 'wh_receiving_hdr_id', 'rcv_ref_no', 'material_out_id', 'issue_ref_no', 'sent_date', 'carrier', 'issue_status', 'prepared_at', 'delivery_note_no', 'delivery_receiver', 'delivery_proof',
                 'resolution_type', 'resolution_date', 'credit_note_no', { name: 'credit_amount', type: 'float' }, 'resolution_note',
                 { name: 'line_count', type: 'int' }, { name: 'total_qty', type: 'float' }, { name: 'total_amount', type: 'float' }],
        listeners: { exception: storeError }
    });
    mainDs.load();

    // [CHANGED] entry retur berbasis dokumen RCV: hanya store RCV + item RCV (tanpa combo proyek/supplier/gudang/PO/material)
    var rcvDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_rcv' }, root: 'data', idProperty: 'id',
        fields: ['id', 'ref_no', 'label', 'received_date', 'warehouse_id', 'warehouse_name', 'grn_no',
                 'po_id', 'po_number', 'project_id', 'project_name', 'supplier_id', 'supplier_name'],
        listeners: { exception: storeError }
    });
    var itemField = ['material_id', 'material_code', 'material_name', 'unit',
                     { name: 'qty_rcv', type: 'float' }, { name: 'qty_returned', type: 'float' }, { name: 'available', type: 'float' },
                     { name: 'qty_claim', type: 'float' }, 'defect_type', 'defect_note', 'evidence_ref'];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });
    var defectData = [];
    Ext.each(DEFECT_TYPES, function (d) { defectData.push([d]); });      // data jenis cacat untuk combo
    var defectDs = new Ext.data.ArrayStore({ fields: ['v'], data: defectData });

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Retur)
    // ------------------------------------------------------------
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',          // [CHANGED] container template, bukan body
        height: mainHeight,
        columns: [
            { header: 'No. Retur', dataIndex: 'ref_no', width: 105 },
            { header: 'Tanggal', dataIndex: 'return_date', width: 85, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 170, renderer: CellWrap },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 150, renderer: CellWrap },
            { header: 'No. RCV', dataIndex: 'rcv_ref_no', width: 120, renderer: function (v) { return v || '-'; } },      // [CHANGED]
            { header: 'PO', dataIndex: 'po_number', width: 100, renderer: function (v) { return v || '-'; } },
            { header: 'Item', dataIndex: 'line_count', width: 45, align: 'center' },
            { header: 'Total Qty', dataIndex: 'total_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { header: 'Nilai (FIFO)', dataIndex: 'total_amount', width: 105, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Issue W/H', dataIndex: 'issue_ref_no', width: 105, renderer: function (v) { return v || '-'; } },
            { header: 'Penyelesaian', dataIndex: 'resolution_type', width: 100,
              renderer: function (v, m, r) { return v ? enc(v) + (r.get('credit_amount') ? ' (' + fmtAmt(r.get('credit_amount')) + ')' : '') : '-'; } },
            { header: 'Status', dataIndex: 'status', width: 85, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } }
        ],
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
        if (dataRecord && dataRecord.status === 'Diajukan') {     // Edit & Delete(Batalkan) hanya untuk retur Diajukan
            mainBtnEnable('#editData');
            mainBtnEnable('#deleteData');
        } else {
            mainBtnDisable('#editData');
            mainBtnDisable('#deleteData');
        }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: pilih dokumen RCV -> header terisi otomatis + grid item RCV
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        enableHdMenu: false,          // [FIX-UI] tanpa dropdown header
        enableColumnMove: false,      // [FIX-UI]
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'material_code', width: 80 },
            { sortable: false, menuDisabled: true, header: 'Material', dataIndex: 'material_name', width: 170 },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Qty RCV', dataIndex: 'qty_rcv', width: 80, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Sudah<br>Diretur', dataIndex: 'qty_returned', width: 85, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Boleh<br>Diretur', dataIndex: 'available', width: 85, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty Retur', dataIndex: 'qty_claim', width: 85, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }),
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { sortable: false, menuDisabled: true, header: 'Jenis Cacat', dataIndex: 'defect_type', width: 150,
              editor: new myComboField({ store: defectDs, displayField: 'v', valueField: 'v', mode: 'local',
                                         triggerAction: 'all', editable: false, forceSelection: true }) },
            { sortable: false, menuDisabled: true, header: 'Keterangan', dataIndex: 'defect_note', width: 160, editor: new myTextField(), renderer: function (v) { return enc(v); } },
            { id: 'rtEvidenceCol', sortable: false, menuDisabled: true, header: 'Bukti<br>Foto / Link', dataIndex: 'evidence_ref', width: 120, renderer: evidenceRenderer }      // [CHANGED] klik sel -> window link / unggah foto ([FIX-UI] trailing comma dihapus)
        ],
        listeners: {
            validateedit: function (e) {
                if (e.field === 'qty_claim' && parseFloat(e.value) > parseFloat(e.record.get('available')) + EPS) {
                    showErr('Qty melebihi qty yang boleh diretur (' + fmtQty(e.record.get('available')) + ').');
                    e.cancel = true;
                }
            },
            cellclick: function (grid, rowIndex, columnIndex, e) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'rtEvidenceCol' && !(e && e.getTarget('a'))) {
                    var r = grid.getStore().getAt(rowIndex);
                    if (r) openEvidence(r);
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Tempat form header (disisipkan sebelum grid editor)
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('rtHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'rtHdrForm';
            hdr.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(hdr, g);
        }
    })();

    // Form dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        // satu-satunya pilihan: dokumen RCV (cari no. RCV / GRN / PO / proyek / supplier)
        fRcv = new myComboField({
            fieldLabel: 'Dokumen RCV', store: rcvDs, displayField: 'label', valueField: 'id',
            mode: 'remote', queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false,
            anchor: '98%', listWidth: 700, emptyText: 'Pilih / cari RCV (no. RCV, GRN, PO, proyek, supplier)...',
            listeners: { select: function (combo, rec) { onRcvSelected(rec); } }
        });
        // diturunkan otomatis dari RCV (read-only)
        fProjInfo = new Ext.form.DisplayField({ fieldLabel: 'Proyek', value: '-', anchor: '95%' });
        fWhInfo = new Ext.form.DisplayField({ fieldLabel: 'Gudang', value: '-', anchor: '95%' });
        fSupInfo = new Ext.form.DisplayField({ fieldLabel: 'Supplier', value: '-', anchor: '95%' });
        fPoInfo = new Ext.form.DisplayField({ fieldLabel: 'PO / GRN', value: '-', anchor: '95%' });
        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fDo = new myTextField({ fieldLabel: 'No. DO Asal', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 95, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'rtHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(1, [fRcv]),
                    col(0.34, [fProjInfo, fWhInfo, fDate]),
                    col(0.33, [fSupInfo, fPoInfo]),
                    col(0.33, [fDo, fRemark])]
        });
    }

    // Isi info header dari baris RCV terpilih
    function fillRcvInfo(rec) {
        fProjInfo.setValue(rec ? '<b>' + enc(rec.get('project_name')) + '</b>' : '-');
        fWhInfo.setValue(rec ? '<b>' + enc(rec.get('warehouse_name')) + '</b>' : '-');
        fSupInfo.setValue(rec ? '<b>' + enc(rec.get('supplier_name') || '-') + '</b>' : '-');
        fPoInfo.setValue(rec ? '<b style="color:#e2e8f0;">' + enc(rec.get('po_number')) + '</b> / <b style="color:#e2e8f0;">' + enc(rec.get('grn_no') || '-') + '</b>' : '-');      // [FIX-UI] GRN dibuat bold + warna terang
    }

    // Muat item RCV + qty yang masih boleh diretur; cb dipanggil setelah selesai
    function loadRcvItems(rcvId, cb) {
        ajax({ action: 'rcv_items', rcv_id: rcvId, exclude_id: editId }, function (res) {
            if (!res.success) { itemDs.removeAll(); showErr(res.message || 'Gagal memuat item RCV.'); return; }
            itemDs.loadData(res);
            var any = false;
            itemDs.each(function (r) { if (r.get('available') > 0) any = true; });
            if (!any) showErr('Semua item pada RCV ini sudah diretur / stok gudang kosong.');
            if (cb) cb();
        });
    }

    function onRcvSelected(rec) {
        fillRcvInfo(rec);
        itemDs.removeAll();
        if (rec) loadRcvItems(rec.get('id'));
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureHdrForm();
        itemGrid.doLayout();                                 // [FIX-UI] hitung ulang lebar header/body setelah window tampil
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = retur baru
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat retur.')) return;
        editId = 0;
        showEditor('Retur Pembelian Baru');
        fRcv.enable();
        fRcv.clearValue();
        fillRcvInfo(null);
        fDate.setValue(new Date()); fDo.setValue(''); fRemark.setValue('');
        itemDs.removeAll();
        rcvDs.load();
    };

    // EDIT = ubah retur Diajukan (dokumen RCV tidak bisa diganti)
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih retur terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Diajukan') { showErr('Hanya retur berstatus Diajukan yang dapat diedit.'); return; }
        if (!dataRecord.wh_receiving_hdr_id) { showErr('Retur ini tidak terhubung ke dokumen RCV, hanya bisa dibatalkan.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengedit retur.')) return;
        var rec = dataRecord;
        editId = rec.id;
        showEditor('Edit Retur ' + rec.ref_no);
        itemDs.removeAll();
        rcvDs.load({ params: { query: rec.rcv_ref_no || '' }, callback: function () {
            var rr = rcvDs.getById(rec.wh_receiving_hdr_id);
            fRcv.setValue(rec.wh_receiving_hdr_id);
            fRcv.disable();
            fillRcvInfo(rr);
            fDate.setValue(parseYmd(rec.return_date) || new Date());      // [FIX-DATE]
            fDo.setValue(rec.supplier_do_no || '');
            fRemark.setValue(rec.remark || '');
            loadRcvItems(rec.wh_receiving_hdr_id, function () {
                // pasang qty / jenis cacat tersimpan ke baris item RCV (baris tambahan bila satu material punya >1 baris)
                ajax({ action: 'detail', id: rec.id }, function (res) {
                    if (!res.success) { showErr(res.message || 'Gagal memuat detail retur.'); return; }
                    var used = {};
                    Ext.each(res.data, function (d) {
                        var idx = itemDs.findBy(function (r) { return String(r.get('material_id')) === String(d.material_id) && !used[r.id]; });
                        var row = idx >= 0 ? itemDs.getAt(idx) : null;
                        if (!row) {
                            var base = itemDs.getAt(itemDs.findBy(function (r) { return String(r.get('material_id')) === String(d.material_id); }));
                            if (!base) return;
                            row = new itemDs.recordType(Ext.apply({}, base.data));
                            itemDs.add(row);
                        }
                        used[row.id] = true;
                        row.set('qty_claim', parseFloat(d.qty_claim));
                        row.set('defect_type', d.defect_type);
                        row.set('defect_note', d.defect_note || '');
                        row.set('evidence_ref', d.evidence_ref || '');
                    });
                });
            });
        } });
    };

    // SAVE
    document.getElementById('saveEdit').onclick = function () {
        if (!fRcv.getValue() || !fDate.getValue()) { showErr('Dokumen RCV dan tanggal wajib diisi.'); return; }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty_claim')) || 0;
            if (q <= 0) return;                                  // baris qty 0 = tidak diretur, diabaikan
            if (!r.get('defect_type')) { err = 'Jenis cacat ' + r.get('material_name') + ' wajib dipilih.'; return false; }
            arr.push({ material_id: r.get('material_id'), qty_claim: q, defect_type: r.get('defect_type'),
                       defect_note: r.get('defect_note') || '', evidence_ref: r.get('evidence_ref') || '' });
        });
        if (err) { showErr(err); return; }
        if (!arr.length) { showErr('Isi qty retur minimal untuk satu material.'); return; }

        ajax({
            action: 'save', id: editId, rcv_id: fRcv.getValue(),
            return_date: ymd(fDate.getValue()), supplier_do_no: fDo.getValue(), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)      // detail bertingkat -> JSON via POST
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'Retur tersimpan.');
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

    // DELETE = Batalkan retur Diajukan (status -> Dibatalkan, data tidak dihapus)
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Diajukan') {
            showErr('Pilih retur berstatus Diajukan terlebih dahulu. Retur Dikirim dibatalkan lewat Batal Kirim di panel detail.');
            return;
        }
        runAction(dataRecord, 'cancel');
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    // [CHANGED] window editor template harus disembunyikan saat halaman dibuka (sebelumnya tampil kosong)
    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';

});