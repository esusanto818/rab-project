-- ============================================================================
-- 2026-11-17.ar_ap_aging_card.sql
-- Pilihan #2 + #3 BUSINESS_GAP_REPORT: Kartu Piutang + Aging Piutang Klien (AR)
--                                      Kartu Hutang + Aging Hutang Supplier (AP)
-- Target: proyekGMN_test SAJA (jangan ke produksi proyekGMN).
--
-- Isi:
--   1. view v_ar_aging / v_ap_aging : rincian per faktur + bucket aging (vs CURRENT_DATE)
--   2. view v_ar_card  / v_ap_card  : kartu per counterparty utk periode berjalan
--                                     (bulan ini): saldo awal, faktur, pembayaran,
--                                     alokasi, sisa, bucket aging vs hari ini.
--   3. menu baru m_form_name seq 6038 (KARTU PIUTANG & AGING) & 6039 (KARTU HUTANG & AGING)
--   4. akses level 4 untuk user yang sudah punya akses ke LAPORAN PPN (form 155)
--
-- Aturan aging (sesuai brief):
--   - AR : tanggal aging = COALESCE(due_date, invoice_date); age = CURRENT_DATE - tanggal tsb.
--   - AP : invoice_date (purchase_invoice_hdr TIDAK punya kolom due_date).
--   - Bucket: 0-30 / 31-60 / 61-90 / >90 hari.
--   - Sisa AR = amount - GREATEST(paid_amount, total alokasi project_payment_items)
--     (konsisten dengan dashboardCRD.php read_financial_aging).
--   - Sisa AP = total_amount - GREATEST(paid_amount, total alokasi supplier_payment_items).
--   - Faktur resmi saja: AR release_status='released'; AP status bukan void/cancel/reverse.
--
-- Definisi kartu (periode = bulan berjalan pada view; backend memakai formula identik
-- dengan parameter from/to sembarang):
--   saldo_awal   = SUM(nominal faktur terbit sebelum periode) - SUM(alokasi pembayaran
--                  sebelum periode, dihitung dari tanggal pembayaran header)
--   faktur       = SUM(nominal faktur terbit dalam periode)
--   pembayaran   = SUM(total_amount header pembayaran dalam periode, termasuk excess/deposit)
--   alokasi      = SUM(amount_paid item pembayaran dalam periode)
--   sisa         = saldo_awal + faktur - alokasi
--   bucket aging = sisa berjalan per faktur di-anchor terhadap CURRENT_DATE (brief:
--                  "aging terhadap hari ini"), sehingga view = kartu bulan ini + aging kini.
--
-- Idempoten: CREATE OR REPLACE VIEW + guard DO block untuk INSERT menu/akses.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. VIEW AGING AR (per faktur termin)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.v_ar_aging AS
SELECT
    c.id                                 AS client_id,
    c.name                               AS client_name,
    p.id                                 AS project_id,
    p.name                               AS project_name,
    v.id                                 AS invoice_id,
    v.inv_no,
    v.termin_name,
    v.release_no,
    v.invoice_date,
    COALESCE(v.due_date, v.invoice_date) AS aging_date,
    v.due_date,
    v.amount                             AS invoice_amount,
    GREATEST(v.paid_amount, COALESCE(a.alloc, 0))            AS paid_amount,
    (v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0))) AS outstanding,
    (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date))    AS age_days,
    CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) <= 30
         THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_0_30,
    CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 31 AND 60
         THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_31_60,
    CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 61 AND 90
         THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_61_90,
    CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) > 90
         THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_90_plus
FROM public.project_invoices v
JOIN public.projects p      ON p.id = v.project_id
LEFT JOIN public.clients c  ON c.id = p.client_id
LEFT JOIN (
    SELECT i.invoice_id, SUM(i.amount_paid) AS alloc
      FROM public.project_payment_items i
     GROUP BY i.invoice_id
) a ON a.invoice_id = v.id
WHERE v.release_status = 'released';   -- [RELEASE-LAYER] hanya tagihan resmi

