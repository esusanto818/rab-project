#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/myadmin/proyekgmn-dev"
cd "$ROOT"

echo "=========================================="
echo " PATCH PROJECT PROGRESS URL - VERSION 2"
echo "=========================================="

# Pastikan struktur proyek sesuai.
for f in \
    views/project.progress.m.php \
    js/project.progress.m.js \
    sw.progress.js
do
    if [[ ! -f "$f" ]]; then
        echo "ERROR: File tidak ditemukan: $f"
        exit 1
    fi
done

python3 <<'PY'
from pathlib import Path
from datetime import datetime
import shutil
import sys

view_path = Path("views/project.progress.m.php")
js_path = Path("js/project.progress.m.js")
sw_path = Path("sw.progress.js")

view = view_path.read_text()
js = js_path.read_text()

# --------------------------------------------------
# 1. VALIDASI STRUKTUR SEBELUM MENGUBAH FILE
# --------------------------------------------------

required_view = [
    'include "../php-func/user.valid.php";',
    '<link rel="manifest" href="/rab-project/manifest.progress.json">',
    '<link rel="apple-touch-icon" href="/rab-project/assets/icon-192.png">',
    '<script type="text/javascript" src="/rab-project/dev/js/project.progress.m.js"></script>'
]

for item in required_view:
    if item not in view:
        print("ERROR: Pola view tidak ditemukan:")
        print(item)
        sys.exit(1)

required_js = [
    "var CRD = '/rab-project/dev/php-crud/project.progressCRD.php';",
    """    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/rab-project/sw.progress.js', { scope: '/rab-project/' }).catch(function () {});
    }"""
]

for item in required_js:
    if item not in js:
        print("ERROR: Pola JavaScript tidak ditemukan:")
        print(item)
        sys.exit(1)

# --------------------------------------------------
# 2. SIAPKAN HASIL PATCH DI MEMORI
# --------------------------------------------------

view = view.replace(
    'include "../php-func/user.valid.php";   // wajib login; set $_SESSION["FormAccess"] untuk form mobile ini',
    '''include "../php-func/user.valid.php";   // wajib login; set $_SESSION["FormAccess"] untuk form mobile ini
    $appBase = rtrim(BASE_URL, '/') . '/';''',
    1
)

view = view.replace(
    '<link rel="manifest" href="/rab-project/manifest.progress.json">',
    '<link rel="manifest" href="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>manifest.progress.json">',
    1
)

view = view.replace(
    '<link rel="apple-touch-icon" href="/rab-project/assets/icon-192.png">',
    '<link rel="apple-touch-icon" href="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>assets/icon-192.png">',
    1
)

view = view.replace(
    '''    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>''',
    '''    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        window.APP_BASE_URL = <?php echo json_encode($appBase); ?>;
    </script>''',
    1
)

view = view.replace(
    '<script type="text/javascript" src="/rab-project/dev/js/project.progress.m.js"></script>',
    '<script type="text/javascript" src="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>js/project.progress.m.js"></script>',
    1
)

js = js.replace(
    "var CRD = '/rab-project/dev/php-crud/project.progressCRD.php';   // [PENTING] samakan dengan nama file backend di server",
    "var CRD = window.APP_BASE_URL + 'php-crud/project.progressCRD.php';",
    1
)

old_sw_registration = """    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/rab-project/sw.progress.js', { scope: '/rab-project/' }).catch(function () {});
    }"""

new_sw_registration = """    if ('serviceWorker' in navigator && window.APP_BASE_URL) {
        var base = window.APP_BASE_URL;

        navigator.serviceWorker.register(base + 'sw.progress.js', {
            scope: base
        }).catch(function (error) {
            console.error('Service Worker registration gagal:', error);
        });
    }"""

js = js.replace(old_sw_registration, new_sw_registration, 1)

# Pastikan semua perubahan penting benar-benar terbentuk.
checks = [
    ("view APP_BASE_URL", "window.APP_BASE_URL" in view),
    ("manifest dinamis", '<?= htmlspecialchars($appBase' in view and 'manifest.progress.json">' in view),
    ("ikon dinamis", 'assets/icon-192.png">' in view),
    ("JS mobile dinamis", 'js/project.progress.m.js">' in view),
    ("endpoint API dinamis", "var CRD = window.APP_BASE_URL +" in js),
    ("Service Worker dinamis", "register(base + 'sw.progress.js'" in js),
]

for name, ok in checks:
    print(("[OK] " if ok else "[GAGAL] ") + name)
    if not ok:
        sys.exit("Patch dibatalkan: hasil tidak lengkap.")

# --------------------------------------------------
# 3. SERVICE WORKER BARU
# --------------------------------------------------

sw = r'''/*
  Service Worker Project Progress.
  Base URL mengikuti lokasi file Service Worker.
  API php-crud tidak pernah di-cache.
*/

var APP_BASE = new URL('.', self.location.href).pathname;

// Cache dipisahkan berdasarkan base path.
// Cache dev dan production tidak saling menghapus.
var CACHE_PREFIX = 'prg-v3-' + APP_BASE.replace(/[^a-zA-Z0-9_-]/g, '_');
var VERSION = CACHE_PREFIX;

var SHELL = [
    APP_BASE + 'views/project.progress.m.php',
    APP_BASE + 'js/project.progress.m.js',
    APP_BASE + 'manifest.progress.json'
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(VERSION).then(function (cache) {
            return Promise.all(
                SHELL.map(function (url) {
                    return cache.add(url).catch(function (error) {
                        console.warn('Gagal cache shell:', url, error);
                    });
                })
            );
        })
    );

    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) {
                    return key.indexOf(CACHE_PREFIX) === 0 &&
                           key !== VERSION;
                }).map(function (key) {
                    return caches.delete(key);
                })
            );
        })
    );

    self.clients.claim();
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    var url = new URL(req.url);

    if (req.method !== 'GET' || url.origin !== location.origin) {
        return;
    }

    // API, CRUD, dan foto selalu langsung ke jaringan.
    if (url.pathname.indexOf(APP_BASE + 'php-crud/') === 0) {
        return;
    }

    // Hanya file shell Project Progress yang dikelola.
    if (SHELL.indexOf(url.pathname) === -1) {
        return;
    }

    e.respondWith(
        fetch(req).then(function (res) {
            if (res && res.ok) {
                var copy = res.clone();

                caches.open(VERSION).then(function (cache) {
                    cache.put(req, copy);
                });
            }

            return res;
        }).catch(function () {
            return caches.open(VERSION).then(function (cache) {
                return cache.match(req);
            });
        })
    );
});
'''

# --------------------------------------------------
# 4. BACKUP DAN TULIS FILE
# --------------------------------------------------

backup = Path(
    "backup_project_progress_v2_" +
    datetime.now().strftime("%Y%m%d_%H%M%S")
)
backup.mkdir(parents=True, exist_ok=False)

for path in (view_path, js_path, sw_path):
    shutil.copy2(path, backup / path.name)

print("\nBackup:", backup)

view_path.write_text(view)
js_path.write_text(js)
sw_path.write_text(sw)

print("[OK] project.progress.m.php")
print("[OK] project.progress.m.js")
print("[OK] sw.progress.js")
print("\nPATCH BERHASIL.")
PY

echo
echo "=========================================="
echo " VALIDASI SINTAKS"
echo "=========================================="

php -l views/project.progress.m.php
node --check js/project.progress.m.js
node --check sw.progress.js

echo
echo "PATCH SELESAI."
