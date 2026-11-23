# CLAUDE.md
# RAB-PROJECT — DEVELOPMENT GUIDELINE & MASTER AGENT INSTRUCTIONS
Version: 2.0
Project: RAB-Project
Purpose: Development, Full System Audit, Quality Assurance & Production Readiness

---

# 0. PETA INSTANCE — WAJIB DIBACA SEBELUM BEKERJA

- URL development: `http://127.0.0.1/rab-project/dev/`
- E2E server: `RAB_BASE_URL` (default `http://127.0.0.1:8098`).
- Database testing: `proyekGMN_test`.
- Database production: `proyekGMN`.
- Database aktual aplikasi harus diperiksa dari `php-func/function.all.php`. Jangan menebak.
- Folder kerja ditentukan berdasarkan hasil `pwd`.
- Sebelum mengedit file, pastikan file tersebut benar-benar disajikan oleh URL development melalui pemeriksaan docroot, symlink, dan konfigurasi Apache.
- Hubungan `/home/myadmin/proyekgmn-dev` dengan `/rab-project/dev` harus diverifikasi terlebih dahulu.
- Jangan menganggap folder kerja, URL dev, E2E server, dan database otomatis menunjuk instance yang sama.

**Aturan keselamatan:**
Jika identitas instance atau database belum dapat dipastikan, jangan melakukan operasi mutasi. Lanjutkan pemeriksaan read-only dan dokumentasikan hambatan.

# 1. ATURAN UMUM PENGEMBANGAN

## 1.1 Cara mengubah file
- File baru ditulis secara utuh.
- File existing diubah dengan edit terarah dan diff minimal.
- Berikan komentar pada perubahan yang memerlukan penjelasan.
- Jangan menulis ulang seluruh file existing jika lebih dari 300 baris, kecuali diminta eksplisit.
- Jangan menggunakan hasil pembacaan file parsial sebagai dasar penulisan ulang file lengkap.
- Sebelum mengubah file, baca konteks fungsi, pemanggil, dan dependency terkait.

## 1.2 Klasifikasi tugas
- KECIL: bugfix/UI, maksimal 3 file, tanpa SQL.
- BESAR: audit, fitur/modul baru, migrasi SQL, atau perubahan lebih dari 3 file.

Klasifikasi menentukan dokumentasi dan verifikasi yang wajib dilakukan.

## 1.3 Pemisahan logika
- UI: `/js/` atau `/views/`.
- Pemrosesan data: `/php-crud/`.
- Helper: `/php-func/` dan `/php-class/`.
- Modul PO menjadi salah satu acuan pola implementasi.

Jangan mencampur pemrosesan bisnis berat ke dalam JavaScript atau tampilan.

## 1.4 Kontrak API
Jika mengubah nama action, parameter, atau struktur JSON:
- Periksa seluruh pemanggil.
- Gunakan:
  `rg -n "<nama_action>" views/ js/ js-func/ php-crud/`
- Perbarui seluruh sisi dalam perubahan yang sama.
- Pastikan frontend dan backend memiliki kontrak yang identik.

## 1.5 Diagnosis UI
Untuk halaman kosong, angka 0, atau data tidak tampil:
- Gunakan `/perbaiki-ui` dan agent `contract-debugger`.
- Reproduksi masalah terlebih dahulu.
- Gunakan curl atau DevTools Network.
- Periksa response, status HTTP, console, dan SQL.
- Jangan langsung mengubah tampilan sebelum akar masalah diketahui.

# 2. STANDAR BACKEND — PHP & POSTGRESQL

## 2.1 Helper dan koneksi
Pada setiap endpoint backend gunakan:

`require_once __DIR__ . '/../php-func/function.all.php';`

`require_once __DIR__ . '/../php-class/crudClass.php';`

Sesuaikan path relatif terhadap lokasi file.

Jangan membuat koneksi database kedua jika koneksi global sudah tersedia.

## 2.2 Kompatibilitas PHP
- Target PHP 7.4.
- Dilarang menggunakan sintaks atau fungsi PHP 8+.
- Contoh: `str_contains`, `str_starts_with`, `match`, dan fitur lain yang tidak kompatibel.

