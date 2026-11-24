#!/usr/bin/env bash
set -euo pipefail

CFG="$HOME/.claude-code-router/config.json"
cp "$CFG" "$CFG.bak-$(date +%Y%m%d%H%M%S)"

python3 - << 'PYEOF'
import json, os

cfg_path = os.path.expanduser("~/.claude-code-router/config.json")
with open(cfg_path, "r", encoding="utf-8") as f:
    cfg = json.load(f)

# Ganti routing utama dari llama-3.3-70b-versatile (yang kena limit) ke qwen3-coder:free & gpt-oss-120b
cfg["Router"]["default"] = "openrouter,qwen/qwen3-coder:free"
cfg["Router"]["think"] = "GROQ,openai/gpt-oss-120b"
cfg["Router"]["background"] = "GROQ,openai/gpt-oss-20b"
cfg["Router"]["longContext"] = "openrouter,qwen/qwen3-coder:free"

# Tambahkan alias openrouter/free jika settings.groq.json masih memintanya
for p in cfg.get("Providers", []):
    if p.get("name") == "openrouter" and "openrouter/free" not in p["models"]:
        p["models"].append("openrouter/free")

with open(cfg_path, "w", encoding="utf-8") as f:
    json.dump(cfg, f, indent=2)

print("[OK] CCR Router dialihkan ke openrouter,qwen/qwen3-coder:free & GROQ,openai/gpt-oss-120b")
PYEOF

ccr restart
