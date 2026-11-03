-- ============================================================
-- Task-level RAB allocation and SPK allocation foundation
-- Additive + idempotent. Existing project/RAB/SPK/expense records
-- are preserved; legacy SPK material lines are NOT auto-assigned
-- to tasks because the intended task split cannot be inferred.
-- ============================================================

CREATE TABLE IF NOT EXISTS public.task_materials (
    id BIGSERIAL PRIMARY KEY,
    task_id INTEGER NOT NULL REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    budget_item_id INTEGER NOT NULL REFERENCES public.budget_items(id) ON DELETE RESTRICT,
    qty_budget NUMERIC(18,4) NOT NULL CHECK (qty_budget > 0),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_task_materials_task_budget UNIQUE (task_id, budget_item_id)
);

CREATE INDEX IF NOT EXISTS ix_task_materials_budget_item
    ON public.task_materials(budget_item_id);
CREATE INDEX IF NOT EXISTS ix_task_materials_task
    ON public.task_materials(task_id);

CREATE TABLE IF NOT EXISTS public.task_expenses (
    id BIGSERIAL PRIMARY KEY,
    task_id INTEGER NOT NULL REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    budget_item_id INTEGER NOT NULL REFERENCES public.budget_items(id) ON DELETE RESTRICT,
    amount_budget NUMERIC(18,2) NOT NULL CHECK (amount_budget > 0),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_task_expenses_task_budget UNIQUE (task_id, budget_item_id)
);

CREATE INDEX IF NOT EXISTS ix_task_expenses_budget_item
    ON public.task_expenses(budget_item_id);
CREATE INDEX IF NOT EXISTS ix_task_expenses_task
    ON public.task_expenses(task_id);

-- One SPK can cover many tasks; the same task may be covered by many SPKs.
-- Quantity/value per task is stored in the following allocation tables.
CREATE TABLE IF NOT EXISTS public.spk_task_materials (
    id BIGSERIAL PRIMARY KEY,
    spk_task_id BIGINT NOT NULL REFERENCES public.spk_tasks(id) ON DELETE CASCADE,
    task_material_id BIGINT NOT NULL REFERENCES public.task_materials(id) ON DELETE RESTRICT,
    qty NUMERIC(18,4) NOT NULL CHECK (qty > 0),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_spk_task_materials UNIQUE (spk_task_id, task_material_id)
);

CREATE INDEX IF NOT EXISTS ix_spk_task_materials_task_material
    ON public.spk_task_materials(task_material_id);

CREATE TABLE IF NOT EXISTS public.spk_task_expenses (
    id BIGSERIAL PRIMARY KEY,
    spk_task_id BIGINT NOT NULL REFERENCES public.spk_tasks(id) ON DELETE CASCADE,
    task_expense_id BIGINT NOT NULL REFERENCES public.task_expenses(id) ON DELETE RESTRICT,
    amount NUMERIC(18,2) NOT NULL CHECK (amount > 0),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_spk_task_expenses UNIQUE (spk_task_id, task_expense_id)
);

CREATE INDEX IF NOT EXISTS ix_spk_task_expenses_task_expense
    ON public.spk_task_expenses(task_expense_id);

-- A single financial expense transaction can be split over several tasks
-- and/or SPKs. The amount of all allocations cannot exceed the transaction.
CREATE TABLE IF NOT EXISTS public.financial_expense_allocations (
    id BIGSERIAL PRIMARY KEY,
    financial_expense_id INTEGER NOT NULL REFERENCES public.financial_expenses(id) ON DELETE CASCADE,
    task_expense_id BIGINT NOT NULL REFERENCES public.task_expenses(id) ON DELETE RESTRICT,
    spk_task_id BIGINT NULL REFERENCES public.spk_tasks(id) ON DELETE RESTRICT,
    amount NUMERIC(18,2) NOT NULL CHECK (amount > 0),
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_fin_exp_alloc_expense
    ON public.financial_expense_allocations(financial_expense_id);
CREATE INDEX IF NOT EXISTS ix_fin_exp_alloc_task_expense
    ON public.financial_expense_allocations(task_expense_id);
CREATE INDEX IF NOT EXISTS ix_fin_exp_alloc_spk_task
    ON public.financial_expense_allocations(spk_task_id);

-- Ensure each SPK-Task link uses a task from the same project as its SPK.
CREATE OR REPLACE FUNCTION public.fn_validate_spk_task_project()
RETURNS TRIGGER AS $
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM public.spk s
          JOIN public.project_tasks t ON t.id = NEW.task_id
         WHERE s.id = NEW.spk_id
           AND s.project_id = t.project_id
    ) THEN
        RAISE EXCEPTION 'SPK dan Task harus berasal dari proyek yang sama.';
    END IF;
    RETURN NEW;
