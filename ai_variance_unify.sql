-- 2026-11-01.ai_variance_unify.sql
-- Satukan analisis AI dashboard dan Pembayaran Termin ke SATU tabel (ai_variance_logs).
-- Tabel ai_variance_logs sudah dipakai Pembayaran Termin (analyze_project_variance); kolom di bawah hanya menambah.
-- Aman diulang. Kompatibel PostgreSQL 9.6. Baris lama (rumus lama: kontrak vs uang diterima) tetap tersimpan, ditandai analysis IS NULL
-- dan TIDAK dipakai untuk status kartu dashboard.
ALTER TABLE ai_variance_logs
    ADD COLUMN IF NOT EXISTS risk_rule varchar(10),     -- risiko hasil aturan sistem (LOW / MEDIUM / HIGH); risk_level = risiko akhir setelah AI
    ADD COLUMN IF NOT EXISTS headline  text,
    ADD COLUMN IF NOT EXISTS analysis  jsonb,           -- hasil lengkap: ringkasan, tindakan, pertanyaan, temuan sistem, metrik, ctx_hash
    ADD COLUMN IF NOT EXISTS source    varchar(20),     -- dashboard | payment
    ADD COLUMN IF NOT EXISTS ai_used   boolean;        -- true = narasi dari AI, false = narasi dari temuan sistem
CREATE INDEX IF NOT EXISTS idx_avl_project_created ON ai_variance_logs (project_id, created_at DESC);
-- Makna total_budget / total_actual / variance_* pada baris baru: budget proyek (kurs anggaran) vs biaya riil
-- (material out proyek + pengeluaran + prestasi subkon disetujui); variance_amount = biaya - budget.
-- Tabel cache lama (dashboard_ai_cache) tidak dipakai lagi: DROP TABLE IF EXISTS dashboard_ai_cache;
