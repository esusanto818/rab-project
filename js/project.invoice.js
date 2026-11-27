/*
  Document      : projectinvoice.js
  Description   : Tagihan Klien (invoice termin proyek).  [MENU-RENAME 2026-11-15] label "Tagihan ke Klien" -> "Tagihan Klien"; id menu 156 & rute lama tidak berubah.
                   Alur: SIMPAN invoice (DRAFT, internal) -> RELEASE tagihan
                   (terbit dokumen TAG-... yang diserahkan ke client; jurnal penagihan posting; invoice baru muncul di
                   modul Project Payment) -> client membayar -> kelebihan bayar jadi deposit client.
                   Grid utama = daftar invoice (status dokumen DRAFT/TERBIT + status Belum Bayar / Sebagian / Lunas,
                   jatuh tempo); klik baris -> panel detail (pembayaran yang masuk, faktur pajak, cetak).
                   Add/Edit membuka editor standar template (editorWdw, seperti PO).
                   Nilai yang diinput = DPP; PPN Keluaran default 11% (0 = tanpa PPN); total tagihan = DPP + PPN.
                   Ubah / hapus hanya untuk invoice DRAFT yang belum ada pembayaran. Ringkasan di atas grid = dasar
                   proyeksi arus kas penerimaan (hanya tagihan yang sudah diterbitkan).
  Backend       : php-crud/project.invoiceCRD.php
  [CHANGED]     : form Terbitkan/Ubah Invoice dipindah dari Ext.Window (tombol Ext tak terbaca di tema gelap) ke editorWdw template
                   dengan tombol SAVE/DISCARD standar, tata letak seperti header PO. Checkbox 'Kenakan PPN' (tak terlihat di tema gelap)
                   diganti pilihan Ya/Tidak. Tombol jendela Faktur Pajak memakai tombol HTML gaya standar (teal / burgundy).
  [RELEASE-LAYER 2026-11-14] : tambah satu layer monitor & release tagihan ke client. Invoice baru = DRAFT (tidak muncul di
                   Pembayaran Termin). Tombol Release Tagihan / Batalkan Release / Release Semua (filter) di filter bar;
                   kolom grid "Dokumen Tagihan" menampilkan nomor TAG + tanggal terbit atau DRAFT; kartu "Belum Diterbitkan"
                   memantau nilai draft. Hanya invoice TERBIT yang dapat dibayar klien.
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Tagihan <em>Klien</em>";

    var CRD = main_url + '/php-crud/project.invoiceCRD.php';

    var ACCESS_ADD = 2;                  // level akses minimal: terbitkan invoice
    var ACCESS_EDIT = 3;                 // ubah / hapus / faktur pajak / release & batal release
    var PPN_DEFAULT_RATE = 11;           // tarif PPN default (%); samakan dengan kebijakan pajak berlaku
    var TOP_DEFAULT_DAYS = 30;           // termin pembayaran default (hari) untuk jatuh tempo; bisa diubah per invoice
    var VEPS = 0.01;

    var dataRecord = null;               // baris invoice terpilih

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }
    // [CHANGED] tombol HTML gaya standar UI (putih tebal, padding 8px 20px, radius 4px); primer teal #066b78, batal burgundy #800020
    function uiBtn(id, label, bg) {
        return '<button type="button" id="' + id + '" style="margin-left:8px;padding:8px 20px;background:' + bg +
               ';color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">' + label + '</button>';
    }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showErr('Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () { if (onFail) onFail(); else showErr('Gagal menghubungi server.'); }
        });
    }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }

    function statusColor(v) {
        return { 'Lunas': '#00ff4c', 'Sebagian': '#e6a817', 'Belum Bayar': '#2bc1e2' }[v] || '#ffffff';
    }
    // [RELEASE-LAYER] warna status dokumen: DRAFT = kuning (internal), TERBIT = hijau (resmi ke client)
    function relColor(s) { return s === 'released' ? '#2e9e4f' : '#e6a817'; }

    // ------------------------------------------------------------
    // 0. Gaya & kerangka halaman (ringkasan, filter, panel detail) - tema gelap
    // ------------------------------------------------------------
    /* [CSS-UNIFIED] Style dipindahkan ke css/app-theme.css */

    var gridContainer = document.getElementById('divMainGrid');
    var topDiv = document.createElement('div');
    topDiv.innerHTML =
        '<div class="ti-cards" id="tiCards"></div>' +
        '<div class="ti-filter">' +
        '<label>Client</label><select id="tiClient"><option value="0">(Semua client)</option></select>' +
        '<label>Status</label><select id="tiStatus">' +
        '<option value="">(Semua)</option><option value="open">Belum lunas</option><option value="unpaid">Belum bayar</option>' +
        '<option value="partial">Sebagian</option><option value="paid">Lunas</option><option value="overdue">Jatuh tempo</option></select>' +
        // [RELEASE-LAYER] filter status dokumen + tombol release/batal release/bulk release
        '<label>Status Dokumen</label><select id="tiDoc">' +
        '<option value="">(Semua)</option><option value="draft">Belum Diterbitkan (DRAFT)</option><option value="released">Sudah Diterbitkan</option></select>' +
        '<button type="button" class="btn" id="tiGo">Tampilkan</button>' +
        '<button type="button" class="btn" id="tiRelease" title="Terbitkan dokumen tagihan (TAG-...) untuk invoice terpilih">Release Tagihan</button>' +
        '<button type="button" class="btn warn" id="tiUnrelease" title="Kembalikan invoice terpilih ke DRAFT">Batalkan Release</button>' +
        '<button type="button" class="btn danger" id="tiReleaseAll" title="Release semua invoice DRAFT pada filter aktif">Release Semua (filter)</button>' +
        '</div>';
    gridContainer.parentNode.insertBefore(topDiv, gridContainer);

    var GridMainHeight = maxGridHeight(gridContainer);
    var mainHeight = Math.max(Math.round(GridMainHeight * 0.55) - 60, 200);
    var DtlHeight = Math.max(Math.round(GridMainHeight - mainHeight - 130), 120);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'tiDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight + 'px';
    dtlDiv.style.overflowY = 'auto';
    gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);

    function renderCards(s) {
        s = s || {};
        var card = function (cls, lb, v) { return '<div class="ti-card ' + cls + '"><div class="lb">' + lb + '</div><div class="vl">' + fmtAmt(v) + '</div></div>'; };
        document.getElementById('tiCards').innerHTML =
            // [RELEASE-LAYER] kartu DRAFT memantau tagihan yang belum diterbitkan ke client
            card('warn', 'Belum Diterbitkan (' + (s.draft_count || 0) + ' dok)', s.draft_value) +
            card('', 'Total Ditagihkan', s.invoiced) + card('ok', 'Sudah Dibayar', s.paid) + card('', 'Sisa Tagihan', s.outstanding) +
            card('warn', 'Lewat Jatuh Tempo', s.overdue) + card('soon', 'Jatuh Tempo 30 Hari', s.due_30) + card('', 'Tanpa Jatuh Tempo', s.no_due);
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    var invField = [
        'id', 'inv_no', 'invoice_date', 'due_date', 'termin_name', 'project_id', 'project_name', 'client_id', 'client_name',
        'faktur_pajak_no', 'faktur_pajak_date', 'remark', 'status',
        'release_status', 'release_no', 'release_date', 'released_by_name', 'release_note',  // [RELEASE-LAYER]
        { name: 'termin_no', type: 'int' }, { name: 'percentage', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'paid_amount', type: 'float' }, { name: 'remaining', type: 'float' }, { name: 'dpp_amount', type: 'float' },
        { name: 'ppn_rate', type: 'float' }, { name: 'ppn_amount', type: 'float' }, 'is_overdue', { name: 'days_overdue', type: 'int' }
    ];
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_invoices' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, invField),
        listeners: {
            exception: storeError,
            load: function (store) { renderCards((store.reader.jsonData || {}).summary); }
        }
    });
    var clientDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_clients' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }]),
        listeners: { exception: storeError }
    });
    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'contract_value', type: 'float' }, { name: 'billed_dpp', type: 'float' }]),
        listeners: { exception: storeError }
    });

    function loadMain() {
        mainDs.baseParams.client_id = parseInt(document.getElementById('tiClient').value, 10) || 0;
        mainDs.baseParams.status = document.getElementById('tiStatus').value;
        mainDs.baseParams.doc_status = document.getElementById('tiDoc').value;          // [RELEASE-LAYER]
        mainDs.load();
    }
    document.getElementById('tiGo').onclick = loadMain;

    // ------------------------------------------------------------
    // 2. Grid utama
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: mainHeight, loadMask: true, stripeRows: true,
        // [GRID-WRAP 2026-11-17] forceFit dimatikan: forceFit memaksa semua kolom menyusut agar pas lebar panel,
        // sehingga kolom nominal/invoice terpotong "...". Dengan forceFit:false grid memakai lebar kolom
        // sesuai definisi + scroll horizontal bila ruang tidak cukup -> teks selalu utuh (wrap, bukan potong).
        viewConfig: { forceFit: false, scrollOffset: 0, deferEmptyText: false, emptyText: '<div style="padding:16px">Belum ada invoice. Klik Add untuk membuat invoice, lalu Release untuk menerbitkan dokumen tagihan ke client.</div>' },
        columns: [
            { header: 'No. Invoice', dataIndex: 'inv_no', width: 130 },                              // [GRID-WRAP] 110 -> 130 (INV-2611-00057)
            {                                                                                       // [RELEASE-LAYER] kolom dokumen tagihan
                header: 'Dokumen Tagihan', dataIndex: 'release_no', width: 145,                       // [GRID-WRAP] 150 -> 145 (sudah muat TAG-... + tgl 2 baris)
                renderer: function (v, meta, r) {
                    if (r.get('release_status') === 'released') {
                        return '<span style="font-weight:bold">' + enc(v || '-') + '</span>' +
                               (r.get('release_date') ? '<br><span style="font-size:11px">' + enc(r.get('release_date')) + '</span>' : '');
                    }
                    return '<span style="font-weight:bold">DRAFT</span>';
                }
            },
            { header: 'Tanggal', dataIndex: 'invoice_date', width: 90, align: 'center' },             // [GRID-WRAP] 82 -> 90 (DD/MM/YYYY)
            {
                header: 'Jatuh Tempo', dataIndex: 'due_date', width: 120, align: 'center',            // [GRID-WRAP] 105 -> 120 (overdue "+N hr" ikut muat)
                renderer: function (v, meta, r) {
                    if (!v) return '<span >-</span>';
                    return r.get('is_overdue') ? '<span style="font-weight:bold">' + enc(v) + ' (+' + r.get('days_overdue') + ' hr)</span>' : enc(v);
                }
            },
            { header: 'Client', dataIndex: 'client_name', width: 160, renderer: function (v) { return CellWrap(v || '-'); } },  // [GRID-WRAP] 150 -> 160
            { header: 'Proyek', dataIndex: 'project_name', width: 190, renderer: CellWrap },          // [GRID-WRAP] 180 -> 190
            { header: 'Termin', dataIndex: 'termin_name', width: 130, renderer: CellWrap },           // [GRID-WRAP] 110 -> 130
            { header: 'DPP', dataIndex: 'dpp_amount', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },        // [GRID-WRAP] 105 -> 130 (nominal panjang)
            { header: 'PPN', dataIndex: 'ppn_amount', width: 110, align: 'right', renderer: function (v) { return v > 0 ? fmtAmt(v) : '-'; } },  // [GRID-WRAP] 90 -> 110
            { header: 'Total Tagihan', dataIndex: 'amount', width: 140, align: 'right', renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } },  // [GRID-WRAP] 115 -> 140
            { header: 'Terbayar', dataIndex: 'paid_amount', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },   // [GRID-WRAP] 105 -> 130
            { header: 'Sisa', dataIndex: 'remaining', width: 130, align: 'right', renderer: function (v) { return v > VEPS ? '<b>' + fmtAmt(v) + '</b>' : '-'; } },  // [GRID-WRAP] 105 -> 130
            {
                header: 'Status', dataIndex: 'status', width: 105, align: 'center',                   // [GRID-WRAP] 90 -> 105 (Belum Bayar / Sebagian)
                renderer: function (v) { return '<span style="font-weight:bold">' + enc(v) + '</span>'; }
            }
        ],
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord.id);
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        var rel = dataRecord ? String(dataRecord.release_status || 'draft') : '';                 // [RELEASE-LAYER]
        if (dataRecord && rel === 'draft' && !(dataRecord.paid_amount > VEPS)) {       // hanya DRAFT tanpa pembayaran yang boleh diubah / dihapus
            mainBtnEnable('#editData');
            mainBtnEnable('#deleteData');
        } else {
            mainBtnDisable('#editData');
            mainBtnDisable('#deleteData');
        }
        // [RELEASE-LAYER] tombol release aktif sesuai status dokumen baris terpilih
        var canRel = getFormAccess() >= ACCESS_EDIT;
        if (canRel && dataRecord && rel === 'draft') mainBtnEnable('#tiRelease'); else mainBtnDisable('#tiRelease');
        if (canRel && dataRecord && rel === 'released' && !(dataRecord.paid_amount > VEPS)) mainBtnEnable('#tiUnrelease'); else mainBtnDisable('#tiUnrelease');
        if (canRel) mainBtnEnable('#tiReleaseAll'); else mainBtnDisable('#tiReleaseAll');
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Panel detail (pembayaran yang masuk + aksi)
    // ------------------------------------------------------------
    function loadDetailPanel(id) {
        ajax({ task: 'load_invoice', id: id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="padding:10px">' + enc(res.message || 'Gagal memuat detail invoice.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(res.data);
        }, function () {
            dtlDiv.innerHTML = '<div style="padding:10px">Gagal terhubung ke server.</div>';
            dtlDiv.style.display = 'block';
        });
    }

    function renderDetailPanel(d) {
        var h = d.header, pays = d.payments || [];
        var st = h.remaining <= VEPS ? 'Lunas' : (h.paid_amount > VEPS ? 'Sebagian' : 'Belum Bayar');
        // [RELEASE-LAYER] informasi dokumen tagihan di panel detail
        var relLine = (h.release_status === 'released')
            ? 'Dokumen tagihan: <b>' + enc(h.release_no || '-') + '</b> (' + enc(h.release_date || '-') + ')' + (h.released_by_name ? ' oleh ' + enc(h.released_by_name) : '')
            : '<span style="font-weight:bold">DRAFT</span> <span >- belum diterbitkan ke client (tidak muncul di Pembayaran Termin)</span>';
        var html = '<h3 class="detail-title">Invoice: ' + enc(h.inv_no) + ' <span >(' + st + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px">Client: <b>' + enc(h.client_name || '-') + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Termin: <b>' + enc(h.termin_name) + '</b>' + (h.percentage > 0 ? ' (' + h.percentage + '% kontrak)' : '') +
                   ' &nbsp;|&nbsp; Tanggal: <b>' + enc(h.invoice_date) + '</b> &nbsp;|&nbsp; Jatuh tempo: <b>' + enc(h.due_date || '-') + '</b>' +
                   ' &nbsp;|&nbsp; Faktur pajak: <b>' + (h.ppn_amount > 0 ? (h.faktur_pajak_no ? enc(h.faktur_pajak_no) : '<span >belum diisi</span>') : 'tanpa PPN') + '</b>' +
                   ' &nbsp;|&nbsp; ' + relLine +
                   (h.release_note ? ' &nbsp;|&nbsp; Catatan terbit: <b>' + enc(h.release_note) + '</b>' : '') +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Keterangan</th><th align="right">Jumlah (IDR)</th></tr></thead><tbody>' +
                   '<tr class="data-row"><td>DPP</td><td align="right">' + fmtAmt(h.dpp_amount) + '</td></tr>';
        if (h.ppn_amount > 0) html += '<tr class="data-row"><td>PPN Keluaran ' + h.ppn_rate + '%</td><td align="right">' + fmtAmt(h.ppn_amount) + '</td></tr>';
        html += '<tr class="data-row"><td><b>Total Tagihan</b></td><td align="right" class="subtotal-val"><b>' + fmtAmt(h.amount) + '</b></td></tr>';
        Ext.each(pays, function (p) {
            html += '<tr class="data-row"><td >Pembayaran ' + enc(p.payment_no) + ' (' + enc(p.payment_date) + ')</td><td align="right" >- ' + fmtAmt(p.amount_paid) + '</td></tr>';
        });
        html += '<tr class="data-row"><td><b>Sisa Tagihan</b></td><td align="right" class="subtotal-val"><b>' + fmtAmt(h.remaining) + '</b></td></tr></tbody></table>';

        html += '<div style="margin-top:15px; text-align:right">';
        // [RELEASE-LAYER] aksi release / batal release juga tersedia di panel detail
        if (h.release_status === 'released' && !(h.paid_amount > VEPS)) {
            html += '<button type="button" class="tiActBtn" data-act="unrelease">Batalkan Release</button>';
        } else if (h.release_status !== 'released') {
            html += '<button type="button" class="tiActBtn" data-act="release">Release Tagihan</button>';
        }
        if (h.ppn_amount > 0) html += '<button type="button" class="tiActBtn" data-act="faktur">Faktur Pajak</button>';
        html += '<button type="button" class="tiActBtn" data-act="print">Cetak Invoice</button></div>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var btns = dtlDiv.querySelectorAll('.tiActBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                var act = this.getAttribute('data-act');
                if (act === 'print') printInvoice(h.id);
                else if (act === 'release') doRelease(h.id);
                else if (act === 'unrelease') doUnrelease(h.id);
                else editFaktur(h);
            };
        }
    }

    function printInvoice(id) {
        window.open('../dev/pdf/project.invoice.php?id=' + id, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    }

    // [RELEASE-LAYER] terbitkan / batalkan dokumen tagihan satu invoice
    function doRelease(id) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses.'); return; }
        // [RELEASE-LAYER] catatan penerbitan opsional (maks 200 karakter)
        Ext.Msg.prompt('Release Tagihan', 'Catatan penerbitan (opsional, maks 200 karakter):', function (btn, note) {
            if (btn !== 'ok') return;
            note = String(note || '').trim();
            if (note.length > 200) { showErr('Catatan penerbitan terlalu panjang (maksimal 200 karakter).'); return; }
            ajax({ task: 'release_invoice', id: id, release_note: note }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    mainDs.load();
                    if (dataRecord && dataRecord.id === id) loadDetailPanel(id);
                }
            });
        });
    }
    function doUnrelease(id) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses.'); return; }
        Ext.Msg.confirm('Batalkan Release', 'Kembalikan invoice ini ke DRAFT? Dokumen tagihan ' + enc(dataRecord && dataRecord.release_no || '') +
            ' tidak lagi berlaku dan tagihan hilang dari Pembayaran Termin.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'unrelease_invoice', id: id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) { mainDs.load(); loadDetailPanel(id); }
            });
        });
    }

    // Isi / ubah faktur pajak keluaran
    function editFaktur(h) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses.'); return; }
        var fNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', maxLength: 30, value: h.faktur_pajak_no || '' });
        var fDt = new Ext.form.DateField({
            fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true,
            value: h.faktur_pajak_date ? Date.parseDate(h.faktur_pajak_date, 'Y-m-d') : ''
        });
        // [CHANGED] tombol HTML gaya standar (Ext.Button bawaan tak terlihat di tema gelap)
        var win = new Ext.Window({
            title: 'Faktur Pajak - ' + h.inv_no, width: 420, height: 250, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 120, bodyStyle: 'padding:12px',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: 'PPN Keluaran <b>' + fmtAmt(h.ppn_amount) + '</b> (DPP ' + fmtAmt(h.dpp_amount) +
                                 '). Tanggal faktur menentukan masa pajak di Laporan PPN. Kosongkan nomor untuk menghapus.' }, fNo, fDt]
            }),
            bbar: ['->', { xtype: 'tbtext', text: uiBtn('tiFpSave', 'Simpan', '#066b78') + uiBtn('tiFpCancel', 'Batal', '#800020') }]
        });
        win.show();
        document.getElementById('tiFpCancel').onclick = function () { win.close(); };
        document.getElementById('tiFpSave').onclick = function () {
            var no = fNo.getValue(), dt = fDt.getValue();        // baca nilai sebelum win.close()
            ajax({ task: 'update_faktur_pajak', id: h.id, faktur_pajak_no: no, faktur_pajak_date: ymd(dt) }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) { win.close(); mainDs.load(); loadDetailPanel(h.id); }
            });
        };
    }

    // ------------------------------------------------------------
    // 4. Editor invoice (editorWdw template, seperti PO): form header 2 baris + bar ringkasan
    // ------------------------------------------------------------
    var editing = false;                 // true = ubah invoice, false = invoice baru
    var curRec = null;                   // invoice yang sedang diubah
    var dueManual = false;               // jatuh tempo diubah manual / dimuat dari invoice -> jangan ditimpa TOP
    var hdrForm = null, hdrForm2 = null;
    var fClient, fProject, fName, fDate, fTop, fDue, fPct, fAmt, fPpnOn, fPpnRate, fFpNo, fFpDate, fRemark;

    // Tempat form header + bar ringkasan; grid template (editorFormGrid) tidak dipakai -> disembunyikan
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        var host = g ? g.parentNode : document.getElementById('editorWdw');
        if (!host || document.getElementById('tiHdrForm')) return;

        var hdr = document.createElement('div');
        hdr.id = 'tiHdrForm';
        hdr.style.cssText = 'margin-bottom:2px;';
        var hdr2 = document.createElement('div');
        hdr2.id = 'tiHdrForm2';
        hdr2.style.cssText = 'margin-bottom:8px;';
        var bar = document.createElement('div');
        bar.id = 'tiInfoBar';
        bar.style.cssText = 'padding:10px 14px; margin-top:8px; font-size:13px; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';

        if (g) {
            host.insertBefore(hdr, g); host.insertBefore(hdr2, g); host.insertBefore(bar, g);
            g.style.display = 'none';
        } else {
            host.appendChild(hdr); host.appendChild(hdr2); host.appendChild(bar);
        }
    })();

    function curProject() { return fProject && fProject.getValue() ? projectDs.getById(fProject.getValue()) : null; }
    function taxed() { return fPpnOn.getValue() === '1'; }
    function curRate() { return taxed() ? (parseFloat(fPpnRate.getValue()) || 0) : 0; }
    function syncDue() {
        if (dueManual) return;
        var d = fDate.getValue(), n = parseInt(fTop.getValue(), 10);
        if (d && n >= 0) fDue.setValue(d.add(Date.DAY, n)); else fDue.setValue('');
    }
    function recalcFromPct() {                    // nilai kontrak x persen -> DPP
        var p = curProject(), pct = parseFloat(fPct.getValue()) || 0;
        if (p && p.get('contract_value') > 0 && pct > 0) fAmt.setValue(round2(p.get('contract_value') * pct / 100));
    }
    function refresh() {
        var bar = document.getElementById('tiInfoBar');
        if (!bar) return;
        var p = curProject(), dpp = round2(parseFloat(fAmt.getValue()) || 0), r = curRate(), ppn = round2(dpp * r / 100);
        var msg = '';
        if (p) {
            var cv = p.get('contract_value'), billed = p.get('billed_dpp') - (editing ? (curRec.dpp_amount || 0) : 0);
            msg += '<div style="font-size:12px">Nilai kontrak <b>' + fmtAmt(cv) + '</b> &nbsp;|&nbsp; sudah ditagih (DPP) <b>' + fmtAmt(billed) +
                   '</b> &nbsp;|&nbsp; sisa kontrak <b>' + fmtAmt(Math.max(cv - billed - dpp, 0)) + '</b>';
            if (cv > 0 && billed + dpp > cv + VEPS) msg += ' <span >(melebihi nilai kontrak)</span>';
            msg += '</div>';
        }
        msg += '<div style="margin-top:4px"><b>Total Tagihan: ' + fmtAmt(round2(dpp + ppn)) + '</b> ' +
               '<span style="font-size:12px;font-weight:normal">&nbsp;(DPP ' + fmtAmt(dpp) + ' &nbsp;|&nbsp; PPN ' + r + '% ' + fmtAmt(ppn) + ')</span></div>';
        bar.innerHTML = msg;
    }

    // Form dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureForm() {
        if (hdrForm) { hdrForm.doLayout(); hdrForm2.doLayout(); return; }

        fClient = new myComboField({
            fieldLabel: 'Client', store: clientDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () {                          // proyek hanya milik client terpilih (filterBy: cocok persis, bukan awalan)
                var cid = String(fClient.getValue());
                projectDs.clearFilter();
                projectDs.filterBy(function (r) { return String(r.get('client_id')) === cid; });
                fProject.clearValue(); refresh();
            } }
        });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { recalcFromPct(); refresh(); } }
        });
        fName = new myTextField({ fieldLabel: 'Nama Termin', anchor: '95%', allowBlank: false });
        fDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal Invoice', format: 'd/m/Y', width: 130, allowBlank: false, value: new Date(),
            listeners: { select: function () { syncDue(); }, change: function () { syncDue(); } }
        });
        fTop = new myNumberField({
            fieldLabel: 'TOP (hari)', decimalPrecision: 0, allowNegative: false, width: 70, value: TOP_DEFAULT_DAYS,
            listeners: { change: function () { dueManual = false; syncDue(); } }
        });
        fDue = new Ext.form.DateField({
            fieldLabel: 'Jatuh Tempo', format: 'd/m/Y', width: 130, allowBlank: true,
            listeners: { select: function () { dueManual = true; }, change: function () { dueManual = !!fDue.getValue(); } }
        });
        fPct = new myNumberField({
            fieldLabel: '% dari Kontrak', decimalPrecision: 2, allowNegative: false, width: 90,
            listeners: { change: function () { recalcFromPct(); refresh(); } }
        });
        fAmt = new myNumberField({
            fieldLabel: 'Nilai Invoice (DPP)', decimalPrecision: 2, allowNegative: false, width: 160, value: 0,
            listeners: { change: function () { refresh(); } }
        });
        // [CHANGED] pilihan Ya/Tidak (bukan checkbox: checkbox bawaan tak terlihat di tema gelap)
        fPpnOn = new myComboField({
            fieldLabel: 'Kenakan PPN', store: new Ext.data.ArrayStore({ fields: ['v', 't'], data: [['1', 'Ya'], ['0', 'Tidak']] }),
            displayField: 't', valueField: 'v', mode: 'local', triggerAction: 'all', forceSelection: true, editable: false,
            value: '1', width: 90,
            listeners: { select: function () { fPpnRate.setDisabled(!taxed()); refresh(); } }
        });
        fPpnRate = new myNumberField({
            fieldLabel: 'Tarif PPN (%)', decimalPrecision: 2, width: 90, allowNegative: false, value: PPN_DEFAULT_RATE,
            listeners: { change: function () { refresh(); } }
        });
        fFpNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', emptyText: 'opsional, bisa diisi setelah terbit' });
        fFpDate = new Ext.form.DateField({ fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'tiHdrForm', border: false, layout: 'column',
            bodyStyle: 'padding:4px 2px 0 2px',
            items: [col(0.34, [fClient, fProject, fName]), col(0.33, [fDate, fTop, fDue]), col(0.33, [fPct, fAmt, fPpnOn, fPpnRate])]
        });
        hdrForm2 = new Ext.form.FormPanel({
            renderTo: 'tiHdrForm2', border: false, layout: 'column',
            bodyStyle: 'padding:0 2px 4px 2px',
            items: [col(0.34, [fFpNo]), col(0.33, [fFpDate]), col(0.33, [fRemark])]
        });
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureForm();
    }

    // Isi form (setelah master client & proyek termuat)
    function fillForm(rec) {
        editing = !!rec;
        curRec = rec || null;
        dueManual = editing && !!rec.due_date;
        projectDs.clearFilter();

        fClient.setDisabled(editing);
        fProject.setDisabled(editing);
        if (editing) { fClient.setValue(rec.client_id); fProject.setValue(rec.project_id); }
        else { fClient.clearValue(); fProject.clearValue(); }

        fName.setValue(editing ? rec.termin_name : '');
        fDate.setValue(editing ? Date.parseDate(rec.invoice_date, 'Y-m-d') : new Date());
        fTop.setValue((editing && rec.due_date)
            ? Math.round((Date.parseDate(rec.due_date, 'Y-m-d') - Date.parseDate(rec.invoice_date, 'Y-m-d')) / 86400000) : TOP_DEFAULT_DAYS);
        fDue.setValue(editing && rec.due_date ? Date.parseDate(rec.due_date, 'Y-m-d') : '');
        fPct.setValue(editing && rec.percentage > 0 ? rec.percentage : '');
        fAmt.setValue(editing ? rec.dpp_amount : 0);

        var isTaxed = editing ? rec.ppn_amount > 0 : true;      // invoice baru: default kena PPN
        fPpnOn.setValue(isTaxed ? '1' : '0');
        fPpnRate.setValue(editing && rec.ppn_rate > 0 ? rec.ppn_rate : PPN_DEFAULT_RATE);
        fPpnRate.setDisabled(!isTaxed);

        fFpNo.setValue(editing ? (rec.faktur_pajak_no || '') : '');
        fFpDate.setValue(editing && rec.faktur_pajak_date ? Date.parseDate(rec.faktur_pajak_date, 'Y-m-d') : '');
        fRemark.setValue(editing ? (rec.remark || '') : '');

        if (!editing) syncDue();
        refresh();
    }

    function openEditor(rec) {                        // rec null = invoice baru
        showEditor(rec ? 'Ubah Invoice ' + rec.inv_no : 'Terbitkan Invoice ke Client');
        clientDs.load({ callback: function () { projectDs.load({ callback: function () { fillForm(rec); } }); } });
    }

    // SAVE (tombol template)
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue()) { showErr('Proyek wajib dipilih.'); return; }
        if (!fName.getValue()) { showErr('Nama termin wajib diisi.'); return; }
        if (!fDate.getValue()) { showErr('Tanggal invoice wajib diisi.'); return; }
        if (!(parseFloat(fAmt.getValue()) > 0)) { showErr('Nilai invoice (DPP) harus lebih dari 0.'); return; }
        if (fDue.getValue() && fDue.getValue() < fDate.getValue()) { showErr('Jatuh tempo tidak boleh lebih awal dari tanggal invoice.'); return; }
        if (taxed() && !(curRate() > 0 && curRate() <= 100)) { showErr('Tarif PPN harus lebih dari 0 dan maksimal 100%.'); return; }
        var p = {
            task: 'save_invoice', id: editing ? curRec.id : 0, project_id: fProject.getValue(), client_id: fClient.getValue() || (editing ? curRec.client_id : 0),
            termin_name: fName.getValue(), invoice_date: ymd(fDate.getValue()), due_date: ymd(fDue.getValue()),
            percentage: fPct.getValue(), amount: fAmt.getValue(), ppn_rate: curRate(),
            faktur_pajak_no: fFpNo.getValue(), faktur_pajak_date: ymd(fFpDate.getValue()), remark: fRemark.getValue()
        };
        ajax(p, function (res) {
            if (res.success) {
                document.getElementById('editorWdw').style.display = 'none';
                Ext.Msg.alert('Sukses', res.message || 'Invoice tersimpan.');
                projectDs.load();            // total DPP yang sudah ditagih berubah
                mainDs.load();
                if (res.data && res.data.id) { dataRecord = { id: res.data.id, paid_amount: 0, release_status: 'draft' }; updateMainButtons(); loadDetailPanel(res.data.id); }
            } else {
                showErr(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    // ------------------------------------------------------------
    // 5. Handler tombol template
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menerbitkan invoice.'); return; }
        openEditor(null);
    };
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (dataRecord.paid_amount > VEPS) { Ext.Msg.alert('Peringatan', 'Invoice yang sudah ada pembayaran tidak dapat diubah.'); return; }
        if (String(dataRecord.release_status || 'draft') !== 'draft') { Ext.Msg.alert('Peringatan', 'Invoice sudah diterbitkan; batalkan release terlebih dahulu untuk mengubah.'); return; }
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengubah invoice.'); return; }
        openEditor(dataRecord);
    };
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (dataRecord.paid_amount > VEPS) { Ext.Msg.alert('Peringatan', 'Invoice yang sudah ada pembayaran tidak dapat dihapus.'); return; }
        if (String(dataRecord.release_status || 'draft') !== 'draft') { Ext.Msg.alert('Peringatan', 'Invoice sudah diterbitkan; batalkan release terlebih dahulu sebelum menghapus.'); return; }
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus invoice.'); return; }
        Ext.Msg.confirm('Hapus Invoice', 'Hapus invoice <b>' + enc(dataRecord.inv_no) + '</b>? Jurnal penagihannya ikut dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'delete_invoice', id: dataRecord.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                    projectDs.load();
                    mainDs.load();
                    updateMainButtons();
                }
            });
        });
    };
    document.getElementById('printData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        printInvoice(dataRecord.id);
    };
    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };
    }

    // ------------------------------------------------------------
    // 5b. [RELEASE-LAYER] Tombol release / batal release / release bulk di filter bar
    // ------------------------------------------------------------
    document.getElementById('tiRelease').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (String(dataRecord.release_status || 'draft') === 'released') { showErr('Invoice sudah diterbitkan (' + enc(dataRecord.release_no || '') + ').'); return; }
        if (dataRecord.paid_amount > VEPS) { showErr('Invoice sudah ada pembayaran.'); return; }
        Ext.Msg.confirm('Release Tagihan', 'Terbitkan dokumen tagihan untuk invoice <b>' + enc(dataRecord.inv_no) + '</b>? ' +
            'Jurnal penagihan diposting dan invoice masuk daftar tagihan di Pembayaran Termin.', function (btn) {
            if (btn === 'yes') doRelease(dataRecord.id);
        });
    };
    document.getElementById('tiUnrelease').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (String(dataRecord.release_status || 'draft') !== 'released') { showErr('Invoice belum diterbitkan.'); return; }
        if (dataRecord.paid_amount > VEPS) { showErr('Invoice sudah ada pembayaran; penerbitannya tidak dapat dibatalkan.'); return; }
        doUnrelease(dataRecord.id);
    };
    document.getElementById('tiReleaseAll').onclick = function () {
        var ids = [];
        mainDs.each(function (r) { if (String(r.get('release_status') || 'draft') === 'draft') ids.push(r.get('id')); });
        if (!ids.length) { showErr('Tidak ada invoice DRAFT pada filter aktif.'); return; }
        // [RELEASE-LAYER] catatan penerbitan opsional (maks 200 karakter), diterapkan ke semua invoice yang di-release
        Ext.Msg.prompt('Release Semua (filter aktif)', 'Catatan penerbitan (opsional, maks 200 karakter):', function (btn, note) {
            if (btn !== 'ok') return;
            note = String(note || '').trim();
            if (note.length > 200) { showErr('Catatan penerbitan terlalu panjang (maksimal 200 karakter).'); return; }
            Ext.Msg.confirm('Release Semua (filter aktif)', 'Terbitkan <b>' + ids.length + ' invoice DRAFT</b> yang sesuai filter Client/Status di layar ini? ' +
                'Dokumen tagihan (TAG-...) diterbitkan untuk semuanya dan jurnal penagihan diposting.', function (btn2) {
                if (btn2 !== 'yes') return;
                ajax({ task: 'release_invoices', ids: Ext.util.JSON.encode(ids), release_note: note }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) mainDs.load();
                });
            });
        });
    };

    // ------------------------------------------------------------
    // 6. Data awal
    // ------------------------------------------------------------
    clientDs.load({ callback: function () {
        var sel = document.getElementById('tiClient');
        clientDs.each(function (r) {
            var o = document.createElement('option');
            o.value = r.get('id'); o.text = r.get('name');
            sel.appendChild(o);
        });
    } });
    document.getElementById('editorWdw').style.display = 'none';

    loadMain();
});
