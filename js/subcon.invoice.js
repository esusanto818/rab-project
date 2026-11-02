/*
Document      : subcon.invoice.js
Description   : Modul Invoice & Pembayaran Subkontraktor (2-Step Payment)
                - Style button disamakan dengan modul Subkon (uiBtn + TEAL/GREY/BURG)
                - Judul window "Invoice Baru" diperbesar 14px
Backend       : ../php-crud/subcon.invoiceCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Invoice & Pembayaran <em>Subkontraktor</em>";
    
    var CRD = main_url + '/php-crud/subcon.invoiceCRD.php';
    var ACCESS_ADD = 2;
    var ACCESS_EDIT = 3;
    var VEPS = 0.01;
    
    // ============================================================
    // [NEW] SAMAKAN STYLE BUTTON DENGAN MODUL SUBKON
    // ============================================================
    var TEAL = '#066b78', GREY = '#555555', BURG = '#800020';
    
    function uiBtn(cls, label, bg, attrs) {
        return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' style="margin-left:8px;padding:8px 20px;background:' + bg +
               ';color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">' + label + '</button>';
    }
    
    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diajukan': return '#e6a817 !important';
            case 'Disetujui': return '#2bc1e2 !important';
            case 'Posted': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
    }
    
    // [NEW] CSS override untuk judul window ExtJS 3.4
    if (!document.getElementById('scInvWindowStyle')) {
        var styleEl = document.createElement('style');
        styleEl.id = 'scInvWindowStyle';
        styleEl.innerHTML = 
            '.x-window-header .x-window-header-text { font-size: 16px !important; font-weight: normal !important; color: #22d3ee !important; }' +
            '.x-window-header { background: #0d2137 !important; }' +
            '.scInvActBtn { font-weight: bold !important; }';
        document.head.appendChild(styleEl);
    }
    
    var dataRecord = null;
    var editingId = 0;
    
    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    
    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respons server tidak valid.'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }
    
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }
    
    // ============================================================
    // STORES
    // ============================================================
    var rdr = function (fields, idp) { return new Ext.data.JsonReader({ root: 'data', idProperty: idp || 'id' }, fields); };
    
    var contractDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_contracts' },
        reader: rdr([{ name: 'id' }, { name: 'spk_no' }, { name: 'subcontractor_name' }, { name: 'contract_value', type: 'float' }, { name: 'status' }]),
        listeners: { exception: function() {} }
    });
    
    var progressDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_progress' },
        reader: rdr([{ name: 'id' }, { name: 'ref_no' }, { name: 'progress_date' }, { name: 'net_payable', type: 'float' },
            { name: 'paid_amount', type: 'float' }, { name: 'unpaid_amount', type: 'float' }, { name: 'status' }]),
        listeners: { exception: function() {} }
    });
    
    var accountDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_accounts' },
        reader: rdr([{ name: 'id' }, { name: 'label' }]),
        listeners: { exception: function() {} }
    });
    
    var invoiceDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_invoices' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, [
            { name: 'id' }, { name: 'invoice_no' }, { name: 'contract_id' }, { name: 'spk_no' },
            { name: 'subcontractor_name' }, { name: 'progress_id' }, { name: 'progress_ref_no' },
            { name: 'invoice_date' }, { name: 'due_date' }, { name: 'total_amount', type: 'float' },
            { name: 'paid_amount', type: 'float' }, { name: 'unpaid_amount', type: 'float' },
            { name: 'status' }, { name: 'reference_no' }, { name: 'notes' }, { name: 'created_by_name' }
        ]),
        listeners: { exception: function() {} }
    });
    
    invoiceDs.load();
    
    // ============================================================
    // GRID UTAMA
    // ============================================================
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var mainHeight = Math.round(GridMainHeight * 0.7);
    
    var gridMain = new Ext.grid.GridPanel({
        store: invoiceDs, renderTo: 'divMainGrid', height: mainHeight,
        columns: [
            { header: 'No. Invoice', dataIndex: 'invoice_no', width: 120 },
            { header: 'Tanggal', dataIndex: 'invoice_date', width: 90, align: 'center' },
            { header: 'No. SPK', dataIndex: 'spk_no', width: 110 },
            { header: 'Subkontraktor', dataIndex: 'subcontractor_name', width: 180, renderer: CellWrap },
            { header: 'No. Prestasi', dataIndex: 'progress_ref_no', width: 110 },
            { header: 'Total Invoice', dataIndex: 'total_amount', width: 120, align: 'right', renderer: function(v) { return '<b>' + fmtAmt(v) + '</b>'; } },
            { header: 'Sudah Dibayar', dataIndex: 'paid_amount', width: 120, align: 'right', renderer: fmtAmt },
            { header: 'Sisa Hutang', dataIndex: 'unpaid_amount', width: 120, align: 'right', renderer: function(v) { return '<span style="color:' + (v > 0 ? '#e6a817' : '#27ae60') + ';">' + fmtAmt(v) + '</span>'; } },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center', renderer: function(v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } },
            { header: 'Dibuat Oleh', dataIndex: 'created_by_name', width: 120 }
        ],
        viewConfig: { forceFit: true }, loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    updateMainButtons();
                    loadDetailPanel(dataRecord.id);
                }
            }
        }
    });
    
    function updateMainButtons() {
        mainBtnEnable('#addData');
        if (dataRecord && dataRecord.status === 'Draft') { mainBtnEnable('#editData'); mainBtnEnable('#deleteData'); }
        else { mainBtnDisable('#editData'); mainBtnDisable('#deleteData'); }
    }
    editDataDisable(); updateMainButtons();
    
    // ============================================================
    // DETAIL PANEL
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'scInvDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling); }
    
    function loadDetailPanel(invoiceId) {
        ajax({ task: 'load_invoice', id: invoiceId }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow;padding:10px;">' + enc(res.message || 'Gagal memuat detail.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetail(res.data.invoice, res.data.payments || []);
        });
    }
    
    function renderDetail(inv, payments) {
        var html = '<h3 class="detail-title">Invoice: ' + enc(inv.invoice_no) + ' <span style="color:' + statusColor(inv.status) + ';">(' + enc(inv.status) + ')</span></h3>' +
            '<hr class="detail-hr" />' +
            '<div style="padding:4px 2px 10px 2px;">SPK: <b>' + enc(inv.spk_no) + '</b> | Subkon: <b>' + enc(inv.subcontractor_name) + '</b>' +
            (inv.npwp_no ? ' (NPWP ' + enc(inv.npwp_no) + ')' : '') + ' | Tanggal: <b>' + enc(inv.invoice_date) + '</b>' +
            (inv.due_date ? ' | Jatuh Tempo: <b>' + enc(inv.due_date) + '</b>' : '') + '</div>' +
            '<div style="padding:0 2px 10px 2px;color:#9fc3d1;">Total: <b>' + fmtAmt(inv.total_amount) + '</b> | Dibayar: <b>' + fmtAmt(inv.paid_amount) + '</b> | Sisa: <b>' + fmtAmt(inv.unpaid_amount) + '</b></div>';
        
        // Detail prestasi (jika ada)
        if (inv.progress_id) {
            html += '<h4 style="margin:14px 0 4px 0;">Detail Prestasi</h4>' +
                '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><tbody>' +
                '<tr><td>Nilai (DPP)</td><td align="right">' + fmtAmt(inv.gross_amount) + '</td></tr>' +
                '<tr><td>PPN</td><td align="right">' + fmtAmt(inv.ppn_amount) + '</td></tr>' +
                '<tr><td>PPh</td><td align="right">' + fmtAmt(inv.pph_amount) + '</td></tr>' +
                '<tr><td>Retensi</td><td align="right">' + fmtAmt(inv.retention_amount) + '</td></tr>' +
                '<tr><td>Pot. Uang Muka</td><td align="right">' + fmtAmt(inv.dp_recovery) + '</td></tr>' +
                '<tr><td><b>Nilai Bayar</b></td><td align="right"><b>' + fmtAmt(inv.net_payable) + '</b></td></tr>' +
                '</tbody></table>';
        }
        
        // Pembayaran
        html += '<h4 style="margin:14px 0 4px 0;">Pembayaran</h4>' +
            '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
            '<th>No. Bayar</th><th>Tanggal</th><th>Akun</th><th>Metode</th><th>Jumlah</th><th>Status</th><th></th>' +
            '</tr></thead><tbody>';
        
        if (!payments.length) {
            html += '<tr><td colspan="7" style="padding:12px;text-align:center;color:#557982;">Belum ada pembayaran.</td></tr>';
        } else {
            Ext.each(payments, function (p) {
                // [FIX] Tombol Batal pembayaran pakai uiBtn (sama dengan subcon.js)
                var cancelBtn = p.status === 'Posted' 
                    ? uiBtn('scInvActBtn', 'Batal', BURG, 'data-act="cancel_payment" data-id="' + p.id + '"') 
                    : '';
                html += '<tr class="data-row"><td>' + enc(p.payment_no) + '</td><td>' + enc(p.payment_date) + '</td>' +
                    '<td>' + enc(p.account_code + ' ' + p.account_name) + '</td><td>' + enc(p.payment_method) + '</td>' +
                    '<td align="right">' + fmtAmt(p.amount) + '</td>' +
                    '<td><span style="color:' + statusColor(p.status) + ';font-weight:bold;">' + enc(p.status) + '</span></td>' +
                    '<td>' + cancelBtn + '</td></tr>';
            });
        }
        html += '</tbody></table>';
        
        // [FIX] Tombol aksi invoice pakai uiBtn (sama dengan subcon.js)
        var btns = '';
        if (inv.status === 'Draft') {
            btns += uiBtn('scInvActBtn', 'Ajukan', TEAL, 'data-act="submit_invoice" data-id="' + inv.id + '"');
            btns += uiBtn('scInvActBtn', 'Edit', GREY, 'data-act="edit_invoice" data-id="' + inv.id + '"');
            btns += uiBtn('scInvActBtn', 'Batalkan', BURG, 'data-act="cancel_invoice" data-id="' + inv.id + '"');
        } else if (inv.status === 'Diajukan') {
            btns += uiBtn('scInvActBtn', 'Setujui', TEAL, 'data-act="approve_invoice" data-id="' + inv.id + '"');
        } else if (inv.status === 'Disetujui' && inv.unpaid_amount > VEPS) {
            btns += uiBtn('scInvActBtn', 'Bayar', GREY, 'data-act="pay_invoice" data-id="' + inv.id + '"');
        }
        
        if (btns) html += '<div style="margin-top:15px;text-align:right;">' + btns + '</div>';
        
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';
        
        // Wire button handlers
        var btns = dtlDiv.querySelectorAll('.scInvActBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () { onAction(this.getAttribute('data-act'), parseInt(this.getAttribute('data-id'), 10)); };
        }
    }
    
    function onAction(act, id) {
        if (act === 'submit_invoice') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
            Ext.Msg.confirm('Ajukan Invoice', 'Ajukan invoice ini untuk disetujui?', function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'update_invoice_status', id: id, to: 'Diajukan' }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { invoiceDs.load(); loadDetailPanel(id); }
                });
            });
        } else if (act === 'approve_invoice') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
            Ext.Msg.confirm('Setujui Invoice', 'Setujui invoice ini? Jurnal akan diposting.', function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'approve_invoice', id: id }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { invoiceDs.load(); loadDetailPanel(id); }
                });
            });
        } else if (act === 'cancel_invoice') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
            Ext.Msg.prompt('Batalkan Invoice', 'Alasan (min. 5 karakter):', function (btn, txt) {
                if (btn !== 'ok') return;
                if (txt.length < 5) { showErr('Alasan minimal 5 karakter.'); return; }
                ajax({ task: 'cancel_invoice', id: id, reason: txt }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { invoiceDs.load(); loadDetailPanel(id); }
                });
            });
        } else if (act === 'edit_invoice') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
            openInvoiceEditor(id);
        } else if (act === 'pay_invoice') {
            if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses.')) return;
            openPaymentEditor(id);
        } else if (act === 'cancel_payment') {
            if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
            Ext.Msg.prompt('Batalkan Pembayaran', 'Alasan (min. 5 karakter):', function (btn, txt) {
                if (btn !== 'ok') return;
                if (txt.length < 5) { showErr('Alasan minimal 5 karakter.'); return; }
                ajax({ task: 'cancel_payment', id: id, reason: txt }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { invoiceDs.load(); loadDetailPanel(dataRecord.id); }
                });
            });
        }
    }
    
    // ============================================================
    // INVOICE EDITOR (Step 1) - [FIX] Judul 14px + tombol uiBtn
    // ============================================================
    function openInvoiceEditor(invoiceId) {
        var fContract = new myComboField({
            fieldLabel: 'Kontrak Subkon', store: contractDs, displayField: 'spk_no', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onContractChange(); } }
        });
        var fProgress = new myComboField({
            fieldLabel: 'Prestasi', store: progressDs, displayField: 'ref_no', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: false, anchor: '95%', value: 0,
            listeners: { select: function (c, rec) { if (rec) fAmount.setValue(rec.get('unpaid_amount')); } }
        });
        var fInvDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Invoice', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        var fDueDate = new Ext.form.DateField({ fieldLabel: 'Jatuh Tempo', format: 'd/m/Y', width: 130 });
        var fAmount = new myNumberField({ fieldLabel: 'Total Invoice', decimalPrecision: 2, allowNegative: false, width: 150 });
        var fRef = new myTextField({ fieldLabel: 'No. Referensi', anchor: '95%' });
        var fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        
        var winTitle = (invoiceId ? 'Edit Invoice' : 'Invoice Baru') + ' - Subkontraktor';
        
        var win = new Ext.Window({
            title: winTitle,
            width: 600, height: 450, modal: true, layout: 'fit',
            // [FIX] headerCfg untuk paksa font-size 14px di title window
            headerCfg: { tag: 'div', cls: 'x-window-header', style: 'font-size:16px;font-weight:bold;' },
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 130, bodyStyle: 'padding:12px;',
                items: [fContract, fProgress, fInvDate, fDueDate, fAmount, fRef, fNotes]
            }),
            // [FIX] bbar pakai uiBtn (sama dengan subcon.js)
            bbar: ['->', { xtype: 'tbtext', text: uiBtn('scInvSave', 'Simpan', TEAL) + uiBtn('scInvCancel', 'Batal', BURG) }]
        });
        
        contractDs.load({ callback: function () {
            progressDs.load({ params: { contract_id: 0 } });
            win.show();
            
            if (invoiceId) {
                ajax({ task: 'load_invoice', id: invoiceId }, function (res) {
                    if (res.success) {
                        var inv = res.data.invoice;
                        fContract.setValue(inv.contract_id);
                        onContractChange(function () {
                            fProgress.setValue(inv.progress_id || 0);
                            fInvDate.setValue(Date.parseDate(inv.invoice_date, 'Y-m-d') || new Date());
                            fDueDate.setValue(inv.due_date ? Date.parseDate(inv.due_date, 'Y-m-d') : null);
                            fAmount.setValue(inv.total_amount);
                            fRef.setValue(inv.reference_no || '');
                            fNotes.setValue(inv.notes || '');
                        });
                    }
                });
            }
            
            setTimeout(function () {
                var btnSave = document.querySelector('.scInvSave');
                var btnCancel = document.querySelector('.scInvCancel');
                if (btnSave) {
                    btnSave.onclick = function () {
                        if (!fContract.getValue()) { showErr('Kontrak wajib dipilih.'); return; }
                        if (!fInvDate.getValue()) { showErr('Tanggal invoice wajib diisi.'); return; }
                        if (!(parseFloat(fAmount.getValue()) > 0)) { showErr('Total invoice harus lebih dari 0.'); return; }
                        
                        ajax({
                            task: 'save_invoice', id: invoiceId || 0,
                            contract_id: fContract.getValue(), progress_id: fProgress.getValue() || 0,
                            invoice_date: ymd(fInvDate.getValue()), due_date: fDueDate.getValue() ? ymd(fDueDate.getValue()) : '',
                            total_amount: fAmount.getValue(), reference_no: fRef.getValue(), notes: fNotes.getValue()
                        }, function (res) {
                            if (res.success) {
                                win.close();
                                Ext.Msg.alert('Sukses', res.message);
                                invoiceDs.load();
                                if (res.data && res.data.id) loadDetailPanel(res.data.id);
                            } else showErr(res.message || 'Gagal.');
                        });
                    };
                }
                if (btnCancel) {
                    btnCancel.onclick = function () { win.close(); };
                }
            }, 100);
        }});
    }
    
    function onContractChange(cb) {
        var cid = fContract ? fContract.getValue() : 0;
        progressDs.load({ params: { contract_id: cid }, callback: function () { if (cb) cb(); } });
    }
    
    // ============================================================
    // PAYMENT EDITOR (Step 2) - [FIX] Judul 14px + tombol uiBtn
    // ============================================================
    function openPaymentEditor(invoiceId) {
        ajax({ task: 'load_invoice', id: invoiceId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat invoice.'); return; }
            var inv = res.data.invoice;
            if (inv.status !== 'Disetujui') { showErr('Hanya invoice Disetujui yang bisa dibayar.'); return; }
            if (inv.unpaid_amount <= 0) { showErr('Invoice sudah lunas.'); return; }
            
            var fPayDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Bayar', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
            var fAccount = new myComboField({ fieldLabel: 'Akun Kas/Bank', store: accountDs, displayField: 'label', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%' });
            var fMethod = new myComboField({ fieldLabel: 'Metode', store: new Ext.data.ArrayStore({ fields: ['v', 't'], data: [['BANK_TRANSFER', 'Transfer Bank'], ['CASH', 'Tunai'], ['CHEQUE', 'Cek']] }), displayField: 't', valueField: 'v', mode: 'local', triggerAction: 'all', forceSelection: true, value: 'BANK_TRANSFER', anchor: '95%' });
            var fAmount = new myNumberField({ fieldLabel: 'Jumlah Bayar', decimalPrecision: 2, allowNegative: false, value: inv.unpaid_amount, width: 150 });
            var fRef = new myTextField({ fieldLabel: 'No. Referensi', anchor: '95%' });
            var fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
            
            var winTitle = 'Pembayaran Invoice ' + inv.invoice_no;
            
            var win = new Ext.Window({
                title: winTitle,
                width: 500, height: 380, modal: true, layout: 'fit',
                // [FIX] headerCfg untuk paksa font-size 14px
                headerCfg: { tag: 'div', cls: 'x-window-header', style: 'font-size:14px;font-weight:bold;' },
                items: new Ext.form.FormPanel({
                    border: false, labelWidth: 130, bodyStyle: 'padding:12px;',
                    items: [{ xtype: 'displayfield', hideLabel: true, value: 'Sisa hutang: <b>' + fmtAmt(inv.unpaid_amount) + '</b>' },
                        fPayDate, fAccount, fMethod, fAmount, fRef, fNotes]
                }),
                // [FIX] bbar pakai uiBtn
                bbar: ['->', { xtype: 'tbtext', text: uiBtn('scPaySave', 'Simpan & Posting', TEAL) + uiBtn('scPayCancel', 'Batal', BURG) }]
            });
            
            accountDs.load({ callback: function () { win.show(); } });
            
            setTimeout(function () {
                var btnSave = document.querySelector('.scPaySave');
                var btnCancel = document.querySelector('.scPayCancel');
                if (btnSave) {
                    btnSave.onclick = function () {
                        if (!fPayDate.getValue() || !fAccount.getValue()) { showErr('Tanggal dan akun wajib diisi.'); return; }
                        if (!(parseFloat(fAmount.getValue()) > 0)) { showErr('Jumlah harus > 0.'); return; }
                        
                        ajax({
                            task: 'save_payment', invoice_id: invoiceId, contract_id: inv.contract_id,
                            payment_date: ymd(fPayDate.getValue()), account_id: fAccount.getValue(),
                            payment_method: fMethod.getValue(), amount: fAmount.getValue(),
                            reference_no: fRef.getValue(), notes: fNotes.getValue()
                        }, function (res) {
                            if (res.success) {
                                win.close();
                                Ext.Msg.alert('Sukses', res.message);
                                invoiceDs.load();
                                loadDetailPanel(invoiceId);
                            } else showErr(res.message || 'Gagal.');
                        });
                    };
                }
                if (btnCancel) {
                    btnCancel.onclick = function () { win.close(); };
                }
            }, 100);
        });
    }
    
    // ============================================================
    // ACTION HANDLERS
    // ============================================================
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki hak akses.')) return;
        openInvoiceEditor(0);
    };
    
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih invoice terlebih dahulu.'); return; }
        if (dataRecord.status !== 'Draft') { showErr('Hanya invoice Draft yang bisa diubah.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
        openInvoiceEditor(dataRecord.id);
    };
    
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Draft') { showErr('Pilih invoice Draft.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses.')) return;
        Ext.Msg.confirm('Hapus Invoice', 'Hapus invoice ' + enc(dataRecord.invoice_no) + '?', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'cancel_invoice', id: dataRecord.id, reason: 'Dihapus oleh user' }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) { dataRecord = null; invoiceDs.load(); dtlDiv.style.display = 'none'; updateMainButtons(); }
            });
        });
    };
    
    document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; if (document.getElementById('popCancel')) document.getElementById('popCancel').style.display = 'none'; };
    document.getElementById('editorWdw').style.display = 'none';
});