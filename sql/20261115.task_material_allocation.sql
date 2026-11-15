-- ============================================================================
-- 20261115.task_material_allocation.sql
--
-- [GAP 4] Stock / Material task planning flow:
--          - task_materials has no active/deleted flag and no rule that the
--            linked budget_item must be a Material category
--          - spk_task_materials has no rule that allocated qty cannot exceed the
--            task's material allocation (task_materials.qty_budget)
--          - spk task / task material allocation is therefore not enforceably
--            bounded, breaking the RAB -> Task -> SPK material flow
--
-- Evidence / business flow trace (upstream -> downstream):
--   project_tasks -> task_materials -> budget_items
--   spk_tasks -> spk_task_materials -> task_materials
--
-- Backward-compatible: ADD COLUMN IF NOT EXISTS + CREATE FUNCTION / TRIGGER
-- (idempotent via DO block). No data is changed in place.
-- ============================================================================

BEGIN;

-- 1. Active flag on task_materials (soft-delete / phased-out items)
ALTER TABLE public.task_materials
    ADD COLUMN IF NOT EXISTS active boolean NOT NULL DEFAULT true;

-- 2. Rule: a material task allocation can only reference a Material category
CREATE OR REPLACE FUNCTION public.fn_check_task_material_category()
RETURNS trigger AS $$
BEGIN
    IF NEW.budget_item_id IS NOT NULL THEN
        PERFORM id
          FROM public.budget_items
         WHERE id = NEW.budget_item_id
           AND category <> 'Material';
        IF FOUND THEN
            RAISE EXCEPTION 'task_materials can only reference budget_items with category = Material (budget_item_id = %)',
                            NEW.budget_item_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_task_material_category'
          AND tgrelid = 'public.task_materials'::regclass) THEN
        CREATE TRIGGER trg_check_task_material_category
            BEFORE INSERT OR UPDATE ON public.task_materials
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_task_material_category();
    END IF;
END
$$;

-- 3. Rule: spk_task_materials allocated qty cannot exceed the task's material
--        allocation (task_materials.qty_budget)
CREATE OR REPLACE FUNCTION public.fn_check_spk_task_material_alloc()
RETURNS trigger AS $$
DECLARE
    v_task_qty numeric(18,4);
BEGIN
    SELECT COALESCE(tm.qty_budget, 0) INTO v_task_qty
      FROM public.task_materials tm
     WHERE tm.id = NEW.task_material_id AND tm.active;

    IF v_task_qty <= 0 THEN
        RAISE EXCEPTION 'No active task_materials allocation found for task_material_id = % (spk_task_materials qty = %.4F)',
                        NEW.task_material_id, NEW.qty;
    END IF;

    IF NEW.qty > v_task_qty + 0.001 THEN
        RAISE EXCEPTION 'spk_task_materials qty (%.4F) exceeds task material allocation (%.4F)',
                        NEW.qty, v_task_qty;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_spk_task_material_alloc'
          AND tgrelid = 'public.spk_task_materials'::regclass) THEN
        CREATE TRIGGER trg_check_spk_task_material_alloc
            BEFORE INSERT OR UPDATE OF qty
            ON public.spk_task_materials
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_spk_task_material_alloc();
    END IF;
END
$$;

COMMIT;
