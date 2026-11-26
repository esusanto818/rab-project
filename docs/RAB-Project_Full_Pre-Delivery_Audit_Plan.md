RAB-Project — Full Pre-Delivery Audit & Release Plan
Panduan berurutan Claude Code dari pemeriksaan awal sampai serah-terima klien
Versi: 2.0 (2026-11-26) — diselaraskan dengan CLAUDE.md v2 dan disuntik
"Known Issues" hasil pembacaan kode nyata, agar auditor tidak mengulang dari nol.

Target kerja: `dev` saja.
Folder DEV: `/home/myadmin/proyekgmn-dev`  (alias URL: `http://127.0.0.1/rab-project/dev/`)
Folder LIVE: `/home/myadmin/proyekgmn`  —  DILARANG disentuh selama proses ini.
Database test: `proyekGMN_test`.
Database production: `proyekGMN`  —  DILARANG digunakan untuk audit/perbaikan.

[v2] TODO developer (harus tuntas SEBELUM Gate 0 lulus):
  Konfirmasi hubungan `/home/myadmin/proyekgmn-dev` dengan docroot URL
  `/rab-project/dev/`. Jika keduanya symlink/alias yang sama, catat buktinya
  di PRE_DELIVERY_00. Jika berbeda, tentukan mana yang disajikan URL dan
  audit HANYA file itu. Jangan lanjut ke Tahap 1 bila ini belum jelas —
  risiko nyata: ada dua generasi `user.valid.php`, `dashboard.php`,
  `m.user.role.js` di repo; memeriksa file yang salah = audit palsu.

PRINSIP WAJIB
- Kerjakan hanya di folder DEV dan branch Git `dev`.
- Jangan checkout, merge, push, atau mengubah `main`/LIVE tanpa instruksi
  dan persetujuan terpisah.
- Audit dilakukan lebih dahulu. Tidak boleh ada patch sebelum laporan audit
  dan patch plan disetujui.
- Jangan menjalankan perubahan database (`INSERT`, `UPDATE`, `DELETE`,
  `ALTER`, `DROP`, `TRUNCATE`) selama audit.
- Jangan menjalankan migration pada database production. Pengujian database
  hanya pada `proyekGMN_test`.
- [v2] Ikuti `CLAUDE.md` v2 sebagai instruksi proyek utama. Baca sampai selesai
  di Tahap 0. Aturan yang WAJIB ditegakkan saat audit:
    * §0 folder kerja = docroot URL; larangan hardcode path.
    * §1 kontrak API (nama action/param/bentuk JSON) harus sinkron dua sisi.
    * §2 dual sistem query (pg_* legacy vs PDO baru) dan dua bentuk kontrak JSON.
    * §3a HANYA API ExtJS 3.4.1 yang valid (EditorGridPanel, bukan EditorGridModel).
    * §3b tema dari token css/app-theme.css; larang class global ber-gradient.
    * §11 aturan khusus modul yang sering bug (PO, User Role, User System,
      Stock Card, Dashboard, Session).
    * §12 checklist sebelum menyatakan selesai.
- [v2] Jangan mengarang hasil. Bedakan BUG TERBUKTI, RISIKO POTENSIAL,
  NEEDS BUSINESS CONFIRMATION, dan BELUM DAPAT DIVERIFIKASI.
- Jangan menandai temuan `VERIFIED`, `FIXED`, atau `PASS` tanpa bukti
  pengujian yang relevan.
- Bila instruksi agent/command bertentangan dengan aturan di dokumen ini atau
  `CLAUDE.md`, berhenti dan laporkan konflik; jangan memilih sendiri instruksi
  yang lebih berisiko.
- Dokumen audit boleh dibuat/diperbarui selama tahap audit. File aplikasi dan
  database tidak boleh diubah.
- [v2] Semua dokumen audit ditulis di `docs/` (bukan root). File ini sendiri
  berada di `docs/RAB-Project_Full_Pre-Delivery_Audit_Plan.md`.

URUTAN TAHAP
Tahap | Tujuan                              | Boleh ubah kode? | Hasil wajib
------|-------------------------------------|------------------|----------------------------------
0     | Preflight & pengamanan              | Tidak            | Baseline + bukti kondisi awal
1     | Audit modul 1A–1E                     | Tidak            | Register temuan per area
2     | Audit integrasi & security            | Tidak            | Matriks integrasi + endpoint + OWASP
3     | Konsolidasi & validasi laporan        | Tidak            | Laporan audit final + coverage
4     | Susun patch plan                      | Tidak            | Rencana perbaikan terurut
5     | Persetujuan pemilik proyek            | Tidak            | Daftar ID temuan yang disetujui
6     | Perbaikan terkontrol per batch        | Ya (post-approval)| Patch per batch + backup
7     | Regression, security retest, QA       | Hanya yang disetujui | Bukti pengujian + SLA performa
8     | Final independent audit + release gate| Tidak (kecuali blocker) | Keputusan NOT/CONDITIONALLY/READY
9     | Handover preparation                  | Terbatas dokumen | Paket serah-terima + training + versi

[DoD] Setiap Gate punya Definition of Done eksplisit (lihat sub-bagian "Gate N DoD").
Gate tidak dianggap lulus bila satu pun kotak DoD belum dicentang dengan bukti.

────────────────────────────────────────────────────────────────────────────
TAHAP 0 — Preflight, baseline, dan keselamatan
────────────────────────────────────────────────────────────────────────────
Jalankan Claude Code dari folder DEV. Tempel prompt ini terlebih dahulu.

