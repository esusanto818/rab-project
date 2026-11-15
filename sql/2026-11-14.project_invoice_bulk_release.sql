-- ============================================================================
-- 2026-11-14.project_invoice_bulk_release.sql
-- [RELEASE-LAYER] Bulk release invoice lama: draft -> released (+ nomor TAG-YYMM-NNNNN).
--
-- Dipakai bila data lama (invoice pra-migrasi) akan di-release sekaligus tanpa
-- lewat tombol "Release Semua (filter)" di UI. UI tetap cara utama; skrip ini
-- untuk kecepatan / repeat di environment lain.
--
-- ATURAN (meniru ti_release_one() di php-crud/project.invoiceCRD.php):
--   1. Hanya invoice ber-status 'draft' yang di-release.
--   2. Invoice yang SUDAH PUNYA jurnal (pra-migrasi) TIDAK diposting ulang
--      -> cegah jurnal ganda. Jurnal lama dibiarkan apa adanya.
--   3. Invoice yang sudah punya PEMBAYARAN dilewati (tidak bisa di-release).
--   4. Nomor TAG dibuat berurutan per bulan (TAG-YYMM-NNNNN) di bawah
--      pg_advisory_xact_lock, sama seperti API.
--   5. Semua dalam satu transaksi: gagal satu = batal semua (ROLLBACK).
--
-- PRILAKU JURNAL:
--   - Invoice pra-migrasi umumnya SUDAH punya jurnal (dibuat sebelum layer
--     release ada) -> skrip ini hanya mengubah status & nomor dokumen.
--   - Bila ada invoice draft yang BELUM punya jurnal (langka: invoice yang
--     disimpan setelah migrasi tapi sebelum fitur release dipakai), invoice
--     tersebut DIKECUALIKAN dan DILAPORKAN di akhir — release lewat UI agar
--     jurnal diposting dengan AutoPostJournal yang benar.
--
-- PARAMETER (ubah sebelum jalan):
--   :rel_date     = tanggal dokumen diterbitkan (default hari ini)
--   :user_id      = m_user_system.id yang men-release
--   :release_note = catatan penerbitan (opsional, maks 200 karakter)
--
-- Cara jalan:
--   psql -h <host> -U postgres -d proyekGMN -v ON_ERROR_STOP=1 \
--        -v rel_date="2026-11-14" -v user_id=1 \
--        -v release_note="bulk release data lama" \
--        -f sql/2026-11-14.project_invoice_bulk_release.sql
-- ============================================================================

\set ON_ERROR_STOP on

BEGIN;

-- Kunci supaya tidak ada nomor TAG ganda bila ada release bersamaan (lock id sama dengan ti_next_release_no).
SELECT pg_advisory_xact_lock(987654321);

-- ---------------------------------------------------------------------------
-- 1. Klasifikasikan semua invoice draft.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS _ti_candidates;
CREATE TEMP TABLE _ti_candidates AS
SELECT v.id, v.inv_no, v.paid_amount,
       EXISTS (SELECT 1 FROM public.journal_entries j
               WHERE j.reference_type::text = 'project_invoice' AND j.reference_id = v.id) AS has_journal,
       EXISTS (SELECT 1 FROM public.project_payment_items i WHERE i.invoice_id = v.id) AS has_payment
  FROM public.project_invoices v
 WHERE v.release_status = 'draft';

-- Yang bisa di-release aman: sudah punya jurnal (pra-migrasi) & belum ada pembayaran.
DROP TABLE IF EXISTS _ti_releasable;
CREATE TEMP TABLE _ti_releasable AS
SELECT id, inv_no FROM _ti_candidates
 WHERE has_journal AND NOT has_payment;

-- Yang dilewati + alasannya (dilaporkan di akhir).
DROP TABLE IF EXISTS _ti_skipped;
CREATE TEMP TABLE _ti_skipped AS
SELECT id, inv_no,
       CASE WHEN has_payment THEN 'sudah ada pembayaran'
            WHEN NOT has_journal THEN 'belum punya jurnal (release lewat UI)'
            ELSE 'lainnya' END AS alasan
  FROM _ti_candidates
 WHERE NOT (has_journal AND NOT has_payment);

-- ---------------------------------------------------------------------------
-- 2. Terbitkan: update status + nomor TAG + tanggal + user + catatan.
--    Penomoran TAG-YYMM-NNNNN mengikuti ti_next_release_no():
--    nomor berikutnya = max nomor bulan berjalan + 1, lalu berurutan.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS _ti_emit;
CREATE TEMP TABLE _ti_emit AS
SELECT r.id,
       'TAG-' || to_char(CURRENT_DATE, 'YYMM') || '-' ||
       lpad((row_number() OVER (ORDER BY r.id) + COALESCE(m.maxn, 0))::text, 5, '0') AS release_no
  FROM _ti_releasable r
  CROSS JOIN (
      SELECT COALESCE(MAX(CAST(substring(release_no from '.....$') AS integer)), 0) AS maxn
        FROM public.project_invoices
       WHERE release_no IS NOT NULL
         AND substring(release_no, length(release_no) - 9, 2) = to_char(CURRENT_DATE, 'YY')
         AND substring(release_no, length(release_no) - 7, 2) = to_char(CURRENT_DATE, 'MM')
  ) m;

UPDATE public.project_invoices v
   SET release_status = 'released',
       release_no     = e.release_no,
       release_date   = CAST(:rel_date AS date),
       released_by    = :user_id,
       release_note   = :release_note
  FROM _ti_emit e
 WHERE v.id = e.id;

-- ---------------------------------------------------------------------------
-- 3. Laporan: apa yang di-release, apa yang dilewati.
-- ---------------------------------------------------------------------------
RAISE NOTICE 'Released: % invoice', (SELECT COUNT(*) FROM _ti_releasable);
RAISE NOTICE 'Skipped : % invoice', (SELECT COUNT(*) FROM _ti_skipped);

-- Detail yang dilewati (untuk ditindak lanjungi lewat UI bila perlu).
SELECT id AS invoice_id, inv_no, alasan FROM _ti_skipped ORDER BY id;

-- Detail yang di-release (untuk cross-check nomor TAG).
SELECT v.id, v.inv_no, v.release_no, v.release_date, v.release_note
  FROM public.project_invoices v
 WHERE v.id IN (SELECT id FROM _ti_releasable)
 ORDER BY v.id;

COMMIT;
