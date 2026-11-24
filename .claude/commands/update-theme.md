---
description: Jalankan agent theme-styler-auditor untuk menerapkan 3 tema baru, menyatukan CSS ke css/app-theme.css, dan menyeragamkan semua button secara otomatis.
---

Panggil sub-agent `theme-styler-auditor` untuk menjalankan seluruh migrasi tema, pembersihan CSS di JS, penyeragaman button, dan sinkronisasi theme switcher (`default`, `dark`, `light`) dengan mematuhi Hard Gate (`tmp_bak/` + `./verify_patch_diff.sh`) sampai selesai.
