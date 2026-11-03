-- ================================================================
-- RAB-PROJECT | SUBCONTRACT MANAGEMENT | ADDITIVE MIGRATION
-- Based on schema export supplied 2026-11-03 (proyekGMN)
-- PostgreSQL. Execute on STAGING first.
--
-- Existing tables reused:
--   spk, spk_items, spk_tasks, project_tasks, budget_items
--   subcontractors, subcon_contracts, subcon_contract_tasks
--   subcon_progress, subcon_progress_dtl, subcon_invoices
--   subcon_payments, subcon_invoice_payments
--   material_leftover_header/items, material_out_header/items,
--   materials, warehouses, stock_movements
--
-- No legacy table is dropped or renamed. spk_items remains warehouse
-- issue entitlement. Existing payment paths are not merged in this DDL.
-- ================================================================

BEGIN;

-- 01. SPK master: same SPK identity supports internal and subcontract work.
ALTER TABLE public.spk
    ADD COLUMN IF NOT EXISTS spk_type varchar(12) NOT NULL DEFAULT 'INTERNAL',
    ADD COLUMN IF NOT EXISTS execution_subcontractor_id integer,
    ADD COLUMN IF NOT EXISTS work_start_date date,
    ADD COLUMN IF NOT EXISTS work_end_date date,
    ADD COLUMN IF NOT EXISTS parent_spk_id integer,
    ADD COLUMN IF NOT EXISTS revision_no integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS addendum_no varchar(50),
    ADD COLUMN IF NOT EXISTS approved_by integer,
    ADD COLUMN IF NOT EXISTS approved_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS cancelled_by integer,
    ADD COLUMN IF NOT EXISTS cancelled_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS cancel_reason text;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_spk_type') THEN
        ALTER TABLE public.spk ADD CONSTRAINT ck_spk_type
            CHECK (spk_type IN ('INTERNAL','SUBCON'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_spk_execution_subcontractor') THEN
        ALTER TABLE public.spk ADD CONSTRAINT fk_spk_execution_subcontractor
            FOREIGN KEY (execution_subcontractor_id)
            REFERENCES public.subcontractors(id) ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_spk_execution_party') THEN
        ALTER TABLE public.spk ADD CONSTRAINT ck_spk_execution_party
            CHECK ((spk_type='INTERNAL' AND execution_subcontractor_id IS NULL)
                OR (spk_type='SUBCON' AND execution_subcontractor_id IS NOT NULL));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_spk_work_dates') THEN
        ALTER TABLE public.spk ADD CONSTRAINT ck_spk_work_dates
            CHECK (work_end_date IS NULL OR work_start_date IS NULL OR work_end_date >= work_start_date);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_spk_parent_spk') THEN
        ALTER TABLE public.spk ADD CONSTRAINT fk_spk_parent_spk
            FOREIGN KEY (parent_spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_spk_type_project ON public.spk(spk_type, project_id);
CREATE INDEX IF NOT EXISTS ix_spk_execution_subcontractor ON public.spk(execution_subcontractor_id);
CREATE INDEX IF NOT EXISTS ix_spk_parent ON public.spk(parent_spk_id);

-- 02. Existing Subcon contract becomes commercial detail of one SUBCON SPK.
-- Existing spk_no remains untouched for backward compatibility.
ALTER TABLE public.subcon_contracts
    ADD COLUMN IF NOT EXISTS spk_id integer,
    ADD COLUMN IF NOT EXISTS contract_revision integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS material_supply_default varchar(12) NOT NULL DEFAULT 'SUBCON';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_subcon_contract_spk') THEN
        ALTER TABLE public.subcon_contracts ADD CONSTRAINT fk_subcon_contract_spk
            FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_subcon_material_supply_default') THEN
        ALTER TABLE public.subcon_contracts ADD CONSTRAINT ck_subcon_material_supply_default
            CHECK (material_supply_default IN ('COMPANY','SUBCON','MIXED'));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_subcon_contract_spk
    ON public.subcon_contracts(spk_id) WHERE spk_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_subcon_contract_status_project
    ON public.subcon_contracts(project_id, status);

CREATE OR REPLACE FUNCTION public.fn_validate_subcon_contract_spk()
RETURNS trigger AS $$
BEGIN
    IF NEW.spk_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.spk s
         WHERE s.id=NEW.spk_id
           AND s.project_id=NEW.project_id
           AND s.spk_type='SUBCON'
           AND s.execution_subcontractor_id=NEW.subcontractor_id
    ) THEN
        RAISE EXCEPTION 'Kontrak Subcon harus terhubung ke SPK SUBCON, proyek dan Subcon yang sama.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_subcon_contract_spk ON public.subcon_contracts;
CREATE TRIGGER trg_validate_subcon_contract_spk
BEFORE INSERT OR UPDATE OF spk_id, project_id, subcontractor_id
ON public.subcon_contracts
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_contract_spk();

-- 03. Common measurable work scope for INTERNAL and SUBCON SPK.
-- Separate from spk_items (warehouse material issue authorization).
CREATE TABLE IF NOT EXISTS public.spk_work_items (
    id              bigserial PRIMARY KEY,
    spk_id          integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    project_task_id integer REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    budget_item_id  integer REFERENCES public.budget_items(id) ON DELETE RESTRICT,
    work_code       varchar(50),
    description     text NOT NULL,
    unit            varchar(30) NOT NULL,
    contract_qty    numeric(18,4) NOT NULL CHECK (contract_qty > 0),
    unit_price      numeric(18,2) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    line_amount     numeric(18,2) NOT NULL DEFAULT 0 CHECK (line_amount >= 0),
    material_supply varchar(12) NOT NULL DEFAULT 'COMPANY'
                    CHECK (material_supply IN ('COMPANY','SUBCON','MIXED')),
    sort_order      integer NOT NULL DEFAULT 0,
    status          varchar(15) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE','COMPLETED','CANCELLED')),
    notes           text,
    created_by      integer,
    created_at      timestamp without time zone NOT NULL DEFAULT now(),
    updated_at      timestamp without time zone NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_spk_work_items_spk ON public.spk_work_items(spk_id);
CREATE INDEX IF NOT EXISTS ix_spk_work_items_task ON public.spk_work_items(project_task_id);
CREATE INDEX IF NOT EXISTS ix_spk_work_items_budget ON public.spk_work_items(budget_item_id);

CREATE OR REPLACE FUNCTION public.fn_validate_spk_work_item_project()
RETURNS trigger AS $$
DECLARE v_project_id integer;
BEGIN
    SELECT project_id INTO v_project_id FROM public.spk WHERE id=NEW.spk_id;
    IF v_project_id IS NULL THEN
        RAISE EXCEPTION 'SPK % tidak ditemukan.', NEW.spk_id;
    END IF;
    IF NEW.project_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.project_tasks t
         WHERE t.id=NEW.project_task_id AND t.project_id=v_project_id
    ) THEN
        RAISE EXCEPTION 'Task harus berasal dari proyek yang sama dengan SPK.';
    END IF;
    IF NEW.budget_item_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.budget_items b
         WHERE b.id=NEW.budget_item_id AND b.project_id=v_project_id
    ) THEN
        RAISE EXCEPTION 'Item RAB harus berasal dari proyek yang sama dengan SPK.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_work_item_project ON public.spk_work_items;
CREATE TRIGGER trg_validate_spk_work_item_project
BEFORE INSERT OR UPDATE OF spk_id, project_task_id, budget_item_id
ON public.spk_work_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_work_item_project();

-- Link current Subcon task detail to the common work scope; legacy fields remain.
ALTER TABLE public.subcon_contract_tasks
    ADD COLUMN IF NOT EXISTS work_item_id bigint;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_subcon_contract_task_work_item') THEN
        ALTER TABLE public.subcon_contract_tasks ADD CONSTRAINT fk_subcon_contract_task_work_item
            FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;
    END IF;
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS uq_subcon_contract_task_work_item
    ON public.subcon_contract_tasks(work_item_id) WHERE work_item_id IS NOT NULL;

CREATE OR REPLACE FUNCTION public.fn_validate_subcon_task_work_item()
RETURNS trigger AS $$
DECLARE v_contract_project integer; v_spk_project integer; v_spk_type varchar(12);
BEGIN
    IF NEW.work_item_id IS NULL THEN RETURN NEW; END IF;
    SELECT c.project_id INTO v_contract_project
      FROM public.subcon_contracts c WHERE c.id=NEW.contract_id;
    SELECT s.project_id, s.spk_type INTO v_spk_project, v_spk_type
      FROM public.spk_work_items wi JOIN public.spk s ON s.id=wi.spk_id
     WHERE wi.id=NEW.work_item_id;
    IF v_contract_project IS NULL OR v_spk_project IS DISTINCT FROM v_contract_project
       OR v_spk_type IS DISTINCT FROM 'SUBCON' THEN
        RAISE EXCEPTION 'Work item harus berasal dari SPK SUBCON pada proyek kontrak yang sama.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_subcon_task_work_item ON public.subcon_contract_tasks;
CREATE TRIGGER trg_validate_subcon_task_work_item
BEFORE INSERT OR UPDATE OF contract_id, work_item_id
ON public.subcon_contract_tasks
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_task_work_item();

-- 04. Quantity claim detail. Existing subcon_progress remains the header.
CREATE TABLE IF NOT EXISTS public.subcon_progress_qty_items (
    id                  bigserial PRIMARY KEY,
    progress_id         integer NOT NULL REFERENCES public.subcon_progress(id) ON DELETE RESTRICT,
    contract_task_id    integer NOT NULL REFERENCES public.subcon_contract_tasks(id) ON DELETE RESTRICT,
    work_item_id        bigint NOT NULL REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    qty_claimed         numeric(18,4) NOT NULL CHECK (qty_claimed > 0),
    unit_price_snapshot numeric(18,2) NOT NULL CHECK (unit_price_snapshot >= 0),
    amount_claimed      numeric(18,2) NOT NULL CHECK (amount_claimed >= 0),
    notes               text,
    created_at          timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT uq_progress_qty_task UNIQUE(progress_id, contract_task_id)
);
CREATE INDEX IF NOT EXISTS ix_progress_qty_progress ON public.subcon_progress_qty_items(progress_id);
CREATE INDEX IF NOT EXISTS ix_progress_qty_work_item ON public.subcon_progress_qty_items(work_item_id);

CREATE OR REPLACE FUNCTION public.fn_validate_progress_qty_item()
RETURNS trigger AS $$
DECLARE v_contract_id integer; v_task_contract_id integer; v_work_item_id bigint;
BEGIN
    SELECT contract_id INTO v_contract_id FROM public.subcon_progress WHERE id=NEW.progress_id;
    SELECT contract_id, work_item_id INTO v_task_contract_id, v_work_item_id
      FROM public.subcon_contract_tasks WHERE id=NEW.contract_task_id;
    IF v_contract_id IS NULL OR v_contract_id <> v_task_contract_id THEN
        RAISE EXCEPTION 'Task detail dan progress harus berasal dari kontrak Subcon yang sama.';
    END IF;
    IF v_work_item_id IS NULL OR v_work_item_id <> NEW.work_item_id THEN
        RAISE EXCEPTION 'Work item harus sama dengan mapping pada subcon_contract_tasks.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_progress_qty_item ON public.subcon_progress_qty_items;
CREATE TRIGGER trg_validate_progress_qty_item
BEFORE INSERT OR UPDATE OF progress_id, contract_task_id, work_item_id
ON public.subcon_progress_qty_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_progress_qty_item();

-- 05. Inspections: only approved qty_passed can be certified/paid.
CREATE TABLE IF NOT EXISTS public.subcon_inspections (
    id              bigserial PRIMARY KEY,
    inspection_no   varchar(50) NOT NULL UNIQUE,
    progress_id     integer NOT NULL REFERENCES public.subcon_progress(id) ON DELETE RESTRICT,
    inspection_date date NOT NULL,
    inspector_id    integer,
    status          varchar(15) NOT NULL DEFAULT 'DRAFT'
                    CHECK (status IN ('DRAFT','APPROVED','REJECTED','CANCELLED')),
    general_result  text,
    evidence_ref    text,
    created_by      integer,
    created_at      timestamp without time zone NOT NULL DEFAULT now(),
    approved_by     integer,
    approved_at     timestamp without time zone,
    cancelled_by    integer,
    cancelled_at    timestamp without time zone,
    cancel_reason   text
);
CREATE INDEX IF NOT EXISTS ix_subcon_inspections_progress ON public.subcon_inspections(progress_id);
CREATE INDEX IF NOT EXISTS ix_subcon_inspections_status_date ON public.subcon_inspections(status, inspection_date);

CREATE TABLE IF NOT EXISTS public.subcon_inspection_items (
    id                   bigserial PRIMARY KEY,
    inspection_id        bigint NOT NULL REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT,
    progress_qty_item_id bigint NOT NULL REFERENCES public.subcon_progress_qty_items(id) ON DELETE RESTRICT,
    qty_inspected        numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_inspected >= 0),
    qty_passed           numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_passed >= 0),
    qty_rejected         numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_rejected >= 0),
    qty_rework           numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_rework >= 0),
    result               varchar(12) NOT NULL DEFAULT 'PENDING'
                         CHECK (result IN ('PASS','PARTIAL','REJECT','REWORK','PENDING')),
    evidence_ref         text,
    notes                text,
    created_at           timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT ck_inspection_qty_sum
        CHECK (qty_passed + qty_rejected + qty_rework <= qty_inspected)
);
CREATE INDEX IF NOT EXISTS ix_subcon_inspection_items_header ON public.subcon_inspection_items(inspection_id);
CREATE INDEX IF NOT EXISTS ix_subcon_inspection_items_claim ON public.subcon_inspection_items(progress_qty_item_id);

