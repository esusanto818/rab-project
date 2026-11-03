-- ================================================================
-- RAB-PROJECT: SUBCONTRACT MANAGEMENT DATABASE DDL
-- Target: PostgreSQL 9.6+
-- Branch: database/subcontract-management-ddl-2026-11
--
-- IMPORTANT:
-- 1. Additive migration. Does not drop/rename existing tables/columns.
-- 2. Keep spk_items as warehouse material issue entitlement.
-- 3. Existing subcon_progress/subcon_progress_dtl and payment tables
--    are retained for compatibility; new quantity inspection/certificate
--    workflow is added alongside them.
-- 4. Run on a STAGING COPY first. See preflight notes in the design doc.
-- 5. Foreign-key ID types follow currently committed SQL (spk/project/task/
--    budget/subcon IDs are INTEGER; new surrogate IDs use BIGSERIAL).
-- ================================================================

BEGIN;

-- ----------------------------------------------------------------
-- 00. Extend the existing SPK master (preserve legacy status values)
-- ----------------------------------------------------------------
ALTER TABLE public.spk
    ADD COLUMN IF NOT EXISTS spk_type varchar(12) NOT NULL DEFAULT 'INTERNAL',
    ADD COLUMN IF NOT EXISTS execution_party_type varchar(12),
    ADD COLUMN IF NOT EXISTS execution_party_id integer,
    ADD COLUMN IF NOT EXISTS work_start_date date,
    ADD COLUMN IF NOT EXISTS work_end_date date,
    ADD COLUMN IF NOT EXISTS parent_spk_id integer,
    ADD COLUMN IF NOT EXISTS revision_no integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS addendum_no varchar(50),
    ADD COLUMN IF NOT EXISTS approved_by integer,
    ADD COLUMN IF NOT EXISTS approved_at timestamp,
    ADD COLUMN IF NOT EXISTS cancelled_by integer,
    ADD COLUMN IF NOT EXISTS cancelled_at timestamp,
    ADD COLUMN IF NOT EXISTS cancel_reason text;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_spk_type') THEN
        ALTER TABLE public.spk ADD CONSTRAINT ck_spk_type
            CHECK (spk_type IN ('INTERNAL','SUBCON'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_spk_execution_party_type') THEN
        ALTER TABLE public.spk ADD CONSTRAINT ck_spk_execution_party_type
            CHECK (execution_party_type IS NULL OR execution_party_type IN ('EMPLOYEE','SUBCON'));
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
CREATE INDEX IF NOT EXISTS ix_spk_parent ON public.spk(parent_spk_id);

-- Existing subcontract contracts become commercial detail of the SPK.
-- Nullable during migration so historical records remain valid.
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
CREATE INDEX IF NOT EXISTS ix_subcon_contract_status
    ON public.subcon_contracts(project_id, status);

-- ----------------------------------------------------------------
-- 01. Measurable SPK work scope (separate from warehouse spk_items)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.spk_work_items (
    id                  bigserial PRIMARY KEY,
    spk_id              integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    project_task_id     integer REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    budget_item_id      integer REFERENCES public.budget_items(id) ON DELETE RESTRICT,
    work_code           varchar(50),
    description         text NOT NULL,
    unit                varchar(30) NOT NULL,
    contract_qty        numeric(18,4) NOT NULL CHECK (contract_qty > 0),
    unit_price          numeric(18,2) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    line_amount         numeric(18,2) NOT NULL DEFAULT 0 CHECK (line_amount >= 0),
    material_supply     varchar(12) NOT NULL DEFAULT 'SUBCON'
                        CHECK (material_supply IN ('COMPANY','SUBCON','MIXED')),
    sort_order          integer NOT NULL DEFAULT 0,
    status              varchar(15) NOT NULL DEFAULT 'ACTIVE'
                        CHECK (status IN ('ACTIVE','COMPLETED','CANCELLED')),
    notes               text,
    created_by          integer,
    created_at          timestamp NOT NULL DEFAULT now(),
    updated_at          timestamp NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ix_spk_work_items_spk ON public.spk_work_items(spk_id);
CREATE INDEX IF NOT EXISTS ix_spk_work_items_task ON public.spk_work_items(project_task_id);
CREATE INDEX IF NOT EXISTS ix_spk_work_items_budget ON public.spk_work_items(budget_item_id);

-- Keep SPK and task/budget references within the same project.
CREATE OR REPLACE FUNCTION public.fn_validate_spk_work_item_project()
RETURNS trigger AS $$
DECLARE
    v_project_id integer;
BEGIN
    SELECT project_id INTO v_project_id
      FROM public.spk WHERE id = NEW.spk_id;
    IF v_project_id IS NULL THEN
        RAISE EXCEPTION 'SPK % tidak ditemukan.', NEW.spk_id;
    END IF;

    IF NEW.project_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.project_tasks t
         WHERE t.id = NEW.project_task_id AND t.project_id = v_project_id
    ) THEN
        RAISE EXCEPTION 'Task pekerjaan harus berasal dari proyek SPK yang sama.';
    END IF;

    IF NEW.budget_item_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.budget_items b
         WHERE b.id = NEW.budget_item_id AND b.project_id = v_project_id
    ) THEN
        RAISE EXCEPTION 'Item RAB harus berasal dari proyek SPK yang sama.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_spk_work_item_project ON public.spk_work_items;
CREATE TRIGGER trg_validate_spk_work_item_project
BEFORE INSERT OR UPDATE OF spk_id, project_task_id, budget_item_id
ON public.spk_work_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_work_item_project();

-- Enforce same project for the SPK <-> subcontract contract association.
CREATE OR REPLACE FUNCTION public.fn_validate_subcon_contract_spk()
RETURNS trigger AS $$
BEGIN
    IF NEW.spk_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.spk s
         WHERE s.id = NEW.spk_id
           AND s.project_id = NEW.project_id
           AND s.spk_type = 'SUBCON'
    ) THEN
        RAISE EXCEPTION 'Kontrak Subcon harus terhubung ke SPK tipe SUBCON pada proyek yang sama.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_subcon_contract_spk ON public.subcon_contracts;