PRE-FLIGHT RAB-PROJECT — READ ONLY.
Sebelum melakukan pekerjaan apa pun:
1. Pastikan current directory adalah folder DEV yang memang disajikan URL dev.
2. Baca CLAUDE.md v2 sampai selesai.
3. Periksa git status, current branch, HEAD, dan remote tracking branch.
4. Pastikan branch adalah dev. Jika bukan dev, BERHENTI; jangan checkout otomatis.
5. Pastikan folder LIVE dan database production tidak disentuh.
6. Identifikasi konfigurasi koneksi database dari source of truth proyek
   (php-func/function.all.php). Jangan menebak nama database dari file lain.
7. Verifikasi bahwa konfigurasi pengujian menunjuk ke proyekGMN_test. Jangan
   membuka koneksi tulis atau menjalankan SQL mutasi.
8. [v2] Tuntaskan TODO folder kerja: buktikan file yang disajikan URL = file
   yang akan diaudit. Daftar file ganda/berbahaya yang harus dipastikan versi
   mana yang aktif: user.valid.php, dashboard.php, m.user.role.js, po.php.
9. Catat file yang sudah modified/untracked sebelum audit. Jangan reset,
   checkout, stash, atau menimpa perubahan pengguna.
10. Periksa struktur .claude/agents dan .claude/commands. Baca instruksi yang
    relevan dan laporkan konflik dengan CLAUDE.md atau aturan audit ini.
11. Jangan mengubah file aplikasi, konfigurasi, database, branch, atau remote.

Buat hanya dokumen:
  docs/PRE_DELIVERY_00_PREFLIGHT.md
Isi:
- lokasi kerja dan branch yang terverifikasi;
- commit HEAD dan status working tree;
- daftar perubahan awal yang harus dipertahankan;
- sumber konfigurasi database yang ditemukan dan bukti environment test;
- bukti folder kerja = docroot URL (atau catatan bila belum tuntas);
- daftar instruksi agent/command yang relevan dan konflik;
- keterbatasan yang menghalangi audit.

Jika ada syarat keselamatan yang gagal atau tidak dapat diverifikasi, BERHENTI
dan jelaskan. Jangan lanjut ke audit.

Gate 0 DoD:
- [ ] CLAUDE.md v2 dibaca dan dipahami.
- [ ] Folder kerja = docroot URL terverifikasi (atau konflik dilaporkan).
- [ ] Branch = dev; tidak ada perubahan production.
- [ ] DB test = proyekGMN_test terkonfirmasi dari source of truth.
- [ ] File ganda (user.valid.php, dashboard.php, m.user.role.js, po.php) sudah
      ditentukan versi aktifnya.
- [ ] Working tree awal dicatat; tidak ada yang di-reset.
- [ ] PRE_DELIVERY_00_PREFLIGHT.md dibuat.

────────────────────────────────────────────────────────────────────────────
TAHAP 1 — Audit mendalam per area (audit-only)
────────────────────────────────────────────────────────────────────────────
Jalankan satu batch pada satu waktu. Setelah setiap batch, periksa dokumennya
sebelum melanjutkan.

[v2] WAJIB untuk semua batch: cocokkan setiap temuan dengan daftar "KNOWN
ISSUES (BASELINE)" di bagian bawah dokumen ini. Temuan yang cocok dengan
baseline ditandai REGRESI (bukan temuan baru) dan tetap butuh bukti file/baris.

1A. RBAC, user, authentication, authorization
Jalankan /audit-ketat batch1 sebagai AUDIT ONLY.
Audit:
- login, logout, session, password verification, user aktif/nonaktif;
- multi-role, role mapping, access level, menu/form permission;
- user.valid.php, router, endpoint authorization;
- [v2] pastikan user.valid.php yang diaudit adalah versi hardened (punya
  app_require_same_origin_post, endpointAliases, pemisahan isLegacyMenuRouter vs
  isDirectViewFile, dan deteksi 401 JSON vs redirect). Versi lama yang punya
  blok session-check di-comment-out adalah RISIKO.
- [v2] cek mekanisme sync role→user (m.user.roleCRD.php task sync_role_to_users):
  ia melakukan DELETE FROM m_user_access_menu WHERE user_id lalu menyalin dari
  m_role_access_menu. Verifikasi ini TIDAK diam-diam menghapus permission
  individual user; flag sebagai RISIKO HIGH bila tanpa konfirmasi UI.
- [v2] cek endpoint CRUD tidak meminjam FormAccess menu lain; harus memetakan
  dirinya ke menu yang benar (pola aiAccessStmt / read_project_ai di dashboardCRD).
- akses view/create/edit/delete/approval/reversal;
- direct PHP/CRUD/PDF/AJAX endpoint dan akses tanpa melalui menu;
- project ownership dan pembatasan data per user/project;
- konsistensi permission antara frontend dan backend.
Jangan patch atau mengubah database.
Telusuri bukti di file dan nomor baris. Uji hanya dengan cara read-only atau
test yang tidak mengubah data.
Catat BUG TERBUKTI, RISIKO POTENSIAL, dan BELUM DAPAT DIVERIFIKASI terpisah.
Perbarui docs/PRE_DELIVERY_AUDIT_REGISTER.md dan buat
docs/PRE_DELIVERY_1A_RBAC_USER.md.
Berhenti setelah laporan batch ini selesai.

1B. Master data, RAB/RAP, budget, procurement, inventory
Jalankan /audit-ketat batch2 sebagai AUDIT ONLY.
Audit:
- Master Karyawan, Supplier, Client, Item, Material, COA;
- RAB, RAP, budget control, revisi dan konsistensi total;
- Purchase Planning, PR, PO, Purchase Invoice;
- alur PO Released dan aturan approval yang berlaku;
- GRN, Warehouse Receiving, CLIENT_SUPPLIED bernilai Rp 0, GRN Approval Gate;
- stock in/out, issue, stock card, saldo dan histori;
- relasi project, supplier, item, satuan, kuantitas, harga, pajak;
- transaksi database, locking, rollback, duplicate submission, concurrency;
  [v2] contoh konkret: po_lock_project() advisory lock per proyek di poCRD.php;
  verifikasi serialisasi save_po vs change_status benar-benar mencegah race
  kuota RAB / stok.
