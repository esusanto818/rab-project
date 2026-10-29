/*
  Document      : m.proyek.js
  Description   : ExtJS Controller untuk pengelolaan Master Proyek dengan Integrasi AI Task Breakdown
                   - Panel Detail Sub-Task AI di Layar Utama (Main Screen)
                   - Editor Form Responsif & Tinggi Maksimal
  Backend       : /rab-project/php-crud/m.proyekCRD.php
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
    function showPlanWin(title, html, yesText, onYes) {
        var w = Math.min(1000, Math.round((window.innerWidth || 1200) * 0.92));
        var h = Math.min(560, Math.round((window.innerHeight || 800) * 0.85));
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
        var no = document.getElementById(uid + 'No'), yes = document.getElementById(uid + 'Yes');
        if (no) no.onclick = function () { win.close(); };
        if (yes) yes.onclick = function () { win.close(); onYes(); };
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
            { name: 'id' }, { name: 'task_name' }, { name: 'description' },
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
                   '<table width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#fff; font-size:13px;">' +
                   '<thead><tr style="background:#1a2f40; color:#00bcd4; text-align:left; font-weight:bold;">' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Nama Sub-Task (WBS)</th>' +
                   '<th style="border-bottom:1px solid #2a485d; padding:8px;">Deskripsi Pekerjaan</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Tgl Mulai</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Tgl Selesai</th>' +
                   '<th align="center" style="border-bottom:1px solid #2a485d; padding:8px;">Status</th>' +
                   '</tr></thead><tbody>';

        if (!records || !records.length) {
            html += '<tr><td colspan="5" style="padding:15px; text-align:center; color:#688998;">Belum ada analisis sub-task AI untuk proyek ini. Klik <b>Edit</b> untuk membuat rincian task.</td></tr>';
        } else {
            Ext.each(records, function (r) {
                var d = r.data;
                html += '<tr style="border-bottom:1px solid #182e3e;">' +
                        '<td style="font-weight:bold; color:#e0f7fa;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.task_name) + '</td>' +
                        '<td style="color:#b0bec5;padding:12px 8px;">' + Ext.util.Format.htmlEncode(d.description) + '</td>' +
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
            { name: 'id' }, { name: 'task_name' }, { name: 'description' },
            { name: 'start_date' }, { name: 'end_date' }, { name: 'status' }
        ])
    });

    var editorTaskGrid = new Ext.grid.GridPanel({
        store: editorTaskStore,
        height: editorGridH,
        title: 'Rincian Sub-Task Proyek oleh AI (Berdasarkan Definisi & Rentang Tanggal)',
        columns: [
            { header: 'Nama Task', dataIndex: 'task_name', width: 160 },
            { header: 'Deskripsi', dataIndex: 'description', width: 240 },
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
                        Ext.Msg.alert('Peringatan', 'Simpan proyek terlebih dahulu sebelum menjalankan analisis task AI.');
                        return;
                    }
                    Ext.MessageBox.wait('AI sedang membaca definisi proyek & memecah sub-task...', 'Mohon Tunggu');
                    Ext.Ajax.request({
                        url: CRD,
                        method: 'POST',
                        params: { task: 'ai_generate_tasks', project_id: projId },
                        success: function(response) {
                            Ext.MessageBox.hide();
                            var res = Ext.util.JSON.decode(response.responseText);
                            if (res.success) {
                                var msg = res.message;
                                if (!res.coverage_valid && res.notes.length) {
                                    msg += '<br><br><span style="color:red;">Peringatan Tanggal:<br>' + res.notes.join('<br>') + '</span>';
                                } else {
                                    msg += '<br><br><span style="color:green;">Semua tanggal kerja sub-task tercover sempurna dalam rentang proyek!</span>';
                                }
                                // [AUTO-INV] hasil penerbitan tagihan otomatis (dijalankan server setelah task tersimpan)
                                if (res.invoice) {
                                    msg += '<br><br><b>Tagihan otomatis:</b> ' + res.invoice.made + ' diterbitkan, ' + res.invoice.synced + ' disesuaikan.<br>' +
                                           planHtml(res.invoice.rows, res.invoice.analysis);
                                } else if (res.invoice_error) {
                                    msg += '<br><br><span style="color:#e6a817;">Tagihan otomatis belum diterbitkan: ' + Ext.util.Format.htmlEncode(res.invoice_error) + '</span>';
                                }
                                showPlanWin('Analisis Selesai', msg);
                                editorTaskStore.load({ params: { project_id: projId } });
                                loadMainDetailPanel(projId);
                            } else {
                                Ext.Msg.alert('Gagal', res.message);
                            }
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