-- ---------------------------------------------------------------------------
-- 2. VIEW KARTU PIUTANG PER KLIEN (periode bulan berjalan)
-- Agregat dihitung per klien lewat CTE terpisah (hindari fan-out join inv x alloc):
--   saldo_awal = total faktur RELEASED s.d. sebelum periode - total alokasi
--                pembayaran (item) dengan tanggal pembayaran < periode
--   faktur     = total faktur RELEASED dalam periode
--   pembayaran = jumlah header pembayaran (total_amount) dalam periode
--   alokasi    = jumlah item pembayaran (amount_paid) dalam periode
--   sisa       = saldo_awal + faktur - alokasi
--   bucket     = agregasi outstanding per faktur (aging vs CURRENT_DATE)
-- Hanya klien dengan aktivitas / saldo / aging tidak nol yang tampil.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.v_ar_card AS
WITH per AS (
    SELECT date_trunc('month', CURRENT_DATE)::date AS ps,
           (date_trunc('month', CURRENT_DATE) + interval '1 month')::date AS pe
),
inv_agg AS (   -- faktur RELEASED per klien, dilepas/sebelum/dalam periode
    SELECT p.client_id,
           COALESCE(SUM(CASE WHEN v.invoice_date < per.ps THEN v.amount END), 0) AS amt_before,
           COALESCE(SUM(CASE WHEN v.invoice_date >= per.ps AND v.invoice_date < per.pe THEN v.amount END), 0) AS amt_period,
           COALESCE(SUM(v.amount), 0) AS amt_total,
           COUNT(*) AS n_total,
           MIN(v.invoice_date) AS first_date,
           MAX(v.invoice_date) AS last_date
      FROM per, public.project_invoices v
      JOIN public.projects p ON p.id = v.project_id
     WHERE v.release_status = 'released'
     GROUP BY p.client_id
),
alloc_agg AS ( -- alokasi item pembayaran per klien, split tanggal bayar vs periode
    SELECT p.client_id,
           COALESCE(SUM(CASE WHEN py.payment_date < per.ps THEN i.amount_paid END), 0) AS alloc_before,
           COALESCE(SUM(CASE WHEN py.payment_date >= per.ps AND py.payment_date < per.pe THEN i.amount_paid END), 0) AS alloc_period,
           COALESCE(SUM(i.amount_paid), 0) AS alloc_total
      FROM per, public.project_payment_items i
      JOIN public.project_payments py ON py.id = i.payment_id
      JOIN public.project_invoices v  ON v.id = i.invoice_id
      JOIN public.projects p          ON p.id = v.project_id
     WHERE v.release_status = 'released'
     GROUP BY p.client_id
),
pay_agg AS (   -- header pembayaran per klien (termasuk excess/deposit) dalam periode
    SELECT py.client_id,
           COALESCE(SUM(py.total_amount), 0) AS paid_period
      FROM per, public.project_payments py
     WHERE py.payment_date >= per.ps AND py.payment_date < per.pe
     GROUP BY py.client_id
),
bucket_agg AS ( -- bucket aging (vs CURRENT_DATE) atas outstanding berjalan
    SELECT client_id,
           COALESCE(SUM(bucket_0_30), 0) AS bucket_0_30,
           COALESCE(SUM(bucket_31_60), 0) AS bucket_31_60,
           COALESCE(SUM(bucket_61_90), 0) AS bucket_61_90,
           COALESCE(SUM(bucket_90_plus), 0) AS bucket_90_plus
      FROM public.v_ar_aging
     WHERE outstanding > 0.005
     GROUP BY client_id
)
SELECT
    c.id                                              AS client_id,
    c.name                                            AS client_name,
    per.ps                                            AS period_start,
    per.pe                                            AS period_end,
    COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0)  AS saldo_awal,
    COALESCE(inv.amt_period, 0)                                     AS faktur,
    COALESCE(pay.paid_period, 0)                                    AS pembayaran,
    COALESCE(al.alloc_period, 0)                                    AS alokasi,
    COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0)
        + COALESCE(inv.amt_period, 0) - COALESCE(al.alloc_period, 0) AS sisa,
    COALESCE(inv.n_total, 0)                          AS invoice_count,
    inv.first_date                                    AS first_invoice_date,
    inv.last_date                                     AS last_invoice_date,
    COALESCE(bk.bucket_0_30, 0)                       AS bucket_0_30,
    COALESCE(bk.bucket_31_60, 0)                      AS bucket_31_60,
    COALESCE(bk.bucket_61_90, 0)                      AS bucket_61_90,
    COALESCE(bk.bucket_90_plus, 0)                    AS bucket_90_plus,
    COALESCE(inv.amt_total, 0) - COALESCE(al.alloc_total, 0)    AS outstanding_all   -- sisa seluruh periode (cross-check)
FROM public.clients c
CROSS JOIN per
LEFT JOIN inv_agg   inv ON inv.client_id = c.id
LEFT JOIN alloc_agg al  ON al.client_id  = c.id
LEFT JOIN pay_agg   pay ON pay.client_id = c.id
LEFT JOIN bucket_agg bk ON bk.client_id  = c.id
WHERE COALESCE(inv.amt_total, 0) <> 0
   OR COALESCE(al.alloc_total, 0) <> 0
   OR COALESCE(pay.paid_period, 0) <> 0
   OR COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0) <> 0
   OR COALESCE(bk.bucket_0_30, 0) + COALESCE(bk.bucket_31_60, 0) + COALESCE(bk.bucket_61_90, 0) + COALESCE(bk.bucket_90_plus, 0) <> 0;

