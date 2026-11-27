/*
  Document      : settings.js
  Description   : Pengaturan Sistem. Empat tab:
                   1. Profil Perusahaan : nama, alamat, kontak, NPWP
                   2. Logo              : logo dokumen (kop cetak) & logo aplikasi (upload PNG/JPEG/WEBP, maks 1 MB)
                   3. Keuangan          : mata uang dasar (pembukuan) & mata uang laporan keuangan final,
                                          jenis kurs default, dasar kurs laba rugi, awal tahun fiskal, desimal
                   4. Dokumen           : footer & penandatangan + pratinjau kop dokumen
                  Transaksi (RAB, PO, dst) boleh multi currency; laporan keuangan final mengacu ke tab Keuangan.
  Backend       : /rab-project/dev/php-crud/settingsCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Pengaturan <em>Sistem</em>";

    var CRD = '../php-crud/settingsCRD.php';
    var ACCESS_WRITE = 3;                       // harus sama dengan SET_ACCESS_WRITE di settingsCRD.php

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    var canWrite = getFormAccess() >= ACCESS_WRITE;

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }

    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
        });
    }

    var settingsState = { company: {}, finance: null, lockReason: null };

    // Gaya ringan agar label & teks bantuan terbaca di tema gelap
    if (!document.getElementById('settingsStyle')) {
        var st = document.createElement('style');
        st.id = 'settingsStyle';
        st.innerHTML =
            '#divMainGrid .x-form-item label { color: #9fc3d1 !important; }' +
            '#divMainGrid textarea.x-form-textarea { background: #041426 !important; color: #cbd5e1 !important; border: 1px solid #1a3a52 !important; border-radius: 4px; padding: 6px 8px !important; line-height: 1.5; font-family: inherit; resize: vertical; }' +
            '#divMainGrid textarea.x-form-textarea.x-form-focus { border-color: #38bdf8 !important; }' +
            '.st-nav { position: relative; display: flex; flex-wrap: wrap; gap: 6px; padding: 0 4px; border-bottom: 1px solid #1a3a52; margin-bottom: 14px; }' +
            '.st-tab { display: flex; align-items: center; gap: 10px; background: transparent; border: none; color: #7f9fb0; cursor: pointer; padding: 10px 20px 12px 14px; border-radius: 8px 8px 0 0; transition: color .2s, background .2s; font-family: inherit; text-align: left; }' +
            '.st-tab:hover { background: rgba(56,189,248,.06); color: #bfe3f5; }' +
            '.st-tab.on { color: #38bdf8; background: linear-gradient(180deg, rgba(56,189,248,.12), rgba(56,189,248,0)); }' +
            '.st-ico svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; display: block; }' +
            '.st-txt b { display: block; font-size: 13px; font-weight: 600; letter-spacing: .2px; }' +
            '.st-txt small { display: block; font-size: 10.5px; opacity: .75; margin-top: 1px; }' +
            '.st-bar { position: absolute; left: 0; bottom: -1px; height: 3px; width: 0; border-radius: 3px 3px 0 0; background: linear-gradient(90deg, #0ea5e9, #38bdf8, #7dd3fc); box-shadow: 0 0 10px rgba(56,189,248,.55); transition: transform .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1); }' +
            '@keyframes stIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }' +
            '.st-in { animation: stIn .22s ease-out; }' +
            '.set-note { margin: 4px 0 14px 0; padding: 10px 14px; border-radius: 4px; background: #0b2236; border: 1px solid #1a3a52; color: #9fc3d1; font-size: 12px; line-height: 1.6; }' +
            '.set-note b { color: #38bdf8; }' +
            '.set-warn { background: #3b2f0b; border-color: #8a6d1c; color: #f5c542; }' +
            '.logo-card { display: inline-block; vertical-align: top; width: 420px; margin: 0 20px 20px 0; padding: 14px; border: 1px solid #1a3a52; border-radius: 6px; background: #0b2236; }' +
            '.logo-card h4 { margin: 0 0 4px 0; color: #38bdf8; font-size: 14px; }' +
            '.logo-card .hint { color: #7f9fb0; font-size: 11px; margin-bottom: 10px; line-height: 1.5; }' +
            '.logo-box { height: 130px; display: flex; align-items: center; justify-content: center; background: #ffffff; border-radius: 4px; margin-bottom: 10px; overflow: hidden; }' +
            '.logo-box img { max-width: 100%; max-height: 120px; }' +
            '.logo-box .none { color: #888; font-size: 12px; }' +
            '.logo-card button { padding: 6px 14px; margin-right: 6px; border: none; border-radius: 3px; cursor: pointer; color: #fff; font-weight: bold; }' +
            '.logo-card .btn-up { background: #03615e; } .logo-card .btn-del { background: #8e2b22; }' +
            '.logo-card button[disabled] { opacity: .45; cursor: not-allowed; }' +
            '.doc-preview { background: #ffffff; color: #222; padding: 18px 22px; border-radius: 4px; max-width: 760px; font-family: Arial, sans-serif; }' +
            '.doc-preview .head { display: flex; align-items: center; border-bottom: 2px solid #222; padding-bottom: 10px; }' +
            '.doc-preview .head img { max-height: 70px; max-width: 160px; margin-right: 16px; }' +
            '.doc-preview .co { font-size: 17px; font-weight: bold; } .doc-preview .ad { font-size: 11px; line-height: 1.5; color: #444; }' +
            '.doc-preview .body { padding: 26px 0; color: #999; font-size: 12px; text-align: center; }' +
            '.doc-preview .sign { text-align: right; font-size: 12px; margin-top: 10px; } .doc-preview .foot { border-top: 1px solid #bbb; margin-top: 16px; padding-top: 6px; font-size: 10px; color: #666; }';
        document.head.appendChild(st);
    }

    // ------------------------------------------------------------
    // Stores lokal untuk combo
    // ------------------------------------------------------------
    var currencyDs = new Ext.data.Store({
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'init' }, { name: 'name' },
            { name: 'label', convert: function (v, r) { return r.init + ' - ' + r.name; } }
        ])
    });
    var rateTypeDs = new Ext.data.ArrayStore({
        fields: ['v', 't'],
        data: [['KMK', 'KMK - Kurs pajak (Kemenkeu)'], ['BI', 'BI - Kurs Bank Indonesia'], ['MANUAL', 'Manual']]
    });
    var basisDs = new Ext.data.ArrayStore({
        fields: ['v', 't'],
        data: [['TRANSACTION', 'Kurs saat transaksi'], ['AVERAGE', 'Kurs rata-rata periode'], ['PERIOD_END', 'Kurs akhir periode']]
    });
    var monthDs = new Ext.data.ArrayStore({
        fields: ['v', 't'],
        data: [[1, 'Januari'], [2, 'Februari'], [3, 'Maret'], [4, 'April'], [5, 'Mei'], [6, 'Juni'],
               [7, 'Juli'], [8, 'Agustus'], [9, 'September'], [10, 'Oktober'], [11, 'November'], [12, 'Desember']]
    });
    var decDs = new Ext.data.ArrayStore({ fields: ['v', 't'], data: [[0, '0'], [1, '1'], [2, '2'], [3, '3'], [4, '4']] });

    function combo(cfg, store, display, value) {
        return new myComboField(Ext.apply({
            store: store, displayField: display, valueField: value,
            mode: 'local', triggerAction: 'all', forceSelection: true, editable: false, anchor: '60%'
        }, cfg));
    }

    // ------------------------------------------------------------
    // Tab 1: Profil Perusahaan
    // ------------------------------------------------------------
    var fCompany  = new myTextField({ fieldLabel: 'Nama Perusahaan *', maxLength: 150, allowBlank: false });
    var fLegal    = new myTextField({ fieldLabel: 'Nama Legal (PT/CV)', maxLength: 200 });
    var fAddress  = new Ext.form.TextArea({ fieldLabel: 'Alamat', maxLength: 1000, height: 90, grow: false, emptyText: 'Alamat lengkap (boleh lebih dari satu baris)' });
    var fCity     = new myTextField({ fieldLabel: 'Kota', maxLength: 100, anchor: '40%' });
    var fProvince = new myTextField({ fieldLabel: 'Provinsi', maxLength: 100, anchor: '40%' });
    var fPostal   = new myTextField({ fieldLabel: 'Kode Pos', maxLength: 20, anchor: '20%' });
    var fCountry  = new myTextField({ fieldLabel: 'Negara', maxLength: 100, anchor: '40%' });
    var fPhone    = new myTextField({ fieldLabel: 'Telepon', maxLength: 50, anchor: '40%' });
    var fEmail    = new myTextField({ fieldLabel: 'Email', maxLength: 150, vtype: 'email', anchor: '50%' });
    var fWebsite  = new myTextField({ fieldLabel: 'Website', maxLength: 150, anchor: '50%' });
    var fTax      = new myTextField({ fieldLabel: 'NPWP', maxLength: 50, anchor: '40%' });

    var companyForm = new Ext.form.FormPanel({
        labelWidth: 150, border: false, autoScroll: true,
        bodyStyle: 'background:transparent; padding:16px;',
        defaults: { anchor: '60%' },
        items: [
            { xtype: 'box', html: '<div class="set-note">Data ini dipakai sebagai <b>kop dokumen</b> (PO, invoice, laporan) dan header laporan keuangan.</div>' },
            fCompany, fLegal, fAddress, fCity, fProvince, fPostal, fCountry, fPhone, fEmail, fWebsite, fTax
        ],
        buttons: [{
            text: 'Simpan Profil', disabled: !canWrite,
            handler: function () {
                if (!companyForm.getForm().isValid()) { Ext.Msg.alert('Peringatan', 'Periksa isian yang berwarna merah.'); return; }
                ajax({
                    task: 'save_company',
                    company_name: fCompany.getValue(), legal_name: fLegal.getValue(), address: fAddress.getValue(),
                    city: fCity.getValue(), province: fProvince.getValue(), postal_code: fPostal.getValue(),
                    country: fCountry.getValue(), phone: fPhone.getValue(), email: fEmail.getValue(),
                    website: fWebsite.getValue(), tax_id: fTax.getValue()
                }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { reloadSettings(); }
                });
            }
        }]
    });

    // ------------------------------------------------------------
    // Tab 2: Logo (upload native + fetch; pratinjau dari endpoint logo)
    // ------------------------------------------------------------
    function logoCard(type, title, hint) {
        return '<div class="logo-card">' +
               '<h4>' + title + '</h4><div class="hint">' + hint + '</div>' +
               '<div class="logo-box"><img id="logoImg_' + type + '" style="display:none" alt="" />' +
               '<span class="none" id="logoNone_' + type + '">Belum ada logo</span></div>' +
               '<input type="file" id="logoFile_' + type + '" accept="image/png,image/jpeg,image/webp" ' + (canWrite ? '' : 'disabled') + ' style="color:#9fc3d1;margin-bottom:10px;" /><br />' +
               '<button type="button" class="btn-up" id="logoUp_' + type + '"' + (canWrite ? '' : ' disabled') + '>Unggah</button>' +
               '<button type="button" class="btn-del" id="logoDel_' + type + '"' + (canWrite ? '' : ' disabled') + '>Hapus</button>' +
               '</div>';
    }

    function showLogo(type) {
        var img = document.getElementById('logoImg_' + type), none = document.getElementById('logoNone_' + type);
        if (!img) return;
        var has = !!settingsState.company['has_logo_' + (type === 'document' ? 'document' : 'app')];
        if (!has) { img.style.display = 'none'; none.style.display = ''; return; }
        var v = settingsState.company['logo_' + (type === 'document' ? 'document' : 'app') + '_v'] || 0;
        img.onload = function () { img.style.display = ''; none.style.display = 'none'; };
        img.onerror = function () { img.style.display = 'none'; none.style.display = ''; };
        img.src = CRD + '?task=logo&type=' + type + '&v=' + v + '&_=' + new Date().getTime();
    }

    function wireLogo(type) {
        var file = document.getElementById('logoFile_' + type);
        document.getElementById('logoUp_' + type).onclick = function () {
            if (!file.files.length) { Ext.Msg.alert('Peringatan', 'Pilih file logo terlebih dahulu.'); return; }
            var f = file.files[0];
            if (['image/png', 'image/jpeg', 'image/webp'].indexOf(f.type) < 0) { Ext.Msg.alert('Peringatan', 'Format logo harus PNG, JPEG, atau WEBP.'); return; }
            if (f.size > 1048576) { Ext.Msg.alert('Peringatan', 'Ukuran logo maksimal 1 MB.'); return; }
            var fd = new FormData();
            fd.append('type', type);
            fd.append('file', f);
            fetch(CRD + '?task=upload_logo', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    var res;
                    try { res = Ext.util.JSON.decode(t); } catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) { file.value = ''; reloadSettings(); }
                })
                .catch(function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); });
        };
        document.getElementById('logoDel_' + type).onclick = function () {
            Ext.Msg.confirm('Hapus Logo', 'Hapus logo ini?', function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_logo', type: type }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) reloadSettings();
                });
            });
        };
    }

    var logoPanel = new Ext.Panel({
        border: false, autoScroll: true,
        bodyStyle: 'background:transparent; padding:16px;',
        html: '<div class="set-note">Format <b>PNG / JPEG / WEBP</b>, maksimal <b>1 MB</b> dan 4000 x 4000 piksel. PNG berlatar transparan paling cocok. SVG tidak didukung.</div>' +
              logoCard('document', 'Logo Dokumen', 'Tampil di kop PO, invoice, dan laporan cetak. Disarankan landscape / persegi, lebar minimal 400 px.') +
              logoCard('app', 'Logo Aplikasi', 'Dipakai di header aplikasi / halaman login. Disarankan persegi.'),
        listeners: {
            afterrender: function () { wireLogo('document'); wireLogo('app'); showLogo('document'); showLogo('app'); }
        }
    });

    // ------------------------------------------------------------
    // Tab 3: Keuangan
    // ------------------------------------------------------------
    var fBase     = combo({ fieldLabel: 'Mata Uang Dasar (Pembukuan) *', allowBlank: false }, currencyDs, 'label', 'id');
    var fReport   = combo({ fieldLabel: 'Mata Uang Laporan Keuangan *', allowBlank: false }, currencyDs, 'label', 'id');
    var fRateType = combo({ fieldLabel: 'Jenis Kurs Default' }, rateTypeDs, 't', 'v');
    var fBasisPl  = combo({ fieldLabel: 'Kurs Laporan Laba Rugi' }, basisDs, 't', 'v');
    var fMonth    = combo({ fieldLabel: 'Awal Tahun Fiskal', anchor: '30%' }, monthDs, 't', 'v');
    var fDecimals = combo({ fieldLabel: 'Desimal Nilai Uang', anchor: '15%' }, decDs, 't', 'v');
    var fLockInfo = new Ext.form.DisplayField({ hideLabel: true, value: '' });

    var financeForm = new Ext.form.FormPanel({
        labelWidth: 230, border: false, autoScroll: true,
        bodyStyle: 'background:transparent; padding:16px;',
        defaults: { anchor: '60%' },
        items: [
            { xtype: 'box', html: '<div class="set-note">Transaksi (RAB, PO, penerimaan, dst) <b>boleh memakai banyak mata uang</b>. ' +
                'Semua nilai dikonversi ke <b>mata uang dasar</b> untuk pembukuan (jurnal, stok FIFO), dan ' +
                '<b>laporan keuangan final mengacu ke pengaturan ini</b>: ditampilkan dalam <b>mata uang laporan</b> memakai jenis dan dasar kurs di bawah. ' +
                'Bila mata uang laporan sama dengan mata uang dasar, tidak ada penjabaran kurs.</div>' },
            fBase, fLockInfo, fReport, fRateType, fBasisPl,
            { xtype: 'box', html: '<div class="set-note" style="margin-top:2px;">Neraca selalu memakai <b>kurs akhir periode</b>. ' +
                'Pilihan kurs untuk pembukuan (KMK atau BI) sebaiknya dipastikan dengan akuntan / konsultan pajak. ' +
                'Kurs otomatis di PO saat ini bersumber dari kurs pajak KMK dan mengasumsikan mata uang dasar IDR.</div>' },
            fMonth, fDecimals
        ],
        buttons: [{
            text: 'Simpan Pengaturan Keuangan', disabled: !canWrite,
            handler: function () {
                if (!financeForm.getForm().isValid()) { Ext.Msg.alert('Peringatan', 'Mata uang dasar dan mata uang laporan wajib dipilih.'); return; }
                ajax({
                    task: 'save_finance',
                    base_currency_id: fBase.getValue(), report_currency_id: fReport.getValue(),
                    default_rate_type: fRateType.getValue(), rate_basis_pl: fBasisPl.getValue(),
                    fiscal_year_start_month: fMonth.getValue(), amount_decimals: fDecimals.getValue()
                }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) reloadSettings();
                });
            }
        }]
    });

    // ------------------------------------------------------------
    // Tab 4: Dokumen (footer, penandatangan, pratinjau kop)
    // ------------------------------------------------------------
    var fFooter    = new Ext.form.TextArea({ fieldLabel: 'Footer Dokumen', maxLength: 1000, height: 90, grow: false, emptyText: 'Catatan kaki dokumen, mis. syarat pembayaran atau info rekening' });
    var fSignName  = new myTextField({ fieldLabel: 'Nama Penandatangan', maxLength: 150, anchor: '40%' });
    var fSignTitle = new myTextField({ fieldLabel: 'Jabatan Penandatangan', maxLength: 100, anchor: '40%' });
    var previewBox = new Ext.BoxComponent({ html: '' });

    function nl2br(v) { return enc(v).replace(/\r?\n/g, '<br />'); }

    function updateDocPreview() {
        var c = settingsState.company || {};
        var name = fCompany.getValue() || c.company_name || '(Nama perusahaan belum diisi)';
        var addr = [fAddress.getValue(), [fCity.getValue(), fProvince.getValue(), fPostal.getValue()].filter(Boolean).join(', '), fCountry.getValue()]
                       .filter(Boolean).map(nl2br).join('<br />');
        var contact = [fPhone.getValue() ? 'Telp: ' + enc(fPhone.getValue()) : '', fEmail.getValue() ? 'Email: ' + enc(fEmail.getValue()) : '',
                       fWebsite.getValue() ? enc(fWebsite.getValue()) : '', fTax.getValue() ? 'NPWP: ' + enc(fTax.getValue()) : '']
                       .filter(Boolean).join(' &nbsp;|&nbsp; ');
        var logo = c.has_logo_document
            ? '<img src="' + CRD + '?task=logo&type=document&v=' + (c.logo_document_v || 0) + '" alt="" />' : '';
        var html =
            '<div class="doc-preview">' +
            '<div class="head">' + logo + '<div><div class="co">' + enc(name) + '</div><div class="ad">' + addr +
            (contact ? '<br />' + contact : '') + '</div></div></div>' +
            '<div class="body">( isi dokumen )</div>' +
            ((fSignName.getValue() || fSignTitle.getValue())
                ? '<div class="sign">' + enc(fSignTitle.getValue()) + '<br /><br /><br /><b>' + enc(fSignName.getValue()) + '</b></div>' : '') +
            (fFooter.getValue() ? '<div class="foot">' + nl2br(fFooter.getValue()) + '</div>' : '') +
            '</div>';
        if (previewBox.rendered) previewBox.getEl().update(html); else previewBox.html = html;
    }

    var docForm = new Ext.form.FormPanel({
        labelWidth: 170, border: false, autoScroll: true,
        bodyStyle: 'background:transparent; padding:16px;',
        defaults: { anchor: '60%' },
        items: [
            { xtype: 'box', html: '<div class="set-note">Pelengkap dokumen cetak. Pratinjau kop di bawah memakai profil perusahaan dan logo dokumen.</div>' },
            fFooter, fSignName, fSignTitle,
            { xtype: 'box', html: '<div style="margin:14px 0 6px 0;color:#38bdf8;font-weight:bold;">Pratinjau Kop Dokumen</div>' },
            previewBox
        ],
        buttons: [{
            text: 'Simpan Pengaturan Dokumen', disabled: !canWrite,
            handler: function () {
                ajax({
                    task: 'save_document',
                    doc_footer: fFooter.getValue(), doc_signer_name: fSignName.getValue(), doc_signer_title: fSignTitle.getValue()
                }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) reloadSettings();
                });
            }
        }],
        listeners: { activate: updateDocPreview }
    });

    // ------------------------------------------------------------
    // TabPanel utama
    // ------------------------------------------------------------
    var host = document.getElementById('divMainGrid');
    var totalH = maxGridHeight(host);

    var TAB_DEFS = [
        { title: 'Profil Perusahaan', sub: 'Nama, alamat, kontak',
          icon: '<svg viewBox="0 0 24 24"><path d="M3 21V5l9-3 9 3v16"/><path d="M9 21v-5h6v5"/><path d="M8 9h2M14 9h2M8 13h2M14 13h2"/></svg>' },
        { title: 'Logo', sub: 'Dokumen & aplikasi',
          icon: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/></svg>' },
        { title: 'Keuangan & Mata Uang', sub: 'Mata uang dasar & laporan',
          icon: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M14.5 9.2c-.4-.8-1.4-1.2-2.5-1.2-1.4 0-2.5.7-2.5 1.8 0 2.6 5 1.2 5 3.8 0 1.1-1.1 1.8-2.5 1.8-1.2 0-2.2-.5-2.6-1.4"/><path d="M12 6v2M12 16v2"/></svg>' },
        { title: 'Dokumen', sub: 'Footer & penandatangan',
          icon: '<svg viewBox="0 0 24 24"><path d="M6 2h8l4 4v16H6z"/><path d="M14 2v4h4M9 12h6M9 16h6"/></svg>' }
    ];

    var navDiv = document.createElement('div');
    navDiv.className = 'st-nav';
    navDiv.innerHTML = '<div class="st-bar" id="stBar"></div>' + TAB_DEFS.map(function (t, i) {
        return '<button type="button" class="st-tab" data-i="' + i + '"><span class="st-ico">' + t.icon + '</span>' +
               '<span class="st-txt"><b>' + t.title + '</b><small>' + t.sub + '</small></span></button>';
    }).join('');
    var bodyDiv = document.createElement('div');
    host.appendChild(navDiv);
    host.appendChild(bodyDiv);

    var cardPanel = new Ext.Panel({
        renderTo: bodyDiv,
        border: false,
        layout: 'card',
        activeItem: 0,
        layoutConfig: { deferredRender: false, layoutOnCardChange: true },
        bodyStyle: 'background:transparent;',
        height: Math.max(320, totalH - 78),
        items: [companyForm, logoPanel, financeForm, docForm]
    });

    var currentTab = 0;
    function setTab(i) {
        currentTab = i;
        cardPanel.getLayout().setActiveItem(i);
        var btns = navDiv.querySelectorAll('.st-tab');
        for (var k = 0; k < btns.length; k++) {
            if (k === i) btns[k].classList.add('on'); else btns[k].classList.remove('on');
        }
        var bar = document.getElementById('stBar'), b = btns[i];
        bar.style.width = b.offsetWidth + 'px';
        bar.style.transform = 'translateX(' + b.offsetLeft + 'px)';
        var el = cardPanel.items.itemAt(i).getEl();
        el.removeClass('st-in');
        void el.dom.offsetWidth;                 // restart animasi
        el.addClass('st-in');
    }
    Ext.each(navDiv.querySelectorAll('.st-tab'), function (btn) {
        btn.onclick = function () { setTab(parseInt(this.getAttribute('data-i'), 10)); };
    });
    window.addEventListener('resize', function () { setTab(currentTab); });
    setTimeout(function () { setTab(0); }, 0);

    // ------------------------------------------------------------
    // Muat & terapkan pengaturan
    // ------------------------------------------------------------
    function applySettings(d) {
        var c = d.company || {}, f = d.finance;
        settingsState.company = c;
        settingsState.finance = f;
        settingsState.lockReason = d.base_lock_reason || null;

        fCompany.setValue(c.company_name || '');
        fLegal.setValue(c.legal_name || '');
        fAddress.setValue(c.address || '');
        fCity.setValue(c.city || '');
        fProvince.setValue(c.province || '');
        fPostal.setValue(c.postal_code || '');
        fCountry.setValue(c.country || '');
        fPhone.setValue(c.phone || '');
        fEmail.setValue(c.email || '');
        fWebsite.setValue(c.website || '');
        fTax.setValue(c.tax_id || '');
        fFooter.setValue(c.doc_footer || '');
        fSignName.setValue(c.doc_signer_name || '');
        fSignTitle.setValue(c.doc_signer_title || '');

        currencyDs.loadData({ data: d.currencies || [] });
        if (f) {
            fBase.setValue(f.base_currency_id);
            fReport.setValue(f.report_currency_id);
            fRateType.setValue(f.default_rate_type);
            fBasisPl.setValue(f.rate_basis_pl);
            fMonth.setValue(f.fiscal_year_start_month);
            fDecimals.setValue(f.amount_decimals);
        } else {
            fBase.clearValue();
            fReport.clearValue();
            fRateType.setValue('KMK');
            fBasisPl.setValue('AVERAGE');
            fMonth.setValue(1);
            fDecimals.setValue(2);
        }

        var locked = !!settingsState.lockReason;
        fBase.setDisabled(locked || !canWrite);
        fLockInfo.setValue(locked
            ? '<div class="set-note set-warn">&#128274; Mata uang dasar terkunci. ' + enc(settingsState.lockReason) +
              ' Mata uang laporan keuangan tetap dapat diubah kapan saja.</div>'
            : (f ? '' : '<div class="set-note set-warn">Pengaturan keuangan belum diatur. Pilih mata uang dasar (umumnya IDR) lalu simpan.</div>'));

        if (!canWrite) {
            [fRateType, fReport, fBasisPl, fMonth, fDecimals].forEach(function (x) { x.setDisabled(true); });
            [fCompany, fLegal, fAddress, fCity, fProvince, fPostal, fCountry, fPhone, fEmail, fWebsite, fTax,
             fFooter, fSignName, fSignTitle].forEach(function (x) { x.setReadOnly(true); });
        }

        showLogo('document');
        showLogo('app');
        updateDocPreview();
    }

    function reloadSettings() {
        ajax({ task: 'load_settings' }, function (res) {
            if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Gagal memuat pengaturan.'); return; }
            applySettings(res.data);
        });
    }

    mainBtnDisable('#addData');
    mainBtnDisable('#editData');
    mainBtnDisable('#deleteData');
    document.getElementById('editorWdw').style.display = 'none';

    reloadSettings();
});