CREATE TRIGGER trg_validate_subcon_contract_spk
BEFORE INSERT OR UPDATE OF spk_id, project_id
ON public.subcon_contracts
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_contract_spk();

-- ----------------------------------------------------------------
-- 02. Quantity-based progress claim and inspection
-- Existing subcon_progress is retained as the claim header.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.subcon_progress_qty_items (
    id                  bigserial PRIMARY KEY,
    progress_id         integer NOT NULL REFERENCES public.subcon_progress(id) ON DELETE RESTRICT,
    work_item_id        bigint NOT NULL REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    qty_claimed         numeric(18,4) NOT NULL CHECK (qty_claimed > 0),
    unit_price_snapshot numeric(18,2) NOT NULL CHECK (unit_price_snapshot >= 0),
    amount_claimed      numeric(18,2) NOT NULL CHECK (amount_claimed >= 0),
    notes               text,
    created_at          timestamp NOT NULL DEFAULT now(),
    CONSTRAINT uq_subcon_progress_work_item UNIQUE(progress_id, work_item_id)
);

CREATE INDEX IF NOT EXISTS ix_subcon_progress_qty_progress
    ON public.subcon_progress_qty_items(progress_id);
CREATE INDEX IF NOT EXISTS ix_subcon_progress_qty_work
    ON public.subcon_progress_qty_items(work_item_id);

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
    created_at      timestamp NOT NULL DEFAULT now(),
    approved_by     integer,
    approved_at     timestamp,
    cancelled_by    integer,
    cancelled_at    timestamp,
    cancel_reason   text
);
CREATE INDEX IF NOT EXISTS ix_subcon_inspections_progress
    ON public.subcon_inspections(progress_id);
CREATE INDEX IF NOT EXISTS ix_subcon_inspections_status_date
    ON public.subcon_inspections(status, inspection_date);

CREATE TABLE IF NOT EXISTS public.subcon_inspection_items (
    id                  bigserial PRIMARY KEY,
    inspection_id       bigint NOT NULL REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT,
    progress_qty_item_id bigint NOT NULL REFERENCES public.subcon_progress_qty_items(id) ON DELETE RESTRICT,
    qty_inspected       numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_inspected >= 0),
    qty_passed          numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_passed >= 0),
    qty_rejected        numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_rejected >= 0),
    qty_rework          numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_rework >= 0),
    result              varchar(12) NOT NULL DEFAULT 'PENDING'
                        CHECK (result IN ('PASS','PARTIAL','REJECT','REWORK','PENDING')),
    evidence_ref        text,
    notes               text,
    created_at          timestamp NOT NULL DEFAULT now(),
    CONSTRAINT ck_inspection_qty_sum
        CHECK (qty_passed + qty_rejected + qty_rework <= qty_inspected)
);
CREATE INDEX IF NOT EXISTS ix_subcon_inspection_items_header
    ON public.subcon_inspection_items(inspection_id);
