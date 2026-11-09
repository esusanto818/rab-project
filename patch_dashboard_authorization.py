
from pathlib import Path
from datetime import datetime
import shutil
import subprocess
import sys

ROOT = Path("/home/myadmin/proyekgmn-dev")
TARGET = ROOT / "router.php"

if not TARGET.exists():
    sys.exit("ERROR: router.php tidak ditemukan")

content = TARGET.read_text()

old = """if (
    !router_authorize_endpoint($db, $userId, 'crud', $crudName)
) {
    router_respond_denied(403, 'Anda tidak memiliki hak akses ke modul ini.');
}"""

new = """$isDashboardApi = (
    strcasecmp($crudName, 'dashboardCRD.php') === 0
);

if (
    !$isDashboardApi &&
    !router_authorize_endpoint($db, $userId, 'crud', $crudName)
) {
    router_respond_denied(403, 'Anda tidak memiliki hak akses ke modul ini.');
}"""

if new in content:
    sys.exit("INFO: Patch sudah diterapkan. Tidak ada perubahan.")

if old not in content:
    sys.exit(
        "ERROR: Blok otorisasi lama tidak ditemukan. "
        "File tidak diubah. Periksa blok aktual router.php."
    )

backup = ROOT / (
    f"router.php.backup_dashboard_{datetime.now():%Y%m%d_%H%M%S}"
)

shutil.copy2(TARGET, backup)

try:
    updated = content.replace(old, new, 1)
    TARGET.write_text(updated)

    result = subprocess.run(
        ["php", "-l", str(TARGET)],
        capture_output=True,
        text=True
    )

    if result.returncode != 0:
        TARGET.write_text(content)
        print("PATCH GAGAL: Sintaks PHP tidak valid.")
        print(result.stdout)
        print(result.stderr)
        print("File asli dipulihkan.")
        sys.exit(1)

    print("PATCH BERHASIL")
    print("Backup:", backup)
    print("Updated:", TARGET)
    print(result.stdout)

except Exception:
    TARGET.write_text(content)
    raise

