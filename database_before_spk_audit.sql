--
-- PostgreSQL database dump
--

-- Dumped from database version 9.6.24
-- Dumped by pg_dump version 13.7 (Ubuntu 13.7-1.pgdg20.04+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: archive; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA archive;


--
-- Name: hstore; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS hstore WITH SCHEMA public;


--
-- Name: EXTENSION hstore; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION hstore IS 'data type for storing sets of (key, value) pairs';


--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: account_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.account_type AS ENUM (
    'asset',
    'liability',
    'equity',
    'revenue',
    'expense',
    'cash',
    'bank'
);


--
-- Name: ap_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.ap_status AS ENUM (
    'open',
    'partially_paid',
    'paid',
    'disputed'
);


--
-- Name: delivery_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.delivery_status AS ENUM (
    'pending',
    'packed',
    'shipped',
    'delivered',
    'returned'
);


--
-- Name: deposit_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.deposit_status AS ENUM (
    'pending',
    'validated',
    'rejected',
    'applied'
);


--
-- Name: invoice_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.invoice_status AS ENUM (
    'draft',
    'issued',
    'partially_paid',
    'paid',
    'overdue',
    'cancelled',
    'final'
);


--
-- Name: journal_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.journal_status AS ENUM (
    'draft',
    'posted',
    'reversed'
);


--
-- Name: po_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.po_status AS ENUM (
    'draft',
    'sent',
    'confirmed',
    'in_production',
    'partially_received',
    'completed',
    'cancelled'
);


--
-- Name: production_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.production_status AS ENUM (
    'not_started',
    'in_progress',
    'completed',
    'delayed',
    'cancelled'
);


--
-- Name: qc_result; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.qc_result AS ENUM (
    'passed',
    'failed',
    'partial'
);


--
-- Name: quotation_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.quotation_status AS ENUM (
    'draft',
    'sent',
    'approved',
    'rejected',
    'expired',
    'converted'
);


--
-- Name: reference_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.reference_type AS ENUM (
    'customer_deposit',
    'customer_payment',
    'sales_invoice',
    'purchase_grn',
    'accounts_payable',
    'supplier_payment',
    'stock_adjustment',
    'manual_entry',
    'closing_entry',
    'purchase_return',
    'wh_receiving',
    'purchase_invoice',
    'project_invoice',
    'project_payment',
    'material_out',
    'supplier_return',
    'wip_release'
);


--
-- Name: return_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.return_status AS ENUM (
    'returned',
    'under_repair',
    'repaired',
    'replaced',
    'closed'
);


--
-- Name: so_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.so_status AS ENUM (
    'draft',
    'confirmed',
    'in_production',
    'partially_delivered',
    'completed',
    'cancelled'
);


--
-- Name: stock_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.stock_status AS ENUM (
    'in_stock',
    'reserved',
    'sold',
    'in_production',
    'defect',
    'returned_to_supplier'
);


--
-- Name: supplier_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.supplier_type AS ENUM (
    'pengrajin',
    'material_supplier',
    'both'
);


--
-- Name: fn_block_posted_leftover_transaction_change(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_block_posted_leftover_transaction_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        IF OLD.status='POSTED' THEN
            RAISE EXCEPTION 'Transaksi material sisa POSTED tidak boleh dihapus; gunakan transaksi koreksi.';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status='POSTED' THEN
        RAISE EXCEPTION 'Transaksi material sisa POSTED tidak boleh diubah; gunakan transaksi koreksi.';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_guard_budget_item_task_allocations(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_guard_budget_item_task_allocations() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_post_spk_leftover_transaction(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_post_spk_leftover_transaction() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_lot public.spk_material_leftover_lots%ROWTYPE;
BEGIN
    IF NEW.status='POSTED'
       AND (TG_OP='INSERT' OR OLD.status IS DISTINCT FROM 'POSTED') THEN
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
            INSERT INTO public.spk_material_leftover_lots (
                lot_no, project_id, spk_id, work_item_id, supply_id, material_id,
                source_owner_type, source_subcontractor_id,
                current_owner_type, current_subcontractor_id,
                qty_origin, qty_available, unit, condition_note, storage_location,
                status, detected_date, created_by, parent_lot_id
            ) VALUES (
                left(NEW.transaction_no, 25) || '-' || NEW.id::text || '-LOT',
                v_lot.project_id, v_lot.spk_id, v_lot.work_item_id, v_lot.supply_id, v_lot.material_id,
                v_lot.source_owner_type, v_lot.source_subcontractor_id,
                NEW.buyer_type, NEW.buyer_subcontractor_id,
                NEW.qty, NEW.qty, v_lot.unit, v_lot.condition_note, v_lot.storage_location,
                'AVAILABLE', NEW.transaction_date, NEW.posted_by, v_lot.id
            );
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_certificate_approval(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_certificate_approval() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_items_total numeric(18,2);
    v_expected_net numeric(18,2);
BEGIN
    IF NEW.status='APPROVED' AND OLD.status IS DISTINCT FROM 'APPROVED' THEN
        SELECT COALESCE(sum(amount_certified),0)
          INTO v_items_total
          FROM public.subcon_payment_certificate_items
         WHERE certificate_id=NEW.id;

        IF v_items_total <= 0 OR NEW.gross_amount <> v_items_total THEN
            RAISE EXCEPTION 'Gross certificate harus sama dengan total detail qty tersertifikasi.';
        END IF;

        v_expected_net := NEW.gross_amount + NEW.ppn_amount - NEW.pph_amount
                          - NEW.retention_amount - NEW.dp_recovery - NEW.other_deduction;
        IF v_expected_net < 0 OR NEW.net_payable <> v_expected_net THEN
            RAISE EXCEPTION 'Net payable certificate tidak sesuai gross, pajak, retention dan potongan.';
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM public.subcon_inspections i
             WHERE i.id=NEW.inspection_id
               AND i.progress_id=NEW.progress_id
               AND i.status='APPROVED'
        ) THEN
            RAISE EXCEPTION 'Payment certificate hanya dapat disetujui dari inspeksi APPROVED.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_certificate_item(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_certificate_item() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_financial_expense_allocation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_financial_expense_allocation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
    IF v_expense_project IS DISTINCT FROM v_task_project THEN
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
$$;


--
-- Name: fn_validate_inspection_item(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_inspection_item() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_claimed numeric(18,4);
    v_progress_id integer;
    v_inspection_progress_id integer;
    v_prior_inspected numeric(18,4);
BEGIN
    SELECT qty_claimed, progress_id
      INTO v_claimed, v_progress_id
      FROM public.subcon_progress_qty_items
     WHERE id=NEW.progress_qty_item_id
     FOR UPDATE;

    SELECT progress_id INTO v_inspection_progress_id
      FROM public.subcon_inspections WHERE id=NEW.inspection_id;

    IF v_claimed IS NULL OR v_progress_id IS DISTINCT FROM v_inspection_progress_id THEN
        RAISE EXCEPTION 'Detail inspeksi dan klaim progres tidak cocok.';
    END IF;

    SELECT COALESCE(sum(qty_inspected),0)
      INTO v_prior_inspected
      FROM public.subcon_inspection_items
     WHERE progress_qty_item_id=NEW.progress_qty_item_id
       AND (TG_OP='INSERT' OR id<>NEW.id);

    IF v_prior_inspected + NEW.qty_inspected > v_claimed THEN
        RAISE EXCEPTION 'Akumulasi qty inspeksi tidak boleh melebihi qty klaim.';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_progress_qty_item(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_progress_qty_item() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_spk_leftover_transaction(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_spk_leftover_transaction() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_lot public.spk_material_leftover_lots%ROWTYPE;
    v_doc_project integer;
    v_doc_qty numeric(18,4);
    v_already_linked numeric(18,4);
BEGIN
    SELECT * INTO v_lot
      FROM public.spk_material_leftover_lots
     WHERE id=NEW.leftover_lot_id
     FOR UPDATE;

    IF NOT FOUND THEN RAISE EXCEPTION 'Lot material sisa tidak ditemukan.'; END IF;
    IF NEW.qty > v_lot.qty_available THEN
        RAISE EXCEPTION 'Qty transaksi melebihi qty sisa tersedia.';
    END IF;

    IF NEW.transaction_type='RETURN_COMPANY' THEN
        IF v_lot.current_owner_type<>'COMPANY'
           OR NEW.seller_type<>'COMPANY' OR NEW.buyer_type<>'COMPANY' THEN
            RAISE EXCEPTION 'RETURN_COMPANY hanya untuk material milik perusahaan.';
        END IF;
    ELSIF NEW.transaction_type='BUY_FROM_SUBCON' THEN
        IF v_lot.current_owner_type<>'SUBCON'
           OR NEW.seller_type<>'SUBCON' OR NEW.buyer_type<>'COMPANY'
           OR NEW.seller_subcontractor_id IS DISTINCT FROM v_lot.current_subcontractor_id THEN
            RAISE EXCEPTION 'BUY_FROM_SUBCON harus membeli dari Subcon pemilik material saat ini.';
        END IF;
    ELSIF NEW.transaction_type='SELL_TO_CLIENT' THEN
        IF v_lot.current_owner_type<>'COMPANY'
           OR NEW.seller_type<>'COMPANY' OR NEW.buyer_type<>'CLIENT' THEN
            RAISE EXCEPTION 'SELL_TO_CLIENT hanya untuk material milik perusahaan.';
        END IF;
    END IF;

    IF NEW.status='POSTED' THEN
        IF NEW.transaction_type IN ('RETURN_COMPANY','BUY_FROM_SUBCON') THEN
            IF NEW.inventory_doc_id IS NULL OR NEW.inventory_out_doc_id IS NOT NULL THEN
                RAISE EXCEPTION 'Transaksi penerimaan/return wajib memakai dokumen material_leftover_header saja.';
            END IF;

            SELECT project_id INTO v_doc_project
              FROM public.material_leftover_header
             WHERE id=NEW.inventory_doc_id AND lower(status)='posted';
            IF v_doc_project IS DISTINCT FROM v_lot.project_id THEN
                RAISE EXCEPTION 'Dokumen penerimaan inventory harus POSTED dan berasal dari proyek yang sama.';
            END IF;

            SELECT COALESCE(sum(li.qty),0) INTO v_doc_qty
              FROM public.material_leftover_items li
             WHERE li.header_id=NEW.inventory_doc_id
               AND li.material_id=v_lot.material_id;
            SELECT COALESCE(sum(t.qty),0) INTO v_already_linked
              FROM public.spk_material_leftover_transactions t
             WHERE t.inventory_doc_id=NEW.inventory_doc_id
               AND t.status='POSTED'
               AND t.id<>NEW.id;
            IF v_already_linked + NEW.qty > v_doc_qty THEN
                RAISE EXCEPTION 'Total transaksi yang ditautkan melebihi qty dokumen penerimaan inventory.';
            END IF;

        ELSE
            IF NEW.inventory_out_doc_id IS NULL OR NEW.inventory_doc_id IS NOT NULL THEN
                RAISE EXCEPTION 'Penjualan ke client wajib memakai dokumen material_out_header saja.';
            END IF;

            SELECT project_id INTO v_doc_project
              FROM public.material_out_header
             WHERE id=NEW.inventory_out_doc_id AND lower(status)='posted';
            IF v_doc_project IS DISTINCT FROM v_lot.project_id THEN
                RAISE EXCEPTION 'Dokumen issue inventory harus POSTED dan berasal dari proyek yang sama.';
            END IF;

            SELECT COALESCE(sum(oi.qty_out),0) INTO v_doc_qty
              FROM public.material_out_items oi
             WHERE oi.material_out_id=NEW.inventory_out_doc_id
               AND oi.material_id=v_lot.material_id;
            SELECT COALESCE(sum(t.qty),0) INTO v_already_linked
              FROM public.spk_material_leftover_transactions t
             WHERE t.inventory_out_doc_id=NEW.inventory_out_doc_id
               AND t.status='POSTED'
               AND t.id<>NEW.id;
            IF v_already_linked + NEW.qty > v_doc_qty THEN
                RAISE EXCEPTION 'Total transaksi yang ditautkan melebihi qty dokumen issue inventory.';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_spk_task_expense(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_spk_task_expense() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_spk_task_material(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_spk_task_material() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_spk_work_item_project(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_spk_work_item_project() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_project_id integer;
    v_assigned_spk integer;
    v_assigned_task integer;
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

    IF NEW.spk_task_id IS NOT NULL THEN
        SELECT st.spk_id, st.task_id
          INTO v_assigned_spk, v_assigned_task
          FROM public.spk_tasks st
         WHERE st.id=NEW.spk_task_id;
        IF v_assigned_spk IS DISTINCT FROM NEW.spk_id
           OR (NEW.project_task_id IS NOT NULL AND v_assigned_task IS DISTINCT FROM NEW.project_task_id) THEN
            RAISE EXCEPTION 'Mapping SPK task tidak cocok dengan SPK/task pada work item.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_subcon_contract_spk(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_subcon_contract_spk() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_subcon_invoice_certificate(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_subcon_invoice_certificate() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: fn_validate_subcon_task_work_item(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_subcon_task_work_item() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_contract_project integer;
    v_contract_subcontractor integer;
    v_spk_project integer;
    v_spk_type varchar(12);
    v_spk_subcontractor integer;
BEGIN
    IF NEW.work_item_id IS NULL THEN RETURN NEW; END IF;

    SELECT c.project_id, c.subcontractor_id
      INTO v_contract_project, v_contract_subcontractor
      FROM public.subcon_contracts c WHERE c.id=NEW.contract_id;

    SELECT s.project_id, s.spk_type, s.execution_subcontractor_id
      INTO v_spk_project, v_spk_type, v_spk_subcontractor
      FROM public.spk_work_items wi JOIN public.spk s ON s.id=wi.spk_id
     WHERE wi.id=NEW.work_item_id;

    IF v_contract_project IS NULL
       OR v_spk_project IS DISTINCT FROM v_contract_project
       OR v_spk_type IS DISTINCT FROM 'SUBCON'
       OR v_spk_subcontractor IS DISTINCT FROM v_contract_subcontractor THEN
        RAISE EXCEPTION 'Work item harus berasal dari SPK SUBCON dengan proyek dan Subcon yang sama.';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: fn_validate_task_budget_allocation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_validate_task_budget_allocation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_task_project INTEGER;
    v_budget_project INTEGER;
    v_category VARCHAR(50);
    v_material_id INTEGER;
    v_expense_id INTEGER;
    v_budget_qty NUMERIC(18,4);
    v_budget_amount NUMERIC(18,2);
    v_allocated NUMERIC(18,4);
BEGIN
    SELECT project_id INTO v_task_project
      FROM public.project_tasks WHERE id = NEW.task_id;
    IF v_task_project IS NULL THEN
        RAISE EXCEPTION 'Task proyek tidak ditemukan.';
    END IF;

    SELECT project_id, category, material_id, expense_id,
           COALESCE(qty_plan,0),
           COALESCE(qty_plan,0) * COALESCE(unit_price_plan,0)
      INTO v_budget_project, v_category, v_material_id, v_expense_id, v_budget_qty, v_budget_amount
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
        IF v_category = 'Material' OR v_category IS NULL OR v_expense_id IS NULL THEN
            RAISE EXCEPTION 'Alokasi expense harus menggunakan item RAB non-Material yang terhubung ke Master Expense.';
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
$$;


--
-- Name: if_modified_func(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.if_modified_func() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_app_user text;
BEGIN
    -- Ambil app.username dari sesi.
    -- Parameter 'true' kedua (missing_ok) mencegah exception jika variabel belum diset.
    -- COALESCE memberikan nilai default (misal: session_user / 'system') jika hasilnya NULL.
    v_app_user := COALESCE(NULLIF(current_setting('app.username', true), ''), session_user::text);

    IF tg_op = 'UPDATE' THEN
        INSERT INTO data_log_update (
            table_name, user_name, app_user_name, action, old_values, new_values, updated_cols, query
        )
        VALUES (
            tg_table_name::text,
            current_user::text,
            v_app_user,
            'u',
            hstore(old.*),
            hstore(new.*),
            akeys(hstore(new.*) - hstore(old.*)),
            current_query()
        );
        RETURN NEW;

    ELSIF tg_op = 'DELETE' THEN
        INSERT INTO data_log_delete (
            table_name, user_name, app_user_name, action, old_values, query
        )
        VALUES (
            tg_table_name::text,
            current_user::text,
            v_app_user,
            'd',
            hstore(old.*),
            current_query()
        );
        RETURN OLD;

    ELSIF tg_op = 'INSERT' THEN
        INSERT INTO data_log_insert (
            table_name, user_name, app_user_name, action, new_values, query
        )
        VALUES (
            tg_table_name::text,
            current_user::text,
            v_app_user,
            'i',
            hstore(new.*),
            current_query()
        );
        RETURN NEW;
    END IF;
END;
$$;


--
-- Name: if_modified_func_old(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.if_modified_func_old() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
begin
    if tg_op = 'UPDATE' then
        insert into data_log_update (table_name, user_name, app_user_name, action, old_values, new_values, updated_cols, query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'u', hstore(old.*), hstore(new.*),
               akeys(hstore(new.*) - hstore(old.*)), current_query());
        return new;
    elsif tg_op = 'DELETE' then
        insert into data_log_delete (table_name, user_name, app_user_name, action, old_values, query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'd', hstore(old.*), current_query());
        return old;
    elsif tg_op = 'INSERT' then
        insert into data_log_insert (table_name, user_name, app_user_name, action, new_values,query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'i', hstore(new.*), current_query());
        return new;
    end if;
end;
$$;


--
-- Name: refresh_mat_view_items(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.refresh_mat_view_items() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
begin
    refresh materialized view mat_view_items;
    return null;
end $$;


--
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


--
-- Name: update_financial_expenses_modtime(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_financial_expenses_modtime() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


--
-- Name: update_financial_incomes_modtime(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_financial_incomes_modtime() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

--
-- Name: m_materials; Type: TABLE; Schema: archive; Owner: -
--

CREATE TABLE archive.m_materials (
    id integer NOT NULL,
    material_code character varying(30) NOT NULL,
    material_name character varying(150) NOT NULL,
    unit character varying(20) NOT NULL,
    estimated_price numeric(15,2) DEFAULT 0,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: m_materials_id_seq; Type: SEQUENCE; Schema: archive; Owner: -
--

CREATE SEQUENCE archive.m_materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: archive; Owner: -
--

ALTER SEQUENCE archive.m_materials_id_seq OWNED BY archive.m_materials.id;


--
-- Name: accounting_periods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.accounting_periods (
    id bigint NOT NULL,
    period_name character varying(20) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_closed boolean DEFAULT false NOT NULL
);


--
-- Name: accounting_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.accounting_periods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: accounting_periods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.accounting_periods_id_seq OWNED BY public.accounting_periods.id;


--
-- Name: ai_variance_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_variance_logs (
    id integer NOT NULL,
    project_id integer NOT NULL,
    total_budget numeric(15,2) DEFAULT 0 NOT NULL,
    total_actual numeric(15,2) DEFAULT 0 NOT NULL,
    variance_amount numeric(15,2) DEFAULT 0 NOT NULL,
    variance_percentage numeric(5,2) DEFAULT 0 NOT NULL,
    risk_level character varying(20),
    ai_summary text,
    recommendations jsonb,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    risk_rule character varying(10),
    headline text,
    analysis jsonb,
    source character varying(20),
    ai_used boolean,
    CONSTRAINT ai_variance_logs_risk_level_check CHECK (((risk_level)::text = ANY ((ARRAY['LOW'::character varying, 'MEDIUM'::character varying, 'HIGH'::character varying, 'CRITICAL'::character varying])::text[])))
);


--
-- Name: ai_variance_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_variance_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_variance_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_variance_logs_id_seq OWNED BY public.ai_variance_logs.id;


--
-- Name: bin_locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bin_locations (
    id integer NOT NULL,
    warehouse_id integer NOT NULL,
    code character varying(30) NOT NULL,
    name character varying(100) DEFAULT ''::character varying,
    zone character varying(20) DEFAULT ''::character varying,
    rack character varying(20) DEFAULT ''::character varying,
    level character varying(20) DEFAULT ''::character varying,
    active boolean DEFAULT true NOT NULL
);


--
-- Name: bin_locations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bin_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bin_locations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bin_locations_id_seq OWNED BY public.bin_locations.id;


--
-- Name: budget_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.budget_items (
    id integer NOT NULL,
    project_id integer NOT NULL,
    category character varying(20) DEFAULT 'Material'::character varying NOT NULL,
    material_id integer,
    item_name character varying(255) NOT NULL,
    unit character varying(30) DEFAULT ''::character varying,
    qty_plan numeric(18,3) DEFAULT 0,
    unit_price_plan numeric(18,2) DEFAULT 0,
    tolerance_pct numeric(5,2) DEFAULT 0,
    notes text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    is_addendum boolean DEFAULT false NOT NULL,
    addendum_reason text,
    addendum_by integer,
    addendum_at timestamp without time zone,
    expense_id integer,
    CONSTRAINT budget_items_category_check CHECK (((category)::text = ANY ((ARRAY['Material'::character varying, 'Upah'::character varying, 'Alat'::character varying, 'Lain-lain'::character varying])::text[])))
);


--
-- Name: budget_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.budget_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: budget_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.budget_items_id_seq OWNED BY public.budget_items.id;


--
-- Name: chart_of_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chart_of_accounts (
    id bigint NOT NULL,
    account_code character varying(20) NOT NULL,
    account_name character varying(150) NOT NULL,
    account_type public.account_type NOT NULL,
    parent_id bigint,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: chart_of_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.chart_of_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: chart_of_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.chart_of_accounts_id_seq OWNED BY public.chart_of_accounts.id;


--
-- Name: clients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clients (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    contact_person character varying(255) DEFAULT ''::character varying,
    phone character varying(50) DEFAULT ''::character varying,
    email character varying(255) DEFAULT ''::character varying,
    address text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: clients_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.clients_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: clients_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.clients_id_seq OWNED BY public.clients.id;


--
-- Name: coa_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coa_accounts (
    id integer NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(150) NOT NULL,
    account_type character varying(20) NOT NULL,
    normal_balance character varying(6) NOT NULL,
    parent_id integer,
    is_postable boolean DEFAULT true NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT coa_accounts_account_type_check CHECK (((account_type)::text = ANY ((ARRAY['Aset'::character varying, 'Kewajiban'::character varying, 'Ekuitas'::character varying, 'Pendapatan'::character varying, 'Beban'::character varying])::text[]))),
    CONSTRAINT coa_accounts_normal_balance_check CHECK (((normal_balance)::text = ANY ((ARRAY['Debit'::character varying, 'Kredit'::character varying])::text[])))
);


--
-- Name: coa_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.coa_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: coa_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.coa_accounts_id_seq OWNED BY public.coa_accounts.id;


--
-- Name: company_profile; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.company_profile (
    id smallint DEFAULT 1 NOT NULL,
    company_name character varying(150) DEFAULT ''::character varying NOT NULL,
    legal_name character varying(200),
    address text,
    city character varying(100),
    province character varying(100),
    postal_code character varying(20),
    country character varying(100) DEFAULT 'Indonesia'::character varying,
    phone character varying(50),
    email character varying(150),
    website character varying(150),
    tax_id character varying(50),
    logo_document bytea,
    logo_document_mime character varying(50),
    logo_document_updated_at timestamp without time zone,
    logo_app bytea,
    logo_app_mime character varying(50),
    logo_app_updated_at timestamp without time zone,
    doc_footer text,
    doc_signer_name character varying(150),
    doc_signer_title character varying(100),
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_by integer,
    CONSTRAINT company_profile_id_check CHECK ((id = 1))
);


--
-- Name: customer_npwp; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_npwp (
    id bigint NOT NULL,
    customer_id integer,
    npwp_no character varying(30),
    active boolean,
    address_office boolean DEFAULT false
);


--
-- Name: customer_npwp_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_npwp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_npwp_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_npwp_id_seq OWNED BY public.customer_npwp.id;


--
-- Name: dashboard_ai_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_ai_cache (
    project_id integer NOT NULL,
    ctx_hash character varying(40) NOT NULL,
    payload text NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: data_log_delete; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_log_delete (
    table_name text,
    user_name text,
    app_user_name text,
    action text,
    old_values public.hstore,
    new_values public.hstore,
    updated_cols text[],
    query text,
    user_terminal character varying(15),
    id bigint NOT NULL,
    action_timestamp timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: data_log_delete_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_delete_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_delete_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_delete_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_delete_id_seq1; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.data_log_delete_id_seq1 OWNED BY public.data_log_delete.id;


--
-- Name: data_log_insert; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_log_insert (
    table_name text,
    user_name text,
    app_user_name text,
    action text,
    old_values public.hstore,
    new_values public.hstore,
    updated_cols text[],
    query text,
    user_terminal character varying(15),
    id bigint NOT NULL,
    action_timestamp timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: data_log_insert_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_insert_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_insert_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_insert_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_insert_id_seq1; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.data_log_insert_id_seq1 OWNED BY public.data_log_insert.id;


--
-- Name: data_log_update; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_log_update (
    table_name text,
    user_name text,
    app_user_name text,
    action text,
    old_values public.hstore,
    new_values public.hstore,
    updated_cols text[],
    query text,
    user_terminal character varying(15),
    id bigint NOT NULL,
    action_timestamp timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: data_log_update_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_update_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_update_id_seq1; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_log_update_id_seq1
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_log_update_id_seq1; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.data_log_update_id_seq1 OWNED BY public.data_log_update.id;


--
-- Name: department_section; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.department_section (
    id bigint NOT NULL,
    department_id bigint,
    section_name character varying(255),
    line_no integer,
    remark text,
    seq_no integer,
    data_output boolean,
    piece_rate boolean,
    operator integer,
    share_amt boolean,
    active boolean DEFAULT true,
    warehouse_id bigint
);


--
-- Name: department_section_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.department_section_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: department_section_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.department_section_id_seq OWNED BY public.department_section.id;


--
-- Name: employee_department; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_department (
    id bigint NOT NULL,
    employee_id bigint,
    department_id bigint,
    from_date date,
    to_date date,
    from_time time without time zone DEFAULT '00:00:00'::time without time zone,
    to_time time without time zone DEFAULT '00:00:00'::time without time zone
);


--
-- Name: employee_department_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_department_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_department_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_department_id_seq OWNED BY public.employee_department.id;


--
-- Name: employee_section; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_section (
    id bigint NOT NULL,
    employee_id integer,
    section_id integer,
    from_date date,
    to_date date,
    remark text,
    from_time time without time zone DEFAULT '00:00:00'::time without time zone,
    to_time time without time zone DEFAULT '00:00:00'::time without time zone
);


--
-- Name: employee_section_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_section_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_section_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_section_id_seq OWNED BY public.employee_section.id;


--
-- Name: employee_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_status (
    id bigint NOT NULL,
    employee_id bigint,
    status_id integer,
    from_date date,
    to_date date
);


--
-- Name: employee_status_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_status_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_status_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_status_id_seq OWNED BY public.employee_status.id;


--
-- Name: expense_master; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.expense_master (
    id integer NOT NULL,
    expense_code character varying(30) NOT NULL,
    expense_name character varying(150) NOT NULL,
    category character varying(50) NOT NULL,
    unit character varying(20) NOT NULL,
    default_price numeric(15,2) DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone,
    created_by integer,
    updated_by integer
);


--
-- Name: TABLE expense_master; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.expense_master IS 'Master Expense - daftar jenis expense (Upah, Transport, Peralatan, dll)';


--
-- Name: expense_master_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.expense_master_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: expense_master_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.expense_master_id_seq OWNED BY public.expense_master.id;


--
-- Name: finance_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.finance_settings (
    id smallint DEFAULT 1 NOT NULL,
    base_currency_id bigint NOT NULL,
    report_currency_id bigint NOT NULL,
    default_rate_type character varying(10) DEFAULT 'KMK'::character varying NOT NULL,
    rate_basis_pl character varying(12) DEFAULT 'AVERAGE'::character varying NOT NULL,
    fiscal_year_start_month smallint DEFAULT 1 NOT NULL,
    amount_decimals smallint DEFAULT 2 NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_by integer,
    CONSTRAINT finance_settings_amount_decimals_check CHECK (((amount_decimals >= 0) AND (amount_decimals <= 4))),
    CONSTRAINT finance_settings_default_rate_type_check CHECK (((default_rate_type)::text = ANY ((ARRAY['KMK'::character varying, 'BI'::character varying, 'MANUAL'::character varying])::text[]))),
    CONSTRAINT finance_settings_fiscal_year_start_month_check CHECK (((fiscal_year_start_month >= 1) AND (fiscal_year_start_month <= 12))),
    CONSTRAINT finance_settings_id_check CHECK ((id = 1)),
    CONSTRAINT finance_settings_rate_basis_pl_check CHECK (((rate_basis_pl)::text = ANY ((ARRAY['TRANSACTION'::character varying, 'AVERAGE'::character varying, 'PERIOD_END'::character varying])::text[])))
);


--
-- Name: financial_expense_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.financial_expense_allocations (
    id bigint NOT NULL,
    financial_expense_id integer NOT NULL,
    task_expense_id bigint NOT NULL,
    spk_task_id bigint,
    amount numeric(18,2) NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT financial_expense_allocations_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: TABLE financial_expense_allocations; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.financial_expense_allocations IS 'Pembagian transaksi financial_expenses aktual ke RAB Expense, Task, dan opsional SPK.';


--
-- Name: financial_expense_allocations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.financial_expense_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: financial_expense_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.financial_expense_allocations_id_seq OWNED BY public.financial_expense_allocations.id;


--
-- Name: financial_expenses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.financial_expenses (
    id integer NOT NULL,
    expense_no character varying(50) NOT NULL,
    expense_date date DEFAULT ('now'::text)::date NOT NULL,
    project_id integer,
    supplier_id integer,
    account_id bigint,
    category character varying(100) DEFAULT 'Beban Operasional'::character varying,
    amount numeric(18,2) DEFAULT 0.00 NOT NULL,
    reference_no character varying(100),
    payment_method character varying(50) DEFAULT 'BANK_TRANSFER'::character varying,
    status character varying(30) DEFAULT 'PAID'::character varying,
    notes text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: financial_expenses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.financial_expenses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: financial_expenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.financial_expenses_id_seq OWNED BY public.financial_expenses.id;


--
-- Name: financial_incomes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.financial_incomes (
    id integer NOT NULL,
    income_no character varying(50) NOT NULL,
    income_date date DEFAULT ('now'::text)::date NOT NULL,
    project_id integer,
    customer_id integer,
    account_id integer,
    category character varying(100) DEFAULT 'Pendapatan Proyek'::character varying,
    amount numeric(18,2) DEFAULT 0.00 NOT NULL,
    reference_no character varying(100),
    payment_method character varying(50) DEFAULT 'BANK_TRANSFER'::character varying,
    status character varying(30) DEFAULT 'PAID'::character varying,
    notes text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: financial_incomes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.financial_incomes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: financial_incomes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.financial_incomes_id_seq OWNED BY public.financial_incomes.id;


--
-- Name: fiscal_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fiscal_config (
    id bigint NOT NULL,
    rule_code character varying(50) NOT NULL,
    rule_name character varying(150) NOT NULL,
    debit_account_id bigint NOT NULL,
    credit_account_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: TABLE fiscal_config; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.fiscal_config IS 'Rule D posts to Persediaan Barang Jadi (debit) vs Hutang Usaha (credit); AP row itself is created in the same transaction, not a separate journal (Rule E folded into D).';


--
-- Name: fiscal_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fiscal_config_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fiscal_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fiscal_config_id_seq OWNED BY public.fiscal_config.id;


--
-- Name: general_ledger; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.general_ledger (
    id bigint NOT NULL,
    account_id bigint NOT NULL,
    journal_line_id bigint NOT NULL,
    transaction_date date NOT NULL,
    debit numeric(18,2) DEFAULT 0 NOT NULL,
    credit numeric(18,2) DEFAULT 0 NOT NULL,
    running_balance numeric(18,2) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: general_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.general_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: general_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.general_ledger_id_seq OWNED BY public.general_ledger.id;


--
-- Name: grn_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grn_dtl (
    id integer NOT NULL,
    grn_hdr_id integer NOT NULL,
    po_item_id integer NOT NULL,
    item_id integer NOT NULL,
    item_name character varying(150) NOT NULL,
    qty_received numeric(14,4) NOT NULL,
    grade_id integer,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    remark text,
    unit_cost_base numeric(15,4) DEFAULT 0 NOT NULL,
    CONSTRAINT grn_dtl_qty_received_check CHECK ((qty_received > (0)::numeric))
);


--
-- Name: grn_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.grn_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grn_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.grn_dtl_id_seq OWNED BY public.grn_dtl.id;


--
-- Name: grn_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grn_hdr (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    po_id integer NOT NULL,
    supplier_id integer,
    grn_date date DEFAULT ('now'::text)::date NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    currency_id bigint,
    exch_rate numeric(15,4) DEFAULT 1 NOT NULL,
    vehicle_plate_no character varying(20),
    driver_name character varying(100),
    supplier_do_no character varying(100),
    CONSTRAINT grn_hdr_exch_rate_chk CHECK ((exch_rate > (0)::numeric)),
    CONSTRAINT grn_hdr_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'partial'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: COLUMN grn_hdr.vehicle_plate_no; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.grn_hdr.vehicle_plate_no IS 'Plat nomor kendaraan pengirim barang (huruf besar, tanpa pemisah ganda)';


--
-- Name: COLUMN grn_hdr.driver_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.grn_hdr.driver_name IS 'Nama driver / pengirim barang';


--
-- Name: grn_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.grn_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grn_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.grn_hdr_id_seq OWNED BY public.grn_hdr.id;


--
-- Name: item_grade; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.item_grade (
    id integer NOT NULL,
    init character varying(50),
    name character varying(255),
    description text,
    remark text
);


--
-- Name: item_grade_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.item_grade_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: item_grade_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.item_grade_id_seq OWNED BY public.item_grade.id;


--
-- Name: item_group; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.item_group (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    description text,
    remark text,
    type_id integer,
    location_id integer,
    department_id integer
);


--
-- Name: item_group_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.item_group_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: item_group_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.item_group_id_seq OWNED BY public.item_group.id;


--
-- Name: items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.items (
    id bigint NOT NULL,
    group_id bigint,
    init character varying(50),
    name character varying(255),
    description text,
    remark text,
    um_unit bigint,
    um_packing bigint,
    color_id bigint,
    active boolean,
    item_code character varying(50),
    diameter numeric(12,2),
    length numeric(12,2),
    width numeric(12,2),
    height numeric(12,2),
    diameter_1 numeric(12,3),
    um_length integer,
    um_width integer,
    um_height integer,
    um_diameter integer,
    um_diameter_1 integer,
    part_number character varying(50),
    additional_size character varying(50),
    pic_url character varying(255),
    track_lot boolean DEFAULT false NOT NULL
);


--
-- Name: TABLE items; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.items IS 'class id split into 3 category :  	1 -- Rawmat 	2 -- Finish goods 	3 -- General';


--
-- Name: COLUMN items.track_lot; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.items.track_lot IS 'Jika true, item ini wajib punya lot_id di setiap stock_movements dan dialokasikan FEFO saat keluar stok.';


--
-- Name: items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.items_id_seq OWNED BY public.items.id;


--
-- Name: journal_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journal_entries (
    id bigint NOT NULL,
    ref_no character varying(40) NOT NULL,
    journal_date date DEFAULT ('now'::text)::date NOT NULL,
    period_id bigint,
    reference_type public.reference_type NOT NULL,
    reference_id bigint NOT NULL,
    description character varying(255),
    status public.journal_status DEFAULT 'draft'::public.journal_status NOT NULL,
    posted_by bigint,
    posted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: journal_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.journal_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: journal_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.journal_entries_id_seq OWNED BY public.journal_entries.id;


--
-- Name: journal_entry_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journal_entry_lines (
    id integer NOT NULL,
    journal_entry_id integer NOT NULL,
    account_id integer NOT NULL,
    project_id integer,
    debit numeric(18,2) DEFAULT 0 NOT NULL,
    credit numeric(18,2) DEFAULT 0 NOT NULL,
    description character varying(255) DEFAULT ''::character varying,
    CONSTRAINT journal_entry_lines_check CHECK ((NOT ((debit > (0)::numeric) AND (credit > (0)::numeric)))),
    CONSTRAINT journal_entry_lines_credit_check CHECK ((credit >= (0)::numeric)),
    CONSTRAINT journal_entry_lines_debit_check CHECK ((debit >= (0)::numeric))
);


--
-- Name: journal_entry_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.journal_entry_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: journal_entry_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.journal_entry_lines_id_seq OWNED BY public.journal_entry_lines.id;


--
-- Name: journal_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.journal_lines (
    id bigint NOT NULL,
    journal_id bigint NOT NULL,
    account_id bigint NOT NULL,
    debit numeric(18,2) DEFAULT 0 NOT NULL,
    credit numeric(18,2) DEFAULT 0 NOT NULL,
    description character varying(255),
    CONSTRAINT chk_debit_credit CHECK (((debit >= (0)::numeric) AND (credit >= (0)::numeric) AND (NOT ((debit > (0)::numeric) AND (credit > (0)::numeric)))))
);


--
-- Name: journal_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.journal_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: journal_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.journal_lines_id_seq OWNED BY public.journal_lines.id;


--
-- Name: m_address; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_address (
    id bigint NOT NULL,
    active boolean,
    street character varying(255),
    block character varying(50),
    street_no character varying(50),
    rt character varying(50),
    rw character varying(50),
    village_id integer,
    address text,
    country_id integer,
    npwp_id integer,
    contact_person_id integer,
    supplier_id integer,
    agent_id integer
);


--
-- Name: m_address_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_address_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_address_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_address_id_seq OWNED BY public.m_address.id;


--
-- Name: m_agent; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_agent (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    remark text,
    active boolean DEFAULT false
);


--
-- Name: m_agent_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_agent_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_agent_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_agent_id_seq OWNED BY public.m_agent.id;


--
-- Name: m_city; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_city (
    id bigint NOT NULL,
    province_id integer,
    city_type character varying(10),
    name character varying(255),
    init character varying(50)
);


--
-- Name: m_city_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_city_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_city_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_city_id_seq OWNED BY public.m_city.id;


--
-- Name: m_color; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_color (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    description text,
    remark text
);


--
-- Name: m_color_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_color_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_color_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_color_id_seq OWNED BY public.m_color.id;


--
-- Name: m_contact_no; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_contact_no (
    id bigint NOT NULL,
    supplier_id integer,
    contact_person_id integer,
    contact_type integer DEFAULT 1,
    contact_no character varying(100),
    agent_id integer
);


--
-- Name: COLUMN m_contact_no.contact_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.m_contact_no.contact_type IS '1. Email, 2. Mobile, 3. Phone, 4. Fax';


--
-- Name: m_contact_no_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_contact_no_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_contact_no_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_contact_no_id_seq OWNED BY public.m_contact_no.id;


--
-- Name: m_contact_person; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_contact_person (
    id bigint NOT NULL,
    supplier_id integer,
    name character varying(255),
    "position" character varying(255),
    remark text,
    agent_id integer
);


--
-- Name: m_contact_person_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_contact_person_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_contact_person_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_contact_person_id_seq OWNED BY public.m_contact_person.id;


--
-- Name: m_country; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_country (
    id bigint NOT NULL,
    region_id integer,
    name character varying(255),
    init character varying(255)
);


--
-- Name: m_country_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_country_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_country_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_country_id_seq OWNED BY public.m_country.id;


--
-- Name: m_currency; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_currency (
    id bigint NOT NULL,
    init character varying(15),
    name character varying(255)
);


--
-- Name: m_currency_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_currency_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_currency_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_currency_id_seq OWNED BY public.m_currency.id;


--
-- Name: m_customer; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_customer (
    id bigint NOT NULL,
    name character varying(255),
    init character varying(50),
    remark text,
    active boolean
);


--
-- Name: m_customer_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_customer_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_customer_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_customer_id_seq OWNED BY public.m_customer.id;


--
-- Name: m_department; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_department (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    remark text,
    location_id bigint,
    seq_no integer,
    active boolean DEFAULT true
);


--
-- Name: m_department_executive; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_department_executive (
    id bigint NOT NULL,
    department_id integer,
    employee_id integer,
    from_date date,
    to_date date,
    remark text
);


--
-- Name: m_department_executive_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_department_executive_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_department_executive_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_department_executive_id_seq OWNED BY public.m_department_executive.id;


--
-- Name: m_department_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_department_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_department_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_department_id_seq OWNED BY public.m_department.id;


--
-- Name: m_district; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_district (
    id bigint NOT NULL,
    city_id integer,
    name character varying(255),
    init character varying(50)
);


--
-- Name: m_district_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_district_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_district_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_district_id_seq OWNED BY public.m_district.id;


--
-- Name: m_employee; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_employee (
    id bigint NOT NULL,
    employee_name character varying(255),
    birth_date date,
    gender character varying(1),
    from_date date,
    to_date date,
    remark text,
    address text,
    id_number character varying(100),
    phone_number character varying(100),
    martial_status character varying(2),
    emergency_name character varying(255),
    emergency_phone character varying(255),
    emergency_relation character varying(255),
    emergency_address text,
    photo bytea,
    photo_url character varying(255),
    email character varying(100),
    active boolean,
    place_birth character varying(255),
    id_address text,
    npwp_no character varying(100),
    bank_account_no character varying(100),
    nik integer
);


--
-- Name: m_employee_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_employee_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_employee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_employee_id_seq OWNED BY public.m_employee.id;


--
-- Name: m_employee_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_employee_status (
    id bigint NOT NULL,
    name character varying(255),
    remark text
);


--
-- Name: m_employee_status_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_employee_status_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_employee_status_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_employee_status_id_seq OWNED BY public.m_employee_status.id;


--
-- Name: m_form_group; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_form_group (
    id bigint NOT NULL,
    seq character varying(8),
    name character varying(50),
    remark text,
    active boolean DEFAULT true NOT NULL
);


--
-- Name: m_form_group_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_form_group_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_form_group_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_form_group_id_seq OWNED BY public.m_form_group.id;


--
-- Name: m_form_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_form_links (
    id bigint NOT NULL,
    form_id integer,
    link_form_id integer
);


--
-- Name: m_form_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_form_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_form_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_form_links_id_seq OWNED BY public.m_form_links.id;


--
-- Name: m_form_name; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_form_name (
    id bigint NOT NULL,
    group_id integer,
    seq character varying(8),
    title character varying(50),
    php_url character varying(100),
    js_url character varying(100),
    remark text,
    department_id integer,
    main_table character varying(100),
    tna boolean,
    active boolean DEFAULT true NOT NULL
);


--
-- Name: m_form_name_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_form_name_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_form_name_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_form_name_id_seq OWNED BY public.m_form_name.id;


--
-- Name: m_location; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_location (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    remark text
);


--
-- Name: m_location_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_location_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_location_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_location_id_seq OWNED BY public.m_location.id;


--
-- Name: zz_deprecated_m_projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.zz_deprecated_m_projects (
    id integer NOT NULL,
    project_code character varying(30) NOT NULL,
    project_name character varying(150) NOT NULL,
    location text,
    project_manager_id integer,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: m_projects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_projects_id_seq OWNED BY public.zz_deprecated_m_projects.id;


--
-- Name: m_region; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_region (
    id bigint NOT NULL,
    name character varying(255),
    init character varying(25)
);


--
-- Name: m_region_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_region_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_region_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_region_id_seq OWNED BY public.m_region.id;


--
-- Name: m_suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_suppliers (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    remark text,
    active boolean,
    log boolean DEFAULT false,
    overseas boolean DEFAULT false,
    npwp_no character varying(30),
    is_pkp boolean DEFAULT false NOT NULL
);


--
-- Name: m_suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_suppliers_id_seq OWNED BY public.m_suppliers.id;


--
-- Name: m_unit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_unit (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    description text,
    remark text
);


--
-- Name: m_unit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_unit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_unit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_unit_id_seq OWNED BY public.m_unit.id;


--
-- Name: m_user_access_menu; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_user_access_menu (
    id bigint NOT NULL,
    user_id integer,
    form_id integer,
    access_level smallint
);


--
-- Name: m_user_access_menu_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_user_access_menu_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_user_access_menu_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_user_access_menu_id_seq OWNED BY public.m_user_access_menu.id;


--
-- Name: m_user_login_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_user_login_status (
    id bigint NOT NULL,
    user_id integer,
    login_status boolean,
    login_terminal character varying(15),
    f_count integer,
    f_lock integer
);


--
-- Name: m_user_login_status_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_user_login_status_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_user_login_status_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_user_login_status_id_seq OWNED BY public.m_user_login_status.id;


--
-- Name: m_user_system; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_user_system (
    id bigint NOT NULL,
    login_name character varying(32),
    employee_id integer,
    password character varying(255),
    active boolean
);


--
-- Name: m_user_system_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_user_system_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_user_system_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_user_system_id_seq OWNED BY public.m_user_system.id;


--
-- Name: m_village; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.m_village (
    id bigint NOT NULL,
    district_id integer,
    name character varying(255),
    init character varying(50),
    postalcode character varying(10)
);


--
-- Name: m_village_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_village_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_village_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_village_id_seq OWNED BY public.m_village.id;


--
-- Name: zz_deprecated_m_warehouse; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.zz_deprecated_m_warehouse (
    id bigint NOT NULL,
    init character varying(50),
    name character varying(255),
    description text,
    remark text,
    location_id integer,
    total_row integer,
    col_row integer,
    active boolean
);


--
-- Name: m_warehouse_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.m_warehouse_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: m_warehouse_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.m_warehouse_id_seq OWNED BY public.zz_deprecated_m_warehouse.id;


--
-- Name: material_issue_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_issue_allocations (
    id integer NOT NULL,
    out_item_id integer NOT NULL,
    layer_source character varying(10) DEFAULT 'WHRCV'::character varying NOT NULL,
    layer_id integer NOT NULL,
    qty numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) NOT NULL,
    amount numeric(18,2) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT material_issue_allocations_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: material_issue_allocations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_issue_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_issue_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_issue_allocations_id_seq OWNED BY public.material_issue_allocations.id;


--
-- Name: material_leftover_header; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_leftover_header (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    project_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    doc_date date DEFAULT ('now'::text)::date NOT NULL,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    reversed_at timestamp without time zone,
    reversed_by integer
);


--
-- Name: material_leftover_header_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_leftover_header_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_leftover_header_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_leftover_header_id_seq OWNED BY public.material_leftover_header.id;


--
-- Name: material_leftover_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_leftover_items (
    id integer NOT NULL,
    header_id integer NOT NULL,
    material_id integer NOT NULL,
    qty numeric(12,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    notes text,
    CONSTRAINT material_leftover_items_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: material_leftover_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_leftover_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_leftover_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_leftover_items_id_seq OWNED BY public.material_leftover_items.id;


--
-- Name: material_out_header; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_out_header (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    project_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    issue_date date DEFAULT ('now'::text)::date NOT NULL,
    issued_by integer,
    received_by_mandor character varying(100),
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    remark text,
    created_at timestamp without time zone DEFAULT now(),
    reversed_at timestamp without time zone,
    reversed_by integer,
    issue_type character varying(20) DEFAULT 'PROJECT'::character varying NOT NULL,
    prepared_at timestamp without time zone,
    prepared_by integer,
    delivery_date date,
    delivery_note_no character varying(50),
    delivery_receiver character varying(100),
    delivery_proof text,
    spk_id integer,
    CONSTRAINT chk_material_out_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'prepared'::character varying, 'posted'::character varying, 'reversed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: material_out_header_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_out_header_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_out_header_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_out_header_id_seq OWNED BY public.material_out_header.id;


--
-- Name: material_out_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_out_items (
    id integer NOT NULL,
    material_out_id integer NOT NULL,
    material_id integer NOT NULL,
    qty_out numeric(12,4) NOT NULL,
    unit_cost numeric(15,2) DEFAULT 0 NOT NULL,
    lot_no character varying(50),
    bin_location_id integer,
    is_overbudget boolean DEFAULT false,
    overbudget_reason character varying(100),
    notes text,
    amount numeric(18,2) DEFAULT 0 NOT NULL,
    CONSTRAINT material_out_items_qty_out_check CHECK ((qty_out > (0)::numeric))
);


--
-- Name: material_out_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_out_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_out_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_out_items_id_seq OWNED BY public.material_out_items.id;


--
-- Name: material_receipt_header; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_receipt_header (
    id integer NOT NULL,
    ref_no character varying(50),
    project_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    po_id integer,
    receipt_date date DEFAULT ('now'::text)::date NOT NULL,
    received_by integer,
    supplier_do_no character varying(100) DEFAULT ''::character varying,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    remark text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    reversed_at timestamp without time zone,
    reversed_by integer,
    CONSTRAINT material_receipt_header_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: material_receipt_header_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_receipt_header_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_receipt_header_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_receipt_header_id_seq OWNED BY public.material_receipt_header.id;


--
-- Name: material_receipt_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.material_receipt_items (
    id integer NOT NULL,
    receipt_id integer NOT NULL,
    material_id integer NOT NULL,
    qty_in numeric(12,4) NOT NULL,
    unit_cost numeric(15,2) DEFAULT 0 NOT NULL,
    bin_location_id integer,
    lot_no character varying(50) DEFAULT ''::character varying,
    notes text DEFAULT ''::text,
    CONSTRAINT material_receipt_items_qty_in_check CHECK ((qty_in > (0)::numeric))
);


--
-- Name: material_receipt_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.material_receipt_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: material_receipt_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.material_receipt_items_id_seq OWNED BY public.material_receipt_items.id;


--
-- Name: materials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.materials (
    id integer NOT NULL,
    material_code character varying(50),
    material_name character varying(255) NOT NULL,
    unit character varying(30) DEFAULT ''::character varying,
    category character varying(100) DEFAULT ''::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    active boolean DEFAULT true,
    estimated_price numeric(15,2) DEFAULT 0
);


--
-- Name: materials_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.materials_id_seq OWNED BY public.materials.id;


--
-- Name: password_change_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_change_log (
    id integer NOT NULL,
    user_id integer NOT NULL,
    changed_at timestamp without time zone DEFAULT now(),
    ip_address character varying(45),
    user_agent text
);


--
-- Name: TABLE password_change_log; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.password_change_log IS 'Audit log untuk perubahan password user';


--
-- Name: password_change_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.password_change_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: password_change_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.password_change_log_id_seq OWNED BY public.password_change_log.id;


--
-- Name: pics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pics (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    "position" character varying(255) DEFAULT ''::character varying,
    phone character varying(50) DEFAULT ''::character varying,
    email character varying(255) DEFAULT ''::character varying,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: pics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pics_id_seq OWNED BY public.pics.id;


--
-- Name: po_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.po_items (
    id integer NOT NULL,
    po_id integer NOT NULL,
    budget_item_id integer,
    material_id integer,
    description character varying(255) NOT NULL,
    qty numeric(18,3) DEFAULT 0 NOT NULL,
    unit character varying(30) DEFAULT ''::character varying,
    unit_price numeric(18,2) DEFAULT 0 NOT NULL,
    currency_id bigint,
    exch_rate numeric(15,4) DEFAULT 1.0000 NOT NULL,
    amount_base numeric(18,2) DEFAULT 0 NOT NULL,
    amount_budget numeric(18,2) DEFAULT 0 NOT NULL
);


--
-- Name: po_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.po_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: po_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.po_items_id_seq OWNED BY public.po_items.id;


--
-- Name: project_invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_invoices (
    id integer NOT NULL,
    inv_no character varying(30) NOT NULL,
    project_id integer NOT NULL,
    termin_name character varying(100) NOT NULL,
    invoice_date date NOT NULL,
    percentage numeric(5,2),
    amount numeric(18,2) NOT NULL,
    paid_amount numeric(18,2) DEFAULT 0 NOT NULL,
    remark text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    dpp_amount numeric(18,2) DEFAULT 0 NOT NULL,
    ppn_rate numeric(5,2) DEFAULT 0 NOT NULL,
    ppn_amount numeric(18,2) DEFAULT 0 NOT NULL,
    faktur_pajak_no character varying(30),
    faktur_pajak_date date,
    due_date date,
    task_id bigint,
    auto_generated boolean DEFAULT false NOT NULL,
    ai_note text,
    CONSTRAINT project_invoices_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: project_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_invoices_id_seq OWNED BY public.project_invoices.id;


--
-- Name: project_payment_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_payment_items (
    id integer NOT NULL,
    payment_id integer NOT NULL,
    invoice_id integer NOT NULL,
    amount_paid numeric(18,2) NOT NULL,
    amount_cur numeric(18,2),
    CONSTRAINT project_payment_items_amount_paid_check CHECK ((amount_paid > (0)::numeric))
);


--
-- Name: project_payment_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_payment_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_payment_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_payment_items_id_seq OWNED BY public.project_payment_items.id;


--
-- Name: project_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_payments (
    id integer NOT NULL,
    payment_no character varying(30) NOT NULL,
    client_id integer NOT NULL,
    account_id integer,
    payment_date date NOT NULL,
    payment_method character varying(20) DEFAULT 'BANK_TRANSFER'::character varying,
    total_amount numeric(18,2) DEFAULT 0 NOT NULL,
    allocated_amount numeric(18,2) DEFAULT 0 NOT NULL,
    excess_amount numeric(18,2) DEFAULT 0 NOT NULL,
    deposit_applied numeric(18,2) DEFAULT 0 NOT NULL,
    reference_no character varying(50) DEFAULT ''::character varying,
    notes text DEFAULT ''::text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    currency_id bigint,
    exch_rate numeric(15,4) DEFAULT 1.0000 NOT NULL,
    total_amount_cur numeric(18,2)
);


--
-- Name: project_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_payments_id_seq OWNED BY public.project_payments.id;


--
-- Name: project_progress_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_progress_log (
    id integer NOT NULL,
    project_id integer NOT NULL,
    report_date date NOT NULL,
    percent_claimed smallint NOT NULL,
    prev_percent smallint DEFAULT 0 NOT NULL,
    reported_by integer,
    note text,
    evidence_ref text,
    status character varying(20) DEFAULT 'Diajukan'::character varying NOT NULL,
    verified_by integer,
    verified_at timestamp without time zone,
    verify_note text,
    snap_budget_base numeric(18,2),
    snap_cost_base numeric(18,2),
    snap_cpi numeric(8,4),
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT project_progress_log_percent_claimed_check CHECK (((percent_claimed >= 0) AND (percent_claimed <= 100))),
    CONSTRAINT project_progress_log_status_check CHECK (((status)::text = ANY ((ARRAY['Diajukan'::character varying, 'Diverifikasi'::character varying, 'Ditolak'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: project_progress_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_progress_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_progress_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_progress_log_id_seq OWNED BY public.project_progress_log.id;


--
-- Name: project_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_tasks (
    id integer NOT NULL,
    project_id integer NOT NULL,
    task_name character varying(255) NOT NULL,
    description text,
    start_date date NOT NULL,
    end_date date NOT NULL,
    status character varying(50) DEFAULT 'Belum Mulai'::character varying,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: project_tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_tasks_id_seq OWNED BY public.project_tasks.id;


--
-- Name: project_wip_release; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_wip_release (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    release_date date NOT NULL,
    project_id integer NOT NULL,
    mode character varying(10) DEFAULT 'progress'::character varying NOT NULL,
    progress_pct numeric(5,2) NOT NULL,
    cumulative_cost numeric(18,2) NOT NULL,
    released_before numeric(18,2) NOT NULL,
    amount numeric(18,2) NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT project_wip_release_mode_check CHECK (((mode)::text = ANY ((ARRAY['progress'::character varying, 'complete'::character varying])::text[])))
);


--
-- Name: project_wip_release_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_wip_release_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_wip_release_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_wip_release_id_seq OWNED BY public.project_wip_release.id;


--
-- Name: projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.projects (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    client_id integer,
    location character varying(255) DEFAULT ''::character varying,
    pic_id bigint,
    start_date date,
    end_date date,
    budget numeric(18,2) DEFAULT 0,
    spent numeric(18,2) DEFAULT 0,
    progress smallint DEFAULT 0,
    status character varying(20) DEFAULT 'Berjalan'::character varying,
    notes text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    location_id integer,
    contract_value numeric(18,2) DEFAULT 0,
    budget_currency_id bigint DEFAULT 2,
    budget_exch_rate numeric(15,4) DEFAULT 1 NOT NULL,
    budget_rate_date date,
    ppn_rate numeric(5,2) DEFAULT 0 NOT NULL,
    ppn_inclusive boolean DEFAULT false NOT NULL,
    retention_pct numeric(5,2) DEFAULT 0 NOT NULL,
    dp_min_pct numeric(5,2) DEFAULT 30 NOT NULL,
    project_scope text,
    CONSTRAINT projects_budget_exch_rate_chk CHECK ((budget_exch_rate > (0)::numeric)),
    CONSTRAINT projects_progress_check CHECK (((progress >= 0) AND (progress <= 100))),
    CONSTRAINT projects_status_check CHECK (((status)::text = ANY ((ARRAY['Belum Mulai'::character varying, 'Berjalan'::character varying, 'Tertunda'::character varying, 'Selesai'::character varying])::text[])))
);


--
-- Name: projects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.projects_id_seq OWNED BY public.projects.id;


--
-- Name: purchase_invoice_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_invoice_dtl (
    id integer NOT NULL,
    invoice_hdr_id integer NOT NULL,
    grn_hdr_id integer NOT NULL,
    matched_amount numeric(18,2) NOT NULL,
    remark text,
    CONSTRAINT purchase_invoice_dtl_matched_amount_check CHECK ((matched_amount > (0)::numeric))
);


--
-- Name: purchase_invoice_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_invoice_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_invoice_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_invoice_dtl_id_seq OWNED BY public.purchase_invoice_dtl.id;


--
-- Name: purchase_invoice_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_invoice_hdr (
    id integer NOT NULL,
    inv_no character varying(50) NOT NULL,
    supplier_invoice_no character varying(100) NOT NULL,
    invoice_date date NOT NULL,
    supplier_id integer NOT NULL,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    total_amount numeric(18,2) DEFAULT 0 NOT NULL,
    paid_amount numeric(18,2) DEFAULT 0 NOT NULL,
    dpp_amount numeric(18,2) DEFAULT 0 NOT NULL,
    ppn_rate numeric(5,2) DEFAULT 0 NOT NULL,
    ppn_amount numeric(18,2) DEFAULT 0 NOT NULL,
    faktur_pajak_no character varying(30),
    faktur_pajak_date date
);


--
-- Name: purchase_invoice_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_invoice_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_invoice_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_invoice_hdr_id_seq OWNED BY public.purchase_invoice_hdr.id;


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_orders (
    id integer NOT NULL,
    po_number character varying(50),
    project_id integer NOT NULL,
    supplier_id integer,
    po_date date DEFAULT ('now'::text)::date NOT NULL,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    notes text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    currency_id bigint,
    exch_rate numeric(15,4) DEFAULT 1.0000 NOT NULL,
    budget_currency_id bigint,
    budget_exch_rate numeric(15,4) DEFAULT 1 NOT NULL,
    total_base numeric(18,2) DEFAULT 0 NOT NULL,
    total_budget numeric(18,2) DEFAULT 0 NOT NULL,
    ppn_mode character varying(4) DEFAULT 'NONE'::character varying NOT NULL,
    ppn_rate numeric(5,2) DEFAULT 0 NOT NULL,
    CONSTRAINT chk_po_ppn_mode CHECK (((ppn_mode)::text = ANY ((ARRAY['NONE'::character varying, 'EXCL'::character varying, 'INCL'::character varying])::text[]))),
    CONSTRAINT chk_po_ppn_rate CHECK (((ppn_rate >= (0)::numeric) AND (ppn_rate <= (100)::numeric))),
    CONSTRAINT purchase_orders_budget_exch_rate_chk CHECK ((budget_exch_rate > (0)::numeric)),
    CONSTRAINT purchase_orders_status_check CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diajukan'::character varying, 'Disetujui'::character varying, 'Selesai'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_orders_id_seq OWNED BY public.purchase_orders.id;


--
-- Name: purchase_return_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_return_dtl (
    id integer NOT NULL,
    return_hdr_id integer NOT NULL,
    wh_receiving_dtl_id integer NOT NULL,
    grn_dtl_id integer,
    item_id integer NOT NULL,
    item_name character varying(150),
    qty_returned numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    remark text,
    CONSTRAINT purchase_return_dtl_qty_returned_check CHECK ((qty_returned > (0)::numeric))
);


--
-- Name: purchase_return_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_return_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_return_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_return_dtl_id_seq OWNED BY public.purchase_return_dtl.id;


--
-- Name: purchase_return_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_return_hdr (
    id integer NOT NULL,
    return_no character varying(50) NOT NULL,
    return_date date DEFAULT ('now'::text)::date NOT NULL,
    wh_receiving_hdr_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    supplier_id integer,
    grn_hdr_id integer,
    posting_basis character varying(30) DEFAULT 'grn_clearing'::character varying,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    reason text,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    CONSTRAINT purchase_return_hdr_status_check CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: purchase_return_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_return_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_return_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_return_hdr_id_seq OWNED BY public.purchase_return_hdr.id;


--
-- Name: spk; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk (
    id integer NOT NULL,
    spk_no character varying(50) NOT NULL,
    spk_date date NOT NULL,
    project_id integer NOT NULL,
    foreman character varying(100),
    description text,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    issued_at timestamp without time zone,
    issued_by integer,
    closed_at timestamp without time zone,
    closed_by integer,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    spk_type character varying(12) DEFAULT 'INTERNAL'::character varying NOT NULL,
    execution_subcontractor_id integer,
    work_start_date date,
    work_end_date date,
    parent_spk_id integer,
    revision_no integer DEFAULT 0 NOT NULL,
    addendum_no character varying(50),
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT ck_spk_execution_party CHECK (((((spk_type)::text = 'INTERNAL'::text) AND (execution_subcontractor_id IS NULL)) OR (((spk_type)::text = 'SUBCON'::text) AND (execution_subcontractor_id IS NOT NULL)))),
    CONSTRAINT ck_spk_type CHECK (((spk_type)::text = ANY ((ARRAY['INTERNAL'::character varying, 'SUBCON'::character varying])::text[]))),
    CONSTRAINT ck_spk_work_dates CHECK (((work_end_date IS NULL) OR (work_start_date IS NULL) OR (work_end_date >= work_start_date))),
    CONSTRAINT spk_status_check CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diterbitkan'::character varying, 'Selesai'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: spk_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_id_seq OWNED BY public.spk.id;


--
-- Name: spk_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_items (
    id integer NOT NULL,
    spk_id integer NOT NULL,
    material_id integer NOT NULL,
    qty numeric(18,4) NOT NULL,
    unit character varying(30),
    notes text,
    CONSTRAINT spk_items_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: spk_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_items_id_seq OWNED BY public.spk_items.id;


--
-- Name: spk_material_leftover_lots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_material_leftover_lots (
    id bigint NOT NULL,
    lot_no character varying(50) NOT NULL,
    project_id integer NOT NULL,
    spk_id integer NOT NULL,
    work_item_id bigint,
    supply_id bigint,
    material_id integer NOT NULL,
    source_owner_type character varying(10) NOT NULL,
    source_subcontractor_id integer,
    current_owner_type character varying(10) NOT NULL,
    current_subcontractor_id integer,
    qty_origin numeric(18,4) NOT NULL,
    qty_available numeric(18,4) NOT NULL,
    unit character varying(30),
    condition_note text,
    storage_location character varying(150),
    status character varying(15) DEFAULT 'AVAILABLE'::character varying NOT NULL,
    detected_date date DEFAULT ('now'::text)::date NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    parent_lot_id bigint,
    CONSTRAINT ck_leftover_current_owner CHECK (((((current_owner_type)::text = 'SUBCON'::text) AND (current_subcontractor_id IS NOT NULL)) OR (((current_owner_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'CLIENT'::character varying])::text[])) AND (current_subcontractor_id IS NULL)))),
    CONSTRAINT ck_leftover_qty CHECK ((qty_available <= qty_origin)),
    CONSTRAINT ck_leftover_source_owner CHECK (((((source_owner_type)::text = 'COMPANY'::text) AND (source_subcontractor_id IS NULL)) OR (((source_owner_type)::text = 'SUBCON'::text) AND (source_subcontractor_id IS NOT NULL)))),
    CONSTRAINT spk_material_leftover_lots_current_owner_type_check CHECK (((current_owner_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying, 'CLIENT'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_lots_qty_available_check CHECK ((qty_available >= (0)::numeric)),
    CONSTRAINT spk_material_leftover_lots_qty_origin_check CHECK ((qty_origin > (0)::numeric)),
    CONSTRAINT spk_material_leftover_lots_source_owner_type_check CHECK (((source_owner_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_lots_status_check CHECK (((status)::text = ANY ((ARRAY['AVAILABLE'::character varying, 'PARTIAL'::character varying, 'EXHAUSTED'::character varying, 'CLOSED'::character varying])::text[])))
);


--
-- Name: TABLE spk_material_leftover_lots; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.spk_material_leftover_lots IS 'Register kepemilikan material sisa, terpisah dari dokumen mutasi gudang material_leftover_header/items.';


--
-- Name: spk_material_leftover_lots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_material_leftover_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_material_leftover_lots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_material_leftover_lots_id_seq OWNED BY public.spk_material_leftover_lots.id;


--
-- Name: spk_material_leftover_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_material_leftover_transactions (
    id bigint NOT NULL,
    transaction_no character varying(50) NOT NULL,
    leftover_lot_id bigint NOT NULL,
    transaction_type character varying(20) NOT NULL,
    transaction_date date NOT NULL,
    seller_type character varying(10) NOT NULL,
    seller_subcontractor_id integer,
    buyer_type character varying(10) NOT NULL,
    buyer_subcontractor_id integer,
    qty numeric(18,4) NOT NULL,
    unit_price numeric(18,2) DEFAULT 0 NOT NULL,
    total_amount numeric(18,2) DEFAULT 0 NOT NULL,
    market_price numeric(18,2),
    market_price_source text,
    market_price_date date,
    reason text NOT NULL,
    status character varying(15) DEFAULT 'DRAFT'::character varying NOT NULL,
    approved_by integer,
    approved_at timestamp without time zone,
    posted_by integer,
    posted_at timestamp without time zone,
    inventory_doc_id integer,
    evidence_ref text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    inventory_out_doc_id integer,
    CONSTRAINT ck_leftover_buyer_party CHECK (((((buyer_type)::text = 'SUBCON'::text) AND (buyer_subcontractor_id IS NOT NULL)) OR (((buyer_type)::text <> 'SUBCON'::text) AND (buyer_subcontractor_id IS NULL)))),
    CONSTRAINT ck_leftover_seller_party CHECK (((((seller_type)::text = 'SUBCON'::text) AND (seller_subcontractor_id IS NOT NULL)) OR (((seller_type)::text <> 'SUBCON'::text) AND (seller_subcontractor_id IS NULL)))),
    CONSTRAINT spk_material_leftover_transactions_buyer_type_check CHECK (((buyer_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying, 'CLIENT'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_transactions_qty_check CHECK ((qty > (0)::numeric)),
    CONSTRAINT spk_material_leftover_transactions_seller_type_check CHECK (((seller_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying, 'CLIENT'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_transactions_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'APPROVED'::character varying, 'POSTED'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_transactions_total_amount_check CHECK ((total_amount >= (0)::numeric)),
    CONSTRAINT spk_material_leftover_transactions_transaction_type_check CHECK (((transaction_type)::text = ANY ((ARRAY['RETURN_COMPANY'::character varying, 'BUY_FROM_SUBCON'::character varying, 'SELL_TO_CLIENT'::character varying])::text[]))),
    CONSTRAINT spk_material_leftover_transactions_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


--
-- Name: TABLE spk_material_leftover_transactions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.spk_material_leftover_transactions IS 'Dokumen transaksi kepemilikan material sisa. Pencatatan stok tetap melalui modul inventory yang sudah ada.';


--
-- Name: spk_material_leftover_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_material_leftover_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_material_leftover_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_material_leftover_transactions_id_seq OWNED BY public.spk_material_leftover_transactions.id;


--
-- Name: spk_material_supply; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_material_supply (
    id bigint NOT NULL,
    spk_id integer NOT NULL,
    work_item_id bigint,
    material_id integer NOT NULL,
    supply_source character varying(10) NOT NULL,
    owner_type character varying(10) NOT NULL,
    subcontractor_id integer,
    qty_planned numeric(18,4) DEFAULT 0 NOT NULL,
    qty_received numeric(18,4) DEFAULT 0 NOT NULL,
    qty_accepted numeric(18,4) DEFAULT 0 NOT NULL,
    unit character varying(30),
    source_reference character varying(100),
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_spk_supply_accepted CHECK ((qty_accepted <= qty_received)),
    CONSTRAINT ck_spk_supply_owner CHECK (((((supply_source)::text = 'COMPANY'::text) AND ((owner_type)::text = 'COMPANY'::text) AND (subcontractor_id IS NULL)) OR (((supply_source)::text = 'SUBCON'::text) AND ((owner_type)::text = 'SUBCON'::text) AND (subcontractor_id IS NOT NULL)))),
    CONSTRAINT spk_material_supply_owner_type_check CHECK (((owner_type)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying])::text[]))),
    CONSTRAINT spk_material_supply_qty_accepted_check CHECK ((qty_accepted >= (0)::numeric)),
    CONSTRAINT spk_material_supply_qty_planned_check CHECK ((qty_planned >= (0)::numeric)),
    CONSTRAINT spk_material_supply_qty_received_check CHECK ((qty_received >= (0)::numeric)),
    CONSTRAINT spk_material_supply_supply_source_check CHECK (((supply_source)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying])::text[])))
);


--
-- Name: spk_material_supply_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_material_supply_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_material_supply_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_material_supply_id_seq OWNED BY public.spk_material_supply.id;


--
-- Name: spk_task_expenses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_task_expenses (
    id bigint NOT NULL,
    spk_task_id bigint NOT NULL,
    task_expense_id bigint NOT NULL,
    amount numeric(18,2) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT spk_task_expenses_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: TABLE spk_task_expenses; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.spk_task_expenses IS 'Alokasi nilai expenses SPK per Task.';


--
-- Name: spk_task_expenses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_task_expenses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_task_expenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_task_expenses_id_seq OWNED BY public.spk_task_expenses.id;


--
-- Name: spk_task_materials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_task_materials (
    id bigint NOT NULL,
    spk_task_id bigint NOT NULL,
    task_material_id bigint NOT NULL,
    qty numeric(18,4) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT spk_task_materials_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: TABLE spk_task_materials; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.spk_task_materials IS 'Alokasi Qty material SPK per Task; satu Task dapat dikerjakan melalui beberapa SPK.';


--
-- Name: spk_task_materials_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_task_materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_task_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_task_materials_id_seq OWNED BY public.spk_task_materials.id;


--
-- Name: spk_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_tasks (
    id bigint NOT NULL,
    spk_id integer NOT NULL,
    task_id integer NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: spk_tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_tasks_id_seq OWNED BY public.spk_tasks.id;


--
-- Name: spk_work_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spk_work_items (
    id bigint NOT NULL,
    spk_id integer NOT NULL,
    project_task_id integer,
    budget_item_id integer,
    work_code character varying(50),
    description text NOT NULL,
    unit character varying(30) NOT NULL,
    contract_qty numeric(18,4) NOT NULL,
    unit_price numeric(18,2) DEFAULT 0 NOT NULL,
    line_amount numeric(18,2) DEFAULT 0 NOT NULL,
    material_supply character varying(12) DEFAULT 'COMPANY'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    status character varying(15) DEFAULT 'ACTIVE'::character varying NOT NULL,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    spk_task_id bigint,
    CONSTRAINT spk_work_items_contract_qty_check CHECK ((contract_qty > (0)::numeric)),
    CONSTRAINT spk_work_items_line_amount_check CHECK ((line_amount >= (0)::numeric)),
    CONSTRAINT spk_work_items_material_supply_check CHECK (((material_supply)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying, 'MIXED'::character varying])::text[]))),
    CONSTRAINT spk_work_items_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'COMPLETED'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT spk_work_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


--
-- Name: TABLE spk_work_items; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.spk_work_items IS 'Lingkup pekerjaan terukur untuk SPK INTERNAL maupun SUBCON; bukan daftar issue material gudang.';


--
-- Name: spk_work_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spk_work_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spk_work_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spk_work_items_id_seq OWNED BY public.spk_work_items.id;


--
-- Name: stock_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_movements (
    id integer NOT NULL,
    movement_date date NOT NULL,
    item_id integer NOT NULL,
    grade_id integer,
    warehouse_id integer NOT NULL,
    movement_type character varying(3) NOT NULL,
    qty numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    reference_type character varying(30) NOT NULL,
    reference_id integer NOT NULL,
    reference_no character varying(50),
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    CONSTRAINT stock_movements_movement_type_check CHECK (((movement_type)::text = ANY ((ARRAY['IN'::character varying, 'OUT'::character varying])::text[]))),
    CONSTRAINT stock_movements_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: stock_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stock_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stock_movements_id_seq OWNED BY public.stock_movements.id;


--
-- Name: stock_opname_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_opname_dtl (
    id integer NOT NULL,
    opname_id integer NOT NULL,
    material_id integer NOT NULL,
    system_qty numeric(14,4) DEFAULT 0 NOT NULL,
    counted_qty numeric(14,4),
    diff_qty numeric(14,4) DEFAULT 0 NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    diff_value numeric(18,2) DEFAULT 0 NOT NULL,
    reason character varying(60),
    note text
);


--
-- Name: stock_opname_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stock_opname_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_opname_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stock_opname_dtl_id_seq OWNED BY public.stock_opname_dtl.id;


--
-- Name: stock_opname_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_opname_hdr (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    warehouse_id integer NOT NULL,
    opname_date date DEFAULT ('now'::text)::date NOT NULL,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone,
    submitted_by integer,
    submitted_at timestamp without time zone,
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    total_gain numeric(18,2) DEFAULT 0 NOT NULL,
    total_loss numeric(18,2) DEFAULT 0 NOT NULL,
    CONSTRAINT chk_so_status CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diajukan'::character varying, 'Disetujui'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: stock_opname_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stock_opname_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_opname_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stock_opname_hdr_id_seq OWNED BY public.stock_opname_hdr.id;


--
-- Name: stock_transfer_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_transfer_dtl (
    id integer NOT NULL,
    transfer_hdr_id integer NOT NULL,
    item_id integer NOT NULL,
    qty numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    remark text,
    CONSTRAINT stock_transfer_dtl_qty_check CHECK ((qty > (0)::numeric))
);


--
-- Name: stock_transfer_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stock_transfer_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_transfer_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stock_transfer_dtl_id_seq OWNED BY public.stock_transfer_dtl.id;


--
-- Name: stock_transfer_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_transfer_hdr (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    transfer_date date DEFAULT ('now'::text)::date NOT NULL,
    from_warehouse_id integer NOT NULL,
    to_warehouse_id integer NOT NULL,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    reversed_at timestamp without time zone,
    reversed_by integer,
    CONSTRAINT chk_stock_transfer_wh CHECK ((from_warehouse_id <> to_warehouse_id))
);


--
-- Name: stock_transfer_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stock_transfer_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stock_transfer_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stock_transfer_hdr_id_seq OWNED BY public.stock_transfer_hdr.id;


--
-- Name: subcon_contract_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_contract_tasks (
    id integer NOT NULL,
    contract_id integer NOT NULL,
    task_id integer,
    task_name character varying(255) NOT NULL,
    task_value numeric(18,2) NOT NULL,
    notes text,
    work_item_id bigint
);


--
-- Name: subcon_contract_tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_contract_tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_contract_tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_contract_tasks_id_seq OWNED BY public.subcon_contract_tasks.id;


--
-- Name: subcon_contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_contracts (
    id integer NOT NULL,
    spk_no character varying(50) NOT NULL,
    project_id integer NOT NULL,
    subcontractor_id integer NOT NULL,
    title character varying(255) NOT NULL,
    scope text,
    budget_item_id integer,
    contract_value numeric(18,2) NOT NULL,
    ppn_rate numeric(5,2) DEFAULT 0 NOT NULL,
    pph_rate numeric(5,2) DEFAULT 0 NOT NULL,
    retention_pct numeric(5,2) DEFAULT 0 NOT NULL,
    dp_pct numeric(5,2) DEFAULT 0 NOT NULL,
    start_date date,
    end_date date,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone,
    approved_by integer,
    approved_at timestamp without time zone,
    closed_by integer,
    closed_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    spk_id integer,
    contract_revision integer DEFAULT 0 NOT NULL,
    material_supply_default character varying(12) DEFAULT 'SUBCON'::character varying NOT NULL,
    CONSTRAINT chk_sc_status CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diajukan'::character varying, 'Disetujui'::character varying, 'Selesai'::character varying, 'Dibatalkan'::character varying])::text[]))),
    CONSTRAINT chk_sc_value CHECK ((contract_value > (0)::numeric)),
    CONSTRAINT ck_subcon_material_supply_default CHECK (((material_supply_default)::text = ANY ((ARRAY['COMPANY'::character varying, 'SUBCON'::character varying, 'MIXED'::character varying])::text[])))
);


--
-- Name: subcon_contracts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_contracts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_contracts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_contracts_id_seq OWNED BY public.subcon_contracts.id;


--
-- Name: subcon_inspection_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_inspection_items (
    id bigint NOT NULL,
    inspection_id bigint NOT NULL,
    progress_qty_item_id bigint NOT NULL,
    qty_inspected numeric(18,4) DEFAULT 0 NOT NULL,
    qty_passed numeric(18,4) DEFAULT 0 NOT NULL,
    qty_rejected numeric(18,4) DEFAULT 0 NOT NULL,
    qty_rework numeric(18,4) DEFAULT 0 NOT NULL,
    result character varying(12) DEFAULT 'PENDING'::character varying NOT NULL,
    evidence_ref text,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_inspection_qty_sum CHECK ((((qty_passed + qty_rejected) + qty_rework) <= qty_inspected)),
    CONSTRAINT subcon_inspection_items_qty_inspected_check CHECK ((qty_inspected >= (0)::numeric)),
    CONSTRAINT subcon_inspection_items_qty_passed_check CHECK ((qty_passed >= (0)::numeric)),
    CONSTRAINT subcon_inspection_items_qty_rejected_check CHECK ((qty_rejected >= (0)::numeric)),
    CONSTRAINT subcon_inspection_items_qty_rework_check CHECK ((qty_rework >= (0)::numeric)),
    CONSTRAINT subcon_inspection_items_result_check CHECK (((result)::text = ANY ((ARRAY['PASS'::character varying, 'PARTIAL'::character varying, 'REJECT'::character varying, 'REWORK'::character varying, 'PENDING'::character varying])::text[])))
);


--
-- Name: subcon_inspection_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_inspection_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_inspection_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_inspection_items_id_seq OWNED BY public.subcon_inspection_items.id;


--
-- Name: subcon_inspections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_inspections (
    id bigint NOT NULL,
    inspection_no character varying(50) NOT NULL,
    progress_id integer NOT NULL,
    inspection_date date NOT NULL,
    inspector_id integer,
    status character varying(15) DEFAULT 'DRAFT'::character varying NOT NULL,
    general_result text,
    evidence_ref text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT subcon_inspections_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying, 'CANCELLED'::character varying])::text[])))
);


--
-- Name: TABLE subcon_inspections; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.subcon_inspections IS 'Inspeksi kuantitas; hanya qty_passed pada inspeksi APPROVED dapat disertifikasi.';


--
-- Name: subcon_inspections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_inspections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_inspections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_inspections_id_seq OWNED BY public.subcon_inspections.id;


--
-- Name: subcon_invoice_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_invoice_payments (
    id integer NOT NULL,
    payment_no character varying(30) NOT NULL,
    invoice_id integer NOT NULL,
    contract_id integer NOT NULL,
    payment_date date NOT NULL,
    account_id integer NOT NULL,
    payment_method character varying(20) DEFAULT 'BANK_TRANSFER'::character varying NOT NULL,
    amount numeric(15,2) DEFAULT 0 NOT NULL,
    reference_no character varying(100),
    notes text,
    status character varying(20) DEFAULT 'Posted'::character varying NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT chk_subcon_inv_pay_amount CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_subcon_inv_pay_status CHECK (((status)::text = ANY ((ARRAY['Posted'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: TABLE subcon_invoice_payments; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.subcon_invoice_payments IS 'Pembayaran invoice subkontraktor (step 2: release payment)';


--
-- Name: subcon_invoice_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_invoice_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_invoice_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_invoice_payments_id_seq OWNED BY public.subcon_invoice_payments.id;


--
-- Name: subcon_invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_invoices (
    id integer NOT NULL,
    invoice_no character varying(30) NOT NULL,
    contract_id integer NOT NULL,
    progress_id integer,
    invoice_date date NOT NULL,
    due_date date,
    total_amount numeric(15,2) DEFAULT 0 NOT NULL,
    paid_amount numeric(15,2) DEFAULT 0 NOT NULL,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    reference_no character varying(100),
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone,
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    certificate_id bigint,
    certificate_amount_snapshot numeric(18,2),
    CONSTRAINT chk_subcon_inv_amount CHECK (((total_amount >= (0)::numeric) AND (paid_amount >= (0)::numeric))),
    CONSTRAINT chk_subcon_inv_status CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diajukan'::character varying, 'Disetujui'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: TABLE subcon_invoices; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.subcon_invoices IS 'Invoice subkontraktor (step 1: terima invoice dari subkon)';


--
-- Name: subcon_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_invoices_id_seq OWNED BY public.subcon_invoices.id;


--
-- Name: subcon_payment_certificate_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_payment_certificate_items (
    id bigint NOT NULL,
    certificate_id bigint NOT NULL,
    inspection_item_id bigint NOT NULL,
    work_item_id bigint NOT NULL,
    qty_certified numeric(18,4) NOT NULL,
    unit_price_snapshot numeric(18,2) NOT NULL,
    amount_certified numeric(18,2) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT subcon_payment_certificate_items_amount_certified_check CHECK ((amount_certified >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificate_items_qty_certified_check CHECK ((qty_certified > (0)::numeric)),
    CONSTRAINT subcon_payment_certificate_items_unit_price_snapshot_check CHECK ((unit_price_snapshot >= (0)::numeric))
);


--
-- Name: subcon_payment_certificate_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_payment_certificate_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_payment_certificate_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_payment_certificate_items_id_seq OWNED BY public.subcon_payment_certificate_items.id;


--
-- Name: subcon_payment_certificates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_payment_certificates (
    id bigint NOT NULL,
    certificate_no character varying(50) NOT NULL,
    contract_id integer NOT NULL,
    progress_id integer NOT NULL,
    inspection_id bigint NOT NULL,
    certificate_date date NOT NULL,
    gross_amount numeric(18,2) DEFAULT 0 NOT NULL,
    ppn_amount numeric(18,2) DEFAULT 0 NOT NULL,
    pph_amount numeric(18,2) DEFAULT 0 NOT NULL,
    retention_amount numeric(18,2) DEFAULT 0 NOT NULL,
    dp_recovery numeric(18,2) DEFAULT 0 NOT NULL,
    other_deduction numeric(18,2) DEFAULT 0 NOT NULL,
    net_payable numeric(18,2) DEFAULT 0 NOT NULL,
    status character varying(15) DEFAULT 'DRAFT'::character varying NOT NULL,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT subcon_payment_certificates_dp_recovery_check CHECK ((dp_recovery >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_gross_amount_check CHECK ((gross_amount >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_net_payable_check CHECK ((net_payable >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_other_deduction_check CHECK ((other_deduction >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_pph_amount_check CHECK ((pph_amount >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_ppn_amount_check CHECK ((ppn_amount >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_retention_amount_check CHECK ((retention_amount >= (0)::numeric)),
    CONSTRAINT subcon_payment_certificates_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'APPROVED'::character varying, 'CANCELLED'::character varying])::text[])))
);


--
-- Name: TABLE subcon_payment_certificates; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.subcon_payment_certificates IS 'Sertifikat pembayaran berbasis kuantitas yang lolos inspeksi.';


--
-- Name: subcon_payment_certificates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_payment_certificates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_payment_certificates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_payment_certificates_id_seq OWNED BY public.subcon_payment_certificates.id;


--
-- Name: subcon_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_payments (
    id integer NOT NULL,
    payment_no character varying(50) NOT NULL,
    contract_id integer NOT NULL,
    pay_type character varying(10) NOT NULL,
    progress_id integer,
    payment_date date NOT NULL,
    account_id bigint NOT NULL,
    amount numeric(18,2) NOT NULL,
    reference_no character varying(100),
    notes text,
    status character varying(12) DEFAULT 'Posted'::character varying NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT chk_spy_amount CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_spy_status CHECK (((status)::text = ANY ((ARRAY['Posted'::character varying, 'Dibatalkan'::character varying])::text[]))),
    CONSTRAINT chk_spy_type CHECK (((pay_type)::text = ANY ((ARRAY['DP'::character varying, 'PROGRESS'::character varying, 'RETENSI'::character varying])::text[])))
);


--
-- Name: subcon_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_payments_id_seq OWNED BY public.subcon_payments.id;


--
-- Name: subcon_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_progress (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    contract_id integer NOT NULL,
    progress_date date NOT NULL,
    status character varying(20) DEFAULT 'Draft'::character varying NOT NULL,
    gross_amount numeric(18,2) DEFAULT 0 NOT NULL,
    ppn_amount numeric(18,2) DEFAULT 0 NOT NULL,
    pph_amount numeric(18,2) DEFAULT 0 NOT NULL,
    retention_amount numeric(18,2) DEFAULT 0 NOT NULL,
    dp_recovery numeric(18,2) DEFAULT 0 NOT NULL,
    net_payable numeric(18,2) DEFAULT 0 NOT NULL,
    cum_pct numeric(7,3) DEFAULT 0 NOT NULL,
    evidence_ref character varying(255),
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone,
    approved_by integer,
    approved_at timestamp without time zone,
    cancelled_by integer,
    cancelled_at timestamp without time zone,
    cancel_reason text,
    CONSTRAINT chk_sp_status CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Disetujui'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: subcon_progress_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_progress_dtl (
    id integer NOT NULL,
    progress_id integer NOT NULL,
    contract_task_id integer NOT NULL,
    prev_pct numeric(7,3) DEFAULT 0 NOT NULL,
    cum_pct numeric(7,3) DEFAULT 0 NOT NULL,
    amount numeric(18,2) DEFAULT 0 NOT NULL
);


--
-- Name: subcon_progress_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_progress_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_progress_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_progress_dtl_id_seq OWNED BY public.subcon_progress_dtl.id;


--
-- Name: subcon_progress_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_progress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_progress_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_progress_id_seq OWNED BY public.subcon_progress.id;


--
-- Name: subcon_progress_qty_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcon_progress_qty_items (
    id bigint NOT NULL,
    progress_id integer NOT NULL,
    contract_task_id integer NOT NULL,
    work_item_id bigint NOT NULL,
    qty_claimed numeric(18,4) NOT NULL,
    unit_price_snapshot numeric(18,2) NOT NULL,
    amount_claimed numeric(18,2) NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT subcon_progress_qty_items_amount_claimed_check CHECK ((amount_claimed >= (0)::numeric)),
    CONSTRAINT subcon_progress_qty_items_qty_claimed_check CHECK ((qty_claimed > (0)::numeric)),
    CONSTRAINT subcon_progress_qty_items_unit_price_snapshot_check CHECK ((unit_price_snapshot >= (0)::numeric))
);


--
-- Name: TABLE subcon_progress_qty_items; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.subcon_progress_qty_items IS 'Klaim kuantitas Subcon. Belum menjadi dasar pembayaran sampai lolos inspeksi.';


--
-- Name: subcon_progress_qty_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcon_progress_qty_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcon_progress_qty_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcon_progress_qty_items_id_seq OWNED BY public.subcon_progress_qty_items.id;


--
-- Name: subcontractors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcontractors (
    id integer NOT NULL,
    code character varying(30),
    name character varying(255) NOT NULL,
    npwp_no character varying(30),
    is_pkp boolean DEFAULT false NOT NULL,
    contact_person character varying(255),
    phone character varying(50),
    address text,
    bank_name character varying(100),
    bank_account_no character varying(100),
    bank_account_name character varying(150),
    pph_rate numeric(5,2) DEFAULT 2.65 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: subcontractors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcontractors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcontractors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcontractors_id_seq OWNED BY public.subcontractors.id;


--
-- Name: supplier_payment_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplier_payment_items (
    id integer NOT NULL,
    supplier_payment_id integer NOT NULL,
    invoice_id integer NOT NULL,
    amount_paid numeric(18,2) NOT NULL,
    CONSTRAINT supplier_payment_items_amount_paid_check CHECK ((amount_paid > (0)::numeric))
);


--
-- Name: supplier_payment_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supplier_payment_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_payment_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supplier_payment_items_id_seq OWNED BY public.supplier_payment_items.id;


--
-- Name: supplier_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplier_payments (
    id integer NOT NULL,
    payment_no character varying(50) NOT NULL,
    payment_date date NOT NULL,
    supplier_id integer NOT NULL,
    account_id integer,
    payment_method character varying(30) DEFAULT 'BANK_TRANSFER'::character varying NOT NULL,
    total_amount numeric(18,2) NOT NULL,
    allocated_amount numeric(18,2) DEFAULT 0 NOT NULL,
    excess_amount numeric(18,2) DEFAULT 0 NOT NULL,
    reference_no character varying(100),
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    deposit_applied numeric(18,2) DEFAULT 0 NOT NULL
);


--
-- Name: supplier_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supplier_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supplier_payments_id_seq OWNED BY public.supplier_payments.id;


--
-- Name: supplier_return_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplier_return_dtl (
    id integer NOT NULL,
    return_hdr_id integer NOT NULL,
    material_id integer NOT NULL,
    qty_claim numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    defect_type character varying(40) NOT NULL,
    defect_note text,
    evidence_ref character varying(255),
    CONSTRAINT supplier_return_dtl_qty_claim_check CHECK ((qty_claim > (0)::numeric))
);


--
-- Name: supplier_return_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supplier_return_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_return_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supplier_return_dtl_id_seq OWNED BY public.supplier_return_dtl.id;


--
-- Name: supplier_return_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplier_return_hdr (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    return_date date DEFAULT ('now'::text)::date NOT NULL,
    project_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    supplier_id integer NOT NULL,
    po_id integer,
    supplier_do_no character varying(100),
    status character varying(20) DEFAULT 'Diajukan'::character varying NOT NULL,
    remark text,
    material_out_id integer,
    sent_date date,
    carrier character varying(100),
    resolution_type character varying(20),
    resolution_date date,
    credit_note_no character varying(100),
    credit_amount numeric(18,2),
    resolution_note text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    wh_receiving_hdr_id integer,
    CONSTRAINT chk_supplier_return_status CHECK (((status)::text = ANY ((ARRAY['Diajukan'::character varying, 'Diperintahkan'::character varying, 'Disiapkan'::character varying, 'Dikirim'::character varying, 'Diganti'::character varying, 'Kredit'::character varying, 'Dibatalkan'::character varying])::text[])))
);


--
-- Name: supplier_return_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supplier_return_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplier_return_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supplier_return_hdr_id_seq OWNED BY public.supplier_return_hdr.id;


--
-- Name: zz_deprecated_suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.zz_deprecated_suppliers (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    contact_person character varying(255) DEFAULT ''::character varying,
    phone character varying(50) DEFAULT ''::character varying,
    email character varying(255) DEFAULT ''::character varying,
    address text DEFAULT ''::text,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.suppliers_id_seq OWNED BY public.zz_deprecated_suppliers.id;


--
-- Name: task_expenses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.task_expenses (
    id bigint NOT NULL,
    task_id integer NOT NULL,
    budget_item_id integer NOT NULL,
    amount_budget numeric(18,2) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT task_expenses_amount_budget_check CHECK ((amount_budget > (0)::numeric))
);


--
-- Name: TABLE task_expenses; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.task_expenses IS 'Alokasi nilai item RAB non-Material ke Task.';


--
-- Name: task_expenses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.task_expenses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: task_expenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.task_expenses_id_seq OWNED BY public.task_expenses.id;


--
-- Name: task_materials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.task_materials (
    id bigint NOT NULL,
    task_id integer NOT NULL,
    budget_item_id integer NOT NULL,
    qty_budget numeric(18,4) NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT task_materials_qty_budget_check CHECK ((qty_budget > (0)::numeric))
);


--
-- Name: TABLE task_materials; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.task_materials IS 'Alokasi item RAB Material ke Task; satu item RAB boleh dibagi ke banyak Task.';


--
-- Name: task_materials_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.task_materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: task_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.task_materials_id_seq OWNED BY public.task_materials.id;


--
-- Name: tax_exchange_rate_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tax_exchange_rate_dtl (
    id integer NOT NULL,
    hdr_id integer NOT NULL,
    currency_id integer,
    currency_code character varying(10) NOT NULL,
    currency_name character varying(100),
    unit_multiplier numeric(10,2) DEFAULT 1,
    rate numeric(18,4) NOT NULL,
    rate_per_unit numeric(18,6),
    prev_rate numeric(18,4),
    change_amount numeric(18,4),
    change_direction character varying(10),
    CONSTRAINT chk_unit_multiplier CHECK ((unit_multiplier > (0)::numeric))
);


--
-- Name: tax_exchange_rate_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tax_exchange_rate_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tax_exchange_rate_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tax_exchange_rate_dtl_id_seq OWNED BY public.tax_exchange_rate_dtl.id;


--
-- Name: tax_exchange_rate_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tax_exchange_rate_hdr (
    id integer NOT NULL,
    kmk_no character varying(100) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    file_url text,
    remark text,
    created_at timestamp without time zone DEFAULT now(),
    created_by integer,
    CONSTRAINT chk_tax_rate_period CHECK ((end_date >= start_date))
);


--
-- Name: tax_exchange_rate_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tax_exchange_rate_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tax_exchange_rate_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tax_exchange_rate_hdr_id_seq OWNED BY public.tax_exchange_rate_hdr.id;


--
-- Name: v_financial_summary; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_financial_summary AS
 SELECT COALESCE(sum(
        CASE
            WHEN ((financial_incomes.status)::text = 'PAID'::text) THEN financial_incomes.amount
            ELSE (0)::numeric
        END), (0)::numeric) AS total_pendapatan,
    count(
        CASE
            WHEN ((financial_incomes.status)::text = 'PAID'::text) THEN 1
            ELSE NULL::integer
        END) AS total_transaksi
   FROM public.financial_incomes
  WHERE (date_trunc('month'::text, (financial_incomes.income_date)::timestamp with time zone) = date_trunc('month'::text, (('now'::text)::date)::timestamp with time zone));


--
-- Name: v_latest_tax_rates; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_latest_tax_rates AS
 WITH ranked_rates AS (
         SELECT d.id,
            d.currency_code,
            d.currency_name,
            d.rate AS rate_value,
            h.kmk_no AS kmk_number,
            h.start_date AS valid_from,
            h.end_date AS valid_to,
            h.created_at AS updated_at,
            lag(d.rate) OVER (PARTITION BY d.currency_code ORDER BY h.start_date) AS prev_rate_value
           FROM (public.tax_exchange_rate_hdr h
             JOIN public.tax_exchange_rate_dtl d ON ((h.id = d.hdr_id)))
        )
 SELECT ranked_rates.currency_code,
    ranked_rates.currency_name,
    ranked_rates.rate_value AS rate_val,
    COALESCE((ranked_rates.rate_value - ranked_rates.prev_rate_value), (0)::numeric) AS change_val,
        CASE
            WHEN ((ranked_rates.rate_value - ranked_rates.prev_rate_value) > (0)::numeric) THEN 'UP'::text
            WHEN ((ranked_rates.rate_value - ranked_rates.prev_rate_value) < (0)::numeric) THEN 'DOWN'::text
            ELSE 'STAY'::text
        END AS change_direction,
    ranked_rates.kmk_number,
    ranked_rates.valid_from,
    ranked_rates.valid_to,
    ranked_rates.updated_at
   FROM ranked_rates
  WHERE (ranked_rates.valid_from = ( SELECT max(tax_exchange_rate_hdr.start_date) AS max
           FROM public.tax_exchange_rate_hdr));


--
-- Name: v_material_budget_monitoring; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_material_budget_monitoring AS
 SELECT b.project_id,
    b.material_id,
    b.qty_plan AS budget_qty,
    b.unit_price_plan AS unit_price,
    (COALESCE(b.qty_plan, (0)::numeric) * COALESCE(b.unit_price_plan, (0)::numeric)) AS total_budget_cost,
    COALESCE(act.total_actual_cost, (0)::numeric) AS total_actual_cost,
    COALESCE(act.total_qty_out, (0)::numeric) AS total_qty_out,
        CASE
            WHEN (COALESCE(act.total_qty_out, (0)::numeric) > b.qty_plan) THEN 'OVERBUDGET'::text
            WHEN (COALESCE(act.total_qty_out, (0)::numeric) >= (b.qty_plan * 0.8)) THEN 'WARNING'::text
            ELSE 'ON TRACK'::text
        END AS status
   FROM (public.budget_items b
     LEFT JOIN ( SELECT moh.project_id,
            moi.material_id,
            sum(moi.qty_out) AS total_qty_out,
            sum((moi.qty_out * moi.unit_cost)) AS total_actual_cost
           FROM (public.material_out_items moi
             JOIN public.material_out_header moh ON ((moh.id = moi.material_out_id)))
          GROUP BY moh.project_id, moi.material_id) act ON (((act.project_id = b.project_id) AND (act.material_id = b.material_id))));


--
-- Name: v_project_material_detail_vs_progress; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_project_material_detail_vs_progress AS
 WITH rab AS (
         SELECT bi.project_id,
            bi.material_id,
            sum(bi.qty_plan) AS rab_qty,
            sum((bi.qty_plan * COALESCE(bi.unit_price_plan, (0)::numeric))) AS rab_value
           FROM public.budget_items bi
          WHERE (bi.material_id IS NOT NULL)
          GROUP BY bi.project_id, bi.material_id
        ), used AS (
         SELECT h.project_id,
            i.material_id,
            sum(i.qty_out) AS used_qty,
            sum((i.qty_out * i.unit_cost)) AS used_value
           FROM (public.material_out_header h
             JOIN public.material_out_items i ON ((i.material_out_id = h.id)))
          WHERE (((h.status)::text = 'posted'::text) AND ((h.issue_type)::text = 'PROJECT'::text))
          GROUP BY h.project_id, i.material_id
        )
 SELECT r.project_id,
    r.material_id,
    m.material_code,
    m.material_name,
    m.unit,
    r.rab_qty,
    COALESCE(u.used_qty, (0)::numeric) AS used_qty,
        CASE
            WHEN (r.rab_qty > (0)::numeric) THEN round(((COALESCE(u.used_qty, (0)::numeric) / r.rab_qty) * (100)::numeric), 2)
            ELSE NULL::numeric
        END AS usage_pct,
    COALESCE((p.progress)::integer, 0) AS progress_pct,
        CASE
            WHEN (r.rab_qty > (0)::numeric) THEN round((((COALESCE(u.used_qty, (0)::numeric) / r.rab_qty) * (100)::numeric) - (COALESCE((p.progress)::integer, 0))::numeric), 2)
            ELSE NULL::numeric
        END AS gap_pts,
    round((r.rab_value * COALESCE(NULLIF(p.budget_exch_rate, (0)::numeric), (1)::numeric)), 2) AS rab_value,
    round(COALESCE(u.used_value, (0)::numeric), 2) AS used_value
   FROM (((rab r
     JOIN public.materials m ON ((m.id = r.material_id)))
     JOIN public.projects p ON ((p.id = r.project_id)))
     LEFT JOIN used u ON (((u.project_id = r.project_id) AND (u.material_id = r.material_id))));


--
-- Name: v_project_material_vs_progress; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_project_material_vs_progress AS
 WITH base AS (
         SELECT p.id AS project_id,
            p.name AS project_name,
            c.name AS client_name,
            p.status AS project_status,
            (COALESCE((p.progress)::integer, 0))::numeric AS progress_pct,
            COALESCE(NULLIF(p.budget_exch_rate, (0)::numeric), (1)::numeric) AS rate
           FROM (public.projects p
             LEFT JOIN public.clients c ON ((c.id = p.client_id)))
        ), rab AS (
         SELECT bi.project_id,
            sum((bi.qty_plan * COALESCE(bi.unit_price_plan, (0)::numeric))) AS rab_value
           FROM public.budget_items bi
          WHERE (bi.material_id IS NOT NULL)
          GROUP BY bi.project_id
        ), used AS (
         SELECT h.project_id,
            sum((i.qty_out * i.unit_cost)) AS used_value
           FROM (public.material_out_header h
             JOIN public.material_out_items i ON ((i.material_out_id = h.id)))
          WHERE (((h.status)::text = 'posted'::text) AND ((h.issue_type)::text = 'PROJECT'::text))
          GROUP BY h.project_id
        ), calc AS (
         SELECT b.project_id,
            b.project_name,
            b.client_name,
            b.project_status,
            b.progress_pct,
            b.rate,
            round((COALESCE(r.rab_value, (0)::numeric) * b.rate), 2) AS rab_value,
            round(COALESCE(u.used_value, (0)::numeric), 2) AS used_value
           FROM ((base b
             LEFT JOIN rab r ON ((r.project_id = b.project_id)))
             LEFT JOIN used u ON ((u.project_id = b.project_id)))
        )
 SELECT calc.project_id,
    calc.project_name,
    calc.client_name,
    calc.project_status,
    calc.progress_pct,
    calc.rab_value,
    calc.used_value,
        CASE
            WHEN (calc.rab_value > (0)::numeric) THEN round(((calc.used_value / calc.rab_value) * (100)::numeric), 2)
            ELSE NULL::numeric
        END AS usage_pct,
        CASE
            WHEN (calc.rab_value > (0)::numeric) THEN round((((calc.used_value / calc.rab_value) * (100)::numeric) - calc.progress_pct), 2)
            ELSE NULL::numeric
        END AS gap_pts,
        CASE
            WHEN (calc.rab_value <= (0)::numeric) THEN 'TANPA RAB'::text
            WHEN ((((calc.used_value / calc.rab_value) * (100)::numeric) - calc.progress_pct) > (20)::numeric) THEN 'KRITIS'::text
            WHEN ((((calc.used_value / calc.rab_value) * (100)::numeric) - calc.progress_pct) > (10)::numeric) THEN 'WASPADA'::text
            ELSE 'NORMAL'::text
        END AS flag
   FROM calc;


--
-- Name: wh_receiving_dtl; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wh_receiving_dtl (
    id integer NOT NULL,
    wh_receiving_hdr_id integer NOT NULL,
    grn_dtl_id integer NOT NULL,
    item_id integer NOT NULL,
    item_name character varying(150) NOT NULL,
    qty_received numeric(14,4) NOT NULL,
    unit_cost numeric(15,4) DEFAULT 0 NOT NULL,
    bin_location_id integer,
    lot_no character varying(50),
    grade_id integer,
    remark text,
    unit_cost_txn numeric(15,4),
    currency_id bigint,
    exch_rate numeric(15,4),
    CONSTRAINT wh_receiving_dtl_qty_received_check CHECK ((qty_received > (0)::numeric))
);


--
-- Name: wh_receiving_hdr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wh_receiving_hdr (
    id integer NOT NULL,
    ref_no character varying(50) NOT NULL,
    grn_hdr_id integer NOT NULL,
    warehouse_id integer NOT NULL,
    received_date date DEFAULT ('now'::text)::date NOT NULL,
    received_by integer,
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    reversed_at timestamp without time zone,
    reversed_by integer,
    created_at timestamp without time zone DEFAULT now(),
    remark text,
    CONSTRAINT wh_receiving_hdr_status_check CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: v_stock_fifo_layers; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_stock_fifo_layers AS
 SELECT h.warehouse_id,
    d.item_id,
    d.id AS layer_id,
    h.ref_no AS rcv_ref,
    h.received_date,
    d.lot_no,
    d.unit_cost,
    d.qty_received,
    COALESCE(ret.q, (0)::numeric) AS qty_returned,
    COALESCE(al.q, (0)::numeric) AS qty_issued,
    ((d.qty_received - COALESCE(ret.q, (0)::numeric)) - COALESCE(al.q, (0)::numeric)) AS remaining
   FROM (((public.wh_receiving_dtl d
     JOIN public.wh_receiving_hdr h ON ((h.id = d.wh_receiving_hdr_id)))
     LEFT JOIN ( SELECT rd.wh_receiving_dtl_id AS id,
            sum(rd.qty_returned) AS q
           FROM (public.purchase_return_dtl rd
             JOIN public.purchase_return_hdr rh ON ((rh.id = rd.return_hdr_id)))
          WHERE ((rh.status)::text = 'posted'::text)
          GROUP BY rd.wh_receiving_dtl_id) ret ON ((ret.id = d.id)))
     LEFT JOIN ( SELECT a.layer_id AS id,
            sum(a.qty) AS q
           FROM ((public.material_issue_allocations a
             JOIN public.material_out_items i ON ((i.id = a.out_item_id)))
             JOIN public.material_out_header oh ON ((oh.id = i.material_out_id)))
          WHERE (((a.layer_source)::text = 'WHRCV'::text) AND ((oh.status)::text = 'posted'::text) AND (oh.reversed_at IS NULL))
          GROUP BY a.layer_id) al ON ((al.id = d.id)))
  WHERE (((h.status)::text = 'posted'::text) AND (h.reversed_at IS NULL));


--
-- Name: view_department_executive_log; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_department_executive_log AS
 SELECT row_number() OVER (PARTITION BY dat.employee_id ORDER BY dat.date, dat.user_time) AS count,
    dat.employee_id AS tx_id,
    dat.date,
    (
        CASE
            WHEN (substr(dat.amend_name, 1, 9) = 'from_date'::text) THEN (('[EDIT] - '::text || substr(dat.amend_name, 11, length(dat.amend_name))) || ' -> Start Date'::text)
            WHEN (substr(dat.amend_name, 1, 7) = 'to_date'::text) THEN (('[EDIT] - '::text || substr(dat.amend_name, 9, length(dat.amend_name))) || ' -> End Date'::text)
            WHEN (substr(dat.amend_name, 1, 6) = 'remark'::text) THEN (('[EDIT] - '::text || substr(dat.amend_name, 8, length(dat.amend_name))) || ' -> Remark'::text)
            ELSE upper(dat.amend_name)
        END)::character varying(200) AS amend_name,
        CASE
            WHEN ((dat.amend_name = 'create'::text) OR (dat.amend_name = 'delete'::text)) THEN (( SELECT m_department.name
               FROM public.m_department
              WHERE ((m_department.id)::text = dat.before_amendment)))::text
            ELSE dat.before_amendment
        END AS before_amendment,
        CASE
            WHEN ((dat.amend_name = 'create'::text) OR (dat.amend_name = 'delete'::text)) THEN (( SELECT m_department.name
               FROM public.m_department
              WHERE ((m_department.id)::text = dat.after_amendment)))::text
            ELSE dat.after_amendment
        END AS after_amendment,
    dat.user_name,
    to_char(dat.user_time, 'HH24:MI:SS'::text) AS user_time
   FROM ( SELECT d.action,
            (d.old_values OPERATOR(public.->) 'employee_id'::text) AS employee_id,
            d.action,
            ((unnest(d.updated_cols) || '/'::text) || (t.name)::text) AS amend_name,
            unnest((d.old_values OPERATOR(public.->) d.updated_cols)) AS before_amendment,
            unnest((d.new_values OPERATOR(public.->) d.updated_cols)) AS after_amendment,
            d.app_user_name AS user_name,
            (d.action_timestamp)::date AS date,
            d.action_timestamp AS user_time
           FROM (public.data_log_update d
             LEFT JOIN public.m_department t ON (((t.id)::text = (d.old_values OPERATOR(public.->) 'department_id'::text))))
          WHERE (lower(btrim(d.table_name)) ~~ 'm_department_executive'::text)
        UNION
         SELECT d.action,
            (d.new_values OPERATOR(public.->) 'employee_id'::text) AS employee_id,
            d.action,
            'create'::text AS amend_name,
            ''::text AS before_amendment,
            (d.new_values OPERATOR(public.->) 'department_id'::text) AS after_amendment,
            d.app_user_name AS user_name,
            (d.action_timestamp)::date AS date,
            d.action_timestamp AS user_time
           FROM public.data_log_insert d
          WHERE (lower(btrim(d.table_name)) ~~ 'm_department_executive'::text)
        UNION
         SELECT d.action,
            (d.old_values OPERATOR(public.->) 'employee_id'::text) AS employee_id,
            d.action,
            'delete'::text AS amend_name,
            (d.old_values OPERATOR(public.->) 'department_id'::text) AS before_amendment,
            ''::text AS after_amendment,
            d.app_user_name AS user_name,
            (d.action_timestamp)::date AS date,
            d.action_timestamp AS user_time
           FROM public.data_log_delete d
          WHERE (lower(btrim(d.table_name)) ~~ 'm_department_executive'::text)) dat(action, employee_id, action_1, amend_name, before_amendment, after_amendment, user_name, date, user_time)
  ORDER BY dat.employee_id, dat.date, (to_char(dat.user_time, 'HH24:MI:SS'::text));


--
-- Name: view_department_section; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_department_section AS
 SELECT data.location_id,
    data.department_id,
    data.section_id,
    data.location_name,
    data.depart_seq,
    data.department_init,
    data.department_name,
    data.seq_no,
    data.sub,
    data.section_name
   FROM (( SELECT l.id AS location_id,
            d.id AS department_id,
            0 AS section_id,
            l.name AS location_name,
            d.seq_no AS depart_seq,
            d.init AS department_init,
            d.name AS department_name,
            0 AS seq_no,
            NULL::character varying(200) AS sub,
            NULL::character varying(200) AS section_name
           FROM (public.m_location l
             LEFT JOIN public.m_department d ON ((d.location_id = l.id)))
          WHERE (d.active IS TRUE)
          ORDER BY l.name, d.seq_no, d.name)
        UNION
        ( SELECT l.id AS location_id,
            d.id AS department_id,
            s.id AS section_id,
            l.name AS location_name,
            d.seq_no AS depart_seq,
            d.init AS department_init,
            d.name AS department_name,
            s.seq_no,
                CASE
                    WHEN ("position"((s.section_name)::text, '/'::text) > 0) THEN ("left"((s.section_name)::text,
                    CASE
                        WHEN ("position"((s.section_name)::text, '/'::text) > 0) THEN ("position"((s.section_name)::text, '/'::text) - 1)
                        ELSE 0
                    END))::character varying
                    ELSE s.section_name
                END AS sub,
                CASE
                    WHEN ("position"((s.section_name)::text, '/'::text) > 0) THEN "right"((s.section_name)::text,
                    CASE
                        WHEN ("position"((s.section_name)::text, '/'::text) > 0) THEN (length((s.section_name)::text) - "position"((s.section_name)::text, '/'::text))
                        ELSE 0
                    END)
                    ELSE ''::text
                END AS section_name
           FROM ((public.m_location l
             LEFT JOIN public.m_department d ON ((d.location_id = l.id)))
             LEFT JOIN public.department_section s ON ((s.department_id = d.id)))
          WHERE ((s.id IS NOT NULL) AND (d.active IS TRUE))
          ORDER BY l.name, d.seq_no, d.name, s.seq_no)) data
  ORDER BY data.location_name, data.depart_seq, data.seq_no, data.department_name;


--
-- Name: view_employee_depart_status; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_employee_depart_status AS
 SELECT e.id,
    e.employee_name,
    d.id AS employee_department_id,
    l.id AS location_id,
    l.name AS location_name,
        CASE
            WHEN (lower(btrim((l.name)::text)) ~~ '%office%'::text) THEN md.init
            ELSE l.init
        END AS location_init,
    md.id AS department_id,
    md.name AS department_name,
    md.init AS department_init,
    sc.id AS employee_section_id,
    ds.id AS section_id,
    ds.section_name,
    s.id AS employee_status_id,
    ms.id AS status_id,
    ms.name AS status_name
   FROM (((((((public.m_employee e
     LEFT JOIN ( SELECT DISTINCT ON (employee_department.employee_id) employee_department.id,
            employee_department.employee_id,
            employee_department.department_id,
            employee_department.from_date
           FROM public.employee_department
          WHERE (employee_department.to_date IS NULL)
          ORDER BY employee_department.employee_id, employee_department.from_date DESC, employee_department.id DESC) d ON ((d.employee_id = e.id)))
     LEFT JOIN public.m_department md ON ((md.id = d.department_id)))
     LEFT JOIN public.m_location l ON ((l.id = md.location_id)))
     LEFT JOIN ( SELECT DISTINCT ON (employee_section.employee_id) employee_section.id,
            employee_section.employee_id,
            employee_section.section_id,
            employee_section.from_date
           FROM public.employee_section
          WHERE (employee_section.to_date IS NULL)
          ORDER BY employee_section.employee_id, employee_section.from_date DESC, employee_section.id DESC) sc ON ((sc.employee_id = e.id)))
     LEFT JOIN public.department_section ds ON ((ds.id = sc.section_id)))
     LEFT JOIN ( SELECT DISTINCT ON (employee_status.employee_id) employee_status.id,
            employee_status.employee_id,
            employee_status.status_id,
            employee_status.from_date
           FROM public.employee_status
          WHERE (employee_status.to_date IS NULL)
          ORDER BY employee_status.employee_id, employee_status.from_date DESC, employee_status.id DESC) s ON ((s.employee_id = e.id)))
     LEFT JOIN public.m_employee_status ms ON ((ms.id = s.status_id)));


--
-- Name: view_employee_department_log; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_employee_department_log AS
 SELECT 'm_employee'::text AS table_name,
    c.employee_id AS tx_id,
    'u'::text AS e_action,
    'department'::text AS amendment_name,
    c.curr_depart AS before_amendment,
    n.new_depart AS after_amendment,
    c.app_user_name,
    (c.action_timestamp)::date AS date,
    to_char(c.action_timestamp, 'HH24:MI:SS'::text) AS user_time
   FROM (( SELECT row_number() OVER () AS id_join,
            dt.employee_id,
            dt.app_user_name,
            dt.action_timestamp,
            dt.curr_depart,
            dt.new_depart
           FROM ( SELECT row_number() OVER () AS num,
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END AS employee_id,
                    d.app_user_name,
                    d.action_timestamp,
                    (d.old_values OPERATOR(public.->) 'department_id'::text) AS curr_depart,
                    (d.new_values OPERATOR(public.->) 'department_id'::text) AS new_depart
                   FROM (( SELECT data_log_insert.table_name,
                            data_log_insert.user_name,
                            data_log_insert.app_user_name,
                            data_log_insert.action,
                            data_log_insert.old_values,
                            data_log_insert.new_values,
                            data_log_insert.updated_cols,
                            data_log_insert.query,
                            data_log_insert.user_terminal,
                            data_log_insert.id,
                            data_log_insert.action_timestamp
                           FROM public.data_log_insert
                          WHERE (lower(btrim(data_log_insert.table_name)) ~~ 'employee_department'::text)
                        UNION ALL
                         SELECT data_log_update.table_name,
                            data_log_update.user_name,
                            data_log_update.app_user_name,
                            data_log_update.action,
                            data_log_update.old_values,
                            data_log_update.new_values,
                            data_log_update.updated_cols,
                            data_log_update.query,
                            data_log_update.user_terminal,
                            data_log_update.id,
                            data_log_update.action_timestamp
                           FROM public.data_log_update
                          WHERE (lower(btrim(data_log_update.table_name)) ~~ 'employee_department'::text)
                        UNION ALL
                         SELECT data_log_delete.table_name,
                            data_log_delete.user_name,
                            data_log_delete.app_user_name,
                            data_log_delete.action,
                            data_log_delete.old_values,
                            data_log_delete.new_values,
                            data_log_delete.updated_cols,
                            data_log_delete.query,
                            data_log_delete.user_terminal,
                            data_log_delete.id,
                            data_log_delete.action_timestamp
                           FROM public.data_log_delete
                          WHERE (lower(btrim(data_log_delete.table_name)) ~~ 'employee_department'::text)) d
                     LEFT JOIN public.m_employee e ON (((e.id)::text =
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END)))
                  WHERE ((lower(btrim(d.table_name)) ~~ 'employee_department'::text) AND (e.id IS NOT NULL))
                  ORDER BY d.table_name, (
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END)::integer, d.action_timestamp) dt
          WHERE (mod(dt.num, (2)::bigint) = 1)) c
     LEFT JOIN ( SELECT row_number() OVER () AS id_join,
            dt.employee_id,
            dt.app_user_name,
            dt.action_timestamp,
            dt.curr_depart,
            dt.new_depart
           FROM ( SELECT row_number() OVER () AS num,
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END AS employee_id,
                    d.app_user_name,
                    d.action_timestamp,
                    (d.old_values OPERATOR(public.->) 'department_id'::text) AS curr_depart,
                    (d.new_values OPERATOR(public.->) 'department_id'::text) AS new_depart
                   FROM (( SELECT data_log_insert.table_name,
                            data_log_insert.user_name,
                            data_log_insert.app_user_name,
                            data_log_insert.action,
                            data_log_insert.old_values,
                            data_log_insert.new_values,
                            data_log_insert.updated_cols,
                            data_log_insert.query,
                            data_log_insert.user_terminal,
                            data_log_insert.id,
                            data_log_insert.action_timestamp
                           FROM public.data_log_insert
                          WHERE (lower(btrim(data_log_insert.table_name)) ~~ 'employee_department'::text)
                        UNION ALL
                         SELECT data_log_update.table_name,
                            data_log_update.user_name,
                            data_log_update.app_user_name,
                            data_log_update.action,
                            data_log_update.old_values,
                            data_log_update.new_values,
                            data_log_update.updated_cols,
                            data_log_update.query,
                            data_log_update.user_terminal,
                            data_log_update.id,
                            data_log_update.action_timestamp
                           FROM public.data_log_update
                          WHERE (lower(btrim(data_log_update.table_name)) ~~ 'employee_department'::text)
                        UNION ALL
                         SELECT data_log_delete.table_name,
                            data_log_delete.user_name,
                            data_log_delete.app_user_name,
                            data_log_delete.action,
                            data_log_delete.old_values,
                            data_log_delete.new_values,
                            data_log_delete.updated_cols,
                            data_log_delete.query,
                            data_log_delete.user_terminal,
                            data_log_delete.id,
                            data_log_delete.action_timestamp
                           FROM public.data_log_delete
                          WHERE (lower(btrim(data_log_delete.table_name)) ~~ 'employee_department'::text)) d
                     LEFT JOIN public.m_employee e ON (((e.id)::text =
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END)))
                  WHERE ((lower(btrim(d.table_name)) ~~ 'employee_department'::text) AND (e.id IS NOT NULL))
                  ORDER BY d.table_name, (
                        CASE
                            WHEN (d.old_values IS NULL) THEN (d.new_values OPERATOR(public.->) 'employee_id'::text)
                            ELSE (d.old_values OPERATOR(public.->) 'employee_id'::text)
                        END)::integer, d.action_timestamp) dt
          WHERE (mod(dt.num, (2)::bigint) = 0)) n ON ((n.id_join = c.id_join)))
  WHERE ((c.curr_depart IS NOT NULL) AND (n.new_depart IS NOT NULL));


--
-- Name: view_grn_invoice_status; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_grn_invoice_status AS
 SELECT g.id AS grn_hdr_id,
    g.ref_no AS grn_ref_no,
    g.grn_date,
    g.po_id,
    g.supplier_id,
    COALESCE(gt.total_amount, (0)::numeric) AS grn_total,
    COALESCE(it.invoiced_amount, (0)::numeric) AS invoiced_amount,
    (COALESCE(gt.total_amount, (0)::numeric) - COALESCE(it.invoiced_amount, (0)::numeric)) AS outstanding_amount
   FROM ((public.grn_hdr g
     LEFT JOIN ( SELECT grn_dtl.grn_hdr_id,
            sum((grn_dtl.qty_received * grn_dtl.unit_cost)) AS total_amount
           FROM public.grn_dtl
          GROUP BY grn_dtl.grn_hdr_id) gt ON ((gt.grn_hdr_id = g.id)))
     LEFT JOIN ( SELECT pid.grn_hdr_id,
            sum(pid.matched_amount) AS invoiced_amount
           FROM (public.purchase_invoice_dtl pid
             JOIN public.purchase_invoice_hdr pih ON ((pih.id = pid.invoice_hdr_id)))
          WHERE ((pih.status)::text <> 'cancelled'::text)
          GROUP BY pid.grn_hdr_id) it ON ((it.grn_hdr_id = g.id)));


--
-- Name: view_stock_balance; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_stock_balance AS
 SELECT stock_movements.item_id,
    stock_movements.grade_id,
    stock_movements.warehouse_id,
    sum(
        CASE
            WHEN ((stock_movements.movement_type)::text = 'IN'::text) THEN stock_movements.qty
            ELSE (- stock_movements.qty)
        END) AS balance_qty
   FROM public.stock_movements
  GROUP BY stock_movements.item_id, stock_movements.grade_id, stock_movements.warehouse_id;


--
-- Name: warehouses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warehouses (
    id integer NOT NULL,
    code character varying(20),
    name character varying(150) NOT NULL,
    project_id integer,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    location_id bigint,
    description text,
    remark text,
    total_row integer,
    col_row integer
);


--
-- Name: view_warehouse_name_init; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_warehouse_name_init AS
 SELECT l.id,
    l.init AS location_old_init,
        CASE
            WHEN ((
            CASE
                WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
                ELSE l.init
            END)::text = 'LG1'::text) THEN 'Logistic Pusat'::character varying
            WHEN ((
            CASE
                WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
                ELSE l.init
            END)::text = 'LG2'::text) THEN 'Logistic @ Unit 2'::character varying
            WHEN ((
            CASE
                WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
                ELSE l.init
            END)::text = 'FGD'::text) THEN 'W/H Barang Jadi'::character varying
            ELSE l.name
        END AS location_name,
        CASE
            WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
            ELSE l.init
        END AS location_init,
    w.id AS warehouse_id,
    w.code AS warehouse_init,
    (
        CASE
            WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("right"((w.name)::text, (length((w.name)::text) - 4)))::character varying
            ELSE w.name
        END)::character varying(255) AS warehouse_name,
    (((
        CASE
            WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
            ELSE l.init
        END)::text || ' ~ '::text) || (
        CASE
            WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("right"((w.name)::text, (length((w.name)::text) - 4)))::character varying
            ELSE w.name
        END)::text) AS warehouse,
    w.active
   FROM (public.warehouses w
     LEFT JOIN public.m_location l ON ((w.location_id = l.id)))
  WHERE (w.active = true)
  ORDER BY
        CASE
            WHEN ("position"((w.name)::text, '/'::text) > 0) THEN ("left"((w.name)::text, 3))::character varying
            ELSE l.init
        END, w.name;


--
-- Name: view_wh_rcv_dtl_return_status; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.view_wh_rcv_dtl_return_status AS
 SELECT wd.id AS wh_rcv_dtl_id,
    wd.wh_receiving_hdr_id,
    wd.grn_dtl_id,
    wd.item_id,
    wd.item_name,
    wd.qty_received,
    wd.unit_cost,
    wd.grade_id,
    COALESCE(ret.qty_returned_total, (0)::numeric) AS qty_returned,
    (wd.qty_received - COALESCE(ret.qty_returned_total, (0)::numeric)) AS qty_returnable,
    wh.warehouse_id
   FROM ((public.wh_receiving_dtl wd
     JOIN public.wh_receiving_hdr wh ON (((wh.id = wd.wh_receiving_hdr_id) AND ((wh.status)::text = 'posted'::text))))
     LEFT JOIN ( SELECT prd.wh_receiving_dtl_id,
            sum(prd.qty_returned) AS qty_returned_total
           FROM (public.purchase_return_dtl prd
             JOIN public.purchase_return_hdr prh ON (((prh.id = prd.return_hdr_id) AND ((prh.status)::text = 'posted'::text))))
          GROUP BY prd.wh_receiving_dtl_id) ret ON ((ret.wh_receiving_dtl_id = wd.id)));


--
-- Name: vw_table_relations; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_table_relations AS
 SELECT tc.constraint_name,
    tc.table_schema,
    tc.table_name AS source_table,
    kcu.column_name AS source_column,
    ccu.table_name AS referenced_table,
    ccu.column_name AS referenced_column,
    kcu.ordinal_position
   FROM ((information_schema.table_constraints tc
     JOIN information_schema.key_column_usage kcu ON ((((tc.constraint_name)::text = (kcu.constraint_name)::text) AND ((tc.constraint_schema)::text = (kcu.constraint_schema)::text))))
     JOIN information_schema.constraint_column_usage ccu ON ((((ccu.constraint_name)::text = (tc.constraint_name)::text) AND ((ccu.constraint_schema)::text = (tc.constraint_schema)::text))))
  WHERE (((tc.constraint_type)::text = 'FOREIGN KEY'::text) AND ((tc.table_schema)::text = 'public'::text));


--
-- Name: warehouses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.warehouses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: warehouses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.warehouses_id_seq OWNED BY public.warehouses.id;


--
-- Name: wh_receiving_dtl_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wh_receiving_dtl_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wh_receiving_dtl_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wh_receiving_dtl_id_seq OWNED BY public.wh_receiving_dtl.id;


--
-- Name: wh_receiving_hdr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wh_receiving_hdr_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wh_receiving_hdr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wh_receiving_hdr_id_seq OWNED BY public.wh_receiving_hdr.id;


--
-- Name: year_closing; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.year_closing (
    id integer NOT NULL,
    fiscal_year integer NOT NULL,
    closing_date date NOT NULL,
    journal_id bigint,
    journal_no character varying(40),
    revenue_total numeric(18,2) DEFAULT 0 NOT NULL,
    expense_total numeric(18,2) DEFAULT 0 NOT NULL,
    net_income numeric(18,2) DEFAULT 0 NOT NULL,
    retained_account_id integer NOT NULL,
    locked_periods text,
    remark text,
    created_by integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: year_closing_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.year_closing_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: year_closing_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.year_closing_id_seq OWNED BY public.year_closing.id;


--
-- Name: m_materials id; Type: DEFAULT; Schema: archive; Owner: -
--

ALTER TABLE ONLY archive.m_materials ALTER COLUMN id SET DEFAULT nextval('archive.m_materials_id_seq'::regclass);


--
-- Name: accounting_periods id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounting_periods ALTER COLUMN id SET DEFAULT nextval('public.accounting_periods_id_seq'::regclass);


--
-- Name: ai_variance_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_variance_logs ALTER COLUMN id SET DEFAULT nextval('public.ai_variance_logs_id_seq'::regclass);


--
-- Name: bin_locations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bin_locations ALTER COLUMN id SET DEFAULT nextval('public.bin_locations_id_seq'::regclass);


--
-- Name: budget_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.budget_items ALTER COLUMN id SET DEFAULT nextval('public.budget_items_id_seq'::regclass);


--
-- Name: chart_of_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chart_of_accounts ALTER COLUMN id SET DEFAULT nextval('public.chart_of_accounts_id_seq'::regclass);


--
-- Name: clients id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients ALTER COLUMN id SET DEFAULT nextval('public.clients_id_seq'::regclass);


--
-- Name: coa_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coa_accounts ALTER COLUMN id SET DEFAULT nextval('public.coa_accounts_id_seq'::regclass);


--
-- Name: customer_npwp id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_npwp ALTER COLUMN id SET DEFAULT nextval('public.customer_npwp_id_seq'::regclass);


--
-- Name: data_log_delete id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_log_delete ALTER COLUMN id SET DEFAULT nextval('public.data_log_delete_id_seq1'::regclass);


--
-- Name: data_log_insert id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_log_insert ALTER COLUMN id SET DEFAULT nextval('public.data_log_insert_id_seq1'::regclass);


--
-- Name: data_log_update id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_log_update ALTER COLUMN id SET DEFAULT nextval('public.data_log_update_id_seq1'::regclass);


--
-- Name: department_section id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_section ALTER COLUMN id SET DEFAULT nextval('public.department_section_id_seq'::regclass);


--
-- Name: employee_department id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_department ALTER COLUMN id SET DEFAULT nextval('public.employee_department_id_seq'::regclass);


--
-- Name: employee_section id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_section ALTER COLUMN id SET DEFAULT nextval('public.employee_section_id_seq'::regclass);


--
-- Name: employee_status id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_status ALTER COLUMN id SET DEFAULT nextval('public.employee_status_id_seq'::regclass);


--
-- Name: expense_master id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expense_master ALTER COLUMN id SET DEFAULT nextval('public.expense_master_id_seq'::regclass);


--
-- Name: financial_expense_allocations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expense_allocations ALTER COLUMN id SET DEFAULT nextval('public.financial_expense_allocations_id_seq'::regclass);


--
-- Name: financial_expenses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses ALTER COLUMN id SET DEFAULT nextval('public.financial_expenses_id_seq'::regclass);


--
-- Name: financial_incomes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes ALTER COLUMN id SET DEFAULT nextval('public.financial_incomes_id_seq'::regclass);


--
-- Name: fiscal_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fiscal_config ALTER COLUMN id SET DEFAULT nextval('public.fiscal_config_id_seq'::regclass);


--
-- Name: general_ledger id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.general_ledger ALTER COLUMN id SET DEFAULT nextval('public.general_ledger_id_seq'::regclass);


--
-- Name: grn_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl ALTER COLUMN id SET DEFAULT nextval('public.grn_dtl_id_seq'::regclass);


--
-- Name: grn_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr ALTER COLUMN id SET DEFAULT nextval('public.grn_hdr_id_seq'::regclass);


--
-- Name: item_grade id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_grade ALTER COLUMN id SET DEFAULT nextval('public.item_grade_id_seq'::regclass);


--
-- Name: item_group id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_group ALTER COLUMN id SET DEFAULT nextval('public.item_group_id_seq'::regclass);


--
-- Name: items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items ALTER COLUMN id SET DEFAULT nextval('public.items_id_seq'::regclass);


--
-- Name: journal_entries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries ALTER COLUMN id SET DEFAULT nextval('public.journal_entries_id_seq'::regclass);


--
-- Name: journal_entry_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entry_lines ALTER COLUMN id SET DEFAULT nextval('public.journal_entry_lines_id_seq'::regclass);


--
-- Name: journal_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines ALTER COLUMN id SET DEFAULT nextval('public.journal_lines_id_seq'::regclass);


--
-- Name: m_address id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address ALTER COLUMN id SET DEFAULT nextval('public.m_address_id_seq'::regclass);


--
-- Name: m_agent id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_agent ALTER COLUMN id SET DEFAULT nextval('public.m_agent_id_seq'::regclass);


--
-- Name: m_city id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_city ALTER COLUMN id SET DEFAULT nextval('public.m_city_id_seq'::regclass);


--
-- Name: m_color id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_color ALTER COLUMN id SET DEFAULT nextval('public.m_color_id_seq'::regclass);


--
-- Name: m_contact_no id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_no ALTER COLUMN id SET DEFAULT nextval('public.m_contact_no_id_seq'::regclass);


--
-- Name: m_contact_person id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_person ALTER COLUMN id SET DEFAULT nextval('public.m_contact_person_id_seq'::regclass);


--
-- Name: m_country id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_country ALTER COLUMN id SET DEFAULT nextval('public.m_country_id_seq'::regclass);


--
-- Name: m_currency id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_currency ALTER COLUMN id SET DEFAULT nextval('public.m_currency_id_seq'::regclass);


--
-- Name: m_customer id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_customer ALTER COLUMN id SET DEFAULT nextval('public.m_customer_id_seq'::regclass);


--
-- Name: m_department id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department ALTER COLUMN id SET DEFAULT nextval('public.m_department_id_seq'::regclass);


--
-- Name: m_department_executive id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department_executive ALTER COLUMN id SET DEFAULT nextval('public.m_department_executive_id_seq'::regclass);


--
-- Name: m_district id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_district ALTER COLUMN id SET DEFAULT nextval('public.m_district_id_seq'::regclass);


--
-- Name: m_employee id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_employee ALTER COLUMN id SET DEFAULT nextval('public.m_employee_id_seq'::regclass);


--
-- Name: m_employee_status id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_employee_status ALTER COLUMN id SET DEFAULT nextval('public.m_employee_status_id_seq'::regclass);


--
-- Name: m_form_group id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_group ALTER COLUMN id SET DEFAULT nextval('public.m_form_group_id_seq'::regclass);


--
-- Name: m_form_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_links ALTER COLUMN id SET DEFAULT nextval('public.m_form_links_id_seq'::regclass);


--
-- Name: m_form_name id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_name ALTER COLUMN id SET DEFAULT nextval('public.m_form_name_id_seq'::regclass);


--
-- Name: m_location id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_location ALTER COLUMN id SET DEFAULT nextval('public.m_location_id_seq'::regclass);


--
-- Name: m_region id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_region ALTER COLUMN id SET DEFAULT nextval('public.m_region_id_seq'::regclass);


--
-- Name: m_suppliers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_suppliers ALTER COLUMN id SET DEFAULT nextval('public.m_suppliers_id_seq'::regclass);


--
-- Name: m_unit id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_unit ALTER COLUMN id SET DEFAULT nextval('public.m_unit_id_seq'::regclass);


--
-- Name: m_user_access_menu id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_access_menu ALTER COLUMN id SET DEFAULT nextval('public.m_user_access_menu_id_seq'::regclass);


--
-- Name: m_user_login_status id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_login_status ALTER COLUMN id SET DEFAULT nextval('public.m_user_login_status_id_seq'::regclass);


--
-- Name: m_user_system id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_system ALTER COLUMN id SET DEFAULT nextval('public.m_user_system_id_seq'::regclass);


--
-- Name: m_village id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_village ALTER COLUMN id SET DEFAULT nextval('public.m_village_id_seq'::regclass);


--
-- Name: material_issue_allocations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_issue_allocations ALTER COLUMN id SET DEFAULT nextval('public.material_issue_allocations_id_seq'::regclass);


--
-- Name: material_leftover_header id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_header ALTER COLUMN id SET DEFAULT nextval('public.material_leftover_header_id_seq'::regclass);


--
-- Name: material_leftover_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_items ALTER COLUMN id SET DEFAULT nextval('public.material_leftover_items_id_seq'::regclass);


--
-- Name: material_out_header id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header ALTER COLUMN id SET DEFAULT nextval('public.material_out_header_id_seq'::regclass);


--
-- Name: material_out_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_items ALTER COLUMN id SET DEFAULT nextval('public.material_out_items_id_seq'::regclass);


--
-- Name: material_receipt_header id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header ALTER COLUMN id SET DEFAULT nextval('public.material_receipt_header_id_seq'::regclass);


--
-- Name: material_receipt_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_items ALTER COLUMN id SET DEFAULT nextval('public.material_receipt_items_id_seq'::regclass);


--
-- Name: materials id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materials ALTER COLUMN id SET DEFAULT nextval('public.materials_id_seq'::regclass);


--
-- Name: password_change_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_change_log ALTER COLUMN id SET DEFAULT nextval('public.password_change_log_id_seq'::regclass);


--
-- Name: pics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pics ALTER COLUMN id SET DEFAULT nextval('public.pics_id_seq'::regclass);


--
-- Name: po_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items ALTER COLUMN id SET DEFAULT nextval('public.po_items_id_seq'::regclass);


--
-- Name: project_invoices id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_invoices ALTER COLUMN id SET DEFAULT nextval('public.project_invoices_id_seq'::regclass);


--
-- Name: project_payment_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payment_items ALTER COLUMN id SET DEFAULT nextval('public.project_payment_items_id_seq'::regclass);


--
-- Name: project_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments ALTER COLUMN id SET DEFAULT nextval('public.project_payments_id_seq'::regclass);


--
-- Name: project_progress_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_progress_log ALTER COLUMN id SET DEFAULT nextval('public.project_progress_log_id_seq'::regclass);


--
-- Name: project_tasks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_tasks ALTER COLUMN id SET DEFAULT nextval('public.project_tasks_id_seq'::regclass);


--
-- Name: project_wip_release id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_wip_release ALTER COLUMN id SET DEFAULT nextval('public.project_wip_release_id_seq'::regclass);


--
-- Name: projects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects ALTER COLUMN id SET DEFAULT nextval('public.projects_id_seq'::regclass);


--
-- Name: purchase_invoice_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_dtl ALTER COLUMN id SET DEFAULT nextval('public.purchase_invoice_dtl_id_seq'::regclass);


--
-- Name: purchase_invoice_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_hdr ALTER COLUMN id SET DEFAULT nextval('public.purchase_invoice_hdr_id_seq'::regclass);


--
-- Name: purchase_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders ALTER COLUMN id SET DEFAULT nextval('public.purchase_orders_id_seq'::regclass);


--
-- Name: purchase_return_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_dtl ALTER COLUMN id SET DEFAULT nextval('public.purchase_return_dtl_id_seq'::regclass);


--
-- Name: purchase_return_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr ALTER COLUMN id SET DEFAULT nextval('public.purchase_return_hdr_id_seq'::regclass);


--
-- Name: spk id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk ALTER COLUMN id SET DEFAULT nextval('public.spk_id_seq'::regclass);


--
-- Name: spk_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_items ALTER COLUMN id SET DEFAULT nextval('public.spk_items_id_seq'::regclass);


--
-- Name: spk_material_leftover_lots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots ALTER COLUMN id SET DEFAULT nextval('public.spk_material_leftover_lots_id_seq'::regclass);


--
-- Name: spk_material_leftover_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions ALTER COLUMN id SET DEFAULT nextval('public.spk_material_leftover_transactions_id_seq'::regclass);


--
-- Name: spk_material_supply id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply ALTER COLUMN id SET DEFAULT nextval('public.spk_material_supply_id_seq'::regclass);


--
-- Name: spk_task_expenses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_expenses ALTER COLUMN id SET DEFAULT nextval('public.spk_task_expenses_id_seq'::regclass);


--
-- Name: spk_task_materials id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_materials ALTER COLUMN id SET DEFAULT nextval('public.spk_task_materials_id_seq'::regclass);


--
-- Name: spk_tasks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_tasks ALTER COLUMN id SET DEFAULT nextval('public.spk_tasks_id_seq'::regclass);


--
-- Name: spk_work_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items ALTER COLUMN id SET DEFAULT nextval('public.spk_work_items_id_seq'::regclass);


--
-- Name: stock_movements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_movements ALTER COLUMN id SET DEFAULT nextval('public.stock_movements_id_seq'::regclass);


--
-- Name: stock_opname_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_dtl ALTER COLUMN id SET DEFAULT nextval('public.stock_opname_dtl_id_seq'::regclass);


--
-- Name: stock_opname_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_hdr ALTER COLUMN id SET DEFAULT nextval('public.stock_opname_hdr_id_seq'::regclass);


--
-- Name: stock_transfer_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_dtl ALTER COLUMN id SET DEFAULT nextval('public.stock_transfer_dtl_id_seq'::regclass);


--
-- Name: stock_transfer_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_hdr ALTER COLUMN id SET DEFAULT nextval('public.stock_transfer_hdr_id_seq'::regclass);


--
-- Name: subcon_contract_tasks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contract_tasks ALTER COLUMN id SET DEFAULT nextval('public.subcon_contract_tasks_id_seq'::regclass);


--
-- Name: subcon_contracts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts ALTER COLUMN id SET DEFAULT nextval('public.subcon_contracts_id_seq'::regclass);


--
-- Name: subcon_inspection_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspection_items ALTER COLUMN id SET DEFAULT nextval('public.subcon_inspection_items_id_seq'::regclass);


--
-- Name: subcon_inspections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspections ALTER COLUMN id SET DEFAULT nextval('public.subcon_inspections_id_seq'::regclass);


--
-- Name: subcon_invoice_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments ALTER COLUMN id SET DEFAULT nextval('public.subcon_invoice_payments_id_seq'::regclass);


--
-- Name: subcon_invoices id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices ALTER COLUMN id SET DEFAULT nextval('public.subcon_invoices_id_seq'::regclass);


--
-- Name: subcon_payment_certificate_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items ALTER COLUMN id SET DEFAULT nextval('public.subcon_payment_certificate_items_id_seq'::regclass);


--
-- Name: subcon_payment_certificates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates ALTER COLUMN id SET DEFAULT nextval('public.subcon_payment_certificates_id_seq'::regclass);


--
-- Name: subcon_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments ALTER COLUMN id SET DEFAULT nextval('public.subcon_payments_id_seq'::regclass);


--
-- Name: subcon_progress id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress ALTER COLUMN id SET DEFAULT nextval('public.subcon_progress_id_seq'::regclass);


--
-- Name: subcon_progress_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_dtl ALTER COLUMN id SET DEFAULT nextval('public.subcon_progress_dtl_id_seq'::regclass);


--
-- Name: subcon_progress_qty_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items ALTER COLUMN id SET DEFAULT nextval('public.subcon_progress_qty_items_id_seq'::regclass);


--
-- Name: subcontractors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcontractors ALTER COLUMN id SET DEFAULT nextval('public.subcontractors_id_seq'::regclass);


--
-- Name: supplier_payment_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payment_items ALTER COLUMN id SET DEFAULT nextval('public.supplier_payment_items_id_seq'::regclass);


--
-- Name: supplier_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payments ALTER COLUMN id SET DEFAULT nextval('public.supplier_payments_id_seq'::regclass);


--
-- Name: supplier_return_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_dtl ALTER COLUMN id SET DEFAULT nextval('public.supplier_return_dtl_id_seq'::regclass);


--
-- Name: supplier_return_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr ALTER COLUMN id SET DEFAULT nextval('public.supplier_return_hdr_id_seq'::regclass);


--
-- Name: task_expenses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_expenses ALTER COLUMN id SET DEFAULT nextval('public.task_expenses_id_seq'::regclass);


--
-- Name: task_materials id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_materials ALTER COLUMN id SET DEFAULT nextval('public.task_materials_id_seq'::regclass);


--
-- Name: tax_exchange_rate_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_dtl ALTER COLUMN id SET DEFAULT nextval('public.tax_exchange_rate_dtl_id_seq'::regclass);


--
-- Name: tax_exchange_rate_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_hdr ALTER COLUMN id SET DEFAULT nextval('public.tax_exchange_rate_hdr_id_seq'::regclass);


--
-- Name: warehouses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses ALTER COLUMN id SET DEFAULT nextval('public.warehouses_id_seq'::regclass);


--
-- Name: wh_receiving_dtl id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl ALTER COLUMN id SET DEFAULT nextval('public.wh_receiving_dtl_id_seq'::regclass);


--
-- Name: wh_receiving_hdr id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_hdr ALTER COLUMN id SET DEFAULT nextval('public.wh_receiving_hdr_id_seq'::regclass);


--
-- Name: year_closing id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_closing ALTER COLUMN id SET DEFAULT nextval('public.year_closing_id_seq'::regclass);


--
-- Name: zz_deprecated_m_projects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_m_projects ALTER COLUMN id SET DEFAULT nextval('public.m_projects_id_seq'::regclass);


--
-- Name: zz_deprecated_m_warehouse id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_m_warehouse ALTER COLUMN id SET DEFAULT nextval('public.m_warehouse_id_seq'::regclass);


--
-- Name: zz_deprecated_suppliers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_suppliers ALTER COLUMN id SET DEFAULT nextval('public.suppliers_id_seq'::regclass);


--
-- Name: m_materials m_materials_material_code_key; Type: CONSTRAINT; Schema: archive; Owner: -
--

ALTER TABLE ONLY archive.m_materials
    ADD CONSTRAINT m_materials_material_code_key UNIQUE (material_code);


--
-- Name: m_materials m_materials_pkey; Type: CONSTRAINT; Schema: archive; Owner: -
--

ALTER TABLE ONLY archive.m_materials
    ADD CONSTRAINT m_materials_pkey PRIMARY KEY (id);


--
-- Name: accounting_periods accounting_periods_period_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_period_name_key UNIQUE (period_name);


--
-- Name: accounting_periods accounting_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_pkey PRIMARY KEY (id);


--
-- Name: ai_variance_logs ai_variance_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_variance_logs
    ADD CONSTRAINT ai_variance_logs_pkey PRIMARY KEY (id);


--
-- Name: bin_locations bin_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bin_locations
    ADD CONSTRAINT bin_locations_pkey PRIMARY KEY (id);


--
-- Name: budget_items budget_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.budget_items
    ADD CONSTRAINT budget_items_pkey PRIMARY KEY (id);


--
-- Name: chart_of_accounts chart_of_accounts_account_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chart_of_accounts
    ADD CONSTRAINT chart_of_accounts_account_code_key UNIQUE (account_code);


--
-- Name: chart_of_accounts chart_of_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chart_of_accounts
    ADD CONSTRAINT chart_of_accounts_pkey PRIMARY KEY (id);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


--
-- Name: coa_accounts coa_accounts_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coa_accounts
    ADD CONSTRAINT coa_accounts_code_key UNIQUE (code);


--
-- Name: coa_accounts coa_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coa_accounts
    ADD CONSTRAINT coa_accounts_pkey PRIMARY KEY (id);


--
-- Name: company_profile company_profile_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.company_profile
    ADD CONSTRAINT company_profile_pkey PRIMARY KEY (id);


--
-- Name: customer_npwp customer_npwp_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_npwp
    ADD CONSTRAINT customer_npwp_pkey PRIMARY KEY (id);


--
-- Name: dashboard_ai_cache dashboard_ai_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_ai_cache
    ADD CONSTRAINT dashboard_ai_cache_pkey PRIMARY KEY (project_id);


--
-- Name: department_section department_section_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_section
    ADD CONSTRAINT department_section_pkey PRIMARY KEY (id);


--
-- Name: employee_department employee_department_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_department
    ADD CONSTRAINT employee_department_pkey PRIMARY KEY (id);


--
-- Name: employee_section employee_section_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_section
    ADD CONSTRAINT employee_section_pkey PRIMARY KEY (id);


--
-- Name: employee_status employee_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_status
    ADD CONSTRAINT employee_status_pkey PRIMARY KEY (id);


--
-- Name: expense_master expense_master_expense_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expense_master
    ADD CONSTRAINT expense_master_expense_code_key UNIQUE (expense_code);


--
-- Name: expense_master expense_master_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expense_master
    ADD CONSTRAINT expense_master_pkey PRIMARY KEY (id);


--
-- Name: finance_settings finance_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT finance_settings_pkey PRIMARY KEY (id);


--
-- Name: financial_expense_allocations financial_expense_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expense_allocations
    ADD CONSTRAINT financial_expense_allocations_pkey PRIMARY KEY (id);


--
-- Name: financial_expenses financial_expenses_expense_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses
    ADD CONSTRAINT financial_expenses_expense_no_key UNIQUE (expense_no);


--
-- Name: financial_expenses financial_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses
    ADD CONSTRAINT financial_expenses_pkey PRIMARY KEY (id);


--
-- Name: financial_incomes financial_incomes_income_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes
    ADD CONSTRAINT financial_incomes_income_no_key UNIQUE (income_no);


--
-- Name: financial_incomes financial_incomes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes
    ADD CONSTRAINT financial_incomes_pkey PRIMARY KEY (id);


--
-- Name: fiscal_config fiscal_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fiscal_config
    ADD CONSTRAINT fiscal_config_pkey PRIMARY KEY (id);


--
-- Name: fiscal_config fiscal_config_rule_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fiscal_config
    ADD CONSTRAINT fiscal_config_rule_code_key UNIQUE (rule_code);


--
-- Name: general_ledger general_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.general_ledger
    ADD CONSTRAINT general_ledger_pkey PRIMARY KEY (id);


--
-- Name: grn_dtl grn_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl
    ADD CONSTRAINT grn_dtl_pkey PRIMARY KEY (id);


--
-- Name: grn_hdr grn_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr
    ADD CONSTRAINT grn_hdr_pkey PRIMARY KEY (id);


--
-- Name: grn_hdr grn_hdr_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr
    ADD CONSTRAINT grn_hdr_ref_no_key UNIQUE (ref_no);


--
-- Name: item_grade item_grade_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_grade
    ADD CONSTRAINT item_grade_pkey PRIMARY KEY (id);


--
-- Name: item_group item_group_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.item_group
    ADD CONSTRAINT item_group_pkey PRIMARY KEY (id);


--
-- Name: items items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_pkey PRIMARY KEY (id);


--
-- Name: journal_entries journal_entries_journal_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_journal_no_key UNIQUE (ref_no);


--
-- Name: journal_entries journal_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_pkey PRIMARY KEY (id);


--
-- Name: journal_entry_lines journal_entry_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_pkey PRIMARY KEY (id);


--
-- Name: journal_lines journal_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_pkey PRIMARY KEY (id);


--
-- Name: m_address m_address_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT m_address_pkey PRIMARY KEY (id);


--
-- Name: m_agent m_agent_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_agent
    ADD CONSTRAINT m_agent_pkey PRIMARY KEY (id);


--
-- Name: m_city m_city_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_city
    ADD CONSTRAINT m_city_pkey PRIMARY KEY (id);


--
-- Name: m_color m_color_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_color
    ADD CONSTRAINT m_color_pkey PRIMARY KEY (id);


--
-- Name: m_contact_no m_contact_no_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_no
    ADD CONSTRAINT m_contact_no_pkey PRIMARY KEY (id);


--
-- Name: m_contact_person m_contact_person_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_person
    ADD CONSTRAINT m_contact_person_pkey PRIMARY KEY (id);


--
-- Name: m_country m_country_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_country
    ADD CONSTRAINT m_country_pkey PRIMARY KEY (id);


--
-- Name: m_currency m_currency_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_currency
    ADD CONSTRAINT m_currency_pkey PRIMARY KEY (id);


--
-- Name: m_customer m_customer_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_customer
    ADD CONSTRAINT m_customer_pkey PRIMARY KEY (id);


--
-- Name: m_department_executive m_department_executive_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department_executive
    ADD CONSTRAINT m_department_executive_pkey PRIMARY KEY (id);


--
-- Name: m_department m_department_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department
    ADD CONSTRAINT m_department_pkey PRIMARY KEY (id);


--
-- Name: m_district m_district_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_district
    ADD CONSTRAINT m_district_pkey PRIMARY KEY (id);


--
-- Name: m_employee m_employee_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_employee
    ADD CONSTRAINT m_employee_pkey PRIMARY KEY (id);


--
-- Name: m_employee_status m_employee_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_employee_status
    ADD CONSTRAINT m_employee_status_pkey PRIMARY KEY (id);


--
-- Name: m_form_group m_form_group_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_group
    ADD CONSTRAINT m_form_group_pkey PRIMARY KEY (id);


--
-- Name: m_form_links m_form_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_links
    ADD CONSTRAINT m_form_links_pkey PRIMARY KEY (id);


--
-- Name: m_form_name m_form_name_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_name
    ADD CONSTRAINT m_form_name_pkey PRIMARY KEY (id);


--
-- Name: m_location m_location_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_location
    ADD CONSTRAINT m_location_pkey PRIMARY KEY (id);


--
-- Name: zz_deprecated_m_projects m_projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_m_projects
    ADD CONSTRAINT m_projects_pkey PRIMARY KEY (id);


--
-- Name: zz_deprecated_m_projects m_projects_project_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_m_projects
    ADD CONSTRAINT m_projects_project_code_key UNIQUE (project_code);


--
-- Name: m_region m_region_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_region
    ADD CONSTRAINT m_region_pkey PRIMARY KEY (id);


--
-- Name: m_suppliers m_suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_suppliers
    ADD CONSTRAINT m_suppliers_pkey PRIMARY KEY (id);


--
-- Name: m_unit m_unit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_unit
    ADD CONSTRAINT m_unit_pkey PRIMARY KEY (id);


--
-- Name: m_user_access_menu m_user_access_menu_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_access_menu
    ADD CONSTRAINT m_user_access_menu_pkey PRIMARY KEY (id);


--
-- Name: m_user_login_status m_user_login_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_login_status
    ADD CONSTRAINT m_user_login_status_pkey PRIMARY KEY (id);


--
-- Name: m_user_system m_user_system_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_system
    ADD CONSTRAINT m_user_system_pkey PRIMARY KEY (id);


--
-- Name: m_village m_village_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_village
    ADD CONSTRAINT m_village_pkey PRIMARY KEY (id);


--
-- Name: zz_deprecated_m_warehouse m_warehouse_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_m_warehouse
    ADD CONSTRAINT m_warehouse_pkey PRIMARY KEY (id);


--
-- Name: material_issue_allocations material_issue_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_issue_allocations
    ADD CONSTRAINT material_issue_allocations_pkey PRIMARY KEY (id);


--
-- Name: material_leftover_header material_leftover_header_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_header
    ADD CONSTRAINT material_leftover_header_pkey PRIMARY KEY (id);


--
-- Name: material_leftover_header material_leftover_header_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_header
    ADD CONSTRAINT material_leftover_header_ref_no_key UNIQUE (ref_no);


--
-- Name: material_leftover_items material_leftover_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_items
    ADD CONSTRAINT material_leftover_items_pkey PRIMARY KEY (id);


--
-- Name: material_out_header material_out_header_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_pkey PRIMARY KEY (id);


--
-- Name: material_out_header material_out_header_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_ref_no_key UNIQUE (ref_no);


--
-- Name: material_out_items material_out_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_items
    ADD CONSTRAINT material_out_items_pkey PRIMARY KEY (id);


--
-- Name: material_receipt_header material_receipt_header_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_pkey PRIMARY KEY (id);


--
-- Name: material_receipt_header material_receipt_header_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_ref_no_key UNIQUE (ref_no);


--
-- Name: material_receipt_items material_receipt_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_items
    ADD CONSTRAINT material_receipt_items_pkey PRIMARY KEY (id);


--
-- Name: materials materials_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materials
    ADD CONSTRAINT materials_code_key UNIQUE (material_code);


--
-- Name: materials materials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materials
    ADD CONSTRAINT materials_pkey PRIMARY KEY (id);


--
-- Name: password_change_log password_change_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_change_log
    ADD CONSTRAINT password_change_log_pkey PRIMARY KEY (id);


--
-- Name: pics pics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pics
    ADD CONSTRAINT pics_pkey PRIMARY KEY (id);


--
-- Name: po_items po_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_pkey PRIMARY KEY (id);


--
-- Name: project_invoices project_invoices_inv_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_invoices
    ADD CONSTRAINT project_invoices_inv_no_key UNIQUE (inv_no);


--
-- Name: project_invoices project_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_invoices
    ADD CONSTRAINT project_invoices_pkey PRIMARY KEY (id);


--
-- Name: project_payment_items project_payment_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payment_items
    ADD CONSTRAINT project_payment_items_pkey PRIMARY KEY (id);


--
-- Name: project_payments project_payments_payment_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments
    ADD CONSTRAINT project_payments_payment_no_key UNIQUE (payment_no);


--
-- Name: project_payments project_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments
    ADD CONSTRAINT project_payments_pkey PRIMARY KEY (id);


--
-- Name: project_progress_log project_progress_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_progress_log
    ADD CONSTRAINT project_progress_log_pkey PRIMARY KEY (id);


--
-- Name: project_tasks project_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_tasks
    ADD CONSTRAINT project_tasks_pkey PRIMARY KEY (id);


--
-- Name: project_wip_release project_wip_release_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_wip_release
    ADD CONSTRAINT project_wip_release_pkey PRIMARY KEY (id);


--
-- Name: project_wip_release project_wip_release_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_wip_release
    ADD CONSTRAINT project_wip_release_ref_no_key UNIQUE (ref_no);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: purchase_invoice_dtl purchase_invoice_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_dtl
    ADD CONSTRAINT purchase_invoice_dtl_pkey PRIMARY KEY (id);


--
-- Name: purchase_invoice_hdr purchase_invoice_hdr_inv_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_hdr
    ADD CONSTRAINT purchase_invoice_hdr_inv_no_key UNIQUE (inv_no);


--
-- Name: purchase_invoice_hdr purchase_invoice_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_hdr
    ADD CONSTRAINT purchase_invoice_hdr_pkey PRIMARY KEY (id);


--
-- Name: purchase_invoice_hdr purchase_invoice_hdr_status_check; Type: CHECK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE public.purchase_invoice_hdr
    ADD CONSTRAINT purchase_invoice_hdr_status_check CHECK (((status)::text = ANY (ARRAY['posted'::text, 'reversed'::text]))) NOT VALID;


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_po_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_po_number_key UNIQUE (po_number);


--
-- Name: purchase_return_dtl purchase_return_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_dtl
    ADD CONSTRAINT purchase_return_dtl_pkey PRIMARY KEY (id);


--
-- Name: purchase_return_hdr purchase_return_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr
    ADD CONSTRAINT purchase_return_hdr_pkey PRIMARY KEY (id);


--
-- Name: purchase_return_hdr purchase_return_hdr_return_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr
    ADD CONSTRAINT purchase_return_hdr_return_no_key UNIQUE (return_no);


--
-- Name: spk_items spk_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_items
    ADD CONSTRAINT spk_items_pkey PRIMARY KEY (id);


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_lot_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_lot_no_key UNIQUE (lot_no);


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_pkey PRIMARY KEY (id);


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_pkey PRIMARY KEY (id);


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_transaction_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_transaction_no_key UNIQUE (transaction_no);


--
-- Name: spk_material_supply spk_material_supply_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply
    ADD CONSTRAINT spk_material_supply_pkey PRIMARY KEY (id);


--
-- Name: spk spk_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk
    ADD CONSTRAINT spk_pkey PRIMARY KEY (id);


--
-- Name: spk spk_spk_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk
    ADD CONSTRAINT spk_spk_no_key UNIQUE (spk_no);


--
-- Name: spk_task_expenses spk_task_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_expenses
    ADD CONSTRAINT spk_task_expenses_pkey PRIMARY KEY (id);


--
-- Name: spk_task_materials spk_task_materials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_materials
    ADD CONSTRAINT spk_task_materials_pkey PRIMARY KEY (id);


--
-- Name: spk_tasks spk_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_tasks
    ADD CONSTRAINT spk_tasks_pkey PRIMARY KEY (id);


--
-- Name: spk_work_items spk_work_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items
    ADD CONSTRAINT spk_work_items_pkey PRIMARY KEY (id);


--
-- Name: stock_movements stock_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_pkey PRIMARY KEY (id);


--
-- Name: stock_opname_dtl stock_opname_dtl_opname_id_material_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_dtl
    ADD CONSTRAINT stock_opname_dtl_opname_id_material_id_key UNIQUE (opname_id, material_id);


--
-- Name: stock_opname_dtl stock_opname_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_dtl
    ADD CONSTRAINT stock_opname_dtl_pkey PRIMARY KEY (id);


--
-- Name: stock_opname_hdr stock_opname_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_hdr
    ADD CONSTRAINT stock_opname_hdr_pkey PRIMARY KEY (id);


--
-- Name: stock_opname_hdr stock_opname_hdr_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_hdr
    ADD CONSTRAINT stock_opname_hdr_ref_no_key UNIQUE (ref_no);


--
-- Name: stock_transfer_dtl stock_transfer_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_dtl
    ADD CONSTRAINT stock_transfer_dtl_pkey PRIMARY KEY (id);


--
-- Name: stock_transfer_hdr stock_transfer_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_hdr
    ADD CONSTRAINT stock_transfer_hdr_pkey PRIMARY KEY (id);


--
-- Name: stock_transfer_hdr stock_transfer_hdr_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_hdr
    ADD CONSTRAINT stock_transfer_hdr_ref_no_key UNIQUE (ref_no);


--
-- Name: subcon_contract_tasks subcon_contract_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contract_tasks
    ADD CONSTRAINT subcon_contract_tasks_pkey PRIMARY KEY (id);


--
-- Name: subcon_contracts subcon_contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT subcon_contracts_pkey PRIMARY KEY (id);


--
-- Name: subcon_contracts subcon_contracts_spk_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT subcon_contracts_spk_no_key UNIQUE (spk_no);


--
-- Name: subcon_inspection_items subcon_inspection_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspection_items
    ADD CONSTRAINT subcon_inspection_items_pkey PRIMARY KEY (id);


--
-- Name: subcon_inspections subcon_inspections_inspection_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspections
    ADD CONSTRAINT subcon_inspections_inspection_no_key UNIQUE (inspection_no);


--
-- Name: subcon_inspections subcon_inspections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspections
    ADD CONSTRAINT subcon_inspections_pkey PRIMARY KEY (id);


--
-- Name: subcon_invoice_payments subcon_invoice_payments_payment_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_payment_no_key UNIQUE (payment_no);


--
-- Name: subcon_invoice_payments subcon_invoice_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_pkey PRIMARY KEY (id);


--
-- Name: subcon_invoices subcon_invoices_invoice_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_invoice_no_key UNIQUE (invoice_no);


--
-- Name: subcon_invoices subcon_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_pkey PRIMARY KEY (id);


--
-- Name: subcon_payment_certificate_items subcon_payment_certificate_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items
    ADD CONSTRAINT subcon_payment_certificate_items_pkey PRIMARY KEY (id);


--
-- Name: subcon_payment_certificates subcon_payment_certificates_certificate_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates
    ADD CONSTRAINT subcon_payment_certificates_certificate_no_key UNIQUE (certificate_no);


--
-- Name: subcon_payment_certificates subcon_payment_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates
    ADD CONSTRAINT subcon_payment_certificates_pkey PRIMARY KEY (id);


--
-- Name: subcon_payments subcon_payments_payment_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments
    ADD CONSTRAINT subcon_payments_payment_no_key UNIQUE (payment_no);


--
-- Name: subcon_payments subcon_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments
    ADD CONSTRAINT subcon_payments_pkey PRIMARY KEY (id);


--
-- Name: subcon_progress_dtl subcon_progress_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_dtl
    ADD CONSTRAINT subcon_progress_dtl_pkey PRIMARY KEY (id);


--
-- Name: subcon_progress_dtl subcon_progress_dtl_progress_id_contract_task_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_dtl
    ADD CONSTRAINT subcon_progress_dtl_progress_id_contract_task_id_key UNIQUE (progress_id, contract_task_id);


--
-- Name: subcon_progress subcon_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress
    ADD CONSTRAINT subcon_progress_pkey PRIMARY KEY (id);


--
-- Name: subcon_progress_qty_items subcon_progress_qty_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items
    ADD CONSTRAINT subcon_progress_qty_items_pkey PRIMARY KEY (id);


--
-- Name: subcon_progress subcon_progress_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress
    ADD CONSTRAINT subcon_progress_ref_no_key UNIQUE (ref_no);


--
-- Name: subcontractors subcontractors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcontractors
    ADD CONSTRAINT subcontractors_pkey PRIMARY KEY (id);


--
-- Name: supplier_payment_items supplier_payment_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payment_items
    ADD CONSTRAINT supplier_payment_items_pkey PRIMARY KEY (id);


--
-- Name: supplier_payments supplier_payments_payment_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_payment_no_key UNIQUE (payment_no);


--
-- Name: supplier_payments supplier_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_pkey PRIMARY KEY (id);


--
-- Name: supplier_return_dtl supplier_return_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_dtl
    ADD CONSTRAINT supplier_return_dtl_pkey PRIMARY KEY (id);


--
-- Name: supplier_return_hdr supplier_return_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_pkey PRIMARY KEY (id);


--
-- Name: supplier_return_hdr supplier_return_hdr_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_ref_no_key UNIQUE (ref_no);


--
-- Name: zz_deprecated_suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.zz_deprecated_suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: task_expenses task_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_expenses
    ADD CONSTRAINT task_expenses_pkey PRIMARY KEY (id);


--
-- Name: task_materials task_materials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_materials
    ADD CONSTRAINT task_materials_pkey PRIMARY KEY (id);


--
-- Name: tax_exchange_rate_dtl tax_exchange_rate_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_dtl
    ADD CONSTRAINT tax_exchange_rate_dtl_pkey PRIMARY KEY (id);


--
-- Name: tax_exchange_rate_hdr tax_exchange_rate_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_hdr
    ADD CONSTRAINT tax_exchange_rate_hdr_pkey PRIMARY KEY (id);


--
-- Name: subcon_payment_certificate_items uq_certificate_inspection_item; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items
    ADD CONSTRAINT uq_certificate_inspection_item UNIQUE (certificate_id, inspection_item_id);


--
-- Name: subcon_progress_qty_items uq_progress_qty_task; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items
    ADD CONSTRAINT uq_progress_qty_task UNIQUE (progress_id, contract_task_id);


--
-- Name: spk_task_expenses uq_spk_task_expenses; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_expenses
    ADD CONSTRAINT uq_spk_task_expenses UNIQUE (spk_task_id, task_expense_id);


--
-- Name: spk_task_materials uq_spk_task_materials; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_materials
    ADD CONSTRAINT uq_spk_task_materials UNIQUE (spk_task_id, task_material_id);


--
-- Name: spk_tasks uq_spk_tasks; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_tasks
    ADD CONSTRAINT uq_spk_tasks UNIQUE (spk_id, task_id);


--
-- Name: task_expenses uq_task_expenses_task_budget; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_expenses
    ADD CONSTRAINT uq_task_expenses_task_budget UNIQUE (task_id, budget_item_id);


--
-- Name: task_materials uq_task_materials_task_budget; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_materials
    ADD CONSTRAINT uq_task_materials_task_budget UNIQUE (task_id, budget_item_id);


--
-- Name: warehouses warehouses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_pkey PRIMARY KEY (id);


--
-- Name: wh_receiving_dtl wh_receiving_dtl_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_pkey PRIMARY KEY (id);


--
-- Name: wh_receiving_hdr wh_receiving_hdr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_hdr
    ADD CONSTRAINT wh_receiving_hdr_pkey PRIMARY KEY (id);


--
-- Name: wh_receiving_hdr wh_receiving_hdr_ref_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_hdr
    ADD CONSTRAINT wh_receiving_hdr_ref_no_key UNIQUE (ref_no);


--
-- Name: year_closing year_closing_fiscal_year_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_closing
    ADD CONSTRAINT year_closing_fiscal_year_key UNIQUE (fiscal_year);


--
-- Name: year_closing year_closing_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_closing
    ADD CONSTRAINT year_closing_pkey PRIMARY KEY (id);


--
-- Name: idx_ai_variance_logs_project_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_variance_logs_project_id ON public.ai_variance_logs USING btree (project_id);


--
-- Name: idx_avl_project_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_avl_project_created ON public.ai_variance_logs USING btree (project_id, created_at DESC);


--
-- Name: idx_bin_warehouse; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_bin_warehouse ON public.bin_locations USING btree (warehouse_id);


--
-- Name: idx_budget_items_expense; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_budget_items_expense ON public.budget_items USING btree (expense_id);


--
-- Name: idx_budget_items_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_budget_items_material ON public.budget_items USING btree (material_id);


--
-- Name: idx_budget_items_non_mat_expense; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_budget_items_non_mat_expense ON public.budget_items USING btree (project_id, category, expense_id) WHERE (((category)::text <> 'Material'::text) AND (expense_id IS NOT NULL));


--
-- Name: idx_budget_items_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_budget_items_project ON public.budget_items USING btree (project_id);


--
-- Name: idx_coa_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coa_parent ON public.coa_accounts USING btree (parent_id);


--
-- Name: idx_coa_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coa_type ON public.coa_accounts USING btree (account_type);


--
-- Name: idx_data_log_delete_table_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_data_log_delete_table_name ON public.data_log_delete USING brin (lower(btrim(table_name))) WITH (pages_per_range='16');


--
-- Name: idx_data_log_delete_tx_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_data_log_delete_tx_id ON public.data_log_delete USING brin ((((new_values OPERATOR(public.->) 'id'::text))::integer)) WITH (pages_per_range='16');


--
-- Name: idx_expense_master_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_expense_master_active ON public.expense_master USING btree (active);


--
-- Name: idx_expense_master_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_expense_master_category ON public.expense_master USING btree (category);


--
-- Name: idx_financial_expenses_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_expenses_date ON public.financial_expenses USING btree (expense_date);


--
-- Name: idx_financial_expenses_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_expenses_project ON public.financial_expenses USING btree (project_id);


--
-- Name: idx_financial_expenses_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_expenses_status ON public.financial_expenses USING btree (status);


--
-- Name: idx_financial_incomes_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_incomes_date ON public.financial_incomes USING btree (income_date);


--
-- Name: idx_financial_incomes_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_incomes_project ON public.financial_incomes USING btree (project_id);


--
-- Name: idx_financial_incomes_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_financial_incomes_status ON public.financial_incomes USING btree (status);


--
-- Name: idx_gl_account_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_gl_account_date ON public.general_ledger USING btree (account_id, transaction_date);


--
-- Name: idx_grn_dtl_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grn_dtl_hdr ON public.grn_dtl USING btree (grn_hdr_id);


--
-- Name: idx_grn_dtl_po_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grn_dtl_po_item ON public.grn_dtl USING btree (po_item_id);


--
-- Name: idx_grn_hdr_po_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grn_hdr_po_id ON public.grn_hdr USING btree (po_id);


--
-- Name: idx_grn_hdr_supplier_do_no; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grn_hdr_supplier_do_no ON public.grn_hdr USING btree (supplier_do_no);


--
-- Name: idx_items_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_items_name ON public.items USING btree (name);


--
-- Name: idx_jel_account; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jel_account ON public.journal_entry_lines USING btree (account_id);


--
-- Name: idx_jel_je; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jel_je ON public.journal_entry_lines USING btree (journal_entry_id);


--
-- Name: idx_journal_entries_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_journal_entries_ref ON public.journal_entries USING btree (reference_type, reference_id);


--
-- Name: idx_journal_lines_account; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_journal_lines_account ON public.journal_lines USING btree (account_id);


--
-- Name: idx_m_employee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_m_employee_id ON public.m_employee USING brin (id) WITH (pages_per_range='16');


--
-- Name: idx_m_employee_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_m_employee_name ON public.m_employee USING brin (employee_name) WITH (pages_per_range='16');


--
-- Name: idx_m_employee_nik; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_m_employee_nik ON public.m_employee USING brin (nik) WITH (pages_per_range='16');


--
-- Name: idx_m_suppliers_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_m_suppliers_name ON public.m_suppliers USING brin (name) WITH (pages_per_range='16');


--
-- Name: idx_m_user_system_employee_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_m_user_system_employee_id ON public.m_user_system USING btree (employee_id);


--
-- Name: idx_m_user_system_login_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_m_user_system_login_name ON public.m_user_system USING btree (login_name);


--
-- Name: idx_mat_out_hdr_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mat_out_hdr_project ON public.material_out_header USING btree (project_id, status);


--
-- Name: idx_mat_out_hdr_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mat_out_hdr_type ON public.material_out_header USING btree (issue_type, status);


--
-- Name: idx_mat_out_items_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mat_out_items_hdr ON public.material_out_items USING btree (material_out_id);


--
-- Name: idx_material_out_header_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_material_out_header_spk ON public.material_out_header USING btree (spk_id);


--
-- Name: idx_mlo_hdr_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mlo_hdr_project ON public.material_leftover_header USING btree (project_id, status);


--
-- Name: idx_mlo_items_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mlo_items_hdr ON public.material_leftover_items USING btree (header_id);


--
-- Name: idx_mlo_items_mat; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mlo_items_mat ON public.material_leftover_items USING btree (material_id);


--
-- Name: idx_out_items_header; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_out_items_header ON public.material_out_items USING btree (material_out_id);


--
-- Name: idx_out_items_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_out_items_material ON public.material_out_items USING btree (material_id);


--
-- Name: idx_out_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_out_project ON public.material_out_header USING btree (project_id);


--
-- Name: idx_out_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_out_status ON public.material_out_header USING btree (status);


--
-- Name: idx_out_warehouse; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_out_warehouse ON public.material_out_header USING btree (warehouse_id);


--
-- Name: idx_password_change_log_changed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_password_change_log_changed_at ON public.password_change_log USING btree (changed_at);


--
-- Name: idx_password_change_log_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_password_change_log_user_id ON public.password_change_log USING btree (user_id);


--
-- Name: idx_pinv_dtl_grn_hdr_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pinv_dtl_grn_hdr_id ON public.purchase_invoice_dtl USING btree (grn_hdr_id);


--
-- Name: idx_pinv_dtl_invoice_hdr_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pinv_dtl_invoice_hdr_id ON public.purchase_invoice_dtl USING btree (invoice_hdr_id);


--
-- Name: idx_pinv_hdr_supplier_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pinv_hdr_supplier_id ON public.purchase_invoice_hdr USING btree (supplier_id);


--
-- Name: idx_po_currency; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_po_currency ON public.purchase_orders USING btree (currency_id);


--
-- Name: idx_po_items_budget_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_po_items_budget_item ON public.po_items USING btree (budget_item_id);


--
-- Name: idx_po_items_po; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_po_items_po ON public.po_items USING btree (po_id);


--
-- Name: idx_po_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_po_project ON public.purchase_orders USING btree (project_id);


--
-- Name: idx_po_supplier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_po_supplier ON public.purchase_orders USING btree (supplier_id);


--
-- Name: idx_ppl_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ppl_project ON public.project_progress_log USING btree (project_id, status, report_date);


--
-- Name: idx_project_invoices_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_invoices_due ON public.project_invoices USING btree (due_date) WHERE (due_date IS NOT NULL);


--
-- Name: idx_project_invoices_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_invoices_project ON public.project_invoices USING btree (project_id, invoice_date);


--
-- Name: idx_project_invoices_project_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_invoices_project_id ON public.project_invoices USING btree (project_id);


--
-- Name: idx_project_payment_items_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_payment_items_invoice_id ON public.project_payment_items USING btree (invoice_id);


--
-- Name: idx_project_payment_items_payment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_payment_items_payment_id ON public.project_payment_items USING btree (payment_id);


--
-- Name: idx_project_payments_client_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_payments_client_id ON public.project_payments USING btree (client_id);


--
-- Name: idx_project_payments_currency; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_payments_currency ON public.project_payments USING btree (currency_id);


--
-- Name: idx_project_tasks_project_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_tasks_project_id ON public.project_tasks USING btree (project_id);


--
-- Name: idx_project_wip_release_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_project_wip_release_project ON public.project_wip_release USING btree (project_id);


--
-- Name: idx_projects_client_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_projects_client_id ON public.projects USING btree (client_id);


--
-- Name: idx_projects_end_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_projects_end_date ON public.projects USING btree (end_date);


--
-- Name: idx_projects_pic_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_projects_pic_id ON public.projects USING btree (pic_id);


--
-- Name: idx_projects_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_projects_status ON public.projects USING btree (status);


--
-- Name: idx_purchase_return_dtl_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchase_return_dtl_hdr ON public.purchase_return_dtl USING btree (return_hdr_id);


--
-- Name: idx_purchase_return_dtl_wh_rcv_dtl; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchase_return_dtl_wh_rcv_dtl ON public.purchase_return_dtl USING btree (wh_receiving_dtl_id);


--
-- Name: idx_purchase_return_hdr_grn; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchase_return_hdr_grn ON public.purchase_return_hdr USING btree (grn_hdr_id);


--
-- Name: idx_purchase_return_hdr_wh_rcv; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchase_return_hdr_wh_rcv ON public.purchase_return_hdr USING btree (wh_receiving_hdr_id);


--
-- Name: idx_rcv_items_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rcv_items_material ON public.material_receipt_items USING btree (material_id);


--
-- Name: idx_rcv_items_receipt; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rcv_items_receipt ON public.material_receipt_items USING btree (receipt_id);


--
-- Name: idx_rcv_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rcv_project ON public.material_receipt_header USING btree (project_id);


--
-- Name: idx_rcv_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rcv_status ON public.material_receipt_header USING btree (status);


--
-- Name: idx_rcv_warehouse; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rcv_warehouse ON public.material_receipt_header USING btree (warehouse_id);


--
-- Name: idx_sc_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sc_project ON public.subcon_contracts USING btree (project_id);


--
-- Name: idx_sct_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sct_contract ON public.subcon_contract_tasks USING btree (contract_id);


--
-- Name: idx_so_dtl_opname; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_so_dtl_opname ON public.stock_opname_dtl USING btree (opname_id);


--
-- Name: idx_sp_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sp_contract ON public.subcon_progress USING btree (contract_id);


--
-- Name: idx_spk_items_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spk_items_spk ON public.spk_items USING btree (spk_id);


--
-- Name: idx_spk_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spk_project ON public.spk USING btree (project_id);


--
-- Name: idx_spy_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spy_contract ON public.subcon_payments USING btree (contract_id);


--
-- Name: idx_srh_rcv; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_srh_rcv ON public.supplier_return_hdr USING btree (wh_receiving_hdr_id);


--
-- Name: idx_stock_movements_item_wh; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stock_movements_item_wh ON public.stock_movements USING btree (item_id, warehouse_id);


--
-- Name: idx_stock_movements_reference; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stock_movements_reference ON public.stock_movements USING btree (reference_type, reference_id);


--
-- Name: idx_stockmov_item_wh_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stockmov_item_wh_date ON public.stock_movements USING btree (item_id, warehouse_id, movement_date, id);


--
-- Name: idx_stockmov_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stockmov_ref ON public.stock_movements USING btree (reference_type, reference_id);


--
-- Name: idx_subcon_inv_payments_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subcon_inv_payments_contract ON public.subcon_invoice_payments USING btree (contract_id);


--
-- Name: idx_subcon_inv_payments_invoice; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subcon_inv_payments_invoice ON public.subcon_invoice_payments USING btree (invoice_id);


--
-- Name: idx_subcon_invoices_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subcon_invoices_contract ON public.subcon_invoices USING btree (contract_id);


--
-- Name: idx_subcon_invoices_progress; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subcon_invoices_progress ON public.subcon_invoices USING btree (progress_id);


--
-- Name: idx_subcon_invoices_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subcon_invoices_status ON public.subcon_invoices USING btree (status);


--
-- Name: idx_sup_ret_dtl_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sup_ret_dtl_hdr ON public.supplier_return_dtl USING btree (return_hdr_id);


--
-- Name: idx_sup_ret_hdr_wh; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sup_ret_hdr_wh ON public.supplier_return_hdr USING btree (warehouse_id, status);


--
-- Name: idx_suppay_items_invoice_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suppay_items_invoice_id ON public.supplier_payment_items USING btree (invoice_id);


--
-- Name: idx_suppay_items_payment_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suppay_items_payment_id ON public.supplier_payment_items USING btree (supplier_payment_id);


--
-- Name: idx_suppay_supplier_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suppay_supplier_id ON public.supplier_payments USING btree (supplier_id);


--
-- Name: idx_tax_rate_dtl_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tax_rate_dtl_code ON public.tax_exchange_rate_dtl USING btree (currency_code);


--
-- Name: idx_tax_rate_dtl_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tax_rate_dtl_hdr ON public.tax_exchange_rate_dtl USING btree (hdr_id);


--
-- Name: idx_tax_rate_hdr_period; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_tax_rate_hdr_period ON public.tax_exchange_rate_hdr USING btree (start_date, end_date);


--
-- Name: idx_warehouses_location; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_warehouses_location ON public.warehouses USING btree (location_id);


--
-- Name: idx_warehouses_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_warehouses_project ON public.warehouses USING btree (project_id);


--
-- Name: idx_wh_receiving_dtl_grn_dtl; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_wh_receiving_dtl_grn_dtl ON public.wh_receiving_dtl USING btree (grn_dtl_id);


--
-- Name: idx_wh_receiving_dtl_hdr; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_wh_receiving_dtl_hdr ON public.wh_receiving_dtl USING btree (wh_receiving_hdr_id);


--
-- Name: idx_wh_receiving_hdr_grn; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_wh_receiving_hdr_grn ON public.wh_receiving_hdr USING btree (grn_hdr_id);


--
-- Name: ix_fin_exp_alloc_expense; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_fin_exp_alloc_expense ON public.financial_expense_allocations USING btree (financial_expense_id);


--
-- Name: ix_fin_exp_alloc_spk_task; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_fin_exp_alloc_spk_task ON public.financial_expense_allocations USING btree (spk_task_id);


--
-- Name: ix_fin_exp_alloc_task_expense; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_fin_exp_alloc_task_expense ON public.financial_expense_allocations USING btree (task_expense_id);


--
-- Name: ix_mia_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_mia_item ON public.material_issue_allocations USING btree (out_item_id);


--
-- Name: ix_mia_layer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_mia_layer ON public.material_issue_allocations USING btree (layer_source, layer_id);


--
-- Name: ix_moh_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_moh_project ON public.material_out_header USING btree (project_id, status);


--
-- Name: ix_moi_header; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_moi_header ON public.material_out_items USING btree (material_out_id);


--
-- Name: ix_progress_qty_progress; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_progress_qty_progress ON public.subcon_progress_qty_items USING btree (progress_id);


--
-- Name: ix_progress_qty_work_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_progress_qty_work_item ON public.subcon_progress_qty_items USING btree (work_item_id);


--
-- Name: ix_spk_execution_subcontractor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_execution_subcontractor ON public.spk USING btree (execution_subcontractor_id);


--
-- Name: ix_spk_leftover_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_material ON public.spk_material_leftover_lots USING btree (material_id);


--
-- Name: ix_spk_leftover_owner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_owner ON public.spk_material_leftover_lots USING btree (current_owner_type, current_subcontractor_id);


--
-- Name: ix_spk_leftover_parent_lot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_parent_lot ON public.spk_material_leftover_lots USING btree (parent_lot_id);


--
-- Name: ix_spk_leftover_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_project ON public.spk_material_leftover_lots USING btree (project_id);


--
-- Name: ix_spk_leftover_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_spk ON public.spk_material_leftover_lots USING btree (spk_id);


--
-- Name: ix_spk_leftover_tx_lot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_tx_lot ON public.spk_material_leftover_transactions USING btree (leftover_lot_id);


--
-- Name: ix_spk_leftover_tx_status_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_leftover_tx_status_date ON public.spk_material_leftover_transactions USING btree (status, transaction_date);


--
-- Name: ix_spk_material_supply_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_material_supply_material ON public.spk_material_supply USING btree (material_id);


--
-- Name: ix_spk_material_supply_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_material_supply_spk ON public.spk_material_supply USING btree (spk_id);


--
-- Name: ix_spk_material_supply_subcon; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_material_supply_subcon ON public.spk_material_supply USING btree (subcontractor_id);


--
-- Name: ix_spk_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_parent ON public.spk USING btree (parent_spk_id);


--
-- Name: ix_spk_task_expenses_task_expense; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_task_expenses_task_expense ON public.spk_task_expenses USING btree (task_expense_id);


--
-- Name: ix_spk_task_materials_task_material; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_task_materials_task_material ON public.spk_task_materials USING btree (task_material_id);


--
-- Name: ix_spk_tasks_spk_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_tasks_spk_id ON public.spk_tasks USING btree (spk_id);


--
-- Name: ix_spk_tasks_task_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_tasks_task_id ON public.spk_tasks USING btree (task_id);


--
-- Name: ix_spk_type_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_type_project ON public.spk USING btree (spk_type, project_id);


--
-- Name: ix_spk_work_items_budget; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_work_items_budget ON public.spk_work_items USING btree (budget_item_id);


--
-- Name: ix_spk_work_items_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_work_items_spk ON public.spk_work_items USING btree (spk_id);


--
-- Name: ix_spk_work_items_spk_task; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_work_items_spk_task ON public.spk_work_items USING btree (spk_task_id);


--
-- Name: ix_spk_work_items_task; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_spk_work_items_task ON public.spk_work_items USING btree (project_task_id);


--
-- Name: ix_subcon_cert_contract; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_cert_contract ON public.subcon_payment_certificates USING btree (contract_id);


--
-- Name: ix_subcon_cert_inspection; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_cert_inspection ON public.subcon_payment_certificates USING btree (inspection_id);


--
-- Name: ix_subcon_cert_items_inspection; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_cert_items_inspection ON public.subcon_payment_certificate_items USING btree (inspection_item_id);


--
-- Name: ix_subcon_cert_items_work; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_cert_items_work ON public.subcon_payment_certificate_items USING btree (work_item_id);


--
-- Name: ix_subcon_cert_progress; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_cert_progress ON public.subcon_payment_certificates USING btree (progress_id);


--
-- Name: ix_subcon_contract_status_project; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_contract_status_project ON public.subcon_contracts USING btree (project_id, status);


--
-- Name: ix_subcon_inspection_items_claim; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_inspection_items_claim ON public.subcon_inspection_items USING btree (progress_qty_item_id);


--
-- Name: ix_subcon_inspection_items_header; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_inspection_items_header ON public.subcon_inspection_items USING btree (inspection_id);


--
-- Name: ix_subcon_inspections_progress; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_inspections_progress ON public.subcon_inspections USING btree (progress_id);


--
-- Name: ix_subcon_inspections_status_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_inspections_status_date ON public.subcon_inspections USING btree (status, inspection_date);


--
-- Name: ix_subcon_invoice_certificate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_subcon_invoice_certificate ON public.subcon_invoices USING btree (certificate_id);


--
-- Name: ix_task_expenses_budget_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_task_expenses_budget_item ON public.task_expenses USING btree (budget_item_id);


--
-- Name: ix_task_expenses_task; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_task_expenses_task ON public.task_expenses USING btree (task_id);


--
-- Name: ix_task_materials_budget_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_task_materials_budget_item ON public.task_materials USING btree (budget_item_id);


--
-- Name: ix_task_materials_task; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_task_materials_task ON public.task_materials USING btree (task_id);


--
-- Name: trgm_idx_data_log_delete_table_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX trgm_idx_data_log_delete_table_name ON public.data_log_delete USING gin (lower(btrim(table_name)) public.gin_trgm_ops);


--
-- Name: trgm_idx_data_log_insert_table_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX trgm_idx_data_log_insert_table_name ON public.data_log_insert USING gin (lower(btrim(table_name)) public.gin_trgm_ops);


--
-- Name: trgm_idx_data_log_update_old_value_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX trgm_idx_data_log_update_old_value_id ON public.data_log_update USING gin (((old_values OPERATOR(public.->) 'id'::text)) public.gin_trgm_ops);


--
-- Name: trgm_idx_data_log_update_table_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX trgm_idx_data_log_update_table_name ON public.data_log_update USING gin (lower(btrim(table_name)) public.gin_trgm_ops);


--
-- Name: uq_pinv_supplier_faktur; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_pinv_supplier_faktur ON public.purchase_invoice_hdr USING btree (supplier_id, faktur_pajak_no) WHERE (faktur_pajak_no IS NOT NULL);


--
-- Name: uq_pinv_supplier_invoice_no; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_pinv_supplier_invoice_no ON public.purchase_invoice_hdr USING btree (supplier_id, supplier_invoice_no) WHERE ((status)::text <> 'cancelled'::text);


--
-- Name: uq_so_open_per_wh; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_so_open_per_wh ON public.stock_opname_hdr USING btree (warehouse_id) WHERE ((status)::text = ANY ((ARRAY['Draft'::character varying, 'Diajukan'::character varying])::text[]));


--
-- Name: uq_subcon_contract_spk; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_subcon_contract_spk ON public.subcon_contracts USING btree (spk_id) WHERE (spk_id IS NOT NULL);


--
-- Name: uq_subcon_contract_task_work_item; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_subcon_contract_task_work_item ON public.subcon_contract_tasks USING btree (work_item_id) WHERE (work_item_id IS NOT NULL);


--
-- Name: ux_budget_items_project_material; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_budget_items_project_material ON public.budget_items USING btree (project_id, material_id) WHERE (((category)::text = 'Material'::text) AND (material_id IS NOT NULL));


--
-- Name: ux_mat_out_hdr_sj_no; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_mat_out_hdr_sj_no ON public.material_out_header USING btree (delivery_note_no) WHERE ((delivery_note_no)::text ~~ 'SJ-%'::text);


--
-- Name: ux_ppl_one_pending; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_ppl_one_pending ON public.project_progress_log USING btree (project_id) WHERE ((status)::text = 'Diajukan'::text);


--
-- Name: chart_of_accounts chart_of_accounts_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER chart_of_accounts_log AFTER INSERT OR DELETE OR UPDATE ON public.chart_of_accounts FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: customer_npwp customer_npwp_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER customer_npwp_log AFTER INSERT OR DELETE OR UPDATE ON public.customer_npwp FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: department_section department_section_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER department_section_log AFTER INSERT OR DELETE OR UPDATE ON public.department_section FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: employee_department employee_department_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER employee_department_log AFTER INSERT OR DELETE OR UPDATE ON public.employee_department FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: grn_dtl grn_dtl_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER grn_dtl_log AFTER INSERT OR DELETE OR UPDATE ON public.grn_dtl FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: grn_hdr grn_hdr_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER grn_hdr_log AFTER INSERT OR DELETE OR UPDATE ON public.grn_hdr FOR EACH ROW EXECUTE PROCEDURE public.if_modified_func();


--
-- Name: item_group refresh_mat_view_items; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER refresh_mat_view_items AFTER INSERT OR DELETE OR UPDATE OR TRUNCATE ON public.item_group FOR EACH STATEMENT EXECUTE PROCEDURE public.refresh_mat_view_items();


--
-- Name: items refresh_mat_view_items; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER refresh_mat_view_items AFTER INSERT OR DELETE OR UPDATE OR TRUNCATE ON public.items FOR EACH STATEMENT EXECUTE PROCEDURE public.refresh_mat_view_items();


--
-- Name: m_color refresh_mat_view_items; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER refresh_mat_view_items AFTER INSERT OR DELETE OR UPDATE OR TRUNCATE ON public.m_color FOR EACH STATEMENT EXECUTE PROCEDURE public.refresh_mat_view_items();


--
-- Name: m_unit refresh_mat_view_items; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER refresh_mat_view_items AFTER INSERT OR DELETE OR UPDATE OR TRUNCATE ON public.m_unit FOR EACH STATEMENT EXECUTE PROCEDURE public.refresh_mat_view_items();


--
-- Name: spk_material_leftover_transactions trg_block_posted_leftover_transaction_change; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_block_posted_leftover_transaction_change BEFORE DELETE OR UPDATE ON public.spk_material_leftover_transactions FOR EACH ROW EXECUTE PROCEDURE public.fn_block_posted_leftover_transaction_change();


--
-- Name: budget_items trg_guard_budget_item_task_allocations; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_guard_budget_item_task_allocations BEFORE DELETE OR UPDATE ON public.budget_items FOR EACH ROW EXECUTE PROCEDURE public.fn_guard_budget_item_task_allocations();


--
-- Name: purchase_orders trg_po_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_po_updated_at BEFORE UPDATE ON public.purchase_orders FOR EACH ROW EXECUTE PROCEDURE public.set_updated_at();


--
-- Name: spk_material_leftover_transactions trg_post_spk_leftover_transaction; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_post_spk_leftover_transaction AFTER INSERT OR UPDATE OF status ON public.spk_material_leftover_transactions FOR EACH ROW EXECUTE PROCEDURE public.fn_post_spk_leftover_transaction();


--
-- Name: projects trg_projects_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_projects_updated_at BEFORE UPDATE ON public.projects FOR EACH ROW EXECUTE PROCEDURE public.set_updated_at();


--
-- Name: financial_expenses trg_update_financial_expenses_modtime; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_update_financial_expenses_modtime BEFORE UPDATE ON public.financial_expenses FOR EACH ROW EXECUTE PROCEDURE public.update_financial_expenses_modtime();


--
-- Name: financial_incomes trg_update_financial_incomes_modtime; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_update_financial_incomes_modtime BEFORE UPDATE ON public.financial_incomes FOR EACH ROW EXECUTE PROCEDURE public.update_financial_incomes_modtime();


--
-- Name: subcon_payment_certificates trg_validate_certificate_approval; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_certificate_approval BEFORE UPDATE OF status ON public.subcon_payment_certificates FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_certificate_approval();


--
-- Name: subcon_payment_certificate_items trg_validate_certificate_item; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_certificate_item BEFORE INSERT OR UPDATE OF certificate_id, inspection_item_id, work_item_id, qty_certified ON public.subcon_payment_certificate_items FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_certificate_item();


--
-- Name: financial_expense_allocations trg_validate_financial_expense_allocation; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_financial_expense_allocation BEFORE INSERT OR UPDATE ON public.financial_expense_allocations FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_financial_expense_allocation();


--
-- Name: subcon_inspection_items trg_validate_inspection_item; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_inspection_item BEFORE INSERT OR UPDATE OF inspection_id, progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework ON public.subcon_inspection_items FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_inspection_item();


--
-- Name: subcon_progress_qty_items trg_validate_progress_qty_item; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_progress_qty_item BEFORE INSERT OR UPDATE OF progress_id, contract_task_id, work_item_id ON public.subcon_progress_qty_items FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_progress_qty_item();


--
-- Name: spk_material_leftover_transactions trg_validate_spk_leftover_transaction; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_spk_leftover_transaction BEFORE INSERT OR UPDATE OF leftover_lot_id, transaction_type, seller_type, seller_subcontractor_id, buyer_type, buyer_subcontractor_id, qty, status, inventory_doc_id, inventory_out_doc_id ON public.spk_material_leftover_transactions FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_leftover_transaction();


--
-- Name: spk_task_expenses trg_validate_spk_task_expense; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_spk_task_expense BEFORE INSERT OR UPDATE ON public.spk_task_expenses FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_task_expense();


--
-- Name: spk_task_materials trg_validate_spk_task_material; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_spk_task_material BEFORE INSERT OR UPDATE ON public.spk_task_materials FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_task_material();


--
-- Name: spk_work_items trg_validate_spk_work_item_project; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_spk_work_item_project BEFORE INSERT OR UPDATE OF spk_id, project_task_id, budget_item_id, spk_task_id ON public.spk_work_items FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_spk_work_item_project();


--
-- Name: subcon_contracts trg_validate_subcon_contract_spk; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_subcon_contract_spk BEFORE INSERT OR UPDATE OF spk_id, project_id, subcontractor_id ON public.subcon_contracts FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_contract_spk();


--
-- Name: subcon_invoices trg_validate_subcon_invoice_certificate; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_subcon_invoice_certificate BEFORE INSERT OR UPDATE OF certificate_id, contract_id ON public.subcon_invoices FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_invoice_certificate();


--
-- Name: subcon_contract_tasks trg_validate_subcon_task_work_item; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_subcon_task_work_item BEFORE INSERT OR UPDATE OF contract_id, work_item_id ON public.subcon_contract_tasks FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_subcon_task_work_item();


--
-- Name: task_expenses trg_validate_task_expenses; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_task_expenses BEFORE INSERT OR UPDATE ON public.task_expenses FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_task_budget_allocation();


--
-- Name: task_materials trg_validate_task_materials; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validate_task_materials BEFORE INSERT OR UPDATE ON public.task_materials FOR EACH ROW EXECUTE PROCEDURE public.fn_validate_task_budget_allocation();


--
-- Name: bin_locations bin_locations_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bin_locations
    ADD CONSTRAINT bin_locations_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: budget_items budget_items_expense_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.budget_items
    ADD CONSTRAINT budget_items_expense_id_fkey FOREIGN KEY (expense_id) REFERENCES public.expense_master(id) ON DELETE SET NULL;


--
-- Name: budget_items budget_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.budget_items
    ADD CONSTRAINT budget_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id) ON DELETE SET NULL;


--
-- Name: budget_items budget_items_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.budget_items
    ADD CONSTRAINT budget_items_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: chart_of_accounts chart_of_accounts_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chart_of_accounts
    ADD CONSTRAINT chart_of_accounts_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: coa_accounts coa_accounts_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coa_accounts
    ADD CONSTRAINT coa_accounts_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.coa_accounts(id) ON DELETE SET NULL;


--
-- Name: expense_master expense_master_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expense_master
    ADD CONSTRAINT expense_master_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.m_user_system(id);


--
-- Name: expense_master expense_master_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expense_master
    ADD CONSTRAINT expense_master_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.m_user_system(id);


--
-- Name: finance_settings finance_settings_base_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT finance_settings_base_currency_id_fkey FOREIGN KEY (base_currency_id) REFERENCES public.m_currency(id);


--
-- Name: finance_settings finance_settings_report_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT finance_settings_report_currency_id_fkey FOREIGN KEY (report_currency_id) REFERENCES public.m_currency(id);


--
-- Name: financial_expense_allocations financial_expense_allocations_financial_expense_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expense_allocations
    ADD CONSTRAINT financial_expense_allocations_financial_expense_id_fkey FOREIGN KEY (financial_expense_id) REFERENCES public.financial_expenses(id) ON DELETE CASCADE;


--
-- Name: financial_expense_allocations financial_expense_allocations_spk_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expense_allocations
    ADD CONSTRAINT financial_expense_allocations_spk_task_id_fkey FOREIGN KEY (spk_task_id) REFERENCES public.spk_tasks(id) ON DELETE RESTRICT;


--
-- Name: financial_expense_allocations financial_expense_allocations_task_expense_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expense_allocations
    ADD CONSTRAINT financial_expense_allocations_task_expense_id_fkey FOREIGN KEY (task_expense_id) REFERENCES public.task_expenses(id) ON DELETE RESTRICT;


--
-- Name: financial_expenses financial_expenses_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses
    ADD CONSTRAINT financial_expenses_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id) ON DELETE SET NULL;


--
-- Name: financial_expenses financial_expenses_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses
    ADD CONSTRAINT financial_expenses_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: financial_expenses financial_expenses_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_expenses
    ADD CONSTRAINT financial_expenses_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) ON DELETE SET NULL;


--
-- Name: financial_incomes financial_incomes_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes
    ADD CONSTRAINT financial_incomes_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id) ON DELETE SET NULL;


--
-- Name: financial_incomes financial_incomes_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes
    ADD CONSTRAINT financial_incomes_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.m_customer(id) ON DELETE SET NULL;


--
-- Name: financial_incomes financial_incomes_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_incomes
    ADD CONSTRAINT financial_incomes_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: fiscal_config fiscal_config_credit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fiscal_config
    ADD CONSTRAINT fiscal_config_credit_account_id_fkey FOREIGN KEY (credit_account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: fiscal_config fiscal_config_debit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fiscal_config
    ADD CONSTRAINT fiscal_config_debit_account_id_fkey FOREIGN KEY (debit_account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: ai_variance_logs fk_ai_variance_project; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_variance_logs
    ADD CONSTRAINT fk_ai_variance_project FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: customer_npwp fk_customer_npwp_m_customer_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_npwp
    ADD CONSTRAINT fk_customer_npwp_m_customer_1 FOREIGN KEY (customer_id) REFERENCES public.m_customer(id);


--
-- Name: department_section fk_department_section_m_department_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_section
    ADD CONSTRAINT fk_department_section_m_department_1 FOREIGN KEY (department_id) REFERENCES public.m_department(id);


--
-- Name: employee_department fk_employee_department_m_department_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_department
    ADD CONSTRAINT fk_employee_department_m_department_1 FOREIGN KEY (department_id) REFERENCES public.m_department(id);


--
-- Name: employee_department fk_employee_department_m_employee_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_department
    ADD CONSTRAINT fk_employee_department_m_employee_1 FOREIGN KEY (employee_id) REFERENCES public.m_employee(id);


--
-- Name: employee_section fk_employee_section_m_employee_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_section
    ADD CONSTRAINT fk_employee_section_m_employee_1 FOREIGN KEY (employee_id) REFERENCES public.m_employee(id);


--
-- Name: employee_status fk_employee_status_m_employee_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_status
    ADD CONSTRAINT fk_employee_status_m_employee_1 FOREIGN KEY (employee_id) REFERENCES public.m_employee(id);


--
-- Name: items fk_items_item_group_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT fk_items_item_group_1 FOREIGN KEY (group_id) REFERENCES public.item_group(id);


--
-- Name: items fk_items_m_color_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT fk_items_m_color_1 FOREIGN KEY (color_id) REFERENCES public.m_color(id);


--
-- Name: items fk_items_m_unit_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT fk_items_m_unit_1 FOREIGN KEY (um_unit) REFERENCES public.m_unit(id);


--
-- Name: items fk_items_m_unit_2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT fk_items_m_unit_2 FOREIGN KEY (um_packing) REFERENCES public.m_unit(id);


--
-- Name: m_address fk_m_address_customer_npwp_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT fk_m_address_customer_npwp_1 FOREIGN KEY (npwp_id) REFERENCES public.customer_npwp(id);


--
-- Name: m_address fk_m_address_m_agent_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT fk_m_address_m_agent_1 FOREIGN KEY (agent_id) REFERENCES public.m_agent(id);


--
-- Name: m_address fk_m_address_m_contact_person_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT fk_m_address_m_contact_person_1 FOREIGN KEY (contact_person_id) REFERENCES public.m_contact_person(id);


--
-- Name: m_address fk_m_address_m_country_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT fk_m_address_m_country_1 FOREIGN KEY (country_id) REFERENCES public.m_country(id);


--
-- Name: m_address fk_m_address_m_village_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT fk_m_address_m_village_1 FOREIGN KEY (village_id) REFERENCES public.m_village(id);


--
-- Name: m_contact_no fk_m_contact_no_m_agent_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_no
    ADD CONSTRAINT fk_m_contact_no_m_agent_1 FOREIGN KEY (agent_id) REFERENCES public.m_agent(id);


--
-- Name: m_contact_no fk_m_contact_no_m_contact_person_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_no
    ADD CONSTRAINT fk_m_contact_no_m_contact_person_1 FOREIGN KEY (contact_person_id) REFERENCES public.m_contact_person(id);


--
-- Name: m_contact_person fk_m_contact_person_m_agent_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_person
    ADD CONSTRAINT fk_m_contact_person_m_agent_1 FOREIGN KEY (agent_id) REFERENCES public.m_agent(id);


--
-- Name: m_country fk_m_country_m_region_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_country
    ADD CONSTRAINT fk_m_country_m_region_1 FOREIGN KEY (region_id) REFERENCES public.m_region(id);


--
-- Name: m_department_executive fk_m_department_executive_m_department_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department_executive
    ADD CONSTRAINT fk_m_department_executive_m_department_1 FOREIGN KEY (department_id) REFERENCES public.m_department(id);


--
-- Name: m_department_executive fk_m_department_executive_m_employee_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department_executive
    ADD CONSTRAINT fk_m_department_executive_m_employee_1 FOREIGN KEY (employee_id) REFERENCES public.m_employee(id);


--
-- Name: m_department fk_m_department_m_location_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_department
    ADD CONSTRAINT fk_m_department_m_location_1 FOREIGN KEY (location_id) REFERENCES public.m_location(id);


--
-- Name: m_district fk_m_district_m_city_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_district
    ADD CONSTRAINT fk_m_district_m_city_1 FOREIGN KEY (city_id) REFERENCES public.m_city(id);


--
-- Name: m_form_links fk_m_form_links_m_form_name_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_links
    ADD CONSTRAINT fk_m_form_links_m_form_name_1 FOREIGN KEY (form_id) REFERENCES public.m_form_name(id);


--
-- Name: m_form_links fk_m_form_links_m_form_name_2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_links
    ADD CONSTRAINT fk_m_form_links_m_form_name_2 FOREIGN KEY (link_form_id) REFERENCES public.m_form_name(id);


--
-- Name: m_form_name fk_m_form_name_m_form_group_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_form_name
    ADD CONSTRAINT fk_m_form_name_m_form_group_1 FOREIGN KEY (group_id) REFERENCES public.m_form_group(id);


--
-- Name: m_user_access_menu fk_m_user_access_menu_m_form_name_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_access_menu
    ADD CONSTRAINT fk_m_user_access_menu_m_form_name_1 FOREIGN KEY (form_id) REFERENCES public.m_form_name(id);


--
-- Name: m_user_access_menu fk_m_user_access_menu_m_user_system_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_access_menu
    ADD CONSTRAINT fk_m_user_access_menu_m_user_system_1 FOREIGN KEY (user_id) REFERENCES public.m_user_system(id);


--
-- Name: m_user_login_status fk_m_user_login_status_m_user_system_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_login_status
    ADD CONSTRAINT fk_m_user_login_status_m_user_system_1 FOREIGN KEY (user_id) REFERENCES public.m_user_system(id);


--
-- Name: m_user_system fk_m_user_system_m_employee_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_user_system
    ADD CONSTRAINT fk_m_user_system_m_employee_1 FOREIGN KEY (employee_id) REFERENCES public.m_employee(id);


--
-- Name: m_village fk_m_village_m_district_1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_village
    ADD CONSTRAINT fk_m_village_m_district_1 FOREIGN KEY (district_id) REFERENCES public.m_district(id);


--
-- Name: password_change_log fk_password_change_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_change_log
    ADD CONSTRAINT fk_password_change_user FOREIGN KEY (user_id) REFERENCES public.m_user_system(id) ON DELETE CASCADE;


--
-- Name: spk fk_spk_execution_subcontractor; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk
    ADD CONSTRAINT fk_spk_execution_subcontractor FOREIGN KEY (execution_subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots fk_spk_leftover_parent_lot; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT fk_spk_leftover_parent_lot FOREIGN KEY (parent_lot_id) REFERENCES public.spk_material_leftover_lots(id) ON DELETE RESTRICT;


--
-- Name: spk fk_spk_parent_spk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk
    ADD CONSTRAINT fk_spk_parent_spk FOREIGN KEY (parent_spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;


--
-- Name: spk_work_items fk_spk_work_item_spk_task; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items
    ADD CONSTRAINT fk_spk_work_item_spk_task FOREIGN KEY (spk_task_id) REFERENCES public.spk_tasks(id) ON DELETE RESTRICT;


--
-- Name: subcon_contracts fk_subcon_contract_spk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT fk_subcon_contract_spk FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;


--
-- Name: subcon_contract_tasks fk_subcon_contract_task_work_item; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contract_tasks
    ADD CONSTRAINT fk_subcon_contract_task_work_item FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;


--
-- Name: subcon_invoices fk_subcon_invoice_certificate; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT fk_subcon_invoice_certificate FOREIGN KEY (certificate_id) REFERENCES public.subcon_payment_certificates(id) ON DELETE RESTRICT;


--
-- Name: general_ledger general_ledger_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.general_ledger
    ADD CONSTRAINT general_ledger_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: general_ledger general_ledger_journal_line_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.general_ledger
    ADD CONSTRAINT general_ledger_journal_line_id_fkey FOREIGN KEY (journal_line_id) REFERENCES public.journal_lines(id);


--
-- Name: grn_dtl grn_dtl_grade_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl
    ADD CONSTRAINT grn_dtl_grade_id_fkey FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;


--
-- Name: grn_dtl grn_dtl_grn_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl
    ADD CONSTRAINT grn_dtl_grn_hdr_id_fkey FOREIGN KEY (grn_hdr_id) REFERENCES public.grn_hdr(id) ON DELETE CASCADE;


--
-- Name: grn_dtl grn_dtl_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl
    ADD CONSTRAINT grn_dtl_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.materials(id);


--
-- Name: grn_dtl grn_dtl_po_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_dtl
    ADD CONSTRAINT grn_dtl_po_item_id_fkey FOREIGN KEY (po_item_id) REFERENCES public.po_items(id);


--
-- Name: grn_hdr grn_hdr_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr
    ADD CONSTRAINT grn_hdr_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id);


--
-- Name: grn_hdr grn_hdr_po_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr
    ADD CONSTRAINT grn_hdr_po_id_fkey FOREIGN KEY (po_id) REFERENCES public.purchase_orders(id);


--
-- Name: grn_hdr grn_hdr_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grn_hdr
    ADD CONSTRAINT grn_hdr_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id);


--
-- Name: journal_entries journal_entries_period_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_period_id_fkey FOREIGN KEY (period_id) REFERENCES public.accounting_periods(id);


--
-- Name: journal_entries journal_entries_posted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_posted_by_fkey FOREIGN KEY (posted_by) REFERENCES public.m_user_system(id);


--
-- Name: journal_entry_lines journal_entry_lines_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.coa_accounts(id);


--
-- Name: journal_entry_lines journal_entry_lines_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: journal_lines journal_lines_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: journal_lines journal_lines_journal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journal_entries(id) ON DELETE CASCADE;


--
-- Name: m_address m_address_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_address
    ADD CONSTRAINT m_address_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;


--
-- Name: m_contact_no m_contact_no_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_no
    ADD CONSTRAINT m_contact_no_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;


--
-- Name: m_contact_person m_contact_person_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.m_contact_person
    ADD CONSTRAINT m_contact_person_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) NOT VALID;


--
-- Name: material_issue_allocations material_issue_allocations_out_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_issue_allocations
    ADD CONSTRAINT material_issue_allocations_out_item_id_fkey FOREIGN KEY (out_item_id) REFERENCES public.material_out_items(id) ON DELETE CASCADE;


--
-- Name: material_leftover_header material_leftover_header_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_header
    ADD CONSTRAINT material_leftover_header_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: material_leftover_header material_leftover_header_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_header
    ADD CONSTRAINT material_leftover_header_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: material_leftover_items material_leftover_items_header_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_items
    ADD CONSTRAINT material_leftover_items_header_id_fkey FOREIGN KEY (header_id) REFERENCES public.material_leftover_header(id) ON DELETE CASCADE;


--
-- Name: material_leftover_items material_leftover_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_leftover_items
    ADD CONSTRAINT material_leftover_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id);


--
-- Name: material_out_header material_out_header_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: material_out_header material_out_header_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id);


--
-- Name: material_out_header material_out_header_spk_id_fkey1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_spk_id_fkey1 FOREIGN KEY (spk_id) REFERENCES public.spk(id);


--
-- Name: material_out_header material_out_header_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_header
    ADD CONSTRAINT material_out_header_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE RESTRICT;


--
-- Name: material_out_items material_out_items_bin_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_items
    ADD CONSTRAINT material_out_items_bin_location_id_fkey FOREIGN KEY (bin_location_id) REFERENCES public.bin_locations(id);


--
-- Name: material_out_items material_out_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_items
    ADD CONSTRAINT material_out_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id) ON DELETE RESTRICT;


--
-- Name: material_out_items material_out_items_material_out_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_out_items
    ADD CONSTRAINT material_out_items_material_out_id_fkey FOREIGN KEY (material_out_id) REFERENCES public.material_out_header(id) ON DELETE CASCADE;


--
-- Name: material_receipt_header material_receipt_header_po_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_po_id_fkey FOREIGN KEY (po_id) REFERENCES public.purchase_orders(id) ON DELETE SET NULL;


--
-- Name: material_receipt_header material_receipt_header_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: material_receipt_header material_receipt_header_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.pics(id) ON DELETE SET NULL;


--
-- Name: material_receipt_header material_receipt_header_reversed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_reversed_by_fkey FOREIGN KEY (reversed_by) REFERENCES public.pics(id) ON DELETE SET NULL;


--
-- Name: material_receipt_header material_receipt_header_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_header
    ADD CONSTRAINT material_receipt_header_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE RESTRICT;


--
-- Name: material_receipt_items material_receipt_items_bin_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_items
    ADD CONSTRAINT material_receipt_items_bin_location_id_fkey FOREIGN KEY (bin_location_id) REFERENCES public.bin_locations(id);


--
-- Name: material_receipt_items material_receipt_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_items
    ADD CONSTRAINT material_receipt_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id);


--
-- Name: material_receipt_items material_receipt_items_receipt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.material_receipt_items
    ADD CONSTRAINT material_receipt_items_receipt_id_fkey FOREIGN KEY (receipt_id) REFERENCES public.material_receipt_header(id) ON DELETE CASCADE;


--
-- Name: po_items po_items_budget_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_budget_item_id_fkey FOREIGN KEY (budget_item_id) REFERENCES public.budget_items(id) ON DELETE SET NULL;


--
-- Name: po_items po_items_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id);


--
-- Name: po_items po_items_currency_id_fkey1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_currency_id_fkey1 FOREIGN KEY (currency_id) REFERENCES public.m_currency(id);


--
-- Name: po_items po_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id) ON DELETE SET NULL;


--
-- Name: po_items po_items_po_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.po_items
    ADD CONSTRAINT po_items_po_id_fkey FOREIGN KEY (po_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: project_invoices project_invoices_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_invoices
    ADD CONSTRAINT project_invoices_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: project_invoices project_invoices_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_invoices
    ADD CONSTRAINT project_invoices_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.project_tasks(id) ON DELETE SET NULL;


--
-- Name: project_payment_items project_payment_items_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payment_items
    ADD CONSTRAINT project_payment_items_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.project_invoices(id);


--
-- Name: project_payment_items project_payment_items_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payment_items
    ADD CONSTRAINT project_payment_items_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.project_payments(id);


--
-- Name: project_payments project_payments_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments
    ADD CONSTRAINT project_payments_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: project_payments project_payments_client_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments
    ADD CONSTRAINT project_payments_client_id_fkey FOREIGN KEY (client_id) REFERENCES public.clients(id);


--
-- Name: project_payments project_payments_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_payments
    ADD CONSTRAINT project_payments_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id);


--
-- Name: project_progress_log project_progress_log_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_progress_log
    ADD CONSTRAINT project_progress_log_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: project_tasks project_tasks_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_tasks
    ADD CONSTRAINT project_tasks_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: project_wip_release project_wip_release_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_wip_release
    ADD CONSTRAINT project_wip_release_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: projects projects_budget_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_budget_currency_id_fkey FOREIGN KEY (budget_currency_id) REFERENCES public.m_currency(id);


--
-- Name: projects projects_client_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_client_id_fkey FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: projects projects_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.m_location(id);


--
-- Name: projects projects_pic_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pic_id_fkey FOREIGN KEY (pic_id) REFERENCES public.m_employee(id) ON DELETE SET NULL;


--
-- Name: purchase_invoice_dtl purchase_invoice_dtl_grn_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_dtl
    ADD CONSTRAINT purchase_invoice_dtl_grn_hdr_id_fkey FOREIGN KEY (grn_hdr_id) REFERENCES public.grn_hdr(id);


--
-- Name: purchase_invoice_dtl purchase_invoice_dtl_purchase_invoice_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_dtl
    ADD CONSTRAINT purchase_invoice_dtl_purchase_invoice_hdr_id_fkey FOREIGN KEY (invoice_hdr_id) REFERENCES public.purchase_invoice_hdr(id);


--
-- Name: purchase_invoice_hdr purchase_invoice_hdr_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_invoice_hdr
    ADD CONSTRAINT purchase_invoice_hdr_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id);


--
-- Name: purchase_orders purchase_orders_budget_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_budget_currency_id_fkey FOREIGN KEY (budget_currency_id) REFERENCES public.m_currency(id);


--
-- Name: purchase_orders purchase_orders_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id) ON DELETE SET NULL;


--
-- Name: purchase_return_dtl purchase_return_dtl_grn_dtl_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_dtl
    ADD CONSTRAINT purchase_return_dtl_grn_dtl_id_fkey FOREIGN KEY (grn_dtl_id) REFERENCES public.grn_dtl(id);


--
-- Name: purchase_return_dtl purchase_return_dtl_return_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_dtl
    ADD CONSTRAINT purchase_return_dtl_return_hdr_id_fkey FOREIGN KEY (return_hdr_id) REFERENCES public.purchase_return_hdr(id) ON DELETE CASCADE;


--
-- Name: purchase_return_dtl purchase_return_dtl_wh_receiving_dtl_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_dtl
    ADD CONSTRAINT purchase_return_dtl_wh_receiving_dtl_id_fkey FOREIGN KEY (wh_receiving_dtl_id) REFERENCES public.wh_receiving_dtl(id);


--
-- Name: purchase_return_hdr purchase_return_hdr_grn_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr
    ADD CONSTRAINT purchase_return_hdr_grn_hdr_id_fkey FOREIGN KEY (grn_hdr_id) REFERENCES public.grn_hdr(id);


--
-- Name: purchase_return_hdr purchase_return_hdr_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr
    ADD CONSTRAINT purchase_return_hdr_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: purchase_return_hdr purchase_return_hdr_wh_receiving_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_return_hdr
    ADD CONSTRAINT purchase_return_hdr_wh_receiving_hdr_id_fkey FOREIGN KEY (wh_receiving_hdr_id) REFERENCES public.wh_receiving_hdr(id);


--
-- Name: spk_items spk_items_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_items
    ADD CONSTRAINT spk_items_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id);


--
-- Name: spk_items spk_items_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_items
    ADD CONSTRAINT spk_items_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE CASCADE;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_current_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_current_subcontractor_id_fkey FOREIGN KEY (current_subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_source_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_source_subcontractor_id_fkey FOREIGN KEY (source_subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_supply_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_supply_id_fkey FOREIGN KEY (supply_id) REFERENCES public.spk_material_supply(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_lots spk_material_leftover_lots_work_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_lots
    ADD CONSTRAINT spk_material_leftover_lots_work_item_id_fkey FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_buyer_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_buyer_subcontractor_id_fkey FOREIGN KEY (buyer_subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_inventory_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_inventory_doc_id_fkey FOREIGN KEY (inventory_doc_id) REFERENCES public.material_leftover_header(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_inventory_out_doc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_inventory_out_doc_id_fkey FOREIGN KEY (inventory_out_doc_id) REFERENCES public.material_out_header(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_leftover_lot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_leftover_lot_id_fkey FOREIGN KEY (leftover_lot_id) REFERENCES public.spk_material_leftover_lots(id) ON DELETE RESTRICT;


--
-- Name: spk_material_leftover_transactions spk_material_leftover_transactions_seller_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_leftover_transactions
    ADD CONSTRAINT spk_material_leftover_transactions_seller_subcontractor_id_fkey FOREIGN KEY (seller_subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_supply spk_material_supply_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply
    ADD CONSTRAINT spk_material_supply_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id) ON DELETE RESTRICT;


--
-- Name: spk_material_supply spk_material_supply_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply
    ADD CONSTRAINT spk_material_supply_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;


--
-- Name: spk_material_supply spk_material_supply_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply
    ADD CONSTRAINT spk_material_supply_subcontractor_id_fkey FOREIGN KEY (subcontractor_id) REFERENCES public.subcontractors(id) ON DELETE RESTRICT;


--
-- Name: spk_material_supply spk_material_supply_work_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_material_supply
    ADD CONSTRAINT spk_material_supply_work_item_id_fkey FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;


--
-- Name: spk spk_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk
    ADD CONSTRAINT spk_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: spk_task_expenses spk_task_expenses_spk_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_expenses
    ADD CONSTRAINT spk_task_expenses_spk_task_id_fkey FOREIGN KEY (spk_task_id) REFERENCES public.spk_tasks(id) ON DELETE CASCADE;


--
-- Name: spk_task_expenses spk_task_expenses_task_expense_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_expenses
    ADD CONSTRAINT spk_task_expenses_task_expense_id_fkey FOREIGN KEY (task_expense_id) REFERENCES public.task_expenses(id) ON DELETE RESTRICT;


--
-- Name: spk_task_materials spk_task_materials_spk_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_materials
    ADD CONSTRAINT spk_task_materials_spk_task_id_fkey FOREIGN KEY (spk_task_id) REFERENCES public.spk_tasks(id) ON DELETE CASCADE;


--
-- Name: spk_task_materials spk_task_materials_task_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_task_materials
    ADD CONSTRAINT spk_task_materials_task_material_id_fkey FOREIGN KEY (task_material_id) REFERENCES public.task_materials(id) ON DELETE RESTRICT;


--
-- Name: spk_tasks spk_tasks_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_tasks
    ADD CONSTRAINT spk_tasks_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE CASCADE;


--
-- Name: spk_tasks spk_tasks_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_tasks
    ADD CONSTRAINT spk_tasks_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.project_tasks(id) ON DELETE RESTRICT;


--
-- Name: spk_work_items spk_work_items_budget_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items
    ADD CONSTRAINT spk_work_items_budget_item_id_fkey FOREIGN KEY (budget_item_id) REFERENCES public.budget_items(id) ON DELETE RESTRICT;


--
-- Name: spk_work_items spk_work_items_project_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items
    ADD CONSTRAINT spk_work_items_project_task_id_fkey FOREIGN KEY (project_task_id) REFERENCES public.project_tasks(id) ON DELETE RESTRICT;


--
-- Name: spk_work_items spk_work_items_spk_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spk_work_items
    ADD CONSTRAINT spk_work_items_spk_id_fkey FOREIGN KEY (spk_id) REFERENCES public.spk(id) ON DELETE RESTRICT;


--
-- Name: stock_movements stock_movements_grade_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_grade_id_fkey FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;


--
-- Name: stock_movements stock_movements_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.materials(id);


--
-- Name: stock_movements stock_movements_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: stock_opname_dtl stock_opname_dtl_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_dtl
    ADD CONSTRAINT stock_opname_dtl_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id);


--
-- Name: stock_opname_dtl stock_opname_dtl_opname_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_dtl
    ADD CONSTRAINT stock_opname_dtl_opname_id_fkey FOREIGN KEY (opname_id) REFERENCES public.stock_opname_hdr(id);


--
-- Name: stock_opname_hdr stock_opname_hdr_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_opname_hdr
    ADD CONSTRAINT stock_opname_hdr_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: stock_transfer_dtl stock_transfer_dtl_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_dtl
    ADD CONSTRAINT stock_transfer_dtl_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.materials(id);


--
-- Name: stock_transfer_dtl stock_transfer_dtl_transfer_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_dtl
    ADD CONSTRAINT stock_transfer_dtl_transfer_hdr_id_fkey FOREIGN KEY (transfer_hdr_id) REFERENCES public.stock_transfer_hdr(id) ON DELETE CASCADE;


--
-- Name: stock_transfer_hdr stock_transfer_hdr_from_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_hdr
    ADD CONSTRAINT stock_transfer_hdr_from_warehouse_id_fkey FOREIGN KEY (from_warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: stock_transfer_hdr stock_transfer_hdr_to_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_hdr
    ADD CONSTRAINT stock_transfer_hdr_to_warehouse_id_fkey FOREIGN KEY (to_warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: subcon_contract_tasks subcon_contract_tasks_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contract_tasks
    ADD CONSTRAINT subcon_contract_tasks_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id);


--
-- Name: subcon_contracts subcon_contracts_budget_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT subcon_contracts_budget_item_id_fkey FOREIGN KEY (budget_item_id) REFERENCES public.budget_items(id);


--
-- Name: subcon_contracts subcon_contracts_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT subcon_contracts_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: subcon_contracts subcon_contracts_subcontractor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_contracts
    ADD CONSTRAINT subcon_contracts_subcontractor_id_fkey FOREIGN KEY (subcontractor_id) REFERENCES public.subcontractors(id);


--
-- Name: subcon_inspection_items subcon_inspection_items_inspection_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspection_items
    ADD CONSTRAINT subcon_inspection_items_inspection_id_fkey FOREIGN KEY (inspection_id) REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT;


--
-- Name: subcon_inspection_items subcon_inspection_items_progress_qty_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspection_items
    ADD CONSTRAINT subcon_inspection_items_progress_qty_item_id_fkey FOREIGN KEY (progress_qty_item_id) REFERENCES public.subcon_progress_qty_items(id) ON DELETE RESTRICT;


--
-- Name: subcon_inspections subcon_inspections_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_inspections
    ADD CONSTRAINT subcon_inspections_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id) ON DELETE RESTRICT;


--
-- Name: subcon_invoice_payments subcon_invoice_payments_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: subcon_invoice_payments subcon_invoice_payments_cancelled_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_cancelled_by_fkey FOREIGN KEY (cancelled_by) REFERENCES public.m_user_system(id);


--
-- Name: subcon_invoice_payments subcon_invoice_payments_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id) ON DELETE CASCADE;


--
-- Name: subcon_invoice_payments subcon_invoice_payments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.m_user_system(id);


--
-- Name: subcon_invoice_payments subcon_invoice_payments_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoice_payments
    ADD CONSTRAINT subcon_invoice_payments_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.subcon_invoices(id) ON DELETE CASCADE;


--
-- Name: subcon_invoices subcon_invoices_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.m_user_system(id);


--
-- Name: subcon_invoices subcon_invoices_cancelled_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_cancelled_by_fkey FOREIGN KEY (cancelled_by) REFERENCES public.m_user_system(id);


--
-- Name: subcon_invoices subcon_invoices_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id) ON DELETE CASCADE;


--
-- Name: subcon_invoices subcon_invoices_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.m_user_system(id);


--
-- Name: subcon_invoices subcon_invoices_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_invoices
    ADD CONSTRAINT subcon_invoices_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id);


--
-- Name: subcon_payment_certificate_items subcon_payment_certificate_items_certificate_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items
    ADD CONSTRAINT subcon_payment_certificate_items_certificate_id_fkey FOREIGN KEY (certificate_id) REFERENCES public.subcon_payment_certificates(id) ON DELETE RESTRICT;


--
-- Name: subcon_payment_certificate_items subcon_payment_certificate_items_inspection_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items
    ADD CONSTRAINT subcon_payment_certificate_items_inspection_item_id_fkey FOREIGN KEY (inspection_item_id) REFERENCES public.subcon_inspection_items(id) ON DELETE RESTRICT;


--
-- Name: subcon_payment_certificate_items subcon_payment_certificate_items_work_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificate_items
    ADD CONSTRAINT subcon_payment_certificate_items_work_item_id_fkey FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;


--
-- Name: subcon_payment_certificates subcon_payment_certificates_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates
    ADD CONSTRAINT subcon_payment_certificates_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id) ON DELETE RESTRICT;


--
-- Name: subcon_payment_certificates subcon_payment_certificates_inspection_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates
    ADD CONSTRAINT subcon_payment_certificates_inspection_id_fkey FOREIGN KEY (inspection_id) REFERENCES public.subcon_inspections(id) ON DELETE RESTRICT;


--
-- Name: subcon_payment_certificates subcon_payment_certificates_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payment_certificates
    ADD CONSTRAINT subcon_payment_certificates_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id) ON DELETE RESTRICT;


--
-- Name: subcon_payments subcon_payments_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments
    ADD CONSTRAINT subcon_payments_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: subcon_payments subcon_payments_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments
    ADD CONSTRAINT subcon_payments_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id);


--
-- Name: subcon_payments subcon_payments_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_payments
    ADD CONSTRAINT subcon_payments_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id);


--
-- Name: subcon_progress subcon_progress_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress
    ADD CONSTRAINT subcon_progress_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.subcon_contracts(id);


--
-- Name: subcon_progress_dtl subcon_progress_dtl_contract_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_dtl
    ADD CONSTRAINT subcon_progress_dtl_contract_task_id_fkey FOREIGN KEY (contract_task_id) REFERENCES public.subcon_contract_tasks(id);


--
-- Name: subcon_progress_dtl subcon_progress_dtl_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_dtl
    ADD CONSTRAINT subcon_progress_dtl_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id);


--
-- Name: subcon_progress_qty_items subcon_progress_qty_items_contract_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items
    ADD CONSTRAINT subcon_progress_qty_items_contract_task_id_fkey FOREIGN KEY (contract_task_id) REFERENCES public.subcon_contract_tasks(id) ON DELETE RESTRICT;


--
-- Name: subcon_progress_qty_items subcon_progress_qty_items_progress_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items
    ADD CONSTRAINT subcon_progress_qty_items_progress_id_fkey FOREIGN KEY (progress_id) REFERENCES public.subcon_progress(id) ON DELETE RESTRICT;


--
-- Name: subcon_progress_qty_items subcon_progress_qty_items_work_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcon_progress_qty_items
    ADD CONSTRAINT subcon_progress_qty_items_work_item_id_fkey FOREIGN KEY (work_item_id) REFERENCES public.spk_work_items(id) ON DELETE RESTRICT;


--
-- Name: supplier_payment_items supplier_payment_items_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payment_items
    ADD CONSTRAINT supplier_payment_items_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.purchase_invoice_hdr(id);


--
-- Name: supplier_payment_items supplier_payment_items_supplier_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payment_items
    ADD CONSTRAINT supplier_payment_items_supplier_payment_id_fkey FOREIGN KEY (supplier_payment_id) REFERENCES public.supplier_payments(id) ON DELETE CASCADE;


--
-- Name: supplier_payments supplier_payments_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.chart_of_accounts(id);


--
-- Name: supplier_payments supplier_payments_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id);


--
-- Name: supplier_return_dtl supplier_return_dtl_material_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_dtl
    ADD CONSTRAINT supplier_return_dtl_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materials(id);


--
-- Name: supplier_return_dtl supplier_return_dtl_return_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_dtl
    ADD CONSTRAINT supplier_return_dtl_return_hdr_id_fkey FOREIGN KEY (return_hdr_id) REFERENCES public.supplier_return_hdr(id) ON DELETE CASCADE;


--
-- Name: supplier_return_hdr supplier_return_hdr_material_out_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_material_out_id_fkey FOREIGN KEY (material_out_id) REFERENCES public.material_out_header(id);


--
-- Name: supplier_return_hdr supplier_return_hdr_po_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_po_id_fkey FOREIGN KEY (po_id) REFERENCES public.purchase_orders(id);


--
-- Name: supplier_return_hdr supplier_return_hdr_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: supplier_return_hdr supplier_return_hdr_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.m_suppliers(id);


--
-- Name: supplier_return_hdr supplier_return_hdr_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: supplier_return_hdr supplier_return_hdr_wh_receiving_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_return_hdr
    ADD CONSTRAINT supplier_return_hdr_wh_receiving_hdr_id_fkey FOREIGN KEY (wh_receiving_hdr_id) REFERENCES public.wh_receiving_hdr(id);


--
-- Name: task_expenses task_expenses_budget_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_expenses
    ADD CONSTRAINT task_expenses_budget_item_id_fkey FOREIGN KEY (budget_item_id) REFERENCES public.budget_items(id) ON DELETE RESTRICT;


--
-- Name: task_expenses task_expenses_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_expenses
    ADD CONSTRAINT task_expenses_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.project_tasks(id) ON DELETE RESTRICT;


--
-- Name: task_materials task_materials_budget_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_materials
    ADD CONSTRAINT task_materials_budget_item_id_fkey FOREIGN KEY (budget_item_id) REFERENCES public.budget_items(id) ON DELETE RESTRICT;


--
-- Name: task_materials task_materials_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_materials
    ADD CONSTRAINT task_materials_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.project_tasks(id) ON DELETE RESTRICT;


--
-- Name: tax_exchange_rate_dtl tax_exchange_rate_dtl_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_dtl
    ADD CONSTRAINT tax_exchange_rate_dtl_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id) ON DELETE SET NULL;


--
-- Name: tax_exchange_rate_dtl tax_exchange_rate_dtl_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tax_exchange_rate_dtl
    ADD CONSTRAINT tax_exchange_rate_dtl_hdr_id_fkey FOREIGN KEY (hdr_id) REFERENCES public.tax_exchange_rate_hdr(id) ON DELETE CASCADE;


--
-- Name: warehouses warehouses_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.m_location(id) ON DELETE SET NULL;


--
-- Name: warehouses warehouses_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: wh_receiving_dtl wh_receiving_dtl_bin_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_bin_location_id_fkey FOREIGN KEY (bin_location_id) REFERENCES public.bin_locations(id);


--
-- Name: wh_receiving_dtl wh_receiving_dtl_currency_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.m_currency(id);


--
-- Name: wh_receiving_dtl wh_receiving_dtl_grade_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_grade_id_fkey FOREIGN KEY (grade_id) REFERENCES public.item_grade(id) NOT VALID;


--
-- Name: wh_receiving_dtl wh_receiving_dtl_grn_dtl_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_grn_dtl_id_fkey FOREIGN KEY (grn_dtl_id) REFERENCES public.grn_dtl(id);


--
-- Name: wh_receiving_dtl wh_receiving_dtl_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.materials(id);


--
-- Name: wh_receiving_dtl wh_receiving_dtl_wh_receiving_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_dtl
    ADD CONSTRAINT wh_receiving_dtl_wh_receiving_hdr_id_fkey FOREIGN KEY (wh_receiving_hdr_id) REFERENCES public.wh_receiving_hdr(id) ON DELETE CASCADE;


--
-- Name: wh_receiving_hdr wh_receiving_hdr_grn_hdr_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_hdr
    ADD CONSTRAINT wh_receiving_hdr_grn_hdr_id_fkey FOREIGN KEY (grn_hdr_id) REFERENCES public.grn_hdr(id);


--
-- Name: wh_receiving_hdr wh_receiving_hdr_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wh_receiving_hdr
    ADD CONSTRAINT wh_receiving_hdr_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- PostgreSQL database dump complete
--

