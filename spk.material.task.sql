-- spk.material.task.sql
-- Material per TASK SPK + sumber material (Perusahaan / Subkon). Migrasi tambahan (additive), aman dijalankan berulang.
--
-- Latar belakang: spk_items (material SPK) hanya per SPK, tidak tahu material itu untuk task yang mana.
-- Material yang dibawa subkon dicatat di spk_material_supply (sudah ada dari DDL subkon), supply_source = 'SUBCON':
-- tidak menambah / mengurangi stok perusahaan dan tidak ikut kuota pembayaran klien.
--
-- Penyimpanan:
--   COMPANY (stok perusahaan) -> spk_items            : izin issue gudang + kuota + cek stok (aturan lama tidak berubah)
--   SUBCON  (dibawa subkon)   -> spk_material_supply  : rencana pasokan subkon (qty_planned)

ALTER TABLE public.spk_items
    ADD COLUMN IF NOT EXISTS spk_task_id bigint REFERENCES public.spk_tasks(id) ON DELETE SET NULL;

ALTER TABLE public.spk_material_supply
    ADD COLUMN IF NOT EXISTS spk_task_id bigint REFERENCES public.spk_tasks(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS ix_spk_items_task ON public.spk_items(spk_task_id);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_task ON public.spk_material_supply(spk_task_id);

-- Cek hasil (harus 2 baris):
-- SELECT table_name, column_name FROM information_schema.columns
--  WHERE column_name = 'spk_task_id' AND table_name IN ('spk_items', 'spk_material_supply');
