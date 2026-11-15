-- ============================================================================
-- 20261115.year_close.sql
--
-- [GAP 6] Year close flow:
--          - year_closing.journal_id is a plain integer: there is NO FK to
--            journal_entries, so an invalid / orphan journal reference can be
--            stored and the closing cannot be audited against the actual journal
--          - year_closing has no unique constraint on fiscal_year: multiple
--            closing records per fiscal year are possible, which breaks the
--            "one year close per year" business rule
--          - journal_entries / journal_lines have no guard preventing new
--            postings when the target accounting period (or fiscal year) is
--            already closed
--
-- Evidence / business flow trace (upstream -> downstream):
--   journal_entries (+ journal_lines) -> accounting_periods
--     -> year_closing (fiscal_year, closing_date, journal_id, net_income)
--
-- Backward-compatible: ADD CONSTRAINT IF NOT EXISTS + CREATE FUNCTION /
-- TRIGGER (idempotent via DO blocks). No data is changed in place.
-- ============================================================================

BEGIN;

-- 1. FK on year_closing.journal_id -> journal_entries.id
DO $$
BEGIN
            IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.year_closing'::regclass
          AND conname = 'fk_year_closing_journal'
          AND contype = 'f'
    ) THEN
        ALTER TABLE public.year_closing
            ADD CONSTRAINT fk_year_closing_journal
                FOREIGN KEY (journal_id) REFERENCES public.journal_entries(id)
                    ON DELETE SET NULL;
    END IF;
EXCEPTION WHEN others THEN
    NULL;
END
$$;

-- 2. Unique per fiscal year (one closing record per year)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.year_closing'::regclass
          AND conname = 'uq_year_closing_fiscal_year'
          AND contype = 'u'
    ) THEN
        ALTER TABLE public.year_closing
            ADD CONSTRAINT uq_year_closing_fiscal_year
                UNIQUE (fiscal_year);
    END IF;
END
$$;

-- 3. Guard: block posting journal_entries / journal_lines for a closed
--        accounting period
CREATE OR REPLACE FUNCTION public.fn_block_journal_for_closed_period()
RETURNS trigger AS $$
BEGIN
    IF (SELECT is_closed
        FROM public.accounting_periods
        WHERE id = NEW.period_id) THEN
        RAISE EXCEPTION 'Cannot post journal for a closed accounting period (period_id = %)',
                        NEW.period_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_block_journal_for_closed_period'
          AND tgrelid = 'public.journal_entries'::regclass) THEN
        CREATE TRIGGER trg_block_journal_for_closed_period
            BEFORE INSERT OR UPDATE ON public.journal_entries
            FOR EACH ROW EXECUTE PROCEDURE public.fn_block_journal_for_closed_period();
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION public.fn_block_journallines_for_closed_period()
RETURNS trigger AS $$
DECLARE
    v_period_id integer;
BEGIN
    SELECT je.period_id INTO v_period_id
      FROM public.journal_entries je
     WHERE je.id = NEW.journal_id;

    IF v_period_id IS NOT NULL AND
       (SELECT is_closed FROM public.accounting_periods WHERE id = v_period_id) THEN
        RAISE EXCEPTION 'Cannot post journal_line for a journal whose accounting period is closed (period_id = %)',
                        v_period_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_block_journallines_for_closed_period'
          AND tgrelid = 'public.journal_lines'::regclass) THEN
        CREATE TRIGGER trg_block_journallines_for_closed_period
            BEFORE INSERT OR UPDATE ON public.journal_lines
            FOR EACH ROW EXECUTE PROCEDURE public.fn_block_journallines_for_closed_period();
    END IF;
END
$$;

COMMIT;