CREATE INDEX IF NOT EXISTS ix_subcon_inspection_items_claim
    ON public.subcon_inspection_items(progress_qty_item_id);

-- Row-level guard: inspected quantity cannot exceed the submitted claim quantity.
CREATE OR REPLACE FUNCTION public.fn_validate_subcon_inspection_qty()
RETURNS trigger AS $$
DECLARE
    v_claimed numeric(18,4);
BEGIN
    SELECT qty_claimed INTO v_claimed
      FROM public.subcon_progress_qty_items
     WHERE id = NEW.progress_qty_item_id;
    IF v_claimed IS NULL THEN
        RAISE EXCEPTION 'Detail klaim progres tidak ditemukan.';
    END IF;
    IF NEW.qty_inspected > v_claimed THEN
        RAISE EXCEPTION 'Qty diperiksa tidak boleh melebihi Qty klaim.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_subcon_inspection_qty ON public.subcon_inspection_items;
CREATE TRIGGER trg_validate_subcon_inspection_qty
BEFORE INSERT OR UPDATE OF progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework
ON public.subcon_inspection_items
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_inspection_qty();

-- ----------------------------------------------------------------
-- 03. Payment certificate: only passed inspection quantity is payable
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.subcon_payment_certificates (
    id                  bigserial PRIMARY KEY,
    certificate_no      varchar(50) NOT NULL UNIQUE,
    contract_id         integer NOT NULL REFERENCES public.subcon_contracts(id) ON DELETE RESTRICT,
    progress_id         integer NOT NULL REFERENCES public.subcon_progress(id) ON DELETE RESTRICT,
    inspection_id       bigint NOT NULL REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT,
    certificate_date    date NOT NULL,
    gross_amount        numeric(18,2) NOT NULL DEFAULT 0 CHECK (gross_amount >= 0),
    ppn_amount           numeric(18,2) NOT NULL DEFAULT 0 CHECK (ppn_amount >= 0),
    pph_amount           numeric(18,2) NOT NULL DEFAULT 0 CHECK (pph_amount >= 0),
    retention_amount    numeric(18,2) NOT NULL DEFAULT 0 CHECK (retention_amount >= 0),
    dp_recovery         numeric(18,2) NOT NULL DEFAULT 0 CHECK (dp_recovery >= 0),
    other_deduction     numeric(18,2) NOT NULL DEFAULT 0 CHECK (other_deduction >= 0),
    net_payable         numeric(18,2) NOT NULL DEFAULT 0 CHECK (net_payable >= 0),
    status              varchar(15) NOT NULL DEFAULT 'DRAFT'
                        CHECK (status IN ('DRAFT','APPROVED','CANCELLED')),
    notes               text,
    created_by          integer,
    created_at          timestamp NOT NULL DEFAULT now(),
    approved_by         integer,
    approved_at         timestamp,
    cancelled_by        integer,
    cancelled_at        timestamp,
    cancel_reason       text
);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_contract
    ON public.subcon_payment_certificates(contract_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_progress
    ON public.subcon_payment_certificates(progress_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_inspection
    ON public.subcon_payment_certificates(inspection_id);

CREATE TABLE IF NOT EXISTS public.subcon_payment_certificate_items (
    id                   bigserial PRIMARY KEY,
    certificate_id       bigint NOT NULL REFERENCES public.subcon_payment_certificates(id) ON DELETE RESTRICT,
    inspection_item_id   bigint NOT NULL REFERENCES public.subcon_inspection_items(id) ON DELETE RESTRICT,
    work_item_id         bigint NOT NULL REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    qty_certified        numeric(18,4) NOT NULL CHECK (qty_certified > 0),
    unit_price_snapshot  numeric(18,2) NOT NULL CHECK (unit_price_snapshot >= 0),
    amount_certified     numeric(18,2) NOT NULL CHECK (amount_certified >= 0),
    created_at           timestamp NOT NULL DEFAULT now(),
    CONSTRAINT uq_certificate_inspection_line UNIQUE(certificate_id, inspection_item_id)
);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_items_work
    ON public.subcon_payment_certificate_items(work_item_id);
CREATE INDEX IF NOT EXISTS ix_subcon_cert_items_inspection
    ON public.subcon_payment_certificate_items(inspection_item_id);

-- Link invoice to its approved certificate. Nullable for legacy invoices.
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

CREATE INDEX IF NOT EXISTS ix_subcon_invoice_certificate
    ON public.subcon_invoices(certificate_id);

-- ----------------------------------------------------------------
-- 04. Material supply origin and ownership traceability
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.spk_material_supply (
    id                  bigserial PRIMARY KEY,
    spk_id              integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    work_item_id        bigint REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    material_id         integer NOT NULL REFERENCES public.materials(id) ON DELETE RESTRICT,
    supply_source       varchar(10) NOT NULL
                        CHECK (supply_source IN ('COMPANY','SUBCON')),
    owner_type          varchar(10) NOT NULL
                        CHECK (owner_type IN ('COMPANY','SUBCON')),
    subcontractor_id    integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty_planned         numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_planned >= 0),
    qty_received        numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    qty_accepted        numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_accepted >= 0),
    unit                varchar(30),
    source_reference    varchar(100),
    notes               text,
    created_by          integer,
    created_at          timestamp NOT NULL DEFAULT now(),
    CONSTRAINT ck_spk_supply_owner
        CHECK ((owner_type='COMPANY' AND supply_source='COMPANY' AND subcontractor_id IS NULL)
            OR (owner_type='SUBCON' AND supply_source='SUBCON' AND subcontractor_id IS NOT NULL)),
    CONSTRAINT ck_spk_supply_received CHECK (qty_accepted <= qty_received)
);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_spk
    ON public.spk_material_supply(spk_id);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_material
    ON public.spk_material_supply(material_id);
