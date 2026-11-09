
from pathlib import Path
from datetime import datetime
import shutil
import subprocess
import sys

root = Path("/home/myadmin/proyekgmn-dev")
target = root / "router.php"

content = target.read_text()

old = """        /*if (!router_authorize_endpoint($db, $userId, 'crud', $crudName)) {
            router_respond_denied(403, 'Anda tidak memiliki hak akses ke modul ini.');
        }*/
"""

if old not in content:
    sys.exit("INFO: Komentar lama tidak ditemukan. Tidak ada perubahan.")

backup = root / (
    "router.php.backup_cleanup_" +
    datetime.now().strftime("%Y%m%d_%H%M%S")
)

shutil.copy2(target, backup)

updated = content.replace(old, "", 1)
target.write_text(updated)

result = subprocess.run(
    ["php", "-l", str(target)],
    capture_output=True,
    text=True
)

if result.returncode != 0:
    target.write_text(content)
    print("GAGAL. File asli dipulihkan.")
    print(result.stderr)
    sys.exit(1)

print("CLEANUP BERHASIL")
print("Backup:", backup)
print(result.stdout)

