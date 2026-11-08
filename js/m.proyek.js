/*
  Document      : m.proyek.js
  Description   : ExtJS Controller untuk pengelolaan Master Proyek dengan Integrasi AI Task Breakdown
                   - Panel Detail Sub-Task AI di Layar Utama (Main Screen)
                   - Editor Form Responsif & Tinggi Maksimal
  Backend       : /rab-project/php-crud/m.proyekCRD.php
  [AI-BOQ]      : analisis AI -> BoQ lengkap (material + biaya/expense, satuan, qty, harga MIN/MAX); review bisa diedit (task, material, biaya);
                  simpan = buat material master -> RAB -> task. Butuh migrasi sql/2026-11-06.budget_items_price_range.sql
  [AUTO-INV]    : + tagihan otomatis (DP/Termin 1-3 = 30-20-20-30) dari breakdown task; tabel tagihan di panel detail
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = " Data <em>Proyek & Kontrak</em>";
    }

    var CRD = main_url + '/php-crud/m.proyekCRD.php';
    var task = '';
    var dataRecord = null;
    var curRate = 1;
    var fmtRp = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
    // [AI-BOQ] qty tanpa nol di belakang (Ext 3 tidak mendukung pola '#'): 150 -> "150", 1.5 -> "1.5", 1000 -> "1,000"
    function fmtQty3(v) { return Ext.util.Format.number(parseFloat(v) || 0, '0,000.000').replace(/\.?0+$/, ''); }
    // [AI-BOQ] penanda plafon: hasil analisis / RAB lebih besar dari budget atau nilai kontrak (aturan sama dengan modul RAB)
    function plafonBanner() {
        var sm = (mainTaskStore && mainTaskStore.reader && mainTaskStore.reader.jsonData) ? mainTaskStore.reader.jsonData.summary : null;
        if (!sm) return '';
        var nf = function (v) { return Ext.util.Format.number(v || 0, '0,000'); }, en = Ext.util.Format.htmlEncode;
        var col = { danger: ['#3b1212', '#ff6b5e', '#ffb4ab'], warning: ['#3a2c08', '#e6a817', '#ffe08a'], ok: ['#0f2f22', '#2e9e4f', '#a5e8bd'] }[sm.severity] || ['#132330', '#1c3648', '#ffffff'];
        var title = sm.severity === 'ok' ? '&#10003; Anggaran aman' : ('&#9888; ' + (sm.severity === 'danger' ? 'PERINGATAN ANGGARAN' : 'PERHATIAN ANGGARAN'));
        var h = '<div style="background:' + col[0] + ';border:2px solid ' + col[1] + ';border-radius:5px;padding:10px;margin-bottom:12px;font-size:15px;line-height:1.6;">' +
            '<b style="color:' + col[1] + ';">' + title + '</b><br><span style="color:#d5e3ec;">Total RAB Rp <b>' + nf(sm.rab_after_idr) + '</b> | Estimasi seluruh task (max) Rp <b>' + nf(sm.task_total_max_idr) + '</b>' +
            (sm.budget_idr > 0 ? ' | Budget Rp <b>' + nf(sm.budget_idr) + '</b>' : '') + (sm.contract_idr > 0 ? ' | Nilai kontrak Rp <b>' + nf(sm.contract_idr) + '</b>' : '') + '</span>';
        Ext.each(sm.warnings || [], function (w) {
            h += '<div style="color:' + (w.level === 'danger' ? '#ffb4ab' : '#ffe08a') + ';margin-top:3px;">&bull; ' + en(w.text) + '</div>';
        });
        return h + '</div>';
    }
    // [AI-BOQ] rincian item task (M = material, E = biaya) dengan harga satuan MIN - MAX (mata uang anggaran proyek)
    function fmtTaskItems(items, kind) {
        var out = [], nf = function (v) { return Ext.util.Format.number(v || 0, '0,000'); };
        Ext.each(items || [], function (it) {
            if (it.kind !== kind) return;
            var pr = (parseFloat(it.pmin) === parseFloat(it.pmax)) ? nf(it.pmax) : nf(it.pmin) + ' - ' + nf(it.pmax);
            out.push('<div style="margin-bottom:6px;"><b>' + Ext.util.Format.htmlEncode(it.name) + '</b>: ' + fmtQty3(it.qty) + ' ' +
                     Ext.util.Format.htmlEncode(it.unit) + '<div style="color:#8fb3c0;font-size:13px;">@ ' + pr + ' / ' + Ext.util.Format.htmlEncode(it.unit) + (parseFloat(it.pmin) === parseFloat(it.pmax) ? ' (rentang master belum diisi)' : ' (min - max)') + '</div></div>');
        });
        return out.length ? out.join('') : '-';
    }

    // [AUTO-INV][NEW] tabel rencana / hasil tagihan (dipakai dialog preview & hasil breakdown)
    var ACTION_LBL = { 'new': 'Akan diterbitkan', 'sync': 'Sesuaikan tanggal', 'exists': 'Sudah ada', 'skip': 'Dilewati' };
    function planHtml(rows, analysis) {
        var td = 'padding:7px 10px; border-bottom:1px solid #2a485d; vertical-align:top;';
        var nw = td + 'white-space:nowrap;';
        var h = '';
        if (analysis) h += '<div style="margin-bottom:12px; text-align:left; line-height:20px;"><b>Analisis AI:</b> ' + Ext.util.Format.htmlEncode(analysis) + '</div>';
        h += '<table style="border-collapse:collapse; font-size:15px; width:100%; text-align:left; table-layout:auto;">' +
             '<tr style="font-weight:bold; color:#00bcd4;">' +
             '<td style="' + nw + '">Tagihan</td><td align="right" style="' + nw + '">%</td><td align="right" style="' + nw + '">Total (DPP+PPN)</td>' +
             '<td style="' + nw + '">Tgl Invoice</td><td style="' + nw + '">Jatuh Tempo</td><td style="' + td + '">Task Pemicu</td><td style="' + nw + '">Aksi</td></tr>';
        Ext.each(rows || [], function (r) {
            h += '<tr><td style="' + nw + '">' + Ext.util.Format.htmlEncode(r.termin_name) + '</td>' +
                 '<td align="right" style="' + nw + '">' + (r.pct || 0) + '</td>' +
                 '<td align="right" style="' + nw + '">' + fmtRp(r.amount) + '</td>' +
                 '<td style="' + nw + '">' + Ext.util.Format.htmlEncode(r.invoice_date || '-') + '</td>' +
                 '<td style="' + nw + '">' + Ext.util.Format.htmlEncode(r.due_date || '-') + '</td>' +
                 '<td style="' + td + '">' + Ext.util.Format.htmlEncode(r.task_name || '-') + '</td>' +
                 '<td style="' + nw + '">' + (ACTION_LBL[r.action] || '') + '</td></tr>';
        });
        return h + '</table>';
    }

    // [AUTO-INV][NEW] window lebar + scroll (Ext.Msg terlalu sempit untuk tabel). onYes kosong -> hanya tombol Tutup.
    // Tombol footer memakai <button> HTML bergaya sama dengan "Print Task" (tombol Ext bawaan tidak terbaca di tema gelap).
    var planWinSeq = 0;
    // [DEDUP] penjaga klik ganda: analisis sedang jalan / jendela hasil analisis masih terbuka / simpan sedang jalan
    var aiBusy = false, aiPlanOpen = false, aiSaving = false;
    function setAiBtn(disabled) {
        var ids = ['customAiBtn', 'customEditBtn'];                  // [EDIT-SAVED] kedua tombol dikunci bersama
        for (var i = 0; i < ids.length; i++) {
            var b = document.getElementById(ids[i]);
            if (!b) continue;
            b.disabled = !!disabled;
            b.style.opacity = disabled ? '0.45' : '1';
            b.style.cursor = disabled ? 'not-allowed' : 'pointer';
        }
    }
    function showPlanWin(title, html, yesText, onYes, onReady, keepOpen) {      // [AI-BOQ] keepOpen: jangan tutup saat Simpan (draft tetap ada bila gagal)
        var w = Math.min(1500, Math.round((window.innerWidth || 1200) * 0.97));      // [UI-READABLE] jendela lebih lebar & tinggi agar kartu tidak sempit
        var h = Math.min(950, Math.round((window.innerHeight || 800) * 0.94));
        var uid = 'planWin' + (++planWinSeq);
        var btnBase = 'padding:11px 28px; color:#fff; border:0; border-radius:5px; cursor:pointer; font-weight:bold; font-size:15px; margin-left:12px;';   // [UI-READABLE] tombol lebih besar
        var btnHtml = '<div style="text-align:right; padding:6px 4px;">' +
            (onYes ? '<button type="button" id="' + uid + 'No" style="' + btnBase + 'background:#800020;">Batal</button>' +        // burgundy
                     '<button type="button" id="' + uid + 'Yes" style="' + btnBase + 'background:#066b78;">' + Ext.util.Format.htmlEncode(yesText || 'Ya') + '</button>'   // deep teal
                   : '<button type="button" id="' + uid + 'No" style="' + btnBase + 'background:#066b78;">Tutup</button>') +
            '</div>';
        if (!document.getElementById('aiPlanWinCss')) {      // [UI-READABLE] judul jendela 20px (default Ext 11px terlalu kecil)
            Ext.util.CSS.createStyleSheet('.aiPlanWin .x-window-header-text{font-size:20px !important;font-weight:bold;line-height:30px;} ' +
                                          '.aiPlanWin .x-window-header{padding-top:8px;padding-bottom:6px;} ' +
                                          '.aiPlanWin .x-tool{margin-top:6px;}', 'aiPlanWinCss');
        }
        var win = new Ext.Window({
            cls: 'aiPlanWin', title: title, width: w, height: h, modal: true, layout: 'auto', autoScroll: true, closeAction: 'close',
            bodyStyle: 'padding:14px;', html: html,
            bbar: new Ext.Toolbar({
                style: 'background:transparent; border:0; padding:6px 10px;',
                items: ['->', { xtype: 'box', autoEl: { tag: 'div', html: btnHtml } }]
            })
        });
        win.show();
        if (onReady) onReady(win, uid);
        var no = document.getElementById(uid + 'No'), yes = document.getElementById(uid + 'Yes');
        if (no) no.onclick = function () { win.close(); };
        if (yes) yes.onclick = function () { if (keepOpen) { onYes(win); } else { win.close(); onYes(); } };
    }

    function storeException(proxy, type, action, options, response) {
        var msg = 'Gagal memuat data dari server.';
        try {
            var r = Ext.util.JSON.decode(response.responseText);
            if (r && r.message) msg = r.message;
        } catch (e) { msg += ' (respons bukan JSON, cek error_log PHP)'; }
        Ext.Msg.alert('Gagal', msg);
    }

    // Perhitungan tinggi layar responsif
    var screenH = window.innerHeight || document.documentElement.clientHeight || 700;
    var mainGridH = Math.round(screenH * 0.5);
    var mainDetailH = Math.round(screenH * 0.40);
    var editorGridH = 220; // Tinggi tetap untuk grid task di dalam editor agar muat dan bisa digeser

    // 1. Stores
    var clientDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_clients' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    clientDs.load();

    var picDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_pics' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    picDs.load();

    var locationDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_locations' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ]),
        listeners: { exception: storeException }
    });
    locationDs.load();

    var statusDs = new Ext.data.SimpleStore({
        fields: ['id', 'name'],
        data: [
            ['Belum Mulai', 'Belum Mulai'],
            ['Berjalan', 'Berjalan'],
            ['Tertunda', 'Tertunda'],
            ['Selesai', 'Selesai']
        ]
    });

    var mainFields = [
        { name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'client_name' },
        { name: 'pic_id' }, { name: 'pic_name' }, { name: 'location_id' }, { name: 'location_name' }, { name: 'location' },
        { name: 'start_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'end_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'budget', type: 'float' }, { name: 'contract_value', type: 'float' },
        { name: 'spent', type: 'float' }, { name: 'project_scope' },
        { name: 'budget_exch_rate', type: 'float' }, { name: 'budget_currency_code' },
        { name: 'progress', type: 'int' }, { name: 'status' }, { name: 'notes' }
    ];

    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, mainFields),
        listeners: { exception: storeException }
    });
    mainDs.load();

    // 2. Main Grid
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainGridH,
        columns: [
            { header: 'Nama Proyek', dataIndex: 'name', width: 180, renderer: CellWrap },
            { header: 'Klien', dataIndex: 'client_name', width: 140, renderer: CellWrap },
            { header: 'PIC / PM', dataIndex: 'pic_name', width: 120, renderer: CellWrap },
            { header: 'Lokasi', dataIndex: 'location_name', width: 120, renderer: CellWrap },
            { header: 'Tgl Mulai', dataIndex: 'start_date', width: 90, renderer: Ext.util.Format.dateRenderer('d/m/Y') },
            { header: 'Tgl Selesai', dataIndex: 'end_date', width: 90, renderer: Ext.util.Format.dateRenderer('d/m/Y') },
            {
                header: 'Budget', dataIndex: 'budget', width: 120, align: 'right',
                renderer: function (v, meta, rec) { return (rec.get('budget_currency_code') || 'IDR') + ' ' + fmtRp(v); }
            },
            {
                header: 'Nilai Kontrak (Plafon)', dataIndex: 'contract_value', width: 130, align: 'right',
                renderer: function (v) { return 'Rp ' + fmtRp(v); }
            },
            { header: 'Progress (%)', dataIndex: 'progress', width: 80, align: 'center', renderer: function(v){ return (v || 0) + '%'; } },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center' }
        ],
        stripeRows : true,
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                    loadMainDetailPanel(dataRecord.id);
                }
            }
        }
    });

    // Printing seluruh daftar proyek (toolbar kanan atas).
    var btnPrintProjects = document.getElementById('printData');
    if (btnPrintProjects) btnPrintProjects.onclick = function (event) {
        if (event && event.preventDefault) event.preventDefault();
        var w = window.open('/rab-project/dev/views/m.proyek.print.php?mode=projects', '_blank', 'width=1100,height=800,scrollbars=yes,resizable=yes');
        if (!w) Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Printing kembali.');
    };

    // ------------------------------------------------------------
    // 3. Main Screen Detail Panel (Untuk AI Sub-Task di Layar Utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var mainDtlDiv = document.createElement('div');
    mainDtlDiv.id = 'projMainDtlContainer';
    mainDtlDiv.style.display = 'none';
    mainDtlDiv.style.height = mainDetailH + 'px';
    mainDtlDiv.style.maxHeight = mainDetailH + 'px';
    mainDtlDiv.style.overflowY = 'auto';
    mainDtlDiv.style.marginTop = '15px';
    mainDtlDiv.style.padding = '14px';
    mainDtlDiv.style.background = '#0d1821';
    mainDtlDiv.style.border = '1px solid #1f3a4b';
    mainDtlDiv.style.borderRadius = '6px';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(mainDtlDiv, gridContainer.nextSibling);
    }

    var mainTaskStore = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_tasks' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'task_name' }, { name: 'description' }, { name: 'materials' }, { name: 'expenses' }, { name: 'items' },
            { name: 'total_max', type: 'float' }, { name: 'total_min', type: 'float' },
            { name: 'start_date' }, { name: 'end_date' }, { name: 'status' }
        ])
    });

    function loadMainDetailPanel(projectId) {
        mainTaskStore.load({
            params: { project_id: projectId },
            callback: function (records) {
                // [AUTO-INV] muat tagihan proyek, lalu render task + tagihan sekaligus
                Ext.Ajax.request({
                    url: CRD, method: 'POST', params: { task: 'read_invoices', project_id: projectId },
                    success: function (resp) {
                        var o = {}; try { o = Ext.util.JSON.decode(resp.responseText); } catch (e) {}
                        renderMainDetailPanel(records, o.data || []);
                    },
                    failure: function () { renderMainDetailPanel(records, []); }
                });
            }
        });
    }

    function renderMainDetailPanel(records, invoices) {
        if (!dataRecord) return;
        var scopeTxt = dataRecord.project_scope || 'Belum ada spesifikasi teknis.';
        
        var html = '<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px;">' +
                   '<h3 style="margin:0; font-size:18px; color:#00bcd4;">Detail & Sub-Task Proyek AI: ' + Ext.util.Format.htmlEncode(dataRecord.name) + '</h3>' +
                   '<span style="color:#a1c4d0; font-size:14px; white-space:nowrap;">Poin Kontrak: <b>Rp ' + fmtRp(dataRecord.contract_value) + '</b></span>' +
                   '</div>' +
                   '<div style="text-align:left; margin:0 0 12px 0;">' +
                   '<button type="button" id="btnPrintProjectTasks" style="padding:7px 14px; background:#087f8c; color:#fff; border:0; border-radius:4px; cursor:pointer; font-weight:bold;">Print Task</button>' +
                   '</div>' +
                   '<div style="background:#132330; padding:10px; border-radius:4px; margin-bottom:12px; border:1px solid #1c3648;">' +
                   '<b style="color:#00bcd4; font-size:15px;">Definisi / Lingkup Proyek:</b>' +
                   '<div style="color:#ffffff; font-size:15px; margin-top:4px; line-height:18px;">' + Ext.util.Format.htmlEncode(scopeTxt) + '</div>' +
                   '</div>' +
                   plafonBanner() +
                   '<table width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#fff; font-size:15px;">' +
                   '<thead><tr style="background:#1a2f40; color:#00bcd4; text-align:left; font-weight:bold;">' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Nama Sub-Task (WBS)</th>' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Deskripsi Pekerjaan</th>' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Material Task</th>' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Biaya / Jasa Task</th>' +
                   '<th align="right" style="border-bottom:1px solid #2a485d; padding:8px;">Estimasi (Max)</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Tgl Mulai</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Tgl Selesai</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Status</th>' +
                   '</tr></thead><tbody>';

        if (!records || !records.length) {
            html += '<tr><td colspan="8" style="padding:15px; text-align:center; color:#688998;">Belum ada analisis sub-task AI untuk proyek ini. Klik <b>Edit</b> untuk membuat rincian task.</td></tr>';
        } else {
            Ext.each(records, function (r) {
                var d = r.data;
                html += '<tr style="border-bottom:1px solid #182e3e;">' +
                        '<td style="font-weight:bold; color:#e0f7fa;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.task_name) + '</td>' +
                        '<td style="color:#b0bec5;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.description) + '</td>' +
                        '<td style="color:#80deea;padding:12px 8px;font-size:14px;">' + fmtTaskItems(d.items, 'M') + '</td>' +
                        '<td style="color:#ffcc80;padding:12px 8px;font-size:14px;">' + fmtTaskItems(d.items, 'E') + '</td>' +
                        '<td align="right" style="color:#e0f7fa;padding:12px 8px;white-space:nowrap;">' + Ext.util.Format.number(d.total_max || 0, '0,000') +
                        '<div style="color:#8fb3c0;font-size:13px;">min ' + Ext.util.Format.number(d.total_min || 0, '0,000') + '</div></td>' +
                        '<td align="center" style="color:#80deea;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.start_date) + '</td>' +
                        '<td align="center" style="color:#80deea;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.end_date) + '</td>' +
                        '<td align="center"><span style="background:#004d40; color:#80cbc4; padding:2px 8px; border-radius:3px; font-size:13px;">' + Ext.util.Format.htmlEncode(d.status) + '</span></td>' +
                        '</tr>';
            });
        }
        html += '</tbody></table>';

        // [AUTO-INV][NEW] tabel tagihan ke klien
        html += '<h3 style="margin:16px 0 8px 0; font-size:17px; color:#00bcd4;">Tagihan ke Klien</h3>' +
                '<table width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#fff; font-size:15px;">' +
                '<thead><tr style="background:#1a2f40; color:#00bcd4; text-align:left; font-weight:bold;">' +
                '<th style="padding:8px;">No. Invoice</th><th style="padding:8px;">Termin</th>' +
                '<th align="center" style="padding:8px;">Tgl Invoice</th><th align="center" style="padding:8px;">Jatuh Tempo</th>' +
                '<th align="right" style="padding:8px;">Nilai</th><th align="right" style="padding:8px;">Terbayar</th>' +
                '<th align="center" style="padding:8px;">Status</th><th style="padding:8px;">Task Pemicu</th></tr></thead><tbody>';
        if (!invoices || !invoices.length) {
            html += '<tr><td colspan="8" style="padding:15px; text-align:center; color:#688998;">Belum ada tagihan. Jalankan <b>ANALISIS & BAGI TASK (AI)</b> atau <b>TERBITKAN TAGIHAN OTOMATIS</b> di jendela Edit.</td></tr>';
        } else {
            Ext.each(invoices, function (iv) {
                var amt = parseFloat(iv.amount) || 0, pd = parseFloat(iv.paid_amount) || 0;
                var st = pd >= amt - 0.005 ? ['Lunas', '#80cbc4'] : (pd > 0 ? ['Sebagian', '#ffd54f'] : ['Belum bayar', '#ff8a80']);
                html += '<tr style="border-bottom:1px solid #182e3e;">' +
                        '<td style="padding:8px;">' + Ext.util.Format.htmlEncode(iv.inv_no) + (iv.auto_generated ? ' <span style="color:#00bcd4;font-size:13px;">AUTO</span>' : '') + '</td>' +
                        '<td style="padding:8px;">' + Ext.util.Format.htmlEncode(iv.termin_name) + '</td>' +
                        '<td align="center" style="padding:8px;">' + Ext.util.Format.htmlEncode(iv.invoice_date || '-') + '</td>' +
                        '<td align="center" style="padding:8px;">' + Ext.util.Format.htmlEncode(iv.due_date || '-') + '</td>' +
                        '<td align="right" style="padding:8px;">' + fmtRp(amt) + '</td>' +
                        '<td align="right" style="padding:8px;">' + fmtRp(pd) + '</td>' +
                        '<td align="center" style="padding:8px; color:' + st[1] + ';">' + st[0] + '</td>' +
                        '<td style="padding:8px; color:#b0bec5;">' + Ext.util.Format.htmlEncode(iv.task_name || '-') + '</td></tr>';
            });
        }
        html += '</tbody></table>';

        mainDtlDiv.innerHTML = html;
        mainDtlDiv.style.display = 'block';
        var btnPrintTasks = document.getElementById('btnPrintProjectTasks');
        if (btnPrintTasks) btnPrintTasks.onclick = function () {
            var w = window.open('/rab-project/dev/views/m.proyek.print.php?mode=tasks&project_id=' + encodeURIComponent(dataRecord.id), '_blank', 'width=1100,height=800,scrollbars=yes,resizable=yes');
            if (!w) Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Print Task kembali.');
        };
    }

    // ------------------------------------------------------------
    // 4. Stores & Editor Task Grid (Di Dalam Window Edit)
    // ------------------------------------------------------------
    var editorTaskStore = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_tasks' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'task_name' }, { name: 'description' }, { name: 'materials' }, { name: 'expenses' }, { name: 'items' },
            { name: 'total_max', type: 'float' }, { name: 'total_min', type: 'float' },
            { name: 'start_date' }, { name: 'end_date' }, { name: 'status' }
        ])
    });

    var editorTaskGrid = new Ext.grid.GridPanel({
        store: editorTaskStore,
        height: editorGridH,
        title: 'Rincian Sub-Task Proyek oleh AI (Berdasarkan Definisi & Rentang Tanggal)',
        columns: [
            { header: 'Nama Task', dataIndex: 'task_name', width: 160 },
            { header: 'Deskripsi', dataIndex: 'description', width: 220 },
            { header: 'Material RAB per Task', dataIndex: 'materials', width: 220, renderer: CellWrap },
            { header: 'Biaya / Jasa per Task', dataIndex: 'expenses', width: 200, renderer: CellWrap },
            { header: 'Est. Max', dataIndex: 'total_max', width: 100, align: 'right', renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000'); } },
            { header: 'Tgl Mulai', dataIndex: 'start_date', width: 90, align: 'center' },
            { header: 'Tgl Selesai', dataIndex: 'end_date', width: 90, align: 'center' },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center' }
        ],
        viewConfig: { forceFit: true }
    });

    var btnAiPanel = new Ext.Panel({
        border: false,
        bodyStyle: 'background:transparent; padding:10px 0;',
        html: '<div style="text-align: left;"><button type="button" id="customAiBtn" style="background:transparent; border:1px solid #00bcd4; color:#00bcd4; padding:8px 24px; border-radius:20px; font-weight:bold; cursor:pointer; font-size:14px; letter-spacing:1px; transition:all 0.3s;">ANALISIS & BAGI TASK (AI)</button>' +
              // [EDIT-SAVED][NEW] edit task / material yang sudah tersimpan tanpa analisis ulang
              ' <button type="button" id="customEditBtn" style="background:transparent; border:1px solid #80cbc4; color:#80cbc4; padding:8px 24px; border-radius:20px; font-weight:bold; cursor:pointer; font-size:14px; letter-spacing:1px; margin-left:8px;">EDIT TASK & MATERIAL</button>' +
              // [AUTO-INV][NEW] tombol terbitkan tagihan otomatis (analisis AI + preview + terbitkan)
              ' <button type="button" id="customInvBtn" style="background:transparent; border:1px solid #ffd54f; color:#ffd54f; padding:8px 24px; border-radius:20px; font-weight:bold; cursor:pointer; font-size:14px; letter-spacing:1px; margin-left:8px;">TERBITKAN TAGIHAN OTOMATIS</button></div>',
        listeners: {
            afterrender: function() {
                // [EDIT-SAVED] Jendela review dipakai bersama: hasil AI (editMode=false) dan edit task TERSIMPAN tanpa AI (editMode=true)
                function openReview(res, editMode) {
                    var projId = Ext.getCmp('id').getValue();
                    var loadedIds = res.loaded_task_ids || [];            // task asal (mode edit): server hanya menghapus task yang ada di daftar ini
                            var plan = res.data;
                            var availableMaterials = res.available_materials || [];
                            var availableExpenses = res.available_expenses || [];
                            var projRange = res.project_range || {};
                            var meta = res;                        // angka total + peringatan dari server (diperbarui tiap Hitung Ulang)
                            var planWin = null;
                            var existing = res.existing_tasks || { total: 0, replaceable: 0, kept: 0 };
                            var saveMode = 'merge';        // [DEDUP] tidak ada pilihan ganti/tambah lagi: server selalu cek 'hampir sama' -> timpa, bila belum ada -> simpan baru

                            // ================================================================
                            // [AI-BOQ][REWORK] Review & EDIT draft sebelum disimpan:
                            //   Task    : ubah nama / deskripsi / tanggal, tambah, hapus
                            //   Material: ubah qty, harga min/max, tambah dari RAB atau buat baru, hapus
                            //   Biaya   : (expense, non-material) sama seperti material
                            //   Total memakai harga MAX; status RAB/master/baru dicek server (tombol Hitung Ulang) dan saat simpan.
                            // ================================================================
                            var enc = Ext.util.Format.htmlEncode;
                            var fmtIdr = function (v) { return Ext.util.Format.number(v || 0, '0,000'); };
                            var num = function (v) { return parseFloat(v) || 0; };
                            var TEAL = '#066b78', BURG = '#800020', SLATE = '#37566a';
                            // [UI-READABLE] Font minimum 13px, isian 15px, tinggi kontrol 38px. Setiap baris = KARTU dengan label jelas
                            // (bukan tabel 8 kolom yang sempit); task bisa dibuka / ditutup; ringkasan total menempel di atas (sticky).
                            var IN = 'padding:7px 10px;font-size:15px;height:38px;background:#061522;color:#fff;border:1px solid #52758b;border-radius:4px;box-sizing:border-box;';
                            var LBL = 'display:block;font-size:13px;font-weight:bold;color:#9ec3d1;margin-bottom:4px;';
                            var openTask = { 0: true }, openAdd = {}, openHelp = false;      // [UI-READABLE] status buka/tutup, bertahan saat redraw
                            var STATUS_LBL = {
                                'rab':       ['Sudah di RAB',     '#80cbc4', 'Item sudah ada di RAB proyek, dipakai ulang (nama, satuan, harga mengikuti RAB / master).'],
                                'rab_topup': ['Qty RAB ditambah', '#ffd54f', 'Item sudah ada di RAB, tetapi qty total task melebihi qty RAB sehingga qty RAB akan ditambah.'],
                                'master':    ['Masuk RAB',        '#4fc3f7', 'Item sudah ada di master, belum di RAB: akan dimasukkan ke RAB (harga mengikuti master).'],
                                'new':       ['Baru (dibuat)',    '#ffb74d', 'Belum ada: akan dibuat di master (lengkap harga min-max) lalu dimasukkan ke RAB.']
                            };
                            var KIND = {
                                materials: { title: 'Material', ph: 'Nama material baru', btnNew: 'Tambah Material Baru', rabLbl: 'Ambil dari RAB yang sudah ada', empty: 'Belum ada material.', addLbl: '+ Tambah Material' },
                                expenses:  { title: 'Biaya / Jasa (Expense)', ph: 'Nama biaya baru (mis. Jasa ereksi baja)', btnNew: 'Tambah Biaya Baru', rabLbl: 'Ambil dari RAB yang sudah ada', empty: 'Belum ada biaya.', addLbl: '+ Tambah Biaya / Jasa' }
                            };
                            var KINDS = ['materials', 'expenses'];

                            function isFixed(m) { return m.status === 'rab' || m.status === 'rab_topup' || m.status === 'master'; }       // nama, satuan & harga mengikuti RAB / master (min-max = milik master)
                            function pMax(m) { return Math.max(num(m.price_max) || num(m.unit_price), num(m.price_min)); }
                            function pMin(m) { var a = num(m.price_min), b = pMax(m); return (a > 0 && a < b) ? a : b; }
                            function lnMax(m) { return num(m.qty_estimate) * pMax(m); }
                            function lnMin(m) { return num(m.qty_estimate) * pMin(m); }
                            function ymd(d) { function p(n) { return (n < 10 ? '0' : '') + n; } return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }

                            function calcTotals() {
                                var t = { max: 0, min: 0, mat: 0, exp: 0, lines: 0, newCount: 0 }, news = {};
                                Ext.each(plan.tasks, function (task) {
                                    Ext.each(KINDS, function (kind) {
                                        Ext.each(task[kind] || [], function (m) {
                                            t.lines++; t.max += lnMax(m); t.min += lnMin(m);
                                            if (kind === 'materials') { t.mat += lnMax(m); if (m.status === 'new') news[String(m.material_name).toLowerCase()] = true; }
                                            else { t.exp += lnMax(m); }
                                        });
                                    });
                                });
                                for (var k in news) { t.newCount++; }
                                return t;
                            }
                            function taskSub(task, kind) {
                                var s = [0, 0];
                                Ext.each(task[kind] || [], function (m) { s[0] += lnMax(m); s[1] += lnMin(m); });
                                return s;
                            }
                            function taskTotalMax(task) { return taskSub(task, 'materials')[0] + taskSub(task, 'expenses')[0]; }
                            function totCell(m) { return '<b style="font-size:17px;color:#fff;">' + fmtIdr(lnMax(m)) + '</b><div style="color:#9ec3d1;font-size:13px;">min ' + fmtIdr(lnMin(m)) + '</div>'; }
                            function subCell(s) { return '<b style="font-size:16px;color:#fff;">' + fmtIdr(s[0]) + '</b> <span style="color:#9ec3d1;font-size:13px;">(min ' + fmtIdr(s[1]) + ')</span>'; }

                            // ---------- komponen kecil ----------
                            function btn(cls, ti, kind, label, bg) {
                                return '<button type="button" class="' + cls + '" data-task="' + ti + '"' + (kind ? ' data-kind="' + kind + '"' : '') +
                                    ' style="padding:9px 18px;background:' + bg + ';color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:bold;font-size:14px;">' + label + '</button>';
                            }
                            function chip(text, color, bg) {
                                return '<span style="display:inline-block;padding:3px 11px;border-radius:13px;font-size:13px;font-weight:bold;color:' + color + ';background:' + (bg || 'transparent') + ';border:1px solid ' + color + ';white-space:nowrap;">' + text + '</span>';
                            }
                            function fld(label, inner, wrapStyle) { return '<div style="' + (wrapStyle || '') + '"><span style="' + LBL + '">' + label + '</span>' + inner + '</div>'; }
                            function roBox(txt, w, right) {
                                return '<div style="' + IN + 'width:' + w + ';background:#0a1a27;border-color:#2c4a5e;color:#e0f7fa;display:flex;align-items:center;' + (right ? 'justify-content:flex-end;' : '') + '">' + txt + '</div>';
                            }
                            function mfld(ti, kind, li, field, val, w, type, ph) {
                                return '<input type="' + (type || 'text') + '" class="aiEdit" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" data-field="' + field + '" value="' +
                                    enc(val === null || val === undefined ? '' : val) + '"' + (type === 'number' ? ' min="0" step="any" inputmode="decimal"' : '') + (ph ? ' placeholder="' + enc(ph) + '"' : '') +
                                    ' style="' + ING + 'width:' + w + ';' + (type === 'number' ? 'text-align:right;' : '') + '">';
                            }
                            function tfld(ti, field, val, style, type, ph) {
                                return '<input type="' + (type || 'text') + '" class="aiTaskEdit" data-task="' + ti + '" data-field="' + field + '" value="' + enc(val || '') + '"' +
                                    (ph ? ' placeholder="' + enc(ph) + '"' : '') + ' style="' + IN + style + '">';
                            }
                            function nfld(id, ti, kind, f, ph, w, type) {          // isian form "buat baru"
                                return '<input id="aiNew_' + id + '_' + f + '" class="aiNewIn" data-task="' + ti + '" data-kind="' + kind + '" type="' + (type || 'text') + '"' +
                                    (type === 'number' ? ' min="0" step="any" inputmode="decimal"' : '') + ' placeholder="' + enc(ph) + '" style="' + ING + 'width:' + w + ';' + (type === 'number' ? 'text-align:right;' : '') + '">';
                            }
                            // [DEDUP] Info hasil cek 'hampir sama' (tanpa pilihan manual): task yang namanya mirip task lama akan DITIMPA, sisanya disimpan baru
                            function modeHtml() {
                                if (editMode) {          // [EDIT-SAVED]
                                    return '<div style="background:#0b2a2a;border:1px solid #80cbc4;border-radius:8px;padding:14px;margin-bottom:14px;font-size:15px;color:#e6f2f7;line-height:1.7;">' +
                                        '<b style="color:#80cbc4;">MODE EDIT &mdash; data yang SUDAH TERSIMPAN (tanpa analisis AI).</b><br>' +
                                        'Perubahan baru berlaku setelah klik <b>Simpan Perubahan</b>. <b>Hapus</b> task / baris hanya diizinkan bila belum dipakai SPK, PO, kontrak subkon atau issue gudang; ' +
                                        'yang bertanda &#128274; <b>Terkunci</b> tidak bisa dihapus (qty tetap bisa diubah). Item RAB yang tidak lagi dipakai task mana pun ikut dihapus dari RAB.<br>' +
                                        '<span style="color:#b0cad6;font-size:14px;">Nama, satuan dan harga item yang sudah di RAB mengikuti RAB / master (ubah harga di modul RAB). Qty RAB hanya dinaikkan bila total alokasi melebihi qty RAB; tidak pernah diturunkan otomatis.</span></div>';
                                }
                                if (!existing.total) return '';
                                var tm = meta.task_matches || [], ov = tm.length, nw = Math.max(0, plan.tasks.length - ov);
                                var h = '<div style="background:#0b1b28;border:1px solid #52758b;border-radius:8px;padding:14px;margin-bottom:14px;font-size:15px;color:#e6f2f7;line-height:1.7;">' +
                                    '<b>Proyek ini sudah punya ' + existing.total + ' task.</b> Saat disimpan, setiap task, material dan biaya dicek dulu: ' +
                                    'bila namanya <b>hampir sama</b> dengan data lama maka data lama <b>ditimpa</b> / dipakai ulang (tidak dobel); bila belum ada maka <b>disimpan baru</b>.<br>' +
                                    '<b>Task: <span style="color:#ffb74d;">' + ov + ' akan menimpa task lama</span>, <span style="color:#7fe0a0;">' + nw + ' disimpan baru</span>.</b>';
                                if (ov) {
                                    h += '<div style="margin-top:6px;color:#c4dde8;font-size:14px;">';
                                    Ext.each(tm, function (x) { h += '&bull; ' + enc(x.task_name) + ' &rarr; menimpa <b>' + enc(x.match_task_name) + '</b> (kemiripan ' + Math.round(x.score * 100) + '%)<br>'; });
                                    h += '</div>';
                                }
                                h += '<div style="margin-top:6px;color:#b0cad6;font-size:14px;">Task lama yang sudah berjalan hanya diperbarui deskripsi &amp; alokasinya (nama / tanggal tidak diubah). Klik <b>Hitung Ulang</b> setelah mengubah nama task agar daftar ini diperbarui.</div>';
                                return h + '</div>';
                            }
                            var EXP_CATS = ['Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'];     // = kategori biaya di modul RAB
                            function catSelect(id, ti, kind, li, val, extraStyle) {
                                var h = '<select ' + (id ? 'id="' + id + '" ' : 'class="aiEdit" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" data-field="category" ') +
                                        'style="' + IN + (extraStyle || '') + '">';
                                Ext.each(EXP_CATS, function (c) { h += '<option value="' + c + '"' + (c === (val || 'Lain-lain') ? ' selected="selected"' : '') + '>' + c + '</option>'; });
                                return h + '</select>';
                            }

                            // ---------- bar ringkasan yang menempel di atas (selalu terlihat saat menggulir) ----------
                            function stickyBar() {
                                var t = calcTotals(), sev = meta.severity || 'ok';
                                var C = { danger: ['#ff6b5e', 'Melebihi plafon / budget'], warning: ['#e6a817', 'Perhatian anggaran'], ok: ['#2e9e4f', 'Anggaran aman'] }[sev] || ['#2e9e4f', 'Anggaran aman'];
                                var stat = function (label, valHtml) { return '<div><span style="display:block;font-size:13px;color:#9ec3d1;">' + label + '</span>' + valHtml + '</div>'; };
                                return '<div id="aiSticky" style="position:sticky;top:-14px;z-index:6;background:#0b1b28;border:1px solid #3b6078;border-radius:8px;padding:12px 16px;margin:0 0 14px 0;box-shadow:0 4px 12px rgba(0,0,0,.5);">' +
                                    '<div style="display:flex;flex-wrap:wrap;gap:22px;align-items:center;">' +
                                    stat('Task', '<b style="font-size:20px;color:#fff;">' + plan.tasks.length + '</b>') +
                                    stat('Baris item', '<b style="font-size:20px;color:#fff;">' + t.lines + '</b>') +
                                    stat('Material baru', '<b style="font-size:20px;color:#ffb74d;">' + t.newCount + '</b>') +
                                    stat('Material (Rp)', '<b id="aiSumMat" style="font-size:18px;color:#fff;">' + fmtIdr(t.mat) + '</b>') +
                                    stat('Biaya (Rp)', '<b id="aiSumExp" style="font-size:18px;color:#fff;">' + fmtIdr(t.exp) + '</b>') +
                                    stat('TOTAL harga MAX (Rp) - dipakai di RAB', '<b id="aiSumMax" style="font-size:22px;color:' + (meta.over_budget || meta.over_contract ? '#ff6b5e' : '#7fe0a0') + ';">' + fmtIdr(t.max) + '</b>') +
                                    stat('Total harga MIN (Rp)', '<b id="aiSumMin" style="font-size:18px;color:#c4dde8;">' + fmtIdr(t.min) + '</b>') +
                                    '<div>' + chip((sev === 'ok' ? '&#10003; ' : '&#9888; ') + C[1], C[0], 'rgba(0,0,0,.25)') + '</div>' +
                                    '</div>' +
                                    '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:10px;">' +
                                    btn('aiRecalc', '', '', 'Hitung Ulang &amp; Cek Status', SLATE) + btn('aiAddTask', '', '', '+ Tambah Task', TEAL) +
                                    btn('aiExpandAll', '', '', 'Buka Semua Task', '#2c4a5e') + btn('aiCollapseAll', '', '', 'Tutup Semua Task', '#2c4a5e') +
                                    '<span id="aiStale" style="display:none;font-size:14px;color:#ffe08a;"><i>Qty / harga berubah: klik "Hitung Ulang" agar status &amp; plafon dicek ulang.</i></span>' +
                                    '</div></div>';
                            }
                            // Kotak penanda: hasil analisis LEBIH BESAR dari budget / nilai kontrak (merah = ditolak / RAB menolak, kuning = pakai cadangan)
                            function alertHtml() {
                                var sev = meta.severity || 'ok';
                                var C = { danger: ['#3b1212', '#ff6b5e', 'PERINGATAN ANGGARAN'], warning: ['#3a2c08', '#e6a817', 'PERHATIAN ANGGARAN'], ok: ['#0f2f22', '#2e9e4f', 'Anggaran aman'] }[sev] || ['#0f2f22', '#2e9e4f', 'Anggaran aman'];
                                var h = '<div id="aiAlert" style="background:' + C[0] + ';border:2px solid ' + C[1] + ';border-radius:8px;padding:14px;margin-bottom:14px;">' +
                                    '<div style="font-size:17px;font-weight:bold;color:' + C[1] + ';margin-bottom:4px;">' + (sev === 'ok' ? '&#10003; ' : '&#9888; ') + C[2] + '</div>';
                                if (sev === 'ok' && num(meta.budget_idr) > 0) {
                                    h += '<div style="color:#a5e8bd;font-size:15px;">Total RAB setelah disimpan Rp ' + fmtIdr(meta.rab_after_idr) + ' masih dalam budget Rp ' + fmtIdr(meta.budget_idr) +
                                         ' (sisa Rp ' + fmtIdr(num(meta.budget_idr) - num(meta.rab_after_idr)) + ').</div>';
                                }
                                Ext.each(meta.warnings || [], function (w) {
                                    h += '<div style="font-size:15px;line-height:1.55;color:' + (w.level === 'danger' ? '#ffb4ab' : '#ffe08a') + ';margin-top:5px;">&bull; ' + enc(w.text) + '</div>';
                                });
                                return h + '</div>';
                            }
                            function metaHtml() {
                                return '<div style="font-size:15px;color:#c4dde8;line-height:1.7;">RAB proyek saat ini <b>Rp ' + fmtIdr(meta.rab_existing_idr) + '</b>' +
                                    ' &nbsp;|&nbsp; tambahan bila disimpan <b>Rp ' + fmtIdr((meta.rab_after_idr || 0) - (meta.rab_existing_idr || 0)) + '</b>' +
                                    ' &nbsp;|&nbsp; <b>total RAB Rp ' + fmtIdr(meta.rab_after_idr) + '</b>' +
                                    (num(meta.budget_idr) > 0 ? ' &nbsp;|&nbsp; budget Rp ' + fmtIdr(meta.budget_idr) : '') +
                                    (num(meta.contract_idr) > 0 ? ' &nbsp;|&nbsp; nilai kontrak (plafon) Rp ' + fmtIdr(meta.contract_idr) : '') + '</div>';
                            }
                            function helpHtml() {
                                var h = '<div style="margin-bottom:14px;">' + btn('aiToggleHelp', '', '', (openHelp ? '&#9650; Sembunyikan cara pakai' : '&#9660; Cara pakai &amp; catatan'), '#2c4a5e');
                                if (openHelp) {
                                    h += '<div style="font-size:15px;line-height:1.75;color:#d5e3ec;background:#0b1b28;border:1px solid #31536a;border-radius:8px;padding:14px;margin-top:8px;">' +
                                        (editMode ? '<b>Ini mengedit data TERSIMPAN.</b> Klik <b>Simpan Perubahan</b> di bagian bawah jendela bila sudah sesuai.<br>' : '<b>Ini DRAFT, belum tersimpan.</b> Klik <b>Simpan ke Master, RAB &amp; Task</b> di bagian bawah jendela bila sudah sesuai.<br>') +
                                        '&bull; Klik judul task untuk membuka / menutup isinya.<br>' +
                                        '&bull; Ubah qty / harga langsung di kotak; total dihitung otomatis. Klik <b>Hitung Ulang</b> setelah mengubah nama atau qty agar status dan plafon dicek ulang.<br>' +
                                        '&bull; <b>Harga satuan RAB = harga MAX.</b> Item <span style="color:#80cbc4;">Sudah di RAB</span> / <span style="color:#4fc3f7;">Masuk RAB</span> mengikuti RAB / master (tidak diubah di sini); item <span style="color:#ffb74d;">Baru</span> dibuat dulu di master lengkap min-max.<br>' +
                                        '&bull; Nama yang <b>hampir sama</b> dengan data lama akan menimpa / dipakai ulang, bukan digandakan.</div>';
                                }
                                return h + '</div>';
                            }

                            // ---------- [UI-GRID] material / biaya per task = TABEL GRID (satu baris satu item, kolom sejajar) ----------
                            var ING = IN + 'height:36px;padding:5px 8px;';                       // isian di dalam sel grid
                            var TH = 'padding:10px 8px;font-size:14px;font-weight:bold;color:#40d7ed;background:#0a1a27;border-bottom:2px solid #2c4a5e;white-space:nowrap;';
                            var TD = 'padding:8px;vertical-align:top;border-bottom:1px solid #2a485d;font-size:15px;color:#fff;';
                            function lineRow(ti, kind, m, li) {
                                var st = STATUS_LBL[m.status] || ['Cek ulang', '#b0bec5', 'Klik Hitung Ulang untuk memeriksa status item ini.'], fixed = isFixed(m), nameCell;
                                if (fixed) {
                                    nameCell = '<div style="font-weight:bold;line-height:1.35;">' + enc(m.material_name) + '</div>' +
                                        (m.spec ? '<div style="font-size:13px;color:#c4dde8;margin-top:2px;line-height:1.4;">' + enc(m.spec) + '</div>' : '') +
                                        (kind === 'expenses' ? '<div style="font-size:13px;color:#9ec3d1;margin-top:2px;">Kategori: ' + enc(m.category || '-') + '</div>' : '');
                                } else {
                                    nameCell = mfld(ti, kind, li, 'material_name', m.material_name, '100%', 'text', KIND[kind].ph) +
                                        '<div style="height:4px;"></div>' + mfld(ti, kind, li, 'spec', m.spec, '100%', 'text', 'Spesifikasi / keterangan') +
                                        (kind === 'expenses' ? '<div style="height:4px;"></div>' + catSelect('', ti, kind, li, m.category, 'width:100%;height:36px;padding:5px 8px;') : '');
                                }
                                var tip = st[2] + (m.reason ? ' Alasan AI: ' + m.reason : '') + (m.lock_reason ? ' TERKUNCI (tidak bisa dihapus): ' + m.lock_reason + '.' : '') +
                                    ((fixed && pMin(m) === pMax(m) && num(m.ai_min) > 0 && num(m.ai_min) < pMax(m)) ? ' Min master akan diisi: ' + fmtIdr(m.ai_min) + '.' : '');
                                var ro = function (txt, right) { return '<div style="padding:7px 8px;' + (right ? 'text-align:right;' : '') + '">' + txt + '</div>'; };
                                return '<tr style="background:' + (li % 2 ? '#0f2436' : '#0c1e2e') + ';">' +
                                    '<td align="center" style="' + TD + 'color:#9ec3d1;border-left:5px solid ' + st[1] + ';">' + (li + 1) + '</td>' +
                                    '<td style="' + TD + '">' + nameCell + '</td>' +
                                    '<td style="' + TD + '">' + (fixed ? ro(enc(m.unit || '-')) : mfld(ti, kind, li, 'unit', m.unit, '100%', 'text', 'satuan')) + '</td>' +
                                    '<td style="' + TD + '">' + mfld(ti, kind, li, 'qty_estimate', m.qty_estimate, '100%', 'number') + '</td>' +
                                    '<td style="' + TD + '">' + (fixed ? ro(fmtIdr(pMin(m)), true) : mfld(ti, kind, li, 'price_min', m.price_min, '100%', 'number')) + '</td>' +
                                    '<td style="' + TD + '">' + (fixed ? ro(fmtIdr(pMax(m)), true) : mfld(ti, kind, li, 'price_max', m.price_max, '100%', 'number')) + '</td>' +
                                    '<td align="right" id="aiTot_' + ti + '_' + kind + '_' + li + '" style="' + TD + 'white-space:nowrap;">' + totCell(m) + '</td>' +
                                    '<td style="' + TD + '"><span title="' + enc(tip) + '" style="cursor:help;">' + chip(st[0], st[1]) + '</span></td>' +
                                    '<td align="center" style="' + TD + '">' + (m.lock_reason
                                        ? '<span title="Tidak bisa dihapus: ' + enc(m.lock_reason) + '" style="cursor:help;font-size:13px;font-weight:bold;color:#ef9a9a;">&#128274; Terkunci<br><span style="font-weight:normal;">' + enc(m.lock_reason) + '</span></span>'
                                        : '<button type="button" class="aiRemoveLine" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" ' +
                                          'style="padding:7px 12px;background:' + BURG + ';color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:bold;font-size:14px;">Hapus</button>') + '</td></tr>';
                            }

                            // Baris tambah item: dua baris yang kolomnya sejajar dengan grid (A = dari RAB, B = buat baru)
                            function addRows(ti, kind) {
                                var K = KIND[kind], list = (kind === 'materials') ? availableMaterials : availableExpenses, id = ti + '_' + kind;
                                var ad = 'padding:10px 8px;vertical-align:top;border-bottom:1px solid #2a485d;background:#123350;font-size:15px;color:#fff;';
                                var lb = 'font-size:13px;font-weight:bold;color:#80deea;margin-bottom:4px;';
                                var sel = '<select id="aiSel_' + id + '" style="' + ING + 'width:100%;"><option value="">-- pilih dari RAB --</option>';
                                Ext.each(list, function (a) {
                                    var already = false;
                                    Ext.each(plan.tasks[ti][kind] || [], function (x) { if (parseInt(x.budget_item_id, 10) === parseInt(a.budget_item_id, 10)) already = true; });
                                    sel += '<option value="' + parseInt(a.budget_item_id, 10) + '"' + (already ? ' disabled="disabled"' : '') + '>' +
                                           enc(a.material_name + ' | sisa RAB: ' + fmtQty3(a.qty_remaining) + ' ' + a.unit) + '</option>';
                                });
                                sel += '</select>';
                                var h = '<tr><td align="center" style="' + ad + 'font-weight:bold;color:#80deea;">A</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">' + K.rabLbl + '</div>' + sel + '</td>' +
                                    '<td style="' + ad + '"></td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Qty</div><input id="aiSelQty_' + id + '" type="number" min="0" step="any" inputmode="decimal" placeholder="Qty" style="' + ING + 'width:100%;text-align:right;"></td>' +
                                    '<td colspan="5" style="' + ad + 'vertical-align:bottom;">' + btn('aiAddRab', ti, kind, 'Tambah dari RAB', TEAL) + '</td></tr>';
                                h += '<tr><td align="center" style="' + ad + 'font-weight:bold;color:#80deea;">B</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Atau buat baru (harga min &amp; max per satuan, Rp)</div>' +
                                    nfld(id, ti, kind, 'name', K.ph, '100%') + '<div style="height:4px;"></div>' + nfld(id, ti, kind, 'spec', 'Spesifikasi / keterangan', '100%') +
                                    (kind === 'expenses' ? '<div style="height:4px;"></div>' + catSelect('aiNew_' + id + '_cat', ti, kind, 0, 'Lain-lain', 'width:100%;height:36px;padding:5px 8px;') : '') + '</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Satuan</div>' + nfld(id, ti, kind, 'unit', 'satuan', '100%') + '</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Qty</div>' + nfld(id, ti, kind, 'qty', 'Qty', '100%', 'number') + '</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Harga Min</div>' + nfld(id, ti, kind, 'pmin', 'Min', '100%', 'number') + '</td>' +
                                    '<td style="' + ad + '"><div style="' + lb + '">Harga Max</div>' + nfld(id, ti, kind, 'pmax', 'Max', '100%', 'number') + '</td>' +
                                    '<td colspan="3" style="' + ad + 'vertical-align:bottom;">' + btn('aiAddNew', ti, kind, K.btnNew, TEAL) + '</td></tr>';
                                return h;
                            }

                            function kindBlock(ti, kind) {
                                var K = KIND[kind], lines = plan.tasks[ti][kind] || [], key = ti + '_' + kind, isAddOpen = !!openAdd[key];
                                var h = '<div style="margin:18px 0 8px;padding-bottom:6px;border-bottom:2px solid #2c4a5e;display:flex;flex-wrap:wrap;gap:12px;align-items:baseline;">' +
                                    '<span style="font-size:18px;font-weight:bold;color:' + (kind === 'materials' ? '#80deea' : '#ffcc80') + ';">' + K.title + ' (' + lines.length + ')</span>' +
                                    '<span style="margin-left:auto;font-size:14px;color:#9ec3d1;">Subtotal (Max): <span id="aiSub_' + key + '">' + subCell(taskSub(plan.tasks[ti], kind)) + '</span></span></div>';
                                h += '<div style="overflow-x:auto;"><table style="width:100%;min-width:1000px;border-collapse:collapse;table-layout:fixed;">' +
                                    '<colgroup><col style="width:48px;"><col><col style="width:90px;"><col style="width:110px;"><col style="width:140px;"><col style="width:140px;"><col style="width:150px;"><col style="width:150px;"><col style="width:90px;"></colgroup>' +
                                    '<thead><tr><th style="' + TH + 'text-align:center;">No</th><th style="' + TH + 'text-align:left;">' + (kind === 'materials' ? 'Material / Spesifikasi' : 'Biaya / Keterangan') + '</th>' +
                                    '<th style="' + TH + 'text-align:left;">Satuan</th><th style="' + TH + 'text-align:right;">Qty</th>' +
                                    '<th style="' + TH + 'text-align:right;">Harga Min (Rp)</th><th style="' + TH + 'text-align:right;">Harga Max (Rp)</th>' +
                                    '<th style="' + TH + 'text-align:right;">Total (Max)</th><th style="' + TH + 'text-align:left;">Status</th><th style="' + TH + 'text-align:center;">Aksi</th></tr></thead><tbody>';
                                if (lines.length) {
                                    Ext.each(lines, function (m, li) { h += lineRow(ti, kind, m, li); });
                                } else {
                                    h += '<tr><td colspan="9" style="padding:14px;font-size:15px;color:#ffcc80;border-bottom:1px solid #2a485d;">' + K.empty + '</td></tr>';
                                }
                                if (isAddOpen) h += addRows(ti, kind);
                                h += '</tbody></table></div>';
                                h += '<div style="margin-top:10px;">' + btn('aiToggleAdd', ti, kind, isAddOpen ? '&minus; Tutup form tambah' : K.addLbl, isAddOpen ? '#2c4a5e' : TEAL) + '</div>';
                                return h;
                            }

                            function taskCard(ti) {
                                var t = plan.tasks[ti], mats = t.materials || [], exps = t.expenses || [], empty = !mats.length && !exps.length, isOpen = !!openTask[ti];
                                var ov = null; Ext.each(meta.task_matches || [], function (x) { if (x.index === ti) ov = x; });
                                var h = '<div style="border:2px solid ' + (empty ? '#b26a00' : '#3b6078') + ';border-radius:9px;margin:0 0 16px 0;background:#102638;overflow:hidden;">' +
                                    '<div class="aiToggleTask" data-task="' + ti + '" title="Klik untuk ' + (isOpen ? 'menutup' : 'membuka') + '" style="cursor:pointer;display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:14px 16px;background:#16344a;">' +
                                    '<span style="font-size:18px;color:#40d7ed;width:20px;">' + (isOpen ? '&#9660;' : '&#9654;') + '</span>' +
                                    '<span style="font-size:20px;font-weight:bold;color:#40d7ed;">' + (ti + 1) + '.</span>' +
                                    '<span id="aiHdrName_' + ti + '" style="flex:1;min-width:220px;font-size:18px;font-weight:bold;color:#fff;line-height:1.35;">' + enc(t.task_name || '(tanpa nama)') + '</span>' +
                                    '<span style="font-size:14px;color:#c4dde8;">' + mats.length + ' material &bull; ' + exps.length + ' biaya</span>' +
                                    '<span style="font-size:14px;color:#80deea;white-space:nowrap;">' + enc(t.start_date || '-') + ' s/d ' + enc(t.end_date || '-') + '</span>' +
                                    (ov ? chip('Menimpa: ' + enc(ov.match_task_name), '#ffb74d') : '') +
                                    (t.task_status ? chip(enc(t.task_status), '#9ec3d1') : '') +
                                    (t.lock_reason ? chip('&#128274; Terkunci', '#ef9a9a') : '') +
                                    (empty ? chip('Belum ada item', '#ffcc80') : '') +
                                    '<span style="font-size:13px;color:#9ec3d1;">Total (Max) Rp <b id="aiHdrSub_' + ti + '" style="font-size:17px;color:#fff;">' + fmtIdr(taskTotalMax(t)) + '</b></span></div>';
                                if (isOpen) {
                                    h += '<div style="padding:16px;">' +
                                        '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">' +
                                        fld('Nama Task', tfld(ti, 'task_name', t.task_name, 'width:100%;font-size:16px;font-weight:bold;', 'text', 'Nama task'), 'flex:3;min-width:280px;') +
                                        fld('Mulai', tfld(ti, 'start_date', t.start_date, 'width:160px;', 'date')) +
                                        fld('Selesai', tfld(ti, 'end_date', t.end_date, 'width:160px;', 'date')) +
                                        '<div>' + (t.lock_reason
                                            ? '<div style="font-size:14px;color:#ef9a9a;max-width:260px;line-height:1.4;">&#128274; Task tidak bisa dihapus: ' + enc(t.lock_reason) + '</div>'
                                            : btn('aiRemoveTask', ti, '', 'Hapus Task', BURG)) + '</div></div>' +
                                        '<div style="margin-top:12px;">' + fld('Deskripsi pekerjaan', tfld(ti, 'description', t.description, 'width:100%;', 'text', 'Deskripsi pekerjaan')) + '</div>' +
                                        (t.assumption ? '<div style="font-size:14px;color:#c4dde8;margin-top:10px;line-height:1.55;background:#0b1b28;border:1px solid #31536a;border-radius:6px;padding:10px;"><b>Asumsi perhitungan:</b> ' + enc(t.assumption) + '</div>' : '') +
                                        (empty ? '<div style="font-size:15px;color:#ffcc80;margin-top:12px;">Task ini belum punya material maupun biaya. Tambahkan minimal satu (biasanya biaya jasa / upah / sewa alat).</div>' : '') +
                                        kindBlock(ti, 'materials') + kindBlock(ti, 'expenses') + '</div>';
                                }
                                return h + '</div>';
                            }

                            function renderTaskPlan() {
                                var html = stickyBar() + alertHtml() + modeHtml();
                                if (res.analysis) {
                                    html += '<div style="font-size:15px;line-height:1.65;color:#d5e3ec;background:#0b1b28;border:1px solid #31536a;border-radius:8px;padding:14px;margin-bottom:14px;"><b>Analisis AI:</b> ' + enc(res.analysis) + '</div>';
                                }
                                if (res.notes && res.notes.length) {
                                    html += '<div style="font-size:15px;color:#ffcc80;margin-bottom:14px;line-height:1.6;">' + enc(res.notes.join(' ')) + '</div>';
                                }
                                html += '<div id="aiMetaBox" style="background:#0b1b28;border:1px solid #31536a;border-radius:8px;padding:14px;margin-bottom:14px;">' + metaHtml() + '</div>' + helpHtml();
                                Ext.each(plan.tasks, function (t, ti) { html += taskCard(ti); });
                                return html + '<div style="margin:4px 0 8px;">' + btn('aiAddTask', '', '', '+ Tambah Task', TEAL) + '</div>';
                            }

                            // Hanya angka (tanpa membangun ulang tabel) agar fokus input tidak hilang saat mengetik
                            function updateTotalsInPlace() {
                                var t = calcTotals(), set = function (id, v) { var el = document.getElementById(id); if (el) el.innerHTML = fmtIdr(v); };
                                set('aiSumMat', t.mat); set('aiSumExp', t.exp); set('aiSumMax', t.max); set('aiSumMin', t.min);
                                Ext.each(plan.tasks, function (task, ti) {
                                    set('aiHdrSub_' + ti, taskTotalMax(task));
                                    Ext.each(KINDS, function (kind) {
                                        var sub = document.getElementById('aiSub_' + ti + '_' + kind);
                                        if (sub) sub.innerHTML = subCell(taskSub(task, kind));
                                        Ext.each(task[kind] || [], function (m, li) {
                                            var c = document.getElementById('aiTot_' + ti + '_' + kind + '_' + li);
                                            if (c) c.innerHTML = totCell(m);
                                        });
                                    });
                                });
                            }

                            function redraw() {
                                var root = planWin.body.dom, sc = root.scrollTop, saved = {}, i, els;
                                els = root.querySelectorAll('[id^="aiNew_"],[id^="aiSel_"],[id^="aiSelQty_"]');   // isian form tambah jangan hilang
                                for (i = 0; i < els.length; i++) { saved[els[i].id] = els[i].value; }
                                planWin.body.update(renderTaskPlan());
                                for (var id in saved) { var el = document.getElementById(id); if (el && saved[id] !== '') el.value = saved[id]; }
                                bindPlanActions(planWin);
                                root.scrollTop = sc;
                            }

                            // Hitung ulang di server (tanpa AI & tanpa tulis DB): status RAB/master/baru, harga ternormalisasi, total, plafon
                            function refresh() {
                                Ext.Ajax.request({
                                    url: CRD, method: 'POST', timeout: 60000,
                                    params: { task: 'resolve_plan', project_id: projId, plan_json: Ext.util.JSON.encode(plan), mode: saveMode, edit_mode: editMode ? 1 : 0, loaded_ids: Ext.util.JSON.encode(loadedIds) },
                                    success: function (r) {
                                        var o = null;
                                        try { o = Ext.util.JSON.decode(r.responseText); } catch (e) { o = null; }
                                        if (o && o.success && o.data && o.data.tasks && o.data.tasks.length === plan.tasks.length) {
                                            Ext.each(plan.tasks, function (t, i) {
                                                t.materials = o.data.tasks[i].materials || [];
                                                t.expenses = o.data.tasks[i].expenses || [];
                                            });
                                            meta = o;
                                        } else if (o && !o.success) {
                                            Ext.Msg.alert('Peringatan', o.message || 'Gagal menghitung ulang draft.');
                                        }
                                        redraw();
                                    },
                                    failure: function () { redraw(); Ext.Msg.alert('Peringatan', 'Gagal menghubungi server untuk hitung ulang.'); }
                                });
                            }

                            function bindPlanActions(win) {
                                var root = win.body.dom, i, els;

                                // [UI-READABLE] buka / tutup task, form tambah, bantuan; buka / tutup semua
                                els = root.querySelectorAll('.aiToggleTask');
                                for (i = 0; i < els.length; i++) { (function (el) {
                                    el.onclick = function () { var k = parseInt(el.getAttribute('data-task'), 10); openTask[k] = !openTask[k]; redraw(); };
                                })(els[i]); }
                                els = root.querySelectorAll('.aiToggleAdd');
                                for (i = 0; i < els.length; i++) { (function (b) {
                                    b.onclick = function () { var k = b.getAttribute('data-task') + '_' + b.getAttribute('data-kind'); openAdd[k] = !openAdd[k]; redraw(); };
                                })(els[i]); }
                                els = root.querySelectorAll('.aiToggleHelp');
                                for (i = 0; i < els.length; i++) { els[i].onclick = function () { openHelp = !openHelp; redraw(); }; }
                                els = root.querySelectorAll('.aiExpandAll');
                                for (i = 0; i < els.length; i++) { els[i].onclick = function () { for (var k = 0; k < plan.tasks.length; k++) openTask[k] = true; redraw(); }; }
                                els = root.querySelectorAll('.aiCollapseAll');
                                for (i = 0; i < els.length; i++) { els[i].onclick = function () { openTask = {}; redraw(); }; }

                                // [UI-READABLE] isian: klik = pilih semua isi (ketik langsung menimpa); Enter = simpan isian / tambah item
                                els = root.querySelectorAll('input[type="text"],input[type="number"]');
                                for (i = 0; i < els.length; i++) { (function (el) {
                                    el.onfocus = function () { try { el.select(); } catch (e) {} };
                                    el.onkeydown = function (ev) {
                                        ev = ev || window.event;
                                        if ((ev.keyCode || ev.which) !== 13) return;
                                        if (el.className === 'aiNewIn') {
                                            var ab = root.querySelector('.aiAddNew[data-task="' + el.getAttribute('data-task') + '"][data-kind="' + el.getAttribute('data-kind') + '"]');
                                            if (ab) ab.click();
                                        } else { el.blur(); }
                                        return false;
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiEdit');                        // ubah qty / harga / nama / satuan baris
                                for (i = 0; i < els.length; i++) { (function (el) {
                                    el.onchange = function () {
                                        var t = plan.tasks[parseInt(el.getAttribute('data-task'), 10)], kind = el.getAttribute('data-kind'), f = el.getAttribute('data-field');
                                        var m = (t && t[kind]) ? t[kind][parseInt(el.getAttribute('data-line'), 10)] : null;
                                        if (!m) return;
                                        if (f === 'qty_estimate' || f === 'price_min' || f === 'price_max') {
                                            m[f] = num(el.value);
                                            if (f === 'price_max') m.unit_price = m.price_max;
                                            updateTotalsInPlace();
                                            var stale = document.getElementById('aiStale');         // angka plafon server belum dihitung ulang
                                            if (stale) stale.style.display = 'inline';
                                        } else { m[f] = el.value; }
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiTaskEdit');                    // ubah nama / deskripsi / tanggal task
                                for (i = 0; i < els.length; i++) { (function (el) {
                                    el.onchange = function () {
                                        var tk = parseInt(el.getAttribute('data-task'), 10), t = plan.tasks[tk];
                                        if (t) t[el.getAttribute('data-field')] = el.value;
                                        if (t && el.getAttribute('data-field') === 'task_name') {         // [UI-READABLE] judul kartu ikut berubah
                                            var hn = document.getElementById('aiHdrName_' + tk);
                                            if (hn) hn.innerHTML = enc(el.value || '(tanpa nama)');
                                        }
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiRemoveLine');
                                for (i = 0; i < els.length; i++) { (function (b) {
                                    b.onclick = function () {
                                        var t = plan.tasks[parseInt(b.getAttribute('data-task'), 10)];
                                        t[b.getAttribute('data-kind')].splice(parseInt(b.getAttribute('data-line'), 10), 1);
                                        refresh();
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiRemoveTask');
                                for (i = 0; i < els.length; i++) { (function (b) {
                                    b.onclick = function () {
                                        var ti = parseInt(b.getAttribute('data-task'), 10), t = plan.tasks[ti];
                                        var has = (t.materials || []).length + (t.expenses || []).length;
                                        var doIt = function () {
                                            plan.tasks.splice(ti, 1);
                                            var no = {};                                                  // [UI-READABLE] geser status buka/tutup setelah task dihapus
                                            for (var k in openTask) { k = parseInt(k, 10); if (k < ti) no[k] = openTask[k]; else if (k > ti) no[k - 1] = openTask[k]; }
                                            openTask = no; openAdd = {};
                                            refresh();
                                        };
                                        if (has) {
                                            Ext.Msg.confirm('Hapus Task', 'Hapus Task <b>' + enc(t.task_name) + '</b> beserta ' + has + ' item di dalamnya?' +
                                                (editMode ? '<br><br>Task ini sudah tersimpan: akan dihapus PERMANEN saat Anda klik <b>Simpan Perubahan</b> (ditolak bila sudah dipakai SPK / PO / issue gudang).' : ''),
                                                function (a) { if (a === 'yes') doIt(); });
                                        } else { doIt(); }
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiAddTask');
                                for (i = 0; i < els.length; i++) {
                                    els[i].onclick = function () {
                                        var today = ymd(new Date());
                                        plan.tasks.push({ task_name: 'Task Baru', description: '', assumption: '', start_date: projRange.start || today, end_date: projRange.end || today, materials: [], expenses: [] });
                                        openTask[plan.tasks.length - 1] = true;                              // [UI-READABLE] task baru langsung terbuka
                                        refresh();
                                    };
                                }

                                els = root.querySelectorAll('.aiRecalc');
                                for (i = 0; i < els.length; i++) { els[i].onclick = function () { refresh(); }; }

                                els = root.querySelectorAll('.aiAddRab');                      // tambah dari RAB yang sudah ada
                                for (i = 0; i < els.length; i++) { (function (b) {
                                    b.onclick = function () {
                                        var ti = parseInt(b.getAttribute('data-task'), 10), kind = b.getAttribute('data-kind'), id = ti + '_' + kind;
                                        var bid = parseInt(document.getElementById('aiSel_' + id).value, 10), qty = parseFloat(document.getElementById('aiSelQty_' + id).value);
                                        if (!bid || !qty || qty <= 0) { Ext.Msg.alert('Peringatan', 'Pilih item RAB dan isi Qty lebih besar dari nol.'); return; }
                                        var list = (kind === 'materials') ? availableMaterials : availableExpenses, cand = null;
                                        Ext.each(list, function (a) { if (parseInt(a.budget_item_id, 10) === bid) cand = a; });
                                        if (!cand) { Ext.Msg.alert('Gagal', 'Item tidak ditemukan pada daftar RAB proyek.'); return; }
                                        var used = 0;
                                        Ext.each(plan.tasks, function (task) {
                                            Ext.each(task[kind] || [], function (x) { if (parseInt(x.budget_item_id, 10) === bid) used += num(x.qty_estimate); });
                                        });
                                        if (used + qty > num(cand.qty_remaining) + 0.00005) {
                                            Ext.Msg.alert('Qty melebihi sisa RAB', 'Sisa alokasi ' + cand.material_name + ' hanya ' + cand.qty_remaining + ' ' + cand.unit + '.');
                                            return;
                                        }
                                        plan.tasks[ti][kind] = plan.tasks[ti][kind] || [];
                                        plan.tasks[ti][kind].push({
                                            budget_item_id: bid, material_id: cand.material_id || null, material_name: cand.material_name, spec: '', unit: cand.unit,
                                            qty_estimate: qty, price_min: cand.price_min, price_max: cand.price_max, unit_price: cand.price_max, status: 'rab', category: cand.category || '',
                                            reason: 'Ditambahkan manual dari RAB'
                                        });
                                        refresh();
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiAddNew');                      // buat item baru (material baru / biaya baru)
                                for (i = 0; i < els.length; i++) { (function (b) {
                                    b.onclick = function () {
                                        var ti = parseInt(b.getAttribute('data-task'), 10), kind = b.getAttribute('data-kind'), id = ti + '_' + kind;
                                        var v = function (f) { return document.getElementById('aiNew_' + id + '_' + f).value; };
                                        var name = String(v('name')).replace(/^\s+|\s+$/g, ''), unit = String(v('unit')).replace(/^\s+|\s+$/g, '');
                                        var qty = num(v('qty')), pmin = num(v('pmin')), pmax = num(v('pmax'));
                                        if (!name) { Ext.Msg.alert('Peringatan', 'Nama wajib diisi.'); return; }
                                        if (!unit) { Ext.Msg.alert('Peringatan', 'Satuan wajib diisi.'); return; }
                                        if (qty <= 0) { Ext.Msg.alert('Peringatan', 'Qty harus lebih besar dari nol.'); return; }
                                        if (pmin <= 0 && pmax <= 0) { Ext.Msg.alert('Peringatan', 'Isi harga min dan/atau max (Rupiah per satuan).'); return; }
                                        if (pmax <= 0) pmax = pmin;
                                        if (pmin <= 0 || pmin > pmax) pmin = Math.min(pmin > 0 ? pmin : pmax, pmax);
                                        plan.tasks[ti][kind] = plan.tasks[ti][kind] || [];
                                        plan.tasks[ti][kind].push({
                                            budget_item_id: null, material_id: null, material_name: name, spec: String(v('spec')), unit: unit,
                                            qty_estimate: qty, price_min: pmin, price_max: pmax, unit_price: pmax, status: 'new', reason: 'Ditambahkan manual',
                                            category: (kind === 'expenses' && document.getElementById('aiNew_' + id + '_cat')) ? document.getElementById('aiNew_' + id + '_cat').value : ''
                                        });
                                        var ids = ['name', 'spec', 'unit', 'qty', 'pmin', 'pmax'];        // kosongkan form (redraw memulihkan isian lama)
                                        for (var k = 0; k < ids.length; k++) { document.getElementById('aiNew_' + id + '_' + ids[k]).value = ''; }
                                        refresh();
                                    };
                                })(els[i]); }
                            }

                            showPlanWin(editMode ? 'Edit Task & Material Tersimpan' : 'Review & Edit Task, Material, Biaya (AI)', renderTaskPlan(), editMode ? 'Simpan Perubahan' : 'Simpan ke Master, RAB & Task', function () {
                                var err = '';
                                if (!plan.tasks.length) err = 'Minimal harus ada satu Task.';
                                Ext.each(plan.tasks, function (t, i) {
                                    if (err) return false;
                                    var n = 'Task ' + (i + 1);
                                    if (!String(t.task_name || '').replace(/\s+/g, '')) { err = n + ': nama Task kosong.'; return false; }
                                    if (!t.start_date || !t.end_date || t.start_date > t.end_date) { err = n + ' "' + t.task_name + '": tanggal mulai / selesai tidak valid.'; return false; }
                                    Ext.each(KINDS, function (kind) {
                                        Ext.each(t[kind] || [], function (m) {
                                            if (err) return false;
                                            if (!String(m.material_name || '').replace(/\s+/g, '')) { err = n + ': ada baris tanpa nama.'; return false; }
                                            if (!(num(m.qty_estimate) > 0)) { err = n + ' - ' + m.material_name + ': qty harus lebih besar dari nol.'; return false; }
                                            if (!isFixed(m) && !(pMax(m) > 0)) { err = n + ' - ' + m.material_name + ': harga min / max wajib diisi.'; return false; }
                                        });
                                        if (err) return false;
                                    });
                                });
                                if (err) { Ext.Msg.alert('Peringatan', err); return false; }

                                function preSave(cb) {          // [AI-BOQ] cek plafon terbaru; tandai & minta konfirmasi bila hasil analisis lebih besar dari budget / kontrak
                                    Ext.MessageBox.wait('Memeriksa anggaran / plafon...', 'Mohon Tunggu');
                                    Ext.Ajax.request({
                                        url: CRD, method: 'POST', timeout: 60000,
                                        params: { task: 'resolve_plan', project_id: projId, plan_json: Ext.util.JSON.encode(plan), mode: saveMode, edit_mode: editMode ? 1 : 0, loaded_ids: Ext.util.JSON.encode(loadedIds) },
                                        success: function (r) {
                                            Ext.MessageBox.hide();
                                            var o = null;
                                            try { o = Ext.util.JSON.decode(r.responseText); } catch (e) { o = null; }
                                            if (o && o.success && o.data && o.data.tasks && o.data.tasks.length === plan.tasks.length) {
                                                Ext.each(plan.tasks, function (t, i) { t.materials = o.data.tasks[i].materials || []; t.expenses = o.data.tasks[i].expenses || []; });
                                                meta = o;
                                                redraw();
                                            }
                                            if (meta.over_contract) {
                                                aiSaving = false;                                // [DEDUP]
                                                Ext.Msg.alert('Tidak dapat disimpan', 'Hasil analisis LEBIH BESAR dari nilai kontrak (plafon). Lihat kotak merah di bagian atas, lalu kurangi qty / harga / item.');
                                                return;
                                            }
                                            if (meta.severity === 'danger' || meta.severity === 'warning') {
                                                var li = '';
                                                Ext.each(meta.warnings || [], function (w) { li += '<li style="margin-bottom:6px;">' + enc(w.text) + '</li>'; });
                                                Ext.Msg.confirm('Periksa peringatan sebelum menyimpan', '<ul style="margin:0 0 0 16px;padding:0;">' + li + '</ul><br>Tetap simpan ke master, RAB dan Task?',
                                                    function (b) { if (b === 'yes') { cb(); } else { aiSaving = false; } });          // [DEDUP]
                                            } else { cb(); }
                                        },
                                        failure: function () { aiSaving = false; Ext.MessageBox.hide(); Ext.Msg.alert('Peringatan', 'Gagal memeriksa plafon ke server. Coba lagi.'); }   // [DEDUP]
                                    });
                                }
                                function doSave() {
                                Ext.MessageBox.wait('Membuat master baru, memasukkan ke RAB, lalu menyimpan Task...', 'Mohon Tunggu');
                                Ext.Ajax.request({
                                    url: CRD, method: 'POST',
                                    timeout: 90000,            // [AI-BOQ] simpan = buat material + RAB + task dalam satu transaksi
                                    params: {
                                        task: 'save_ai_task_plan',
                                        project_id: projId,
                                        plan_json: Ext.util.JSON.encode(plan), mode: saveMode,
                                        edit_mode: editMode ? 1 : 0, loaded_ids: Ext.util.JSON.encode(loadedIds)       // [EDIT-SAVED]
                                    },
                                    success: function (saveResponse) {
                                        Ext.MessageBox.hide();
                                        aiSaving = false;                                    // [DEDUP]
                                        var saved;
                                        try { saved = Ext.util.JSON.decode(saveResponse.responseText); }
                                        catch (e) { Ext.Msg.alert('Gagal', 'Respons penyimpanan tidak valid.'); return; }
                                        Ext.Msg.alert(saved.success ? 'Sukses' : 'Gagal', saved.message || 'Proses selesai.');
                                        if (saved.success) {
                                            if (planWin) planWin.close();            // jendela baru ditutup bila sukses; bila gagal draft tetap bisa diperbaiki
                                            editorTaskStore.load({ params: { project_id: projId } });
                                            loadMainDetailPanel(projId);
                                        }
                                    },
                                    failure: function () {
                                        Ext.MessageBox.hide();
                                        aiSaving = false;                                    // [DEDUP]
                                        Ext.Msg.alert('Gagal', 'Gagal menyimpan. Draft Anda masih terbuka; periksa log PHP.');
                                    }
                                });
                                }   // doSave
                                if (aiSaving) return false;          // [DEDUP] klik Simpan berulang diabaikan selama proses berjalan
                                aiSaving = true;
                                preSave(doSave);
                                return false;
                            }, function (win) {
                                planWin = win;
                                aiPlanOpen = true;                                   // [DEDUP] selama jendela hasil terbuka, Analisa tidak bisa diklik lagi
                                win.on('destroy', function () { aiPlanOpen = false; aiSaving = false; });
                                bindPlanActions(win);
                            }, true);                  // keepOpen: jendela tetap terbuka sampai simpan berhasil
                }

                document.getElementById('customAiBtn').onclick = function() {
                    var projId = Ext.getCmp('id').getValue();
                    if (!projId) {
                        Ext.Msg.alert('Peringatan', 'Simpan proyek terlebih dahulu sebelum menjalankan analisis AI.');
                        return;
                    }
                    if (aiBusy || aiPlanOpen) return;                  // [DEDUP] abaikan klik berikutnya selama analisis berjalan / hasil belum ditutup
                    aiBusy = true; setAiBtn(true);
                    Ext.MessageBox.wait('AI sedang menyusun Task, daftar material (BoQ) dan perkiraan harga... (bisa 1-2 menit)', 'Mohon Tunggu');
                    Ext.Ajax.request({
                        url: CRD, method: 'POST',
                        timeout: 180000,                       // [AI-BOQ] BoQ lengkap butuh waktu analisis lebih lama (default Ext 30 dtk)
                        params: { task: 'ai_generate_tasks', project_id: projId },
                        success: function(response) {
                            Ext.MessageBox.hide();
                            aiBusy = false; setAiBtn(false);              // [DEDUP]
                            var res;
                            try { res = Ext.util.JSON.decode(response.responseText); }
                            catch (e) { Ext.Msg.alert('Gagal', 'Respons AI tidak valid.'); return; }
                            if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Analisis AI gagal.'); return; }

                            openReview(res, false);                    // [EDIT-SAVED] jendela review dipakai bersama (AI & edit tersimpan)
                        },
                        failure: function() {
                            Ext.MessageBox.hide();
                            aiBusy = false; setAiBtn(false);              // [DEDUP]
                            Ext.Msg.alert('Gagal', 'Terjadi kesalahan koneksi server.');
                        }
                    });
                };

                // [EDIT-SAVED][NEW] Muat task + material yang sudah tersimpan ke jendela review (tanpa AI)
                document.getElementById('customEditBtn').onclick = function() {
                    var projId = Ext.getCmp('id').getValue();
                    if (!projId) { Ext.Msg.alert('Peringatan', 'Simpan proyek terlebih dahulu.'); return; }
                    if (aiBusy || aiPlanOpen) return;
                    aiBusy = true; setAiBtn(true);
                    Ext.MessageBox.wait('Memuat task dan material tersimpan...', 'Mohon Tunggu');
                    Ext.Ajax.request({
                        url: CRD, method: 'POST', timeout: 60000,
                        params: { task: 'load_task_plan', project_id: projId },
                        success: function(response) {
                            Ext.MessageBox.hide();
                            aiBusy = false; setAiBtn(false);
                            var res;
                            try { res = Ext.util.JSON.decode(response.responseText); }
                            catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                            if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Gagal memuat task tersimpan.'); return; }
                            openReview(res, true);
                        },
                        failure: function() {
                            Ext.MessageBox.hide();
                            aiBusy = false; setAiBtn(false);
                            Ext.Msg.alert('Gagal', 'Terjadi kesalahan koneksi server.');
                        }
                    });
                };

                // [AUTO-INV][NEW] preview rencana tagihan -> konfirmasi -> terbitkan
                document.getElementById('customInvBtn').onclick = function () {
                    var projId = Ext.getCmp('id').getValue();
                    if (!projId) { Ext.Msg.alert('Peringatan', 'Simpan proyek terlebih dahulu.'); return; }
                    Ext.MessageBox.wait('AI menganalisis tagihan & pembayaran klien...', 'Mohon Tunggu');
                    Ext.Ajax.request({
                        url: CRD, method: 'POST', params: { task: 'auto_invoice', project_id: projId, mode: 'preview' },
                        success: function (response) {
                            Ext.MessageBox.hide();
                            var res;
                            try { res = Ext.util.JSON.decode(response.responseText); } catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                            if (!res.success) { Ext.Msg.alert('Gagal', res.message); return; }
                            if (!res.new_count && !res.sync_count) {
                                showPlanWin('Info', 'Tidak ada tagihan baru yang perlu diterbitkan.<br><br>' + planHtml(res.data, res.analysis));
                                return;
                            }
                            showPlanWin('Terbitkan Tagihan Otomatis?',
                                planHtml(res.data, res.analysis) + '<div style="margin-top:14px;">Terbitkan <b>' + res.new_count + '</b> tagihan baru dan sesuaikan <b>' + res.sync_count + '</b> tagihan?</div>',
                                'Terbitkan',
                                function () {
                                    Ext.Ajax.request({
                                        url: CRD, method: 'POST', params: { task: 'auto_invoice', project_id: projId, mode: 'issue' },
                                        success: function (r2) {
                                            var o2; try { o2 = Ext.util.JSON.decode(r2.responseText); } catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                                            Ext.Msg.alert(o2.success ? 'Sukses' : 'Gagal', o2.message);
                                            if (o2.success) loadMainDetailPanel(projId);
                                        },
                                        failure: function () { Ext.Msg.alert('Gagal', 'Terjadi kesalahan koneksi server.'); }
                                    });
                                });
                        },
                        failure: function () { Ext.MessageBox.hide(); Ext.Msg.alert('Gagal', 'Terjadi kesalahan koneksi server.'); }
                    });
                };
            }
        }
    });

    // 5. Form Editor Input/Edit
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });
    var fldName = new myTextField({ fieldLabel: 'Nama Proyek', id: 'name', name: 'name', allowBlank: false, width: 220 });
    var fldClient = new myComboField({ fieldLabel: 'Klien', id: 'client_id', name: 'client_id', hiddenName: 'client_id', store: clientDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    var fldPic = new myComboField({ fieldLabel: 'PIC Proyek', id: 'pic_id', name: 'pic_id', hiddenName: 'pic_id', store: picDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    var fldLocation = new myComboField({ fieldLabel: 'Lokasi', id: 'location_id', name: 'location_id', hiddenName: 'location_id', store: locationDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, width: 220 });
    
    // Tgl Mulai & Tgl Selesai berdampingan
    var fldStartDate = new Ext.form.DateField({ fieldLabel: 'Tgl Mulai', id: 'start_date', name: 'start_date', format: 'Y-m-d', width: 110 });
    var fldEndDate = new Ext.form.DateField({ fieldLabel: 'Tgl Selesai', id: 'end_date', name: 'end_date', format: 'Y-m-d', width: 110 });
    
    var dateContainer = new Ext.Container({
        layout: 'column',
        border: false,
        items: [
            { columnWidth: 0.5, layout: 'form', border: false, items: [fldStartDate] },
            { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 70, items: [fldEndDate] }
        ]
    });

    // Status naik ke bekas tempat Tgl Selesai
    var fldStatus = new myComboField({ fieldLabel: 'Status', id: 'status', name: 'status', hiddenName: 'status', store: statusDs, displayField: 'name', valueField: 'id', value: 'Berjalan', width: 220 });
    
    // Catatan dipindah ke dekat Status, Definisi dinaikkan ke atas grid detail
    var fldNotes = new Ext.form.TextArea({ fieldLabel: 'Catatan', id: 'notes', name: 'notes', anchor: '100%', height: 45 });
    var fldScope = new Ext.form.TextArea({ 
        fieldLabel: 'Definisi / Lingkup Proyek', 
        id: 'project_scope', 
        name: 'project_scope', 
        anchor: '100%', 
        height: 60,
        emptyText: 'Contoh: Bangun gudang ukuran 150x50m struktur baja wf lengkap dengan dak lantai & kelistrikan.' 
    });

    var fldBudget = new myNumberField({ fieldLabel: 'Budget', id: 'budget', name: 'budget', decimalPrecision: 2, value: 0, width: 140,
        enableKeyEvents: true, listeners: { change: function () { updateReserve(); }, keyup: function () { updateReserve(); } } });
    var fldContract = new myNumberField({ fieldLabel: 'Nilai Kontrak (Plafon)', id: 'contract_value', name: 'contract_value', decimalPrecision: 2, value: 0, width: 140,
        enableKeyEvents: true, listeners: { change: function () { updateReserve(); }, keyup: function () { updateReserve(); } } });
    var fldReserve = new Ext.form.DisplayField({ fieldLabel: 'Cadangan', value: '-' });

    var fldPpn = new myNumberField({ fieldLabel: 'Tarif PPN (%)', id: 'ppn_rate', name: 'ppn_rate', decimalPrecision: 2, value: 11, width: 80, minValue: 0, maxValue: 100 });
    var ppnIncDs = new Ext.data.SimpleStore({ fields: ['id', 'name'], data: [['0', 'Belum termasuk PPN'], ['1', 'Sudah termasuk PPN']] });
    var fldPpnInc = new myComboField({ fieldLabel: 'Nilai Kontrak', id: 'ppn_inclusive', name: 'ppn_inclusive', hiddenName: 'ppn_inclusive', store: ppnIncDs,
        displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, editable: false, value: '0', width: 160 });
    var fldRetention = new myNumberField({ fieldLabel: 'Retensi (%)', id: 'retention_pct', name: 'retention_pct', decimalPrecision: 2, value: 0, width: 80, minValue: 0, maxValue: 100 });
    var fldDpMin = new myNumberField({ fieldLabel: 'DP Minimum (%)', id: 'dp_min_pct', name: 'dp_min_pct', decimalPrecision: 2, value: 30, width: 80, minValue: 0, maxValue: 100 });

    function updateReserve() {
        var b = parseFloat(fldBudget.getValue()) || 0, c = parseFloat(fldContract.getValue()) || 0, bb = b * curRate;
        if (c <= 0) { fldReserve.setValue('<span style="color:#e6a817;">isi Nilai Kontrak</span>'); return; }
        var r = c - bb;
        fldReserve.setValue(r < 0 ? '<span style="color:#ff6b5e;">Budget melebihi Nilai Kontrak (' + fmtRp(-r) + ')</span>'
                                  : '<b>' + fmtRp(r) + '</b>');
    }

    var fldProgress = new myNumberField({ fieldLabel: 'Progress (%)', id: 'progress', name: 'progress', decimalPrecision: 0, value: 0, width: 80, readOnly: true });

    // stdForm dengan autoScroll dan tinggi bodi tetap agar slider geser bawah aktif sempurna
    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        autoScroll: true,
        height: Math.round(screenH * 0.90),
        bodyStyle: 'padding: 10px; overflow-y: auto;',
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: [fldId, fldName, fldClient, fldPic, fldLocation, dateContainer, fldStatus, fldNotes] },
            { items: [fldBudget, fldContract, fldReserve, fldPpn, fldPpnInc, fldRetention, fldDpMin, fldProgress] },     
            { columnWidth: 1, items: [fldScope] }, 
            { columnWidth: 1, items: [btnAiPanel, editorTaskGrid] } 
        ]
    });

    // 6. Action Handlers
    document.getElementById('addData').onclick = function () {
        task = 'create';
        frmEditor.getForm().reset();
        curRate = 1; updateReserve();
        editorTaskStore.removeAll();
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Proyek Baru';
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit  Proyek';
        
        frmEditor.getForm().load({
            url: CRD,
            params: { id: dataRecord.id, task: 'read_one' },
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                var d = res.data || {};
                
                Ext.getCmp('id').setValue(d.id);
                Ext.getCmp('name').setValue(d.name);
                fldClient.setValue(d.client_id);
                fldPic.setValue(d.pic_id);
                fldLocation.setValue(d.location_id);
                fldStartDate.setValue(d.start_date);
                fldEndDate.setValue(d.end_date);
                fldStatus.setValue(d.status);
                fldBudget.setValue(d.budget);
                fldContract.setValue(d.contract_value);
                fldProgress.setValue(d.progress);
                fldNotes.setValue(d.notes);
                fldPpn.setValue(d.ppn_rate !== undefined && d.ppn_rate !== null ? d.ppn_rate : 0);
                fldPpnInc.setValue((d.ppn_inclusive === true || d.ppn_inclusive === 't' || d.ppn_inclusive === '1' || d.ppn_inclusive === 1) ? '1' : '0');
                fldRetention.setValue(d.retention_pct !== undefined && d.retention_pct !== null ? d.retention_pct : 0);
                fldDpMin.setValue(d.dp_min_pct !== undefined && d.dp_min_pct !== null ? d.dp_min_pct : 30);
                Ext.getCmp('project_scope').setValue(d.project_scope);

                curRate = parseFloat(d.budget_exch_rate) || 1; 
                updateReserve();

                editorTaskStore.load({ params: { project_id: d.id } });
            },
            failure: function () {
                Ext.Msg.alert('Gagal', 'Gagal memuat data proyek.');
            }
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!frmEditor.getForm().isValid()) return;
        var cv = parseFloat(fldContract.getValue()) || 0, bv = (parseFloat(fldBudget.getValue()) || 0) * curRate;
        if (cv > 0 && bv > cv + 0.01) {
            Ext.Msg.alert('Peringatan', 'Budget tidak boleh melebihi Nilai Kontrak.');
            return;
        }

        var currentId = parseInt(Ext.getCmp('id').getValue() || (dataRecord ? dataRecord.id : 0), 10);
        var submitTask = (currentId > 0) ? 'update' : 'create';

        frmEditor.getForm().submit({
            url: CRD,
            method: 'POST',
            params: { 
                task: submitTask,
                id: currentId,
                client_id: fldClient.getValue(),
                pic_id: fldPic.getValue(),
                location_id: fldLocation.getValue()
            },
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
                mainDs.load({ params: { task: 'read' } });
                document.getElementById('popCancel').innerHTML = 'Close';
                document.getElementById('editorWdw').style.display = 'none';
                if (currentId > 0) loadMainDetailPanel(currentId);
            },
            failure: function (form, action) {
                var msg = (action.result && action.result.message) ? action.result.message : 'Gagal menyimpan data.';
                Ext.Msg.alert('Gagal', msg);
            }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        Ext.Msg.confirm('Konfirmasi', 'Hapus proyek ini?', function (btn) {
            if (btn === 'yes') {
                Ext.Ajax.request({
                    url: CRD,
                    params: { task: 'delete', id: dataRecord.id },
                    success: function (response) {
                        var res;
                        try { res = Ext.util.JSON.decode(response.responseText); }
                        catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                        document.getElementById('popWdwTxt').innerHTML = res.message;
                        document.getElementById('popWdw').style.display = 'block';
                        if (res.success) {
                            dataRecord = null;
                            mainDtlDiv.style.display = 'none';
                        }
                        mainDs.load({ params: { task: 'read' } });
                    },
                    failure: function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
                });
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
});