/*
  Document      : project.progress.m.js
  Description   : Lapor Progress untuk HP (Android/iPhone). TANPA ExtJS (vanilla JS + PWA).
                  Memakai ULANG backend modul desktop (project.progressCRD.php): lapangan mengajukan KLAIM
                  (persen + bukti) -> office memverifikasi di desktop (akses level 3) -> baru projects.progress berubah.
                  - Foto bukti dikompres di HP lalu diunggah (task upload_evidence), URL-nya masuk evidence_ref
                  - OFFLINE: klaim + foto disimpan di IndexedDB, dikirim otomatis saat online / aplikasi dibuka
                  - Aturan server (tetap berlaku): persen > progress terverifikasi, bukti wajib, hanya 1 klaim menunggu per proyek,
                    proyek berstatus Berjalan / Tertunda
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/project.progressCRD.php
=======
  Backend       : /rab-project/live/php-crud/project.progressCRD.php
>>>>>>> Stashed changes
*/
(function () {
    'use strict';

<<<<<<< Updated upstream
    var CRD = '/rab-project/dev/php-crud/project.progressCRD.php';   // [PENTING] samakan dengan nama file backend di server
=======
    var CRD = '/rab-project/live/php-crud/project.progressCRD.php';   // [PENTING] samakan dengan nama file backend di server
>>>>>>> Stashed changes
    var ACCESS_REPORT = 2;                                  // samakan dengan PROG_ACCESS_REPORT di backend
    var MAX_PHOTOS = 5, MAX_SIDE = 1280, JPEG_Q = 0.75;
    var LS_PROJECTS = 'prg_claim_projects_cache';

    var projects = [];         // daftar proyek terakhir dari server / cache
    var cur = null;            // proyek terpilih
    var photos = [];           // [{blob, url}] foto laporan yang sedang diisi
    var geo = null;            // {lat, lng}
    var syncing = false;

    function $(id) { return document.getElementById(id); }
    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
    function escA(v) { return esc(v).replace(/"/g, '&quot;'); }
    function todayStr() { var d = new Date(), m = d.getMonth() + 1, dd = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd); }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
    }
    function showMsg(t, type) {
        var m = $('msg'); m.textContent = t; m.className = 'msg ' + (type || '');
        if (type === 'ok') setTimeout(function () { m.className = 'msg hidden'; }, 4000);
    }
    function hideMsg() { $('msg').className = 'msg hidden'; }
    function canReportStatus(p) { return p && (p.status === 'Berjalan' || p.status === 'Tertunda'); }

    // ------------------------------------------------------------
    // 0. Jaringan
    // ------------------------------------------------------------
    // GET (params) / POST (formData). Error jaringan -> reject {offline:true}; 401 -> reject {auth:true}
    function api(params, formData) {
        var opt = { credentials: 'same-origin' }, url = CRD;
        if (formData) { opt.method = 'POST'; opt.body = formData; }
        else { url += '?' + Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&'); }
        return fetch(url, opt).then(function (r) {
            if (r.status === 401) return Promise.reject({ auth: true });
            return r.json().catch(function () { return Promise.reject({ message: 'Respons server tidak valid.' }); });
        }, function () { return Promise.reject({ offline: true }); });
    }
    function post(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return api(null, fd);
    }
    function updateNet() {
        var p = $('pillNet'); p.textContent = navigator.onLine ? 'online' : 'offline'; p.className = 'pill' + (navigator.onLine ? '' : ' off');
    }
    function netErr(e) {
        if (e && e.auth) return 'Sesi berakhir. Buka ulang halaman & login.';
        if (e && e.offline) return 'Tidak ada koneksi.';
        return (e && e.message) || 'Gagal.';
    }

    // ------------------------------------------------------------
    // 1. IndexedDB (antrean klaim offline)
    // ------------------------------------------------------------
    var dbp = null;
    function openDb() {
        if (dbp) return dbp;
        dbp = new Promise(function (res, rej) {
            var rq = indexedDB.open('prgClaimQueue', 1);
            rq.onupgradeneeded = function () { rq.result.createObjectStore('queue', { keyPath: 'uuid' }); };
            rq.onsuccess = function () { res(rq.result); };
            rq.onerror = function () { rej(rq.error); };
        });
        return dbp;
    }
    function tx(mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (res, rej) {
                var t = db.transaction('queue', mode), s = t.objectStore('queue'), out = fn(s);
                t.oncomplete = function () { res(out && out.result !== undefined ? out.result : undefined); };
                t.onerror = function () { rej(t.error); };
            });
        });
    }
    function qPut(item) { return tx('readwrite', function (s) { return s.put(item); }); }
    function qDel(id) { return tx('readwrite', function (s) { return s.delete(id); }); }
    function qAll() { return tx('readonly', function (s) { return s.getAll(); }).then(function (r) { return r || []; }); }

    // ------------------------------------------------------------
    // 2. Daftar proyek (cache localStorage agar tetap tampil saat offline)
    // ------------------------------------------------------------
    function normalize(list) {
        return (list || []).map(function (p) {
            return { id: p.id, name: p.name, status: p.status, progress: parseInt(p.progress, 10) || 0, pending_count: parseInt(p.pending_count, 10) || 0 };
        });
    }
    function refreshProjects() {
        return api({ task: 'list_projects' }).then(function (res) {
            if (!res.success) { showMsg(res.message || 'Gagal memuat proyek.', 'err'); return; }
            projects = normalize(res.data);
            try { localStorage.setItem(LS_PROJECTS, JSON.stringify(projects)); } catch (e) {}
            renderProjects();
            if (cur) { for (var i = 0; i < projects.length; i++) if (projects[i].id === cur.id) cur = projects[i]; applyProjectState(); }
        }, function (e) {
            if (e.auth) showMsg(netErr(e), 'err');
        });
    }
    function loadProjects() {
        var cached = null;
        try { cached = JSON.parse(localStorage.getItem(LS_PROJECTS) || 'null'); } catch (e) {}
        if (cached) { projects = cached; renderProjects(); }
        refreshProjects().then(function () {
            if (!projects.length) showMsg('Daftar proyek belum tersedia. Buka sekali saat online.', 'err');
        });
    }
    function renderProjects() {
        var h = '';
        projects.forEach(function (p) {
            h += '<div class="card prj" data-id="' + p.id + '"><div class="nm">' + esc(p.name) +
                 (p.pending_count > 0 ? '<span class="tag">' + p.pending_count + ' menunggu verifikasi</span>' : '') + '</div>' +
                 '<div class="sub">' + esc(p.status || '') + ' &middot; terverifikasi ' + p.progress + '%</div>' +
                 '<div class="bar"><i style="width:' + p.progress + '%"></i></div></div>';
        });
        $('prjList').innerHTML = h || '<div class="card">Belum ada proyek.</div>';
        var cards = $('prjList').querySelectorAll('.prj');
        for (var i = 0; i < cards.length; i++) {
            cards[i].onclick = (function (el) {
                return function () {
                    var id = parseInt(el.getAttribute('data-id'), 10);
                    for (var k = 0; k < projects.length; k++) if (projects[k].id === id) openProject(projects[k]);
                };
            })(cards[i]);
        }
    }

    // ------------------------------------------------------------
    // 3. Form klaim
    // ------------------------------------------------------------
    function openProject(p) {
        cur = p; hideMsg();
        $('vList').className = 'hidden'; $('vForm').className = '';
        $('btnBack').className = 'sec'; $('ttl').textContent = p.name;
        $('fDate').value = todayStr(); $('fNotes').value = ''; $('fEvid').value = '';
        clearPhotos(); geo = null; $('btnGps').textContent = 'Lokasi: -';
        applyProjectState();
        renderQueueBox(); loadHistory();
        if (navigator.onLine) refreshProjects();          // ambil progress terverifikasi & status klaim terbaru
    }
    function backToList() {
        cur = null; $('vForm').className = 'hidden'; $('vList').className = '';
        $('btnBack').className = 'sec hidden'; $('ttl').textContent = 'Lapor Progress'; hideMsg();
        renderProjects();
    }
    // Atur form sesuai kondisi proyek (status, progress terverifikasi, klaim menunggu)
    function applyProjectState() {
        if (!cur) return;
        var info = 'Progress terverifikasi: <b>' + cur.progress + '%</b>. Klaim harus lebih besar dan akan diverifikasi office sebelum berlaku.';
        var blocked = '';
        if (window.formAccess < ACCESS_REPORT) blocked = 'Anda tidak memiliki akses untuk melapor progress.';
        else if (!canReportStatus(cur)) blocked = 'Proyek berstatus ' + esc(cur.status) + ': progress tidak dapat dilaporkan.';
        else if (cur.progress >= 100) blocked = 'Progress sudah 100%.';
        else if (cur.pending_count > 0) blocked = 'Masih ada klaim yang menunggu verifikasi. Tunggu office memverifikasi atau batalkan klaim tersebut.';
        $('projInfo').innerHTML = info + (blocked ? '<br><span style="color:#e6a817;">' + blocked + '</span>' : '');
        $('frmCard').className = (blocked && cur.pending_count === 0) ? 'card hidden' : 'card';
        $('btnSave').disabled = !!blocked;
        if (!blocked) { var cp = parseInt($('fPct').value, 10); if (isNaN(cp) || cp <= cur.progress) setPct(Math.min(100, cur.progress + 1)); }
    }
    function setPct(v) {
        v = Math.max(0, Math.min(100, Math.round(parseFloat(v) || 0)));
        $('fRange').value = v; $('fPct').value = v; $('lblPct').textContent = v;
    }

    // Kompres foto: sisi terpanjang <= 1280px, JPEG 75%. Gagal (mis. HEIC) -> pakai file asli bila < 5 MB.
    function compress(file) {
        return new Promise(function (res, rej) {
            var img = new Image(), url = URL.createObjectURL(file);
            img.onload = function () {
                var w = img.naturalWidth, h = img.naturalHeight, r = Math.min(1, MAX_SIDE / Math.max(w, h));
                var c = document.createElement('canvas'); c.width = Math.round(w * r); c.height = Math.round(h * r);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { b ? res(b) : rej(new Error('compress')); }, 'image/jpeg', JPEG_Q);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                if (file.size < 5 * 1024 * 1024 && /^image\/(jpeg|png|webp)$/.test(file.type)) res(file); else rej(new Error('format'));
            };
            img.src = url;
        });
    }
    function renderThumbs() {
        var h = '';
        photos.forEach(function (p, i) { h += '<div><img src="' + p.url + '"><b data-i="' + i + '">&times;</b></div>'; });
        $('thumbs').innerHTML = h;
        var xs = $('thumbs').querySelectorAll('b');
        for (var i = 0; i < xs.length; i++) xs[i].onclick = function () {
            var k = parseInt(this.getAttribute('data-i'), 10); URL.revokeObjectURL(photos[k].url); photos.splice(k, 1); renderThumbs();
        };
    }
    function clearPhotos() { photos.forEach(function (p) { URL.revokeObjectURL(p.url); }); photos = []; renderThumbs(); }

    function getGps() {
        if (!navigator.geolocation) { showMsg('GPS tidak tersedia di perangkat ini.', 'err'); return; }
        $('btnGps').textContent = 'Lokasi: mencari...';
        navigator.geolocation.getCurrentPosition(function (pos) {
            geo = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            $('btnGps').textContent = 'Lokasi: ' + geo.lat.toFixed(5) + ', ' + geo.lng.toFixed(5);
        }, function (err) {
            geo = null; $('btnGps').textContent = 'Lokasi: gagal (ketuk untuk ulangi)';
            var why = 'Lokasi gagal diambil.';
            if (!window.isSecureContext) why = 'Lokasi hanya bisa dipakai lewat HTTPS. Alamat saat ini bukan HTTPS.';
            else if (err && err.code === 1) why = 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini, lalu ulangi.';
            else if (err && err.code === 2) why = 'Posisi tidak tersedia. Nyalakan GPS / Lokasi di HP, lalu coba di tempat terbuka.';
            else if (err && err.code === 3) why = 'Pencarian lokasi terlalu lama (timeout). Coba lagi di tempat terbuka.';
            showMsg(why + ' Klaim tetap bisa diajukan tanpa lokasi.', 'err');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    }

    function saveReport() {
        if (!cur) return;
        if (window.formAccess < ACCESS_REPORT) { showMsg('Anda tidak memiliki akses untuk melapor progress.', 'err'); return; }
        var pct = parseInt($('fPct').value, 10), date = $('fDate').value, evid = $('fEvid').value.replace(/^\s+|\s+$/g, '');
        if (isNaN(pct) || pct < 0 || pct > 100) { showMsg('Persentase harus 0 - 100.', 'err'); return; }
        if (pct <= cur.progress) { showMsg('Klaim harus lebih besar dari progress terverifikasi (' + cur.progress + '%).', 'err'); return; }
        if (!date) { showMsg('Tanggal wajib diisi.', 'err'); return; }
        if (date > todayStr()) { showMsg('Tanggal laporan tidak boleh di masa depan.', 'err'); return; }
        if (!photos.length && !evid) { showMsg('Bukti wajib: ambil foto atau isi no. BAP.', 'err'); return; }

        $('btnSave').disabled = true;
        qAll().then(function (items) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].project_id === cur.id) { throw { dup: true }; }    // server hanya mengizinkan 1 klaim menunggu per proyek
            }
            var gpsLine = geo ? ('[GPS: ' + geo.lat.toFixed(6) + ', ' + geo.lng.toFixed(6) + ']') : '';
            var note = $('fNotes').value.replace(/^\s+|\s+$/g, '');
            return qPut({
                uuid: uuid(), project_id: cur.id, project_name: cur.name, report_date: date, percent: pct,
                note: (note + (note && gpsLine ? ' ' : '') + gpsLine), evid: evid,
                photos: photos.map(function (p) { return p.blob; }), urls: [], submitTried: false, created: Date.now(), error: ''
            });
        }).then(function () {
            clearPhotos(); $('fNotes').value = ''; $('fEvid').value = ''; geo = null; $('btnGps').textContent = 'Lokasi: -';
            return renderQueueBox();
        }).then(function () { return syncQueue(true); }).then(function () {
            applyProjectState();
        }, function (e) {
            applyProjectState();
            if (e && e.dup) showMsg('Sudah ada klaim proyek ini yang antre. Tunggu terkirim dulu.', 'err');
            else showMsg('Gagal menyimpan ke penyimpanan perangkat (ruang penuh / mode privat).', 'err');
        });
    }

    // ------------------------------------------------------------
    // 4. Antrean & sinkronisasi
    // ------------------------------------------------------------
    function uploadOne(blob) {
        var ext = blob.type === 'image/png' ? 'png' : (blob.type === 'image/webp' ? 'webp' : 'jpg');
        var fd = new FormData();
        fd.append('task', 'upload_evidence');
        fd.append('file', blob, 'foto.' + ext);
        return api(null, fd);
    }
    // Cek apakah klaim yang sama sudah masuk (respons terputus setelah server menyimpan) -> hindari klaim ganda
    function alreadySubmitted(it) {
        if (!it.submitTried) return Promise.resolve(false);
        return api({ task: 'list_log', project_id: it.project_id }).then(function (res) {
            if (!res.success) return false;
            for (var i = 0; i < res.data.length; i++) {
                var r = res.data[i];
                if ((r.status === 'Diajukan' || r.status === 'Diverifikasi') && r.percent_claimed === it.percent &&
                    r.report_date === it.report_date && r.reported_by === res.user_id) return true;
            }
            return false;
        });
    }
    // Hasil: 'sent' | 'rejected' (ditolak server, perlu tindakan user). Error jaringan/sesi -> reject ke pemanggil.
    function sendItem(it) {
        function nextPhoto() {
            if (!it.photos.length) return Promise.resolve(true);
            return uploadOne(it.photos[0]).then(function (res) {
                if (!res.success) { it.error = res.message || 'Unggah foto ditolak.'; return qPut(it).then(function () { return false; }); }
                it.urls.push(res.data.url); it.photos.shift();            // foto yang sudah terunggah tidak diunggah ulang
                return qPut(it).then(nextPhoto);
            });
        }
        return nextPhoto().then(function (ok) {
            if (!ok) return 'rejected';
            return alreadySubmitted(it).then(function (found) {
                if (found) return qDel(it.uuid).then(function () { return 'sent'; });
                var lines = it.urls.slice(0);
                if (it.evid) lines.push(it.evid);                                // format sama dengan modul desktop: satu bukti per baris
                it.submitTried = true;
                return qPut(it).then(function () {
                    return post({ task: 'submit_claim', project_id: it.project_id, report_date: it.report_date,
                                  percent_claimed: it.percent, evidence_ref: lines.join('\n'), note: it.note || '' });
                }).then(function (res) {
                    if (res.success) return qDel(it.uuid).then(function () { return 'sent'; });
                    it.error = res.message || 'Ditolak server.';
                    return qPut(it).then(function () { return 'rejected'; });
                });
            });
        });
    }

    function syncQueue(announce) {
        if (syncing) return Promise.resolve();
        if (!navigator.onLine) {
            return updateQueuePill().then(function () { if (announce) showMsg('Offline: klaim tersimpan di HP dan akan dikirim saat ada sinyal.', 'ok'); });
        }
        syncing = true;
        return qAll().then(function (items) {
            items = items.filter(function (i) { return !i.error; }).sort(function (a, b) { return a.created - b.created; });
            var sent = 0, rejected = 0, stopMsg = '';
            return items.reduce(function (chain, it) {
                return chain.then(function () {
                    if (stopMsg) return;
                    return sendItem(it).then(function (r) { if (r === 'sent') sent++; else rejected++; },
                        function (e) { stopMsg = (e && e.auth) ? 'Sesi berakhir. Klaim tetap aman di HP - buka ulang halaman, login, lalu kirim lagi.'
                                                : (e && e.offline) ? 'Sinyal terputus. Klaim tersimpan di HP dan akan dicoba lagi.' : (e.message || 'Gagal mengirim.'); });
                });
            }, Promise.resolve()).then(function () {
                syncing = false;
                if (stopMsg) showMsg(stopMsg, 'err');
                else if (rejected > 0) showMsg(rejected + ' klaim ditolak server. Lihat bagian Antrean.', 'err');
                else if (sent > 0) showMsg(sent + ' klaim terkirim, menunggu verifikasi office.', 'ok');
                return Promise.all([renderQueueBox(), cur ? loadHistory() : null, sent > 0 ? refreshProjects() : null]);
            });
        }).catch(function () { syncing = false; });
    }

    function updateQueuePill() {
        return qAll().then(function (items) {
            var p = $('pillQ');
            if (items.length) { p.textContent = items.length + ' antre'; p.className = 'pill q'; } else { p.className = 'pill q hidden'; }
            return items;
        });
    }
    function renderQueueBox() {
        return updateQueuePill().then(function (items) {
            var h = '';
            items.filter(function (i) { return !cur || i.project_id === cur.id; }).forEach(function (it) {
                var n = (it.photos || []).length + (it.urls || []).length;
                h += '<div class="card"><b>' + esc(it.percent) + '%</b> &middot; ' + esc(it.report_date) + ' &middot; ' + n + ' foto' +
                     (cur ? '' : ' &middot; ' + esc(it.project_name)) +
                     '<div style="color:' + (it.error ? '#ff8a7d' : '#e6a817') + ';font-size:13px;">' +
                     (it.error ? 'DITOLAK: ' + esc(it.error) : 'Menunggu dikirim...') + '</div>' +
                     (it.error ? '<div class="row" style="margin-top:8px"><button class="sec" data-retry="' + escA(it.uuid) + '">Kirim ulang</button>' +
                                 '<button class="danger" data-del="' + escA(it.uuid) + '">Hapus</button></div>' : '') + '</div>';
            });
            $('qBox').innerHTML = h ? '<h2>Antrean (belum terkirim)</h2>' + h : '';
            var ds = $('qBox').querySelectorAll('[data-del]');
            for (var i = 0; i < ds.length; i++) ds[i].onclick = function () {
                if (window.confirm('Hapus klaim dari antrean? Foto di HP ikut terhapus.')) qDel(this.getAttribute('data-del')).then(renderQueueBox);
            };
            var rs = $('qBox').querySelectorAll('[data-retry]');
            for (var j = 0; j < rs.length; j++) rs[j].onclick = function () {
                var id = this.getAttribute('data-retry');
                qAll().then(function (all) {
                    for (var k = 0; k < all.length; k++) if (all[k].uuid === id) { all[k].error = ''; return qPut(all[k]); }
                }).then(function () { return syncQueue(true); });
            };
        });
    }

    // ------------------------------------------------------------
    // 5. Riwayat klaim (online saja) + batalkan klaim sendiri yang masih Diajukan
    // ------------------------------------------------------------
    function statusColor(s) { return s === 'Diverifikasi' ? '#2e9e4f' : (s === 'Diajukan' ? '#e6a817' : '#c0392b'); }
    function renderEvidence(v) {
        var out = '', texts = [];
        String(v || '').split(/\r?\n/).forEach(function (l) {
            l = l.replace(/^\s+|\s+$/g, '');
            if (!l) return;
            if (!/^(https?:\/\/|\/)/i.test(l)) { texts.push(esc(l)); return; }
            if (/\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(l)) out += '<a href="' + escA(l) + '" target="_blank"><img loading="lazy" src="' + escA(l) + '"></a>';
            else texts.push('<a href="' + escA(l) + '" target="_blank" style="color:#7fe0a0;">Berkas</a>');
        });
        return (texts.length ? '<div class="meta">Bukti: ' + texts.join(' | ') + '</div>' : '') + (out ? '<div class="thumbs">' + out + '</div>' : '');
    }
    function loadHistory() {
        if (!cur) return Promise.resolve();
        $('hist').innerHTML = '<div class="card">Memuat...</div>';
        return api({ task: 'list_log', project_id: cur.id }).then(function (res) {
            if (!res.success) { $('hist').innerHTML = '<div class="card">' + esc(res.message || 'Gagal memuat riwayat.') + '</div>'; return; }
            var h = '';
            res.data.forEach(function (r) {
                h += '<div class="card hist"><span class="st" style="color:' + statusColor(r.status) + ';">' + esc(r.status) + '</span>' +
                     '<span class="pct">' + esc(r.percent_claimed) + '%</span> <span class="meta">(dari ' + esc(r.prev_percent) + '%) &middot; ' + esc(r.report_date) +
                     ' &middot; ' + esc(r.reporter_name || '-') + '</span>' +
                     (r.note ? '<div>' + esc(r.note) + '</div>' : '') + renderEvidence(r.evidence_ref) +
                     (r.verified_at ? '<div class="meta" style="margin-top:6px;">' + esc(r.status) + ' oleh ' + esc(r.verifier_name || '-') +
                                      (r.verify_note ? ': ' + esc(r.verify_note) : '') + '</div>' : '');
                if (r.status === 'Diajukan' && r.reported_by === res.user_id && window.formAccess >= ACCESS_REPORT) {
                    h += '<div style="margin-top:8px"><button class="danger" data-cancel="' + r.id + '">Batalkan klaim</button></div>';
                }
                h += '</div>';
            });
            $('hist').innerHTML = h || '<div class="card">Belum ada klaim progress.</div>';
            var cs = $('hist').querySelectorAll('[data-cancel]');
            for (var i = 0; i < cs.length; i++) cs[i].onclick = function () {
                var id = this.getAttribute('data-cancel');
                if (!window.confirm('Batalkan klaim ini?')) return;
                post({ task: 'cancel_claim', id: id }).then(function (r) {
                    showMsg(r.message || (r.success ? 'Klaim dibatalkan.' : 'Gagal.'), r.success ? 'ok' : 'err');
                    loadHistory(); refreshProjects();
                }, function (e) { showMsg(netErr(e), 'err'); });
            };
        }, function (e) {
            $('hist').innerHTML = '<div class="card">' + (e.offline ? 'Offline - riwayat tidak tersedia.' : esc(netErr(e))) + '</div>';
        });
    }

    // ------------------------------------------------------------
    // 6. Init
    // ------------------------------------------------------------
    $('btnBack').onclick = backToList;
    $('btnGps').onclick = getGps;
    $('btnSave').onclick = saveReport;
    $('btnPhoto').onclick = function () {
        if (photos.length >= MAX_PHOTOS) { showMsg('Maksimal ' + MAX_PHOTOS + ' foto.', 'err'); return; }
        $('fPhoto').click();
    };
    $('fPhoto').onchange = function () {
        var files = Array.prototype.slice.call(this.files || []), self = this;
        files.reduce(function (chain, f) {
            return chain.then(function () {
                if (photos.length >= MAX_PHOTOS) return;
                return compress(f).then(function (b) { photos.push({ blob: b, url: URL.createObjectURL(b) }); },
                                        function () { showMsg('Foto ' + f.name + ' tidak dapat diproses (gunakan JPG/PNG).', 'err'); });
            });
        }, Promise.resolve()).then(function () { renderThumbs(); self.value = ''; });
    };
    $('fRange').oninput = function () { setPct(this.value); };
    $('fPct').onchange = function () { setPct(this.value); };

    window.addEventListener('online', function () { updateNet(); syncQueue(false); });
    window.addEventListener('offline', updateNet);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) syncQueue(false); });   // iOS tanpa Background Sync

    updateNet();
    updateQueuePill();
    loadProjects();
    syncQueue(false);

    // Minta penyimpanan permanen agar antrean (foto) tidak dihapus browser (terutama iOS/Safari)
    if (navigator.storage && navigator.storage.persist) { navigator.storage.persist(); }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/rab-project/sw.progress.js', { scope: '/rab-project/' }).catch(function () {});
    }
})();