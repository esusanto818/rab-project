/*
  Document      : subcon.js
  Description   : Subkontraktor. Grid utama = daftar SPK; klik baris -> panel detail di bawah grid (ringkasan keuangan, task, prestasi,
                   pembayaran, jurnal, jejak audit, tombol aksi). Add/Edit membuka editor SPK (header + pembagian nilai ke task proyek).
                   Prestasi, pembayaran, dan subkon baru memakai dialog (tombol HTML gaya standar).
                   Alur SPK: Draft -> Diajukan -> Disetujui -> Selesai (Dibatalkan selama belum ada prestasi / pembayaran).
                   Prestasi : progres kumulatif % per task -> nilai periode - PPh - retensi - potongan uang muka + PPN = nilai bayar.
                   Edit & Delete SPK hanya untuk status Draft. Dokumen turunan tidak dihapus, hanya Dibatalkan (alasan wajib).
  Backend       : ../php-crud/subconCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Sub<em>kontraktor</em>";

    var CRD = main_url + '/php-crud/subconCRD.php';

    var ACCESS_ADD = 2;          // level akses minimal: buat SPK / prestasi / pembayaran
    var ACCESS_EDIT = 3;         // level akses minimal: ubah, hapus, ganti status, setujui, batalkan
    var VEPS = 0.01;
    var PPN_DEFAULT = 11;        // tarif PPN default bila subkon PKP

    var dataRecord = null, editingId = 0, hdrForm = null, hdrForm2 = null;
    var curDetail = null;        // hasil load_contract terakhir (dipakai dialog)
    var fProject, fSub, fTitle, fBud, fValue, fPpn, fPph, fRet, fDp, fStart, fEnd, fScope, fNotes;

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtPct(v) { return Ext.util.Format.number(v || 0, '0,000.00') + '%'; }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function uiBtn(cls, label, bg, attrs) {      // tombol HTML gaya standar UI (putih tebal, 8px 20px, radius 4px)
        return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' style="margin-left:8px;padding:8px 20px;background:' + bg +
               ';color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">' + label + '</button>';
    }
    var TEAL = '#066b78', GREY = '#555555', BURG = '#800020';
    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diajukan': return '#e6a817 !important';
            case 'Disetujui': return '#2bc1e2 !important';
            case 'Selesai': return '#00ff4c !important';
            case 'Dibatalkan': case 'Dibatalkan ': return '#c0392b !important';
            case 'Posted': return '#00ff4c !important';
        }
        return '#ffffff !important';
    }
    var ACTION_LABEL = { Diajukan: 'Ajukan', Disetujui: 'Setujui SPK', Draft: 'Kembalikan ke Draft', Selesai: 'Tutup SPK (Selesai)', Dibatalkan: 'Batalkan SPK' };

    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }
    function reasonPrompt(title, fn) {
        Ext.Msg.prompt(title, 'Alasan (wajib, min. 5 karakter):', function (btn, txt) { if (btn === 'ok') fn(txt); }, this, true);
    }

    // ------------------------------------------------------------
    // 0. Panel detail
    // ------------------------------------------------------------
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'scDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);
    dtlDiv.style.overflowY = 'auto';
    var gridContainer = document.getElementById('divMainGrid');
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling); }

    function loadDetailPanel(id) {
        ajax({ task: 'load_contract', id: id }, function (res) {
            if (!res.success) { dtlDiv.innerHTML = '<div style="color:yellow;padding:10px;">' + enc(res.message || 'Gagal memuat detail SPK.') + '</div>'; dtlDiv.style.display = 'block'; return; }
            curDetail = res.data;
            renderDetail(res.data);
        });
    }

    function th(label, align) { return '<th align="' + (align || 'left') + '">' + label + '</th>'; }
    function td(v, align, style) { return '<td align="' + (align || 'left') + '"' + (style ? ' style="' + style + '"' : '') + '>' + v + '</td>'; }

    function renderDetail(d) {
        var h = d.header, t = d.totals, tasks = d.tasks || [], prog = d.progress || [], pays = d.payments || [], jr = d.journals || [];
        var html = '<h3 class="detail-title">SPK: ' + enc(h.spk_no) + ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3><hr class="detail-hr" />' +
            '<div style="padding:4px 2px 10px 2px;">Proyek: <b>' + enc(h.project_name) + '</b> &nbsp;|&nbsp; Subkon: <b>' + enc(h.subcontractor_name) + '</b>' +
            (h.npwp_no ? ' (NPWP ' + enc(h.npwp_no) + ')' : '') + ' &nbsp;|&nbsp; Pekerjaan: <b>' + enc(h.title) + '</b>' +
            (h.budget_item_name ? ' &nbsp;|&nbsp; RAB: <b>' + enc(h.budget_item_name) + '</b>' : '') +
            ' &nbsp;|&nbsp; Jadwal: ' + enc(h.start_date || '-') + ' s/d ' + enc(h.end_date || '-') + '</div>' +
            '<div style="padding:0 2px 10px 2px;color:#9fc3d1;">PPN ' + h.ppn_rate + '% &nbsp;|&nbsp; PPh final ' + h.pph_rate + '% &nbsp;|&nbsp; Retensi ' + h.retention_pct + '% &nbsp;|&nbsp; Uang muka ' + h.dp_pct + '%' +
            (h.scope ? ' &nbsp;|&nbsp; Lingkup: ' + enc(h.scope) : '') + '</div>';

        // ringkasan keuangan
        html += '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('Nilai Kontrak (DPP)', 'right') + th('Progres Tertagih', 'right') + th('Tertagih (DPP)', 'right') + th('Hutang Prestasi', 'right') + th('Sudah Dibayar', 'right') + th('Sisa Hutang', 'right') +
            th('Uang Muka (bayar / potong / sisa)', 'right') + th('Retensi (ditahan / sisa)', 'right') + '</tr></thead><tbody><tr class="data-row">' +
            td(fmtAmt(h.contract_value), 'right') + td(fmtPct(t.billed_pct), 'right') + td(fmtAmt(t.gross), 'right') + td(fmtAmt(t.net), 'right') + td(fmtAmt(t.progress_paid), 'right') +
            td('<b>' + fmtAmt(t.payable_balance) + '</b>', 'right') +
            td(fmtAmt(t.dp_paid) + ' / ' + fmtAmt(t.dp_recovered) + ' / <b>' + fmtAmt(t.dp_balance) + '</b>', 'right') +
            td(fmtAmt(t.retention) + ' / <b>' + fmtAmt(t.retention_balance) + '</b>', 'right') + '</tr></tbody></table>';

        // task
        html += '<h4 style="margin:14px 0 4px 0;">Pembagian Pekerjaan per Task</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('Task') + th('Jadwal task') + th('Nilai Task (DPP)', 'right') + th('Progres Disetujui', 'right') + th('Nilai Tertagih', 'right') + '</tr></thead><tbody>';
        Ext.each(tasks, function (x) {
            html += '<tr class="data-row">' + td(enc(x.task_name)) + td(x.start_date ? enc(x.start_date) + ' s/d ' + enc(x.end_date || '-') : '-') +
                    td(fmtAmt(x.task_value), 'right') + td(fmtPct(x.cum_pct), 'right') + td(fmtAmt(x.billed_amount), 'right') + '</tr>';
        });
        html += '</tbody></table>';

        // prestasi
        html += '<h4 style="margin:14px 0 4px 0;">Prestasi</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('No.') + th('Tanggal') + th('Progres Kum.', 'right') + th('Nilai (DPP)', 'right') + th('PPN', 'right') + th('PPh', 'right') + th('Retensi', 'right') + th('Pot. Uang Muka', 'right') +
            th('Nilai Bayar', 'right') + th('Dibayar', 'right') + th('Status') + th('Jejak') + th('') + '</tr></thead><tbody>';
        if (!prog.length) html += '<tr><td colspan="13" style="padding:12px;text-align:center;color:#557982;">Belum ada prestasi.</td></tr>';
        Ext.each(prog, function (p) {
            var acts = '';
            if (p.status === 'Draft') acts += uiBtn('scAct', 'Edit', GREY, 'data-act="edit_progress" data-id="' + p.id + '"') + uiBtn('scAct', 'Setujui', TEAL, 'data-act="approve_progress" data-id="' + p.id + '"');
            if (p.status !== 'Dibatalkan') acts += uiBtn('scAct', 'Batal', BURG, 'data-act="cancel_progress" data-id="' + p.id + '"');
            html += '<tr class="data-row">' + td(enc(p.ref_no)) + td(enc(p.progress_date)) + td(fmtPct(p.cum_pct), 'right') + td(fmtAmt(p.gross_amount), 'right') +
                td(fmtAmt(p.ppn_amount), 'right') + td(fmtAmt(p.pph_amount), 'right') + td(fmtAmt(p.retention_amount), 'right') + td(fmtAmt(p.dp_recovery), 'right') +
                td('<b>' + fmtAmt(p.net_payable) + '</b>', 'right') + td(fmtAmt(p.paid_amount), 'right') +
                td('<span style="color:' + statusColor(p.status) + ';font-weight:bold;">' + enc(p.status) + '</span>') +
                td('<span style="color:#668894;font-size:11px;">' + enc(p.created_by_name || '-') + (p.approved_by_name ? ' &rarr; ' + enc(p.approved_by_name) : '') +
                   (p.cancel_reason ? '<br/>batal: ' + enc(p.cancel_reason) : '') + '</span>') + td(acts, 'right') + '</tr>';
        });
        html += '</tbody></table>';

        // pembayaran
        html += '<h4 style="margin:14px 0 4px 0;">Pembayaran</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('No.') + th('Tanggal') + th('Jenis') + th('Prestasi') + th('Akun Kas/Bank') + th('Referensi') + th('Jumlah', 'right') + th('Status') + th('Jejak') + th('') + '</tr></thead><tbody>';
        if (!pays.length) html += '<tr><td colspan="10" style="padding:12px;text-align:center;color:#557982;">Belum ada pembayaran.</td></tr>';
        Ext.each(pays, function (y) {
            var typ = y.pay_type === 'DP' ? 'Uang Muka' : (y.pay_type === 'RETENSI' ? 'Lepas Retensi' : 'Prestasi');
            html += '<tr class="data-row">' + td(enc(y.payment_no)) + td(enc(y.payment_date)) + td(typ) + td(enc(y.progress_ref || '-')) +
                td(enc(y.account_code + ' ' + y.account_name)) + td(enc(y.reference_no || '')) + td(fmtAmt(y.amount), 'right') +
                td('<span style="color:' + statusColor(y.status) + ';font-weight:bold;">' + enc(y.status) + '</span>') +
                td('<span style="color:#668894;font-size:11px;">' + enc(y.created_by_name || '-') + (y.cancel_reason ? '<br/>batal: ' + enc(y.cancel_reason) : '') + '</span>') +
                td(y.status === 'Posted' ? uiBtn('scAct', 'Batal', BURG, 'data-act="cancel_payment" data-id="' + y.id + '"') : '', 'right') + '</tr>';
        });
        html += '</tbody></table>';

        // jejak
        var trail = ['Dibuat: <b>' + enc(h.created_by_name || '-') + '</b> (' + enc(h.created_at) + ')'];
        if (h.approved_at) trail.push('Disetujui: <b>' + enc(h.approved_by_name || '-') + '</b> (' + enc(h.approved_at) + ')');
        if (h.closed_at) trail.push('Ditutup: <b>' + enc(h.closed_by_name || '-') + '</b> (' + enc(h.closed_at) + ')');
        if (h.cancelled_at) trail.push('Dibatalkan: <b>' + enc(h.cancelled_by_name || '-') + '</b> (' + enc(h.cancelled_at) + ') - ' + enc(h.cancel_reason || ''));
        html += '<div style="margin-top:12px;color:#9fc3d1;">' + trail.join(' &nbsp;|&nbsp; ') + '</div>';
        if (jr.length) html += '<div style="margin-top:6px;color:#9fc3d1;">Jurnal terkait: ' + Ext.pluck(jr, 'ref_no').join(', ') + '</div>';

        // tombol aksi
        var btns = '';
        Ext.each(d.allowed_actions || [], function (to) {
            btns += uiBtn('scAct', enc(ACTION_LABEL[to] || to), to === 'Dibatalkan' ? BURG : (to === 'Draft' ? GREY : TEAL), 'data-act="status" data-to="' + enc(to) + '"');
        });
        var hasDraft = false; Ext.each(prog, function (p) { if (p.status === 'Draft') hasDraft = true; });
        if (h.status === 'Disetujui' && !hasDraft && t.billed_pct < 100 - 0.001) btns += uiBtn('scAct', 'Prestasi Baru', TEAL, 'data-act="new_progress"');
        if (h.status === 'Disetujui' && t.dp_planned > 0 && t.dp_paid < t.dp_planned - VEPS) btns += uiBtn('scAct', 'Bayar Uang Muka', GREY, 'data-act="pay_dp"');
        if ((h.status === 'Disetujui' || h.status === 'Selesai') && t.payable_balance > VEPS) btns += uiBtn('scAct', 'Bayar Prestasi', GREY, 'data-act="pay_progress"');
        if (h.status === 'Selesai' && t.retention_balance > VEPS) btns += uiBtn('scAct', 'Lepas Retensi', GREY, 'data-act="pay_retention"');
        if (btns) html += '<div style="margin-top:15px;text-align:right;">' + btns + '</div>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';
        var els = dtlDiv.querySelectorAll('.scAct');
        for (var i = 0; i < els.length; i++) {
            els[i].onclick = function () { onAction(this.getAttribute('data-act'), parseInt(this.getAttribute('data-id'), 10) || 0, this.getAttribute('data-to')); };
        }
    }

    function refreshAll(id) { mainDs.load(); loadDetailPanel(id); updateMainButtons(); }

    function onAction(act, id, to) {
        var h = curDetail.header;
        if (act === 'status') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengubah status SPK.')) return;
            var go = function (reason) {
                ajax({ task: 'change_status', id: h.id, to: to, reason: reason || '' }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { if (dataRecord) dataRecord.status = to; refreshAll(h.id); }
                });
            };
            if (to === 'Dibatalkan') { reasonPrompt('Batalkan SPK', go); return; }
            Ext.Msg.confirm('Ubah Status SPK', 'Ubah status SPK <b>' + enc(h.spk_no) + '</b> menjadi <b>' + enc(to) + '</b>?', function (b) { if (b === 'yes') go(); });
        } else if (act === 'new_progress') {
            if (needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat prestasi.')) openProgress(h.id, 0);
        } else if (act === 'edit_progress') {
            if (needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk mengubah prestasi.')) openProgress(h.id, id);
        } else if (act === 'approve_progress') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk menyetujui prestasi.')) return;
            Ext.Msg.confirm('Setujui Prestasi', 'Setujui prestasi ini? Jurnal (WIP, PPN Masukan, hutang, potongan) akan diposting.', function (b) {
                if (b !== 'yes') return;
                ajax({ task: 'approve_progress', id: id }, function (res) { Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || ''); if (res.success) refreshAll(h.id); });
            });
        } else if (act === 'cancel_progress') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membatalkan prestasi.')) return;
            reasonPrompt('Batalkan Prestasi', function (txt) {
                ajax({ task: 'cancel_progress', id: id, reason: txt }, function (res) { Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || ''); if (res.success) refreshAll(h.id); });
            });
        } else if (act === 'cancel_payment') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membatalkan pembayaran.')) return;
            reasonPrompt('Batalkan Pembayaran', function (txt) {
                ajax({ task: 'cancel_payment', id: id, reason: txt }, function (res) { Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || ''); if (res.success) refreshAll(h.id); });
            });
        } else if (act === 'pay_dp' || act === 'pay_progress' || act === 'pay_retention') {
            if (needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat pembayaran.')) openPayment(act === 'pay_dp' ? 'DP' : (act === 'pay_progress' ? 'PROGRESS' : 'RETENSI'));
        }
    }

    // ------------------------------------------------------------
    // 1. Stores & grid utama
    // ------------------------------------------------------------
    var rdr = function (fields, idp) { return new Ext.data.JsonReader({ root: 'data', idProperty: idp || 'id' }, fields); };
    var projectDs = new Ext.data.Store({ url: CRD, baseParams: { task: 'list_project_options' }, reader: rdr([{ name: 'id' }, { name: 'name' }]), listeners: { exception: storeError } });
    var subDs = new Ext.data.Store({ url: CRD, baseParams: { task: 'list_subcontractors' }, reader: rdr([{ name: 'id' }, { name: 'name' }, { name: 'npwp_no' }, { name: 'is_pkp' }, { name: 'pph_rate', type: 'float' }]), listeners: { exception: storeError } });
    var taskDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_tasks' },
        reader: rdr([{ name: 'id' }, { name: 'task_name' }, { name: 'start_date' }, { name: 'end_date' },
                     { name: 'label', convert: function (v, r) { return r.task_name + ' (' + (r.start_date || '-') + ' s/d ' + (r.end_date || '-') + ')'; } }]),
        listeners: { exception: storeError }
    });
    var budDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_budget_items' },
        reader: rdr([{ name: 'id' }, { name: 'label' }, { name: 'remaining', type: 'float' }]), listeners: { exception: storeError }
    });
    var accDs = new Ext.data.Store({ url: CRD, baseParams: { task: 'list_accounts' }, reader: rdr([{ name: 'id' }, { name: 'label' }]), listeners: { exception: storeError } });

    mainDs = new Ext.data.Store({       // assignment polos (konvensi search box template)
        url: CRD, baseParams: { task: 'list_contracts' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, [
            { name: 'id' }, { name: 'spk_no' }, { name: 'project_id' }, { name: 'project_name' }, { name: 'subcontractor_name' }, { name: 'title' }, { name: 'status' },
            { name: 'contract_value', type: 'float' }, { name: 'progress_pct', type: 'float' }, { name: 'billed_net', type: 'float' },
            { name: 'progress_paid', type: 'float' }, { name: 'payable_balance', type: 'float' }, { name: 'retention_held', type: 'float' }
        ]),
        listeners: { exception: storeError }
    });
    mainDs.load();

    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: mainHeight,
        columns: [
            { header: 'No. SPK', dataIndex: 'spk_no', width: 120 },
            { header: 'Proyek', dataIndex: 'project_name', width: 200, renderer: CellWrap },
            { header: 'Subkontraktor', dataIndex: 'subcontractor_name', width: 160, renderer: CellWrap },
            { header: 'Pekerjaan', dataIndex: 'title', width: 190, renderer: CellWrap },
            { header: 'Nilai Kontrak', dataIndex: 'contract_value', width: 115, align: 'right', renderer: fmtAmt },
            { header: 'Progres', dataIndex: 'progress_pct', width: 70, align: 'right', renderer: fmtPct },
            { header: 'Hutang Prestasi', dataIndex: 'billed_net', width: 115, align: 'right', renderer: fmtAmt },
            { header: 'Dibayar', dataIndex: 'progress_paid', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Sisa Hutang', dataIndex: 'payable_balance', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Retensi Ditahan', dataIndex: 'retention_held', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Status', dataIndex: 'status', width: 85, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } }
        ],
        viewConfig: { forceFit: true }, loadMask: true,
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
        if (dataRecord && dataRecord.status === 'Draft') { mainBtnEnable('#editData'); mainBtnEnable('#deleteData'); }
        else { mainBtnDisable('#editData'); mainBtnDisable('#deleteData'); }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 2. Editor SPK: header + pembagian ke task
    // ------------------------------------------------------------
    var taskField = [{ name: 'task_id' }, { name: 'task_name' }, { name: 'task_value', type: 'float' }, { name: 'jadwal' }, { name: 'notes' }];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, taskField) });
    var editorDeleter = new Extensive.grid.ItemDeleter({ header: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>' });
    itemDs.on('remove', function () { updateSummary(); });

    var itemGrid = new stdGridEditor({
        selModel: editorDeleter, store: itemDs, renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 }, height: 240,
        columns: [
            {
                header: 'Task Proyek (kosongkan = seluruh pekerjaan)', dataIndex: 'task_id', width: 330,
                editor: new myComboField({
                    store: taskDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: false,
                    listeners: { select: function (combo, record) {
                        var row = itemGrid.getSelectionModel().getSelected();
                        if (row) { row.set('task_id', record.get('id')); row.set('task_name', record.get('task_name')); row.set('jadwal', (record.get('start_date') || '-') + ' s/d ' + (record.get('end_date') || '-')); }
                    } }
                }),
                renderer: function (v, m, rec) { return rec.get('task_name') ? enc(rec.get('task_name')) : '<i style="color:#668894;">(seluruh pekerjaan)</i>'; }
            },
            { header: 'Jadwal Task', dataIndex: 'jadwal', width: 170 },
            { header: 'Nilai Task (DPP)', dataIndex: 'task_value', width: 130, align: 'right', editor: new myNumberField({ decimalPrecision: 2, allowNegative: false }), renderer: fmtAmt },
            { header: 'Catatan', dataIndex: 'notes', width: 160, editor: new myTextField() },
            editorDeleter
        ],
        listeners: { afteredit: function () { updateSummary(); } }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (!g || document.getElementById('scHdrForm')) return;
        var mk = function (id, css) { var e = document.createElement('div'); e.id = id; e.style.cssText = css || ''; g.parentNode.insertBefore(e, g); return e; };
        mk('scHdrForm', 'margin-bottom:2px;');
        mk('scHdrForm2', 'margin-bottom:6px;');
        var bar = mk('scAddBar', 'margin:4px 0 6px 0;');
        bar.innerHTML = uiBtn('scBtnAddRow', '+ Tambah Task', '#2e6da4') + uiBtn('scBtnSplit', 'Bagi Rata ke Semua Baris', GREY) + uiBtn('scBtnNewSub', '+ Subkontraktor Baru', GREY);
        var sum = document.createElement('div'); sum.id = 'scSummary';
        sum.style.cssText = 'padding:10px 14px;margin-top:8px;font-size:13px;text-align:right;background:#1f2d3a;color:#ffffff !important;border-radius:4px;';
        g.parentNode.insertBefore(sum, g.nextSibling);
    })();

    function updateSummary() {
        var el = document.getElementById('scSummary');
        if (!el || !fValue) return;
        var sum = 0; itemDs.each(function (r) { sum += parseFloat(r.get('task_value')) || 0; });
        var val = parseFloat(fValue.getValue()) || 0, diff = round2(val - sum), ok = Math.abs(diff) <= VEPS;
        var ppn = round2(val * (parseFloat(fPpn.getValue()) || 0) / 100);
        el.innerHTML = 'Nilai kontrak (DPP) <b>' + fmtAmt(val) + '</b> &nbsp;|&nbsp; PPN <b>' + fmtAmt(ppn) + '</b> &nbsp;|&nbsp; Total + PPN <b>' + fmtAmt(val + ppn) + '</b> &nbsp;|&nbsp; ' +
            'Terbagi ke task <b>' + fmtAmt(sum) + '</b> &nbsp;|&nbsp; Selisih <b style="color:' + (ok ? '#7fe0a0' : '#ff6b5e') + ';">' + fmtAmt(diff) + (ok ? ' (OK)' : ' (harus 0)') + '</b>';
    }

    function ensureForm() {
        if (hdrForm) { hdrForm.doLayout(); hdrForm2.doLayout(); return; }
        fProject = new myComboField({ fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } } });
        fSub = new myComboField({ fieldLabel: 'Subkontraktor', store: subDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function (c, rec) {      // default tarif dari master subkon: PPh dari master, PPN 11% bila PKP
                fPph.setValue(rec.get('pph_rate'));
                var pkp = rec.get('is_pkp'); fPpn.setValue((pkp === true || pkp === 't' || pkp === 'true') ? PPN_DEFAULT : 0);
                updateSummary();
            } } });
        fTitle = new myTextField({ fieldLabel: 'Pekerjaan', anchor: '95%', allowBlank: false });
        fBud = new myComboField({ fieldLabel: 'Baris RAB', store: budDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, anchor: '95%', value: 0 });
        fValue = new myNumberField({ fieldLabel: 'Nilai Kontrak (DPP)', decimalPrecision: 2, allowNegative: false, width: 150, listeners: { change: function () { updateSummary(); } } });
        fPpn = new myNumberField({ fieldLabel: 'Tarif PPN (%)', decimalPrecision: 2, allowNegative: false, width: 80, value: 0, listeners: { change: function () { updateSummary(); } } });
        fPph = new myNumberField({ fieldLabel: 'Tarif PPh Final (%)', decimalPrecision: 2, allowNegative: false, width: 80, value: 0 });
        fRet = new myNumberField({ fieldLabel: 'Retensi (%)', decimalPrecision: 2, allowNegative: false, width: 80, value: 0 });
        fDp = new myNumberField({ fieldLabel: 'Uang Muka (%)', decimalPrecision: 2, allowNegative: false, width: 80, value: 0 });
        fStart = new Ext.form.DateField({ fieldLabel: 'Tgl Mulai', format: 'd/m/Y', width: 130 });
        fEnd = new Ext.form.DateField({ fieldLabel: 'Tgl Selesai', format: 'd/m/Y', width: 130 });
        fScope = new myTextField({ fieldLabel: 'Lingkup', anchor: '95%' });
        fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 120, bodyStyle: 'background:transparent;', items: items }; };
        hdrForm = new Ext.form.FormPanel({ renderTo: 'scHdrForm', border: false, layout: 'column', bodyStyle: 'background:transparent;padding:4px 2px 0 2px;',
            items: [col(0.36, [fProject, fSub, fTitle, fBud]), col(0.30, [fValue, fPpn, fPph]), col(0.34, [fRet, fDp, fStart, fEnd])] });
        hdrForm2 = new Ext.form.FormPanel({ renderTo: 'scHdrForm2', border: false, layout: 'column', bodyStyle: 'background:transparent;padding:0 2px 4px 2px;',
            items: [col(0.5, [fScope]), col(0.5, [fNotes])] });

        var bar = document.getElementById('scAddBar');
        bar.getElementsByClassName('scBtnAddRow')[0].onclick = function () { itemDs.add(newTaskRec()); updateSummary(); };
        bar.getElementsByClassName('scBtnSplit')[0].onclick = splitEvenly;
        bar.getElementsByClassName('scBtnNewSub')[0].onclick = openSubcontractor;
    }

    function newTaskRec(cfg) {
        var Rec = itemDs.recordType || Ext.data.Record.create(taskField);
        return new Rec(Ext.apply({ task_id: null, task_name: '', task_value: 0, jadwal: '', notes: '' }, cfg || {}));
    }
    function splitEvenly() {
        var n = itemDs.getCount(), val = parseFloat(fValue.getValue()) || 0;
        if (!n || val <= 0) { showErr('Isi nilai kontrak dan tambahkan baris task terlebih dahulu.'); return; }
        var each = Math.floor(val / n * 100) / 100, used = 0;
        itemDs.each(function (r, i) { var v = (i === n - 1) ? round2(val - used) : each; used += v; r.set('task_value', v); });
        updateSummary();
    }
    function onProjectChange() {
        itemDs.removeAll(); fBud.clearValue(); fBud.setValue(0);
        var pid = fProject.getValue();
        if (!pid) { taskDs.removeAll(); budDs.removeAll(); updateSummary(); return; }
        taskDs.load({ params: { project_id: pid } });
        budDs.load({ params: { project_id: pid, contract_id: editingId || 0 } });
        updateSummary();
    }
    function showEditor(title) {
        var addWdw = document.getElementById('addWdw'); if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureForm();
    }
    function loadMasters(cb) { projectDs.load({ callback: function () { subDs.load({ callback: cb }); } }); }

    // ---- dialog subkontraktor baru
    function openSubcontractor() {
        var fN = new Ext.form.TextField({ fieldLabel: 'Nama', anchor: '95%', allowBlank: false });
        var fNp = new Ext.form.TextField({ fieldLabel: 'NPWP', anchor: '95%' });
        var fPk = new Ext.form.ComboBox({ fieldLabel: 'PKP', store: [['0', 'Tidak'], ['1', 'Ya']], value: '0', triggerAction: 'all', editable: false, width: 90 });
        var fPh = new Ext.form.TextField({ fieldLabel: 'Telepon', anchor: '95%' });
        var fBn = new Ext.form.TextField({ fieldLabel: 'Bank', anchor: '95%' });
        var fBa = new Ext.form.TextField({ fieldLabel: 'No. Rekening', anchor: '95%' });
        var fPp = new Ext.form.NumberField({ fieldLabel: 'PPh Final default (%)', decimalPrecision: 2, value: 2.65, width: 90 });
        var win = new Ext.Window({
            title: 'Subkontraktor Baru', width: 460, height: 380, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({ border: false, labelWidth: 130, bodyStyle: 'padding:12px;', items: [fN, fNp, fPk, fPh, fBn, fBa, fPp] }),
            bbar: ['->', { xtype: 'tbtext', text: uiBtn('scSubSave', 'Simpan', TEAL) + uiBtn('scSubCancel', 'Batal', BURG) }]
        });
        win.show();
        document.getElementById('scSubCancel').onclick = function () { win.close(); };
        document.getElementById('scSubSave').onclick = function () {
            var p = { task: 'save_subcontractor', name: fN.getValue(), npwp_no: fNp.getValue(), is_pkp: fPk.getValue(), phone: fPh.getValue(),
                      bank_name: fBn.getValue(), bank_account_no: fBa.getValue(), pph_rate: fPp.getValue() || 0 };
            if (!p.name) { showErr('Nama subkontraktor wajib diisi.'); return; }
            ajax(p, function (res) {
                if (!res.success) { showErr(res.message || 'Gagal menyimpan subkontraktor.'); return; }
                win.close();
                subDs.load({ callback: function () { if (fSub) { fSub.setValue(res.data.id); var r = subDs.getById(res.data.id); if (r) { fPph.setValue(r.get('pph_rate')); } } } });
            });
        };
    }

    // ------------------------------------------------------------
    // 3. Dialog prestasi
    // ------------------------------------------------------------
    function calcDeductions(P, gross, cumPct) {
        var ppn = round2(gross * P.ppn_rate / 100), pph = round2(gross * P.pph_rate / 100), ret = round2(gross * P.retention_pct / 100);
        var plan = round2(gross * P.dp_pct / 100), bal = P.dp_balance;
        var dp = (cumPct >= 99.999) ? bal : Math.min(bal, plan);
        dp = Math.max(0, round2(dp));
        var net = round2(gross + ppn - pph - ret - dp);
        if (net < 0) { dp = Math.max(0, round2(dp + net)); net = round2(gross + ppn - pph - ret - dp); }
        return { ppn: ppn, pph: pph, ret: ret, dp: dp, net: net };
    }

    function openProgress(contractId, progressId) {
        ajax({ task: 'progress_prepare', contract_id: contractId, progress_id: progressId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat data prestasi.'); return; }
            var P = res.params, existing = null;
            if (progressId > 0 && curDetail) { Ext.each(curDetail.progress, function (p) { if (p.id === progressId) existing = p; }); }
            var pds = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, [
                { name: 'id' }, { name: 'task_name' }, { name: 'task_value', type: 'float' }, { name: 'prev_pct', type: 'float' }, { name: 'cum_pct', type: 'float' }, { name: 'amount', type: 'float' }]) });
            pds.loadData(res);
            pds.each(function (r) { r.set('amount', round2(r.get('task_value') * (r.get('cum_pct') - r.get('prev_pct')) / 100)); });
            pds.commitChanges();

            var fDt = new Ext.form.DateField({ fieldLabel: 'Tanggal Prestasi', format: 'd/m/Y', width: 130, allowBlank: false,
                value: existing ? Date.parseDate(existing.progress_date, 'Y-m-d') : new Date() });
            var fEv = new Ext.form.TextField({ fieldLabel: 'Bukti (no. BA / foto / link)', anchor: '95%', value: existing ? (existing.evidence_ref || '') : '' });
            var fRm = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%', value: existing ? (existing.remark || '') : '' });

            function recalc() {
                var gross = 0, weighted = 0;
                pds.each(function (r) { gross += r.get('amount'); weighted += r.get('task_value') * r.get('cum_pct') / 100; });
                gross = round2(gross);
                var cumPct = P.contract_value > 0 ? weighted / P.contract_value * 100 : 0, c = calcDeductions(P, gross, cumPct);
                var el = document.getElementById('scPrInfo');
                if (el) el.innerHTML = 'Progres kumulatif kontrak <b>' + fmtPct(cumPct) + '</b> &nbsp;|&nbsp; Nilai periode (DPP) <b>' + fmtAmt(gross) + '</b> &nbsp;|&nbsp; + PPN ' + P.ppn_rate + '% <b>' + fmtAmt(c.ppn) +
                    '</b> &nbsp;|&nbsp; - PPh ' + P.pph_rate + '% <b>' + fmtAmt(c.pph) + '</b> &nbsp;|&nbsp; - Retensi ' + P.retention_pct + '% <b>' + fmtAmt(c.ret) +
                    '</b> &nbsp;|&nbsp; - Pot. Uang Muka <b>' + fmtAmt(c.dp) + '</b> (sisa UM ' + fmtAmt(P.dp_balance) + ') &nbsp;|&nbsp; <b style="color:#7fe0a0;">Nilai Bayar ' + fmtAmt(c.net) + '</b>';
            }
            var grid = new Ext.grid.EditorGridPanel({
                region: 'center', store: pds, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Task', dataIndex: 'task_name', width: 260 },
                    { header: 'Nilai Task (DPP)', dataIndex: 'task_value', width: 120, align: 'right', renderer: fmtAmt },
                    { header: 'Progres Lalu', dataIndex: 'prev_pct', width: 90, align: 'right', renderer: fmtPct },
                    { header: 'Progres Kumulatif (%)', dataIndex: 'cum_pct', width: 130, align: 'right', renderer: fmtPct,
                      editor: new Ext.form.NumberField({ decimalPrecision: 3, allowNegative: false, maxValue: 100 }) },
                    { header: 'Nilai Periode', dataIndex: 'amount', width: 120, align: 'right', renderer: fmtAmt }
                ],
                listeners: { validateedit: function (e) {
                    if (e.field !== 'cum_pct') return;
                    var v = parseFloat(e.value) || 0;
                    if (v < e.record.get('prev_pct') - 0.0005 || v > 100.0005) { showErr('Progres kumulatif harus antara progres lalu (' + e.record.get('prev_pct') + '%) dan 100%.'); e.cancel = true; }
                }, afteredit: function (e) {
                    e.record.set('amount', round2(e.record.get('task_value') * (e.record.get('cum_pct') - e.record.get('prev_pct')) / 100));
                    recalc();
                } }
            });
            var win = new Ext.Window({
                title: (progressId ? 'Ubah Prestasi ' + (existing ? existing.ref_no : '') : 'Prestasi Baru') + ' - ' + (curDetail ? curDetail.header.spk_no : ''),
                width: 980, height: 560, modal: true, layout: 'border',
                items: [
                    new Ext.form.FormPanel({ region: 'north', height: 110, border: false, labelWidth: 170, bodyStyle: 'padding:8px 10px;', items: [fDt, fEv, fRm] }),
                    grid,
                    { region: 'south', height: 100, border: false, bodyStyle: 'padding:8px 10px;',
                      html: '<div id="scPrInfo" style="margin-bottom:10px;"></div><div style="text-align:right;">' + uiBtn('scPrSave', 'Simpan (Draft)', TEAL) + uiBtn('scPrCancel', 'Batal', BURG) + '</div>' }
                ]
            });
            win.show();
            recalc();
            document.getElementById('scPrCancel').onclick = function () { win.close(); };
            document.getElementById('scPrSave').onclick = function () {
                if (!fDt.getValue()) { showErr('Tanggal prestasi wajib diisi.'); return; }
                var arr = [];
                pds.each(function (r) { arr.push({ contract_task_id: r.get('id'), cum_pct: r.get('cum_pct') }); });
                var p = { task: 'save_progress', id: progressId, contract_id: contractId, progress_date: ymd(fDt.getValue()), evidence_ref: fEv.getValue(), remark: fRm.getValue(),
                          details: Ext.util.JSON.encode(arr) };
                ajax(p, function (r2) {
                    if (!r2.success) { showErr(r2.message || 'Gagal menyimpan prestasi.'); return; }
                    win.close();
                    Ext.Msg.alert('Sukses', r2.message);
                    refreshAll(contractId);
                });
            };
        });
    }

    // ------------------------------------------------------------
    // 4. Dialog pembayaran
    // ------------------------------------------------------------
    function openPayment(type) {
        var d = curDetail, h = d.header, t = d.totals;
        var titles = { DP: 'Bayar Uang Muka', PROGRESS: 'Bayar Prestasi', RETENSI: 'Lepas Retensi' };
        var suggest = type === 'DP' ? round2(t.dp_planned - t.dp_paid) : (type === 'RETENSI' ? t.retention_balance : 0);
        var progOpts = [];
        Ext.each(d.progress, function (p) { if (p.status === 'Disetujui' && round2(p.net_payable - p.paid_amount) > VEPS) progOpts.push([p.id, p.ref_no + ' - sisa ' + fmtAmt(round2(p.net_payable - p.paid_amount)), round2(p.net_payable - p.paid_amount)]); });
        if (type === 'PROGRESS' && !progOpts.length) { showErr('Tidak ada prestasi disetujui yang masih punya sisa hutang.'); return; }

        var fDt = new Ext.form.DateField({ fieldLabel: 'Tanggal Bayar', format: 'd/m/Y', width: 130, allowBlank: false, value: new Date() });
        var fAc = new Ext.form.ComboBox({ fieldLabel: 'Akun Kas / Bank', store: accDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%' });
        var fPr = new Ext.form.ComboBox({ fieldLabel: 'Prestasi', store: new Ext.data.ArrayStore({ fields: ['id', 'label', 'sisa'], data: progOpts }), displayField: 'label', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, editable: false, anchor: '95%', hidden: type !== 'PROGRESS', hideLabel: type !== 'PROGRESS',
            listeners: { select: function (c, rec) { fAm.setValue(rec.get('sisa')); } } });
        var fAm = new Ext.form.NumberField({ fieldLabel: 'Jumlah Bayar', decimalPrecision: 2, allowNegative: false, width: 160, value: type === 'PROGRESS' ? progOpts[0][2] : suggest });
        if (type === 'PROGRESS') fPr.setValue(progOpts[0][0]);
        var fRf = new Ext.form.TextField({ fieldLabel: 'No. Referensi / Bukti', anchor: '95%' });
        var fNt = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });
        var win = new Ext.Window({
            title: titles[type] + ' - ' + h.spk_no, width: 520, height: 330, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({ border: false, labelWidth: 150, bodyStyle: 'padding:12px;',
                items: [{ xtype: 'displayfield', hideLabel: true, value: type === 'DP' ? 'Uang muka dipotong proporsional dari prestasi berikutnya.' :
                          (type === 'RETENSI' ? 'Retensi ditahan <b>' + fmtAmt(t.retention_balance) + '</b>.' : 'Sisa hutang prestasi <b>' + fmtAmt(t.payable_balance) + '</b>.') },
                        fDt, fAc, fPr, fAm, fRf, fNt] }),
            bbar: ['->', { xtype: 'tbtext', text: uiBtn('scPySave', 'Simpan &amp; Posting', TEAL) + uiBtn('scPyCancel', 'Batal', BURG) }]
        });
        accDs.load({ callback: function () { win.show();
            document.getElementById('scPyCancel').onclick = function () { win.close(); };
            document.getElementById('scPySave').onclick = function () {
                if (!fDt.getValue() || !fAc.getValue()) { showErr('Tanggal dan akun kas / bank wajib diisi.'); return; }
                if (!(parseFloat(fAm.getValue()) > 0)) { showErr('Jumlah bayar harus lebih besar dari 0.'); return; }
                var p = { task: 'save_payment', contract_id: h.id, pay_type: type, progress_id: type === 'PROGRESS' ? fPr.getValue() : 0, payment_date: ymd(fDt.getValue()),
                          account_id: fAc.getValue(), amount: fAm.getValue(), reference_no: fRf.getValue(), notes: fNt.getValue() };
                ajax(p, function (res) {
                    if (!res.success) { showErr(res.message || 'Gagal menyimpan pembayaran.'); return; }
                    win.close(); Ext.Msg.alert('Sukses', res.message); refreshAll(h.id);
                });
            };
        } });
    }

    // ------------------------------------------------------------
    // 5. Handler tombol template
    // ------------------------------------------------------------
    function fillEmpty() {
        fProject.clearValue(); fSub.clearValue(); fTitle.setValue(''); fBud.setValue(0); fValue.setValue(0); fPpn.setValue(0); fPph.setValue(0);
        fRet.setValue(0); fDp.setValue(0); fStart.setValue(''); fEnd.setValue(''); fScope.setValue(''); fNotes.setValue('');
        taskDs.removeAll(); budDs.removeAll(); itemDs.removeAll(); updateSummary();
    }

    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat SPK.')) return;
        editingId = 0;
        showEditor('SPK Subkontraktor Baru');
        loadMasters(function () { fillEmpty(); itemDs.add(newTaskRec()); });
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih SPK terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Draft') { showErr('Hanya SPK berstatus Draft yang dapat diubah.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengedit SPK.')) return;
        ajax({ task: 'load_contract', id: dataRecord.id }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat SPK.'); return; }
            var h = res.data.header;
            editingId = parseInt(h.id, 10);
            showEditor('Edit SPK ' + h.spk_no);
            loadMasters(function () {
                taskDs.load({ params: { project_id: h.project_id }, callback: function () {
                    budDs.load({ params: { project_id: h.project_id, contract_id: editingId }, callback: function () {
                        fProject.setValue(parseInt(h.project_id, 10)); fSub.setValue(parseInt(h.subcontractor_id, 10)); fTitle.setValue(h.title);
                        fBud.setValue(h.budget_item_id ? parseInt(h.budget_item_id, 10) : 0);
                        fValue.setValue(parseFloat(h.contract_value)); fPpn.setValue(parseFloat(h.ppn_rate)); fPph.setValue(parseFloat(h.pph_rate));
                        fRet.setValue(parseFloat(h.retention_pct)); fDp.setValue(parseFloat(h.dp_pct));
                        fStart.setValue(h.start_date ? Date.parseDate(h.start_date, 'Y-m-d') : ''); fEnd.setValue(h.end_date ? Date.parseDate(h.end_date, 'Y-m-d') : '');
                        fScope.setValue(h.scope || ''); fNotes.setValue(h.notes || '');
                        itemDs.removeAll();
                        Ext.each(res.data.tasks, function (x) {
                            itemDs.add(newTaskRec({ task_id: x.task_id, task_name: x.task_name, task_value: parseFloat(x.task_value), notes: x.notes || '',
                                                    jadwal: x.start_date ? x.start_date + ' s/d ' + (x.end_date || '-') : '' }));
                        });
                        updateSummary();
                    } });
                } });
            });
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fProject.getValue()) { showErr('Proyek wajib dipilih.'); return; }
        if (!fSub.getValue()) { showErr('Subkontraktor wajib dipilih.'); return; }
        if (!fTitle.getValue()) { showErr('Pekerjaan wajib diisi.'); return; }
        var val = parseFloat(fValue.getValue()) || 0;
        if (val <= 0) { showErr('Nilai kontrak harus lebih besar dari 0.'); return; }
        var arr = [], sum = 0;
        itemDs.each(function (r) {
            var v = parseFloat(r.get('task_value')) || 0;
            if (v <= 0 && !r.get('task_id') && !r.get('task_name')) return;      // baris kosong diabaikan
            sum += v;
            arr.push({ task_id: r.get('task_id') || 0, task_name: r.get('task_name') || '', task_value: v, notes: r.get('notes') || '' });
        });
        if (arr.length && Math.abs(sum - val) > VEPS) { showErr('Total pembagian task (' + fmtAmt(sum) + ') harus sama dengan nilai kontrak (' + fmtAmt(val) + ').'); return; }
        ajax({
            task: 'save_contract', id: editingId, project_id: fProject.getValue(), subcontractor_id: fSub.getValue(), title: fTitle.getValue(),
            budget_item_id: fBud.getValue() || 0, contract_value: val, ppn_rate: fPpn.getValue() || 0, pph_rate: fPph.getValue() || 0,
            retention_pct: fRet.getValue() || 0, dp_pct: fDp.getValue() || 0, start_date: ymd(fStart.getValue()), end_date: ymd(fEnd.getValue()),
            scope: fScope.getValue(), notes: fNotes.getValue(), tasks: Ext.util.JSON.encode(arr)     // detail bertingkat -> JSON via POST
        }, function (res) {
            if (res.success) {
                document.getElementById('editorWdw').style.display = 'none';
                Ext.Msg.alert('Sukses', res.message || 'SPK tersimpan.');
                mainDs.load();
                if (res.data && res.data.id) { dataRecord = { id: res.data.id, status: 'Draft' }; updateMainButtons(); loadDetailPanel(res.data.id); }
            } else {
                showErr(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Draft') { showErr('Pilih SPK berstatus Draft terlebih dahulu. SPK lain dapat dibatalkan dari panel detail.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk menghapus SPK.')) return;
        Ext.Msg.confirm('Hapus SPK', 'Hapus SPK <b>' + enc(dataRecord.spk_no) + '</b>? Tindakan ini tidak dapat dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'delete_contract', id: dataRecord.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) { dataRecord = null; dtlDiv.style.display = 'none'; mainDs.load(); updateMainButtons(); }
            });
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) { document.getElementById('popCancel').style.display = 'none'; }
    };
    document.getElementById('editorWdw').style.display = 'none';
});