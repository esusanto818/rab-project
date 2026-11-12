-- Persiapan AP multicurrency. JANGAN dijalankan sampai kode invoice/payment siap.
BEGIN;
ALTER TABLE public.purchase_invoice_hdr
  ADD COLUMN IF NOT EXISTS currency_id bigint REFERENCES public.m_currency(id),
  ADD COLUMN IF NOT EXISTS exch_rate numeric(15,4) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS dpp_amount_base numeric(18,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ppn_amount_base numeric(18,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_amount_base numeric(18,2) NOT NULL DEFAULT 0;
ALTER TABLE public.supplier_payments
  ADD COLUMN IF NOT EXISTS currency_id bigint REFERENCES public.m_currency(id),
  ADD COLUMN IF NOT EXISTS exch_rate numeric(15,4) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS total_amount_base numeric(18,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS allocated_amount_base numeric(18,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS excess_amount_base numeric(18,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deposit_applied_base numeric(18,2) NOT NULL DEFAULT 0;
COMMIT;
