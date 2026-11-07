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
        var h = '<div style="background:' + col[0] + ';border:2px solid ' + col[1] + ';border-radius:5px;padding:10px;margin-bottom:12px;font-size:13px;line-height:1.6;">' +
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
                     Ext.util.Format.htmlEncode(it.unit) + '<div style="color:#8fb3c0;font-size:11px;">@ ' + pr + ' / ' + Ext.util.Format.htmlEncode(it.unit) + (parseFloat(it.pmin) === parseFloat(it.pmax) ? ' (rentang master belum diisi)' : ' (min - max)') + '</div></div>');
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
        h += '<table style="border-collapse:collapse; font-size:13px; width:100%; text-align:left; table-layout:auto;">' +
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
    function showPlanWin(title, html, yesText, onYes, onReady, keepOpen) {      // [AI-BOQ] keepOpen: jangan tutup saat Simpan (draft tetap ada bila gagal)
        var w = Math.min(1250, Math.round((window.innerWidth || 1200) * 0.95));      // [AI-BOQ] lebih lebar untuk tabel BoQ
        var h = Math.min(700, Math.round((window.innerHeight || 800) * 0.90));
        var uid = 'planWin' + (++planWinSeq);
        var btnBase = 'padding:8px 20px; color:#fff; border:0; border-radius:4px; cursor:pointer; font-weight:bold; font-size:13px; margin-left:10px;';
        var btnHtml = '<div style="text-align:right; padding:6px 4px;">' +
            (onYes ? '<button type="button" id="' + uid + 'No" style="' + btnBase + 'background:#800020;">Batal</button>' +        // burgundy
                     '<button type="button" id="' + uid + 'Yes" style="' + btnBase + 'background:#066b78;">' + Ext.util.Format.htmlEncode(yesText || 'Ya') + '</button>'   // deep teal
                   : '<button type="button" id="' + uid + 'No" style="' + btnBase + 'background:#066b78;">Tutup</button>') +
            '</div>';
        var win = new Ext.Window({
            title: title, width: w, height: h, modal: true, layout: 'auto', autoScroll: true, closeAction: 'close',
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
                   '<h3 style="margin:0; font-size:16px; color:#00bcd4;">Detail & Sub-Task Proyek AI: ' + Ext.util.Format.htmlEncode(dataRecord.name) + '</h3>' +
                   '<span style="color:#a1c4d0; font-size:12px; white-space:nowrap;">Poin Kontrak: <b>Rp ' + fmtRp(dataRecord.contract_value) + '</b></span>' +
                   '</div>' +
                   '<div style="text-align:left; margin:0 0 12px 0;">' +
                   '<button type="button" id="btnPrintProjectTasks" style="padding:7px 14px; background:#087f8c; color:#fff; border:0; border-radius:4px; cursor:pointer; font-weight:bold;">Print Task</button>' +
                   '</div>' +
                   '<div style="background:#132330; padding:10px; border-radius:4px; margin-bottom:12px; border:1px solid #1c3648;">' +
                   '<b style="color:#00bcd4; font-size:13px;">Definisi / Lingkup Proyek:</b>' +
                   '<div style="color:#ffffff; font-size:13px; margin-top:4px; line-height:18px;">' + Ext.util.Format.htmlEncode(scopeTxt) + '</div>' +
                   '</div>' +
                   plafonBanner() +
                   '<table width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#fff; font-size:13px;">' +
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
                        '<td style="color:#80deea;padding:12px 8px;font-size:12px;">' + fmtTaskItems(d.items, 'M') + '</td>' +
                        '<td style="color:#ffcc80;padding:12px 8px;font-size:12px;">' + fmtTaskItems(d.items, 'E') + '</td>' +
                        '<td align="right" style="color:#e0f7fa;padding:12px 8px;white-space:nowrap;">' + Ext.util.Format.number(d.total_max || 0, '0,000') +
                        '<div style="color:#8fb3c0;font-size:11px;">min ' + Ext.util.Format.number(d.total_min || 0, '0,000') + '</div></td>' +
                        '<td align="center" style="color:#80deea;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.start_date) + '</td>' +
                        '<td align="center" style="color:#80deea;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.end_date) + '</td>' +
                        '<td align="center"><span style="background:#004d40; color:#80cbc4; padding:2px 8px; border-radius:3px; font-size:11px;">' + Ext.util.Format.htmlEncode(d.status) + '</span></td>' +
                        '</tr>';
            });
        }
        html += '</tbody></table>';

        // [AUTO-INV][NEW] tabel tagihan ke klien
        html += '<h3 style="margin:16px 0 8px 0; font-size:15px; color:#00bcd4;">Tagihan ke Klien</h3>' +
                '<table width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#fff; font-size:13px;">' +
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
                        '<td style="padding:8px;">' + Ext.util.Format.htmlEncode(iv.inv_no) + (iv.auto_generated ? ' <span style="color:#00bcd4;font-size:10px;">AUTO</span>' : '') + '</td>' +
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
        html: '<div style="text-align: left;"><button type="button" id="customAiBtn" style="background:transparent; border:1px solid #00bcd4; color:#00bcd4; padding:8px 24px; border-radius:20px; font-weight:bold; cursor:pointer; font-size:12px; letter-spacing:1px; transition:all 0.3s;">ANALISIS & BAGI TASK (AI)</button>' +
              // [AUTO-INV][NEW] tombol terbitkan tagihan otomatis (analisis AI + preview + terbitkan)
              ' <button type="button" id="customInvBtn" style="background:transparent; border:1px solid #ffd54f; color:#ffd54f; padding:8px 24px; border-radius:20px; font-weight:bold; cursor:pointer; font-size:12px; letter-spacing:1px; margin-left:8px;">TERBITKAN TAGIHAN OTOMATIS</button></div>',
        listeners: {
            afterrender: function() {
                document.getElementById('customAiBtn').onclick = function() {
                    var projId = Ext.getCmp('id').getValue();
                    if (!projId) {
                        Ext.Msg.alert('Peringatan', 'Simpan proyek terlebih dahulu sebelum menjalankan analisis AI.');
                        return;
                    }
                    Ext.MessageBox.wait('AI sedang menyusun Task, daftar material (BoQ) dan perkiraan harga... (bisa 1-2 menit)', 'Mohon Tunggu');
                    Ext.Ajax.request({
                        url: CRD, method: 'POST',
                        timeout: 180000,                       // [AI-BOQ] BoQ lengkap butuh waktu analisis lebih lama (default Ext 30 dtk)
                        params: { task: 'ai_generate_tasks', project_id: projId },
                        success: function(response) {
                            Ext.MessageBox.hide();
                            var res;
                            try { res = Ext.util.JSON.decode(response.responseText); }
                            catch (e) { Ext.Msg.alert('Gagal', 'Respons AI tidak valid.'); return; }
                            if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Analisis AI gagal.'); return; }

                            var plan = res.data;
                            var availableMaterials = res.available_materials || [];
                            var availableExpenses = res.available_expenses || [];
                            var projRange = res.project_range || {};
                            var meta = res;                        // angka total + peringatan dari server (diperbarui tiap Hitung Ulang)
                            var planWin = null;
                            var existing = res.existing_tasks || { total: 0, replaceable: 0, kept: 0 };
                            var saveMode = existing.replaceable > 0 ? 'replace' : 'append';        // replace = task lama (belum dimulai) diganti agar tidak ganda

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
                            var IN = 'padding:5px;font-size:12px;background:#061522;color:#fff;border:1px solid #52758b;border-radius:3px;box-sizing:border-box;';
                            var STATUS_LBL = {
                                'rab':       ['Sudah di RAB',     '#80cbc4'],
                                'rab_topup': ['Qty RAB ditambah', '#ffd54f'],
                                'master':    ['Masuk RAB',        '#4fc3f7'],
                                'new':       ['Baru (dibuat)',    '#ffb74d']
                            };
                            var KIND = {
                                materials: { title: 'Material', nameHdr: 'Material / Spesifikasi', ph: 'Nama material baru', btnNew: 'Tambah Material Baru', rabLbl: 'Tambah material dari RAB yang sudah ada', empty: 'Belum ada material.' },
                                expenses:  { title: 'Biaya / Jasa (Expense)', nameHdr: 'Biaya / Keterangan', ph: 'Nama biaya baru (mis. Jasa ereksi baja)', btnNew: 'Tambah Biaya Baru', rabLbl: 'Tambah biaya dari RAB yang sudah ada', empty: 'Belum ada biaya.' }
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
                            function totCell(m) { return '<b>' + fmtIdr(lnMax(m)) + '</b><div style="color:#8fb3c0;font-size:11px;">min ' + fmtIdr(lnMin(m)) + '</div>'; }
                            function subCell(s) { return '<b>' + fmtIdr(s[0]) + '</b><div style="color:#8fb3c0;font-size:11px;">min ' + fmtIdr(s[1]) + '</div>'; }

                            function btn(cls, ti, kind, label, bg) {
                                return '<button type="button" class="' + cls + '" data-task="' + ti + '"' + (kind ? ' data-kind="' + kind + '"' : '') +
                                    ' style="padding:7px 16px;background:' + bg + ';color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:bold;font-size:12px;">' + label + '</button>';
                            }
                            function mfld(ti, kind, li, field, val, w, type, ph) {
                                return '<input type="' + (type || 'text') + '" class="aiEdit" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" data-field="' + field + '" value="' +
                                    enc(val === null || val === undefined ? '' : val) + '"' + (type === 'number' ? ' min="0" step="any"' : '') + (ph ? ' placeholder="' + enc(ph) + '"' : '') +
                                    ' style="' + IN + 'width:' + w + ';' + (type === 'number' ? 'text-align:right;' : '') + '">';
                            }
                            function tfld(ti, field, val, style, type, ph) {
                                return '<input type="' + (type || 'text') + '" class="aiTaskEdit" data-task="' + ti + '" data-field="' + field + '" value="' + enc(val || '') + '"' +
                                    (ph ? ' placeholder="' + enc(ph) + '"' : '') + ' style="' + IN + style + '">';
                            }

                            function summaryHtml() {
                                var t = calcTotals();
                                return '<div style="font-size:15px;line-height:1.55;color:#d5e3ec;margin-bottom:10px;">' + enc(res.message) +
                                    '<br><b>Task: ' + plan.tasks.length + ' | Baris: ' + t.lines + ' | Material baru (dibuat di master): ' + t.newCount + '</b><br>' +
                                    'Total Material: Rp <span id="aiSumMat">' + fmtIdr(t.mat) + '</span> | Total Biaya: Rp <span id="aiSumExp">' + fmtIdr(t.exp) + '</span><br>' +
                                    '<b>Total (harga MAX, dipakai di RAB): Rp <span id="aiSumMax" style="' + (meta.over_budget || meta.over_contract ? 'color:#ff6b5e;' : '') + '">' + fmtIdr(t.max) + '</span></b> | Total (harga MIN): Rp <span id="aiSumMin">' + fmtIdr(t.min) + '</span></div>';
                            }
                            // Pilihan: ganti task lama (disarankan) atau tambahkan. Ganti mencegah task dan qty RAB bertambah ganda saat analisis diulang.
                            function modeHtml() {
                                if (!existing.total) return '';
                                var topups = 0;
                                Ext.each(plan.tasks, function (t) { Ext.each(KINDS, function (k) { Ext.each(t[k] || [], function (m) { if (m.status === 'rab_topup') topups++; }); }); });
                                var h = '<div style="background:#0b1b28;border:1px solid #52758b;border-radius:6px;padding:10px;margin-bottom:12px;font-size:13px;color:#d5e3ec;line-height:1.7;">' +
                                    '<b>Proyek ini sudah punya ' + existing.total + ' task</b> (' + existing.replaceable + ' belum dimulai dan aman diganti; ' + existing.kept + ' dipertahankan karena sudah berjalan / ada tagihan terbayar).<br>' +
                                    '<label style="cursor:pointer;"><input type="radio" name="aiMode" class="aiMode" value="replace"' + (saveMode === 'replace' ? ' checked="checked"' : '') + (existing.replaceable ? '' : ' disabled="disabled"') +
                                    '> <b>Ganti</b> ' + existing.replaceable + ' task lama dengan hasil analisis ini (disarankan: task dan qty RAB tidak menjadi ganda)</label><br>' +
                                    '<label style="cursor:pointer;"><input type="radio" name="aiMode" class="aiMode" value="append"' + (saveMode === 'append' ? ' checked="checked"' : '') +
                                    '> <b>Tambahkan</b> sebagai task baru (task lama tetap ada; item yang sama menambah qty RAB)</label>';
                                if (saveMode === 'append' && topups > 0) h += '<div style="color:#ffe08a;margin-top:4px;">&#9888; ' + topups + ' item akan MENAMBAH qty RAB karena sudah dialokasikan ke task lama.</div>';
                                return h + '</div>';
                            }
                            var EXP_CATS = ['Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'];     // = kategori biaya di modul RAB
                            function catSelect(id, ti, kind, li, val, extraStyle) {
                                var h = '<select ' + (id ? 'id="' + id + '" ' : 'class="aiEdit" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" data-field="category" ') +
                                        'style="' + IN + (extraStyle || '') + '">';
                                Ext.each(EXP_CATS, function (c) { h += '<option value="' + c + '"' + (c === (val || 'Lain-lain') ? ' selected="selected"' : '') + '>' + c + '</option>'; });
                                return h + '</select>';
                            }
                            // Kotak penanda: hasil analisis LEBIH BESAR dari budget / nilai kontrak (merah = ditolak / RAB menolak, kuning = pakai cadangan)
                            function alertHtml() {
                                var sev = meta.severity || 'ok';
                                var C = { danger: ['#3b1212', '#ff6b5e', 'PERINGATAN ANGGARAN'], warning: ['#3a2c08', '#e6a817', 'PERHATIAN ANGGARAN'], ok: ['#0f2f22', '#2e9e4f', 'Anggaran aman'] }[sev] || ['#0f2f22', '#2e9e4f', 'Anggaran aman'];
                                var h = '<div id="aiAlert" style="background:' + C[0] + ';border:2px solid ' + C[1] + ';border-radius:6px;padding:12px;margin-bottom:12px;">' +
                                    '<div style="font-size:15px;font-weight:bold;color:' + C[1] + ';margin-bottom:4px;">' + (sev === 'ok' ? '&#10003; ' : '&#9888; ') + C[2] + '</div>';
                                if (sev === 'ok' && num(meta.budget_idr) > 0) {
                                    h += '<div style="color:#a5e8bd;font-size:13px;">Total RAB setelah disimpan Rp ' + fmtIdr(meta.rab_after_idr) + ' masih dalam budget Rp ' + fmtIdr(meta.budget_idr) +
                                         ' (sisa Rp ' + fmtIdr(num(meta.budget_idr) - num(meta.rab_after_idr)) + ').</div>';
                                }
                                Ext.each(meta.warnings || [], function (w) {
                                    h += '<div style="font-size:13px;line-height:1.5;color:' + (w.level === 'danger' ? '#ffb4ab' : '#ffe08a') + ';margin-top:4px;">&bull; ' + enc(w.text) + '</div>';
                                });
                                return h + '<div id="aiStale" style="display:none;font-size:12px;color:#ffe08a;margin-top:6px;"><i>Qty / harga berubah: angka plafon di atas belum dihitung ulang. Klik "Hitung Ulang".</i></div></div>';
                            }
                            function metaHtml() {
                                var h = '<div style="font-size:13px;color:#b0d4e0;line-height:1.6;">RAB proyek saat ini Rp ' + fmtIdr(meta.rab_existing_idr) +
                                    ' | tambahan bila disimpan Rp ' + fmtIdr((meta.rab_after_idr || 0) - (meta.rab_existing_idr || 0)) +
                                    ' | <b>total RAB Rp ' + fmtIdr(meta.rab_after_idr) + '</b>' +
                                    (num(meta.budget_idr) > 0 ? ' | budget Rp ' + fmtIdr(meta.budget_idr) : '') +
                                    (num(meta.contract_idr) > 0 ? ' | nilai kontrak (plafon) Rp ' + fmtIdr(meta.contract_idr) : '') + '</div>';
                                return h;
                            }

                            function lineRow(ti, kind, m, li) {
                                var st = STATUS_LBL[m.status] || ['Cek ulang', '#b0bec5'], fixed = isFixed(m), td = 'padding:7px;vertical-align:top;';
                                var nameCell = fixed
                                    ? '<b>' + enc(m.material_name) + '</b>' + (m.spec ? '<div style="color:#a1c4d0;font-size:12px;">' + enc(m.spec) + '</div>' : '')
                                    : mfld(ti, kind, li, 'material_name', m.material_name, '100%', 'text', KIND[kind].ph) + '<div style="height:3px;"></div>' +
                                      mfld(ti, kind, li, 'spec', m.spec, '100%', 'text', 'Spesifikasi / keterangan');
                                var catHtml = '';
                                if (kind === 'expenses') {
                                    catHtml = (fixed || m.status === 'master')
                                        ? '<div style="color:#a1c4d0;font-size:11px;margin-top:2px;">Kategori: ' + enc(m.category || '-') + '</div>'
                                        : '<div style="margin-top:3px;">' + catSelect('', ti, kind, li, m.category, 'width:130px;') + '</div>';
                                }
                                return '<tr style="border-top:1px solid #34546a;">' +
                                    '<td style="' + td + '">' + nameCell + catHtml + (m.reason ? '<div style="color:#688998;font-size:11px;">' + enc(m.reason) + '</div>' : '') + '</td>' +
                                    '<td align="center" style="' + td + '">' + (fixed ? enc(m.unit || '') : mfld(ti, kind, li, 'unit', m.unit, '62px', 'text', 'satuan')) + '</td>' +
                                    '<td align="right" style="' + td + '">' + mfld(ti, kind, li, 'qty_estimate', m.qty_estimate, '84px', 'number') + '</td>' +
                                    '<td align="right" style="' + td + '">' + (fixed ? fmtIdr(pMin(m)) : mfld(ti, kind, li, 'price_min', m.price_min, '100px', 'number')) + '</td>' +
                                    '<td align="right" style="' + td + '">' + (fixed ? fmtIdr(pMax(m)) : mfld(ti, kind, li, 'price_max', m.price_max, '100px', 'number')) + '</td>' +
                                    '<td align="right" id="aiTot_' + ti + '_' + kind + '_' + li + '" style="' + td + 'white-space:nowrap;">' + totCell(m) + '</td>' +
                                    '<td style="' + td + 'white-space:nowrap;color:' + st[1] + ';font-weight:bold;font-size:12px;">' + st[0] +
                                    ((fixed && pMin(m) === pMax(m) && num(m.ai_min) > 0 && num(m.ai_min) < pMax(m)) ? '<div style="color:#9ec3d1;font-weight:normal;font-size:11px;">min master diisi: ' + fmtIdr(m.ai_min) + '</div>' : '') + '</td>' +
                                    '<td style="padding:6px;vertical-align:top;"><button type="button" class="aiRemoveLine" data-task="' + ti + '" data-kind="' + kind + '" data-line="' + li + '" ' +
                                    'style="padding:6px 12px;background:' + BURG + ';color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:bold;font-size:12px;">Hapus</button></td></tr>';
                            }

                            function addBar(ti, kind) {
                                var K = KIND[kind], list = (kind === 'materials') ? availableMaterials : availableExpenses, id = ti + '_' + kind;
                                var h = '<div style="border-top:1px dashed #34546a;margin-top:8px;padding-top:8px;font-size:12px;color:#9ec3d1;">' +
                                    '<div style="margin-bottom:5px;"><b>' + K.rabLbl + '</b></div>' +
                                    '<select id="aiSel_' + id + '" style="' + IN + 'width:46%;min-width:200px;"><option value="">-- pilih dari RAB --</option>';
                                Ext.each(list, function (a) {
                                    var already = false;
                                    Ext.each(plan.tasks[ti][kind] || [], function (x) { if (parseInt(x.budget_item_id, 10) === parseInt(a.budget_item_id, 10)) already = true; });
                                    h += '<option value="' + parseInt(a.budget_item_id, 10) + '"' + (already ? ' disabled="disabled"' : '') + '>' +
                                         enc(a.material_name + ' | sisa RAB: ' + fmtQty3(a.qty_remaining) + ' ' + a.unit) + '</option>';
                                });
                                h += '</select> <input id="aiSelQty_' + id + '" type="number" min="0" step="any" placeholder="Qty" style="' + IN + 'width:90px;text-align:right;"> ' +
                                     btn('aiAddRab', ti, kind, 'Tambah dari RAB', TEAL) +
                                     '<div style="margin:9px 0 5px;"><b>atau buat baru</b> (harga min &amp; max per satuan, Rupiah)</div>' +
                                     (kind === 'expenses' ? catSelect('aiNew_' + id + '_cat', ti, kind, 0, 'Lain-lain', 'width:110px;') + ' ' : '') +
                                     '<input id="aiNew_' + id + '_name" type="text" placeholder="' + enc(K.ph) + '" style="' + IN + 'width:24%;min-width:150px;"> ' +
                                     '<input id="aiNew_' + id + '_spec" type="text" placeholder="Spesifikasi / keterangan" style="' + IN + 'width:20%;min-width:120px;"> ' +
                                     '<input id="aiNew_' + id + '_unit" type="text" placeholder="satuan" style="' + IN + 'width:64px;"> ' +
                                     '<input id="aiNew_' + id + '_qty" type="number" min="0" step="any" placeholder="Qty" style="' + IN + 'width:80px;text-align:right;"> ' +
                                     '<input id="aiNew_' + id + '_pmin" type="number" min="0" step="any" placeholder="Harga min" style="' + IN + 'width:100px;text-align:right;"> ' +
                                     '<input id="aiNew_' + id + '_pmax" type="number" min="0" step="any" placeholder="Harga max" style="' + IN + 'width:100px;text-align:right;"> ' +
                                     btn('aiAddNew', ti, kind, K.btnNew, TEAL) + '</div>';
                                return h;
                            }

                            function kindBlock(ti, kind) {
                                var K = KIND[kind], lines = plan.tasks[ti][kind] || [], th = 'padding:7px;border-bottom:1px solid #34546a;';
                                var h = '<div style="margin:12px 0 4px;font-size:14px;font-weight:bold;color:#80deea;">' + K.title + '</div>';
                                if (lines.length) {
                                    h += '<table width="100%" style="border-collapse:collapse;color:#fff;font-size:13px;line-height:1.4;">' +
                                        '<tr style="color:#40d7ed;background:#0a1a27;">' +
                                        '<th align="left" style="' + th + '">' + K.nameHdr + '</th><th align="center" style="' + th + '">Satuan</th>' +
                                        '<th align="right" style="' + th + '">Qty</th><th align="right" style="' + th + '">Harga Min (Rp)</th>' +
                                        '<th align="right" style="' + th + '">Harga Max (Rp)</th><th align="right" style="' + th + '">Total (Max)</th>' +
                                        '<th align="left" style="' + th + '">Status</th><th style="' + th + '"></th></tr>';
                                    Ext.each(lines, function (m, li) { h += lineRow(ti, kind, m, li); });
                                    h += '<tr style="border-top:1px solid #34546a;"><td colspan="5" align="right" style="padding:7px;color:#40d7ed;"><b>Subtotal ' + K.title + '</b></td>' +
                                         '<td align="right" id="aiSub_' + ti + '_' + kind + '" style="padding:7px;">' + subCell(taskSub(plan.tasks[ti], kind)) + '</td><td colspan="2"></td></tr></table>';
                                } else {
                                    h += '<div style="font-size:13px;color:#ffcc80;margin:4px 0;">' + K.empty + '</div>';
                                }
                                return h + addBar(ti, kind);
                            }

                            function taskCard(ti) {
                                var t = plan.tasks[ti], empty = !(t.materials || []).length && !(t.expenses || []).length;
                                var h = '<div style="border:1px solid ' + (empty ? '#b26a00' : '#3b6078') + ';border-radius:7px;padding:14px;margin:0 0 14px 0;background:#102638;">' +
                                    '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;"><span style="font-size:17px;font-weight:bold;color:#40d7ed;">' + (ti + 1) + '.</span>' +
                                    tfld(ti, 'task_name', t.task_name, 'flex:1;font-size:15px;font-weight:bold;', 'text', 'Nama task') +
                                    btn('aiRemoveTask', ti, '', 'Hapus Task', BURG) + '</div>' +
                                    tfld(ti, 'description', t.description, 'width:100%;font-size:13px;', 'text', 'Deskripsi pekerjaan') +
                                    (t.assumption ? '<div style="font-size:12px;color:#a1c4d0;margin:5px 0;"><i>Asumsi: ' + enc(t.assumption) + '</i></div>' : '') +
                                    '<div style="margin:7px 0 0;font-size:12px;color:#80deea;">Mulai ' + tfld(ti, 'start_date', t.start_date, 'width:140px;', 'date') +
                                    ' &nbsp;Selesai ' + tfld(ti, 'end_date', t.end_date, 'width:140px;', 'date') + '</div>' +
                                    (empty ? '<div style="font-size:13px;color:#ffcc80;margin-top:8px;">Task ini belum punya material maupun biaya. Tambahkan minimal satu (biasanya biaya jasa / upah / sewa alat).</div>' : '');
                                return h + kindBlock(ti, 'materials') + kindBlock(ti, 'expenses') + '</div>';
                            }

                            function toolbar() {
                                return '<div style="margin:6px 0 12px;">' + btn('aiAddTask', '', '', '+ Tambah Task', TEAL) + ' &nbsp;' +
                                       btn('aiRecalc', '', '', 'Hitung Ulang &amp; Cek Status', SLATE) + '</div>';
                            }

                            function renderTaskPlan() {
                                var html = alertHtml() + modeHtml() + '<div id="aiSummary">' + summaryHtml() + '</div>';
                                if (res.analysis) {
                                    html += '<div style="font-size:14px;line-height:1.55;color:#b0d4e0;background:#0b1b28;border:1px solid #31536a;padding:10px;margin-bottom:10px;"><b>Analisis AI:</b> ' + enc(res.analysis) + '</div>';
                                }
                                if (res.notes && res.notes.length) {
                                    html += '<div style="font-size:14px;color:#ffcc80;margin-bottom:10px;">' + enc(res.notes.join(' ')) + '</div>';
                                }
                                html += '<div id="aiMetaBox" style="background:#0b1b28;border:1px solid #31536a;padding:10px;margin-bottom:10px;">' + metaHtml() + '</div>' +
                                    '<div style="font-size:13px;color:#b0bec5;background:#0b1b28;border:1px solid #31536a;padding:10px;margin-bottom:6px;line-height:1.5;">' +
                                    '<b>Ini draft, belum tersimpan.</b> Ubah Task, material dan biaya sesuka Anda. Harga min dan max disimpan di <b>master</b> (material / biaya baru); <b>harga satuan RAB = harga MAX</b> dan total memakai harga MAX. ' +
                                    'Saat disimpan: material <span style="color:#ffb74d;">baru</span> dibuat dulu di master (lengkap min-max), lalu material dan biaya masuk ke RAB (lengkap dengan satuan), kemudian Task dan alokasinya disimpan. ' +
                                    'Item yang sudah ada di master memakai rentang harga master (tidak diubah di sini); di halaman RAB harga satuan boleh diubah selama masih di antara min dan max master. Klik <b>Hitung Ulang</b> setelah mengubah nama / qty agar status dan total plafon dicek ulang.</div>' + toolbar();
                                Ext.each(plan.tasks, function (t, ti) { html += taskCard(ti); });
                                return html + toolbar();
                            }

                            // Hanya angka (tanpa membangun ulang tabel) agar fokus input tidak hilang saat mengetik
                            function updateTotalsInPlace() {
                                var t = calcTotals(), set = function (id, v) { var el = document.getElementById(id); if (el) el.innerHTML = fmtIdr(v); };
                                set('aiSumMat', t.mat); set('aiSumExp', t.exp); set('aiSumMax', t.max); set('aiSumMin', t.min);
                                Ext.each(plan.tasks, function (task, ti) {
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
                                    params: { task: 'resolve_plan', project_id: projId, plan_json: Ext.util.JSON.encode(plan), mode: saveMode },
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
                                            if (stale) stale.style.display = 'block';
                                        } else { m[f] = el.value; }
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiTaskEdit');                    // ubah nama / deskripsi / tanggal task
                                for (i = 0; i < els.length; i++) { (function (el) {
                                    el.onchange = function () {
                                        var t = plan.tasks[parseInt(el.getAttribute('data-task'), 10)];
                                        if (t) t[el.getAttribute('data-field')] = el.value;
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
                                        var doIt = function () { plan.tasks.splice(ti, 1); refresh(); };
                                        if (has) {
                                            Ext.Msg.confirm('Hapus Task', 'Hapus Task <b>' + enc(t.task_name) + '</b> beserta ' + has + ' item di dalamnya?', function (a) { if (a === 'yes') doIt(); });
                                        } else { doIt(); }
                                    };
                                })(els[i]); }

                                els = root.querySelectorAll('.aiAddTask');
                                for (i = 0; i < els.length; i++) {
                                    els[i].onclick = function () {
                                        var today = ymd(new Date());
                                        plan.tasks.push({ task_name: 'Task Baru', description: '', assumption: '', start_date: projRange.start || today, end_date: projRange.end || today, materials: [], expenses: [] });
                                        refresh();
                                    };
                                }

                                els = root.querySelectorAll('.aiMode');                       // ganti / tambah task lama
                                for (i = 0; i < els.length; i++) { (function (r) {
                                    r.onclick = function () { saveMode = r.value; refresh(); };
                                })(els[i]); }

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

                            showPlanWin('Review & Edit Task, Material, Biaya (AI)', renderTaskPlan(), 'Simpan ke Master, RAB & Task', function () {
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
                                        params: { task: 'resolve_plan', project_id: projId, plan_json: Ext.util.JSON.encode(plan), mode: saveMode },
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
                                                Ext.Msg.alert('Tidak dapat disimpan', 'Hasil analisis LEBIH BESAR dari nilai kontrak (plafon). Lihat kotak merah di bagian atas, lalu kurangi qty / harga / item.');
                                                return;
                                            }
                                            if (meta.severity === 'danger' || meta.severity === 'warning') {
                                                var li = '';
                                                Ext.each(meta.warnings || [], function (w) { li += '<li style="margin-bottom:6px;">' + enc(w.text) + '</li>'; });
                                                Ext.Msg.confirm('Periksa peringatan sebelum menyimpan', '<ul style="margin:0 0 0 16px;padding:0;">' + li + '</ul><br>Tetap simpan ke master, RAB dan Task?',
                                                    function (b) { if (b === 'yes') cb(); });
                                            } else { cb(); }
                                        },
                                        failure: function () { Ext.MessageBox.hide(); Ext.Msg.alert('Peringatan', 'Gagal memeriksa plafon ke server. Coba lagi.'); }
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
                                        plan_json: Ext.util.JSON.encode(plan), mode: saveMode
                                    },
                                    success: function (saveResponse) {
                                        Ext.MessageBox.hide();
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
                                        Ext.Msg.alert('Gagal', 'Gagal menyimpan. Draft Anda masih terbuka; periksa log PHP.');
                                    }
                                });
                                }   // doSave
                                preSave(doSave);
                                return false;
                            }, function (win) {
                                planWin = win;
                                bindPlanActions(win);
                            }, true);                  // keepOpen: jendela tetap terbuka sampai simpan berhasil
                        },
                        failure: function() {
                            Ext.MessageBox.hide();
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