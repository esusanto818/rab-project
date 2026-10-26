/*
  Document      : m.journal.js
  Description   : Journal Entry (manual) - Header Grid + Detail View + Editor Form
  Backend       : /rab-project/dev/php-crud/journal.entryCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    var CRD = main_url + '/php-crud/journal.entryCRD.php';

    document.getElementById("mainHeaderText").innerHTML = "Journal <em>Entry</em>";

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // 0. Container Detail Baris Jurnal (Pola Detail Panel GRN)
    var gridContainer = document.getElementById('divMainGrid');

    var journalDtlDiv = document.createElement('div');
    journalDtlDiv.id = 'journalDtlContainer';
    journalDtlDiv.className = 'detail-panel-container';
    journalDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(journalDtlDiv, gridContainer.nextSibling);
    }

    // Buat elemen UI tambahan pada form editor
    (function injectExtraUI() {
        var iconMenu = document.getElementById('iconMenuL');
        if (iconMenu && !document.getElementById('postData')) {
            var postBtn = document.createElement('a');
            postBtn.id = 'postData';
            postBtn.href = 'javascript:void(0);';
            postBtn.style.display = 'none';
            postBtn.innerHTML = '<t>Post</t><i class="material-icons md-22 white">lock</i>';
            iconMenu.appendChild(postBtn);
        }

        var gridEditorContainer = document.getElementById('editorFormGrid');
        if (gridEditorContainer && !document.getElementById('btnAddLine')) {
            var toolbar = document.createElement('div');
            toolbar.style.padding = '5px 0';
            toolbar.innerHTML = '<button id="btnAddLine" type="button" class="field-button grey-field-button">+ Tambah Baris</button>';
            gridEditorContainer.parentNode.insertBefore(toolbar, gridEditorContainer);

            var totalsDiv = document.createElement('div');
            totalsDiv.className = 'journal-totals';
            totalsDiv.innerHTML =
                '<span>Total Debit: <span id="lblTotalDebit">0.00</span></span>' +
                '<span>Total Kredit: <span id="lblTotalCredit">0.00</span></span>' +
                '<span id="lblBalanceStatus"></span>';
            gridEditorContainer.parentNode.insertBefore(totalsDiv, gridEditorContainer.nextSibling);
        }
    })();

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var journalField = [
        { name: 'id' }, { name: 'ref_no' }, { name: 'journal_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'period_id' }, { name: 'period_name' }, { name: 'description' },
        { name: 'total_debit', type: 'float' }, { name: 'total_credit', type: 'float' },
        { name: 'status' }
    ];

    var lineField = [
        { name: 'line_id' }, { name: 'account_id' }, { name: 'account_code' }, { name: 'account_name' },
        { name: 'debit', type: 'float' }, { name: 'credit', type: 'float' }, { name: 'description' }
    ];
    var addLineRow = Ext.data.Record.create(lineField);

    var coaField = [{ name: 'id' }, { name: 'account_code' }, { name: 'account_name' }];
    var coaDs = new Ext.data.Store({
        url: '../dev/php-crud/m.coaCRD.php',
        baseParams: { task: 'read_parent' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, coaField)
    });
    coaDs.load();

    var periodField = [{ name: 'id' }, { name: 'period_name' }];
    var periodDs = new Ext.data.Store({
        url: '../dev/php-crud/m.fiscalperiodCRD.php',
        baseParams: { task: 'read_open' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField)
    });
    periodDs.load();

    var lineGridDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_lines' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, lineField)
    });

    var mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, journalField)
    });
    mainDs.load();

    function statusRenderer(v) {
        if (v === 'posted') return '<span style="color:#2e9e4f;font-weight:bold;">Posted</span>';
        if (v === 'reversed') return '<span style="color:#8e44ad;font-weight:bold;">Reversed</span>';
        return '<span style="color:#e08e0b;">Draft</span>';
    }

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.45));

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. Jurnal', dataIndex: 'ref_no', width: 110, renderer: CellWrap },
            { header: 'Tanggal', dataIndex: 'journal_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'Periode', dataIndex: 'period_name', width: 110, renderer: CellWrap },
            { header: 'Keterangan', dataIndex: 'description', width: 220, renderer: CellWrap },
            {
                header: 'Total Debit', dataIndex: 'total_debit', width: 110, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Total Kredit', dataIndex: 'total_credit', width: 110, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center', renderer: statusRenderer }
        ],
        stripeRows: true,
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                    togglePostButton();
                    toggleEditViewButtons();
                    loadJournalDetail(dataRecord.id, dataRecord.ref_no);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Detail View Renderer (Desain Seragam dengan GRN)
    // ------------------------------------------------------------
    // [PRINT-JV][NEW] Cetak satu jurnal (per id) -- dipanggil dari tombol Print di panel detail
    function printJournal(journalId) {
        if (!journalId) return;
        window.open('../pdf/journal.php?id=' + encodeURIComponent(journalId), '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    }

    function loadJournalDetail(journalId, refNo) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_lines', id: journalId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    journalDtlDiv.innerHTML = '<div style="color:yellow;">Gagal memuat detail jurnal.</div>';
                    journalDtlDiv.style.display = 'block';
                    return;
                }
                renderJournalDetailView(res.data || [], refNo, journalId);     // [PRINT-JV] + journalId
            },
            failure: function() {
                journalDtlDiv.innerHTML = '<div style="color:red;">Gagal terhubung ke server.</div>';
                journalDtlDiv.style.display = 'block';
            }
        });
    }

    function renderJournalDetailView(rows, refNo, journalId) {     // [PRINT-JV] + journalId
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var totalDebit = 0, totalCredit = 0;

        // [PRINT-JV][NEW] judul detail + tombol Print (cetak jurnal ini saja)
        var html = '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                   '<h3 class="detail-title" style="margin:0;">Detail Baris Jurnal: ' + Ext.util.Format.htmlEncode(refNo || '') + '</h3>' +
                   // [PRINT-JV] gaya disamakan dengan tombol toolbar utama: latar transparan, ikon & teks putih (bukan tombol abu-abu terang)
                   '<button id="btnPrintJournalDtl" type="button" ' +
                   'style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#ffffff; font-family:inherit; font-size:13px; ' +
                   'border:1px solid rgba(255,255,255,0.35); border-radius:3px; padding:4px 14px; cursor:pointer;" ' +
                   'onmouseover="this.style.background=\'rgba(255,255,255,0.12)\';" onmouseout="this.style.background=\'transparent\';">' +
                   '<i class="material-icons md-22 white">print</i> Print</button>' +
                   '</div>' +
                   '<hr class="detail-hr" />' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left">Kode Akun</th>' +
                   '<th align="left">Nama Akun</th>' +
                   '<th align="left">Journal </th>' +
                   '<th align="right">Debit</th>' +
                   '<th align="right">Kredit</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="5" class="empty-msg">Tidak ada baris jurnal.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var debit = parseFloat(r.debit) || 0;
                var credit = parseFloat(r.credit) || 0;
                totalDebit += debit;
                totalCredit += credit;

                html += '<tr class="data-row">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.account_code || '-') + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.account_name || '-') + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.description || '-') + '</td>' +
                        '<td align="right" style="color:#4ade80; font-weight:bold;">' + (debit ? fmtAmt(debit) : '-') + '</td>' +
                        '<td align="right" style="color:#f87171; font-weight:bold;">' + (credit ? fmtAmt(credit) : '-') + '</td>' +
                        '</tr>';
            });
        }

        html += '</tbody>' +
                '<tfoot>' +
                '<tr class="total-row">' +
                '<td colspan="3" align="right" class="total-label">TOTAL:</td>' +
                '<td align="right" style="color:#4ade80; font-weight:bold;">' + fmtAmt(totalDebit) + '</td>' +
                '<td align="right" style="color:#f87171; font-weight:bold;">' + fmtAmt(totalCredit) + '</td>' +
                '</tr>' +
                '</tfoot>' +
                '</table>';

        journalDtlDiv.innerHTML = html;
        journalDtlDiv.style.display = 'block';

        var btnPrint = document.getElementById('btnPrintJournalDtl');     // [PRINT-JV] dibuat ulang tiap render -> pasang handler di sini
        if (btnPrint) btnPrint.onclick = function () { printJournal(journalId); };
    }

    function toggleEditViewButtons() {
        var editBtn = document.getElementById('editData');
        var viewBtn = document.getElementById('viewData');
        if (!dataRecord) return;
        var isDraft = dataRecord.status === 'draft';
        if (editBtn) editBtn.style.display = isDraft ? 'block' : 'none';
        if (viewBtn) viewBtn.style.display = isDraft ? 'none' : 'block';
    }

    function setLineEditable(editable) {
        var cm = lineGrid.getColumnModel();
        for (var i = 0; i < cm.getColumnCount(); i++) {
            if (cm.isHidden(i)) continue;
            cm.setEditable(i, editable);
        }
        var addBtn = document.getElementById('btnAddLine');
        if (addBtn) addBtn.style.display = editable ? 'inline-block' : 'none';
        var saveBtn = document.getElementById('saveEdit');
        if (saveBtn) saveBtn.style.display = editable ? 'block' : 'none';
        fldDate.setDisabled(!editable);
        fldPeriod.setDisabled(!editable);
        fldDesc.setDisabled(!editable);
    }

    function togglePostButton() {
        var btn = document.getElementById('postData');
        if (!btn || !dataRecord) return;
        if (dataRecord.status === 'draft') {
            btn.style.display = 'block';
            btn.innerHTML = '<t>Post</t><i class="material-icons md-22 white">lock</i>';
        } else if (dataRecord.status === 'posted') {
            btn.style.display = 'block';
            btn.innerHTML = '<t>Reverse</t><i class="material-icons md-22 white">undo</i>';
        } else {
            btn.style.display = 'none';
        }
    }

    // ------------------------------------------------------------
    // 4. Line Grid Editor (Form Dialog)
    // ------------------------------------------------------------
    var lineDeleter = new Extensive.grid.ItemDeleter();

    var lineGrid = new stdGridEditor({
        store: lineGridDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 220,
        selModel: lineDeleter,
        columns: [
            {
                header: 'Akun', dataIndex: 'account_id', width: 220,
                editor: new myComboField({ store: coaDs, displayField: 'account_name', valueField: 'id' }),
                renderer: function (v, meta, record) {
                    return record.get('account_code') ? (record.get('account_code') + ' - ' + record.get('account_name')) : '';
                }
            },
            {
                header: 'Debit', dataIndex: 'debit', width: 110, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
            },
            {
                header: 'Kredit', dataIndex: 'credit', width: 110, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
            },
            {
                header: 'Keterangan', dataIndex: 'description', width: 180,
                editor: new myTextField({}), renderer: CellWrap
            },
            lineDeleter
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'account_id') {
                    var rec = coaDs.getById(e.value);
                    if (rec) {
                        e.record.set('account_code', rec.get('account_code'));
                        e.record.set('account_name', rec.get('account_name'));
                    }
                }
                updateTotals();
            }
        }
    });
    lineGrid.addClass('x-grid3-gridEditor');

    document.getElementById('btnAddLine') && (document.getElementById('btnAddLine').onclick = function () {
        lineGridDs.add(new addLineRow({ account_id: null, debit: 0, credit: 0, description: '' }));
    });

    function updateTotals() {
        var totalDebit = 0, totalCredit = 0;
        lineGridDs.each(function (r) {
            totalDebit += parseFloat(r.get('debit')) || 0;
            totalCredit += parseFloat(r.get('credit')) || 0;
        });
        var debitEl = document.getElementById('lblTotalDebit');
        var creditEl = document.getElementById('lblTotalCredit');
        var balanceEl = document.getElementById('lblBalanceStatus');
        if (debitEl) debitEl.innerHTML = Ext.util.Format.number(totalDebit, '0,000.00');
        if (creditEl) creditEl.innerHTML = Ext.util.Format.number(totalCredit, '0,000.00');
        if (balanceEl) {
            var isBalance = Math.abs(totalDebit - totalCredit) < 0.01;
            balanceEl.innerHTML = isBalance ? 'BALANCE' : 'TIDAK BALANCE';
            balanceEl.style.color = isBalance ? '#2e9e4f' : '#c0392b';
        }
    }
    lineGridDs.on('update', updateTotals);
    lineGridDs.on('add', updateTotals);
    lineGridDs.on('remove', updateTotals);
    lineGridDs.on('load', updateTotals);

    // ------------------------------------------------------------
    // 5. Editor Form
    // ------------------------------------------------------------
    var fldJournalNo = new myTextField({ fieldLabel: 'No. Jurnal', id: 'ref_no', name: 'ref_no', readOnly: true, emptyText: 'Auto-generate' });
    var fldDate = new myDateField({ fieldLabel: 'Tanggal', id: 'journal_date', name: 'journal_date', allowBlank: false, value: new Date() });
    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', id: 'period_id', name: 'period_id', hiddenName: 'period_id',
        store: periodDs, displayField: 'period_name', valueField: 'id', allowBlank: false
    });
    var fldDesc = new myTextArea({ fieldLabel: 'Keterangan', id: 'description', name: 'description' });
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 100,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldJournalNo },
            { items: fldDate },
            { items: fldPeriod },
            { columnWidth: 1, items: fldDesc },
            { items: fldId }
        ]
    });
    frmEditor.on('clientvalidation', function (f, valid) { if (!valid) { saveEditDisable(); } else { saveEditEnable(); } });

    // ------------------------------------------------------------
    // 6. Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        task = '';
        frmEditor.getForm().reset();
        if (getFormAccess() == 2 || getFormAccess() == 3 || getFormAccess() == 4) {
            task = 'create';
            lineGridDs.removeAll();
            lineGridDs.add(new addLineRow({ account_id: null, debit: 0, credit: 0, description: '' }));
            lineGridDs.add(new addLineRow({ account_id: null, debit: 0, credit: 0, description: '' }));
            updateTotals();
            setLineEditable(true);
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Jurnal';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function () {
        task = '';
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (dataRecord.status !== 'draft') {
            document.getElementById('popWdwTxt').innerHTML = 'Hanya jurnal berstatus Draft yang boleh diedit. Gunakan tombol View untuk melihat detail.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 3 || getFormAccess() == 4) {
            frmEditor.getForm().reset();
            task = 'update';
            frmEditor.getForm().load({ url: CRD, params: { id: dataRecord.id, task: 'read_edit' } });
            lineGridDs.load({ params: { id: dataRecord.id } });
            setLineEditable(true);
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Jurnal';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    var viewBtn = document.getElementById('viewData');
    if (viewBtn) {
        viewBtn.onclick = function () {
            if (!dataRecord) {
                document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            task = '';
            frmEditor.getForm().reset();
            frmEditor.getForm().load({ url: CRD, params: { id: dataRecord.id, task: 'read_edit' } });
            lineGridDs.load({ params: { id: dataRecord.id } });
            setLineEditable(false);
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Detail Jurnal (' + dataRecord.status.toUpperCase() + ') - Read Only';
        };
    }

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (dataRecord.status !== 'draft') {
            document.getElementById('popWdwTxt').innerHTML = 'Hanya jurnal berstatus Draft yang boleh dihapus.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 4) {
            task = 'delete';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById('popWdwTxt').innerHTML = 'Hapus jurnal \'' + dataRecord.ref_no + '\' ?';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses untuk menghapus data.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    var postBtn = document.getElementById('postData');
    if (postBtn) {
        postBtn.onclick = function () {
            if (!dataRecord) {
                document.getElementById('popWdwTxt').innerHTML = 'Pilih jurnal terlebih dahulu.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            if (dataRecord.status === 'draft') {
                task = 'post';
                document.getElementById('popWdw').style.display = 'block';
                document.getElementById('popCancel').style.display = 'block';
                document.getElementById('popWdwTxt').innerHTML = 'Post jurnal \'' + dataRecord.ref_no + '\'? Setelah di-post, jurnal tidak bisa diedit/dihapus lagi.';
            } else if (dataRecord.status === 'posted') {
                task = 'reverse';
                document.getElementById('popWdw').style.display = 'block';
                document.getElementById('popCancel').style.display = 'block';
                document.getElementById('popWdwTxt').innerHTML = 'Reverse jurnal \'' + dataRecord.ref_no + '\'? Ini akan membuat jurnal koreksi baru dengan debit/kredit dibalik.';
            }
        };
    }

    document.getElementById('printData').onclick = function (event) {
        // [PRINT-JV] Tombol Printing utama = cetak SEMUA jurnal (tidak tergantung baris terpilih).
        //            Cetak per jurnal memakai tombol Print di panel Detail Baris Jurnal (printJournal).
        //            Argumen fitur window.open dijadikan satu string (sebelumnya terpisah & diabaikan).
        window.open('../dev/pdf/journal.php', '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    };

    document.getElementById('saveEdit').onclick = function () {
        var totalDebit = 0, totalCredit = 0;
        var arrLines = [];
        lineGridDs.each(function (r) {
            var acc = r.get('account_id');
            var debit = parseFloat(r.get('debit')) || 0;
            var credit = parseFloat(r.get('credit')) || 0;
            if (!acc || (debit === 0 && credit === 0)) return;
            totalDebit += debit;
            totalCredit += credit;
            arrLines.push({ account_id: acc, debit: debit, credit: credit, description: r.get('description') });
        });

        if (arrLines.length < 2) {
            document.getElementById('popWdwTxt').innerHTML = 'Jurnal minimal harus punya 2 baris (debit & kredit).';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (Math.abs(totalDebit - totalCredit) > 0.01) {
            document.getElementById('popWdwTxt').innerHTML = 'Jurnal tidak balance! Total Debit: ' + totalDebit.toFixed(2) + ', Total Kredit: ' + totalCredit.toFixed(2);
            document.getElementById('popWdw').style.display = 'block';
            return;
        }

        frmEditor.getForm().submit({
            url: CRD,
            method: 'POST',
            params: { task: task, arrLines: JSON.stringify(arrLines) },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
                journalDtlDiv.style.display = 'none';
                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                lineGridDs.removeAll();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message || 'Gagal menyimpan jurnal.';
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('editorWdw').style.display = 'none';
});