CREATE INDEX IF NOT EXISTS ix_spk_material_supply_subcon
    ON public.spk_material_supply(subcontractor_id);

CREATE TABLE IF NOT EXISTS public.material_leftovers (
    id                  bigserial PRIMARY KEY,
    leftover_no         varchar(50) NOT NULL UNIQUE,
    project_id          integer NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    spk_id              integer NOT NULL REFERENCES public.spk(id) ON DELETE RESTRICT,
    work_item_id        bigint REFERENCES public.spk_work_items(id) ON DELETE RESTRICT,
    supply_id           bigint REFERENCES public.spk_material_supply(id) ON DELETE RESTRICT,
    material_id         integer NOT NULL REFERENCES public.materials(id) ON DELETE RESTRICT,
    source_owner_type   varchar(10) NOT NULL CHECK (source_owner_type IN ('COMPANY','SUBCON')),
    source_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    current_owner_type  varchar(10) NOT NULL CHECK (current_owner_type IN ('COMPANY','SUBCON','CLIENT')),
    current_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty_origin          numeric(18,4) NOT NULL CHECK (qty_origin > 0),
    qty_available       numeric(18,4) NOT NULL CHECK (qty_available >= 0),
    unit                varchar(30),
    condition_note      text,
    storage_location    varchar(150),
    status              varchar(15) NOT NULL DEFAULT 'AVAILABLE'
                        CHECK (status IN ('AVAILABLE','PARTIAL','EXHAUSTED','CLOSED')),
    detected_date       date NOT NULL DEFAULT CURRENT_DATE,
    created_by          integer,
    created_at          timestamp NOT NULL DEFAULT now(),
    CONSTRAINT ck_leftover_available_qty CHECK (qty_available <= qty_origin),
    CONSTRAINT ck_leftover_source_owner
        CHECK ((source_owner_type='COMPANY' AND source_subcontractor_id IS NULL)
            OR (source_owner_type='SUBCON' AND source_subcontractor_id IS NOT NULL)),
    CONSTRAINT ck_leftover_current_owner
        CHECK ((current_owner_type='SUBCON' AND current_subcontractor_id IS NOT NULL)
            OR (current_owner_type IN ('COMPANY','CLIENT') AND current_subcontractor_id IS NULL))
);
CREATE INDEX IF NOT EXISTS ix_material_leftovers_project
    ON public.material_leftovers(project_id);
