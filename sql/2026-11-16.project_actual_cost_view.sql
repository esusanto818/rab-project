-- =====================================================================
-- Patch Gelombang 3 (kedua) — Kontrol Progress Proyek vs Realisasi Biaya
-- Item    : F-1.2 (audit hulu-hilir 2026-11-15, §7 A2 / §9 F-1.2)
-- Target  : proyekGMN_test (dan produksi bila dijalankan ulang)
-- Idempoten: ya (CREATE OR REPLACE VIEW).
--
-- Latar belakang (temuan A2):
--   "Realisasi biaya HPP proyek" dihitung dengan TIGA rumus berbeda:
--     1. project.analysis.php (dashboard & kartu AI): material + expenses + subkon
--        (subkon dari subcon_payment_certificates.gross_amount APPROVED, atau
--         fallback subcon_progress.gross_amount Disetujui bila tabel sertifikat belum ada)
--     2. project.progressCRD.php prog_metrics()     : material + expenses saja (TANPA subkon)
--     3. project.wipreleaseCRD.php wr_calc()        : material saja (berbasis jurnal material_out)
--   Ketiganya dipakai untuk keputusan berbeda (kartu kesehatan, indeks biaya CPI, pelepasan WIP),
--   sehingga angka "realisasi" yang tampil di layar berbeda-beda untuk proyek yang sama.
--
-- Solusi (F-1.2): SATUKAN definisi realisasi biaya ke SATU objek DB,
--   view `public.v_project_actual_cost`, lalu pakai di ketiga jalur di atas.
--
-- Definisi kolom view (satu baris per proyek):
--   cost_material  = SUM(qty_out*unit_cost) issue PROJECT posted & belum reversed
--                    (sama persis dgn filter lama: COALESCE(issue_type,'PROJECT')='PROJECT')
--   cost_expense   = SUM(financial_expenses.amount) status bukan void/cancelled
--                    (daftar pengecualian DISATUKAN: VOID,CANCELLED,CANCELED,BATAL,DIBATALKAN)
--   cost_subcon    = SUM(gross_amount) subcon_payment_certificates APPROVED via
--                    subcon_inspections -> subcon_progress -> subcon_contracts (project_id).
--                    Bila tabel sertifikat belum ada, fallback ke subcon_progress
--                    status 'Disetujui' (perilaku dash_project_context dipertahankan).
--   cost_total     = cost_material + cost_expense + cost_subcon
--   rab_budget     = budget proyek (projects.budget x kurs anggaran) bila > 0,
--                    bila nol fallback SUM(budget_items.qty_plan*unit_price_plan) (perilaku dash)
--   hpp_released   = SUM(project_wip_release.amount)  -- HPP yang SUDAH dilepas ke Laba Rugi
--   wip_remaining  = GREATEST(cost_material - hpp_released, 0)  -- sisa WIP material belum dilepas
--
-- Catatan penting (sengaja TIDAK disatukan):
--   wr_calc() tetap menghitung pelepasan WIP dari jurnal material_out (bukan dari cost_total),
--   karena pelepasan WIP secara akuntansi hanya melepas beban material yang memang berjurnal
--   ke akun WIP. View ini dipakai untuk TAMPILAN & CPI, dan untuk menyepakati satu definisi
--   "realisasi biaya" lintas layar; rumus pelepasan tidak diubah (lihat docs/HOWTO_PROGRESS_COST_CONTROL_*).
--   Kolom cost_* di view disediakan agar prog_metrics() & dash_project_context() memakai
--   angka yang sama sampai ke digit terakhir.
-- =====================================================================

BEGIN;