## 2.3 Keamanan query
- Gunakan `pg_query_params` atau PDO `prepare/execute`.
- Hindari SQL concatenation dari input pengguna.
- Validasi seluruh parameter.
- Periksa tipe data, batas nilai, dan kepemilikan record.
- SQL dinamis harus menggunakan whitelist untuk identifier yang tidak dapat di-bind.

## 2.4 Kontrak JSON
Read:
`{"success":true,"totalCount":100,"data":[]}`

Mutasi:
`{"success":true,"message":"Data berhasil disimpan."}`

Gagal:
`{"success":false,"message":"Pesan kesalahan spesifik."}`

Endpoint agregat read-only boleh menggunakan struktur khusus, tetapi harus menyediakan `success`, dan `message` ketika gagal.

Dokumentasikan struktur setiap action pada komentar header file.

## 2.5 Error handling
- Tangkap `Throwable`.
- Jangan hanya menangkap `Exception`.
- Fatal/error handling harus menghasilkan response JSON yang konsisten jika endpoint masih dapat dikendalikan.
- Gunakan HTTP status yang sesuai.
- Catat detail teknis melalui `error_log`.
- Jangan membocorkan credential, SQL sensitif, atau stack trace kepada user.

Dilarang menggunakan `echo`, `var_dump`, atau `print_r` untuk debugging pada endpoint produksi.

## 2.6 Transaksi database
- Gunakan BEGIN/COMMIT/ROLLBACK untuk transaksi header-detail.
- Gunakan `FOR UPDATE` pada perubahan status dokumen yang membutuhkan penguncian.
- Periksa kemungkinan concurrent requests.
- Hindari partial commit.
- Pastikan rollback terjadi pada seluruh kegagalan.
- Periksa idempotency dan kemungkinan double submission.
- Pastikan perubahan saldo, stok, pembayaran, dan jurnal berlangsung atomik.

Tanggal menggunakan `YYYY-MM-DD`. Desimal menggunakan titik.

# 3. STANDAR FRONTEND

## 3.1 ExtJS 3.4.1
Berlaku untuk `js/*.js` dan view yang menggunakan `Ext.*`.

- Setiap `Ext.data.Store` wajib memiliki listener `exception`.
- Null-check `dataRecord` sebelum Edit/Delete.
- Pastikan form sudah ter-render sebelum `.getForm().load()` atau `.setValue()`.
- Payload bertingkat menggunakan `Ext.util.JSON.encode` via POST.
- Tangani loading, error, dan empty state.
- Hindari event listener ganda.
- Pastikan response backend sesuai kontrak frontend.

## 3.2 Non-ExtJS
Berlaku untuk Tailwind, JavaScript biasa, dan Chart.js.

- Ikuti pola masing-masing halaman.
- Jangan mengonversi halaman non-ExtJS menjadi ExtJS.
- Semua fetch menggunakan helper terpusat.
- Helper harus mendeteksi response non-JSON dan `success:false`.
- Error harus ditampilkan kepada user.
- Dilarang menghentikan proses diam-diam dengan `if (!json.success) return;`.
- Escape teks database sebelum memasukkan ke `innerHTML`.
- Gunakan class Tailwind yang sudah tersedia.
- CDN Tailwind dinonaktifkan.
- Jika membutuhkan class baru, build ulang CSS terkait.

# 4. STRUKTUR DIREKTORI

- `ext-3.4.1/` : framework ExtJS.
- `js/` : UI ExtJS.
- `js-func/` : fungsi global.
- `views/` : halaman utama dan UI non-ExtJS.
- `php-crud/` : handler backend.
- `php-func/` : helper.
- `php-class/` : class global.
- `sql/` : migrasi.
- `tests/` : testing dan E2E.
- `docs/` : dokumentasi.
- `cron/` : background jobs.
- `assets/` : CSS dan JS lokal.
- `pdf/` : modul dokumen dan PDF.

Acuan implementasi backend: `purchaseinvoiceCRD.php`.

# 5. VERIFIKASI DAN PELAPORAN

## 5.1 Tugas kecil
1. Jalankan `php -l` atau `node --check`.
2. Jika memungkinkan, uji endpoint menggunakan curl.
3. Periksa kontrak JSON.
4. Jalankan `git status -s`.
5. Catat maksimal 5 baris di `docs/SESSION_LOG.md`.

