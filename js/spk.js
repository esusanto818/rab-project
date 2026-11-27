/*
  Document      : spk.js
  Description   : SPK (Surat Perintah Kerja). Grid utama = daftar SPK; klik baris -> panel detail (jatah, terpakai, sisa) dan
                  tombol aksi status. Add/Edit membuka editor (header + grid material) untuk SPK berstatus Draft.
                  Aturan: SPK baru dapat DITERBITKAN setelah proyek menerima pembayaran termin; qty tiap material dibatasi kuota
                  = qty RAB x (% kontrak dibayar x 2), maks 100% (dihitung server). Warehouse Issuing proyek wajib memilih SPK.
                  Alur status: Draft -> Diterbitkan -> Selesai ; Dibatalkan dari Draft / Diterbitkan yang belum dipakai issue.
                  Pola mengikuti po.js / warehouse.issuing.js (template many-template-icn.php).
  Backend       : /proyek/php-crud/spkCRD.php  (kuota: php-func/function.spk.php)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "<em>SPK</em> - Surat Perintah Kerja";

    var CRD =  main_url + '/php-crud/spkCRD.php';
    var ACCESS_EDIT = 2;      // buat / ubah Draft
    var ACCESS_ACTION = 3;    // terbitkan / tutup / batalkan / hapus
    var EPS = 0.00005;

    var dataRecord = null;    // baris SPK terpilih
    var editingId = 0;        // 0 = SPK baru
    var hdrForm = null, matBar = null;
    var fDate, fProject, fForeman, fDesc, fRemark, fMat, quotaDiv;
    var fType, fSubcon, fStart, fEnd;   // [2026-11-11] SPK Internal / Subkon
    var selectedTasks = [], taskSelect = null, taskListDiv = null;

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtPct(v) { return Ext.util.Format.number(v || 0, '0,000.00') + ' %'; }
    function ymd(d) {         // Date.format('Y-m-d') pernah bermasalah di sebagian browser -> format manual
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diterbitkan': return '#2bc1e2 !important';
            case 'Selesai': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
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

    // ------------------------------------------------------------
    // 0. Panel detail di bawah grid utama
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'spkDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30) + 'px';
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling); }

    function loadDetailPanel(id) {
        ajax({ action: 'detail', id: id }, function (res) {
            if (!res.success) { dtlDiv.innerHTML = '<div style="padding:10px">' + enc(res.message || 'Gagal memuat detail SPK.') + '</div>'; dtlDiv.style.display = 'block'; return; }
            renderDetailPanel(res);
        });
    }

    function actionBtn(id, label, bg) {
        return '<button type="button" id="' + id + '">' + label + '</button>';
    }

    function renderDetailPanel(d) {
        var h = d.header, pay = d.pay, items = d.items || [], tasks = d.tasks || [];
        var btns = actionBtn('spkPrintBtn', 'Cetak SPK', '#2e6da4');
        if (h.status === 'Draft') { btns += actionBtn('spkIssueBtn', 'Terbitkan SPK', '#03615e'); }
        if (h.status === 'Diterbitkan') { btns += actionBtn('spkCloseBtn', 'Tutup SPK (Selesai)', '#555555'); }
        if ((h.status === 'Draft' || h.status === 'Diterbitkan') && !(parseInt(d.issue_count, 10) > 0)) { btns += actionBtn('spkCancelBtn', 'Batalkan SPK', '#8e2b22'); }

        var isSub = (h.spk_type === 'SUBCON');
        var typeLine = '';
        if (isSub) {
            var sb = d.subcontractor, ct = d.contract;
            typeLine = '<div style="padding:6px 10px;margin:6px 0;">' +
                       '<b >SPK SUBKON</b> &nbsp;|&nbsp; Subkontraktor: <b>' + enc(sb ? sb.name : '-') + '</b>' +
                       (h.work_start_date || h.work_end_date ? ' &nbsp;|&nbsp; Jadwal kerja: <b>' + enc(h.work_start_date || '-') + ' s/d ' + enc(h.work_end_date || '-') + '</b>' : '') +
                       ' &nbsp;|&nbsp; Kontrak subkon: ' + (ct ? '<b>' + enc(ct.contract_no) + '</b> (' + enc(ct.status) + ', nilai ' + fmtAmt(ct.contract_value) + ')'
                                                              : '<span >belum terhubung</span>') + '</div>';
        }
        var wiHtml = '';
        if (isSub) {
            var wis = d.work_items || [], wiTotal = 0;
            wiHtml = '<div style="padding:6px 10px;margin:6px 0;">' +
                     '<div style="float:right">' + (h.status === 'Draft' ? actionBtn('spkWorkBtn', 'Atur Lingkup Pekerjaan', '#066b78') : '') + '</div>' +
                     '<b>Lingkup Pekerjaan (dasar klaim, inspeksi &amp; pembayaran subkon)</b><div style="clear:both"></div>';
            if (!wis.length) {
                wiHtml += '<div style="padding:10px 2px">Belum ada lingkup pekerjaan' + (h.status === 'Draft' ? '. Klik "Atur Lingkup Pekerjaan" untuk mengisi uraian, qty, dan harga satuan.' : '.') + '</div>';
            } else {
                wiHtml += '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                          '<th align="left">Kode</th><th align="left">Uraian</th><th align="left">Task</th><th align="right">Qty</th><th align="left">Sat.</th>' +
                          '<th align="right">Harga Satuan</th><th align="right">Jumlah</th><th align="left">Material</th></tr></thead><tbody>';
                Ext.each(wis, function (w) {
                    wiTotal += parseFloat(w.line_amount) || 0;
                    wiHtml += '<tr class="data-row"><td align="left">' + enc(w.work_code || '-') + '</td><td align="left">' + enc(w.description) + '</td>' +
                              '<td align="left">' + enc(w.task_name || '-') + '</td><td align="right">' + fmtQty(w.contract_qty) + '</td><td align="left">' + enc(w.unit) + '</td>' +
                              '<td align="right">' + fmtAmt(w.unit_price) + '</td><td align="right"><b>' + fmtAmt(w.line_amount) + '</b></td>' +
                              '<td align="left">' + enc(({ COMPANY: 'Perusahaan', SUBCON: 'Subkon', MIXED: 'Campuran' })[w.material_supply] || w.material_supply) + '</td></tr>';
                });
                wiHtml += '<tr class="data-row"><td colspan="6" align="right"><b>Total lingkup</b></td><td align="right"><b>' + fmtAmt(wiTotal) + '</b></td><td></td></tr></tbody></table>';
                if (d.contract && Math.abs(parseFloat(d.contract.contract_value) - wiTotal) > 0.005) {
                    wiHtml += '<div style="padding:6px 2px">Total lingkup berbeda dari nilai kontrak subkon (' + fmtAmt(d.contract.contract_value) + ').</div>';
                }
            }
            wiHtml += '</div>';
        }
        // Material per task: jawab "material ini untuk task yang mana" + sumbernya (perusahaan / dibawa subkon)
        var planHtml = '', plan = d.material_plan || [];
        if (plan.length) {
            var groups = {}, order = [];
            Ext.each(d.tasks || [], function (t) { groups['t' + t.id] = { name: t.task_name, rows: [] }; order.push('t' + t.id); });
            Ext.each(plan, function (m) {
                var k = m.task_id ? 't' + m.task_id : 't0';
                if (!groups[k]) { groups[k] = { name: m.task_id ? (m.task_name || 'Task') : 'Umum (tidak terkait task tertentu)', rows: [] }; order.push(k); }
                groups[k].rows.push(m);
            });
            planHtml = '<div style="padding:6px 10px;margin:6px 0;"><b>Material per Task</b>';
            Ext.each(order, function (k) {
                var g = groups[k];
                if (!g.rows.length && k === 't0') { return; }
                planHtml += '<div style="margin:10px 0 3px 0;font-weight:bold">' + enc(g.name) + '</div>';
                if (!g.rows.length) { planHtml += '<div style="padding:2px 0 4px 0">Belum ada material untuk task ini.</div>'; return; }
                planHtml += '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row"><th align="left">Material</th><th align="right">Qty</th><th align="left">Sat.</th><th align="left">Sumber</th><th align="left">Catatan</th></tr></thead><tbody>';
                Ext.each(g.rows, function (m) {
                    planHtml += '<tr class="data-row"><td align="left">' + enc(m.material_code) + ' - ' + enc(m.material_name) + '</td><td align="right"><b>' + fmtQty(m.qty) + '</b></td><td align="left">' + enc(m.unit) + '</td>' +
                                '<td align="left">' + (m.supply_source === 'SUBCON' ? '<span style="font-weight:bold">Subkon (dibawa subkon)</span>' : 'Perusahaan (stok gudang)') + '</td><td align="left">' + enc(m.notes || '') + '</td></tr>';
                });
                planHtml += '</tbody></table>';
            });
            planHtml += '</div>';
        }
        var html = '<div style="float:right; margin:2px 4px 0 0">' + btns + '</div>' +
                   '<h3 class="detail-title">Detail SPK: ' + enc(h.spk_no) + ' <span >(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' + typeLine + wiHtml + planHtml +
                   '<div style="padding:4px 2px 6px 2px">Tanggal: <b>' + enc(h.spk_date) + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Klien: <b>' + enc(h.client_name || '-') + '</b> &nbsp;|&nbsp; Mandor: <b>' + enc(h.foreman || '-') + '</b>' +
                   (h.description ? ' &nbsp;|&nbsp; Uraian: ' + enc(h.description) : '') + '</div>' +
                   '<div style="padding:6px 10px;margin:6px 0;"><b>Task Proyek dalam SPK</b><ul style="margin:6px 0 2px 18px">' +
                   (tasks.length ? tasks.map(function(t){ return '<li>' + enc(t.task_name) + ' <span >(' + enc(t.status || 'Belum Mulai') + ')</span></li>'; }).join('') : '<li>Belum ada task terhubung.</li>') +
                   '</ul></div>' +
                   ((isSub && !items.length) ? '' : '<div style="padding:4px 10px; margin-bottom:8px;">Pembayaran proyek: <b>' + fmtAmt(pay.paid_amount) +
                   '</b> dari kontrak <b>' + fmtAmt(pay.contract_value) + '</b> (<b>' + fmtPct(pay.paid_pct) + '</b>) &rarr; batas material saat ini <b>' + fmtPct(pay.allowed_pct) +
                   '</b> dari qty RAB (dibayar x 2).</div>') +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty SPK</th><th align="right">Terpakai (issue)</th><th align="right">Sisa Jatah</th><th align="left">Catatan</th></tr></thead><tbody>';
        if (!items.length) { html += '<tr><td colspan="5" style="padding:15px; text-align:center">' + (isSub ? 'SPK subkon tanpa material gudang (pekerjaan murni).' : 'Belum ada item.') + '</td></tr>'; }
        Ext.each(items, function (i) {
            var color = i.remaining <= EPS ? '#2e9e4f' : (i.used > 0 ? '#e6a817' : '#668894');
            html += '<tr class="data-row"><td align="left">' + enc(i.material_name) + ' <span >(' + enc(i.material_code) + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty) + ' ' + enc(i.unit) + '</td>' +
                    '<td align="right" >' + fmtQty(i.used) + '</td>' +
                    '<td align="right"><b>' + fmtQty(i.remaining) + '</b></td><td align="left">' + enc(i.notes || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var b;
        if ((b = document.getElementById('spkPrintBtn'))) b.onclick = function () { window.open('../pdf/spk.php?id=' + h.id, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes'); };
        if ((b = document.getElementById('spkIssueBtn'))) b.onclick = function () { doAction('issue', h, 'Terbitkan SPK <b>' + enc(h.spk_no) + '</b>? Sistem memeriksa pembayaran termin dan kuota material.'); };
        if ((b = document.getElementById('spkCloseBtn'))) b.onclick = function () { doAction('close', h, 'Tutup SPK <b>' + enc(h.spk_no) + '</b>? Sisa jatah yang belum terpakai kembali menjadi kuota proyek.'); };
        if ((b = document.getElementById('spkCancelBtn'))) b.onclick = function () { doAction('cancel', h, 'Batalkan SPK <b>' + enc(h.spk_no) + '</b>?'); };
        if ((b = document.getElementById('spkWorkBtn'))) b.onclick = function () { openWorkItemsEditor(d); };
    }

    // ------------------------------------------------------------
    // Editor lingkup pekerjaan (SPK Subkon, status Draft)
    // ------------------------------------------------------------
    function openWorkItemsEditor(d) {
        var h = d.header, tasks = d.tasks || [];
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengubah lingkup pekerjaan.')) { return; }
        if (h.status !== 'Draft') { showErr('Lingkup pekerjaan hanya dapat diubah saat SPK berstatus Draft.'); return; }
        var TEAL = '#066b78', BURG = '#800020';
        var INP = 'width:100%;box-sizing:border-box;padding:7px;font-size:14px;background:#0f2230;color:#fff;border:1px solid #2e6da4;border-radius:3px;';
        var rows = [];
        Ext.each(d.work_items || [], function (w) {
            rows.push({ id: w.id, work_code: w.work_code || '', description: w.description, unit: w.unit, contract_qty: w.contract_qty, unit_price: w.unit_price,
                        project_task_id: w.project_task_id || 0, material_supply: w.material_supply || 'SUBCON', notes: w.notes || '',
                        locked: !!(w.in_contract || w.claim_lines > 0) });
        });
        function blank() { return { id: 0, work_code: '', description: '', unit: '', contract_qty: 0, unit_price: 0, project_task_id: 0, material_supply: 'SUBCON', notes: '', locked: false }; }
        if (!rows.length) { rows.push(blank()); }

        function btn(id, label, bg) {
            return '<button type="button" id="' + id + '">' + label + '</button>';
        }
        function taskOpts(sel) {
            var o = '<option value="0">- (tanpa task) -</option>';
            Ext.each(tasks, function (t) { o += '<option value="' + t.id + '"' + (parseInt(sel, 10) === parseInt(t.id, 10) ? ' selected' : '') + '>' + enc(t.task_name) + '</option>'; });
            return o;
        }
        function supplyOpts(sel) {
            var o = '';
            Ext.each([['SUBCON', 'Subkon'], ['COMPANY', 'Perusahaan'], ['MIXED', 'Campuran']], function (p) { o += '<option value="' + p[0] + '"' + (sel === p[0] ? ' selected' : '') + '>' + p[1] + '</option>'; });
            return o;
        }
        function amountOf(r) { return Math.round((parseFloat(r.contract_qty) || 0) * (parseFloat(r.unit_price) || 0) * 100) / 100; }
        function totalOf() { var t = 0; Ext.each(rows, function (r) { t += amountOf(r); }); return t; }

        function draw() {
            var box = document.getElementById('wiBox');
            var h2 = '<div style="margin-bottom:10px;font-size:14px">Isi pekerjaan yang diukur (qty), harga satuan, dan task proyek terkait. Jumlah dihitung otomatis. ' +
                     'Item yang sudah dipakai kontrak / klaim subkon dikunci dan tidak bisa dihapus.</div>' +
                     '<table width="100%" cellSpacing="0" cellPadding="4" style="font-size:14px;border-collapse:collapse"><thead><tr >' +
                     '<th align="left" width="28">#</th><th align="left" width="90">Kode</th><th align="left">Uraian pekerjaan</th><th align="left" width="190">Task proyek</th>' +
                     '<th align="left" width="90">Qty</th><th align="left" width="70">Satuan</th><th align="left" width="120">Harga satuan</th>' +
                     '<th align="right" width="130">Jumlah</th><th align="left" width="105">Material</th><th width="60"></th></tr></thead><tbody>';
            Ext.each(rows, function (r, i) {
                var dis = r.locked ? ' disabled title="Sudah dipakai kontrak / klaim"' : '';
                h2 += '<tr style="border-bottom:1px solid #1d3a52">' +
                      '<td>' + (i + 1) + '</td>' +
                      '<td><input style="' + INP + '" data-i="' + i + '" data-f="work_code" value="' + enc(r.work_code) + '"/></td>' +
                      '<td><input style="' + INP + '" data-i="' + i + '" data-f="description" value="' + enc(r.description) + '"/></td>' +
                      '<td><select style="' + INP + '" data-i="' + i + '" data-f="project_task_id">' + taskOpts(r.project_task_id) + '</select></td>' +
                      '<td><input type="number" min="0" step="any" style="' + INP + '" data-i="' + i + '" data-f="contract_qty" value="' + (r.contract_qty || '') + '"' + dis + '/></td>' +
                      '<td><input style="' + INP + '" data-i="' + i + '" data-f="unit" value="' + enc(r.unit) + '"/></td>' +
                      '<td><input type="number" min="0" step="any" style="' + INP + '" data-i="' + i + '" data-f="unit_price" value="' + (r.unit_price || '') + '"' + dis + '/></td>' +
                      '<td align="right" id="wiAmt' + i + '"><b>' + fmtAmt(amountOf(r)) + '</b></td>' +
                      '<td><select style="' + INP + '" data-i="' + i + '" data-f="material_supply">' + supplyOpts(r.material_supply) + '</select></td>' +
                      '<td align="center"><button type="button" data-del="' + i + '"' + dis + '>Hapus</button></td></tr>';
            });
            h2 += '</tbody><tfoot><tr><td colspan="7" align="right" style="padding-top:10px;font-size:15px"><b>Total lingkup</b></td>' +
                  '<td align="right" style="padding-top:10px;font-size:15px" id="wiTotal"><b>' + fmtAmt(totalOf()) + '</b></td><td colspan="2"></td></tr></tfoot></table>' +
                  '<div style="margin-top:12px">' + btn('wiAdd', '+ Tambah Baris', '#2e6da4') + '</div>';
            box.innerHTML = h2;
        }

        var win = new Ext.Window({
            title: 'Lingkup Pekerjaan - ' + h.spk_no, modal: true, layout: 'fit', maximizable: true,
            width: Math.min(1240, Ext.getBody().getViewSize().width - 30), height: Math.min(640, Ext.getBody().getViewSize().height - 30),
            items: new Ext.Panel({ border: false, autoScroll: true, html: '<div id="wiBox" style="padding:12px"></div>' }),
            bbar: ['->', { xtype: 'tbtext', text: btn('wiSave', 'Simpan Lingkup', TEAL) + btn('wiCancel', 'Batal', BURG) }]
        });
        win.show();
        draw();
        var box = document.getElementById('wiBox');
        box.oninput = function (e) {
            var t = e.target, i = t.getAttribute('data-i'), f = t.getAttribute('data-f');
            if (i === null || !f) { return; }
            var r = rows[parseInt(i, 10)];
            if (f === 'contract_qty' || f === 'unit_price') { r[f] = parseFloat(t.value) || 0; }
            else if (f === 'project_task_id') { r[f] = parseInt(t.value, 10) || 0; }
            else { r[f] = t.value; }
            if (f === 'contract_qty' || f === 'unit_price') {
                var a = document.getElementById('wiAmt' + i); if (a) { a.innerHTML = '<b>' + fmtAmt(amountOf(r)) + '</b>'; }
                var tt = document.getElementById('wiTotal'); if (tt) { tt.innerHTML = '<b>' + fmtAmt(totalOf()) + '</b>'; }
            }
        };
        box.onclick = function (e) {
            var t = e.target;
            if (t.id === 'wiAdd') { rows.push(blank()); draw(); return; }
            var del = t.getAttribute && t.getAttribute('data-del');
            if (del !== null && del !== undefined && del !== '' && !t.disabled) { rows.splice(parseInt(del, 10), 1); if (!rows.length) { rows.push(blank()); } draw(); }
        };
        document.getElementById('wiCancel').onclick = function () { win.close(); };
        document.getElementById('wiSave').onclick = function () {
            var out = [], n = 0, bad = '';
            Ext.each(rows, function (r) {
                var blankRow = !r.id && !String(r.description).replace(/\s/g, '') && !(parseFloat(r.contract_qty) > 0) && !(parseFloat(r.unit_price) > 0);
                if (blankRow) { return; }
                n++;
                if (!bad) {
                    if (!String(r.description).replace(/\s/g, '')) { bad = 'Baris ' + n + ': uraian pekerjaan wajib diisi.'; }
                    else if (!String(r.unit).replace(/\s/g, '')) { bad = 'Baris ' + n + ': satuan wajib diisi.'; }
                    else if (!(parseFloat(r.contract_qty) > 0)) { bad = 'Baris ' + n + ': qty harus lebih besar dari 0.'; }
                }
                out.push({ id: r.id, work_code: r.work_code, description: r.description, unit: r.unit, contract_qty: r.contract_qty, unit_price: r.unit_price,
                           project_task_id: r.project_task_id, material_supply: r.material_supply, notes: r.notes });
            });
            if (bad) { showErr(bad); return; }
            ajax({ action: 'save_work_items', id: h.id, items: Ext.util.JSON.encode(out) }, function (res) {
                if (!res.success) { showErr(res.message || 'Gagal menyimpan lingkup pekerjaan.'); return; }
                win.close(); Ext.Msg.alert('Sukses', res.message || ''); loadDetailPanel(h.id);
            });
        };
    }

    function doAction(action, h, text) {
        if (!needAccess(ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk aksi ini.')) return;
        Ext.Msg.confirm('Konfirmasi', text, function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: action, id: h.id }, function (res) {
                if (!res.success) { showErr(res.message || 'Proses gagal.'); return; }
                Ext.Msg.alert('Sukses', res.message || '');
                mainDs.reload({ callback: function () {
                    var idx = mainDs.find('id', h.id);
                    if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; }
                    updateMainButtons();
                    loadDetailPanel(h.id);
                } });
            });
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'spk_no', 'spk_date', 'project_id', 'project_name', 'foreman', 'description', 'status', 'remark',
                 'spk_type', 'subcontractor_name', 'contract_no', 'contract_status',
                 { name: 'item_count', type: 'int' }, { name: 'used_pct', type: 'float' }],
        listeners: { exception: storeError }
    });
    mainDs.load();

    var projectDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var subconDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_subcontractors' }, root: 'data', fields: ['id', 'name', 'code'],
        listeners: { exception: storeError }
    });
    var materialDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit',
                 { name: 'rab_qty', type: 'float' }, { name: 'cap_qty', type: 'float' }, { name: 'stock_available', type: 'float' }, { name: 'quota_available', type: 'float' }, { name: 'available', type: 'float' }],
        listeners: { exception: storeError }
    });
    var itemField = ['material_id', 'material_code', 'material_name', 'unit',
                     { name: 'rab_qty', type: 'float' }, { name: 'cap_qty', type: 'float' }, { name: 'available', type: 'float' },
                     { name: 'qty', type: 'float' }, 'notes', 'task_id', 'task_name', 'supply_source'];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: Math.round(GridMainHeight * 0.6),
        columns: [
            { header: 'No. SPK', dataIndex: 'spk_no', width: 130 },
            { header: 'Tanggal', dataIndex: 'spk_date', width: 90, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 230, renderer: CellWrap },
            { header: 'Tipe', dataIndex: 'spk_type', width: 150,
              renderer: function (v, m, r) {
                  if (v === 'SUBCON') { return CellWrap('<b >Subkon</b>: ' + enc(r.get('subcontractor_name') || '-')); }
                  return 'Internal';
              } },
            { header: 'Mandor', dataIndex: 'foreman', width: 130, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Uraian', dataIndex: 'description', width: 220, renderer: function (v) { return CellWrap(v || ''); } },
            { header: 'Item', dataIndex: 'item_count', width: 50, align: 'center' },
            { header: 'Terpakai', dataIndex: 'used_pct', width: 80, align: 'right', renderer: function (v) { return fmtPct(v); } },
            { header: 'Status', dataIndex: 'status', width: 100, align: 'center',
              renderer: function (v) { return '<span style="font-weight:bold">' + enc(v) + '</span>'; } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
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
    // 3. Editor: header + grid material
    // ------------------------------------------------------------
    // pilihan "Untuk Task" pada baris material = task yang sudah dipilih pada SPK ini (diisi ulang tiap mulai edit sel)
    var taskChoiceDs = new Ext.data.ArrayStore({ fields: ['id', 'name'], data: [[0, '(umum / semua task)']] });
    function matKey(r, taskId, src) {
        return String(r.get('material_id')) + '|' + (parseInt(r.get('task_id'), 10) || 0) + '|' + (r.get('supply_source') || 'COMPANY');
    }
    // total qty COMPANY satu material pada baris lain (kuota berlaku untuk jumlah semua task)
    function otherCompanyQty(rec) {
        var t = 0;
        itemDs.each(function (r) {
            if (r !== rec && String(r.get('material_id')) === String(rec.get('material_id')) && (r.get('supply_source') || 'COMPANY') !== 'SUBCON') { t += parseFloat(r.get('qty')) || 0; }
        });
        return t;
    }
    var itemGrid = new stdGridEditor({
        store: itemDs, renderTo: 'editorFormGrid', viewConfig: { forceFit: true, scrollOffset: 0 }, height: 280,
        enableHdMenu: false, enableColumnMove: false,
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'material_code', width: 90 },
            { sortable: false, menuDisabled: true, header: 'Material', dataIndex: 'material_name', width: 200 },
            { sortable: false, menuDisabled: true, header: 'Untuk Task', dataIndex: 'task_id', width: 210,
              editor: new Ext.form.ComboBox({ store: taskChoiceDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', editable: false, forceSelection: true,
                  listeners: { select: function (c, rec) {
                      var row = itemGrid.getSelectionModel().getSelected();
                      if (row) { row.set('task_name', parseInt(rec.get('id'), 10) ? rec.get('name') : ''); }
                  } } }),
              renderer: function (v, m, r) { return r.get('task_name') ? enc(r.get('task_name')) : '<i >(umum / semua task)</i>'; } },
            { sortable: false, menuDisabled: true, header: 'Sumber', dataIndex: 'supply_source', width: 95,
              editor: new Ext.form.ComboBox({ store: [['COMPANY', 'Perusahaan'], ['SUBCON', 'Subkon']], mode: 'local', triggerAction: 'all', editable: false, forceSelection: true }),
              renderer: function (v) { return v === 'SUBCON' ? '<b >Subkon</b>' : 'Perusahaan'; } },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Qty RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Kuota Saat Ini', dataIndex: 'cap_qty', width: 100, align: 'right', renderer: function (v, m, r) { return r.get('supply_source') === 'SUBCON' ? '-' : fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Tersedia', dataIndex: 'available', width: 90, align: 'right', renderer: function (v, m, r) { return r.get('supply_source') === 'SUBCON' ? '-' : fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty SPK', dataIndex: 'qty', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }), renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { sortable: false, menuDisabled: true, header: 'Catatan', dataIndex: 'notes', width: 140, editor: new myTextField({}) },
            { id: 'spkDeleteCol', sortable: false, menuDisabled: true, header: '<span style="font-size:15px" title="Hapus baris"><i class="fa fa-trash" aria-hidden="true"></i></span>', dataIndex: 'material_id', width: 34, align: 'center', fixed: true,
              renderer: function () { return '<span style="cursor:pointer;font-size:15px" title="Hapus baris"><i class="fa fa-trash" aria-hidden="true"></i></span>'; } }
        ],
        listeners: {
            beforeedit: function (e) {
                if (e.field === 'task_id') {
                    var d = [[0, '(umum / semua task)']];
                    Ext.each(selectedTasks, function (t) { d.push([t.id, t.task_name]); });
                    taskChoiceDs.loadData(d);
                }
                // SPK internal selalu bersumber stok perusahaan; sumber Subkon hanya untuk SPK bertipe Subkon
                if (e.field === 'supply_source' && !(fType && fType.getValue() === 'SUBCON')) { return false; }
            },
            validateedit: function (e) {
                // kuota COMPANY berlaku untuk jumlah semua task; material dibawa subkon tidak kena kuota
                if (e.field === 'qty' && (e.record.get('supply_source') || 'COMPANY') !== 'SUBCON') {
                    var other = otherCompanyQty(e.record), avail = parseFloat(e.record.get('available')) || 0;
                    if (parseFloat(e.value) + other > avail + EPS) {
                        showErr('Qty melebihi kuota tersedia (' + fmtQty(avail) + ')' + (other > 0 ? ' - termasuk ' + fmtQty(other) + ' pada baris lain untuk material yang sama.' : '.'));
                        e.cancel = true;
                    }
                }
            },
            afteredit: function (e) {
                // beralih Subkon -> Perusahaan: qty dibatasi kuota yang masih tersedia
                if (e.field === 'supply_source' && e.value === 'COMPANY') {
                    var room = (parseFloat(e.record.get('available')) || 0) - otherCompanyQty(e.record);
                    if (room <= EPS) { e.record.set('supply_source', 'SUBCON'); showErr('Tidak ada kuota perusahaan tersisa untuk material ini, tetap sebagai material Subkon.'); }
                    else if ((parseFloat(e.record.get('qty')) || 0) > room + EPS) { e.record.set('qty', room); showErr('Qty disesuaikan ke kuota tersedia (' + fmtQty(room) + ').'); }
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'spkDeleteCol') { grid.getStore().removeAt(rowIndex); }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('spkHdrForm')) {
            var hdr = document.createElement('div'); hdr.id = 'spkHdrForm'; hdr.style.cssText = 'margin-bottom:2px;';
            g.parentNode.insertBefore(hdr, g);
            var taskBox = document.createElement('div'); taskBox.id = 'spkTaskBox'; taskBox.style.cssText = 'margin:8px 4px;padding:10px;background:#172936;border:1px solid #29495b;border-radius:4px;color:#dcebf0;';
            taskBox.innerHTML = '<b>Task Proyek (wajib)</b><div><select id="spkTaskSelect"><option value="">Pilih proyek terlebih dahulu...</option></select><button type="button" id="spkAddTask">+ Tambah Task</button></div><div id="spkTaskList"></div>';
            g.parentNode.insertBefore(taskBox, g);
            var q = document.createElement('div'); q.id = 'spkQuotaInfo'; q.style.cssText = 'margin:2px 4px 6px 4px;';
            g.parentNode.insertBefore(q, g);
            var bar = document.createElement('div'); bar.id = 'spkMatBar'; bar.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(bar, g);
        }
        quotaDiv = document.getElementById('spkQuotaInfo');
        taskSelect = document.getElementById('spkTaskSelect');
        taskListDiv = document.getElementById('spkTaskList');
        var addTaskButton = document.getElementById('spkAddTask');
        if (addTaskButton && !addTaskButton._spkBound) {
            addTaskButton._spkBound = true;
            addTaskButton.onclick = function () {
                var id = parseInt(taskSelect.value, 10);
                if (!id) { showErr('Pilih task proyek terlebih dahulu.'); return; }
                if (selectedTasks.some(function(t){ return parseInt(t.id,10) === id; })) { showErr('Task sudah ada di daftar SPK.'); return; }
                var opt = taskSelect.options[taskSelect.selectedIndex];
                selectedTasks.push({id:id, task_name:opt.getAttribute('data-name') || opt.text, description:opt.getAttribute('data-description') || '', status:opt.getAttribute('data-status') || '', start_date:opt.getAttribute('data-start') || '', end_date:opt.getAttribute('data-end') || ''});
                renderSelectedTasks();
            };
        }
    })();

    function renderSelectedTasks() {
        if (!taskListDiv) return;
        if (!selectedTasks.length) { taskListDiv.innerHTML = '<div >Belum ada task dipilih.</div>'; return; }
        var html = '<table style="width:100%;border-collapse:collapse"><thead><tr style="text-align:left;border-bottom:1px solid #365567"><th style="padding:5px">Task</th><th style="padding:5px">Jadwal</th><th style="padding:5px">Status</th><th></th></tr></thead><tbody>';
        selectedTasks.forEach(function(t, idx) { html += '<tr><td>' + enc(t.task_name) + '</td><td>' + enc(t.start_date || '-') + ' - ' + enc(t.end_date || '-') + '</td><td>' + enc(t.status || 'Belum Mulai') + '</td><td><button type="button" data-spk-task-index="' + idx + '" >Hapus</button></td></tr>'; });
        taskListDiv.innerHTML = html + '</tbody></table>';
        var buttons = taskListDiv.querySelectorAll('button[data-spk-task-index]');
        for (var i=0;i<buttons.length;i++) buttons[i].onclick = function(){
            var gone = selectedTasks.splice(parseInt(this.getAttribute('data-spk-task-index'),10),1)[0];
            // baris material yang tertaut ke task yang dilepas kembali menjadi "umum"
            if (gone) { itemDs.each(function (r) { if ((parseInt(r.get('task_id'), 10) || 0) === parseInt(gone.id, 10)) { r.set('task_id', 0); r.set('task_name', ''); } }); }
            renderSelectedTasks();
        };
    }
    function loadProjectTasks(projectId, existing) {
        selectedTasks = (existing || []).map(function(t){ return {id:parseInt(t.id || t.task_id,10),task_name:t.task_name || '',description:t.description || '',start_date:t.start_date || '',end_date:t.end_date || '',status:t.status || ''}; });
        if (taskSelect) taskSelect.innerHTML = '<option value="">Memuat task proyek...</option>';
        if (!projectId) { if(taskSelect) taskSelect.innerHTML='<option value="">Pilih proyek terlebih dahulu...</option>'; renderSelectedTasks(); return; }
        ajax({action:'lookup_tasks',project_id:projectId,exclude_id:editingId},function(res){
            if(!res.success){showErr(res.message || 'Gagal memuat task proyek.');return;}
            if(taskSelect){ var opts='<option value="">Pilih task proyek...</option>'; (res.data || []).forEach(function(t){opts+='<option value="'+enc(t.id)+'" data-name="'+enc(t.task_name)+'" data-description="'+enc(t.description || '')+'" data-status="'+enc(t.status || '')+'" data-start="'+enc(t.start_date || '')+'" data-end="'+enc(t.end_date || '')+'">'+enc(t.task_name)+' ('+enc(t.status || 'Belum Mulai')+')</option>';}); taskSelect.innerHTML=opts; }
            renderSelectedTasks();
        });
    }

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); matBar.doLayout(); return; }

        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal SPK', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fForeman = new myTextField({ fieldLabel: 'Mandor / Pelaksana', anchor: '95%' });
        fDesc = new myTextField({ fieldLabel: 'Uraian Pekerjaan', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fType = new myComboField({
            fieldLabel: 'Tipe SPK', store: [['INTERNAL', 'Internal (dikerjakan sendiri)'], ['SUBCON', 'Subkon (dikerjakan subkontraktor)']],
            mode: 'local', triggerAction: 'all', editable: false, forceSelection: true, allowBlank: false, value: 'INTERNAL', anchor: '95%',
            listeners: { select: function () { onTypeChange(); } }
        });
        fSubcon = new myComboField({
            fieldLabel: 'Subkontraktor', store: subconDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: true, anchor: '95%',
            tpl: '<tpl for="."><div class="x-combo-list-item">{name} <span >{code}</span></div></tpl>'
        });
        fStart = new Ext.form.DateField({ fieldLabel: 'Mulai Kerja', format: 'd/m/Y', width: 120 });
        fEnd = new Ext.form.DateField({ fieldLabel: 'Selesai Kerja', format: 'd/m/Y', width: 120 });

        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 120, items: items }; };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'spkHdrForm', border: false, layout: 'column', bodyStyle: 'padding:4px 2px 0 2px',
            items: [col(0.5, [fDate, fType, fProject, fStart, fEnd]), col(0.5, [fSubcon, fForeman, fDesc, fRemark])]
        });

        fMat = new myComboField({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, anchor: '98%', listWidth: 560,
            emptyText: 'Cari material dari RAB proyek...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(RAB {rab_qty}, stok {stock_available}, kuota {quota_available}, maksimal {available} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fProject.getValue()) { showErr('Pilih proyek terlebih dahulu.'); return false; }
                    materialDs.baseParams.project_id = fProject.getValue();
                    materialDs.baseParams.exclude_id = editingId;
                }
            }
        });


        matBar = new Ext.Panel({
            renderTo: 'spkMatBar', border: false, layout: 'column', bodyStyle: 'padding:2px',
            items: [
                { columnWidth: 0.45, layout: 'form', border: false, hideLabels: true, items: [fMat] },
                { width: 150, border: false, bodyStyle: 'padding-left:6px', items: [new Ext.Button({ text: '<span style="font-size:13px; font-weight:400; padding: 10 10">+ Tambah Material</span>' , handler: addMaterial })] },
                { width: 210, border: false, bodyStyle: 'padding-left:6px', items: [new Ext.Button({ text: '<span style="font-weight:400; font-size:13px; padding: 10 10">+ Semua Material (Qty Maks)</span>' , handler: addAllMaterials })] },     
                { columnWidth: 0.4, border: false, bodyStyle: 'padding:6px 0 0 8px; font-size:11px',
                  html: 'Qty SPK otomatis = kuota tersedia (maksimum), bisa dikurangi. Kuota = qty RAB x (% kontrak dibayar x 2).' }
            ]
        });
        onTypeChange();
    }

    // tampilkan / sembunyikan satu field form (ExtJS 3: sembunyikan juga pembungkus x-form-item)
    function showField(f, show) {
        f.setVisible(show);
        var el = f.getEl && f.getEl();
        var item = el && el.up('.x-form-item');
        if (item) { item.setDisplayed(show); }
        f.allowBlank = !show;
    }
    // Internal: field subkon + jadwal disembunyikan. Subkon: wajib pilih subkontraktor; material boleh kosong.
    function onTypeChange() {
        var sub = (fType.getValue() === 'SUBCON');
        showField(fSubcon, sub);
        showField(fStart, sub);
        showField(fEnd, sub);
        if (!sub) { fSubcon.clearValue(); fStart.setValue(''); fEnd.setValue(''); }
    }

    function loadQuotaInfo() {
        if (!fProject || !fProject.getValue()) { quotaDiv.innerHTML = ''; return; }
        ajax({ action: 'quota', project_id: fProject.getValue() }, function (res) {
            if (!res.success) { quotaDiv.innerHTML = '<span >' + enc(res.message || '') + '</span>'; return; }
            var p = res.data;
            var ok = p.paid_amount > 0;
            quotaDiv.innerHTML = '<div style="padding:6px 10px;">' +
                'Pembayaran diterima: <b>' + fmtAmt(p.paid_amount) + '</b> dari kontrak <b>' + fmtAmt(p.contract_value) + '</b> (<b>' + fmtPct(p.paid_pct) +
                '</b>) &rarr; batas material saat ini <b>' + fmtPct(p.allowed_pct) + '</b> dari qty RAB.' +
                (ok ? '' : ' <b>Belum ada pembayaran termin: SPK dapat disimpan sebagai Draft tetapi belum dapat diterbitkan.</b>') + '</div>';
        });
    }

    function onProjectChange() {
        itemDs.removeAll();
        fMat.clearValue();
        loadQuotaInfo();
        loadProjectTasks(fProject.getValue(), []);
    }

    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        var isSub = fType.getValue() === 'SUBCON', avail = parseFloat(rec.get('available')) || 0, src = 'COMPANY';
        if (avail <= 0) {
            if (!isSub) { showErr('Tidak ada kuota tersedia untuk material ini (kuota habis / belum ada pembayaran / sudah dipakai SPK lain).'); return; }
            src = 'SUBCON';   // tanpa kuota perusahaan: pada SPK subkon dicatat sebagai material yang dibawa subkon
        }
        var key = String(rec.get('material_id')) + '|0|' + src;
        if (itemDs.findBy(function (r) { return matKey(r) === key; }) >= 0) {
            showErr('Material ini sudah ada dengan task dan sumber yang sama. Ubah "Untuk Task" / "Sumber" pada baris yang ada, lalu tambahkan lagi bila perlu.'); return;
        }
        itemDs.add(new itemDs.recordType({
            material_id: rec.get('material_id'), material_code: rec.get('material_code'), material_name: rec.get('material_name'), unit: rec.get('unit'),
            rab_qty: rec.get('rab_qty'), cap_qty: rec.get('cap_qty'), stock_available: rec.get('stock_available'), quota_available: rec.get('quota_available'), available: rec.get('available'),
            qty: src === 'SUBCON' ? (parseFloat(rec.get('rab_qty')) || 1) : avail, notes: '', task_id: 0, task_name: '', supply_source: src
        }));
        fMat.clearValue();
    }

    function addAllMaterials() {
        if (!fProject.getValue()) { showErr('Pilih proyek terlebih dahulu.'); return; }
        ajax({ action: 'lookup_materials', project_id: fProject.getValue(), exclude_id: editingId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat material.'); return; }
            var added = 0, skippedNoQuota = 0;
            Ext.each(res.data || [], function (m) {
                var avail = parseFloat(m.available) || 0;
                if (itemDs.findBy(function (r) { return matKey(r) === String(m.material_id) + '|0|COMPANY'; }) >= 0) { return; }
                if (avail <= 0) { skippedNoQuota++; return; }
                itemDs.add(new itemDs.recordType({
                    material_id: m.material_id, material_code: m.material_code, material_name: m.material_name, unit: m.unit,
                    rab_qty: parseFloat(m.rab_qty) || 0, cap_qty: parseFloat(m.cap_qty) || 0, stock_available: parseFloat(m.stock_available) || 0, quota_available: parseFloat(m.quota_available) || 0, available: avail, qty: avail, notes: '',
                    task_id: 0, task_name: '', supply_source: 'COMPANY'
                }));
                added++;
            });
            if (!added) { showErr('Tidak ada material yang bisa ditambahkan' + (skippedNoQuota ? ' (' + skippedNoQuota + ' material tanpa kuota tersedia).' : '.')); return; }
            Ext.Msg.alert('Material ditambahkan', added + ' material ditambahkan dengan qty maksimum.' +
                          (skippedNoQuota ? ' ' + skippedNoQuota + ' material dilewati karena tidak ada kuota tersedia.' : ''));
        });
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
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membuat SPK.')) return;
        editingId = 0;
        projectDs.load({ callback: function () {
            showEditor('SPK Baru');
            fDate.setValue(new Date()); fProject.clearValue(); fForeman.setValue(''); fDesc.setValue(''); fRemark.setValue('');
            fType.setValue('INTERNAL'); fType.enable(); onTypeChange();
            itemDs.removeAll(); fMat.clearValue(); quotaDiv.innerHTML = '';
            selectedTasks = []; renderSelectedTasks(); if (taskSelect) taskSelect.innerHTML = '<option value="">Pilih proyek terlebih dahulu...</option>';
        } });
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih SPK terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Draft') { showErr('Hanya SPK berstatus Draft yang dapat diubah.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengubah SPK.')) return;
        ajax({ action: 'detail', id: dataRecord.id }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat SPK.'); return; }
            var h = res.header;
            editingId = parseInt(h.id, 10);
            projectDs.load({ callback: function () {
                showEditor('Edit SPK ' + h.spk_no);
                fDate.setValue(Date.parseDate(h.spk_date, 'Y-m-d') || new Date());
                fProject.setValue(parseInt(h.project_id, 10));
                fForeman.setValue(h.foreman || ''); fDesc.setValue(h.description || ''); fRemark.setValue(h.remark || '');
                fType.setValue(h.spk_type === 'SUBCON' ? 'SUBCON' : 'INTERNAL'); fType.disable();   // tipe tidak bisa diubah setelah disimpan
                onTypeChange();
                fStart.setValue(h.work_start_date ? Date.parseDate(h.work_start_date, 'Y-m-d') : '');
                fEnd.setValue(h.work_end_date ? Date.parseDate(h.work_end_date, 'Y-m-d') : '');
                if (h.spk_type === 'SUBCON' && h.execution_subcontractor_id) {
                    subconDs.load({ callback: function () { fSubcon.setValue(parseInt(h.execution_subcontractor_id, 10)); } });
                }
                fMat.clearValue(); itemDs.removeAll();
                loadQuotaInfo();
                loadProjectTasks(h.project_id, res.tasks || []);
                ajax({ action: 'lookup_materials', project_id: h.project_id, exclude_id: h.id }, function (q) {
                    var map = {};
                    Ext.each((q.data || []), function (m) { map[String(m.material_id)] = m; });
                    Ext.each(res.material_plan && res.material_plan.length ? res.material_plan : res.items, function (i) {
                        var m = map[String(i.material_id)] || {};
                        itemDs.add(new itemDs.recordType({
                            material_id: i.material_id, material_code: i.material_code, material_name: i.material_name, unit: i.unit,
                            rab_qty: parseFloat(m.rab_qty) || 0, cap_qty: parseFloat(m.cap_qty) || 0, stock_available: parseFloat(m.stock_available) || 0, quota_available: parseFloat(m.quota_available) || 0, available: parseFloat(m.available) || 0,
                            qty: parseFloat(i.qty), notes: i.notes || '',
                            task_id: i.task_id || 0, task_name: i.task_name || '', supply_source: i.supply_source || 'COMPANY'
                        }));
                    });
                });
            } });
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue() || !fDate.getValue()) { showErr('Proyek dan tanggal wajib diisi.'); return; }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty')) || 0;
            if (q <= 0) { err = 'Qty SPK ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
            arr.push({ material_id: r.get('material_id'), qty: q, notes: r.get('notes') || '', task_id: parseInt(r.get('task_id'), 10) || 0, supply_source: r.get('supply_source') || 'COMPANY' });
        });
        if (err) { showErr(err); return; }
        var spkType = fType.getValue() === 'SUBCON' ? 'SUBCON' : 'INTERNAL';
        if (spkType === 'INTERNAL' && !arr.length) { showErr('Tambahkan minimal satu material.'); return; }
        if (spkType === 'SUBCON' && !fSubcon.getValue()) { showErr('Pilih subkontraktor untuk SPK subkon.'); return; }
        if (spkType === 'SUBCON' && fStart.getValue() && fEnd.getValue() && fEnd.getValue() < fStart.getValue()) { showErr('Tanggal selesai kerja tidak boleh lebih awal dari tanggal mulai.'); return; }
        if (!selectedTasks.length) { showErr('Pilih minimal satu task proyek untuk SPK.'); return; }
        ajax({
            action: 'save', id: editingId, project_id: fProject.getValue(), spk_date: ymd(fDate.getValue()),
            foreman: fForeman.getValue(), description: fDesc.getValue(), remark: fRemark.getValue(),
            spk_type: spkType,
            execution_subcontractor_id: spkType === 'SUBCON' ? fSubcon.getValue() : 0,
            work_start_date: (spkType === 'SUBCON' && fStart.getValue()) ? ymd(fStart.getValue()) : '',
            work_end_date: (spkType === 'SUBCON' && fEnd.getValue()) ? ymd(fEnd.getValue()) : '',
            details: Ext.util.JSON.encode(arr),
            task_details: Ext.util.JSON.encode(selectedTasks.map(function(t){ return {task_id:t.id}; }))
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'SPK tersimpan.');
                document.getElementById('editorWdw').style.display = 'none';
                mainDs.reload({ callback: function () {
                    var idx = res.id ? mainDs.find('id', res.id) : -1;
                    if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; gridMain.getSelectionModel().selectRow(idx, false); updateMainButtons(); loadDetailPanel(dataRecord.id); }
                } });
            } else { showErr(res.message || 'Terjadi kesalahan sistem.'); }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Draft') { showErr('Pilih SPK berstatus Draft. SPK lain dibatalkan dari panel detail.'); return; }
        if (!needAccess(ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk menghapus SPK.')) return;
        Ext.Msg.confirm('Hapus SPK', 'Hapus SPK <b>' + enc(dataRecord.spk_no) + '</b>? Tindakan ini tidak dapat dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: 'delete', id: dataRecord.id }, function (res) {
                if (!res.success) { showErr(res.message || 'Gagal menghapus.'); return; }
                Ext.Msg.alert('Sukses', res.message || '');
                dataRecord = null; dtlDiv.style.display = 'none'; mainDs.reload(); updateMainButtons();
            });
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) { document.getElementById('popCancel').style.display = 'none'; }
    };
    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';
});