- validasi input dan otorisasi pada seluruh endpoint.
Jangan patch, jangan jalankan migration, dan jangan melakukan SQL mutasi.
Bandingkan perhitungan UI, PHP, dan SQL. Catat sumber data dan alur antar-tabel.
Perbarui register dan buat docs/PRE_DELIVERY_1B_PROCUREMENT_INVENTORY.md.
Berhenti setelah laporan batch ini selesai.

1C. Project, SPK, subcontractor, progress, opname, retention
Jalankan /audit-ketat batch3 sebagai AUDIT ONLY.
Audit:
- project ownership dan lifecycle proyek;
- SPK, subcontractor, pekerjaan, volume, harga, dan kontrak;
- progress proyek, opname, approval, revisi dan reversal;
- subcontractor buy-back dan perhitungan material;
- client retention dan subcontractor retention bila ada;
- relasi progress dengan RAB, procurement, inventory, invoice, payment;
- validasi batas kuantitas/nilai dan pencegahan pembayaran ganda;
- histori perubahan, audit trail, dan hak akses;
- endpoint langsung, PDF, AJAX, dan laporan.
Jangan patch atau mengubah database.
Telusuri alur dari input sampai penyimpanan, perhitungan, laporan, dan dampak
ke modul lain.
Perbarui register dan buat docs/PRE_DELIVERY_1C_PROJECT_SUBCON.md.
Berhenti setelah laporan batch ini selesai.

1D. Dashboard, UI, theme, navigasi, PDF, reporting
Jalankan /audit-ketat batch4 sebagai AUDIT ONLY.
Audit:
- dashboard dan widget, angka ringkasan, filter dan drill-down;
- [v2] KONTRAK ACTION NAME: cocokkan setiap `action=...` di JS dashboard dengan
  `case '...'` di dashboardCRD.php. Contoh mismatch yang SUDAH ADA di repo:
  JS `get_project_health` vs CRD `read_project_health`; JS `get_aging_data` vs
  CRD `read_financial_aging`; JS `get_tax_rates` vs CRD `read_tax_rates` (CRD
  juga tidak membaca param year yang dikirim JS). Setiap mismatch = widget
  gagal senyap = BUG TERBUKTI.
- [v2] HOISTING JS: cek fungsi yang memakai variabel sebelum baris `var`-nya
  di-assign. Contoh nyata di po.js renderDetailPanel(): `csBanner` dan `actions`
  dipakai sebelum deklarasinya → panel detail PO mati / string "undefined".
- [v2] API ExtJS 3.4.1 valid: pastikan tidak ada class karangan. Contoh nyata:
  `Ext.grid.EditorGridModel` (TIDAK ADA) di m.user.role.js lama; yang benar
  `Ext.grid.EditorGridPanel` / `stdGridEditor`.
- [v2] HARDCODE PATH: grep `../dev/`, `/rab-project/`, path absolut di js/ dan
  views/. Contoh nyata: po.php hardcode `/rab-project/dev/...`; m.user.system.js
  hardcode `../dev/php-crud/m.employeeCRD.php`. Harus pakai main_url /
  window.APP_BASE_URL.
- [v2] MOBILE (.m.php): pastikan link bottom-nav "Dashboard" mengarah ke
  dashboard.php, bukan router?id=X (risiko 403 dari user.valid.php); pastikan
  halaman mobile tidak include user.valid.php bila memang read-only.
