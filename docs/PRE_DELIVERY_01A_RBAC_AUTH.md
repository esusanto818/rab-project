# PRE_DELIVERY_01A_RBAC_AUTH.md

## 1. Lingkup Audit
Audit RBAC, Authentication, dan Authorization (RBAC, User, Auth, Access Control).
- File audit utama: `php-func/user.valid.php`, `php-func/function.all.php`, `php-crud/*.php`.

## 2. Pemeriksaan Mekanisme Keamanan

### 2.1 Authentication & Session (`php-func/user.valid.php`)
- **Session Check:** `user.valid.php` menerapkan pengecekan sesi (`$_SESSION['user_id']`).
- **Response Handling:**
  - Jika sesi tidak valid dan request adalah AJAX/JSON/Fetch, aplikasi mengembalikan JSON dengan `401` dan `redirect`.
  - Jika request browser biasa, aplikasi mengarahkan ke `index.php`.
- **Status User:** `app_require_active_user()` (dari `function.all.php`) memvalidasi `active IS TRUE` di `m_user_system`.
- **Hardening:** Kode sudah memiliki perlindungan terhadap request lintas origin (`app_require_same_origin_post`) dan pengecekan origin.

### 2.2 Authorization & RBAC
- **Mapping Menu:** Aplikasi menggunakan tabel `m_user_access_menu` dan `m_form_name`.
- **Validasi CRUD:**
  - `user.valid.php` memetakan request endpoint ke `form_id` melalui `endpointAliases` jika tidak ditemukan melalui `menu_id`/`form_id` di URL.
  - CRUD direct request divalidasi `access_level`.
- **Risiko Teridentifikasi:**
  - **KI-05/KI-07:** Mekanisme `sync_role_to_users` dan peminjaman `FormAccess` perlu perhatian khusus saat testing.
  - **Hardened Logic:** Versi `user.valid.php` saat ini tampak berisi logika `hardened` yang sesuai dengan standar audit (membedakan legacy router vs direct file, alias endpoint).

### 2.3 Endpoint Proteksi
- Hampir semua endpoint di `php-crud/*.php` menggunakan `require_once __DIR__ . '/../php-func/user.valid.php';`.

## 3. Temuan & Catatan (Status: OPEN/BASELINE)
- **REGRESI (KI-06):** Memastikan bahwa versi `user.valid.php` yang aktif adalah versi *hardened* yang benar-benar tersaji oleh web server.
- **RISIKO (KI-05):** Mekanisme `m.user.roleCRD.php` (sync_role_to_users) melakukan `DELETE` dan `INSERT` massal pada `m_user_access_menu`, risiko penghapusan permission individual.
- **BELUM DIVERIFIKASI:** Pengujian apakah semua endpoint `php-crud/*.php` sudah benar-benar memanggil `user.valid.php`.

## 4. Kesimpulan Tahap 1A
- Audit read-only menunjukkan implementasi RBAC sudah mengarah pada standar *hardened*. Pengujian lebih lanjut diperlukan pada tahap testing untuk memverifikasi proteksi setiap endpoint CRUD secara independen.
