/*
  Document      : project.progress.js
  Description   : Progress Proyek (level proyek). Grid utama = daftar proyek dengan indikator progress terverifikasi vs biaya
                  (Earned Value sederhana); klik baris -> panel detail di bawah grid (angka EVM + riwayat klaim + tombol aksi).
                  Alur: lapangan mengajukan klaim (persen + bukti) -> orang LAIN memverifikasi / menolak -> baru projects.progress berubah.
                  Nilai hasil  = Budget (x kurs anggaran) x progress terverifikasi
                  Biaya aktual = pemakaian material (issue PROJECT) + biaya non-material
                  Indeks biaya = Nilai hasil / Biaya aktual (kuning < 0,90; merah < 0,80 -- ambang dari server)
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/project.progressCRD.php
=======
  Backend       : /rab-project/live/php-crud/project.progressCRD.php
>>>>>>> Stashed changes
  [NEW 2026-10-13 #2] Dialog Lapor Progress memakai gaya gelap (tombol Ajukan / Batal kini terbaca) + unggah bukti foto / PDF
                  (maks 5 file x 5 MB) + isian teks (no. BAP). Tombol Verifikasi mengikuti flag server allow_self_verify;
                  untuk klaim milik sendiri tampil keterangan 'verifikasi oleh pengguna lain'.
  [NEW 2026-10-13] Tombol '+ Lapor Progress' di panel detail (selain ikon Add di toolbar); tombol Verifikasi / Tolak / Batalkan
                  muncul di baris klaim berstatus Diajukan. Edit / Hapus sengaja dinonaktifkan (klaim tidak boleh diubah: jejak audit).
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    // [NEW] gaya dialog gelap (sama dengan dialog Konfirmasi Dikirim di Warehouse Issuing) agar tombol & field terbaca
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
                       '.whDarkWin .x-btn-mc button{padding:7px 18px !important;height:auto !important;line-height:18px !important;font-size:13px !important;}';
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    // CSS Tombol Ikon Aksi Satu Baris
    (function () {
        if (document.getElementById('pgActBtnCss')) { return; }
        var st = document.createElement('style');
        st.id = 'pgActBtnCss';
        st.innerHTML = 
            '.btn-act-icon {' +
            '   display: inline-flex !important;' +
            '   align-items: center !important;' +
            '   justify-content: center !important;' +
            '   width: 28px !important;' +
            '   height: 28px !important;' +
            '   padding: 0 !important;' +
            '   border: none !important;' +
            '   border-radius: 4px !important;' +
            '   cursor: pointer !important;' +
            '   transition: all 0.2s ease !important;' +
            '}' +
            '.btn-act-icon svg { display: block; }' +
            '.btn-verify { background-color: #03615e !important; color: #ffffff !important; }' +
            '.btn-verify:hover { background-color: #048a86 !important; transform: scale(1.08); }' +
            '.btn-reject { background-color: #8e2b22 !important; color: #ffffff !important; }' +
            '.btn-reject:hover { background-color: #b8382c !important; transform: scale(1.08); }' +
            '.btn-cancel { background-color: #4a5568 !important; color: #ffffff !important; }' +
            '.btn-cancel:hover { background-color: #718096 !important; transform: scale(1.08); }';
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    document.getElementById("mainHeaderText").innerHTML = "Progress <em>Proyek</em>";

    var CRD = '../php-crud/project.progressCRD.php';
    var ACCESS_REPORT = 2;      // lapor / batalkan klaim
    var ACCESS_VERIFY = 3;      // verifikasi / tolak
    var dataRecord = null;      // baris proyek terpilih
    var currentUserId = 0;      // dari list_log (untuk aturan pelapor != pemverifikasi)
    var allowSelfVerify = false; // dari list_log: bila true, pelapor boleh memverifikasi klaimnya sendiri (uji coba)
    var thresholds = { yellow: 0.9, red: 0.8 };

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtCpi(v) { return (v === null || v === undefined) ? '-' : Ext.util.Format.number(v, '0.00'); }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () { if (onFail) onFail(); else Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
        });
    }

    function proofLinks(v) {
        if (!v) { return '-'; }
        var out = [], parts = String(v).split(/\r?\n/);
        for (var i = 0; i < parts.length; i++) {
            var u = parts[i].replace(/^\s+|\s+$/g, '');
            if (!u) { continue; }
            if (!/^(https?:\/\/|\/)/i.test(u)) { out.push(enc(u)); continue; }
            var label = /\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(u) ? 'Foto' : (/\.pdf(\?.*)?$/i.test(u) ? 'PDF' : 'Link');
            out.push('<a href="' + enc(u) + '" target="_blank" style="color:#7fe0a0;">' + label + ' ' + (out.length + 1) + '</a>');
        }
        return out.length ? out.join(' &nbsp;|&nbsp; ') : '-';
    }

    function cpiColor(st) {
        switch (st) {
            case 'OK': return '#00ff4c !important';
            case 'KUNING': return '#e6a817 !important';
            case 'MERAH': return '#ff4d3d !important';
        }
        return '#999999 !important';
    }
    function claimColor(st) {
        switch (st) {
            case 'Diajukan': return '#e6a817 !important';
            case 'Diverifikasi': return '#00ff4c !important';
            case 'Ditolak': return '#ff4d3d !important';
        }
        return '#999999 !important';
    }

    // ------------------------------------------------------------
    // 0. Panel detail (di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var mainHeight = Math.round(GridMainHeight * 0.5);
    var DtlHeight = Math.round(GridMainHeight - mainHeight - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'progDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight;
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetail(projectId) {
        ajax({ task: 'list_log', project_id: projectId }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat riwayat klaim.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            currentUserId = res.user_id || 0;
            allowSelfVerify = !!res.allow_self_verify;
            renderDetail(res.data || []);
        }, function () {
            dtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
            dtlDiv.style.display = 'block';
        });
    }

    function renderDetail(rows) {
        var d = dataRecord;
        if (!d) { dtlDiv.style.display = 'none'; return; }
        var noBudget = !(d.budget_base > 0);
        var canReport = getFormAccess() >= ACCESS_REPORT && (d.status === 'Berjalan' || d.status === 'Tertunda');
        var reportBar = '<div style="padding:2px 2px 8px 2px; text-align:right;">' +
            (canReport
                ? '<button type="button" id="pgReportBtn" style="padding:6px 16px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">+ Lapor Progress</button>'
                : '<span style="color:#668894; font-size:11px;">' + (getFormAccess() < ACCESS_REPORT ? 'Anda tidak memiliki akses melapor progress.' : 'Proyek berstatus ' + enc(d.status) + ': progress tidak dapat dilaporkan.') + '</span>') +
            '</div>';
        
        var html = '<h3 class="detail-title">Progress: ' + enc(d.name) + ' <span style="color:' + cpiColor(d.cpi_status) + ';">(' + enc(d.cpi_status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' + reportBar +
                   '<div style="padding:6px 10px; margin:0 2px 10px 2px; background:#1f2d3a; border-radius:4px; line-height:1.7;">' +
                   (noBudget
                       ? '<span style="color:#ff6b5e;">Budget proyek belum diisi di Master Proyek - indeks biaya tidak dapat dihitung.</span><br>'
                       : 'Budget (setara): <b>' + fmtAmt(d.budget_base) + '</b> &nbsp;|&nbsp; Progress terverifikasi: <b>' + d.progress + '%</b>' +
                         ' &nbsp;|&nbsp; Nilai hasil: <b>' + fmtAmt(d.earned_value) + '</b><br>') +
                   'Biaya aktual: <b>' + fmtAmt(d.cost_actual) + '</b> (material ' + fmtAmt(d.cost_material) + ' + lain ' + fmtAmt(d.cost_other) + ')' +
                   (d.cost_pct !== null ? ' &nbsp;|&nbsp; Biaya / Budget: <b>' + fmtAmt(d.cost_pct) + '%</b>' : '') +
                   ' &nbsp;|&nbsp; <span style="color:' + cpiColor(d.cpi_status) + ';">Indeks biaya: <b>' + fmtCpi(d.cpi) + '</b></span>' +
                   '<br><span style="color:#668894; font-size:11px;">Indeks biaya = nilai hasil / biaya aktual. Di bawah ' + fmtCpi(thresholds.yellow) +
                   ' kuning, di bawah ' + fmtCpi(thresholds.red) + ' merah (biaya melaju lebih cepat dari pekerjaan).</span></div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Tanggal</th><th align="right">Klaim</th><th align="left">Pelapor</th><th align="left">Bukti</th>' +
                   '<th align="left">Catatan</th><th align="center">Status</th><th align="left">Verifikasi</th><th align="center">Aksi</th></tr></thead><tbody>';
        
        if (!rows.length) {
            html += '<tr><td colspan="8" style="padding:15px; text-align:center; color:#557982;">Belum ada klaim progress. Klik "+ Lapor Progress" untuk mengajukan; verifikasi dilakukan pengguna lain (akses level 3).</td></tr>';
        }

        // SVG Icons untuk tombol aksi
        var iconCheck = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        var iconCross = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        var iconBan   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>';

        Ext.each(rows, function (r) {
            var acts = '';
            if (r.status === 'Diajukan') {
                var btnList = [];
                if (getFormAccess() >= ACCESS_VERIFY && (allowSelfVerify || r.reported_by !== currentUserId)) {
                    btnList.push('<button type="button" class="pgAct btn-act-icon btn-verify" data-act="verify" data-id="' + r.id + '" title="Verifikasi Progress">' + iconCheck + '</button>');
                    btnList.push('<button type="button" class="pgAct btn-act-icon btn-reject" data-act="reject" data-id="' + r.id + '" title="Tolak Klaim">' + iconCross + '</button>');
                } else if (r.reported_by === currentUserId) {
                    acts += '<span style="color:#e6a817; font-size:11px;">Anda pelapor: verifikasi oleh pengguna lain</span> ';
                } else if (getFormAccess() < ACCESS_VERIFY) {
                    acts += '<span style="color:#668894; font-size:11px;">menunggu verifikator</span> ';
                }
                
                if (getFormAccess() >= ACCESS_REPORT && (r.reported_by === currentUserId || getFormAccess() >= ACCESS_VERIFY)) {
                    btnList.push('<button type="button" class="pgAct btn-act-icon btn-cancel" data-act="cancel" data-id="' + r.id + '" title="Batalkan Klaim">' + iconBan + '</button>');
                }

                if (btnList.length > 0) {
                    acts = '<div style="display:inline-flex; align-items:center; justify-content:center; gap:4px; white-space:nowrap;">' + btnList.join('') + '</div>';
                }
            }

            var ver = r.verified_at ? enc(r.verifier_name || '-') + '<br><span style="color:#668894; font-size:11px;">' + enc(String(r.verified_at).substr(0, 16)) + '</span>' : '-';
            if (r.verify_note) ver += '<br><span style="font-size:11px;">' + enc(r.verify_note) + '</span>';
            if (r.snap_cpi !== null && r.snap_cpi !== undefined) ver += '<br><span style="color:#9fc3d1; font-size:11px;">indeks saat verifikasi ' + fmtCpi(r.snap_cpi) + '</span>';
            
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(r.report_date) + '</td>' +
                    '<td align="right"><b>' + r.percent_claimed + '%</b> <span style="color:#668894;">(dari ' + r.prev_percent + '%)</span></td>' +
                    '<td align="left">' + enc(r.reporter_name || '-') + '</td>' +
                    '<td align="left">' + proofLinks(r.evidence_ref) + '</td>' +
                    '<td align="left">' + enc(r.note || '') + '</td>' +
                    '<td align="center" style="color:' + claimColor(r.status) + ';font-weight:bold;">' + enc(r.status) + '</td>' +
                    '<td align="left">' + ver + '</td>' +
                    '<td align="center" style="white-space:nowrap;">' + acts + '</td></tr>';
        });
        html += '</tbody></table>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var rb = document.getElementById('pgReportBtn');
        if (rb) { rb.onclick = function () { openClaimWindow(dataRecord); }; }

        var btns = dtlDiv.querySelectorAll('.pgAct');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                var id = parseInt(this.getAttribute('data-id'), 10), act = this.getAttribute('data-act');
                var claim = null;
                Ext.each(rows, function (r) { if (r.id === id) { claim = r; } });
                if (claim) doAction(act, claim);
            };
        }
    }

    // ------------------------------------------------------------
    // 1. Store & grid utama
    // ------------------------------------------------------------
    var projField = [
        { name: 'id', type: 'int' }, { name: 'name' }, { name: 'status' }, { name: 'progress', type: 'int' },
        { name: 'budget_base', type: 'float' }, { name: 'contract_value', type: 'float' },
        { name: 'cost_material', type: 'float' }, { name: 'cost_other', type: 'float' }, { name: 'cost_actual', type: 'float' },
        { name: 'earned_value', type: 'float' }, { name: 'cost_pct', type: 'float' }, { name: 'cpi', type: 'float' },
        { name: 'cpi_status' }, { name: 'pending_count', type: 'int' }
    ];

    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, projField),
        listeners: {
            load: function (store) {
                var t = (store.reader.jsonData || {}).thresholds;
                if (t) { thresholds = t; }
            },
            exception: function (proxy, type, action, options, response) {
                var msg = 'Gagal memuat data dari server.';
                try { var r = Ext.util.JSON.decode(response.responseText); if (r && r.message) msg = r.message; }
                catch (e) { msg += ' (respons bukan JSON, cek error_log PHP)'; }
                Ext.Msg.alert('Gagal', msg);
            }
        }
    });
    mainDs.load();

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'Proyek', dataIndex: 'name', width: 210, renderer: CellWrap },
            { header: 'Status', dataIndex: 'status', width: 85, align: 'center' },
            { header: 'Progress (terverifikasi)', dataIndex: 'progress', width: 110, align: 'center', renderer: function (v) { return '<b>' + (v || 0) + '%</b>'; } },
            { header: 'Budget (setara)', dataIndex: 'budget_base', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Nilai Hasil', dataIndex: 'earned_value', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Biaya Aktual', dataIndex: 'cost_actual', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Biaya / Budget', dataIndex: 'cost_pct', width: 90, align: 'right', renderer: function (v) { return (v === null || v === undefined) ? '-' : fmtAmt(v) + '%'; } },
            { header: 'Indeks Biaya', dataIndex: 'cpi', width: 85, align: 'right', renderer: function (v) { return fmtCpi(v); } },
            {
                header: 'Indikator', dataIndex: 'cpi_status', width: 90, align: 'center',
                renderer: function (v) { return '<span style="color:' + cpiColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; }
            },
            {
                header: 'Menunggu Verifikasi', dataIndex: 'pending_count', width: 110, align: 'center',
                renderer: function (v) { return v > 0 ? '<span style="color:#e6a817;font-weight:bold;">' + v + ' klaim</span>' : '-'; }
            }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                loadDetail(dataRecord.id);
            }
        }
    });

    editDataDisable();
    mainBtnDisable('#deleteData');
    mainBtnEnable('#addData');
    document.getElementById('addData').title = 'Lapor Progress (pilih proyek dulu)';

    function refreshAll(projectId) {
        mainDs.load({
            callback: function () {
                var idx = mainDs.findBy(function (r) { return r.get('id') === projectId; });
                if (idx >= 0) {
                    dataRecord = mainDs.getAt(idx).data;
                    loadDetail(projectId);
                } else {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                }
            }
        });
    }

    // ------------------------------------------------------------
    // 2. Aksi: lapor, verifikasi, tolak, batalkan
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_REPORT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk melaporkan progress.');
            return;
        }
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Berjalan' && dataRecord.status !== 'Tertunda') {
            Ext.Msg.alert('Peringatan', 'Progress hanya bisa dilaporkan untuk proyek berstatus Berjalan / Tertunda (status sekarang: ' + dataRecord.status + ').');
            return;
        }
        openClaimWindow(dataRecord);
    };

    function openClaimWindow(proj) {
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Laporan', format: 'd/m/Y', value: new Date(), maxValue: new Date(), allowBlank: false, width: 130 });
        var fPct = new Ext.form.NumberField({
            fieldLabel: 'Progress Diklaim (%)', allowDecimals: false, allowNegative: false, minValue: proj.progress + 1, maxValue: 100,
            allowBlank: false, width: 90,
            minText: 'Harus lebih besar dari progress terverifikasi (' + proj.progress + '%).'
        });
        var fEvid = new Ext.form.TextField({ fieldLabel: 'No. BAP / keterangan bukti', anchor: '95%' });
        var fNote = new Ext.form.TextArea({ fieldLabel: 'Catatan', anchor: '95%', height: 55 });

        var proofs = [], uploading = 0;
        var fProofList = new Ext.form.DisplayField({ fieldLabel: 'Bukti foto / PDF', anchor: '95%', value: '' });
        function renderProofs() {
            var h = '';
            for (var i = 0; i < proofs.length; i++) {
                h += '<div>' + (i + 1) + '. ' + proofLinks(proofs[i]) + ' &nbsp;<span style="color:#e05353;cursor:pointer;font-weight:bold;" title="Hapus" onclick="window.pgProofRemove(' + i + ');">&#10005;</span></div>';
            }
            if (uploading > 0) { h += '<div style="color:#9fc3d1;">Mengunggah ' + uploading + ' file...</div>'; }
            fProofList.setValue(h || '<span style="color:#668894;">Belum ada file</span>');
        }
        window.pgProofRemove = function (i) { proofs.splice(i, 1); renderProofs(); };
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,.pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
        fileInput.onchange = function () {
            var f = fileInput.files && fileInput.files[0];
            if (!f) { return; }
            if (proofs.length + uploading >= 5) { Ext.Msg.alert('Peringatan', 'Bukti maksimal 5 file.'); fileInput.value = ''; return; }
            if (f.size > 5 * 1024 * 1024) { Ext.Msg.alert('Peringatan', 'Ukuran file maksimal 5 MB.'); fileInput.value = ''; return; }
            var fd = new FormData();
            fd.append('task', 'upload_evidence');
            fd.append('file', f);
            fileInput.value = '';
            uploading++; renderProofs();
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CRD);
            xhr.onload = function () {
                uploading--;
                var o;
                try { o = Ext.decode(xhr.responseText); } catch (e) { renderProofs(); Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                if (!o.success) { renderProofs(); Ext.Msg.alert('Gagal', o.message || 'Unggah gagal.'); return; }
                proofs.push(o.data.url); renderProofs();
            };
            xhr.onerror = function () { uploading--; renderProofs(); Ext.Msg.alert('Gagal', 'Koneksi ke server gagal saat mengunggah.'); };
            xhr.send(fd);
        };
        var fProofBtn = new Ext.Button({ text: '+ Unggah Foto / PDF Bukti...', minWidth: 220, handler: function () { fileInput.click(); } });
        renderProofs();

        var win = new Ext.Window({
            title: 'Lapor Progress - ' + proj.name, width: 520, height: 470, modal: true, layout: 'fit', cls: 'whDarkWin', minButtonWidth: 150,
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 150, bodyStyle: 'padding:12px; background:transparent;',
                items: [{ xtype: 'displayfield', hideLabel: true, value: 'Progress terverifikasi saat ini: <b>' + proj.progress + '%</b>. Klaim harus lebih besar dan akan diverifikasi oleh orang lain sebelum berlaku. Bukti wajib: unggah foto / PDF dan / atau isi no. BAP.' },
                        fDate, fPct, fProofList, fProofBtn, fEvid, fNote]
            }),
            buttons: [
                { text: 'Ajukan Klaim', handler: function () {
                    if (!fDate.isValid() || !fPct.isValid()) { Ext.Msg.alert('Peringatan', 'Lengkapi tanggal dan persen (lebih besar dari ' + proj.progress + '%).'); return; }
                    if (uploading > 0) { Ext.Msg.alert('Peringatan', 'Masih ada file yang sedang diunggah, tunggu sampai selesai.'); return; }
                    var lines = proofs.slice(0);
                    if (Ext.util.Format.trim(fEvid.getValue() || '') !== '') { lines.push(Ext.util.Format.trim(fEvid.getValue())); }
                    if (!lines.length) { Ext.Msg.alert('Peringatan', 'Bukti wajib: unggah foto / PDF atau isi no. BAP.'); return; }
                    var params = {
                        task: 'submit_claim', project_id: proj.id, report_date: ymd(fDate.getValue()),
                        percent_claimed: fPct.getValue(), evidence_ref: lines.join('\n'), note: fNote.getValue()
                    };
                    ajax(params, function (res) {
                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                        if (res.success) { win.close(); refreshAll(proj.id); }
                    });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ],
            listeners: { close: function () {
                if (fileInput.parentNode) { fileInput.parentNode.removeChild(fileInput); }
                window.pgProofRemove = null;
            } }
        });
        win.show();
    }

    function doAction(act, claim) {
        var pid = dataRecord.id;
        if (act === 'cancel') {
            Ext.Msg.confirm('Batalkan Klaim', 'Batalkan klaim progress <b>' + claim.percent_claimed + '%</b>?', function (b) {
                if (b !== 'yes') return;
                ajax({ task: 'cancel_claim', id: claim.id }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) refreshAll(pid);
                });
            });
            return;
        }
        if (getFormAccess() < ACCESS_VERIFY) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk memverifikasi progress.');
            return;
        }
        var approve = (act === 'verify');
        var msg;
        if (approve) {
            var ev = dataRecord.budget_base * claim.percent_claimed / 100;
            var cpi = dataRecord.cost_actual > 0 ? ev / dataRecord.cost_actual : null;
            msg = 'Setujui progress <b>' + claim.percent_claimed + '%</b> (bukti: ' + proofLinks(claim.evidence_ref) + ')?<br>' +
                  'Pada progress ini: nilai hasil <b>' + fmtAmt(ev) + '</b>, biaya aktual <b>' + fmtAmt(dataRecord.cost_actual) + '</b>, indeks biaya <b>' + fmtCpi(cpi) + '</b>.' +
                  (cpi !== null && cpi < thresholds.red ? '<br><span style="color:#ff6b5e;">Indeks MERAH - catatan verifikasi wajib bila tetap disetujui.</span>' : '') +
                  '<br>Catatan verifikasi:';
        } else {
            msg = 'Tolak klaim progress <b>' + claim.percent_claimed + '%</b>?<br>Alasan penolakan (wajib):';
        }
        // [CHANGED] Ext.Msg.prompt -> Ext.Msg.show agar ukuran dialog bisa diatur: lebar 460px, kolom catatan tinggi 90px.
        //           Warna kolom catatan diatur di css/frm.css (.ext-mb-textarea).
        Ext.Msg.show({
            title: approve ? 'Verifikasi Progress' : 'Tolak Klaim', msg: msg,
            width: 520, minWidth: 520,                 // [CHANGED] 460 -> 520: box catatan tidak terpotong
            prompt: true, multiline: 90,               // [CHANGED] textarea 90px (bawaan 75px)
            buttons: Ext.Msg.OKCANCEL, scope: this,
            fn: function (btn, text) {
            if (btn !== 'ok') return;
            text = Ext.util.Format.trim(text || '');
            if (!approve && text === '') { Ext.Msg.alert('Peringatan', 'Alasan penolakan wajib diisi.'); return; }
            ajax({ task: 'verify', id: claim.id, decision: approve ? 'Diverifikasi' : 'Ditolak', verify_note: text }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) refreshAll(pid);
            });
            }
        });
        // [NEW] jaga-jaga: bila ExtJS tidak memakai lebar yang diminta, paksa lebar dialog minimal 520px lalu tengahkan
        var msgDlg = Ext.Msg.getDialog();
        if (msgDlg && msgDlg.getWidth() < 520) { msgDlg.setWidth(520); msgDlg.doLayout(); msgDlg.center(); }
    }

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };
    document.getElementById('editorWdw').style.display = 'none';
});