-- ---------------------------------------------------------------------------
-- 3. VIEW AGING AP (per invoice supplier)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.v_ap_aging AS
SELECT
    s.id                             AS supplier_id,
    s.name                           AS supplier_name,
    h.id                             AS invoice_id,
    h.inv_no,
    h.supplier_invoice_no,
    h.invoice_date,
    h.invoice_date                   AS aging_date,   -- purchase_invoice_hdr tidak punya due_date
    h.total_amount                   AS invoice_amount,
    GREATEST(h.paid_amount, COALESCE(a.alloc, 0))            AS paid_amount,
    (h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0))) AS outstanding,
    (CURRENT_DATE - h.invoice_date)  AS age_days,
    CASE WHEN (CURRENT_DATE - h.invoice_date) <= 30
         THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_0_30,
    CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 31 AND 60
         THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_31_60,
    CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 61 AND 90
         THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_61_90,
    CASE WHEN (CURRENT_DATE - h.invoice_date) > 90
         THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_90_plus
FROM public.purchase_invoice_hdr h
LEFT JOIN public.m_suppliers s ON s.id = h.supplier_id
LEFT JOIN (
    SELECT i.invoice_id, SUM(i.amount_paid) AS alloc
      FROM public.supplier_payment_items i
     GROUP BY i.invoice_id
) a ON a.invoice_id = h.id
WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed');

-- ---------------------------------------------------------------------------
-- 4. VIEW KARTU HUTANG PER SUPPLIER (periode bulan berjalan)
-- Struktur identik dengan v_ar_card, sumber purchase_invoice_hdr + supplier_payments.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.v_ap_card AS
WITH per AS (
    SELECT date_trunc('month', CURRENT_DATE)::date AS ps,
           (date_trunc('month', CURRENT_DATE) + interval '1 month')::date AS pe
),
inv_agg AS (   -- invoice supplier aktif per supplier, split sebelum/dalam periode
    SELECT h.supplier_id,
           COALESCE(SUM(CASE WHEN h.invoice_date < per.ps THEN h.total_amount END), 0) AS amt_before,
           COALESCE(SUM(CASE WHEN h.invoice_date >= per.ps AND h.invoice_date < per.pe THEN h.total_amount END), 0) AS amt_period,
           COALESCE(SUM(h.total_amount), 0) AS amt_total,
           COUNT(*) AS n_total,
           MIN(h.invoice_date) AS first_date,
           MAX(h.invoice_date) AS last_date
      FROM per, public.purchase_invoice_hdr h
     WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
     GROUP BY h.supplier_id
),
alloc_agg AS ( -- alokasi item pembayaran supplier, split tanggal bayar vs periode
    SELECT h.supplier_id,
           COALESCE(SUM(CASE WHEN py.payment_date < per.ps THEN i.amount_paid END), 0) AS alloc_before,
           COALESCE(SUM(CASE WHEN py.payment_date >= per.ps AND py.payment_date < per.pe THEN i.amount_paid END), 0) AS alloc_period,
           COALESCE(SUM(i.amount_paid), 0) AS alloc_total
      FROM per, public.supplier_payment_items i
      JOIN public.supplier_payments py  ON py.id = i.supplier_payment_id
      JOIN public.purchase_invoice_hdr h ON h.id = i.invoice_id
     WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
     GROUP BY h.supplier_id
),
pay_agg AS (   -- header pembayaran supplier (termasuk excess/deposit) dalam periode
    SELECT py.supplier_id,
           COALESCE(SUM(py.total_amount), 0) AS paid_period
      FROM per, public.supplier_payments py
     WHERE py.payment_date >= per.ps AND py.payment_date < per.pe
     GROUP BY py.supplier_id
),
bucket_agg AS ( -- bucket aging (vs CURRENT_DATE) atas outstanding berjalan
    SELECT supplier_id,
           COALESCE(SUM(bucket_0_30), 0) AS bucket_0_30,
           COALESCE(SUM(bucket_31_60), 0) AS bucket_31_60,
           COALESCE(SUM(bucket_61_90), 0) AS bucket_61_90,
           COALESCE(SUM(bucket_90_plus), 0) AS bucket_90_plus
      FROM public.v_ap_aging
     WHERE outstanding > 0.005
     GROUP BY supplier_id
)
SELECT
    s.id                                              AS supplier_id,
    s.name                                            AS supplier_name,
    per.ps                                            AS period_start,
    per.pe                                            AS period_end,
    COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0)  AS saldo_awal,
    COALESCE(inv.amt_period, 0)                                     AS faktur,
    COALESCE(pay.paid_period, 0)                                    AS pembayaran,
    COALESCE(al.alloc_period, 0)                                    AS alokasi,
    COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0)
        + COALESCE(inv.amt_period, 0) - COALESCE(al.alloc_period, 0) AS sisa,
    COALESCE(inv.n_total, 0)                          AS invoice_count,
    inv.first_date                                    AS first_invoice_date,
    inv.last_date                                     AS last_invoice_date,
    COALESCE(bk.bucket_0_30, 0)                       AS bucket_0_30,
    COALESCE(bk.bucket_31_60, 0)                      AS bucket_31_60,
    COALESCE(bk.bucket_61_90, 0)                      AS bucket_61_90,
    COALESCE(bk.bucket_90_plus, 0)                    AS bucket_90_plus,
    COALESCE(inv.amt_total, 0) - COALESCE(al.alloc_total, 0)    AS outstanding_all   -- sisa seluruh periode (cross-check)