END;
$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_task_project ON public.spk_tasks;
CREATE TRIGGER trg_validate_spk_task_project
BEFORE INSERT OR UPDATE ON public.spk_tasks
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_task_project();

-- Validate that RAB budget lines are allocated only to tasks of the same project
-- and to the matching item category.
CREATE OR REPLACE FUNCTION public.fn_validate_task_budget_allocation()
RETURNS TRIGGER AS $$
DECLARE
    v_task_project INTEGER;
    v_budget_project INTEGER;
    v_category VARCHAR(50);
    v_material_id INTEGER;
    v_budget_qty NUMERIC(18,4);
    v_budget_amount NUMERIC(18,2);
    v_allocated NUMERIC(18,4);
BEGIN
    SELECT project_id INTO v_task_project
      FROM public.project_tasks WHERE id = NEW.task_id;
    IF v_task_project IS NULL THEN
        RAISE EXCEPTION 'Task proyek tidak ditemukan.';
    END IF;

    SELECT project_id, category, material_id,
           COALESCE(qty_plan,0),
           COALESCE(qty_plan,0) * COALESCE(unit_price_plan,0)
      INTO v_budget_project, v_category, v_material_id, v_budget_qty, v_budget_amount
      FROM public.budget_items
     WHERE id = NEW.budget_item_id
     FOR UPDATE;

    IF v_budget_project IS NULL THEN
        RAISE EXCEPTION 'Item RAB tidak ditemukan.';
    END IF;
    IF v_task_project <> v_budget_project THEN
        RAISE EXCEPTION 'Task dan item RAB harus berasal dari proyek yang sama.';
    END IF;

    IF TG_TABLE_NAME = 'task_materials' THEN
        IF v_category <> 'Material' OR v_material_id IS NULL THEN
            RAISE EXCEPTION 'Alokasi material hanya boleh menggunakan item RAB kategori Material.';
        END IF;
        SELECT COALESCE(SUM(qty_budget),0) INTO v_allocated
          FROM public.task_materials
         WHERE budget_item_id = NEW.budget_item_id
           AND (TG_OP = 'INSERT' OR id <> NEW.id);
        IF v_allocated + NEW.qty_budget > v_budget_qty + 0.00005 THEN
            RAISE EXCEPTION 'Total alokasi Task material melebihi Qty RAB.';
        END IF;
    ELSE
        IF v_category = 'Material' OR v_category IS NULL THEN
            RAISE EXCEPTION 'Alokasi expense hanya boleh menggunakan item RAB non-Material.';
        END IF;
        SELECT COALESCE(SUM(amount_budget),0) INTO v_allocated
          FROM public.task_expenses
         WHERE budget_item_id = NEW.budget_item_id
           AND (TG_OP = 'INSERT' OR id <> NEW.id);
        IF v_allocated + NEW.amount_budget > v_budget_amount + 0.01 THEN
            RAISE EXCEPTION 'Total alokasi Task expenses melebihi nilai RAB.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_task_materials ON public.task_materials;
CREATE TRIGGER trg_validate_task_materials
BEFORE INSERT OR UPDATE ON public.task_materials
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_task_budget_allocation();

DROP TRIGGER IF EXISTS trg_validate_task_expenses ON public.task_expenses;
CREATE TRIGGER trg_validate_task_expenses
BEFORE INSERT OR UPDATE ON public.task_expenses
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_task_budget_allocation();

-- Prevent reducing/deleting an RAB line below/in conflict with existing task allocations.
CREATE OR REPLACE FUNCTION public.fn_guard_budget_item_task_allocations()
RETURNS TRIGGER AS $$
DECLARE
    v_material_alloc NUMERIC(18,4);
    v_expense_alloc NUMERIC(18,2);
BEGIN
    IF TG_OP = 'DELETE' THEN
        SELECT COALESCE(SUM(qty_budget),0) INTO v_material_alloc
          FROM public.task_materials WHERE budget_item_id = OLD.id;
        SELECT COALESCE(SUM(amount_budget),0) INTO v_expense_alloc
          FROM public.task_expenses WHERE budget_item_id = OLD.id;
        IF v_material_alloc > 0 OR v_expense_alloc > 0 THEN
            RAISE EXCEPTION 'Item RAB tidak dapat dihapus karena sudah dialokasikan ke Task.';
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.project_id <> OLD.project_id OR NEW.category <> OLD.category
       OR NEW.material_id IS DISTINCT FROM OLD.material_id
       OR NEW.expense_id IS DISTINCT FROM OLD.expense_id THEN
        IF EXISTS (SELECT 1 FROM public.task_materials WHERE budget_item_id = OLD.id)
           OR EXISTS (SELECT 1 FROM public.task_expenses WHERE budget_item_id = OLD.id) THEN
            RAISE EXCEPTION 'Identitas item RAB tidak dapat diubah selama masih memiliki alokasi Task.';
        END IF;
    END IF;

    SELECT COALESCE(SUM(qty_budget),0) INTO v_material_alloc
      FROM public.task_materials WHERE budget_item_id = OLD.id;
    SELECT COALESCE(SUM(amount_budget),0) INTO v_expense_alloc
      FROM public.task_expenses WHERE budget_item_id = OLD.id;

    IF NEW.category = 'Material' AND v_material_alloc > COALESCE(NEW.qty_plan,0) + 0.00005 THEN
        RAISE EXCEPTION 'Qty RAB tidak boleh lebih kecil dari total alokasi material Task.';
    END IF;
    IF NEW.category <> 'Material'
       AND v_expense_alloc > COALESCE(NEW.qty_plan,0) * COALESCE(NEW.unit_price_plan,0) + 0.01 THEN
        RAISE EXCEPTION 'Nilai RAB tidak boleh lebih kecil dari total alokasi expenses Task.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_guard_budget_item_task_allocations ON public.budget_items;