## 5.2 Tugas besar
Semua verifikasi tugas kecil ditambah:

1. `git diff --stat`.
2. Perbarui `AUDIT_CHANGELOG.md`.
3. Gunakan tabel: tanggal/waktu, path file, ringkasan perubahan, alasan, dan status Git.
4. Jalankan E2E menggunakan skill `e2e-test-template` apabila logika transaksi/DB berubah.
5. Pastikan pengujian berjalan pada instance dan DB yang benar.
6. Buat dokumentasi:
   `docs/YYYY-MM-DD_<NAMA_AGENT>_<MODUL>.md`

Dokumentasi mencakup:
- Ringkasan pekerjaan.
- File yang diperiksa/diubah.
- Temuan.
- SQL/migrasi jika ada.
- Langkah verifikasi.
- Hasil testing.

Selalu tampilkan daftar file yang berubah.

Jangan melakukan commit tanpa instruksi eksplisit.

Jawaban akhir harus padat dan langsung ke poin.

# 6. STANDAR AUDIT MENDALAM

Berlaku untuk seluruh sesi audit, security review, integrasi, dan regresi.

## 6.1 Cakupan lintas lapisan
Periksa:
- `php-crud/`
- `php-func/`
- `php-class/`
- `views/`
- `pdf/`
- `js/`
- `js-func/`
- `sql/`
- `tests/`
- File berekstensi huruf besar seperti `*.PHP`.

Jangan hanya mengaudit backend.

## 6.2 Business logic dan SQL
Periksa:
- Komentar `//` di dalam string SQL multi-baris yang dapat menyebabkan error PostgreSQL.
- SQL dinamis.
- Regex PostgreSQL.
- Penomoran otomatis.
- Transisi bulan/tahun.
- Validasi status dokumen.
- Validasi sisa tagihan.
- Kepemilikan client.
- Validasi sebelum dan sesudah transaksi.
- Perhitungan quantity dan monetary values.

Verifikasi secara khusus:
- `CreateRefNo`
- `ti_next_release_no`
- Urutan `TAG-`
- Urutan `INV-`
- Urutan `PPAY-`

Perhatikan regex titik (`.`) yang seharusnya literal.

## 6.3 Master data dan konfigurasi
Periksa:
- `fiscal_config`.
- `PRJ_DEP_APPLY`.
- `accounting_periods`.
- Distribusi `m_user_access_menu`.
- Master material dan satuan.
- Data pra-migrasi yang sudah berjurnal.
- Potensi double-posting.
- Konsistensi data sebelum dan sesudah migrasi.

## 6.4 Pengujian temuan
Setiap temuan baru pada sesi audit harus:
- Memiliki bukti.
- Memiliki test case.
- Diuji pada `proyekGMN_test`.
- Memiliki hasil PASS, FAIL, atau NOT TESTED.

Jangan menguji secara destruktif pada database aktif.

# 7. MIGRASI SQL

- File baru menggunakan format:
  `sql/YYYY-MM-DD.nama_fitur.sql`
- Wajib memiliki blok:
  `-- ROLLBACK / DOWN MIGRATION`
- Rollback harus aman dan mempertimbangkan data yang sudah digunakan.
- Periksa dependency FK, trigger, view, function, dan index.
- Jangan mengasumsikan DDL dapat langsung dijalankan pada semua environment.

**Agent DILARANG mengeksekusi migrasi atau DDL.**

Developer menjalankan migrasi secara manual setelah review.

Alur `/bangun-fitur` dan `/audit-dan-tambal` harus berhenti pada tahap review migrasi dan menunggu eksekusi developer.

# 8. PENDAFTARAN MENU DAN HAK AKSES

Hanya berlaku untuk halaman, modul, atau laporan BARU.

1. Periksa struktur tabel menu dan hak akses aktual.
2. Tentukan parent menu, ikon, urutan, URL, handler JS, dan role.
3. Sertakan SQL pendaftaran pada migrasi modul.
4. Gunakan `INSERT ... ON CONFLICT DO NOTHING` atau `WHERE NOT EXISTS` sesuai struktur aktual.
5. Jangan membuat asumsi terhadap unique constraint.
6. Eksekusi tetap dilakukan developer.
7. Tambahkan validasi menu dan hak akses ke checklist deployment.