FROM public.m_suppliers s
CROSS JOIN per
LEFT JOIN inv_agg   inv ON inv.supplier_id = s.id
LEFT JOIN alloc_agg al  ON al.supplier_id  = s.id
LEFT JOIN pay_agg   pay ON pay.supplier_id = s.id
LEFT JOIN bucket_agg bk ON bk.supplier_id  = s.id
WHERE COALESCE(inv.amt_total, 0) <> 0
   OR COALESCE(al.alloc_total, 0) <> 0
   OR COALESCE(pay.paid_period, 0) <> 0
   OR COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0) <> 0
   OR COALESCE(bk.bucket_0_30, 0) + COALESCE(bk.bucket_31_60, 0) + COALESCE(bk.bucket_61_90, 0) + COALESCE(bk.bucket_90_plus, 0) <> 0;

-- ---------------------------------------------------------------------------
-- 5. MENU BARU (group 76 = REPORT, sejajar 6037 LAPORAN PPN)
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    v_exists INT;
BEGIN
    SELECT COUNT(*) INTO v_exists FROM public.m_form_name WHERE seq = '6038';
    IF v_exists = 0 THEN
        INSERT INTO public.m_form_name
            (group_id, seq, title, php_url, js_url, remark, main_table, tna, active)
        VALUES
            (76, '6038', 'KARTU PIUTANG & AGING', 'ar.aging.php', 'ar.aging.js',
             'Kartu piutang & aging AR per klien (saldo awal, faktur, pembayaran, alokasi, sisa, bucket 0/30/60/90+)',
             'project_invoices', false, true);
    END IF;

    SELECT COUNT(*) INTO v_exists FROM public.m_form_name WHERE seq = '6039';
    IF v_exists = 0 THEN
        INSERT INTO public.m_form_name
            (group_id, seq, title, php_url, js_url, remark, main_table, tna, active)
        VALUES
            (76, '6039', 'KARTU HUTANG & AGING', 'ap.aging.php', 'ap.aging.js',
             'Kartu hutang & aging AP per supplier (saldo awal, invoice, pembayaran, alokasi, sisa, bucket 0/30/60/90+)',
             'purchase_invoice_hdr', false, true);
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 6. ACCESS MENU (level 4) untuk user yang sudah punya akses ke LAPORAN PPN (form 155)
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    v_ar_id BIGINT;
    v_ap_id BIGINT;
BEGIN
    SELECT id INTO v_ar_id FROM public.m_form_name WHERE seq = '6038';
    SELECT id INTO v_ap_id FROM public.m_form_name WHERE seq = '6039';

    IF v_ar_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.m_user_access_menu WHERE form_id = v_ar_id) THEN
        INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
        SELECT DISTINCT user_id, v_ar_id, 4 FROM public.m_user_access_menu WHERE form_id = 155 AND user_id IS NOT NULL;
    END IF;

    IF v_ap_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.m_user_access_menu WHERE form_id = v_ap_id) THEN
        INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
        SELECT DISTINCT user_id, v_ap_id, 4 FROM public.m_user_access_menu WHERE form_id = 155 AND user_id IS NOT NULL;
    END IF;
END $$;

COMMIT;

-- ============================================================================
-- ROLLBACK / DOWN MIGRATION
-- ============================================================================
-- BEGIN;
-- DELETE FROM public.m_user_access_menu WHERE form_id IN (SELECT id FROM public.m_form_name WHERE seq IN ('6038','6039'));
-- DELETE FROM public.m_form_name WHERE seq IN ('6038','6039');
-- DROP VIEW IF EXISTS public.v_ap_card;
-- DROP VIEW IF EXISTS public.v_ap_aging;
-- DROP VIEW IF EXISTS public.v_ar_card;
-- DROP VIEW IF EXISTS public.v_ar_aging;
-- COMMIT;
