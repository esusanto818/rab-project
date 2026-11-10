# Rangkuman Patch Audit Hak Akses — 10 November 2026

## Ruang lingkup
Patch dibuat langsung pada branch **dev** repository `esusanto818/rab-project`. Branch `main` tidak diubah, tidak di-merge, dan tidak dipublikasikan. Pemeriksaan ini merupakan tahap audit terarah pada login/session dan beberapa endpoint CRUD yang dibaca; belum merupakan sertifikasi bahwa seluruh endpoint telah lolos uji keamanan.

## Temuan dan perubahan

### 1. Login alternatif `index.org.php`
- Temuan: setelah login, kode menetapkan `$_SESSION['FormAccess'] = 3` tanpa memeriksa hak akses menu pengguna. Nilai sesi ini dapat terbawa sebagai hak modul yang tidak sesuai.
- Patch: menghapus nilai tetap tersebut; meregenerasi session ID saat login; menghapus konteks menu lama (`FormAccess`, `form_id`, `current_menu_id`). Router/validasi modul harus menetapkan hak akses berdasarkan `m_user_access_menu`.
- Efek bagi pengguna: hak akses tidak lagi otomatis dianggap level 3 setelah login melalui halaman alternatif. Menu/modul yang dibuka akan memakai hak yang terdaftar untuk akun tersebut.

### 2. Login `loginV1.php`
- Temuan: login mengisi `FormAccess` dari `form_access` pada data user, bukan hak akses untuk menu yang sedang dibuka.
- Patch: meregenerasi session ID sebelum menyimpan identitas login dan menghapus nilai/konteks akses menu lama. Nilai akses modul diisi kembali oleh router sesuai menu.
- Efek bagi pengguna: perpindahan modul tidak seharusnya mewarisi level akses global dari proses login; hak menu ditentukan ketika modul dibuka.

### 3. CRUD Master Klien `php-crud/clientsCRD.php`
- Patch: operasi update diselaraskan menjadi minimal level 2; delete minimal level 3.
- Efek bagi pengguna: pengguna level 2 dapat mengubah data klien; penghapusan membutuhkan level 3. Pemeriksaan tetap dilakukan di backend.

### 4. CRUD Master Material `php-crud/m.materialsCRD.php`
- Patch: update minimal level 2 dan delete minimal level 3.
- Efek bagi pengguna: aturan update/delete konsisten dengan kebijakan umum; request langsung ke endpoint tetap melewati pemeriksaan backend.

### 5. CRUD Chart of Accounts `php-crud/m.coaCRD.php`
- Patch: update minimal level 2 dan delete minimal level 3.
- Efek bagi pengguna: level 2 dapat melakukan perubahan biasa; penghapusan memerlukan level 3.

## Konvensi level yang diterapkan
- Level 1: baca.
- Level 2: buat dan ubah.
- Level 3: hapus dan tindakan khusus yang kebijakannya memang ditetapkan pada level tersebut.
- Level 4: tetap dipertahankan untuk tindakan sensitif tertentu seperti pembatalan/reversal finansial atau administrasi khusus. Patch ini tidak menurunkan semua tindakan level 4 secara massal.

## Bagian yang sengaja tidak diubah
- `router.php` dan pengecualian `dashboardCRD.php): dashboard memiliki aturan akses khusus per aksi; perlu audit seluruh aksi dan pemetaan menu sebelum perubahan.
- Endpoint pembayaran, PO, SPK, gudang, opname, dan subkon: tindakan seperti posting, approval, reversal, pembatalan, atau penghapusan transaksi dapat memiliki konsekuensi finansial/stok. Level tinggi yang sudah ada tidak diturunkan tanpa verifikasi kebijakan per aksi.
- `php-func/user.valid.php`: fallback pemetaan menu lama dan penentuan konteks dari parameter/session perlu pengujian integrasi router sebelum disederhanakan.

## Pengujian dan status
- File yang diubah telah dikirim ke branch `dev) melalui GitHub Contents API.
- Isi file setelah patch perlu diverifikasi ulang dengan fetch GitHub dan dibandingkan diff.
- Belum menjalankan PHP lint, pengujian database, atau pengujian HTTP terautentikasi dari lingkungan aplikasi. Karena itu patch **belum dinyatakan lulus uji runtime**.
- Pengujian wajib sebelum dipakai: login melalui kedua jalur; akses modul dengan level 1/2/3/4; uji langsung endpoint CRUD (tanpa UI); pastikan request tanpa login mendapat 401; pastikan akses di bawah ambang mendapat 403; dan pastikan operasi sah tetap berjalan.
- Setelah file di-deploy ke lingkungan dev lokal, jalankan `php -l` pada setiap file yang diubah dan uji browser/AJAX. Jangan deploy ke production.

## File yang diubah
- `index.org.php`
- `loginV1.php`
- `php-crud/clientsCRD.php`
- `php-crud/m.materialsCRD.php`
- `php-crud/m.coaCRD.php`

## Catatan rilis
Patch ini hanya berada di branch `dev`. Jangan merge ke `main` sebelum audit endpoint yang tersisa, pengujian runtime, dan validasi hak akses per menu selesai.