Tidak berlaku untuk bugfix atau perubahan layar yang sudah ada.

# 9. DOKUMENTASI

- Seluruh dokumentasi berada di `docs/` pada root proyek.
- Jangan membuat laporan baru sembarangan di root.
- Pengecualian root: `AUDIT_CHANGELOG.md`.
- Tugas kecil cukup `docs/SESSION_LOG.md`.
- Tugas besar wajib dokumentasi lengkap.
- Jangan membuat laporan berdasarkan asumsi.
- Semua kesimpulan harus memiliki bukti.

Pencarian normal dilakukan secara terarah dengan `Grep`/`rg`.

Pengecualian: sesi full audit wajib memeriksa seluruh cakupan proyek.

# 10. NOTIFIKASI WHATSAPP PASCA-TUGAS

Dikirim sekali setelah Tugas Besar selesai atau saat sesi berakhir setelah ada perubahan kode.

Tidak dikirim untuk sesi tanya-jawab atau audit read-only tanpa perubahan kode.

Nomor tujuan dari env `HERMES_WA_TO`.

Jalankan:

`hermes send --to "whatsapp:$HERMES_WA_TO" "<1-3 baris: pekerjaan, status, commit hash bila ada>"`

Jika env kosong:
- Lewati pengiriman.
- Sebutkan di ringkasan akhir.

Jika exit code non-zero:
1. Jalankan `hermes gateway restart && sleep 3`.
2. Ulangi `hermes send` satu kali.
3. Jika masih gagal, laporkan.

Jangan memasukkan credential atau informasi sensitif.

# 11. MASTER AUDIT PROTOCOL — AUDIT FIRST, NO PATCH

Bagian ini merupakan aturan tertinggi selama sesi audit.

Jika instruksi audit bertentangan dengan instruksi patch biasa, bagian ini yang berlaku.

## 11.1 Fase 1 — Audit tanpa perubahan aplikasi

Selama audit belum selesai:

- DILARANG mengubah source code aplikasi.
- DILARANG menerapkan bugfix.
- DILARANG refactor.
- DILARANG mengubah konfigurasi aplikasi.
- DILARANG mengubah schema database.
- DILARANG mengeksekusi DDL atau migrasi.
- DILARANG mengubah data database aktif.
- DILARANG melakukan commit, merge, push.
- DILARANG mengubah branch main/live.

Pengujian read-only boleh dilakukan pada instance yang telah diverifikasi.

Pengujian yang membutuhkan perubahan data hanya boleh menggunakan database testing terisolasi.

Pembuatan test case di `tests/` diperbolehkan, tetapi tidak boleh mengubah source code aplikasi.

## 11.2 Fase 2 — Inventarisasi menyeluruh

Buat daftar:
- Seluruh modul.
- Seluruh submodul.
- Seluruh endpoint.
- Seluruh tabel.
- Seluruh database objects.
- Seluruh integrasi.
- Seluruh proses transaksi.
- Seluruh dependency penting.

Setiap modul harus memiliki status audit.

Jangan melewatkan modul hanya karena jarang digunakan.

## 11.3 Fase 3 — Audit seluruh area

Audit sekurang-kurangnya:

1. Arsitektur dan struktur aplikasi.
2. Authentication.
3. Authorization dan RBAC.
4. Business logic.
5. Backend PHP.
6. Frontend JavaScript.
7. API dan AJAX.
8. PostgreSQL dan SQL.
9. Database integrity.
10. Transaction safety.
11. Master data.
12. Inventory.
13. Purchasing.
14. RAB.
15. Project dan Task.
16. SPK Internal.
17. SPK Subcon.
18. Subcontract management.
19. Progress dan inspection.
20. Payment certificate.
21. Invoice dan payment.
22. Finance dan accounting.
23. Dashboard.
24. Reports dan PDF.
25. AI integration.
26. File upload/download.
27. Security.
28. Performance.
29. Error handling dan logging.
30. User interaction dan UI/UX.

Audit hubungan data antarmodul, bukan hanya masing-masing modul secara terpisah.

## 11.4 Fase 4 — Audit integrasi proses bisnis