CREATE TRIGGER trg_guard_budget_item_task_allocations
BEFORE UPDATE OR DELETE ON public.budget_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_guard_budget_item_task_allocations();

-- SPK material allocation: task must belong to the SPK, the material must exist
-- on the SPK header, and cumulative SPK quantity cannot exceed task allocation.
CREATE OR REPLACE FUNCTION public.fn_validate_spk_task_material()
RETURNS TRIGGER AS $$
DECLARE
    v_task_id INTEGER;
    v_spk_id INTEGER;
    v_project_id INTEGER;
    v_material_id INTEGER;
    v_task_material_qty NUMERIC(18,4);
    v_spk_material_qty NUMERIC(18,4);
    v_allocated NUMERIC(18,4);
BEGIN
    SELECT st.task_id, st.spk_id INTO v_task_id, v_spk_id
      FROM public.spk_tasks st WHERE st.id = NEW.spk_task_id;
    IF v_task_id IS NULL THEN RAISE EXCEPTION 'Relasi SPK-Task tidak ditemukan.'; END IF;

    SELECT tm.qty_budget, bi.material_id, t.project_id
      INTO v_task_material_qty, v_material_id, v_project_id
      FROM public.task_materials tm
      JOIN public.budget_items bi ON bi.id = tm.budget_item_id
      JOIN public.project_tasks t ON t.id = tm.task_id
     WHERE tm.id = NEW.task_material_id
       AND tm.task_id = v_task_id
     FOR UPDATE OF tm;
    IF v_task_material_qty IS NULL THEN
        RAISE EXCEPTION 'Item material tidak dialokasikan ke Task pada RAB.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM public.spk s WHERE s.id = v_spk_id AND s.project_id = v_project_id) THEN
        RAISE EXCEPTION 'SPK dan Task material harus berasal dari proyek yang sama.';
    END IF;

    PERFORM 1 FROM public.spk_items
     WHERE spk_id = v_spk_id AND material_id = v_material_id
     FOR UPDATE;
    SELECT COALESCE(SUM(qty),0) INTO v_spk_material_qty
      FROM public.spk_items WHERE spk_id = v_spk_id AND material_id = v_material_id;
    IF v_spk_material_qty <= 0 THEN
        RAISE EXCEPTION 'Material belum tercantum pada detail SPK.';
    END IF;

    SELECT COALESCE(SUM(qty),0) INTO v_allocated
      FROM public.spk_task_materials
     WHERE task_material_id = NEW.task_material_id
       AND (TG_OP = 'INSERT' OR id <> NEW.id);
    IF v_allocated + NEW.qty > v_task_material_qty + 0.00005 THEN
        RAISE EXCEPTION 'Total Qty SPK untuk Task melebihi alokasi material Task.';
    END IF;

    SELECT COALESCE(SUM(stm.qty),0) INTO v_allocated
      FROM public.spk_task_materials stm
      JOIN public.spk_tasks st ON st.id = stm.spk_task_id
     WHERE st.spk_id = v_spk_id
       AND stm.task_material_id = NEW.task_material_id
       AND (TG_OP = 'INSERT' OR stm.id <> NEW.id);
    IF v_allocated + NEW.qty > v_spk_material_qty + 0.00005 THEN
        RAISE EXCEPTION 'Total alokasi Task melebihi Qty material pada SPK.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_task_material ON public.spk_task_materials;
CREATE TRIGGER trg_validate_spk_task_material
BEFORE INSERT OR UPDATE ON public.spk_task_materials
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_task_material();

CREATE OR REPLACE FUNCTION public.fn_validate_spk_task_expense()
RETURNS TRIGGER AS $$
DECLARE
    v_task_id INTEGER;
    v_spk_id INTEGER;
    v_project_id INTEGER;
    v_task_expense_amount NUMERIC(18,2);
    v_allocated NUMERIC(18,2);
