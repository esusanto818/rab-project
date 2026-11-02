/*
Document : js/change.pass.js
Description : Change Password Form - ExtJS 3.4
Features : 
  - Password strength meter (real-time)
  - 5 syarat password checklist
  - Show/hide toggle: Default mata dicoret (hidden) -> klik jadi mata terbuka (visible)
  - Validasi client-side
  - Auto logout & redirect ke halaman login setelah sukses ubah password
  - Integrasi backend change.passCRD.php
*/
Ext.onReady(function() {
    Ext.QuickTips.init();
    
    // Ambil UserCurrId dari window (sudah di-set di change.pass.php)[cite: 2]
    var UserCurrId = (window.UserCurrId);
    
    // Set header[cite: 2]
    document.getElementById("mainHeaderText").innerHTML = "Change <em>Password</em>";
    document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Change Password";
    
    // ============================================================
    // FUNGSI CEK KEKUATAN PASSWORD[cite: 2]
    // ============================================================
    function checkPasswordStrength(pwd) {
        if (!pwd) return { score: 0, level: 'none', color: 'transparent', label: '-', checks: {} };
        
        var score = 0;
        var checks = {
            length: pwd.length >= 8,
            upper: /[A-Z]/.test(pwd),
            lower: /[a-z]/.test(pwd),
            number: /[0-9]/.test(pwd),
            special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd)
        };
        
        if (checks.length) score++;
        if (checks.upper) score++;
        if (checks.lower) score++;
        if (checks.number) score++;
        if (checks.special) score++;
        if (pwd.length >= 12) score = Math.min(5, score + 0.5);
        
        var level, color, label;
        if (score <= 1) { level = 'very-weak'; color = '#ef4444'; label = 'Sangat Lemah'; }
        else if (score <= 2) { level = 'weak'; color = '#f97316'; label = 'Lemah'; }
        else if (score <= 3) { level = 'medium'; color = '#eab308'; label = 'Sedang'; }
        else if (score <= 4) { level = 'strong'; color = '#22c55e'; label = 'Kuat'; }
        else { level = 'very-strong'; color = '#10b981'; label = 'Sangat Kuat'; }
        
        return { score: score, level: level, color: color, label: label, checks: checks };
    }
    
    // ============================================================
    // FUNGSI UPDATE UI METER + CHECKLIST[cite: 2]
    // ============================================================
    function updatePasswordIndicator(pwd) {
        var bar = document.getElementById('pwd-strength-bar');
        var label = document.getElementById('pwd-strength-label');
        var checklist = document.getElementById('pwd-checklist');
        
        if (!bar || !label || !checklist) return;
        
        if (!pwd) {
            bar.style.width = '0%';
            bar.style.background = 'transparent';
            label.innerHTML = '<span style="color:#64748b;">Masukkan password baru</span>';
            checklist.style.display = 'none';
            return;
        }
        
        var result = checkPasswordStrength(pwd);
        var pct = (result.score / 5) * 100;
        
        bar.style.width = pct + '%';
        bar.style.background = result.color;
        label.innerHTML = '<span style="color:' + result.color + ';font-weight:bold;">' + result.label + '</span> <span style="color:#64748b;">(' + Math.round(pct) + '%)</span>';
        
        var items = [
            { text: 'Minimal 8 karakter', met: result.checks.length },
            { text: 'Minimal 1 huruf besar (A-Z)', met: result.checks.upper },
            { text: 'Minimal 1 huruf kecil (a-z)', met: result.checks.lower },
            { text: 'Minimal 1 angka (0-9)', met: result.checks.number },
            { text: 'Minimal 1 simbol (!@#$...)', met: result.checks.special }
        ];
        
        var html = '<div style="margin-top:8px;"><div style="font-size:11px;color:#94a3b8;margin-bottom:6px;font-weight:600;">Syarat Password:</div>';
        items.forEach(function(item) {
            var icon = item.met ? '✓' : '✗';
            var color = item.met ? '#10b981' : '#ef4444';
            var weight = item.met ? 'normal' : 'bold';
            html += '<div style="font-size:11px;color:' + color + ';font-weight:' + weight + ';margin-bottom:3px;display:flex;align-items:center;gap:6px;">';
            html += '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + color + ';color:#fff;text-align:center;line-height:14px;font-size:10px;">' + icon + '</span>';
            html += '<span>' + item.text + '</span></div>';
        });
        html += '</div>';
        
        checklist.innerHTML = html;
        checklist.style.display = 'block';
    }
    
    // ============================================================
    // SVG ICONS[cite: 2]
    // ============================================================
    var ICON_EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var ICON_EYE_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
    
    // ============================================================
    // FUNGSI TOGGLE SHOW/HIDE PASSWORD
    // ============================================================
    function togglePasswordVisibility(fieldId, btnElement) {
        var field = Ext.getCmp(fieldId);
        if (!field || !field.el || !field.el.dom) return;
        
        var inputEl = field.el.dom;
        if (inputEl.tagName !== 'INPUT') {
            inputEl = inputEl.querySelector('input') || inputEl;
        }
        
        var isCurrentlyHidden = (inputEl.type === 'password');
        
        if (isCurrentlyHidden) {
            inputEl.type = 'text';
            btnElement.innerHTML = ICON_EYE_OPEN;
            btnElement.title = 'Sembunyikan password';
        } else {
            inputEl.type = 'password';
            btnElement.innerHTML = ICON_EYE_CLOSED;
            btnElement.title = 'Tampilkan password';
        }
        
        if (fieldId === 'newpwd') {
            var confPwd = Ext.getCmp('confpwd');
            if (confPwd && confPwd.getValue()) {
                setTimeout(function() { confPwd.validate(); }, 100);
            }
        }
    }
    
    // ============================================================
    // FUNGSI TAMBAH TOGGLE BUTTON KE FIELD
    // ============================================================
    function addToggleToField(fieldId) {
        setTimeout(function() {
            var field = Ext.getCmp(fieldId);
            if (!field || !field.el || !field.el.dom) return;
            
            var inputEl = field.el.dom;
            if (inputEl.tagName !== 'INPUT') {
                inputEl = inputEl.querySelector('input') || inputEl;
            }
            
            var wrapper = inputEl;
            while (wrapper && wrapper !== document.body && !wrapper.classList.contains('x-form-field-wrap')) {
                wrapper = wrapper.parentNode;
            }
            
            if (!wrapper || wrapper === document.body) {
                wrapper = inputEl.parentNode;
            }
            
            wrapper.style.position = 'relative';
            
            if (document.getElementById('toggle-' + fieldId)) return;
            
            var toggleBtn = document.createElement('div');
            toggleBtn.id = 'toggle-' + fieldId;
            toggleBtn.style.cssText = 'position:absolute; right:130px; top:50%; transform:translateY(-50%); cursor:pointer; padding:4px; z-index:99; display:flex; align-items:center; justify-content:center; background:transparent; border:none;';
            
            toggleBtn.innerHTML = ICON_EYE_CLOSED;
            toggleBtn.title = 'Tampilkan password';
            
            toggleBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                togglePasswordVisibility(fieldId, this);
            };
            
            toggleBtn.onmouseenter = function() {
                this.style.background = 'rgba(34, 211, 238, 0.1)';
                this.style.borderRadius = '4px';
            };
            toggleBtn.onmouseleave = function() {
                this.style.background = 'transparent';
            };
            
            wrapper.appendChild(toggleBtn);
            inputEl.style.paddingRight = '32px';
        }, 400);
    }
    
    // ============================================================
    // FUNGSI ATTACH NATIVE DOM LISTENER[cite: 2]
    // ============================================================
    function attachNativeListener(fieldId) {
        setTimeout(function() {
            var field = Ext.getCmp(fieldId);
            if (!field || !field.el || !field.el.dom) return;
            
            var inputEl = field.el.dom;
            if (inputEl.tagName !== 'INPUT') {
                inputEl = inputEl.querySelector('input') || inputEl;
            }
            
            inputEl.addEventListener('input', function() {
                var pwd = this.value || '';
                updatePasswordIndicator(pwd);
                field.setValue(pwd);
                
                var confPwd = Ext.getCmp('confpwd');
                if (confPwd && confPwd.getValue()) confPwd.validate();
            });
            
            inputEl.addEventListener('keyup', function() {
                var pwd = this.value || '';
                updatePasswordIndicator(pwd);
            });
            
            inputEl.addEventListener('keypress', function() {
                setTimeout(function() {
                    var pwd = field.el.dom.value || '';
                    updatePasswordIndicator(pwd);
                }, 10);
            });
        }, 400);
    }
    
    // ============================================================
    // FIELD DEFINITIONS[cite: 2]
    // ============================================================
    var employeeFields = [
        {name: 'id', type: 'int'},
        {name: 'login_name', type: 'string'},
        {name: 'employee_id', type: 'int'},
        {name: 'name', type: 'string', convert: function(v, rec) { return rec.employee_name; }},
        {name: 'employee_name'},
        {name: 'department_name'},
        {name: 'password'},
        {name: 'confirm_password'}
    ];
    
    // ============================================================
    // EDITOR FORM[cite: 2]
    // ============================================================
    var frmEditor = new stdForm({
        url: '/rab-project/dev/php-crud/change.passCRD.php',
        id: 'frmEditor',
        reader: new Ext.data.JsonReader({root: 'data', fields: employeeFields}),
        renderTo: 'editorFormMain',
        items: [
            {columnWidth: 1, items: RowSpace},
            
            // Baris 1: Name & Department
            {
                columnWidth: 0.5,
                items: new myTextField({
                    fieldLabel: 'Name',
                    id: 'name',
                    name: 'name',
                    readOnly: true
                })
            },
            {
                columnWidth: 0.5,
                items: new myTextField({
                    fieldLabel: 'Department',
                    id: 'department_name',
                    name: 'department_name',
                    readOnly: true
                })
            },
            
            {columnWidth: 1, items: FieldSpaceTopLabel},
            
            // Baris 2: Login Name
            {
                columnWidth: 0.5,
                items: new myTextField({
                    fieldLabel: 'Login Name',
                    id: 'login_name',
                    name: 'login_name',
                    readOnly: true
                })
            },
            {columnWidth: 0.5, items: RowSpace},
            
            {columnWidth: 1, items: FieldSpaceTopLabel},
            
            // Baris 3: Password & Confirm Password
            {
                columnWidth: 0.5,
                layout: 'form',
                baseCls: 'x-plain',
                items: [
                    {
                        xtype: 'textfield',
                        fieldLabel: 'New Password <span style="color:red">*</span>',
                        id: 'newpwd',
                        name: 'password',
                        inputType: 'password',
                        autoCreate: {
                            tag: "input",
                            maxlength: 50,
                            type: "password",
                            size: "50",
                            autocomplete: "off"
                        },
                        minLength: 8,
                        maxLength: 50,
                        minLengthText: 'Password minimal 8 karakter.',
                        allowBlank: false,
                        blankText: 'Password wajib diisi.',
                        listeners: {
                            'render': function(field) {
                                addToggleToField('newpwd');
                                attachNativeListener('newpwd');
                            }
                        }
                    }
                ]
            },
            {
                columnWidth: 0.5,
                layout: 'form',
                baseCls: 'x-plain',
                items: [
                    {
                        xtype: 'textfield',
                        fieldLabel: 'Confirm Password <span style="color:red">*</span>',
                        id: 'confpwd',
                        name: 'confirm_password',
                        inputType: 'password',
                        autoCreate: {
                            tag: "input",
                            maxlength: 50,
                            type: "password",
                            size: "50",
                            autocomplete: "off"
                        },
                        minLength: 8,
                        maxLength: 50,
                        minLengthText: 'Konfirmasi password minimal 8 karakter.',
                        allowBlank: false,
                        blankText: 'Konfirmasi password wajib diisi.',
                        validator: function(value) {
                            var newPwd = Ext.getCmp('newpwd');
                            if (!newPwd) return true;
                            if (value && newPwd.getValue() && value !== newPwd.getValue()) {
                                return 'Password tidak cocok!';
                            }
                            return true;
                        },
                        listeners: {
                            'render': function(field) {
                                addToggleToField('confpwd');
                            }
                        }
                    }
                ]
            },
            
            {columnWidth: 1, items: FieldSpaceTopLabel},
            
            // PASSWORD STRENGTH INDICATOR + CHECKLIST
            {
                columnWidth: 1,
                html: '<div id="pwd-indicator-container" style="margin: 0 0 20px 0; padding: 12px; background: rgba(5, 21, 37, 0.6); border: 1px solid rgba(34, 211, 238, 0.15); border-radius: 8px;">' +
                      '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">' +
                      '<div style="font-size:11px;color:#94a3b8;font-weight:600;min-width:80px;">Kekuatan:</div>' +
                      '<div style="flex:1;height:8px;background:rgba(255,255,255,0.05);border-radius:4px;overflow:hidden;">' +
                      '<div id="pwd-strength-bar" style="height:100%;width:0%;background:transparent;transition:all 0.3s ease;border-radius:4px;"></div>' +
                      '</div>' +
                      '<div id="pwd-strength-label" style="font-size:11px;color:#64748b;min-width:120px;text-align:right;">Masukkan password baru</div>' +
                      '</div>' +
                      '<div id="pwd-checklist" style="display:none;"></div>' +
                      '</div>'
            },
            
            // Hidden fields
            {
                items: new myTextField({
                    name: 'employee_id',
                    id: 'employee_id',
                    hidden: true
                })
            },
            {
                items: new myTextField({
                    name: 'id',
                    id: 'hidden_id',
                    hidden: true
                })
            }
        ]
    });
    
    // Form validation[cite: 2]
    frmEditor.on('clientvalidation', function(f, valid) {
        if (!valid) {
            saveEditDisable();
        } else {
            saveEditEnable();
        }
    });
    
    // Load data user[cite: 2]
    Ext.getCmp('frmEditor').getForm().load({
        url: '/rab-project/dev/php-crud/change.passCRD.php',
        params: {
            id: UserCurrId,
            task: 'read_edit'
        },
        success: function(form, action) {
            var idField = Ext.getCmp('hidden_id');
            if (idField) {
                idField.setValue(UserCurrId);
            }
        },
        failure: function(form, action) {
            Ext.Msg.alert('Error', 'Gagal memuat data user. ID: ' + UserCurrId);
        }
    });
    
    // Save button handler[cite: 2]
    document.getElementById('saveEdit').onclick = function(event) {
        var form = Ext.getCmp('frmEditor').getForm();
        
        if (!form.isValid()) {
            Ext.Msg.alert('Validasi Gagal', 'Mohon periksa kembali input Anda.');
            return;
        }
        
        var newPwdField = Ext.getCmp('newpwd');
        var newPwd = newPwdField ? (newPwdField.getRawValue() || newPwdField.getValue()) : '';
        var confPwd = Ext.getCmp('confpwd').getValue();
        var strength = checkPasswordStrength(newPwd);
        
        if (newPwd.length < 8) {
            Ext.Msg.alert('Password Terlalu Pendek', 'Password minimal 8 karakter.');
            return;
        }
        
        if (strength.score < 3) {
            Ext.Msg.alert('Password Terlalu Lemah', 
                'Password Anda berlevel "' + strength.label + '".<br/><br/>' +
                'Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol.');
            return;
        }
        
        if (newPwd !== confPwd) {
            Ext.Msg.alert('Password Tidak Cocok', 'Password dan Confirm Password harus sama.');
            return;
        }
        
        form.submit({
            method: 'POST',
            params: {
                task: 'update',
                id: UserCurrId
            },
            waitTitle: 'Menyimpan',
            waitMsg: 'Mengubah password...',
            success: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                var messages = objResponse.message || 'Password berhasil diubah.';
                
                Ext.Msg.show({
                    title: 'Sukses',
                    msg: messages + '<br/><br/>Password berhasil diubah. Silakan login kembali dengan password baru.',
                    buttons: Ext.Msg.OK,
                    icon: Ext.Msg.INFO,
                    fn: function() {
                        // Otomatis logout dan arahkan kembali ke halaman login aplikasi
                        window.top.location.href = '/rab-project/dev/views/logout.php';
                    }
                });
            },
            failure: function(form, action) {
                var objResponse = null;
                try {
                    objResponse = Ext.util.JSON.decode(action.response.responseText);
                } catch (e) {
                    objResponse = { message: 'Terjadi kesalahan sistem.' };
                }
                Ext.Msg.alert('Gagal', objResponse.message || 'Gagal mengubah password.');
            }
        });
    };
    
    // Discard button[cite: 2]
    if (document.getElementById('discardEdit')) {
        document.getElementById('discardEdit').onclick = function() {
            window.close();
        };
    }
    
    // Hide main header[cite: 2]
    if (document.getElementById('mainHeader')) {
        document.getElementById('mainHeader').style.display = 'none';
    }
    if (document.getElementById('editorWdw')) {
        document.getElementById('editorWdw').style.display = 'block';
    }
    
    function editWdwClose() {
        window.close();
    }
});