Telusuri aliran data:

RAB → Project → Task → Material/Expense → SPK → Execution → Progress → Inspection → Payment Certificate → Invoice → Payment.

Periksa:
- Konsistensi quantity.
- Konsistensi harga.
- Kepemilikan material.
- Pemakaian stok.
- Material sisa.
- Biaya proyek.
- Status dokumen.
- Hak akses.
- Perhitungan pembayaran.
- Pembatalan transaksi.
- Potensi duplikasi dan double posting.

Setiap perpindahan data harus dapat ditelusuri ke sumbernya.

## 11.5 Fase 5 — Testing

Lakukan:
- PHP syntax check.
- JavaScript syntax check.
- SQL validation.
- Static analysis.
- Database integrity testing.
- Unit testing jika tersedia.
- Integration testing.
- Regression testing.
- Security testing.
- Negative testing.
- Boundary testing.
- E2E testing.

Semua test harus mencatat:
- Test ID.
- Modul.
- Skenario.
- Expected result.
- Actual result.
- PASS/FAIL/NOT TESTED.
- Bukti hasil.

Jangan menyatakan PASS tanpa pengujian.

Jika test tidak dapat dilakukan, dokumentasikan alasannya.

## 11.6 Fase 6 — Findings Register

Setiap temuan wajib memiliki:

- Finding ID.
- Modul.
- Severity.
- File dan lokasi kode.
- Bukti aktual.
- Akar masalah.
- Dampak user.
- Dampak data.
- Risiko bisnis.
- Rekomendasi.
- Test case.
- Status verifikasi.

Klasifikasi:

CRITICAL: risiko keamanan besar, kerusakan data serius, atau transaksi kritis tidak aman.

HIGH: kesalahan bisnis/keuangan, akses ilegal, atau kegagalan fungsi utama.

MEDIUM: gangguan fungsi sebagian atau inkonsistensi proses.

LOW: masalah minor.

INFO: rekomendasi peningkatan.

## 11.7 Fase 7 — Audit completion gate

Audit baru boleh dinyatakan lengkap jika:

- Seluruh modul telah diinventarisasi.
- Seluruh area wajib telah diperiksa.
- Seluruh integrasi penting telah ditelusuri.
- Seluruh database objects telah diinventarisasi dan diperiksa sesuai relevansi.
- Seluruh temuan terdokumentasi.
- Seluruh pengujian yang memungkinkan sudah dijalankan.
- Semua NOT TESTED memiliki alasan.
- Tidak ada area yang dilewati tanpa penjelasan.
- Seluruh laporan audit sudah tersedia.

Jika belum memenuhi seluruh persyaratan, statusnya AUDIT INCOMPLETE.

Jangan berhenti hanya karena jumlah temuan banyak.

## 11.8 Fase 8 — Rencana patch

Setelah audit lengkap:

- Susun daftar patch.
- Prioritaskan CRITICAL, HIGH, MEDIUM, LOW.
- Identifikasi dependency antarpaket perbaikan.
- Identifikasi risiko regresi.
- Tentukan urutan implementasi.
- Tentukan test case untuk setiap patch.
- Identifikasi kebutuhan migrasi SQL.

Pada fase ini hanya menyusun rencana.

Jangan langsung melakukan patch tanpa persetujuan developer.

# 12. PATCH & REGRESSION PROTOCOL

Bagian ini berlaku setelah audit lengkap dan developer menyetujui patch.

1. Pastikan branch aktif adalah dev.
2. Pastikan branch main/live tidak berubah.
3. Pastikan working tree dan perubahan sebelumnya teridentifikasi.
4. Terapkan patch berdasarkan findings register.
5. Gunakan perubahan minimal.
6. Jangan mengubah modul lain tanpa alasan dan dokumentasi.
7. Migrasi SQL hanya dibuat, bukan dieksekusi agent.
8. Jalankan syntax check.
9. Jalankan unit/integration test yang relevan.
10. Jalankan regression test.
11. Jalankan E2E terhadap instance yang benar.
12. Verifikasi ulang setiap finding.
13. Periksa efek samping ke modul terkait.
14. Pastikan tidak ada data yang rusak.
15. Perbarui dokumentasi dan changelog.

