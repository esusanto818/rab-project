Gunakan sub-agent `business-gap-analyst` untuk menjalankan analisis celah proses bisnis (*Business Gap Analysis*), memberikan daftar pilihan modul yang kurang, membangun modul yang dipilih, dan langsung melakukan re-audit.

Instruksi / Pilihan User: $ARGUMENTS

Alur Eksekusi:
- **Jika `$ARGUMENTS` kosong atau meminta analisis:** Jalankan **Fase 1** (pindai proyek, buat `docs/BUSINESS_GAP_REPORT.md`, lalu tampilkan daftar pilihan modul yang disarankan beserta nomornya dan berhenti untuk menunggu pilihan user).
- **Jika `$ARGUMENTS` berisi pilihan modul (contoh: `"tambah modul AP dan Aging Hutang"` atau `"eksekusi pilihan 1 dan 2"`):** Langsung jalankan **Fase 2 (Build End-to-End)** diikuti **Fase 3 (Re-Audit Otomatis)**, lalu laporkan daftar file yang dibuat dan hasil re-auditnya.