CREATE OR REPLACE FUNCTION public.fn_validate_inspection_item()
RETURNS trigger AS $$
DECLARE v_claimed numeric(18,4); v_progress_id integer; v_inspection_progress_id integer;
BEGIN
    SELECT qty_claimed INTO v_claimed FROM public.subcon_progress_qty_items
     WHERE id=NEW.progress_qty_item_id FOR UPDATE;
    SELECT progress_id INTO v_progress_id FROM public.subcon_progress_qty_items
     WHERE id=NEW.progress_qty_item_id;
    SELECT progress_id INTO v_inspection_progress_id FROM public.subcon_inspections
     WHERE id=NEW.inspection_id;
    IF v_claimed IS NULL OR v_progress_id IS DISTINCT FROM v_inspection_progress_id THEN
        RAISE EXCEPTION 'Detail inspeksi dan klaim progres tidak cocok.';
    END IF;
    IF NEW.qty_inspected > v_claimed THEN
        RAISE EXCEPTION 'Qty diperiksa tidak boleh melebihi qty klaim.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_inspection_item ON public.subcon_inspection_items;
CREATE TRIGGER trg_validate_inspection_item
BEFORE INSERT OR UPDATE OF inspection_id, progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework
ON public.subcon_inspection_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_inspection_item();

