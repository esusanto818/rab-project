# Database Schema: Subcon & Material Leftover (FINAL)

**Status**: ✅ LENGKAP - Tidak perlu SQL migration lagi
**Tanggal verifikasi**: 2026-11-13

## Tabel Utama

### SPK & Tasks
- `spk` - Header SPK (dengan spk_type: INTERNAL/SUBCON)
- `spk_tasks` - Mapping SPK ke project_tasks (UNIQUE spk_id+task_id)
- `spk_work_items` - Rincian pekerjaan (work_code, contract_qty, unit_price, material_supply: COMPANY/SUBCON/MIXED)

### Material Supply & Leftover
- `spk_material_supply` - Supply material per work item (qty_planned, qty_received, qty_accepted)
- `spk_material_leftover_lots` - Lot material sisa (lot_no, qty_origin, qty_available, parent_lot_id untuk split)
- `spk_material_leftover_transactions` - Transaksi leftover (transaction_type: RETURN_COMPANY/BUY_FROM_SUBCON/SELL_TO_CLIENT)

### Subcon Progress & Inspection
- `subcon_progress` - Header progress Subcon
- `subcon_progress_qty_items` - Qty progress per work item (qty_claimed, unit_price_snapshot)
- `subcon_inspections` - Header inspeksi (inspection_no, status: DRAFT/APPROVED/REJECTED/CANCELLED)
- `subcon_inspection_items` - Detail inspeksi (qty_inspected, qty_passed, qty_rejected, qty_rework, result)

### Payment Certificate
- `subcon_payment_certificates` - Sertifikat (gross_amount, ppn_amount, pph_amount, retention_amount, dp_recovery, other_deduction, net_payable)
- `subcon_payment_certificate_items` - Detail (qty_certified, unit_price_snapshot, amount_certified)

## Trigger Penting
- `fn_validate_spk_work_item_project` - Validasi konsistensi proyek
- `fn_validate_progress_qty_item` - Validasi qty progress vs kontrak
- `fn_validate_inspection_item` - Validasi qty lolos ≤ qty diperiksa
- `fn_validate_certificate_item` - Validasi qty certified ≤ qty lolos
- `fn_validate_certificate_approval` - Validasi approval certificate
- `fn_validate_spk_leftover_transaction` - Validasi transaksi leftover
- `fn_post_spk_leftover_transaction` - Auto-posting inventory
- `fn_block_posted_leftover_transaction_change` - Anti-tampering

## Query Dashboard (project.analysis.php)
```sql
SELECT COALESCE(SUM(spc.gross_amount), 0)
FROM subcon_payment_certificates spc
JOIN subcon_inspections si ON si.id = spc.inspection_id
JOIN subcon_progress sp ON sp.id = si.progress_id
JOIN subcon_contracts sc ON sc.id = sp.contract_id
WHERE sc.project_id = :project_id AND spc.status = 'APPROVED'