Status temuan:
- OPEN
- IN_PROGRESS
- FIXED
- VERIFIED
- ACCEPTED_RISK
- NOT_REPRODUCIBLE

Hanya VERIFIED yang dapat dianggap selesai setelah pengujian ulang berhasil.

Jangan menandai FIXED hanya karena source code sudah diubah.

# 13. RELEASE READINESS GATE

Sebelum aplikasi direkomendasikan untuk diserahkan kepada user, periksa:

## A. Functional readiness
- Seluruh fitur utama berjalan.
- Tidak ada tombol utama yang tidak berfungsi.
- Tidak ada form gagal simpan.
- Tidak ada halaman kosong.
- Tidak ada alur transaksi terputus.

## B. Data readiness
- Database integrity valid.
- Tidak ada orphan data kritis.
- Tidak ada double posting.
- Kalkulasi keuangan konsisten.
- Stok dan material dapat ditelusuri.

## C. Security readiness
- Authentication valid.
- RBAC valid.
- Tidak ada akses ilegal yang diketahui.
- Input dan output tervalidasi.
- File handling aman.

## D. Integration readiness
- Seluruh integrasi penting berfungsi.
- Data antarmodul konsisten.
- Transaksi berjalan atomik.
- Error ditangani dengan benar.

## E. Testing readiness
- Regression test dijalankan.
- E2E test dijalankan pada instance yang benar.
- Tidak ada FAIL kritis yang belum diselesaikan.
- NOT TESTED dijelaskan.
- Bukti pengujian tersedia.

## F. Release decision

Gunakan status:

A. READY FOR RELEASE
Seluruh persyaratan utama terpenuhi dan tidak ada temuan kritis/tinggi yang terbuka.

B. READY AFTER FIXES
Masih ada temuan yang harus diselesaikan sebelum release.

C. NOT READY FOR RELEASE
Terdapat risiko besar terhadap fungsi, keamanan, data, atau transaksi.

D. AUDIT INCOMPLETE
Audit belum mencakup seluruh area wajib.

Jangan memberikan status READY hanya karena aplikasi dapat dibuka.

Developer tetap menjadi pihak yang menentukan dan melakukan deployment ke production.

# 14. ATURAN KOMUNIKASI AGENT

- Bekerja proaktif dan berurutan.
- Jangan meminta konfirmasi rutin.
- Minta informasi hanya jika benar-benar diperlukan.
- Jangan menyembunyikan error.
- Jangan membuat klaim pengujian tanpa bukti.
- Jangan mengasumsikan fitur berhasil karena tidak ada error yang terlihat.
- Laporkan hambatan secara spesifik.
- Lanjutkan area audit lain jika satu area terhambat.
- Jangan mengulang pemeriksaan yang sama tanpa alasan.
- Gunakan hasil pemeriksaan sebelumnya sebagai referensi.
- Selalu tampilkan progres dan status pekerjaan secara faktual.

## FORMAT RINGKASAN AKHIR

Setiap tugas harus diakhiri dengan:

1. Pekerjaan yang dilakukan.
2. Modul yang diperiksa.
3. Temuan penting.
4. Hasil testing.
5. Status pekerjaan.
6. File yang dibuat/diubah.
7. Database yang digunakan untuk pengujian.
8. Status Git dan branch.
9. Hambatan yang belum terselesaikan.
10. Langkah berikutnya.

---

# FINAL DIRECTIVE

PRIORITAS UTAMA PROYEK RAB-PROJECT:

**DATA INTEGRITY > BUSINESS LOGIC > SECURITY > FUNCTIONALITY > PERFORMANCE > UI/UX**

Jangan mengorbankan integritas data demi membuat fitur terlihat berfungsi.

Jangan melakukan patch sebelum audit lengkap.

Jangan mengubah production.

Jangan menganggap pengujian berhasil tanpa bukti.

Target akhir adalah aplikasi ERP yang stabil, aman, konsisten, dapat diuji, dan layak digunakan oleh user.

Semua agent, command, skill, dan subagent wajib mengikuti CLAUDE.md ini.

Jika terdapat konflik instruksi, prioritaskan keselamatan data, pemisahan environment, dan aturan AUDIT FIRST — NO PATCH.
