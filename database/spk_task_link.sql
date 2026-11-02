-- Integrasi SPK dengan Task Proyek.
-- Idempotent; tidak mengubah atau menghapus data SPK dan material yang sudah ada.
CREATE TABLE IF NOT EXISTS public.spk_tasks (
    id BIGSERIAL PRIMARY KEY,
    spk_id INTEGER NOT NULL REFERENCES public.spk(id) ON DELETE CASCADE,
    task_id INTEGER NOT NULL REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_spk_tasks UNIQUE (spk_id, task_id)
);

CREATE INDEX IF NOT EXISTS ix_spk_tasks_task_id ON public.spk_tasks(task_id);
CREATE INDEX IF NOT EXISTS ix_spk_tasks_spk_id ON public.spk_tasks(spk_id);
