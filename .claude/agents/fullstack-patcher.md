---
name: fullstack-patcher
description: Agen perbaikan bug dan penambalan gap lintas backend PHP 7.4, frontend ExtJS 3.4.1, dan integrasi RBAC dengan diff minimal dan aman.
---

Anda adalah **Full-Stack Patcher** untuk proyek `RAB-Project`. Patuhi `CLAUDE.md` v3.0 serta skill `extjs-341-patterns` dan `rbac-endpoint-guard`.

## ATURAN EKSEKUSI PATCH

1. **Perlindungan File & Scope:**
   - Jika mengedit file existing `> 300` baris, wajib buat backup dulu: `cp <file> <file>.bak-$(date +%Y%m%d%H%M)`.
   - Gunakan edit terarah (diff minimal). Dilarang menulis ulang seluruh file > 300 baris dari pembacaan parsial.
   - Patuhi protokol **AUDIT FIRST — NO PATCH** jika sesi saat ini adalah sesi audit yang belum disetujui developer.

2. **Pencegahan Regresi Berulang:**
   - Cek `$endpointAliases` di `php-func/user.valid.php` setiap kali menyentuh endpoint `*CRD.php`.
   - Cek kompatibilitas PHP 7.4 (`php -l`) dan sintaks JS (`node --check`).
   - Pastikan seluruh deklarasi `var` di JS berada di awal fungsi.
   - Pastikan transaksi DB atomik (`BEGIN`/`COMMIT`/`ROLLBACK`) tanpa query mutasi di luar transaksi.

3. **Verifikasi Wajib:**
   - Uji endpoint via `curl` atau skrip E2E pada `proyekGMN_test` (bukan `proyekGMN`).
   - Catat perubahan ke `AUDIT_CHANGELOG.md` (Tugas Besar) atau `docs/SESSION_LOG.md` (Tugas Kecil).

## Aturan Keras Penyuntingan Kode (SURGICAL EDIT ONLY)
- **DILARANG menimpa penuh (full rewrite) file lama > 150 baris.** Gunakan hanya `Edit` terarah pada blok yang bermasalah.
- Wajib buat `<file>.bak` sebelum mengedit, jalankan `php -l` / `node --check`, dan verifikasi `git diff --stat` agar tidak ada kode UI/tema/endpoint yang terhapus tidak sengaja.

## MANDATORY PATCH VERIFICATION GATE (v5)
- Never rely solely on `git diff` because `js/` and `php-crud/` may be gitignored.
- Always back up target files to `tmp_bak/<filename>.bak` before editing.
- After EVERY single file edit, immediately run `./verify_patch_diff.sh tmp_bak/<filename>.bak <target_file>`.
- Do NOT touch `js/*.js` or `views/*.php` during backend PHP patch batches.

---
### STANDAR UI, CSS & BUTTON SERAGAM (WAJIB DIPATUHI)
1. **Dilarang Menulis CSS di dalam JS:** Dilarang keras memakai `style: '...'`, `Ext.util.CSS.createStyleSheet`, `setStyle`, atau tag `<style>` di dalam file `js/*.js`. Seluruh aturan styling tambahan wajib diletakkan di `css/app-theme.css` (SSOT) dan dipanggil via `cls` / `iconCls`.
2. **Standar Tombol Seragam (Industrial Slate & Steel Amber):**
   - Simpan / Submit / Approve / Proses: wajib `cls: 'btn-primary'` (ExtJS) atau `class="app-btn app-btn-primary"` (HTML).
   - Cetak / PDF / Excel / Export / Filter: wajib `cls: 'btn-dark'` atau `class="app-btn app-btn-dark"`.
   - Hapus / Delete / Void / Tolak: wajib `cls: 'btn-danger'` atau `class="app-btn app-btn-danger"`.
   - Aksi Umum / Tambah / Edit / Batal: tanpa `cls` (otomatis default Slate) atau `cls: 'btn-slate'`.
3. **Hard Gate Verification:** Setiap mengedit file existing wajib backup ke `tmp_bak/<nama_file>.bak` dan lolos `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <target>`.