CREATE INDEX IF NOT EXISTS ix_material_leftovers_spk
    ON public.material_leftovers(spk_id);
CREATE INDEX IF NOT EXISTS ix_material_leftovers_material
    ON public.material_leftovers(material_id);
CREATE INDEX IF NOT EXISTS ix_material_leftovers_owner
    ON public.material_leftovers(current_owner_type, current_subcontractor_id);

-- Documented ownership/quantity transaction. No inventory posting is performed
-- by this table itself; application must create the official stock movement.
CREATE TABLE IF NOT EXISTS public.material_leftover_transactions (
    id                  bigserial PRIMARY KEY,
    transaction_no      varchar(50) NOT NULL UNIQUE,
    leftover_id         bigint NOT NULL REFERENCES public.material_leftovers(id) ON DELETE RESTRICT,
    transaction_type    varchar(20) NOT NULL
                        CHECK (transaction_type IN ('RETURN_COMPANY','BUY_FROM_SUBCON','SELL_TO_CLIENT')),
    transaction_date    date NOT NULL,
    seller_type         varchar(10) NOT NULL CHECK (seller_type IN ('COMPANY','SUBCON','CLIENT')),
    seller_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    buyer_type          varchar(10) NOT NULL CHECK (buyer_type IN ('COMPANY','SUBCON','CLIENT')),
    buyer_subcontractor_id integer REFERENCES public.subcontractors(id) ON DELETE RESTRICT,
    qty                 numeric(18,4) NOT NULL CHECK (qty > 0),
    unit_price          numeric(18,2) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    total_amount        numeric(18,2) NOT NULL DEFAULT 0 CHECK (total_amount >= 0),
    market_price        numeric(18,2),
    market_price_source text,
    market_price_date   date,
    reason              text NOT NULL,
    status              varchar(15) NOT NULL DEFAULT 'DRAFT'
                        CHECK (status IN ('DRAFT','APPROVED','POSTED','CANCELLED')),
    approved_by         integer,
    approved_at         timestamp,
    posted_by           integer,
    posted_at           timestamp,
    inventory_ref_type  varchar(40),
    inventory_ref_id    bigint,
    evidence_ref        text,
    created_by          integer,
    created_at          timestamp NOT NULL DEFAULT now(),
    CONSTRAINT ck_leftover_seller_subcon
        CHECK ((seller_type='SUBCON' AND seller_subcontractor_id IS NOT NULL)
            OR (seller_type<>'SUBCON' AND seller_subcontractor_id IS NULL)),
    CONSTRAINT ck_leftover_buyer_subcon
        CHECK ((buyer_type='SUBCON' AND buyer_subcontractor_id IS NOT NULL)
            OR (buyer_type<>'SUBCON' AND buyer_subcontractor_id IS NULL))
);
CREATE INDEX IF NOT EXISTS ix_leftover_txn_leftover
    ON public.material_leftover_transactions(leftover_id);
CREATE INDEX IF NOT EXISTS ix_leftover_txn_status_date
    ON public.material_leftover_transactions(status, transaction_date);

-- Validate that each ownership transaction matches the allowed direction.
CREATE OR REPLACE FUNCTION public.fn_validate_leftover_transaction()
RETURNS trigger AS $$
DECLARE
    v_owner varchar(10);
    v_subcon integer;
    v_available numeric(18,4);
