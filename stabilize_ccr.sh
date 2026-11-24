#!/usr/bin/env bash
set -euo pipefail

CFG="$HOME/.claude-code-router/config.json"
cp "$CFG" "$CFG.bak-$(date +%Y%m%d%H%M%S)"

python3 - << 'PYEOF'
import json, os

cfg_path = os.path.expanduser("~/.claude-code-router/config.json")
with open(cfg_path, "r", encoding="utf-8") as f:
    cfg = json.load(f)

# Gunakan model dengan kuota tertinggi & paling stabil untuk coding
cfg["Router"] = {
    "default": "openrouter,meta-llama/llama-3.3-70b-instruct:free",
    "background": "GROQ,openai/gpt-oss-20b",
    "think": "GROQ,openai/gpt-oss-120b",
    "longContext": "openrouter,qwen/qwen3-coder:free",
    "longContextThreshold": 60000
}
cfg["API_TIMEOUT_MS"] = 180000

with open(cfg_path, "w", encoding="utf-8") as f:
    json.dump(cfg, f, indent=2)

print("[OK] Config CCR distabilkan ke llama-3.3-70b-instruct:free & gpt-oss-120b")
PYEOF

# Arahkan settings.groq.json ke model yang sama
cat << 'SETEOF' > /home/myadmin/.claude/settings.groq.json
{
  "env": {
    "ANTHROPIC_BASE_URL": "http://127.0.0.1:3456",
    "ANTHROPIC_AUTH_TOKEN": "sk-ccr-kIAsLDVB8PJFngB6-4O8VJ7YTlXBKSrxmkUyD3XCh-U",
    "MAX_THINKING_TOKENS": "0",
    "CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC": "1",
    "ANTHROPIC_MODEL": "openrouter,meta-llama/llama-3.3-70b-instruct:free",
    "ANTHROPIC_DEFAULT_SONNET_MODEL": "openrouter,meta-llama/llama-3.3-70b-instruct:free",
    "ANTHROPIC_DEFAULT_HAIKU_MODEL": "GROQ,openai/gpt-oss-20b"
  },
  "permissions": {
    "defaultMode": "bypassPermissions"
  }
}
SETEOF

ccr restart || (nohup ccr start >/tmp/ccr.log 2>&1 & sleep 3)
echo "[OK] CCR kembali aktif di port 3456!"