CREATE OR REPLACE VIEW public.v_project_actual_cost AS
WITH iss AS (
    SELECT h.project_id,
           SUM(i.qty_out * i.unit_cost) AS cost_material
      FROM public.material_out_header h
      JOIN public.material_out_items i ON i.material_out_id = h.id
     WHERE h.status = 'posted'
       AND h.reversed_at IS NULL
       AND COALESCE(h.issue_type, 'PROJECT') = 'PROJECT'
     GROUP BY h.project_id
), oth AS (
    -- [DISATUKAN] daftar status yang DIKECUALIKAN disamakan di semua jalur:
    -- VOID, CANCELLED, CANCELED, BATAL, DIBATALKAN (dipakai dashboard & progressCRD).
    SELECT e.project_id,
           SUM(e.amount) AS cost_expense
      FROM public.financial_expenses e
     WHERE e.project_id IS NOT NULL
       AND UPPER(COALESCE(e.status, '')) NOT IN ('VOID', 'CANCELLED', 'CANCELED', 'BATAL', 'DIBATALKAN')
     GROUP BY e.project_id
), sub_cert AS (
    SELECT sc.project_id,
           SUM(spc.gross_amount) AS cost_subcon
      FROM public.subcon_payment_certificates spc
      JOIN public.subcon_inspections si ON si.id = spc.inspection_id
      JOIN public.subcon_progress sp    ON sp.id = si.progress_id
      JOIN public.subcon_contracts sc   ON sc.id = sp.contract_id
     WHERE spc.status = 'APPROVED'
     GROUP BY sc.project_id
), sub_fallback AS (
    -- dipakai hanya bila tabel subcon_payment_certificates belum ada / kosong hasilnya:
    -- tetap DISEDIAKAN sebagai kolom terpisah agar aplikasi yang memilih bisa menentukan.
    SELECT sc.project_id,
           SUM(sp.gross_amount) AS cost_subcon
      FROM public.subcon_progress sp
      JOIN public.subcon_contracts sc ON sc.id = sp.contract_id
     WHERE sp.status = 'Disetujui'
     GROUP BY sc.project_id
), wip AS (
    SELECT r.project_id,
           SUM(r.amount) AS hpp_released
      FROM public.project_wip_release r
     GROUP BY r.project_id
)
SELECT p.id                                                        AS project_id,
       p.name                                                      AS project_name,
       p.status                                                    AS project_status,
       COALESCE(p.progress, 0)                                     AS progress,
       COALESCE(p.budget, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_base_raw,
       COALESCE(NULLIF(p.budget * COALESCE(NULLIF(p.budget_exch_rate, 0), 1), 0),
                (SELECT COALESCE(SUM(b.qty_plan * b.unit_price_plan), 0)
                   FROM public.budget_items b WHERE b.project_id = p.id), 0) AS rab_budget,
       COALESCE(iss.cost_material, 0)::numeric(18,2)               AS cost_material,
       COALESCE(oth.cost_expense, 0)::numeric(18,2)                AS cost_expense,
       COALESCE(sub_cert.cost_subcon, sub_fallback.cost_subcon, 0)::numeric(18,2) AS cost_subcon,
       (COALESCE(iss.cost_material, 0)
        + COALESCE(oth.cost_expense, 0)
        + COALESCE(sub_cert.cost_subcon, sub_fallback.cost_subcon, 0))::numeric(18,2) AS cost_total,
       COALESCE(wip.hpp_released, 0)::numeric(18,2)                AS hpp_released,
       GREATEST(COALESCE(iss.cost_material, 0) - COALESCE(wip.hpp_released, 0), 0)::numeric(18,2) AS wip_remaining
  FROM public.projects p
  LEFT JOIN iss          ON iss.project_id = p.id
  LEFT JOIN oth          ON oth.project_id = p.id
  LEFT JOIN sub_cert     ON sub_cert.project_id = p.id
  LEFT JOIN sub_fallback ON sub_fallback.project_id = p.id
  LEFT JOIN wip          ON wip.project_id = p.id;

-- Verifikasi: view tersedia, satu baris per proyek, tidak ada nilai negatif.
DO $$
DECLARE
    n_view  integer;
    n_neg   integer;
BEGIN
    SELECT COUNT(*) INTO n_view
      FROM information_schema.views
     WHERE table_schema = 'public' AND table_name = 'v_project_actual_cost';
    IF n_view <> 1 THEN
        RAISE EXCEPTION 'View public.v_project_actual_cost tidak terbentuk.';
    END IF;
    SELECT COUNT(*) INTO n_neg FROM public.v_project_actual_cost WHERE cost_total < 0 OR cost_material < 0;
    IF n_neg <> 0 THEN
        RAISE EXCEPTION 'Ada % baris v_project_actual_cost dengan biaya negatif.', n_neg;
    END IF;
END $$;

COMMIT;

-- =====================================================================
-- ROLLBACK / DOWN MIGRATION
-- =====================================================================
-- Menjalankan blok ini mengembalikan kondisi sebelum migrasi (aman: view
-- hanya baca; tidak ada tabel/kolom yang berubah).
--
--   DROP VIEW IF EXISTS public.v_project_actual_cost;
--
-- Tidak ada data yang perlu dikembalikan. Aplikasi yang sudah memakai view
-- akan gagal query bila view di-drop; kembalikan kode aplikasi ke versi
-- sebelumnya (git revert) sebelum men-drop view di produksi.
-- =====================================================================
