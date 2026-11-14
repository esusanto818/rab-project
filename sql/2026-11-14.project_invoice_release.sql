-- ============================================================================
-- 2026-11-14.project_invoice_release.sql
-- [RELEASE-LAYER] Layer penerbitan tagihan ke klien untuk project_invoices.
-- Masalah: sembarang invoice yang disimpan langsung dianggap "tagihan aktif" dan
-- muncul di Pembayaran Termin, padahal dokumen tagihannya belum diterbitkan/
-- diserahkan ke klien. Sekarang invoice punya siklus hidup dokumen:
--   draft    = baru diketik (internal), TIDAK muncul di Pembayaran, jurnal belum posting
--   released = dokumen tagihan sudah diterbitkan (nomor TAG-...), baru masuk
--              daftar tagihan di Pembayaran Termin dan jurnal penagihan posting.
-- Data lama: semua otomatis menjadi 'draft' (default kolom baru) dan harus
-- di-review / di-release satu per satu (atau bulk) dari menu Tagihan ke Klien.
-- ============================================================================

BEGIN;

ALTER TABLE public.project_invoices
    ADD COLUMN IF NOT EXISTS release_status text    NOT NULL DEFAULT 'draft',  -- draft | released
    ADD COLUMN IF NOT EXISTS release_no   varchar(30),                         -- nomor dokumen tagihan (TAG-yymm-00001)
    ADD COLUMN IF NOT EXISTS release_date date,                               -- tanggal dokumen tagihan diterbitkan
    ADD COLUMN IF NOT EXISTS released_by  integer,                            -- m_user_system.id yang men-release
    ADD COLUMN IF NOT EXISTS release_note text    NOT NULL DEFAULT '';        -- catatan penerbitan (opsional)

CREATE INDEX IF NOT EXISTS idx_project_invoices_release_status ON public.project_invoices(release_status);

-- Nomor dokumen tagihan unik (boleh banyak NULL untuk yang belum diterbitkan).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'project_invoices_release_no_key'
                      AND conrelid = 'public.project_invoices'::regclass) THEN
        ALTER TABLE public.project_invoices
            ADD CONSTRAINT project_invoices_release_no_key UNIQUE (release_no);
    END IF;
END
$$;

COMMIT;
