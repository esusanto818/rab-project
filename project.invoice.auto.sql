-- Migrasi: penerbitan tagihan otomatis berdasarkan breakdown task (m_proyekCRD.php)
-- Aman dijalankan ulang (IF NOT EXISTS).
ALTER TABLE public.project_invoices ADD COLUMN IF NOT EXISTS due_date       date;                 -- jatuh tempo = mulai task - 1 hari
ALTER TABLE public.project_invoices ADD COLUMN IF NOT EXISTS task_id        bigint;               -- task pemicu tagihan
ALTER TABLE public.project_invoices ADD COLUMN IF NOT EXISTS auto_generated boolean NOT NULL DEFAULT false;
ALTER TABLE public.project_invoices ADD COLUMN IF NOT EXISTS ai_note        text;                 -- ringkasan analisis AI

-- task dihapus & dibuat ulang saat breakdown ulang -> tagihan tetap ada, task_id jadi NULL lalu disinkron ulang
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'project_invoices_task_id_fkey') THEN
    ALTER TABLE public.project_invoices
      ADD CONSTRAINT project_invoices_task_id_fkey
      FOREIGN KEY (task_id) REFERENCES public.project_tasks(id) ON DELETE SET NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_project_invoices_project ON public.project_invoices(project_id, invoice_date);