BEGIN
    SELECT st.task_id, st.spk_id INTO v_task_id, v_spk_id
      FROM public.spk_tasks st WHERE st.id = NEW.spk_task_id;
    IF v_task_id IS NULL THEN RAISE EXCEPTION 'Relasi SPK-Task tidak ditemukan.'; END IF;

    SELECT te.amount_budget, t.project_id INTO v_task_expense_amount, v_project_id
      FROM public.task_expenses te
      JOIN public.project_tasks t ON t.id = te.task_id
     WHERE te.id = NEW.task_expense_id AND te.task_id = v_task_id
     FOR UPDATE OF te;
    IF v_task_expense_amount IS NULL THEN
        RAISE EXCEPTION 'Expense belum dialokasikan ke Task pada RAB.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.spk s WHERE s.id = v_spk_id AND s.project_id = v_project_id) THEN
        RAISE EXCEPTION 'SPK dan Task expense harus berasal dari proyek yang sama.';
    END IF;

    SELECT COALESCE(SUM(amount),0) INTO v_allocated
      FROM public.spk_task_expenses
     WHERE task_expense_id = NEW.task_expense_id
       AND (TG_OP = 'INSERT' OR id <> NEW.id);
    IF v_allocated + NEW.amount > v_task_expense_amount + 0.01 THEN
        RAISE EXCEPTION 'Total nilai SPK untuk Task expenses melebihi alokasi anggaran Task.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_task_expense ON public.spk_task_expenses;
CREATE TRIGGER trg_validate_spk_task_expense
BEFORE INSERT OR UPDATE ON public.spk_task_expenses
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_task_expense();

-- Actual financial expenses may be split over task/SPK allocations, but may
-- never be allocated beyond the amount of the source transaction.
CREATE OR REPLACE FUNCTION public.fn_validate_financial_expense_allocation()
RETURNS TRIGGER AS $$
DECLARE
    v_expense_project INTEGER;
    v_task_project INTEGER;
    v_task_id INTEGER;
    v_spk_task_id BIGINT;
    v_allocated NUMERIC(18,2);
    v_source_amount NUMERIC(18,2);
BEGIN
    SELECT project_id, amount INTO v_expense_project, v_source_amount
      FROM public.financial_expenses
     WHERE id = NEW.financial_expense_id
     FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Transaksi financial expense tidak ditemukan.'; END IF;

    SELECT te.task_id, t.project_id INTO v_task_id, v_task_project
      FROM public.task_expenses te
      JOIN public.project_tasks t ON t.id = te.task_id
     WHERE te.id = NEW.task_expense_id;
    IF v_task_id IS NULL THEN RAISE EXCEPTION 'Alokasi RAB expenses untuk Task tidak ditemukan.'; END IF;
    IF v_expense_project IS NOT NULL AND v_expense_project <> v_task_project THEN
        RAISE EXCEPTION 'Transaksi expense dan Task harus berasal dari proyek yang sama.';
    END IF;

    IF NEW.spk_task_id IS NOT NULL THEN
        SELECT task_id INTO v_spk_task_id
          FROM public.spk_tasks WHERE id = NEW.spk_task_id;
        IF v_spk_task_id IS NULL OR v_spk_task_id <> v_task_id THEN
            RAISE EXCEPTION 'SPK yang dipilih tidak terhubung dengan Task expense ini.';
        END IF;
    END IF;

    SELECT COALESCE(SUM(amount),0) INTO v_allocated
      FROM public.financial_expense_allocations
     WHERE financial_expense_id = NEW.financial_expense_id
       AND (TG_OP = 'INSERT' OR id <> NEW.id);
    IF v_allocated + NEW.amount > v_source_amount + 0.01 THEN
        RAISE EXCEPTION 'Total alokasi melebihi nilai transaksi financial expense.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_financial_expense_allocation ON public.financial_expense_allocations;
CREATE TRIGGER trg_validate_financial_expense_allocation
BEFORE INSERT OR UPDATE ON public.financial_expense_allocations
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_financial_expense_allocation();

COMMENT ON TABLE public.task_materials IS 'Alokasi item RAB Material ke Task; satu item RAB boleh dibagi ke banyak Task.';
COMMENT ON TABLE public.task_expenses IS 'Alokasi nilai item RAB non-Material ke Task.';
COMMENT ON TABLE public.spk_task_materials IS 'Alokasi Qty material SPK per Task; satu Task dapat dikerjakan melalui beberapa SPK.';
COMMENT ON TABLE public.spk_task_expenses IS 'Alokasi nilai expenses SPK per Task.';
COMMENT ON TABLE public.financial_expense_allocations IS 'Pembagian transaksi financial_expenses aktual ke RAB Expense, Task, dan opsional SPK.';
