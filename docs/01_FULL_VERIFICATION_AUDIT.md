# Fase 1: Full Verification Audit (Static & Security Scan)

- **Waktu Audit:** 2026-11-27 13:53:50
- **Total Endpoint Dipindai:** `64` file di `php-crud/`
- **Ringkasan Temuan:** `9 CRITICAL` | `0 HIGH` | `1 MEDIUM`

## Temuan CRITICAL (9)
- **[m.employeeCRD.php:272]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:318]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:319]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:326]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:328]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:334]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.employeeCRD.php:335]** Potensi SQL Injection: input `$_POST/$_GET` langsung disisipkan ke string SQL tanpa prepared statement.
- **[m.taxexchangerate.pdf.php]** Tidak ditemukan validasi `$_SESSION['user_id']` secara eksplisit di level endpoint.
- **[m.taxexchangerateCRD.php]** Tidak ditemukan validasi `$_SESSION['user_id']` secara eksplisit di level endpoint.

## Temuan HIGH (0)
- Tidak ditemukan temuan pada kategori ini.

## Temuan MEDIUM (1)
- **[grnCRD.php]** Tidak menggunakan row-level lock (`FOR UPDATE`), berisiko *double-posting* saat submit paralel.

