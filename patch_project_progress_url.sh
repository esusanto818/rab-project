cd /home/myadmin/proyekgmn-dev

cat > patch_project_progress_url.sh <<'BASH'
#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/myadmin/proyekgmn-dev"
cd "$ROOT"

echo "=========================================="
echo " PATCH PROJECT PROGRESS - URL & PWA"
echo "=========================================="

# Pastikan dijalankan di direktori yang benar.
if [[ ! -f "php-func/app.url.php" ||
      ! -f "views/project.progress.m.php" ||
      ! -f "js/project.progress.m.js" ||
      ! -f "sw.progress.js" ]]; then
    echo "ERROR: File proyek tidak lengkap."
    exit 1
fi

# Backup seluruh file yang akan diubah.
BACKUP="backup_project_progress_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP"

cp -a views/project.progress.m.php "$BACKUP/"
cp -a js/project.progress.m.js "$BACKUP/"
cp -a sw.progress.js "$BACKUP/"

echo "Backup dibuat: $BACKUP"

python3 <<'PY'
from pathlib import Path
import re
import sys

view = Path("views/project.progress.m.php")
js = Path("js/project.progress.m.js")
sw = Path("sw.progress.js")

# ==================================================
# 1. PATCH VIEW MOBILE
# ==================================================

content = view.read_text()

# Tambahkan APP_BASE_URL setelah user.valid.php.
if "window.APP_BASE_URL" not in content:
    pattern = re.compile(
        r"(?m)^([ \t]*(?:include|include_once|require|require_once)"
        r"\s*\(?[^;\n]*user\.valid\.php[^;\n]*;[ \t]*)$"
    )

    match = pattern.search(content)

    if not match:
        sys.exit(
            "ERROR: Pemanggilan user.valid.php tidak ditemukan "
            "di project.progress.m.php"
        )

    insertion = (
        match.group(1)
        + "\n$appBase = rtrim(BASE_URL, '/') . '/';\n"
    )

    content = content[:match.start()] + insertion + content[match.end():]

    # APP_BASE_URL harus didefinisikan di HTML, bukan di dalam PHP.
    marker = "</head>"

    if marker not in content:
        sys.exit("ERROR: Tag </head> tidak ditemukan di view mobile.")

    script = """<script>
window.APP_BASE_URL = <?= json_encode($appBase) ?>;
</script>

"""

    content = content.replace(marker, script + marker, 1)

# Ubah manifest.
content, manifest_count = re.subn(
    r'<link\s+rel=["\']manifest["\']\s+href=["\'][^"\']*manifest\.progress\.json["\']\s*/?>',
    '<link rel="manifest" href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>manifest.progress.json">',
    content,
    count=1,
    flags=re.I
)

if manifest_count == 0 and "manifest.progress.json" not in content:
    sys.exit("ERROR: Link manifest tidak ditemukan.")

# Ubah apple touch icon.
content, icon_count = re.subn(
    r'<link\s+rel=["\']apple-touch-icon["\']\s+href=["\'][^"\']*["\']\s*/?>',
    '<link rel="apple-touch-icon" href="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>assets/icon-192.png">',
    content,
    count=1,
    flags=re.I
)

if icon_count == 0 and "apple-touch-icon" not in content:
    sys.exit("ERROR: Link apple-touch-icon tidak ditemukan.")

# Ubah JavaScript mobile.
content, js_count = re.subn(
    r'<script\s+src=["\'][^"\']*project\.progress\.m\.js["\']\s*>\s*</script>',
    '<script src="<?= htmlspecialchars($appBase, ENT_QUOTES, \'UTF-8\') ?>js/project.progress.m.js"></script>',
    content,
    count=1,
    flags=re.I
)

if js_count == 0 and "project.progress.m.js" not in content:
    sys.exit("ERROR: Pemanggilan project.progress.m.js tidak ditemukan.")

view.write_text(content)
print("[OK] View mobile diperbarui.")

# ==================================================
# 2. PATCH JAVASCRIPT MOBILE
# ==================================================

content = js.read_text()

# Endpoint API.
content, api_count = re.subn(
    r"var\s+CRD\s*=\s*['\"]/rab-project/dev/php-crud/project\.progressCRD\.php['\"]\s*;",
    "var CRD = window.APP_BASE_URL + 'php-crud/project.progressCRD.php';",
    content,
    count=1
)

if api_count == 0 and "var CRD = window.APP_BASE_URL" not in content:
    sys.exit("ERROR: Endpoint CRD mobile tidak cocok dengan pola yang diharapkan.")

# Registrasi Service Worker.
sw_pattern = re.compile(
    r"if\s*\(\s*'serviceWorker'\s+in\s+navigator\s*\)\s*\{"
    r".*?navigator\.serviceWorker\.register\(.*?\);\s*\}",
    re.S
)

sw_replacement = """if ('serviceWorker' in navigator) {
        var base = window.APP_BASE_URL;

        navigator.serviceWorker.register(base + 'sw.progress.js', {
            scope: base
        }).catch(function (error) {
            console.error('Service Worker registration gagal:', error);
        });
    }"""

content, sw_count = sw_pattern.subn(sw_replacement, content, count=1)

if sw_count == 0:
    sys.exit("ERROR: Blok registrasi Service Worker tidak ditemukan.")

js.write_text(content)
print("[OK] JavaScript mobile diperbarui.")

# ==================================================
# 3. GANTI SERVICE WORKER
# ==================================================

service_worker = r'''/*
  Service Worker Project Progress.
  Base URL mengikuti lokasi file Service Worker.
  API php-crud tidak pernah di-cache.
*/

var APP_BASE = new URL('.', self.location.href).pathname;

var VERSION = 'prg-v3';

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
                    return key.indexOf('prg-') === 0 &&
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

    // API dan foto tidak pernah di-cache.
    if (url.pathname.indexOf(APP_BASE + 'php-crud/') !== -1) {
        return;
    }

    // Hanya file shell Project Progress.
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

sw.write_text(service_worker)
print("[OK] Service Worker diperbarui.")

print("\nPATCH SELESAI.")
PY

echo
echo "=========================================="
echo " VERIFIKASI AWAL"
echo "=========================================="

echo
echo "=== BASE URL ==="
php -r 'require "php-func/app.url.php"; echo BASE_URL, PHP_EOL;'

echo
echo "=== PEMANGGILAN PWA ==="
grep -nE 'APP_BASE_URL|manifest.progress|apple-touch-icon|project.progress.m.js' \
    views/project.progress.m.php

echo
echo "=== ENDPOINT DAN SERVICE WORKER ==="
grep -nE 'var CRD|serviceWorker.register|scope:' \
    js/project.progress.m.js

echo
echo "=== CACHE SERVICE WORKER ==="
grep -nE 'APP_BASE|VERSION|SHELL|caches.delete' sw.progress.js

echo
echo "Backup: $BACKUP"
echo "Selesai."
BASH

