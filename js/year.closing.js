/*
  Document      : year.closing.js
  Description   : Tutup Buku Tahunan (jurnal penutup). Grid utama = daftar tahun yang sudah ditutup.
                  Add membuka editor: pilih tahun buku + akun Laba Ditahan + opsi kunci periode; pratinjau dari server
                  (saldo pendapatan & beban, laba/rugi, syarat penutupan). Simpan = membuat 1 jurnal penutup (closing_entry)
                  tanggal 31 Desember dan (opsional) mengunci seluruh periode tahun itu.
                  Delete = buka kembali penutupan TERAKHIR (jurnal dihapus, periode dibuka). Edit tidak tersedia.
                  Pola mengikuti po.js / project.wiprelease.js (template many-template-icn.php).
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/year.closingCRD.php
=======
  Backend       : /rab-project/live/php-crud/year.closingCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Tutup Buku <em>Tahunan</em>";

    var CRD = '../php-crud/year.closingCRD.php';
    var ACCESS_ADD = 3;      // tutup buku
    var ACCESS_DEL = 3;      // buka kembali

    var dataRecord = null;   // baris penutupan terpilih
    var frm = null;
    var fYear, fAcc, fLock, fRemark, fInfo;
    var previewReq = 0;
    var previewOk = false;
    var previewNotEnded = false;
    var selected = {};        // [PILIH AKUN] account_id -> true/false (checklist akun yang ikut ditutup)
    var lastPreview = null;   // hasil pratinjau terakhir (data akun)
    var lastYear = null;      // tahun pratinjau terakhir (pilihan direset saat tahun berganti)

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function showMsg(title, msg) { Ext.Msg.alert(title, msg); }

    function storeError(proxy, type, action, opt, res) {      // standar: setiap store punya handler exception
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showMsg('Gagal', m);
    }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showMsg('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () {
                if (onFail) onFail(); else showMsg('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    var closingField = [
        { name: 'id', type: 'int' }, { name: 'fiscal_year', type: 'int' }, { name: 'closing_date' }, { name: 'journal_no' },
        { name: 'revenue_total', type: 'float' }, { name: 'expense_total', type: 'float' }, { name: 'net_income', type: 'float' },
        { name: 'retained_account' }, { name: 'locked_count', type: 'int' }, { name: 'remark' }, { name: 'is_latest' }
    ];

    var yearDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_years' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'year' },
            [{ name: 'year', type: 'int' }, { name: 'closed' }, { name: 'label' }]),
        listeners: { exception: storeError }
    });
    var accDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_accounts' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id', type: 'int' }, { name: 'label' }, { name: 'is_default' }]),
        listeners: { exception: storeError }
    });

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_closings' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, closingField),
        listeners: { exception: storeError }
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: maxGridHeight(document.getElementById('divMainGrid')),
        columns: [
            { header: 'Tahun Buku', dataIndex: 'fiscal_year', width: 90, align: 'center' },
            { header: 'Tanggal Tutup', dataIndex: 'closing_date', width: 110, align: 'center' },
            { header: 'No. Jurnal', dataIndex: 'journal_no', width: 120, align: 'center' },
            { header: 'Total Pendapatan', dataIndex: 'revenue_total', width: 140, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Total Beban', dataIndex: 'expense_total', width: 140, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Laba / (Rugi) Bersih', dataIndex: 'net_income', width: 150, align: 'right',
                renderer: function (v) { return '<b style="color:' + (v >= 0 ? '#4ade80' : '#f87171') + ' !important;">' + fmtAmt(v) + '</b>'; }
            },
            { header: 'Akun Laba Ditahan', dataIndex: 'retained_account', width: 200, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Periode Dikunci', dataIndex: 'locked_count', width: 100, align: 'center' },
            { header: 'Catatan', dataIndex: 'remark', width: 200, renderer: function (v) { return CellWrap(v || ''); } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        // buka kembali hanya untuk penutupan terakhir (server juga memvalidasi)
        if (dataRecord && dataRecord.is_latest) { mainBtnEnable('#deleteData'); } else { mainBtnDisable('#deleteData'); }
    }
    editDataDisable();          // Edit tidak tersedia: koreksi = buka kembali lalu tutup ulang
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: form + pratinjau
    // ------------------------------------------------------------
    function ensureForm() {
        if (frm) { frm.doLayout(); return; }

        fYear = new myComboField({
            fieldLabel: 'Tahun Buku', store: yearDs, displayField: 'label', valueField: 'year',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, editable: false, width: 200,
            listeners: { select: function () { doPreview(); } }
        });
        fAcc = new myComboField({
            fieldLabel: 'Akun Laba Ditahan', store: accDs, displayField: 'label', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { doPreview(); } }
        });
        fLock = new Ext.form.Checkbox({ boxLabel: 'Kunci semua periode tahun ini setelah jurnal penutup dibuat', checked: true, hideLabel: true });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 130, bodyStyle: 'background:transparent;', items: items };
        };
        frm = new Ext.form.FormPanel({
            renderTo: 'editorFormGrid',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:6px 4px 0 4px;',
            items: [col(0.45, [fYear, fAcc]), col(0.55, [fRemark, fLock]),
                    { columnWidth: 1, layout: 'form', border: false, bodyStyle: 'background:transparent;', items: [fInfo] }]
        });
    }

    function resetForm() {
        fYear.clearValue();
        // akun default: Laba Ditahan (3200)
        var defId = null;
        accDs.each(function (r) {
            var d = r.get('is_default');
            if (defId === null && (d === true || d === 'true' || d === 1 || d === '1')) defId = r.get('id');
        });
        if (defId === null) { accDs.each(function (r) { if (defId === null && /^3200\b/.test(r.get('label'))) defId = r.get('id'); }); }
        if (defId !== null) fAcc.setValue(defId); else fAcc.clearValue();
        selected = {}; lastPreview = null; lastYear = null;      // [PILIH AKUN]
        fLock.setValue(true);
        fRemark.setValue('');
        fInfo.setValue('<span style="color:#668894;">Pilih tahun buku untuk melihat pratinjau jurnal penutup.</span>');
        previewOk = false;
        previewNotEnded = false;
        // tahun default: tahun terlama yang belum ditutup (penutupan harus berurutan)
        var pick = null;
        yearDs.each(function (r) { if (r.get('closed') !== true) pick = r.get('year'); });    // store urut turun -> baris terakhir = terlama
        if (pick !== null) { fYear.setValue(pick); doPreview(); }
    }

    function listHtml(items, color) {
        var h = '<ul style="margin:4px 0 8px 18px; padding:0; color:' + color + ';">';
        Ext.each(items, function (m) { h += '<li>' + enc(m) + '</li>'; });
        return h + '</ul>';
    }

    // [PILIH AKUN] tabel akun dengan checkbox per baris + checkbox "pilih semua" per bagian
    function accTable(title, secKey, items) {
        var allOn = items.length > 0;
        Ext.each(items, function (a) { if (!selected[a.account_id]) allOn = false; });
        var h = '<div style="margin-top:10px; font-weight:bold; font-size:14px;">' + title + '</div>' +
                '<table width="100%" style="border-collapse:collapse; font-size:13px;">' +
                '<tr style="background:rgba(255,255,255,0.10); font-weight:bold;">' +
                '<td style="padding:3px 6px;" width="34"><input type="checkbox" class="ycSec" data-sec="' + secKey + '"' + (allOn ? ' checked' : '') + (items.length ? '' : ' disabled') + ' title="Pilih semua ' + title.toLowerCase() + '"/></td>' +
                '<td style="padding:3px 6px;" width="90">Kode</td><td style="padding:3px 6px;">Nama Akun</td>' +
                '<td align="right" style="padding:3px 6px;" width="150">Saldo</td><td align="right" style="padding:3px 6px;" width="170">Jurnal Penutup</td></tr>';
        Ext.each(items, function (a, i) {
            h += '<tr style="' + (i % 2 ? 'background:rgba(15,113,194,0.2);' : '') + '">' +
                 '<td style="padding:3px 6px;"><input type="checkbox" class="ycChk" data-id="' + a.account_id + '" data-sec="' + secKey + '"' + (selected[a.account_id] ? ' checked' : '') + '/></td>' +
                 '<td style="padding:3px 6px;">' + enc(a.account_code) + '</td>' +
                 '<td style="padding:3px 6px;">' + enc(a.account_name) + '</td>' +
                 '<td align="right" style="padding:3px 6px;">' + fmtAmt(a.balance) + '</td>' +
                 '<td align="right" style="padding:3px 6px;">' + (a.debit > 0 ? 'Dr ' + fmtAmt(a.debit) : 'Cr ' + fmtAmt(a.credit)) + '</td></tr>';
        });
        if (!items.length) h += '<tr><td colspan="5" style="padding:6px; color:#668894;">Tidak ada saldo.</td></tr>';
        return h + '</table>';
    }

    function selectedIds() {
        var ids = [];
        if (!lastPreview) return ids;
        Ext.each(lastPreview.revenue.concat(lastPreview.expense), function (a) { if (selected[a.account_id]) ids.push(a.account_id); });
        return ids;
    }

    // [PILIH AKUN] hitung ulang total dari akun terpilih (tanpa memuat ulang daftar, supaya posisi scroll tidak hilang)
    function updateSelectionUI() {
        if (!lastPreview) return;
        var rev = 0, exp = 0, cnt = 0, all = 0;
        Ext.each(lastPreview.revenue, function (a) { all++; if (selected[a.account_id]) { rev += a.balance; cnt++; } });
        Ext.each(lastPreview.expense, function (a) { all++; if (selected[a.account_id]) { exp += a.balance; cnt++; } });
        var net = Math.round((rev - exp) * 100) / 100;
        var set = function (id, html, color) {
            var el = document.getElementById(id);
            if (el) { el.innerHTML = html; if (color) el.style.color = color; }
        };
        set('ycCnt', cnt + ' dari ' + all + ' akun dipilih', cnt === 0 ? '#ff6b5e' : (cnt < all ? '#e6a817' : '#9fc3d1'));
        set('ycRev', fmtAmt(rev));
        set('ycExp', fmtAmt(exp));
        set('ycNet', (net >= 0 ? 'LABA BERSIH ' : 'RUGI BERSIH ') + fmtAmt(Math.abs(net)) + ' &rarr; ' +
                     (net >= 0 ? 'Cr ' : 'Dr ') + enc(lastPreview.retained_account || 'Laba Ditahan'), net >= 0 ? '#7fe0a0' : '#ff6b5e');
        set('ycPartial', cnt > 0 && cnt < all ? 'Perhatian: akun yang tidak dipilih TIDAK ditutup, saldonya tetap terbawa ke tahun berikutnya.' : '');
        // status checkbox "pilih semua" tiap bagian
        var root = fInfo.getEl() ? fInfo.getEl().dom : null;
        if (!root) return;
        Ext.each(root.querySelectorAll('.ycSec'), function (box) {
            var items = box.getAttribute('data-sec') === 'rev' ? lastPreview.revenue : lastPreview.expense, on = items.length > 0;
            Ext.each(items, function (a) { if (!selected[a.account_id]) on = false; });
            box.checked = on;
        });
    }

    function wireSelection() {
        var root = fInfo.getEl() ? fInfo.getEl().dom : null;
        if (!root) return;
        Ext.each(root.querySelectorAll('.ycChk'), function (box) {
            box.onclick = function () { selected[parseInt(box.getAttribute('data-id'), 10)] = box.checked; updateSelectionUI(); };
        });
        Ext.each(root.querySelectorAll('.ycSec'), function (box) {
            box.onclick = function () {
                var items = box.getAttribute('data-sec') === 'rev' ? lastPreview.revenue : lastPreview.expense;
                Ext.each(items, function (a) { selected[a.account_id] = box.checked; });
                Ext.each(root.querySelectorAll('.ycChk[data-sec="' + box.getAttribute('data-sec') + '"]'), function (c) { c.checked = box.checked; });
                updateSelectionUI();
            };
        });
        var setAll = function (on) {
            Ext.each(lastPreview.revenue.concat(lastPreview.expense), function (a) { selected[a.account_id] = on; });
            Ext.each(root.querySelectorAll('.ycChk'), function (c) { c.checked = on; });
            updateSelectionUI();
        };
        var bAll = root.querySelector('.ycAllBtn'), bNone = root.querySelector('.ycNoneBtn');
        if (bAll) bAll.onclick = function () { setAll(true); };
        if (bNone) bNone.onclick = function () { setAll(false); };
    }

    var btnCss = 'background:transparent; color:#ffffff; font-family:inherit; font-size:12px; border:1px solid rgba(255,255,255,0.35); border-radius:3px; padding:2px 10px; cursor:pointer; margin-right:6px;';

    // Pratinjau dihitung server (rumus yang sama dengan saat simpan); akun dipilih lewat checklist
    function doPreview() {
        var y = fYear.getValue();
        previewOk = false;
        if (!y) { fInfo.setValue(''); return; }
        var token = ++previewReq;
        fInfo.setValue('<span style="color:#668894;">menghitung...</span>');
        ajax({ task: 'preview', year: y, retained_account_id: fAcc.getValue() || 0 }, function (res) {
            if (token !== previewReq) return;
            if (!res.success) { fInfo.setValue('<span style="color:#e6a817;">' + enc(res.message || 'Gagal menghitung.') + '</span>'); return; }
            var d = res.data;
            previewOk = (d.blockers.length === 0);
            previewNotEnded = !!d.not_ended;

            // pilihan: semua tercentang bila tahun berganti / pertama kali; selain itu pertahankan pilihan (akun baru default tercentang)
            var all = d.revenue.concat(d.expense), next = {};
            Ext.each(all, function (a) { next[a.account_id] = (lastYear === y && selected[a.account_id] !== undefined) ? selected[a.account_id] : true; });
            selected = next; lastYear = y; lastPreview = d;

            var html = '<div style="padding:8px 10px; background:#1f2d3a; border-radius:4px; line-height:1.6; max-height:340px; overflow-y:auto;">' +
                       'Jurnal penutup tanggal <b>' + enc(d.closing_date) + '</b>, laba/rugi dipindah ke <b>' + enc(d.retained_account || '-') + '</b>';
            if (d.blockers.length) html += '<div style="color:#ff6b5e; margin-top:6px;"><b>Tidak dapat diproses:</b>' + listHtml(d.blockers, '#ff6b5e') + '</div>';
            if (d.warnings.length) html += '<div style="color:#e6a817; margin-top:6px;">' + listHtml(d.warnings, '#e6a817') + '</div>';
            html += '<div style="margin-top:8px;"><button type="button" class="ycAllBtn" style="' + btnCss + '">Pilih Semua</button>' +
                    '<button type="button" class="ycNoneBtn" style="' + btnCss + '">Kosongkan</button>' +
                    '<span id="ycCnt" style="font-weight:bold;"></span></div>';
            html += accTable('PENDAPATAN', 'rev', d.revenue) + accTable('BEBAN', 'exp', d.expense);
            html += '<div style="margin-top:10px;">Total pendapatan dipilih: <b id="ycRev"></b> &nbsp;|&nbsp; Total beban dipilih: <b id="ycExp"></b></div>' +
                    '<div id="ycNet" style="margin-top:4px; font-size:15px; font-weight:bold;"></div>' +
                    '<div id="ycPartial" style="margin-top:4px; color:#e6a817;"></div>';
            var open = 0; Ext.each(d.periods, function (p) { if (!p.is_closed) open++; });
            html += '<div style="margin-top:6px; color:#9fc3d1;">Periode tahun ini: ' + d.periods.length + ' (terbuka ' + open + ').</div></div>';
            fInfo.setValue(html);
            wireSelection();
            updateSelectionUI();
        });
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureForm();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = proses tutup buku
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            showMsg('Akses Ditolak', 'Anda tidak memiliki hak akses untuk tutup buku.');
            return;
        }
        yearDs.load({
            callback: function () {
                accDs.load({
                    callback: function () {          // combo harus terisi sebelum form dipakai
                        showEditor('Tutup Buku Tahunan');
                        resetForm();
                    }
                });
            }
        });
    };

    // SAVE
    document.getElementById('saveEdit').onclick = function () {
        if (!fYear || !fYear.getValue()) { showMsg('Peringatan', 'Tahun buku wajib dipilih.'); return; }
        if (!fAcc.getValue()) { showMsg('Peringatan', 'Akun Laba Ditahan wajib dipilih.'); return; }
        if (!previewOk) { showMsg('Peringatan', 'Penutupan belum dapat diproses. Periksa pratinjau di form (bagian "Tidak dapat diproses").'); return; }
        var ids = selectedIds();                                              // [PILIH AKUN]
        if (!ids.length) { showMsg('Peringatan', 'Pilih minimal satu akun yang akan ditutup.'); return; }
        var totalAcc = lastPreview ? (lastPreview.revenue.length + lastPreview.expense.length) : ids.length;

        var year = fYear.getValue();
        var msg = 'Proses tutup buku tahun <b>' + year + '</b>?<br/>Jurnal penutup dibuat tanggal 31 Desember ' + year +
                  (fLock.getValue() ? ' dan <b>seluruh periode tahun ' + year + ' akan dikunci</b>.' : '.') +
                  '<br/>Penutupan dapat dibuka kembali selama belum ada tahun yang lebih baru ditutup.';
        if (ids.length < totalAcc) msg = '<span style="color:#e6a817;">Hanya ' + ids.length + ' dari ' + totalAcc + ' akun yang ditutup; sisanya tetap bersaldo.</span><br/>' + msg;
        if (previewNotEnded) msg = '<span style="color:#e6a817;">Tahun ' + year + ' belum berakhir (uji coba).</span><br/>' + msg;

        Ext.Msg.confirm('Tutup Buku', msg, function (btn) {
            if (btn !== 'yes') return;
            ajax({
                task: 'save_closing',
                year: year,
                retained_account_id: fAcc.getValue(),
                account_ids: Ext.util.JSON.encode(ids),               // [PILIH AKUN] akun yang ikut ditutup
                lock_periods: fLock.getValue() ? 1 : 0,
                force: previewNotEnded ? 1 : 0,
                remark: fRemark.getValue()
            }, function (res) {
                showMsg(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    document.getElementById('editorWdw').style.display = 'none';
                    dataRecord = null;
                    mainDs.load();
                    updateMainButtons();
                } else {
                    doPreview();
                }
            });
        });
    };

    // DELETE = buka kembali penutupan terakhir
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { showMsg('Peringatan', 'Pilih tahun yang sudah ditutup dari tabel.'); return; }
        if (!dataRecord.is_latest) { showMsg('Peringatan', 'Hanya penutupan tahun terakhir yang dapat dibuka kembali.'); return; }
        if (getFormAccess() < ACCESS_DEL) {
            showMsg('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuka kembali tutup buku.');
            return;
        }
        Ext.Msg.confirm('Buka Kembali Tutup Buku',
            'Buka kembali tutup buku tahun <b>' + enc(dataRecord.fiscal_year) + '</b>?<br/>Jurnal penutup <b>' + enc(dataRecord.journal_no) +
            '</b> dihapus dan periode yang dikunci dibuka kembali.',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_closing', id: dataRecord.id }, function (res) {
                    showMsg(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) {
                        dataRecord = null;
                        mainDs.load();
                        updateMainButtons();
                    }
                });
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