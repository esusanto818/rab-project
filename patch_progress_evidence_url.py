from pathlib import Path
from datetime import datetime
import shutil
import sys

ROOT = Path(__file__).resolve().parent

VIEW = ROOT / "views/project.progress.php"
BACKEND = ROOT / "php-crud/project.progressCRD.php"
JS = ROOT / "js/project.progress.js"

stamp = datetime.now().strftime("%Y%m%d_%H%M%S")

# Baca seluruh file dan validasi dahulu sebelum mengubah apa pun.
try:
    view = VIEW.read_text()
    backend = BACKEND.read_text()
    js = JS.read_text()
except Exception as e:
    print("GAGAL membaca file:", e)
    sys.exit(1)

# 1. View desktop: inject APP_BASE_URL.
view_marker = 'window.formAccess ='
view_insert = (
    "        window.APP_BASE_URL = "
    "<?php echo json_encode(rtrim(BASE_URL, '/') . '/'); ?>;\n"
)

if "window.APP_BASE_URL" not in view:
    if view.count(view_marker) != 1:
        print("GAGAL: posisi injeksi APP_BASE_URL tidak ditemukan secara unik.")
        sys.exit(1)
    view_new = view.replace(view_marker, view_insert + "        " + view_marker, 1)
else:
    view_new = view

# 2. Backend: pastikan helper URL tersedia.
php_open = "<?php"
helper = "require_once dirname(__DIR__) . '/php-func/app.url.php';"

if php_open not in backend:
    print("GAGAL: pembuka PHP backend tidak ditemukan.")
    sys.exit(1)

if helper not in backend:
    backend_new = backend.replace(
        php_open,
        php_open + "\n" + helper,
        1
    )
else:
    backend_new = backend

old_upload_url = (
    "sendJsonResponse(true, ['url' => '/rab-project/uploads/progress/' . $name, 'name' => $name]);"
)

new_upload_url = (
    "sendJsonResponse(true, ["
    "'url' => rtrim(BASE_URL, '/') . '/uploads/progress/' . rawurlencode($name), "
    "'name' => $name"
    "]);"
)

if old_upload_url in backend_new:
    backend_new = backend_new.replace(old_upload_url, new_upload_url, 1)
elif new_upload_url not in backend_new:
    print("GAGAL: baris URL upload tidak cocok dengan kode yang diperiksa.")
    sys.exit(1)

# 3. JavaScript: normalisasi URL foto lama.
old_proof = """    function proofLinks(v) {
        if (!v) { return '-'; }
        var out = [], parts = String(v).split(/\\r?\\n/);
        for (var i = 0; i < parts.length; i++) {
            var u = parts[i].replace(/^\\s+|\\s+$/g, '');
            if (!u) { continue; }
            if (!/^(https?:\\/\\/|\\/)/i.test(u)) { out.push(enc(u)); continue; }
            var label = /\\.(jpe?g|png|gif|webp)(\\?.*)?$/i.test(u) ? 'Foto' : (/\\.pdf(\\?.*)?$/i.test(u) ? 'PDF' : 'Link');
            out.push('<a href="' + enc(u) + '" target="_blank" style="color:#7fe0a0;">' + label + ' ' + (out.length + 1) + '</a>');
        }
        return out.length ? out.join(' &nbsp;|&nbsp; ') : '-';
    }"""

new_proof = """    function normalizeProofUrl(url) {
        var value = String(url || '').replace(/^\\s+|\\s+$/g, '');
        if (!value) { return ''; }

        // URL lama dari lokasi aplikasi sebelumnya.
        // Hanya path bukti progress yang dinormalisasi.
        var match = value.match(/^\\/(?:proyek|rab-project)\\/uploads\\/progress\\/(.+)$/i)
                 || value.match(/^\\/uploads\\/progress\\/(.+)$/i);

        if (match) {
            var base = String(APP_BASE_URL || '/');
            if (base.charAt(base.length - 1) !== '/') {
                base += '/';
            }
            return base + 'uploads/progress/' + match[1];
        }

        return value;
    }

    function proofLinks(v) {
        if (!v) { return '-'; }
        var out = [], parts = String(v).split(/\\r?\\n/);
        for (var i = 0; i < parts.length; i++) {
            var u = parts[i].replace(/^\\s+|\\s+$/g, '');
            if (!u) { continue; }

            var href = normalizeProofUrl(u);

            if (!/^(https?:\\/\\/|\\/)/i.test(href)) {
                out.push(enc(href));
                continue;
            }

            var label = /\\.(jpe?g|png|gif|webp)(\\?.*)?$/i.test(href) ? 'Foto' : (/\\.pdf(\\?.*)?$/i.test(href) ? 'PDF' : 'Link');
            out.push('<a href="' + enc(href) + '" target="_blank" style="color:#7fe0a0;">' + label + ' ' + (out.length + 1) + '</a>');
        }
        return out.length ? out.join(' &nbsp;|&nbsp; ') : '-';
    }"""

if old_proof in js:
    js_new = js.replace(old_proof, new_proof, 1)
elif new_proof in js:
    js_new = js
else:
    print("GAGAL: blok proofLinks tidak cocok dengan kode yang diperiksa.")
    sys.exit(1)

# Backup semua file sebelum penulisan.
backup_dir = ROOT / ("backup_progress_evidence_url_" + stamp)
backup_dir.mkdir(parents=True, exist_ok=False)

for path in (VIEW, BACKEND, JS):
    shutil.copy2(path, backup_dir / path.name)

try:
    VIEW.write_text(view_new)
    BACKEND.write_text(backend_new)
    JS.write_text(js_new)
except Exception as e:
    print("GAGAL menulis patch:", e)
    print("Backup tersedia:", backup_dir)
    sys.exit(1)

print("PATCH BERHASIL")
print("Backup :", backup_dir)
print("Updated:")
print("-", VIEW.relative_to(ROOT))
print("-", BACKEND.relative_to(ROOT))
print("-", JS.relative_to(ROOT))