- [v2] CSS/tema: pastikan tidak ada class global ber-gradient (my-label-GroupLbl)
  dipakai di komponen kecil; pastikan CSS wrap grid ditarget ke id/class NYATA
  dari renderTo (bukan #editorFormGrid yang tidak ada di halaman tsb).
- seluruh link/menu antar-modul dan route tujuan;
- tombol CRUD, approval, reversal, export, print, download;
- theme switcher light/dark dan konsistensi theme pada semua modul;
- CSS global, ExtJS 3.4.1, grid, wrapping, modal, form, responsive;
- JavaScript errors, AJAX request/response, JSON contract;
- PDF, report, export, print dan direct endpoint;
- URL/base path dinamis untuk DEV/LIVE, tanpa hardcode path environment;
- akses menu dan endpoint sesuai RBAC.
Jangan patch CSS, JavaScript, PHP, atau konfigurasi.
Periksa link sampai tujuan sebenarnya; jangan menyatakan berfungsi hanya karena
elemen terlihat.
Perbarui register dan buat docs/PRE_DELIVERY_1D_DASHBOARD_UI_PDF.md.
Berhenti setelah laporan batch ini selesai.

1E. Keuangan dan akuntansi
Jalankan /audit-ketat keuangan sebagai AUDIT ONLY.
Audit:
- AR/AP, aging, kartu piutang dan hutang;
- invoice, pembayaran, penerimaan, pelunasan dan alokasi;
- COA, jurnal, debit-kredit, periode akuntansi;
- auto-posting dari transaksi operasional ke jurnal;
- pajak/PPN, backfill (ppn.backfillCRD.php) dan konsistensi nilai pajak;
- client retention, subcontractor retention, deposit dan saldo;
- reversal, void, pembatalan, koreksi dan audit trail;
- idempotency, duplicate posting, concurrency, transaksi dan rollback;
- rekonsiliasi saldo antar-modul dan laporan keuangan.
Jangan menjalankan SQL mutasi atau mengubah data.
Periksa apakah setiap posting seimbang dan dapat ditelusuri ke transaksi sumber.
Catat asumsi akuntansi yang belum dapat dikonfirmasi sebagai NEEDS BUSINESS
CONFIRMATION.
Perbarui register dan buat docs/PRE_DELIVERY_1E_FINANCE.md.
Berhenti setelah laporan batch ini selesai.

Gate 1 DoD:
- [ ] Kelima batch (1A–1E) selesai ATAU bagian yang tak dapat diperiksa dicatat
      sebagai keterbatasan.
- [ ] Setiap temuan punya bukti file/baris.
- [ ] Temuan yang cocok dengan KNOWN ISSUES baseline ditandai REGRESI.
- [ ] BUG TERBUKTI / RISIKO POTENSIAL / BELUM DAPAT DIVERIFIKASI dipisah.
- [ ] Tidak ada patch yang dilakukan.

────────────────────────────────────────────────────────────────────────────
TAHAP 2 — Audit integrasi end-to-end dan permukaan serangan
────────────────────────────────────────────────────────────────────────────
Lakukan CROSS-MODULE INTEGRATION & SECURITY AUDIT — READ ONLY.
Gunakan seluruh hasil Tahap 1. Jangan mengulang kesimpulan tanpa memeriksa
hubungan nyata di source code.

A. Telusuri alur bisnis end-to-end:
Project → RAB/RAP → Purchase Planning/PR → PO → GRN/Warehouse → Issue/Stock →
SPK/Subcon → Progress/Opname → Invoice → AR/AP → Payment/Deposit/Retention →
Journal/Report.

B. Untuk setiap perpindahan data, catat:
- modul sumber dan modul tujuan;
- tabel/field atau API/endpoint yang terlibat;
- event pemicu dan status transaksi;
- validasi, otorisasi, transaksi database;
- dampak jika gagal, diulang, dibatalkan, atau diproses bersamaan;
- bukti bahwa data sumber dan tujuan konsisten.
- [v2] KONTRAK JSON dua sisi: untuk setiap endpoint, cocokkan bentuk respons
  server dengan reader JS. Dua bentuk yang hidup di repo:
    (1) List ExtJS: {success, totalCount, data:[...]}  (ar.agingCRD, ap.agingCRD,
        m.user.roleCRD, m.user.systemCRD, stock.cardCRD)
    (2) Nested poCRD: {success, message, data:{data:[...],totalCount}} atau
        {success, message, data:{header, items, allowed_actions}}
  Reader JS wajib cocok; mismatch = grid kosong tanpa pesan = BUG.
- [v2] DUAL QUERY SYSTEM: catat endpoint mana yang pakai pg_query_params($1,$2)
  (legacy) vs PDO $db->prepare (baru). Verifikasi tidak ada satu file yang
  mencampur keduanya, dan tidak ada endpoint baru yang salah ikut pola lama.

C. Audit security — petakan ke OWASP Top 10 (2021):
- A01 Broken Access Control: RBAC lintas user/project; direct endpoint tanpa
  menu; privilege escalation; sync role→user menimpa permission (lihat 1A).
- A02 Cryptographic Failures: hashing password; tidak ada plaintext di log/DB.
- A03 Injection: SQL injection (uji input ' OR 1=1 -- di semua form & query
  dinamis); XSS di innerHTML (cek esc()/htmlEncode dipakai).
- A04 Insecure Design: session management (timeout, regenerate), CSRF
  (app_require_same_origin_post), same-origin.
- A05 Security Misconfiguration: error handling tidak membocorkan stack trace/
  kredensial ke client; display_errors off; ob_start bersih.
- A06 Vulnerable Components: CVE ExtJS 3.4.1; PHP 7.4 EOL (mitigasi tercatat).
- A07 Identification Failures: bypass auth; akses CRUD langsung tanpa session.
- A08 Software & Data Integrity: validasi server-side (bukan hanya client);
  mass assignment; manipulasi ID.
- A09 Security Logging & Monitoring: audit trail mutasi data kritis tersedia.
- A10 SSRF: endpoint yang memanggil URL eksternal (fetch_kurs_pajak, sync
  Bea Cukai) divalidasi.
- file upload, kebocoran konfigurasi, file statis sensitif.

D. Audit teknis:
- semua route dan endpoint penting;
- query database, transaksi, locking, rollback;
- dependency dan kompatibilitas PHP 7.4/ExtJS 3.4.1;
- error handling, logging, performa query dan potensi N+1;
- dead code, duplicate logic, hardcoded URL, konfigurasi environment;
- [v2] dead/duplicate code antar-generasi (dua dashboard.php, dua user.valid.php)
  — tandukan file mati yang masih ada di repo sebagai risiko kebingungan.

Buat:
  docs/PRE_DELIVERY_2_INTEGRATION_MATRIX.md
  docs/PRE_DELIVERY_2_SECURITY_AUDIT.md
  docs/PRE_DELIVERY_2_ENDPOINT_INVENTORY.md
Perbarui audit register. Setiap klaim harus memiliki file/baris atau bukti test.
Jangan patch. Berhenti setelah laporan selesai.

────────────────────────────────────────────────────────────────────────────
TAHAP 3 — Konsolidasi dan pemeriksaan kelengkapan audit
────────────────────────────────────────────────────────────────────────────
Lakukan AUDIT CONSOLIDATION — READ ONLY.
Baca semua dokumen PRE_DELIVERY yang telah dibuat dan cocokkan dengan codebase
aktual.
1. Buat daftar seluruh modul, submodul, menu, route, endpoint, PDF, export,
   AJAX, tabel utama, dan proses background yang ditemukan.
2. Cocokkan daftar tersebut dengan area yang sudah diaudit.
3. Identifikasi modul, file, endpoint, alur, atau integrasi yang belum diperiksa.
4. Periksa duplikasi temuan dan gabungkan hanya jika akar masalahnya sama;
   pertahankan referensi bukti masing-masing.
5. Pastikan tingkat risiko konsisten:
   CRITICAL = risiko langsung terhadap keamanan, integritas finansial/data, atau
              operasi inti.
   HIGH     = fungsi inti/akses penting gagal atau dapat disalahgunakan.
   MEDIUM   = dampak terbatas dengan workaround.
   LOW      = dampak minor/UX/dokumentasi.
6. Pisahkan BUG TERBUKTI, RISIKO POTENSIAL, NEEDS BUSINESS CONFIRMATION, dan
   NOT VERIFIED.
7. Jangan mengubah status menjadi VERIFIED/PASS tanpa bukti.
8. Catat seluruh keterbatasan pengujian.

Buat:
  docs/PRE_DELIVERY_3_FINAL_AUDIT_REGISTER.md
  docs/PRE_DELIVERY_3_AUDIT_SUMMARY.md
  docs/PRE_DELIVERY_3_COVERAGE_MATRIX.md
Laporan ringkasan wajib menyebut:
- jumlah temuan per tingkat risiko dan status;
- status setiap modul: PASS / FAIL / PARTIAL / NOT VERIFIED;
- modul/endpoint yang tidak tercakup;
- risiko terbesar untuk klien;
- kesimpulan: NOT READY / CONDITIONALLY READY / READY FOR PATCH PLANNING.
Jangan patch. Jika coverage belum lengkap, rekomendasikan batch audit tambahan
dan berhenti.

Gate 3 DoD:
- [ ] Coverage seluruh modul/endpoint penting terpetakan di COVERAGE_MATRIX.
- [ ] NOT VERIFIED ≠ PASS (dicatat sebagai keterbatasan).
- [ ] Duplikasi digabung hanya bila akar masalah sama.
- [ ] Tingkat risiko konsisten CRITICAL/HIGH/MEDIUM/LOW.
- [ ] Kesimpulan kesiapan dinyatakan eksplisit.

────────────────────────────────────────────────────────────────────────────
TAHAP 4 — Susun patch plan, tanpa mengubah kode
────────────────────────────────────────────────────────────────────────────
Berdasarkan FINAL AUDIT REGISTER, buat PATCH PLAN ONLY.
Jangan mengubah kode, SQL, konfigurasi, atau database.
Untuk setiap temuan OPEN yang disarankan untuk diperbaiki, tulis:
- ID temuan dan bukti asal;
- akar masalah;
- file/tabel/endpoint terdampak;
- solusi minimal yang disarankan;
- risiko regresi dan modul lain yang terdampak;
- backup yang diperlukan sesuai CLAUDE.md;
- urutan dependensi;
- test case positif, negatif, security, dan regression;
- cara rollback;
- apakah memerlukan perubahan schema/migration;
- apakah perlu keputusan bisnis dari pemilik proyek.
Kelompokkan:
P0: CRITICAL, harus ditangani sebelum serah-terima.
P1: HIGH, harus ditangani sebelum serah-terima.
P2: MEDIUM, perbaiki sesuai dampak dan keputusan pemilik.
P3: LOW, dapat dijadwalkan atau diterima dengan catatan.
Jangan menggabungkan beberapa akar masalah menjadi satu patch besar.
[v2] Prioritas awal yang sudah jelas dari baseline (tetap butuh bukti final):
  - P1: kontrak action dashboard (get_* vs read_*) — widget mati.
  - P1: hoisting csBanner/actions di po.js — panel detail PO mati.
  - P1: EditorGridModel di m.user.role.js — halaman mati.
  - P2: hardcode path po.php & m.user.system.js.
  - P1/P2: sync role→user menimpa permission individual (butuh keputusan bisnis).
Buat docs/PRE_DELIVERY_4_PATCH_PLAN.md dan daftar keputusan yang membutuhkan
persetujuan. Tunggu persetujuan pemilik proyek.

Gate 4 DoD:
- [ ] Setiap temuan OPEN punya akar masalah, solusi minimal, risiko regresi,
      backup, test case, rollback.
- [ ] Pengelompokan P0/P1/P2/P3 selesai.
- [ ] Daftar keputusan bisnis yang butuh persetujuan pemilik dibuat.
- [ ] Pemilik proyek menyetujui ID temuan yang akan diperbaiki (eksplisit).

────────────────────────────────────────────────────────────────────────────
TAHAP 5 — Persetujuan dan persiapan perbaikan
────────────────────────────────────────────────────────────────────────────
Sebelum patch, pastikan: laporan audit dan patch plan sudah ditinjau; working
tree dan perubahan pengguna sudah diamankan; backup tersedia dan dapat
dipulihkan; branch tetap `dev`; konfigurasi mengarah ke database
`proyekGMN_test`; temuan yang butuh keputusan bisnis sudah dijawab; setiap batch
patch memiliki ruang lingkup yang jelas.

Prompt:
PRE-PATCH SAFETY CHECK — READ ONLY.
Saya akan menyetujui perbaikan hanya untuk ID temuan yang disebutkan secara
eksplisit. Untuk saat ini:
1. Baca PRE_DELIVERY_3_FINAL_AUDIT_REGISTER.md dan PRE_DELIVERY_4_PATCH_PLAN.md.
2. Tampilkan hanya temuan OPEN yang termasuk daftar persetujuan saya:
   [ISI ID TEMUAN YANG DISETUJUI].
3. Verifikasi ulang bahwa bukti dan file terkait masih sesuai dengan codebase.
4. Periksa git branch/status dan perubahan pengguna.
5. Jelaskan file yang akan diubah, potensi dampak, backup, test, dan rollback.
6. Pastikan tidak ada perubahan di luar scope.
Jangan patch dulu. Laporkan apakah batch siap dieksekusi dan tunggu instruksi
RUN PATCH.

────────────────────────────────────────────────────────────────────────────
TAHAP 6 — Perbaikan terkontrol per batch
────────────────────────────────────────────────────────────────────────────
Lakukan satu batch kecil pada satu waktu. Prioritaskan P0, lalu P1.
Gunakan `/tambal-semua-gap` hanya untuk temuan yang telah disetujui dan batasi
scope maksimal tiga temuan per batch.

Prompt untuk setiap batch:
CONTROLLED PATCH — HANYA TEMUAN YANG DISETUJUI.
ID temuan yang disetujui untuk batch ini: [ISI ID]
Sebelum mengubah file:
1. Baca CLAUDE.md v2 dan patch plan.
2. Verifikasi branch dev dan folder DEV yang memang disajikan URL.
3. Periksa git diff/status; jangan menimpa perubahan yang sudah ada.
4. Buat backup sesuai aturan CLAUDE.md untuk setiap file yang akan diubah.
5. Tampilkan daftar file dan rencana perubahan.
Lalu perbaiki hanya ID temuan yang disetujui:
- perubahan sekecil mungkin;
- pertahankan kompatibilitas PHP 7.4 dan ExtJS 3.4.1;
- ikuti pola database dan URL dinamis proyek;
- jangan refactor area lain tanpa persetujuan;
- jangan mengubah main/LIVE atau database production;
- jangan menjalankan migration tanpa persetujuan eksplisit.
Setelah patch:
- jalankan git diff --check;
- lint PHP/JavaScript yang relevan;
- jalankan test yang sesuai;
- [v2] buka DevTools Console, pastikan 0 error merah (wajib untuk tugas UI);
- periksa dampak pada modul yang terhubung;
- dokumentasikan file berubah, alasan, backup, hasil test, dan rollback.
Jangan menandai temuan VERIFIED hanya karena kode sudah diubah.
Status maksimal FIXED — PENDING VERIFICATION sampai test independen lulus.
Berhenti setelah batch ini dan tunggu review.

Penting: hindari menjalankan `/patch-all-remaining` secara otomatis. Command
tersebut dapat mendorong penutupan semua temuan OPEN/PARTIAL tanpa verifikasi
independen. Hindari juga command yang otomatis mengaudit sekaligus menambal jika
tujuan batch adalah kontrol perubahan.

Jika patch membutuhkan perubahan database:
Jangan jalankan migration otomatis. Minta agent membuat migration SQL terpisah
dan laporan dampak. Periksa SQL secara manual, backup database test, lalu
jalankan hanya pada `proyekGMN_test` setelah persetujuan eksplisit. Verifikasi
hasil dan siapkan rollback. Production migration adalah proses terpisah setelah
release approval.

────────────────────────────────────────────────────────────────────────────
TAHAP 7 — Regression, security retest, dan bukti QA
────────────────────────────────────────────────────────────────────────────
POST-PATCH QA — TEST ONLY, NO NEW PATCH.
Uji semua ID temuan yang sudah diperbaiki dan modul yang terdampak.
Wajib:
1. Jalankan PHP lint untuk seluruh file PHP yang berubah.
2. Jalankan JavaScript lint/check yang tersedia untuk file JS berubah.
3. Jalankan git diff --check.
4. Jalankan automated test/E2E yang tersedia dengan base URL dan database test.
5. Uji alur positif dan negatif.
6. Uji RBAC dengan role yang berhak dan tidak berhak.
7. Uji direct endpoint, manipulasi ID, validasi server-side, dan akses lintas
   project.
8. Uji transaksi gagal, rollback, duplikasi request, dan konsistensi data bila
   test dapat dilakukan dengan aman.
9. Uji integrasi modul yang menerima atau mengirim data dari file yang berubah.
10. Pastikan tidak ada error console, PHP fatal, response JSON rusak, atau link
    yang putus.
Gunakan hanya database proyekGMN_test. Jangan menguji dengan data production.
Jangan membuat patch baru. Jika ditemukan bug baru, catat sebagai temuan baru
dan berhenti untuk persetujuan.

[v2] Performance Criteria (SLA release — wajib diukur, bukan diasumsikan):
- Dashboard load time            : < 3 detik (dengan ~100 proyek aktif)
- Grid pagination                : < 1 detik per halaman (250 rows)
- API read-only (aging/pipeline) : < 500 ms
- Chart rendering (12 bulan)     : < 2 detik
- Halaman mobile load (3G)       : < 2 detik
- Concurrent user                : 50 user simultan, degradasi < 30%
Ukur dengan curl/DevTools Network; catat angka aktual di QA evidence.

[v2] Automated Test Coverage (minimal untuk Tugas Besar):
- Unit/integration test untuk endpoint kritis (PO, Invoice, Payment, Aging).
- Regression test untuk SETIAP bug yang diperbaiki di Tahap 6 (bukti PASS).
- RBAC matrix test (Admin vs Finance vs Viewer) pada endpoint sensitif.
- Direct-endpoint test tanpa session → harus 401/403.
- Kontrak test: setiap action JS punya case CRD (grep otomatis).

Buat docs/PRE_DELIVERY_7_QA_EVIDENCE.md berisi:
- ID temuan;
- perintah/test case;
- environment;
- hasil aktual (termasuk angka performa);
- bukti/log;
- PASS/FAIL/BLOCKED;
- keterbatasan test.
Hanya status dengan bukti yang cukup boleh dinyatakan PASS.

────────────────────────────────────────────────────────────────────────────
TAHAP 8 — Final independent audit dan release gate
────────────────────────────────────────────────────────────────────────────
FINAL INDEPENDENT PRE-DELIVERY AUDIT — READ ONLY.
Lakukan pemeriksaan independen setelah semua patch dan QA. Jangan mengandalkan
status yang ditulis agent patch.
1. Baca audit register, patch plan, QA evidence, dan git diff aktual.
2. Verifikasi setiap temuan P0/P1 yang diperbaiki dengan membaca kode dan
   mengulang test relevan.
3. Pastikan tidak ada perubahan di luar scope atau perubahan pengguna yang hilang.
4. Periksa seluruh security boundary dan endpoint yang terkait patch.
5. Jalankan kembali lint, diff check, dan regression test yang relevan.
6. Pastikan migration yang disetujui memiliki hasil test dan rollback plan.
7. Periksa branch, working tree, commit history, konfigurasi environment, dan
   tidak ada secret/debug artefact yang tertinggal.
8. Jangan mengubah status menjadi VERIFIED tanpa bukti aktual.
9. [v2] Independent reviewer: final audit dilakukan oleh agent/developer yang
   TIDAK terlibat di Tahap 6 (patch). Bila tidak memungkinkan, wajib ada peer
   review dari developer lain dengan checklist CLAUDE.md v2 §12.

Buat: docs/PRE_DELIVERY_8_FINAL_ACCEPTANCE.md
Berikan keputusan:
- NOT READY: masih ada CRITICAL/HIGH, kegagalan test penting, atau gap
  keamanan/integritas.
- CONDITIONALLY READY: tidak ada blocker kritis, tetapi ada risiko/area belum
  diverifikasi yang harus diterima tertulis.
- READY FOR HANDOVER: semua kriteria penerimaan terpenuhi dan bukti tersedia.
Jangan commit, push, merge, deploy, atau menyentuh LIVE/main.
Berhenti setelah laporan final.

Kriteria minimum release gate
Kriteria                                  Target
Temuan CRITICAL terbuka                   0
Temuan HIGH terbuka                       0, kecuali diterima tertulis dengan
                                          alasan dan mitigasi
Security & authorization untuk alur kritis Lulus
Integritas transaksi dan keuangan         Lulus
Alur bisnis utama                         Lulus
Regression test untuk area terdampak      Lulus
Performance Criteria (SLA Tahap 7)        Terukur & lulus
DevTools Console 0 error merah            Lulus (semua halaman UI)
Kontrak action JS==CRD                    Lulus (grep otomatis)
Database production                       Tidak tersentuh
Temuan NOT VERIFIED                       Diungkapkan dan disetujui pemilik proyek
Backup dan rollback                        Tersedia
Dokumen handover                           Lengkap

────────────────────────────────────────────────────────────────────────────
TAHAP 9 — Persiapan paket serah-terima klien
────────────────────────────────────────────────────────────────────────────
Jalankan setelah keputusan `READY FOR HANDOVER` atau `CONDITIONALLY READY`
disetujui pemilik proyek.

PREPARE CLIENT HANDOVER PACKAGE — NO DEPLOYMENT.
Siapkan dokumen serah-terima berdasarkan hasil audit dan QA yang sudah disetujui.
Buat:
1. docs/CLIENT_HANDOVER_SUMMARY.md
   - ruang lingkup sistem dan modul;
   - fitur/alur utama;
   - status penerimaan dan batasan yang diketahui.
2. docs/CLIENT_USER_GUIDE.md
   - panduan pengguna per peran;
   - alur kerja operasional;
   - tindakan yang tidak boleh dilakukan.
3. docs/CLIENT_ADMIN_GUIDE.md
   - administrasi user/role (termasuk prosedur Sync to Users dan risikonya);
   - konfigurasi yang memang boleh diubah;
   - backup dan prosedur pemulihan.
4. docs/CLIENT_KNOWN_LIMITATIONS.md
   - keterbatasan, risiko yang diterima, dan workaround;
   - jangan menyembunyikan temuan NOT VERIFIED.
5. docs/CLIENT_RELEASE_CHECKLIST.md
   - versi/commit kandidat rilis;
   - persyaratan server;
   - konfigurasi environment tanpa membocorkan secret;
   - backup, migration, smoke test, rollback;
   - pihak yang menyetujui rilis.
6. [v2] Training & Knowledge Transfer:
   - User training 2 sesi (admin + end-user) demo alur utama;
   - Admin training: backup, restore, user/role management;
   - Developer handover: arsitektur + CLAUDE.md v2 + deployment guide;
   - Support handover: FAQ, troubleshooting, contact person, SLA.
7. [v2] Versioning & Tagging:
   - Format MAJOR.MINOR.PATCH (contoh 1.0.0);
   - MAJOR = breaking change/skema; MINOR = fitur baru backward-compatible;
     PATCH = bugfix;
   - git tag v<versi> setelah handover disetujui;
   - docs/CHANGELOG.md ringkasan perubahan per versi.
Jangan memasukkan password, API key, token, atau data pribadi ke dokumen.
Jangan deploy, mengubah LIVE, mengubah branch main, atau menjalankan migration
production.
Laporkan daftar dokumen yang dibuat dan hal yang masih harus dilakukan tim
sebelum go-live.

────────────────────────────────────────────────────────────────────────────
RISK REGISTER
────────────────────────────────────────────────────────────────────────────
| Risk                                   | Prob  | Impact   | Mitigation |
|----------------------------------------|-------|----------|------------|
| Audit menemukan >10 critical bug        | Med   | High     | Tambah Tahap 6.5 triage; tunda release |
| Production DB terkontaminasi            | Rendah| Critical | Isolasi proyekGMN_test; backup sebelum patch; larangan SQL mutasi |
| Agent mengarang hasil audit             | Med   | High     | Bukti file/baris wajib; independent reviewer (Tahap 8) |
| Regresi dari patch                      | Med   | High     | Regression test Tahap 7; backup per file; rollback plan |
| File ganda (2 generasi) diaudit salah   | Med   | High     | Tahap 0 tentukan versi aktif per file |
| Sync role→user menghapus permission user| Med   | High     | Konfirmasi UI + keputusan bisnis sebelum eksekusi |
| Client reject handover                  | Rendah| Med      | Pre-review dengan client di Tahap 5; transparan Known Limitations |
| Bug UI lolos karena tidak cek Console  | Med   | Med      | Wajib 0 error merah (Tahap 6 & 7) |
| Performa lambat tapi "lulus" audit      | Med   | Med      | SLA Tahap 7 diukur, bukan diasumsikan |

────────────────────────────────────────────────────────────────────────────
KNOWN ISSUES (BASELINE) — hasil pembacaan kode, untuk diverifikasi ulang
────────────────────────────────────────────────────────────────────────────
Bukan temuan baru; auditor cukup mengonfirmasi masih ada / sudah diperbaiki.
KI-01 (HIGH, 1D/2D) Kontrak action dashboard: JS `get_*` vs CRD `read_*`
      (get_project_health, get_action_center, get_aging_data, get_milestones,
      get_profitability, get_tax_rates, get_extra_financials,
      get_financial_performance, get_material_monitoring).
KI-02 (HIGH, 1D) po.js renderDetailPanel(): `csBanner` & `actions` dipakai
      sebelum `var` di-assign → panel detail PO mati / "undefined".
KI-03 (HIGH, 1D) m.user.role.js lama: `Ext.grid.EditorGridModel` (class tidak
      ada di 3.4.1) → Ext.onReady mati.
KI-04 (MED, 1D/2D) Hardcode path: po.php `/rab-project/dev/...`;
      m.user.system.js `../dev/php-crud/m.employeeCRD.php`.
KI-05 (HIGH, 1A) m.user.roleCRD.php sync_role_to_users: DELETE m_user_access_menu
      per user → menimpa permission individual; butuh konfirmasi UI/keputusan.
KI-06 (MED, 0/1D) Dua generasi user.valid.php & dashboard.php & m.user.role.js
      di repo; pastikan yang disajikan URL adalah versi hardened/lokal-CSS.
KI-07 (MED, 1D) Endpoint CRUD bisa meminjam FormAccess menu lain bila tidak
      memetakan dirinya (pola benar: read_project_ai cek m_user_access_menu).
KI-08 (LOW, 1D) CSS wrap grid ditarget ke id yang tidak ada (#editorFormGrid)
      di m.user.system.js → header tetap terpotong "...".
KI-09 (MED, 1D) Mobile .m.php mengarah ke router?id=X → 403 dari user.valid.php.
KI-10 (LOW, 2D) Dual kontrak JSON (list ExtJS vs nested poCRD) tidak dicatat di
      komentar header endpoint.

────────────────────────────────────────────────────────────────────────────
CARA MENJALANKANNYA TANPA KEHILANGAN KONTROL
────────────────────────────────────────────────────────────────────────────
- Tempel satu prompt tahap pada satu waktu ke Claude Code.
- Baca hasil dan dokumen yang dibuat sebelum menjalankan tahap berikutnya.
- Simpan salinan laporan audit di luar folder kerja sebelum patch.
- Jangan memberi persetujuan umum seperti "perbaiki semua". Setujui ID temuan
  dan batch secara eksplisit.
- Jika Claude Code menemukan konflik instruksi, perubahan awal yang tidak
  dikenal, database tidak jelas, atau test tidak aman, minta berhenti dan
  laporkan.
- Jangan menganggap semua agent selesai hanya karena mereka menghasilkan
  dokumen. Periksa cakupan, bukti, dan hasil test.

RINGKASAN URUTAN
0 Preflight → 1 Audit modul → 2 Integrasi & security → 3 Konsolidasi →
4 Patch plan → 5 Approval → 6 Controlled patch → 7 QA regression →
8 Final audit → 9 Handover.

Aturan terakhir: audit selesai bukan berarti proyek otomatis siap diserahkan.
Proyek baru layak diserahkan setelah hasil final audit, bukti pengujian, risiko
tersisa, dan persetujuan pemilik proyek semuanya jelas.

[v2] Catatan perubahan dari v1.0:
- Selaraskan folder kerja dengan CLAUDE.md v2 + TODO folder kerja dipindah ke
  Gate 0 (wajib tuntas).
- Tambah referensi wajib CLAUDE.md v2 di Prinsip wajib.
- Tambah item audit konkret (kontrak action, hoisting, class ExtJS invalid,
  hardcode path, sync role→user, mobile 403, dual JSON/query) di 1A/1D/2.
- Tambah OWASP Top 10 mapping di 2C.
- Tambah DoD eksplisit per Gate (0,1,3,4).
- Tambah Performance Criteria + Automated Test Coverage di Tahap 7.
- Tambah Independent Reviewer di Tahap 8 + kriteria release gate baru.
- Tambah Training & Versioning di Tahap 9.
- Tambah section Risk Register dan KNOWN ISSUES (BASELINE).
- Lokasi file dipindah ke docs/ sesuai CLAUDE.md v2 §4.