BEGIN
    SELECT current_owner_type, current_subcontractor_id, qty_available
      INTO v_owner, v_subcon, v_available
      FROM public.material_leftovers
     WHERE id = NEW.leftover_id
     FOR UPDATE;

    IF v_owner IS NULL THEN
        RAISE EXCEPTION 'Lot material sisa tidak ditemukan.';
    END IF;
    IF NEW.qty > v_available THEN
        RAISE EXCEPTION 'Qty transaksi melebihi qty material sisa tersedia.';
    END IF;

    IF NEW.transaction_type='RETURN_COMPANY' THEN
        IF v_owner <> 'COMPANY' OR NEW.seller_type <> 'COMPANY' OR NEW.buyer_type <> 'COMPANY' THEN
            RAISE EXCEPTION 'RETURN_COMPANY hanya untuk material milik perusahaan yang dikembalikan ke gudang perusahaan.';
        END IF;
    ELSIF NEW.transaction_type='BUY_FROM_SUBCON' THEN
        IF v_owner <> 'SUBCON' OR NEW.seller_type <> 'SUBCON' OR NEW.buyer_type <> 'COMPANY'
           OR NEW.seller_subcontractor_id IS DISTINCT FROM v_subcon THEN
            RAISE EXCEPTION 'BUY_FROM_SUBCON harus membeli material dari pemilik Subcon saat ini.';
        END IF;
    ELSIF NEW.transaction_type='SELL_TO_CLIENT' THEN
        IF v_owner <> 'COMPANY' OR NEW.seller_type <> 'COMPANY' OR NEW.buyer_type <> 'CLIENT' THEN
            RAISE EXCEPTION 'SELL_TO_CLIENT hanya untuk material milik perusahaan.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validate_leftover_transaction ON public.material_leftover_transactions;
CREATE TRIGGER trg_validate_leftover_transaction
BEFORE INSERT OR UPDATE OF leftover_id, transaction_type, seller_type, seller_subcontractor_id,
                           buyer_type, buyer_subcontractor_id, qty
ON public.material_leftover_transactions
FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_leftover_transaction();

-- ----------------------------------------------------------------
-- 05. Comments / indexes supporting audit and reporting
-- ----------------------------------------------------------------
COMMENT ON TABLE public.spk_work_items IS
    'Lingkup pekerjaan terukur per SPK; berbeda dari spk_items yang mengatur otorisasi issue material gudang.';
COMMENT ON TABLE public.subcon_progress_qty_items IS
    'Klaim kuantitas pekerjaan Subcon. Klaim belum menjadi dasar pembayaran sebelum inspeksi disetujui.';
COMMENT ON TABLE public.subcon_inspections IS
    'Pemeriksaan kuantitas pekerjaan. Hanya qty_passed pada inspeksi APPROVED yang dapat disertifikasi.';
COMMENT ON TABLE public.subcon_payment_certificates IS
    'Sertifikat pembayaran berdasarkan kuantitas pekerjaan yang lolos inspeksi.';
COMMENT ON TABLE public.spk_material_supply IS
    'Asal pasokan dan pemilik material untuk pekerjaan SPK.';
COMMENT ON TABLE public.material_leftovers IS
    'Lot material sisa dengan jejak pemilik asal dan pemilik saat ini.';
COMMENT ON TABLE public.material_leftover_transactions IS
    'Dokumen pengembalian/pembelian/penjualan material sisa. Posting inventory dilakukan melalui modul transaksi resmi.';

COMMIT;

-- ----------------------------------------------------------------
-- POST-MIGRATION CHECKS (run separately after migration)
-- ----------------------------------------------------------------
-- 1. Legacy Subcon contracts not yet linked to a SPK:
-- SELECT id, spk_no, project_id, status FROM public.subcon_contracts WHERE spk_id IS NULL;
--
-- 2. SPK type distribution:
-- SELECT spk_type, count(*) FROM public.spk GROUP BY spk_type;
--
-- 3. Confirm Warehouse Issuing linkage remains intact:
-- SELECT count(*) FROM public.material_out_header WHERE spk_id IS NOT NULL;
--
-- 4. Confirm new tables exist:
-- SELECT table_name FROM information_schema.tables
-- WHERE table_schema='public' AND table_name IN (
-- 'spk_work_items','subcon_progress_qty_items','subcon_inspections',
-- 'subcon_inspection_items','subcon_payment_certificates',
-- 'subcon_payment_certificate_items','spk_material_supply',
-- 'material_leftovers','material_leftover_transactions'
-- ) ORDER BY table_name;
--
-- IMPORTANT APPLICATION RULES NOT EXPRESSIBLE AS SIMPLE CHECK CONSTRAINTS:
-- - cumulative qty claimed/accepted across multiple rows and inspections;
-- - do not certify more than approved passed quantity not already certified;
-- - invoice total must not exceed approved certificate balance;
-- - payment total must not exceed invoice balance;
-- - only POSTED leftover transactions may reduce qty_available/change owner;
-- - create stock ledger movements through existing inventory APIs only;
-- - reconcile legacy subcon_payments vs subcon_invoice_payments before
--   enabling a single production payment posting path.
