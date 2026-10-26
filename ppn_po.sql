-- ============================================================
-- Migrasi PPN - TAHAP 1 (PPN Masukan di Purchase Order)
-- Database : proyekGMN (PostgreSQL 9.6)
-- Dipakai  : po.js / poCRD.php
-- Aman dijalankan ulang (IF NOT EXISTS / pengecekan constraint).
-- ============================================================
BEGIN;

-- 1) Mode & tarif PPN per PO.
--    NONE = tanpa PPN | EXCL = harga item belum termasuk PPN | INCL = harga item sudah termasuk PPN
--    PO lama otomatis NONE / 0% (nilai tidak berubah).
ALTER TABLE public.purchase_orders
    ADD COLUMN IF NOT EXISTS ppn_mode varchar(4)   NOT NULL DEFAULT 'NONE',
    ADD COLUMN IF NOT EXISTS ppn_rate numeric(5,2) NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_po_ppn_mode') THEN
        ALTER TABLE public.purchase_orders
            ADD CONSTRAINT chk_po_ppn_mode CHECK (ppn_mode IN ('NONE','EXCL','INCL'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_po_ppn_rate') THEN
        ALTER TABLE public.purchase_orders
            ADD CONSTRAINT chk_po_ppn_rate CHECK (ppn_rate >= 0 AND ppn_rate <= 100);
    END IF;
END $$;

-- 2) Status PKP & NPWP supplier (dasar default mode PPN di PO dan faktur pajak masukan tahap berikutnya).
--    Semua supplier awalnya is_pkp = false -> tandai supplier PKP di master supplier / lewat UPDATE.
ALTER TABLE public.m_suppliers
    ADD COLUMN IF NOT EXISTS npwp_no varchar(30),
    ADD COLUMN IF NOT EXISTS is_pkp  boolean NOT NULL DEFAULT false;

COMMIT;

-- Contoh menandai supplier PKP:
-- UPDATE public.m_suppliers SET is_pkp = true, npwp_no = '01.234.567.8-901.000' WHERE id = 1;
