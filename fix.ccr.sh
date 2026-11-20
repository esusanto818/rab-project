#!/bin/bash

# Script Perbaikan Claude Code Router (CCR) + OpenRouter
# Tanggal: 18 November 2026

set -e

echo "🔧 Script Perbaikan Claude Code Router (CCR)"
echo "============================================"
echo ""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status() { echo -e "${GREEN}✓${NC} $1"; }
print_warning() { echo -e "${YELLOW}⚠${NC} $1"; }
print_error() { echo -e "${RED}✗${NC} $1"; }

# 1. Perbaiki Auth Conflict di CCR
echo "📋 Langkah 1: Memperbaiki Auth Conflict"
echo "--------------------------------------"
# CCR akan konflik jika ANTHROPIC_AUTH_TOKEN atau ANTHROPIC_API_KEY di-set di environment
for var in ANTHROPIC_AUTH_TOKEN ANTHROPIC_API_KEY; do
    if [ -n "${!var}" ]; then
        print_warning "$var terdeteksi: ${!var:0:10}..."
        echo "Unsetting $var..."
        unset "$var"
        print_status "$var di-unset dari sesi saat ini"
    fi
done

# Hapus dari bashrc/zshrc agar tidak muncul lagi di sesi berikutnya
for rcfile in ~/.bashrc ~/.zshrc ~/.profile; do
    if [ -f "$rcfile" ] && grep -qE "ANTHROPIC_AUTH_TOKEN|ANTHROPIC_API_KEY" "$rcfile"; then
        print_warning "Ditemukan key Anthropic di $rcfile"
        sed -i '/ANTHROPIC_AUTH_TOKEN/d' "$rcfile"
        sed -i '/ANTHROPIC_API_KEY/d' "$rcfile"
        print_status "Dibersihkan dari $rcfile"
    fi
done
echo ""

# 2. Konfigurasi CCR untuk OpenRouter
echo "📋 Langkah 2: Memeriksa & Memperbaiki Konfigurasi CCR"
echo "----------------------------------------------------"
CCR_CONFIG_DIR="$HOME/.claude-code-router"
CCR_CONFIG="$CCR_CONFIG_DIR/config.json"

mkdir -p "$CCR_CONFIG_DIR"

if [ -f "$CCR_CONFIG" ]; then
    print_status "Config CCR ditemukan: $CCR_CONFIG"
    
    # Backup config lama
    cp "$CCR_CONFIG" "$CCR_CONFIG.backup.$(date +%Y%m%d_%H%M%S)"
    print_status "Backup dibuat"
else
    print_warning "Config CCR belum ada, membuat baru..."
fi

# Cek apakah OPENROUTER_API_KEY sudah di-set
if [ -z "$OPENROUTER_API_KEY" ]; then
    print_error "OPENROUTER_API_KEY tidak ditemukan di environment!"
    echo "Silakan export terlebih dahulu: export OPENROUTER_API_KEY='sk-or-v1-...'"
    echo "Atau masukkan langsung ke config.json di bawah."
else
    print_status "OPENROUTER_API_KEY terdeteksi"
fi

# Tulis config.json yang valid untuk OpenRouter
# Kita tambahkan model mapping agar jika Claude Code meminta model aneh, ada fallback-nya
cat > "$CCR_CONFIG" << 'EOF'
{
  "LOG": true,
  "API_TIMEOUT_MS": 120000,
  "Providers": [
    {
      "name": "openrouter",
      "api_base_url": "https://openrouter.ai/api/v1/chat/completions",
      "api_key": "${OPENROUTER_API_KEY}",
      "models": [
        "openai/gpt-4o",
        "qwen/qwen3-coder:free",
        "meta-llama/llama-3.3-70b-instruct",
        "openai/gpt-oss-20b:free"
      ]
    }
  ],
  "ModelMappings": {
    "gpt-oss-120b": "openai/gpt-oss-20b:free",
    "default": "qwen/qwen3-coder:free"
  }
}
EOF
print_status "Config CCR diperbarui dengan mapping model OpenRouter yang valid"
echo ""

# 3. Restart CCR Service
echo "📋 Langkah 3: Merestart Claude Code Router (CCR)"
echo "------------------------------------------------"
if command -v ccr &> /dev/null; then
    print_status "Menghentikan proses CCR yang lama..."
    pkill -f "claude-code-router" || true
    sleep 2
    
    print_status "Memulai CCR kembali..."
    # Jalankan CCR di background
    ccr start &
    sleep 3
    
    if curl -s http://127.0.0.1:3456/v1/models > /dev/null 2>&1; then
        print_status "CCR berhasil berjalan di port 3456"
    else
        print_error "CCR gagal berjalan. Cek log di ~/.claude-code-router.log"
    fi
else
    print_error "Perintah 'ccr' tidak ditemukan. Pastikan CCR sudah diinstal:"
    echo "  npm install -g @musistudio/claude-code-router"
fi
echo ""

# 4. Verifikasi
echo "📋 Langkah 4: Verifikasi"
echo "------------------------"
echo "1. Buka UI CCR untuk memastikan settingan: ccr ui"
echo "2. Test Claude Code dengan model yang valid:"
echo "   claude -m 'qwen/qwen3-coder:free' 'Halo, tes 1 2 3'"
echo ""
echo "✅ Perbaikan CCR selesai!"