-- 06. Payment certificate. One approved inspection can be certified incrementally.
CREATE TABLE IF NOT EXISTS public.subcon_payment_certificates (
    id               bigserial PRIMARY KEY,
    certificate_no   varchar(50) NOT NULL UNIQUE,
    contract_id      integer NOT NULL REFERENCES public.subcon_contracts(id) ON DELETE RESTRICT,
    progress_id      integer NOT NULL REFERENCES public.subcon_progress(id) ON DELETE RESTRICT,
    inspection_id    bigint NOT NULL REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT,
    certificate_date date NOT NULL,
    gross_amount     numeric(18,2) NOT NULL DEFAULT 0 CHECK (gross_amount >= 0),
    ppn_amount       numeric(18,2) NOT NULL DEFAULT 0 CHECK (ppn_amount >= 0),
    pph_amount       numeric(18,2) NOT NULL DEFAULT 0 CHECK (pph_amount >= 0),
    retention_amount numeric(18,2) NOT NULL DEFAULT 0 CHECK (retention_amount >= 0),
    dp_recovery      numeric(18,2) NOT NULL DEFAULT 0 CHECK (dp_recovery >= 0),
    other_deduction  numeric(18,2) NOT NULL DEFAULT 0 CHECK (other_deduction >= 0),
    net_payable      numeric(18,2) NOT NULL DEFAULT 0 CHECK (net_payable >= 0),
    status           varchar(15) NOT NULL DEFAULT 'DRAFT'
                     CHECK (status IN ('DRAFT','APPROVED','CANCELLED')),
    notes            text,
    created_by       integer,
    created_at       timestamp without time zone NOT NULL DEFAULT now(),
    approved_by      integer,
    approved_at      timestamp without time zone,
    cancelled_by     integer,
    cancelled_at     timestamp without time zone,
    cancel_reason    text
);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_contract ON public.subcon_payment_certificates(contract_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_progress ON public.subcon_payment_certificates(progress_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_inspection ON public.subcon_payment_certificates(inspection_id);

CREATE TABLE IF NOT EXISTS public.subcon_payment_certificate_items (
    id                  bigserial PRIMARY KEY,
    certificate_id      bigint NOT NULL REFERENCES public.subcon_payment_certificates(id) ON DELETE RESTRICT,
    inspection_item_id  bigint NOT NULL REFERENCES public.subcon_inspection_items(id) ON DELETE RESTRICT,
    work_item_id        bigint NOT NULL REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    qty_certified       numeric(18,4) NOT NULL CHECK (qty_certified > 0),
    unit_price_snapshot numeric(18,2) NOT NULL CHECK (unit_price_snapshot >= 0),
    amount_certified    numeric(18,2) NOT NULL CHECK (amount_certified >= 0),
    created_at          timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT uq_certificate_inspection_item UNIQUE(certificate_id, inspection_item_id)
);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_items_work ON public.subcon_payment_certificate_items(work_item_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_items_inspection ON public.subcon_payment_certificate_items(inspection_item_id);

CREATE OR REPLACE FUNCTION public.fn_validate_certificate_item()
RETURNS trigger AS $$
DECLARE
    v_passed numeric(18,4);
    v_inspection_id bigint;
    v_inspection_status varchar(15);
    v_cert_progress integer;
    v_claim_work_item bigint;
    v_already_certified numeric(18,4);
BEGIN
    SELECT ii.qty_passed, ii.inspection_id, i.status, pqi.work_item_id
      INTO v_passed, v_inspection_id, v_inspection_status, v_claim_work_item
      FROM public.subcon_inspection_items ii
      JOIN public.subcon_inspections i ON i.id=ii.inspection_id
      JOIN public.subcon_progress_qty_items pqi ON pqi.id=ii.progress_qty_item_id
     WHERE ii.id=NEW.inspection_item_id FOR UPDATE OF ii;
    IF v_passed IS NULL OR v_inspection_status <> 'APPROVED' THEN
        RAISE EXCEPTION 'Sertifikat hanya dapat mengambil qty dari inspeksi APPROVED.';
    END IF;
    IF v_claim_work_item <> NEW.work_item_id THEN
        RAISE EXCEPTION 'Work item sertifikat tidak sesuai dengan item inspeksi.';
    END IF;
    SELECT progress_id INTO v_cert_progress
      FROM public.subcon_payment_certificates WHERE id=NEW.certificate_id;
    IF v_cert_progress IS NULL OR v_cert_progress <> (
        SELECT progress_id FROM public.subcon_inspections WHERE id=v_inspection_id
    ) THEN
        RAISE EXCEPTION 'Sertifikat dan inspeksi harus berasal dari progress yang sama.';
    END IF;
    SELECT COALESCE(sum(qty_certified),0) INTO v_already_certified
      FROM public.subcon_payment_certificate_items ci
      JOIN public.subcon_payment_certificates c ON c.id=ci.certificate_id
     WHERE ci.inspection_item_id=NEW.inspection_item_id
       AND c.status <> 'CANCELLED'
       AND (TG_OP='INSERT' OR ci.id <> NEW.id);
    IF v_already_certified + NEW.qty_certified > v_passed THEN
        RAISE EXCEPTION 'Qty sertifikat melebihi qty lolos inspeksi yang belum disertifikasi.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_certificate_item ON public.subcon_payment_certificate_items;
CREATE TRIGGER trg_validate_certificate_item
BEFORE INSERT OR UPDATE OF certificate_id, inspection_item_id, work_item_id, qty_certified
ON public.subcon_payment_certificate_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_certificate_item();

-- The existing invoice table remains the invoice/payment workflow.
ALTER TABLE public.subcon_invoices
    ADD COLUMN IF NOT EXISTS certificate_id bigint,
    ADD COLUMN IF NOT EXISTS certificate_amount_snapshot numeric(18,2);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_subcon_invoice_certificate') THEN
        ALTER TABLE public.subcon_invoices ADD CONSTRAINT fk_subcon_invoice_certificate
            FOREIGN KEY (certificate_id)
            REFERENCES public.subcon_payment_certificates(id) ON DELETE RESTRICT;
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS ix_subcon_invoice_certificate ON public.subcon_invoices(certificate_id);

CREATE OR REPLACE FUNCTION public.fn_validate_subcon_invoice_certificate()
RETURNS trigger AS $$
DECLARE v_contract_id integer;
BEGIN
    IF NEW.certificate_id IS NULL THEN RETURN NEW; END IF;
    SELECT contract_id INTO v_contract_id
      FROM public.subcon_payment_certificates
     WHERE id=NEW.certificate_id AND status='APPROVED';
    IF v_contract_id IS NULL OR v_contract_id <> NEW.contract_id THEN
        RAISE EXCEPTION 'Invoice harus mengacu pada sertifikat APPROVED dari kontrak yang sama.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_validate_subcon_invoice_certificate ON public.subcon_invoices;
CREATE TRIGGER trg_validate_subcon_invoice_certificate
BEFORE INSERT OR UPDATE OF certificate_id, contract_id
ON public.subcon_invoices
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_invoice_certificate();

-- 07. Material supply provenance. This does not post stock movements.
CREATE TABLE IF NOT EXISTS public.spk_material_supply (
    id               bigserial PRIMARY KEY,
    spk_id           integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    work_item_id     bigint REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    material_id      integer NOT NULL REFERENCES public.materials(id) ON DELETE RESTRICT,
    supply_source    varchar(10) NOT NULL CHECK (supply_source IN ('COMPANY','SUBCON')),
    owner_type       varchar(10) NOT NULL CHECK (owner_type IN ('COMPANY','SUBCON')),
    subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty_planned      numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_planned >= 0),
    qty_received     numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    qty_accepted     numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_accepted >= 0),
    unit             varchar(30),
    source_reference varchar(100),
    notes            text,
    created_by       integer,
    created_at       timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT ck_spk_supply_owner CHECK (
        (supply_source='COMPANY' AND owner_type='COMPANY' AND subcontractor_id IS NULL)
        OR (supply_source='SUBCON' AND owner_type='SUBCON' AND subcontractor_id IS NOT NULL)
    ),
    CONSTRAINT ck_spk_supply_accepted CHECK (qty_accepted <= qty_received)
);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_spk ON public.spk_material_supply(spk_id);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_material ON public.spk_material_supply(material_id);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_subcon ON public.spk_material_supply(subcontractor_id);

-- 08. Ownership register for leftover lots. Separate from the existing
-- material_leftover_header/items, which are warehouse inventory documents.
CREATE TABLE IF NOT EXISTS public.spk_material_leftover_lots (
    id                    bigserial PRIMARY KEY,
    lot_no                varchar(50) NOT NULL UNIQUE,
    project_id            integer NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    spk_id                integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    work_item_id          bigint REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    supply_id             bigint REFERENCES public.spk_material_supply(id) ON DELETE RESTRICT,
    material_id           integer NOT NULL REFERENCES public.materials(id) ON DELETE RESTRICT,
    source_owner_type     varchar(10) NOT NULL CHECK (source_owner_type IN ('COMPANY','SUBCON')),
    source_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    current_owner_type    varchar(10) NOT NULL CHECK (current_owner_type IN ('COMPANY','SUBCON','CLIENT')),
    current_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty_origin            numeric(18,4) NOT NULL CHECK (qty_origin > 0),
    qty_available         numeric(18,4) NOT NULL CHECK (qty_available >= 0),
    unit                  varchar(30),
    condition_note        text,
    storage_location      varchar(150),
    status                varchar(15) NOT NULL DEFAULT 'AVAILABLE'
                          CHECK (status IN ('AVAILABLE','PARTIAL','EXHAUSTED','CLOSED')),
    detected_date         date NOT NULL DEFAULT CURRENT_DATE,
    created_by            integer,
    created_at            timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT ck_leftover_qty CHECK (qty_available <= qty_origin),
    CONSTRAINT ck_leftover_source_owner CHECK (
        (source_owner_type='COMPANY' AND source_subcontractor_id IS NULL)
        OR (source_owner_type='SUBCON' AND source_subcontractor_id IS NOT NULL)
    ),
    CONSTRAINT ck_leftover_current_owner CHECK (
        (current_owner_type='SUBCON' AND current_subcontractor_id IS NOT NULL)
        OR (current_owner_type IN ('COMPANY','CLIENT') AND current_subcontractor_id IS NULL)
    )
);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_project ON public.spk_material_leftover_lots(project_id);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_spk ON public.spk_material_leftover_lots(spk_id);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_material ON public.spk_material_leftover_lots(material_id);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_owner ON public.spk_material_leftover_lots(current_owner_type,current_subcontractor_id);

CREATE TABLE IF NOT EXISTS public.spk_material_leftover_transactions (
    id                    bigserial PRIMARY KEY,
    transaction_no        varchar(50) NOT NULL UNIQUE,
    leftover_lot_id       bigint NOT NULL REFERENCES public.spk_material_leftover_lots(id) ON DELETE RESTRICT,
    transaction_type      varchar(20) NOT NULL CHECK (transaction_type IN ('RETURN_COMPANY','BUY_FROM_SUBCON','SELL_TO_CLIENT')),
    transaction_date      date NOT NULL,
    seller_type           varchar(10) NOT NULL CHECK (seller_type IN ('COMPANY','SUBCON','CLIENT')),
    seller_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    buyer_type            varchar(10) NOT NULL CHECK (buyer_type IN ('COMPANY','SUBCON','CLIENT')),
    buyer_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty                   numeric(18,4) NOT NULL CHECK (qty > 0),
    unit_price            numeric(18,2) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    total_amount          numeric(18,2) NOT NULL DEFAULT 0 CHECK (total_amount >= 0),
    market_price          numeric(18,2),
    market_price_source   text,
    market_price_date     date,
    reason                text NOT NULL,
    status                varchar(15) NOT NULL DEFAULT 'DRAFT'
                          CHECK (status IN ('DRAFT','APPROVED','POSTED','CANCELLED')),
    approved_by           integer,
    approved_at           timestamp without time zone,
    posted_by             integer,
    posted_at             timestamp without time zone,
    inventory_doc_id      integer REFERENCES public.material_leftover_header(id) ON DELETE RESTRICT,
    evidence_ref          text,
    created_by            integer,
    created_at            timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT ck_leftover_seller_party CHECK (
        (seller_type='SUBCON' AND seller_subcontractor_id IS NOT NULL)
        OR (seller_type<>'SUBCON' AND seller_subcontractor_id IS NULL)
    ),
    CONSTRAINT ck_leftover_buyer_party CHECK (
        (buyer_type='SUBCON' AND buyer_subcontractor_id IS NOT NULL)
        OR (buyer_type<>'SUBCON' AND buyer_subcontractor_id IS NULL)
    )
);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_tx_lot ON public.spk_material_leftover_transactions(leftover_lot_id);
CREATE INDEX IF NOT EXISTS ix_spk_leftover_tx_status_date ON public.spk_material_leftover_transactions(status,transaction_date);

-- Validate the allowed ownership-transfer direction before posting.
CREATE OR REPLACE FUNCTION public.fn_validate_spk_leftover_transaction()
RETURNS trigger AS $$
DECLARE
    v_owner varchar(10);
    v_owner_subcon integer;
    v_available numeric(18,4);
BEGIN
    SELECT current_owner_type,current_subcontractor_id,qty_available
      INTO v_owner,v_owner_subcon,v_available
      FROM public.spk_material_leftover_lots
     WHERE id=NEW.leftover_lot_id FOR UPDATE;
    IF v_owner IS NULL THEN RAISE EXCEPTION 'Lot material sisa tidak ditemukan.'; END IF;
    IF NEW.qty > v_available THEN RAISE EXCEPTION 'Qty transaksi melebihi qty sisa tersedia.'; END IF;

    IF NEW.transaction_type='RETURN_COMPANY' THEN
        IF v_owner<>'COMPANY' OR NEW.seller_type<>'COMPANY' OR NEW.buyer_type<>'COMPANY' THEN
            RAISE EXCEPTION 'RETURN_COMPANY hanya untuk material milik perusahaan.';
        END IF;
    ELSIF NEW.transaction_type='BUY_FROM_SUBCON' THEN
        IF v_owner<>'SUBCON' OR NEW.seller_type<>'SUBCON' OR NEW.buyer_type<>'COMPANY'
           OR NEW.seller_subcontractor_id IS DISTINCT FROM v_owner_subcon THEN
            RAISE EXCEPTION 'BUY_FROM_SUBCON harus membeli dari Subcon pemilik material saat ini.';
        END IF;
    ELSIF NEW.transaction_type='SELL_TO_CLIENT' THEN
        IF v_owner<>'COMPANY' OR NEW.seller_type<>'COMPANY' OR NEW.buyer_type<>'CLIENT' THEN
            RAISE EXCEPTION 'SELL_TO_CLIENT hanya untuk material milik perusahaan.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_leftover_transaction ON public.spk_material_leftover_transactions;
CREATE TRIGGER trg_validate_spk_leftover_transaction
BEFORE INSERT OR UPDATE OF leftover_lot_id,transaction_type,seller_type,seller_subcontractor_id,
                           buyer_type,buyer_subcontractor_id,qty
ON public.spk_material_leftover_transactions
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_leftover_transaction();

-- Apply ownership/quantity once, only on transition into POSTED.
-- Inventory receipt/issue itself must be created in the existing inventory
-- workflow in the SAME application transaction; this trigger does not fake stock.
CREATE OR REPLACE FUNCTION public.fn_post_spk_leftover_transaction()
RETURNS trigger AS $$
DECLARE v_new_owner varchar(10); v_new_subcon integer;
BEGIN
    IF NEW.status='POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
        IF NEW.transaction_type='BUY_FROM_SUBCON' THEN
            v_new_owner := 'COMPANY'; v_new_subcon := NULL;
        ELSIF NEW.transaction_type='SELL_TO_CLIENT' THEN
            v_new_owner := 'CLIENT'; v_new_subcon := NULL;
        ELSE
            v_new_owner := 'COMPANY'; v_new_subcon := NULL;
        END IF;

        UPDATE public.spk_material_leftover_lots
           SET qty_available=qty_available-NEW.qty,
               current_owner_type=CASE WHEN qty_available-NEW.qty=0 THEN current_owner_type ELSE v_new_owner END,
               current_subcontractor_id=CASE WHEN qty_available-NEW.qty=0 THEN current_subcontractor_id ELSE v_new_subcon END,
               status=CASE WHEN qty_available-NEW.qty=0 THEN 'EXHAUSTED' ELSE 'PARTIAL' END
         WHERE id=NEW.leftover_lot_id AND qty_available>=NEW.qty;
        IF NOT FOUND THEN RAISE EXCEPTION 'Qty lot tidak cukup atau sudah berubah.'; END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_post_spk_leftover_transaction ON public.spk_material_leftover_transactions;
CREATE TRIGGER trg_post_spk_leftover_transaction
AFTER UPDATE OF status ON public.spk_material_leftover_transactions
FOR EACH ROW EXECUTE PROCEDURE public.fn_post_spk_leftover_transaction();

-- 09. Comments and post-migration verification.
COMMENT ON TABLE public.spk_work_items IS
    'Lingkup pekerjaan terukur untuk SPK INTERNAL maupun SUBCON; bukan daftar issue material gudang.';
COMMENT ON TABLE public.subcon_progress_qty_items IS
    'Klaim kuantitas Subcon. Belum menjadi dasar pembayaran sampai lolos inspeksi.';
COMMENT ON TABLE public.subcon_inspections IS
    'Inspeksi kuantitas; hanya qty_passed pada inspeksi APPROVED dapat disertifikasi.';
COMMENT ON TABLE public.subcon_payment_certificates IS
    'Sertifikat pembayaran berbasis kuantitas yang lolos inspeksi.';
COMMENT ON TABLE public.spk_material_leftover_lots IS
    'Register kepemilikan material sisa, terpisah dari dokumen mutasi gudang material_leftover_header/items.';
COMMENT ON TABLE public.spk_material_leftover_transactions IS
    'Dokumen transaksi kepemilikan material sisa. Pencatatan stok tetap melalui modul inventory yang sudah ada.';

COMMIT;

-- POST-MIGRATION CHECKS
-- A. Map legacy contracts before enforcing NOT NULL on spk_id:
-- SELECT id,spk_no,project_id,subcontractor_id,status FROM public.subcon_contracts WHERE spk_id IS NULL;
-- B. Work items not yet mapped to Subcon contract tasks:
-- SELECT id,contract_id,task_name FROM public.subcon_contract_tasks WHERE work_item_id IS NULL;
-- C. Existing warehouse issue link must remain intact:
-- SELECT count(*) FROM public.material_out_header WHERE spk_id IS NOT NULL;
-- D. New structures:
-- SELECT table_name FROM information_schema.tables WHERE table_schema='public'
--   AND table_name IN ('spk_work_items','subcon_progress_qty_items','subcon_inspections',
--   'subcon_inspection_items','subcon_payment_certificates','subcon_payment_certificate_items',
--   'spk_material_supply','spk_material_leftover_lots','spk_material_leftover_transactions')
-- ORDER BY table_name;
--
-- IMPORTANT:
-- 1. This DDL does not migrate historical percentage progress into quantities.
-- 2. Existing subcon_payments and subcon_invoice_payments remain untouched.
--    Reconcile their posting logic before enabling new certificate-based payments.
-- 3. The application must create/update material_leftover_header/items or
--    other approved stock documents atomically when required by a POSTED
--    ownership transaction. This DDL deliberately does not write stock_movements.
-- 4. Aggregate cumulative claims/accepted quantities, certificate header totals,
--    invoice balance and payment balance still require transactional application
--    validation and concurrency tests before production.


-- ================================================================
-- 10. Ownership transfer lot-splitting correction.
-- A partial purchase/sale must transfer ONLY the transacted quantity.
-- The untransferred balance keeps its current owner. A child lot records
-- the transferred quantity and retains the original source owner.
-- ================================================================
ALTER TABLE public.spk_material_leftover_lots
    ADD COLUMN IF NOT EXISTS parent_lot_id bigint;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_spk_leftover_parent_lot') THEN
        ALTER TABLE public.spk_material_leftover_lots
            ADD CONSTRAINT fk_spk_leftover_parent_lot
            FOREIGN KEY (parent_lot_id)
            REFERENCES public.spk_material_leftover_lots(id) ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_spk_leftover_parent_lot
    ON public.spk_material_leftover_lots(parent_lot_id);

CREATE OR REPLACE FUNCTION public.fn_post_spk_leftover_transaction()
RETURNS trigger AS $$
DECLARE
    v_lot public.spk_material_leftover_lots%ROWTYPE;
    v_new_owner varchar(10);
    v_new_subcon integer;
BEGIN
    IF NEW.status='POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
        SELECT * INTO v_lot
          FROM public.spk_material_leftover_lots
         WHERE id=NEW.leftover_lot_id
         FOR UPDATE;

        IF NOT FOUND OR v_lot.qty_available < NEW.qty THEN
            RAISE EXCEPTION 'Qty lot tidak cukup atau sudah berubah.';
        END IF;

        UPDATE public.spk_material_leftover_lots
           SET qty_available = qty_available - NEW.qty,
               status = CASE
                   WHEN qty_available - NEW.qty = 0 THEN 'EXHAUSTED'
                   ELSE 'PARTIAL'
               END
         WHERE id=NEW.leftover_lot_id;

        IF NEW.transaction_type IN ('BUY_FROM_SUBCON','SELL_TO_CLIENT') THEN
            v_new_owner := NEW.buyer_type;
            v_new_subcon := NEW.buyer_subcontractor_id;

            INSERT INTO public.spk_material_leftover_lots (
                lot_no, project_id, spk_id, work_item_id, supply_id, material_id,
                source_owner_type, source_subcontractor_id,
                current_owner_type, current_subcontractor_id,
                qty_origin, qty_available, unit, condition_note, storage_location,
                status, detected_date, created_by, parent_lot_id
            ) VALUES (
                left(NEW.transaction_no || '-LOT', 50),
                v_lot.project_id, v_lot.spk_id, v_lot.work_item_id, v_lot.supply_id, v_lot.material_id,
                v_lot.source_owner_type, v_lot.source_subcontractor_id,
                v_new_owner, v_new_subcon,
                NEW.qty, NEW.qty, v_lot.unit, v_lot.condition_note, v_lot.storage_location,
                'AVAILABLE', NEW.transaction_date, NEW.posted_by, v_lot.id
            );
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
