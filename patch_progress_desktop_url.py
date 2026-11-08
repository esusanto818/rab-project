from pathlib import Path
from datetime import datetime
import shutil
import sys

ROOT = Path.cwd()

VIEW = ROOT / "views/project.progress.php"
JS = ROOT / "js/project.progress.js"

if not VIEW.is_file() or not JS.is_file():
    sys.exit(
        "ERROR: File tidak ditemukan.\n"
        "Jalankan script dari root folder proyekgmn-dev."
    )

view = VIEW.read_text(encoding="utf-8")
js = JS.read_text(encoding="utf-8")

view_new = view
js_new = js

# ==========================================================
# 1. PATCH PHP VIEW
# ==========================================================

# Tambahkan BASE_URL setelah include
include_anchor = (
    'include "../php-func/user.valid.php";\n'
    'include "../includes/many-template-icn.php";'
)

include_replacement = (
    'include "../php-func/user.valid.php";\n'
    'include "../includes/many-template-icn.php";\n'
    '\n'
    '$appBase = rtrim(BASE_URL, \'/\') . \'/\';'
)

import re

# Tambahkan BASE_URL setelah include many-template
if "$appBase = rtrim(BASE_URL" not in view_new:
    pattern = (
        r'(?m)^([ \t]*include\s+'
        r'["\']\.\./includes/many-template-icn\.php["\'];?)'
        r'[ \t]*$'
    )

    view_new, count = re.subn(
        pattern,
        r'\1\n\n$appBase = rtrim(BASE_URL, \'/\') . \'/\';',
        view_new,
        count=1
    )

    if count != 1:
        sys.exit(
            "ERROR: Include many-template-icn.php tidak ditemukan. "
            "Periksa struktur project.progress.php."
        )

# Ganti CSS hardcoded DEV menjadi dinamis
css_replacements = {
    'href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css"':
    'href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>ext-3.4.1/resources/css/ext-all.css"',

    'href="/rab-project/dev/css/mainCSS.css"':
    'href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>css/mainCSS.css"',

    'href="/rab-project/dev/css/header.black.css"':
    'href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>css/header.black.css"',

    'href="/rab-project/dev/css/grddetail.css"':
    'href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>css/grddetail.css"',

    'href="/rab-project/dev/css/frm.css"':
    'href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>css/frm.css"',
}

for old, new in css_replacements.items():
    if old in view_new:
        view_new = view_new.replace(old, new, 1)
    elif new not in view_new:
        sys.exit(f"ERROR: CSS anchor tidak ditemukan: {old}")

# Tambahkan APP_BASE_URL sebelum konfigurasi formAccess
if "window.APP_BASE_URL" not in view_new:
    lines = view_new.splitlines()
    found = False

    for i, line in enumerate(lines):
        if "window.formAccess =" in line:
            indent = line[:len(line) - len(line.lstrip())]
            lines.insert(
                i,
                indent + 'window.APP_BASE_URL = <?php echo json_encode($appBase); ?>;'
            )
            found = True
            break

    if not found:
        sys.exit("ERROR: Baris window.formAccess tidak ditemukan.")

    view_new = "\\n".join(lines) + "\\n"

# Ganti pemanggilan JS hardcoded DEV
old_js_src = '<script type="text/javascript" src="/rab-project/dev/js/project.progress.js"></script>'
new_js_src = '<script type="text/javascript" src="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>js/project.progress.js"></script>'

if old_js_src in view_new:
    view_new = view_new.replace(old_js_src, new_js_src, 1)
elif new_js_src not in view_new:
    sys.exit("ERROR: Tag script project.progress.js tidak ditemukan.")

# ==========================================================
# 2. PATCH JAVASCRIPT
# ==========================================================

old_crd = "var CRD = '../dev/php-crud/project.progressCRD.php';"

new_crd = (
    "var APP_BASE_URL = window.APP_BASE_URL || '/';\n"
    "  var CRD = APP_BASE_URL + 'php-crud/project.progressCRD.php';"
)

if old_crd in js_new:
    js_new = js_new.replace(old_crd, new_crd, 1)
elif new_crd not in js_new:
    sys.exit("ERROR: Deklarasi CRD tidak ditemukan atau formatnya berbeda.")

# ==========================================================
# 3. VALIDASI SEBELUM MENULIS
# ==========================================================

if "/rab-project/dev/" in view_new:
    sys.exit("ERROR: Masih ditemukan URL DEV hardcoded pada PHP view.")

if "../dev/php-crud/project.progressCRD.php" in js_new:
    sys.exit("ERROR: URL endpoint DEV hardcoded masih ditemukan pada JS.")

if "window.APP_BASE_URL" not in view_new:
    sys.exit("ERROR: APP_BASE_URL belum terpasang pada PHP view.")

if "APP_BASE_URL + 'php-crud/project.progressCRD.php'" not in js_new:
    sys.exit("ERROR: Endpoint dinamis belum terpasang pada JS.")

if view_new == view and js_new == js:
    print("INFO: Kedua file sudah menggunakan konfigurasi dinamis.")
    sys.exit(0)

# ==========================================================
# 4. BACKUP DAN SIMPAN
# ==========================================================

backup_dir = ROOT / (
    "backup_progress_url_" + datetime.now().strftime("%Y%m%d_%H%M%S")
)
backup_dir.mkdir(parents=True, exist_ok=False)

shutil.copy2(VIEW, backup_dir / VIEW.name)
shutil.copy2(JS, backup_dir / JS.name)

try:
    VIEW.write_text(view_new, encoding="utf-8")
    JS.write_text(js_new, encoding="utf-8")
except Exception:
    shutil.copy2(backup_dir / VIEW.name, VIEW)
    shutil.copy2(backup_dir / JS.name, JS)
    raise

print("PATCH BERHASIL")
print(f"Backup : {backup_dir}")
print(f"Updated: {VIEW.relative_to(ROOT)}")
print(f"Updated: {JS.relative_to(ROOT)}")
