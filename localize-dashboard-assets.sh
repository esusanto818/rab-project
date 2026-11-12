#!/usr/bin/env bash
# localize-dashboard-assets.sh
# Melokalkan Chart.js + Google Fonts (Inter, Orbitron, Rajdhani) untuk dashboard.
# Jalankan dari root repo:  bash localize-dashboard-assets.sh
# Butuh internet SEKALI saat skrip dijalankan; setelah itu aset dilayani dari server sendiri.
set -euo pipefail
cd "$(dirname "$0")"
[ -d views ] || { echo "ERROR: jalankan dari root repo (folder views/ tidak ditemukan)."; exit 1; }
command -v curl >/dev/null || { echo "ERROR: curl belum terpasang."; exit 1; }
command -v python3 >/dev/null || { echo "ERROR: python3 belum terpasang."; exit 1; }

mkdir -p assets/js assets/fonts assets/css

echo "[1/3] Chart.js -> assets/js/chart.umd.js"
curl -fsSL --retry 3 "https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js" -o assets/js/chart.umd.js
grep -q "Chart" assets/js/chart.umd.js || { echo "ERROR: file Chart.js tidak valid."; exit 1; }

echo "[2/3] Google Fonts -> assets/fonts/*.woff2 + assets/css/fonts.css"
python3 - <<'PY'
import urllib.request, re, pathlib, hashlib
CSS_URL = ("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700"
           "&family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&display=swap")
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
def fetch(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": UA}), timeout=90).read()
css = fetch(CSS_URL).decode("utf-8")
font_dir = pathlib.Path("assets/fonts"); font_dir.mkdir(parents=True, exist_ok=True)
urls = sorted(set(re.findall(r"url\((https://fonts\.gstatic\.com/[^)]+)\)", css)))
if not urls:
    raise SystemExit("ERROR: tidak ada URL font ditemukan (respons Google Fonts berubah?).")
for u in urls:
    base = u.split("/")[-1].split("?")[0]
    name = hashlib.sha1(u.encode()).hexdigest()[:10] + "-" + base
    dest = font_dir / name
    if not dest.exists():
        dest.write_bytes(fetch(u))
    css = css.replace(u, "../fonts/" + name)
out = pathlib.Path("assets/css/fonts.css")
out.write_text("/* Font lokal (Inter, Orbitron, Rajdhani) - hasil unduh Google Fonts; jangan edit manual. */\n" + css, encoding="utf-8")
print(f"    {len(urls)} file woff2, fonts.css = {out.stat().st_size} byte")
PY

echo "[3/3] Patch views/dashboard*.php (CDN -> lokal)"
python3 - <<'PY'
import pathlib, re
chart_local = """<script src="../assets/js/chart.umd.js?v=<?= @filemtime(__DIR__ . '/../assets/js/chart.umd.js') ?: date('YmdHis') ?>"></script>"""
fonts_local = """<link rel="stylesheet" href="../assets/css/fonts.css?v=<?= @filemtime(__DIR__ . '/../assets/css/fonts.css') ?: date('YmdHis') ?>">"""
chart_re = re.compile(r'''<script\b[^>]*src=["']https://cdn\.jsdelivr\.net/npm/chart\.js[^"']*["'][^>]*>\s*</script>''', re.I)
fonts_re = re.compile(r'''<link\b[^>]*href=["']https://fonts\.googleapis\.com/css2\?[^"']*["'][^>]*>''', re.I)
changed = []
for p in sorted(pathlib.Path("views").glob("dashboard*.php")):
    s = p.read_text(encoding="utf-8"); o = s
    s = chart_re.sub(lambda m: chart_local, s)
    s = fonts_re.sub(lambda m: fonts_local, s)
    if s != o:
        p.write_text(s, encoding="utf-8"); changed.append(p.name)
print("    diubah:", ", ".join(changed) if changed else "(tidak ada - mungkin sudah lokal)")
PY

echo ""
echo "Selesai. Verifikasi cepat:"
echo "  grep -REn 'cdn.jsdelivr.net/npm/chart.js|fonts.googleapis.com' views/dashboard*.php || echo 'OK: tidak ada CDN tersisa'"
echo ""
echo "Commit ke branch baru:"
echo "  git checkout -b fix/dashboard-local-assets"
echo "  git add assets/js/chart.umd.js assets/fonts assets/css/fonts.css views/dashboard*.php"
echo "  git commit -m 'fix(dashboard): localize Chart.js & Google Fonts'"
echo "  git push -u origin fix/dashboard-local-assets"
