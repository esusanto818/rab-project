/*
Document      : subcon.js
Description   : Subkontraktor. Grid utama = daftar SPK; klik baris -> panel detail di bawah grid (ringkasan keuangan, task, prestasi,
pembayaran, jurnal, jejak audit, tombol aksi). Add/Edit membuka editor SPK (header + pembagian nilai ke task proyek).
Prestasi, pembayaran, dan subkon baru memakai dialog (tombol HTML gaya standar).
Alur SPK: Draft -> Diajukan -> Disetujui -> Selesai (Dibatalkan selama belum ada prestasi / pembayaran).
Prestasi : progres kumulatif % per task -> nilai periode - PPh - retensi - potongan uang muka + PPN = nilai bayar.
Edit  & Delete SPK hanya untuk status Draft. Dokumen turunan tidak dihapus, hanya Dibatalkan (alasan wajib).
Backend       : ../php-crud/subconCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Sub<em>kontraktor</em>";
    var CRD = main_url + '/php-crud/subconCRD.php';
    var ACCESS_ADD = 2;
    var ACCESS_EDIT = 3;
    var VEPS = 0.01;
    var PPN_DEFAULT = 11;
    var dataRecord = null, editingId = 0, hdrForm = null, hdrForm2 = null;
    var curDetail = null;
    var fProject, fSub, fTitle, fBud, fValue, fPpn, fPph, fRet, fDp, fStart, fEnd, fScope, fNotes, fSpk;
    
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
    
    function uiBtn(cls, label, bg, attrs) {
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
            if (!res.success) { dtlDiv.innerHTML = '<div style="padding:10px">' + enc(res.message || 'Gagal memuat detail SPK.') + '</div>'; dtlDiv.style.display = 'block'; return; }
            curDetail = res.data;
            renderDetail(res.data);
        });
    }
    
    function th(label, align) { return '<th align="' + (align || 'left') + '">' + label + '</th>'; }
    function td(v, align, style) { return '<td align="' + (align || 'left') + '"' + (style ? ' style="' + style + '"' : '') + '>' + v + '</td>'; }
    
    function renderDetail(d) {
        var h = d.header, t = d.totals, tasks = d.tasks || [], prog = d.progress || [], pays = d.payments || [], jr = d.journals || [];
        var html = '<h3 class="detail-title">SPK: ' + enc(h.spk_no) + ' <span >(' + enc(h.status) + ')</span></h3><hr class="detail-hr" />' +
            '<div style="padding:4px 2px 10px 2px">Proyek: <b>' + enc(h.project_name) + '</b> &nbsp;|&nbsp; Subkon: <b>' + enc(h.subcontractor_name) + '</b>' +
            (h.npwp_no ? ' (NPWP ' + enc(h.npwp_no) + ')' : '') + ' &nbsp;|&nbsp; Pekerjaan: <b>' + enc(h.title) + '</b>' +
            (h.budget_item_name ? ' &nbsp;|&nbsp; RAB: <b>' + enc(h.budget_item_name) + '</b>' : '') +
            ' &nbsp;|&nbsp; Jadwal: ' + enc(h.start_date || '-') + ' s/d ' + enc(h.end_date || '-') +
            (h.linked_spk_no ? ' &nbsp;|&nbsp; SPK Subkon: <b>' + enc(h.linked_spk_no) + '</b> (' + enc(h.linked_spk_status || '-') + ')' : ' &nbsp;|&nbsp; <span >SPK Subkon: belum ditautkan</span> <span style="font-size:11px">(Edit kontrak &rarr; pilih SPK di field "Tautan SPK Subkon")</span>') + '</div>' +
            '<div style="padding:0 2px 10px 2px">PPN ' + h.ppn_rate + '% &nbsp;|&nbsp; PPh final ' + h.pph_rate + '% &nbsp;|&nbsp; Retensi ' + h.retention_pct + '% &nbsp;|&nbsp; Uang muka ' + h.dp_pct + '%' +
            (h.scope ? ' &nbsp;|&nbsp; Lingkup: ' + enc(h.scope) : '') + '</div>';
        
        html += '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('Nilai Kontrak (DPP)', 'right') + th('Progres Tertagih', 'right') + th('Tertagih (DPP)', 'right') + th('Hutang Prestasi', 'right') + th('Sudah Dibayar', 'right') + th('Sisa Hutang', 'right') +
            th('Uang Muka (bayar / potong / sisa)', 'right') + th('Retensi (ditahan / sisa)', 'right') + '</tr></thead><tbody><tr class="data-row">' +
            td(fmtAmt(h.contract_value), 'right') + td(fmtPct(t.billed_pct), 'right') + td(fmtAmt(t.gross), 'right') + td(fmtAmt(t.net), 'right') + td(fmtAmt(t.progress_paid), 'right') +
            td('<b>' + fmtAmt(t.payable_balance) + '</b>', 'right') +
            td(fmtAmt(t.dp_paid) + ' / ' + fmtAmt(t.dp_recovered) + ' / <b>' + fmtAmt(t.dp_balance) + '</b>', 'right') +
            td(fmtAmt(t.retention) + ' / <b>' + fmtAmt(t.retention_balance) + '</b>', 'right') + '</tr></tbody></table>';
        
        html += '<h4 style="margin:14px 0 4px 0">Pembagian Pekerjaan per Task</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('Task') + th('Jadwal task') + th('Nilai Task (DPP)', 'right') + th('Progres Disetujui', 'right') + th('Nilai Tertagih', 'right') + '</tr></thead><tbody>';
        Ext.each(tasks, function (x) {
            html += '<tr class="data-row">' + td(enc(x.task_name) + (x.work_item_id ? '<br/><span style="font-size:11px">' + fmtQ(x.accepted_qty) + ' / ' + fmtQ(x.contract_qty) + ' ' + enc(x.unit) + ' diterima</span>' : '')) + td(x.start_date ? enc(x.start_date) + ' s/d ' + enc(x.end_date || '-') : '-') +
                    td(fmtAmt(x.task_value), 'right') + td(fmtPct(x.cum_pct), 'right') + td(fmtAmt(x.billed_amount), 'right') + '</tr>';
        });
        html += '</tbody></table>';
        
        html += '<h4 style="margin:14px 0 4px 0">Prestasi</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('No.') + th('Tanggal') + th('Progres Kum.', 'right') + th('Nilai (DPP)', 'right') + th('PPN', 'right') + th('PPh', 'right') + th('Retensi', 'right') + th('Pot. Uang Muka', 'right') +
            th('Nilai Bayar', 'right') + th('Dibayar', 'right') + th('Status') + th('Jejak') + th('') + '</tr></thead><tbody>';
        if (!prog.length) html += '<tr><td colspan="13" style="padding:12px;text-align:center">Belum ada prestasi.</td></tr>';
        Ext.each(prog, function (p) {
            var acts = '';
            if (p.status === 'Draft') {
                acts += uiBtn('scAct', 'Edit', GREY, 'data-act="edit_progress" data-id="' + p.id + '"');
                if (p.qty_lines > 0) acts += uiBtn('scAct', 'Inspeksi', '#2e6da4', 'data-act="inspect" data-id="' + p.id + '"');
                // klaim kuantitas baru bisa disetujui setelah inspeksinya disetujui
                if (!(p.qty_lines > 0) || p.insp_status === 'APPROVED') acts += uiBtn('scAct', 'Setujui', TEAL, 'data-act="approve_progress" data-id="' + p.id + '"');
            }
            if (p.status !== 'Dibatalkan') acts += uiBtn('scAct', 'Batal', BURG, 'data-act="cancel_progress" data-id="' + p.id + '"');
            html += '<tr class="data-row">' + td(enc(p.ref_no)) + td(enc(p.progress_date)) + td(fmtPct(p.cum_pct), 'right') + td(fmtAmt(p.gross_amount), 'right') +
                td(fmtAmt(p.ppn_amount), 'right') + td(fmtAmt(p.pph_amount), 'right') + td(fmtAmt(p.retention_amount), 'right') + td(fmtAmt(p.dp_recovery), 'right') +
                td('<b>' + fmtAmt(p.net_payable) + '</b>', 'right') + td(fmtAmt(p.paid_amount), 'right') +
                td('<span style="font-weight:bold">' + enc(p.status) + '</span>' +
                   (p.qty_lines > 0 && p.status === 'Draft' ? '<br/>' + (p.insp_status === 'APPROVED' ? '<span style="font-size:11px;;padding:1px 5px">&#10003; Inspeksi disetujui &#8212; klik &#8220;Setujui&#8221; di baris ini</span>' : (p.insp_status === 'DRAFT' ? '<span style="font-size:11px;;padding:1px 5px">Inspeksi masih Draft &#8212; setujui inspeksi dulu di tabel bawah</span>' : '<span style="font-size:11px;;padding:1px 5px">Belum diinspeksi &#8212; klik &#8220;Inspeksi&#8221; untuk membuat inspeksi</span>')) : '')) +
                td('<span style="font-size:11px">' + enc(p.created_by_name || '-') + (p.approved_by_name ? ' &rarr; ' + enc(p.approved_by_name) : '') +
                   (p.cancel_reason ? '<br/>batal: ' + enc(p.cancel_reason) : '') + '</span>') + td(acts, 'right') + '</tr>';
        });
        html += '</tbody></table>';
        
        var insp = d.inspections || [];
        if (insp.length) {
            html += '<h4 style="margin:14px 0 4px 0">Inspeksi Kuantitas</h4>' +
                '<p style="margin:2px 0 6px 0;font-size:11px">Hanya kuantitas yang lolos inspeksi (qty_passed) yang dihitung sebagai dasar pembayaran. Setujui inspeksi terlebih dahulu sebelum menyetujui prestasi.</p>' +
                '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                th('No. Inspeksi') + th('Prestasi') + th('Tanggal') + th('Hasil per baris') + th('Status') + th('Jejak') + th('') + '</tr></thead><tbody>';
            Ext.each(insp, function (i) {
                var lines = '', pr = null, acts = '';
                Ext.each(i.items || [], function (it) {
                    lines += enc(it.description) + ': diperiksa ' + fmtQ(it.qty_inspected) + ' dari klaim ' + fmtQ(it.qty_claimed) + ' ' + enc(it.unit) +
                        ' &rarr; <b >lolos ' + fmtQ(it.qty_passed) + '</b>' +
                        (it.qty_rejected > 0 ? ', <span >ditolak ' + fmtQ(it.qty_rejected) + '</span>' : '') +
                        (it.qty_rework > 0 ? ', <span >perbaikan ' + fmtQ(it.qty_rework) + '</span>' : '') + '<br/>';
                });
                Ext.each(prog, function (p) { if (parseInt(p.id, 10) === i.progress_id) pr = p; });
                if (i.status === 'DRAFT') acts += uiBtn('scAct', 'Edit', GREY, 'data-act="edit_inspection" data-id="' + i.progress_id + '"') + uiBtn('scAct', 'Setujui', TEAL, 'data-act="approve_inspection" data-id="' + i.id + '"');
                if ((i.status === 'DRAFT' || i.status === 'APPROVED') && pr && pr.status === 'Draft') acts += uiBtn('scAct', 'Batal', BURG, 'data-act="cancel_inspection" data-id="' + i.id + '"');
                var col = i.status === 'APPROVED' ? '#7fe0a0' : (i.status === 'DRAFT' ? '#e6a817' : '#c46a6a');
                html += '<tr class="data-row">' + td(enc(i.inspection_no)) + td(enc(i.progress_ref)) + td(enc(i.inspection_date)) + td(lines || '-') +
                    td('<span style="font-weight:bold">' + enc(i.status) + '</span>' + (i.general_result ? '<br/><span style="font-size:11px">' + enc(i.general_result) + '</span>' : '')) +
                    td('<span style="font-size:11px">' + enc(i.created_by_name || '-') + (i.approved_by_name ? ' &rarr; ' + enc(i.approved_by_name) : '') +
                       (i.cancel_reason ? '<br/>batal: ' + enc(i.cancel_reason) : '') + '</span>') + td(acts, 'right') + '</tr>';
            });
            html += '</tbody></table>';
        }
        
        html += '<h4 style="margin:14px 0 4px 0">Pembayaran</h4><table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            th('No.') + th('Tanggal') + th('Jenis') + th('Prestasi') + th('Akun Kas/Bank') + th('Referensi') + th('Jumlah', 'right') + th('Status') + th('Jejak') + th('') + '</tr></thead><tbody>';
        if (!pays.length) html += '<tr><td colspan="10" style="padding:12px;text-align:center">Belum ada pembayaran.</td></tr>';
        Ext.each(pays, function (y) {
            var typ = y.pay_type === 'DP' ? 'Uang Muka' : (y.pay_type === 'RETENSI' ? 'Lepas Retensi' : 'Prestasi');
            html += '<tr class="data-row">' + td(enc(y.payment_no)) + td(enc(y.payment_date)) + td(typ) + td(enc(y.progress_ref || '-')) +
                td(enc(y.account_code + ' ' + y.account_name)) + td(enc(y.reference_no || '')) + td(fmtAmt(y.amount), 'right') +
                td('<span style="font-weight:bold">' + enc(y.status) + '</span>') +
                td('<span style="font-size:11px">' + enc(y.created_by_name || '-') + (y.cancel_reason ? '<br/>batal: ' + enc(y.cancel_reason) : '') + '</span>') +
                td(y.status === 'Posted' ? uiBtn('scAct', 'Batal', BURG, 'data-act="cancel_payment" data-id="' + y.id + '"') : '', 'right') + '</tr>';
        });
        html += '</tbody></table>';
        
        var trail = ['Dibuat: <b>' + enc(h.created_by_name || '-') + '</b> (' + enc(h.created_at) + ')'];
        if (h.approved_at) trail.push('Disetujui: <b>' + enc(h.approved_by_name || '-') + '</b> (' + enc(h.approved_at) + ')');
        if (h.closed_at) trail.push('Ditutup: <b>' + enc(h.closed_by_name || '-') + '</b> (' + enc(h.closed_at) + ')');
        if (h.cancelled_at) trail.push('Dibatalkan: <b>' + enc(h.cancelled_by_name || '-') + '</b> (' + enc(h.cancelled_at) + ') - ' + enc(h.cancel_reason || ''));
        html += '<div style="margin-top:12px">' + trail.join(' &nbsp;|&nbsp; ') + '</div>';
        if (jr.length) html += '<div style="margin-top:6px">Jurnal terkait: ' + Ext.pluck(jr, 'ref_no').join(', ') + '</div>';
        
        var btns = '';
        Ext.each(d.allowed_actions || [], function (to) {
            btns += uiBtn('scAct', enc(ACTION_LABEL[to] || to), to === 'Dibatalkan' ? BURG : (to === 'Draft' ? GREY : TEAL), 'data-act="status" data-to="' + enc(to) + '"');
        });
        var hasDraft = false; Ext.each(prog, function (p) { if (p.status === 'Draft') hasDraft = true; });
        if (h.status === 'Disetujui' && !hasDraft && t.billed_pct < 100 - 0.001) btns += uiBtn('scAct', 'Prestasi Baru', TEAL, 'data-act="new_progress"');
        if (h.status === 'Disetujui' && t.dp_planned > 0 && t.dp_paid < t.dp_planned - VEPS) btns += uiBtn('scAct', 'Bayar Uang Muka', GREY, 'data-act="pay_dp"');
        if ((h.status === 'Disetujui' || h.status === 'Selesai') && t.payable_balance > VEPS) btns += uiBtn('scAct', 'Bayar Prestasi', GREY, 'data-act="pay_progress"');
        if (h.status === 'Selesai' && t.retention_balance > VEPS) btns += uiBtn('scAct', 'Lepas Retensi', GREY, 'data-act="pay_retention"');
        if (btns) html += '<div style="margin-top:15px;text-align:right">' + btns + '</div>';
        
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
        } else if (act === 'inspect' || act === 'edit_inspection') {
            if (needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses untuk membuat inspeksi.')) openInspection(id);
        } else if (act === 'approve_inspection') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk menyetujui inspeksi.')) return;
            Ext.Msg.confirm('Setujui Inspeksi', 'Setujui inspeksi ini? Nilai prestasi akan dihitung ulang dari qty yang LOLOS.', function (b) {
                if (b !== 'yes') return;
                ajax({ task: 'approve_inspection', id: id }, function (res) { Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || ''); if (res.success) refreshAll(h.id); });
            });
        } else if (act === 'cancel_inspection') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membatalkan inspeksi.')) return;
            reasonPrompt('Batalkan Inspeksi', function (txt) {
                ajax({ task: 'cancel_inspection', id: id, reason: txt }, function (res) { Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || ''); if (res.success) refreshAll(h.id); });
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
    mainDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_contracts' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, [
            { name: 'id' }, { name: 'spk_no' }, { name: 'spk_id' }, { name: 'linked_spk_no' }, { name: 'linked_spk_status' }, { name: 'project_id' }, { name: 'project_name' }, { name: 'subcontractor_name' }, { name: 'title' }, { name: 'status' },
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
            { header: 'SPK Ref', dataIndex: 'linked_spk_no', width: 110, renderer: function (v) { return v ? enc(v) : '-'; } },
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
              renderer: function (v) { return '<span style="font-weight:bold">' + enc(v || '-') + '</span>'; } }
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
    var taskField = [{ name: 'task_id' }, { name: 'task_name' }, { name: 'task_value', type: 'float' }, { name: 'jadwal' }, { name: 'notes' }, { name: 'work_item_id' }, { name: 'wi_label' }];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, taskField) });
    var editorDeleter = new Extensive.grid.ItemDeleter({ header: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>' });
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
                renderer: function (v, m, rec) { return rec.get('task_name') ? enc(rec.get('task_name')) : '<i >(seluruh pekerjaan)</i>'; }
            },
            {
                header: 'Item Lingkup SPK (klaim qty + inspeksi)', dataIndex: 'work_item_id', width: 290,
                editor: new myComboField({
                    store: workDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: false,
                    listeners: { select: function (combo, rec) {
                        var row = itemGrid.getSelectionModel().getSelected();
                        if (!row) return;
                        var dup = false;
                        itemDs.each(function (r) { if (r !== row && r.get('work_item_id') && parseInt(r.get('work_item_id'), 10) === parseInt(rec.get('id'), 10)) dup = true; });
                        if (dup) { showErr('Item lingkup ini sudah dipakai pada baris lain.'); itemGrid.stopEditing(true); return; }
                        var cfg = mappedCfg(rec);
                        row.set('task_id', cfg.task_id); row.set('task_name', cfg.task_name); row.set('task_value', cfg.task_value);
                        row.set('jadwal', cfg.jadwal); row.set('wi_label', cfg.wi_label);
                        updateSummary();
                    } }
                }),
                renderer: function (v, m, rec) { return rec.get('work_item_id') ? enc(rec.get('wi_label')) : '<i >(tanpa lingkup: progres dalam %)</i>'; }
            },
            { header: 'Jadwal Task', dataIndex: 'jadwal', width: 170 },
            { header: 'Nilai Task (DPP)', dataIndex: 'task_value', width: 130, align: 'right', editor: new myNumberField({ decimalPrecision: 2, allowNegative: false }), renderer: fmtAmt },
            { header: 'Catatan', dataIndex: 'notes', width: 160, editor: new myTextField() },
            editorDeleter
        ],
        listeners: {
            afteredit: function () { updateSummary(); },
            // baris yang sudah dipetakan ke item lingkup: task & nilai ditentukan item lingkup, tidak diedit manual
            beforeedit: function (e) { if (e.record.get('work_item_id') && (e.field === 'task_value' || e.field === 'task_id')) return false; }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');
    
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (!g || document.getElementById('scHdrForm')) return;
        var mk = function (id, css) { var e = document.createElement('div'); e.id = id; e.style.cssText = css || ''; g.parentNode.insertBefore(e, g); return e; };
        mk('scHdrForm', 'margin-bottom:2px;');
        mk('scHdrForm2', 'margin-bottom:6px;');
        var bar = mk('scAddBar', 'margin:4px 0 6px 0;');
        bar.innerHTML = uiBtn('scBtnAddRow', '+ Tambah Task', '#2e6da4') + uiBtn('scBtnSplit', 'Bagi Rata ke Semua Baris', GREY) + uiBtn('scBtnNewSub', '+ Subkontraktor Baru', GREY) + uiBtn('scBtnFromScope', 'Isi dari Lingkup SPK', TEAL);
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
            'Terbagi ke task <b>' + fmtAmt(sum) + '</b> &nbsp;|&nbsp; Selisih <b >' + fmtAmt(diff) + (ok ? ' (OK)' : ' (harus 0)') + '</b>';
    }
    
    // SPK bertipe Subkon yang proyek & subkontraktornya sama dan belum punya kontrak (untuk tautan kontrak -> SPK)
    var spkDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_spk_options' },
        reader: rdr([{ name: 'id' }, { name: 'spk_no' }, { name: 'spk_date' }, { name: 'status' }, { name: 'description' },
                     { name: 'label', convert: function (v, r) { return r.spk_no + ' (' + r.status + (r.description ? ') - ' + r.description : ')'); } }]),
        listeners: { exception: storeError }
    });
    // muat ulang pilihan SPK setiap proyek / subkontraktor berubah; keepValue = pertahankan pilihan yang masih valid
    function reloadSpkOptions(keepValue, cb) {
        var pid = fProject && fProject.getValue(), sid = fSub && fSub.getValue();
        var keep = keepValue ? fSpk.getValue() : '';
        if (!keepValue) { unmapRows(); workDs.removeAll(); }
        fSpk.clearValue();
        if (!pid || !sid) { spkDs.removeAll(); if (cb) cb(); return; }
        spkDs.load({ params: { project_id: pid, subcontractor_id: sid, contract_id: editingId || 0 }, callback: function () {
            if (keep && spkDs.findExact('id', keep) >= 0) { fSpk.setValue(keep); }
            if (cb) cb();
        } });
    }

    // item lingkup pekerjaan milik SPK yang ditautkan (untuk memetakan task kontrak -> klaim kuantitas)
    var workDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_work_items' },
        reader: rdr([{ name: 'id' }, { name: 'work_code' }, { name: 'description' }, { name: 'unit' }, { name: 'contract_qty', type: 'float' },
                     { name: 'unit_price', type: 'float' }, { name: 'line_amount', type: 'float' }, { name: 'project_task_id' },
                     { name: 'label', convert: function (v, r) { return (r.work_code ? r.work_code + ' - ' : '') + r.description + ' (' + r.contract_qty + ' ' + r.unit + ')'; } }]),
        listeners: { exception: storeError }
    });
    function loadWorkItems(cb) {
        var sid = fSpk && fSpk.getValue();
        if (!sid) { workDs.removeAll(); if (cb) cb(); return; }
        workDs.load({ params: { spk_id: sid }, callback: function () { if (cb) cb(); } });
    }
    function unmapRows() {
        itemDs.each(function (r) { if (r.get('work_item_id')) { r.set('work_item_id', null); r.set('wi_label', ''); } });
    }
    function mappedCfg(w) {
        var pid = w.get('project_task_id'), tr = null;
        if (pid) { var i = taskDs.findExact('id', pid); if (i >= 0) tr = taskDs.getAt(i); }
        return { task_id: pid || null, task_name: w.get('description'), task_value: parseFloat(w.get('line_amount')) || 0,
                 jadwal: tr ? (tr.get('start_date') || '-') + ' s/d ' + (tr.get('end_date') || '-') : '', work_item_id: w.get('id'), wi_label: w.get('label') };
    }
    // buat baris task dari seluruh item lingkup SPK; Nilai Kontrak diset = total lingkup
    function fillFromScope() {
        if (!fSpk.getValue()) { showErr('Pilih "Tautan SPK Subkon" dulu (dropdown di atas). Jika SPK belum ada, buat dulu di menu SPK dengan tipe Subkon, lalu isi lingkup pekerjaan.'); return; }
        if (!workDs.getCount()) { showErr('SPK yang dipilih belum punya lingkup pekerjaan aktif. Isi dulu di layar SPK (Atur Lingkup Pekerjaan).'); return; }
        var go = function () {
            itemDs.removeAll();
            var total = 0;
            workDs.each(function (w) { itemDs.add(newTaskRec(mappedCfg(w))); total += parseFloat(w.get('line_amount')) || 0; });
            fValue.setValue(round2(total));
            updateSummary();
        };
        var has = false;
        itemDs.each(function (r) { if (r.get('task_name') || (parseFloat(r.get('task_value')) || 0) > 0) has = true; });
        if (has) { Ext.Msg.confirm('Isi dari Lingkup SPK', 'Baris task yang ada akan diganti dengan item lingkup SPK, dan Nilai Kontrak diset sama dengan total lingkup. Lanjutkan?', function (b) { if (b === 'yes') go(); }); }
        else { go(); }
    }

    function ensureForm() {
        if (hdrForm) { hdrForm.doLayout(); hdrForm2.doLayout(); return; }
        fProject = new myComboField({ fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } } });
        fSub = new myComboField({ fieldLabel: 'Subkontraktor', store: subDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function (c, rec) {
                reloadSpkOptions(false);
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
        fSpk = new myComboField({ fieldLabel: 'Tautan SPK Subkon', store: spkDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: true, anchor: '95%', emptyText: 'Pilih SPK Subkon (diperlukan untuk klaim qty & inspeksi lapangan)...',
            listeners: { select: function () { unmapRows(); loadWorkItems(); } } });
        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 120, items: items }; };
        hdrForm = new Ext.form.FormPanel({ renderTo: 'scHdrForm', border: false, layout: 'column', bodyStyle: 'padding:4px 2px 0 2px',
            items: [col(0.36, [fProject, fSub, fTitle, fBud]), col(0.30, [fValue, fPpn, fPph]), col(0.34, [fRet, fDp, fStart, fEnd])] });
        hdrForm2 = new Ext.form.FormPanel({ renderTo: 'scHdrForm2', border: false, layout: 'column', bodyStyle: 'padding:0 2px 4px 2px',
            items: [col(0.5, [fScope, fSpk]), col(0.5, [fNotes])] });
        // inject SPK hint below hdrForm2
        var spkHint = document.createElement('div');
        spkHint.id = 'scSpkHint';
        spkHint.style.cssText = 'padding:0 2px 4px 128px;color:#668894;font-size:11px;';
        spkHint.innerHTML = 'Jika SPK dipilih: gunakan "Isi dari Lingkup SPK" agar nilai kontrak &amp; task otomatis terisi sesuai volume pekerjaan SPK. Tanpa SPK: progres dihitung dalam %.';
        var hf2El = document.getElementById('scHdrForm2');
        if (hf2El && hf2El.parentNode) hf2El.parentNode.insertBefore(spkHint, hf2El.nextSibling);
        var bar = document.getElementById('scAddBar');
        bar.getElementsByClassName('scBtnAddRow')[0].onclick = function () { itemDs.add(newTaskRec()); updateSummary(); };
        bar.getElementsByClassName('scBtnSplit')[0].onclick = splitEvenly;
        bar.getElementsByClassName('scBtnNewSub')[0].onclick = openSubcontractor;
        bar.getElementsByClassName('scBtnFromScope')[0].onclick = fillFromScope;
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
        reloadSpkOptions(false);
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
    
    // ============================================================
    // [FIX] DIALOG SUBKONTRAKTOR BARU - PERBAIKAN TOMBOL SIMPAN
    // ============================================================
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
            items: new Ext.form.FormPanel({ border: false, labelWidth: 130, bodyStyle: 'padding:12px', items: [fN, fNp, fPk, fPh, fBn, fBa, fPp] }),
            bbar: ['->', { xtype: 'tbtext', text: uiBtn('scSubSave', 'Simpan', TEAL) + uiBtn('scSubCancel', 'Batal', BURG) }]
        });
        win.show();
        
        // [FIX] Gunakan querySelector (class) bukan getElementById (id)
        // Karena uiBtn() membuat tombol dengan class, bukan id
        setTimeout(function() {
            var btnSave = document.querySelector('.scSubSave');
            var btnCancel = document.querySelector('.scSubCancel');
            
            if (btnCancel) {
                btnCancel.onclick = function () { win.close(); };
            } else {
                console.error('[Subcon] Tombol Batal tidak ditemukan di DOM!');
            }
            
            if (btnSave) {
                btnSave.onclick = function () {
                    var name = fN.getValue();
                    var npwp = fNp.getValue();
                    var pkp = fPk.getValue();
                    var phone = fPh.getValue();
                    var bankName = fBn.getValue();
                    var bankAcc = fBa.getValue();
                    var pphRate = fPp.getValue() || 0;
                    
                    // Validasi
                    if (!name || name.trim() === '') {
                        showErr('Nama subkontraktor wajib diisi.');
                        return;
                    }
                    
                    // Kirim ke backend
                    var params = { 
                        task: 'save_subcontractor', 
                        name: name.trim(), 
                        npwp_no: npwp ? npwp.trim() : '', 
                        is_pkp: pkp, 
                        phone: phone ? phone.trim() : '',
                        bank_name: bankName ? bankName.trim() : '', 
                        bank_account_no: bankAcc ? bankAcc.trim() : '', 
                        pph_rate: pphRate 
                    };
                    
                    console.log('[Subcon] Menyimpan subkontraktor:', params);
                    
                    ajax(params, function (res) {
                        console.log('[Subcon] Response:', res);
                        if (!res.success) { 
                            showErr(res.message || 'Gagal menyimpan subkontraktor.'); 
                            return; 
                        }
                        win.close();
                        // Reload store subkontraktor dan auto-select yang baru
                        subDs.load({ 
                            callback: function () { 
                                if (fSub && res.data && res.data.id) { 
                                    fSub.setValue(res.data.id); 
                                    var r = subDs.getById(res.data.id); 
                                    if (r) { 
                                        fPph.setValue(r.get('pph_rate')); 
                                    } 
                                } 
                            } 
                        });
                    });
                };
            } else {
                console.error('[Subcon] Tombol Simpan tidak ditemukan di DOM!');
            }
        }, 100);
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
    
    function fmtQ(v) { return Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 }); }

    function openProgress(contractId, progressId) {
        ajax({ task: 'progress_prepare', contract_id: contractId, progress_id: progressId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat data prestasi.'); return; }
            var P = res.params, existing = null;
            if (progressId > 0 && curDetail) { Ext.each(curDetail.progress, function (p) { if (p.id === progressId) existing = p; }); }
            var pds = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, [
                { name: 'id' }, { name: 'task_name' }, { name: 'task_value', type: 'float' }, { name: 'prev_pct', type: 'float' }, { name: 'cum_pct', type: 'float' }, { name: 'amount', type: 'float' },
                { name: 'qty_mode' }, { name: 'unit' }, { name: 'contract_qty', type: 'float' }, { name: 'unit_price', type: 'float' },
                { name: 'accepted_qty', type: 'float' }, { name: 'remaining_qty', type: 'float' }, { name: 'qty_claimed', type: 'float' }]) });
            pds.loadData(res);
            // task terpetakan ke item lingkup: klaim dalam QTY (progres & nilai dihitung dari qty); lainnya tetap dalam persen
            function rowCalc(r) {
                if (r.get('qty_mode')) {
                    var q = parseFloat(r.get('qty_claimed')) || 0, cq = r.get('contract_qty') || 0, pr = r.get('accepted_qty') || 0;
                    if (q > 0 && cq > 0) { r.set('cum_pct', Math.min(100, Math.round((pr + q) / cq * 100000) / 1000)); r.set('amount', round2(q * r.get('unit_price'))); }
                    else { r.set('cum_pct', r.get('prev_pct')); r.set('amount', 0); }
                } else {
                    r.set('amount', round2(r.get('task_value') * (r.get('cum_pct') - r.get('prev_pct')) / 100));
                }
            }
            pds.each(rowCalc);
            pds.commitChanges();
            var fDt = new Ext.form.DateField({ fieldLabel: 'Tanggal Prestasi', format: 'd/m/Y', width: 130, allowBlank: false,
                value: existing ? Date.parseDate(existing.progress_date, 'Y-m-d') : new Date() });
            var fEv = new Ext.form.TextField({ fieldLabel: 'Bukti (no. BA / foto / link)', anchor: '95%', value: existing ? (existing.evidence_ref || '') : '' });
            var fRm = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%', value: existing ? (existing.remark || '') : '' });
            function recalc() {
                var gross = 0, weighted = 0, hasQty = false;
                pds.each(function (r) { gross += r.get('amount'); weighted += r.get('task_value') * r.get('cum_pct') / 100; if (r.get('qty_mode')) hasQty = true; });
                gross = round2(gross);
                var cumPct = P.contract_value > 0 ? weighted / P.contract_value * 100 : 0, c = calcDeductions(P, gross, cumPct);
                var el = document.getElementById('scPrInfo');
                if (el) el.innerHTML = 'Progres kumulatif kontrak <b>' + fmtPct(cumPct) + '</b> &nbsp;|&nbsp; Nilai periode (DPP) <b>' + fmtAmt(gross) + '</b> &nbsp;|&nbsp; + PPN ' + P.ppn_rate + '% <b>' + fmtAmt(c.ppn) +
                    '</b> &nbsp;|&nbsp; - PPh ' + P.pph_rate + '% <b>' + fmtAmt(c.pph) + '</b> &nbsp;|&nbsp; - Retensi ' + P.retention_pct + '% <b>' + fmtAmt(c.ret) +
                    '</b> &nbsp;|&nbsp; - Pot. Uang Muka <b>' + fmtAmt(c.dp) + '</b> (sisa UM ' + fmtAmt(P.dp_balance) + ') &nbsp;|&nbsp; <b >Nilai Bayar ' + fmtAmt(c.net) + '</b>' +
                    (hasQty ? '<br/><span >Klaim kuantitas: nilai di atas masih sementara (dari qty klaim). Nilai akhir mengikuti qty yang LOLOS inspeksi.</span>' : '');
            }
            var grid = new Ext.grid.EditorGridPanel({
                region: 'center', store: pds, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Task', dataIndex: 'task_name', width: 280,
                      renderer: function (v, m, r) {
                          return r.get('qty_mode') ? enc(v) + '<br/><span style="font-size:11px">Qty kontrak ' + fmtQ(r.get('contract_qty')) + ' ' + enc(r.get('unit')) +
                              ' | diterima ' + fmtQ(r.get('accepted_qty')) + ' | sisa ' + fmtQ(r.get('remaining_qty')) + '</span>' : enc(v);
                      } },
                    { header: 'Nilai Task (DPP)', dataIndex: 'task_value', width: 120, align: 'right', renderer: fmtAmt },
                    { header: 'Progres Lalu', dataIndex: 'prev_pct', width: 90, align: 'right', renderer: fmtPct },
                    { header: 'Qty Klaim', dataIndex: 'qty_claimed', width: 110, align: 'right',
                      renderer: function (v, m, r) { return r.get('qty_mode') ? '<b>' + fmtQ(v) + '</b> ' + enc(r.get('unit')) : '<span >-</span>'; },
                      editor: new Ext.form.NumberField({ decimalPrecision: 4, allowNegative: false }) },
                    { header: 'Progres Kumulatif (%)', dataIndex: 'cum_pct', width: 130, align: 'right', renderer: fmtPct,
                      editor: new Ext.form.NumberField({ decimalPrecision: 3, allowNegative: false, maxValue: 100 }) },
                    { header: 'Nilai Periode', dataIndex: 'amount', width: 120, align: 'right', renderer: fmtAmt }
                ],
                listeners: { beforeedit: function (e) {
                    if (e.field === 'qty_claimed' && !e.record.get('qty_mode')) return false;   // task tanpa lingkup: isi persen
                    if (e.field === 'cum_pct' && e.record.get('qty_mode')) return false;        // task terpetakan: progres dihitung dari qty
                }, validateedit: function (e) {
                    if (e.field === 'qty_claimed') {
                        var q = parseFloat(e.value) || 0;
                        if (q < 0 || q > (e.record.get('remaining_qty') || 0) + 0.00005) {
                            showErr('Qty klaim harus antara 0 dan sisa qty kontrak (' + fmtQ(e.record.get('remaining_qty')) + ' ' + e.record.get('unit') + ').'); e.cancel = true;
                        }
                        return;
                    }
                    if (e.field !== 'cum_pct') return;
                    var v = parseFloat(e.value) || 0;
                    if (v < e.record.get('prev_pct') - 0.0005 || v > 100.0005) { showErr('Progres kumulatif harus antara progres lalu (' + e.record.get('prev_pct') + '%) dan 100%.'); e.cancel = true; }
                }, afteredit: function (e) {
                    rowCalc(e.record);
                    recalc();
                } }
            });
            var win = new Ext.Window({
                title: (progressId ? 'Ubah Prestasi ' + (existing ? existing.ref_no : '') : 'Prestasi Baru') + ' - ' + (curDetail ? curDetail.header.spk_no : ''),
                width: 1060, height: 580, modal: true, layout: 'border',
                items: [
                    new Ext.form.FormPanel({ region: 'north', height: 110, border: false, labelWidth: 170, bodyStyle: 'padding:8px 10px', items: [fDt, fEv, fRm] }),
                    grid,
                    { region: 'south', height: 125, border: false, bodyStyle: 'padding:8px 10px',
                      html: '<div id="scPrInfo" style="margin-bottom:10px"></div><div style="text-align:right">' + uiBtn('scPrSave', 'Simpan (Draft)', TEAL) + uiBtn('scPrCancel', 'Batal', BURG) + '</div>' }
                ]
            });
            win.show();
            recalc();
            document.getElementById('scPrCancel').onclick = function () { win.close(); };
            document.getElementById('scPrSave').onclick = function () {
                if (!fDt.getValue()) { showErr('Tanggal prestasi wajib diisi.'); return; }
                var arr = [];
                pds.each(function (r) { arr.push({ contract_task_id: r.get('id'), cum_pct: r.get('cum_pct'), qty_claimed: r.get('qty_mode') ? r.get('qty_claimed') : 0 }); });
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
    // 3b. Dialog inspeksi kuantitas: lolos / ditolak / perbaikan per baris klaim
    // ------------------------------------------------------------
    function openInspection(progressId) {
        ajax({ task: 'inspection_prepare', progress_id: progressId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat data inspeksi.'); return; }
            var ins = res.inspection, prg = res.progress;
            if (prg.status !== 'Draft') { showErr('Inspeksi hanya dapat dibuat untuk prestasi berstatus Draft.'); return; }
            if (ins && ins.status !== 'DRAFT') { showErr('Inspeksi ini sudah ' + ins.status + ' dan tidak dapat diubah. Batalkan dulu bila perlu diperiksa ulang.'); return; }
            var ds = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, [
                { name: 'progress_qty_item_id' }, { name: 'task_name' }, { name: 'description' }, { name: 'work_code' }, { name: 'unit' },
                { name: 'qty_claimed', type: 'float' }, { name: 'qty_inspected', type: 'float' }, { name: 'qty_passed', type: 'float' },
                { name: 'qty_rejected', type: 'float' }, { name: 'qty_rework', type: 'float' }, { name: 'notes' }]) });
            ds.loadData(res);
            var fDt = new Ext.form.DateField({ fieldLabel: 'Tanggal Inspeksi', format: 'd/m/Y', width: 130, allowBlank: false,
                value: ins && ins.inspection_date ? Date.parseDate(ins.inspection_date, 'Y-m-d') : new Date() });
            var fEv = new Ext.form.TextField({ fieldLabel: 'Bukti (no. BA / foto / link)', anchor: '95%', value: ins ? (ins.evidence_ref || '') : '' });
            var fGen = new Ext.form.TextField({ fieldLabel: 'Kesimpulan umum', anchor: '95%', value: ins ? (ins.general_result || '') : '' });
            function info() {
                var cl = 0, ps = 0, rj = 0, rw = 0;
                ds.each(function (r) { cl += r.get('qty_claimed'); ps += r.get('qty_passed'); rj += r.get('qty_rejected'); rw += r.get('qty_rework'); });
                var el = document.getElementById('scInInfo');
                if (el) el.innerHTML = 'Total klaim <b>' + fmtQ(cl) + '</b> &nbsp;|&nbsp; <b >Lolos ' + fmtQ(ps) + '</b> &nbsp;|&nbsp; <span >Ditolak ' + fmtQ(rj) +
                    '</span> &nbsp;|&nbsp; <span >Perbaikan ' + fmtQ(rw) + '</span> &nbsp;&mdash;&nbsp; hanya qty LOLOS yang menjadi nilai prestasi, jurnal, dan hutang.';
            }
            var num = function () { return new Ext.form.NumberField({ decimalPrecision: 4, allowNegative: false }); };
            var grid = new Ext.grid.EditorGridPanel({
                region: 'center', store: ds, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Pekerjaan', dataIndex: 'description', width: 280,
                      renderer: function (v, m, r) { return enc(v) + '<br/><span style="font-size:11px">' + enc(r.get('task_name')) + '</span>'; } },
                    { header: 'Sat.', dataIndex: 'unit', width: 55 },
                    { header: 'Qty Klaim', dataIndex: 'qty_claimed', width: 95, align: 'right', renderer: fmtQ },
                    { header: 'Qty Diperiksa', dataIndex: 'qty_inspected', width: 105, align: 'right', renderer: fmtQ, editor: num() },
                    { header: 'Qty Lolos', dataIndex: 'qty_passed', width: 95, align: 'right', renderer: function (v) { return '<b >' + fmtQ(v) + '</b>'; }, editor: num() },
                    { header: 'Qty Ditolak', dataIndex: 'qty_rejected', width: 95, align: 'right', renderer: fmtQ, editor: num() },
                    { header: 'Qty Perbaikan', dataIndex: 'qty_rework', width: 105, align: 'right', renderer: fmtQ, editor: num() },
                    { header: 'Catatan', dataIndex: 'notes', width: 180, editor: new Ext.form.TextField() }
                ],
                listeners: { afteredit: function (e) {
                    var r = e.record;
                    // kemudahan: ubah diperiksa / ditolak / perbaikan -> qty lolos otomatis = sisanya (masih bisa diedit manual)
                    if (e.field === 'qty_inspected' || e.field === 'qty_rejected' || e.field === 'qty_rework') {
                        r.set('qty_passed', Math.max(0, Math.round((r.get('qty_inspected') - r.get('qty_rejected') - r.get('qty_rework')) * 10000) / 10000));
                    }
                    info();
                } }
            });
            var win = new Ext.Window({
                title: (ins ? 'Ubah Inspeksi ' + ins.inspection_no : 'Inspeksi Baru') + ' - Prestasi ' + prg.ref_no,
                width: 1080, height: 520, modal: true, layout: 'border',
                items: [
                    new Ext.form.FormPanel({ region: 'north', height: 110, border: false, labelWidth: 170, bodyStyle: 'padding:8px 10px', items: [fDt, fEv, fGen] }),
                    grid,
                    { region: 'south', height: 95, border: false, bodyStyle: 'padding:8px 10px',
                      html: '<div id="scInInfo" style="margin-bottom:10px"></div><div style="text-align:right">' + uiBtn('scInSave', 'Simpan Inspeksi (Draft)', TEAL) + uiBtn('scInCancel', 'Batal', BURG) + '</div>' }
                ]
            });
            win.show();
            info();
            document.getElementById('scInCancel').onclick = function () { win.close(); };
            document.getElementById('scInSave').onclick = function () {
                if (!fDt.getValue()) { showErr('Tanggal inspeksi wajib diisi.'); return; }
                var arr = [], bad = '', n = 0;
                ds.each(function (r) {
                    n++;
                    var cl = r.get('qty_claimed'), ip = r.get('qty_inspected'), ps = r.get('qty_passed'), rj = r.get('qty_rejected'), rw = r.get('qty_rework');
                    if (!bad && ip > cl + 0.00005) { bad = 'Baris ' + n + ': qty diperiksa (' + fmtQ(ip) + ') melebihi qty klaim (' + fmtQ(cl) + ').'; }
                    if (!bad && ps + rj + rw > ip + 0.00005) { bad = 'Baris ' + n + ': lolos + ditolak + perbaikan (' + fmtQ(ps + rj + rw) + ') melebihi qty diperiksa (' + fmtQ(ip) + ').'; }
                    arr.push({ progress_qty_item_id: r.get('progress_qty_item_id'), qty_inspected: ip, qty_passed: ps, qty_rejected: rj, qty_rework: rw, notes: r.get('notes') || '' });
                });
                if (bad) { showErr(bad); return; }
                ajax({ task: 'save_inspection', id: ins ? ins.id : 0, progress_id: progressId, inspection_date: ymd(fDt.getValue()),
                       evidence_ref: fEv.getValue(), general_result: fGen.getValue(), items: Ext.util.JSON.encode(arr) }, function (r2) {
                    if (!r2.success) { showErr(r2.message || 'Gagal menyimpan inspeksi.'); return; }
                    win.close();
                    Ext.Msg.alert('Sukses', r2.message);
                    refreshAll(curDetail.header.id);
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
            items: new Ext.form.FormPanel({ border: false, labelWidth: 150, bodyStyle: 'padding:12px',
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
        fSpk.clearValue(); spkDs.removeAll(); workDs.removeAll();
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
                        // pilihan SPK: muat dulu, baru pasang nilai tersimpan (SPK yang sudah tertaut ikut muncul karena contract_id dikirim)
                        spkDs.load({ params: { project_id: h.project_id, subcontractor_id: h.subcontractor_id, contract_id: editingId }, callback: function () {
                            if (h.spk_id) {
                                if (spkDs.findExact('id', h.spk_id) < 0) {   // SPK sudah Selesai/Dibatalkan tapi masih tertaut: tampilkan tetap
                                    spkDs.add(new spkDs.recordType({ id: h.spk_id, spk_no: h.linked_spk_no, status: h.linked_spk_status, description: '', label: h.linked_spk_no + ' (' + h.linked_spk_status + ')' }));
                                }
                                fSpk.setValue(parseInt(h.spk_id, 10));
                                loadWorkItems();
                            }
                        } });
                        itemDs.removeAll();
                        Ext.each(res.data.tasks, function (x) {
                            itemDs.add(newTaskRec({ task_id: x.task_id, task_name: x.task_name, task_value: parseFloat(x.task_value), notes: x.notes || '',
                                                    jadwal: x.start_date ? x.start_date + ' s/d ' + (x.end_date || '-') : '',
                                                    work_item_id: x.work_item_id || null,
                                                    wi_label: x.work_item_id ? ((x.work_code ? x.work_code + ' - ' : '') + x.task_name + ' (' + x.contract_qty + ' ' + x.unit + ')') : '' }));
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
            if (v <= 0 && !r.get('task_id') && !r.get('task_name')) return;
            sum += v;
            arr.push({ task_id: r.get('task_id') || 0, task_name: r.get('task_name') || '', task_value: v, notes: r.get('notes') || '', work_item_id: r.get('work_item_id') || 0 });
        });
        if (arr.length && Math.abs(sum - val) > VEPS) { showErr('Total pembagian task (' + fmtAmt(sum) + ') harus sama dengan nilai kontrak (' + fmtAmt(val) + ').'); return; }
        ajax({
            task: 'save_contract', id: editingId, project_id: fProject.getValue(), subcontractor_id: fSub.getValue(), title: fTitle.getValue(),
            budget_item_id: fBud.getValue() || 0, contract_value: val, ppn_rate: fPpn.getValue() || 0, pph_rate: fPph.getValue() || 0,
            retention_pct: fRet.getValue() || 0, dp_pct: fDp.getValue() || 0, start_date: ymd(fStart.getValue()), end_date: ymd(fEnd.getValue()),
            scope: fScope.getValue(), notes: fNotes.getValue(), tasks: Ext.util.JSON.encode(arr), spk_id: fSpk.getValue() || 0
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