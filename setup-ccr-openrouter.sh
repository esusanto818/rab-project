#!/bin/bash
# =====================================================================
#  Setup OpenRouter di CCR (Claude Code Router) - v2
#  Akar masalah: gpt-oss-120b hanya ada di OpenRouter, bukan Groq
#  Tanggal: 18 Nov 2026
# =====================================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok(){  echo -e "${GREEN}✓${NC} $1"; }
warn(){ echo -e "${YELLOW}⚠${NC} $1"; }
err(){ echo -e "${RED}✗${NC} $1"; }

CCR_DIR="$HOME/.claude-code-router"
CFG="$CCR_DIR/config.json"
mkdir -p "$CCR_DIR"

echo "=============================================="
echo "  CCR + OpenRouter Setup"
echo "=============================================="

# ---------- 1. Auth conflict (ANTHROPIC_AUTH_TOKEN vs apiKeyHelper) ----------
echo -e "\n[1/5] Membersihkan auth conflict..."
unset ANTHROPIC_AUTH_TOKEN 2>/dev/null || true
for rc in ~/.bashrc ~/.zshrc ~/.profile; do
  [ -f "$rc" ] && grep -qE 'ANTHROPIC_AUTH_TOKEN|ANTHROPIC_API_KEY' "$rc" \
    && { sed -i '/ANTHROPIC_AUTH_TOKEN/d;/ANTHROPIC_API_KEY/d' "$rc"; ok "Bersih dari $rc"; }
done
# apiKeyHelper juga bentrok -> hapus dari settings Claude Code
for s in "$HOME/.claude/settings.json" "$HOME/.claude.json"; do
  [ -f "$s" ] && grep -q 'apiKeyHelper' "$s" \
    && { python3 -c "import json,sys; p='$s'; d=json.load(open(p)); d.pop('apiKeyHelper',None); json.dump(d,open(p,'w'),indent=2)"; ok "apiKeyHelper dihapus dari $s"; }
done

# ---------- 2. Pastikan OPENROUTER_API_KEY ada ----------
echo -e "\n[2/5] Cek OpenRouter API key..."
if [ -z "${OPENROUTER_API_KEY:-}" ]; then
  err "OPENROUTER_API_KEY belum di-set!"
  echo "   export OPENROUTER_API_KEY='sk-or-v1-xxxxxxxx'"
  echo "   (buat key di https://openrouter.ai/keys , isi kredit utk gpt-oss-120b)"
  exit 1
fi
ok "OPENROUTER_API_KEY terdeteksi: ${OPENROUTER_API_KEY:0:12}..."

# ---------- 3. Backup + merge config.json ----------
echo -e "\n[3/5] Merge provider OpenRouter ke $CFG ..."
[ -f "$CFG" ] && cp "$CFG" "$CFG.bak.$(date +%Y%m%d_%H%M%S)" && ok "Backup dibuat"

python3 - "$CFG" <<'PY'
import json, sys, os
cfg_path = sys.argv[1]

# load existing (CCR v2) atau buat skeleton
if os.path.exists(cfg_path):
    try:
        cfg = json.load(open(cfg_path))
    except Exception:
        cfg = {}
else:
    cfg = {}

cfg.setdefault("Providers", [])
cfg.setdefault("Router", {})
cfg.setdefault("LOG", True)
cfg.setdefault("API_TIMEOUT_MS", 120000)

OR_MODELS = [
    "openai/gpt-oss-120b",     # berbayar (utama) [[1]]
    "openai/gpt-oss-20b",      # fallback murah [[6]]
    "qwen/qwen3-coder:free",   # gratis utk background
    "meta-llama/llama-3.3-70b-instruct",
]

# update / tambah provider openrouter (idempotent)
or_prov = next((p for p in cfg["Providers"] if p.get("name")=="openrouter"), None)
if or_prov is None:
    or_prov = {"name":"openrouter"}
    cfg["Providers"].append(or_prov)
or_prov["api_base_url"] = "https://openrouter.ai/api/v1/chat/completions"
or_prov["api_key"]      = "${OPENROUTER_API_KEY}"   # CCR resolve dari env
or_prov["models"]       = OR_MODELS

# routing: default -> gpt-oss-120b via openrouter; murah/gratis utk background
cfg["Router"]["default"]      = "openrouter,openai/gpt-oss-120b"
cfg["Router"]["background"]   = "openrouter,qwen/qwen3-coder:free"
cfg["Router"]["think"]        = "openrouter,openai/gpt-oss-120b"
cfg["Router"]["longContext"]  = "openrouter,openai/gpt-oss-120b"

json.dump(cfg, open(cfg_path,"w"), indent=2)
print("   Providers:", [p["name"] for p in cfg["Providers"]])
print("   Router.default:", cfg["Router"]["default"])
PY
ok "Config OpenRouter terpasang"

# ---------- 4. Restart CCR ----------
echo -e "\n[4/5] Restart CCR..."
if ! command -v ccr >/dev/null 2>&1; then
  err "Perintah 'ccr' tidak ditemukan. Install: npm i -g @musistudio/claude-code-router"
  exit 1
fi
pkill -f "claude-code-router" 2>/dev/null || true
sleep 2
ccr start >/dev/null 2>&1 &
sleep 3
if curl -s --max-time 5 http://127.0.0.1:3456/v1/models >/dev/null 2>&1; then
  ok "CCR hidup di 127.0.0.1:3456"
else
  warn "CCR belum respon. Cek: ccr status  |  cat $CCR_DIR/*.log"
fi

# ---------- 5. Test end-to-end ke OpenRouter via CCR ----------
echo -e "\n[5/5] Test chat completion lewat CCR..."
RESP=$(curl -s --max-time 30 http://127.0.0.1:3456/v1/chat/completions \
  -H "Content-Type: application/json" -H "Authorization: Bearer ccr-test" \
  -d '{"model":"openrouter,openai/gpt-oss-120b","messages":[{"role":"user","content":"satu kata saja: halo"}]}')
if echo "$RESP" | grep -q '"content"'; then
  ok "Berhasil! OpenRouter + gpt-oss-120b jalan."
else
  err "Gagal. Response:"; echo "$RESP"
  echo "   -> Kalau 401/402: cek key & kredit OpenRouter."
  echo "   -> Kalau 404: pakai 'openrouter,openai/gpt-oss-20b' (tanpa :free)."
fi

echo -e "\n=============================================="
echo "  Selesai. Reload Claude Code (Ctrl+Shift+P -> Reload Window)"
echo "  atau jalankan:  claude  (header harusnya: OPENROUTER/openai/gpt-oss-120b)"
echo "=============================================="
