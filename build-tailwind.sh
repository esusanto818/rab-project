#!/bin/bash
# build-tailwind.sh - Build Tailwind CSS untuk production

echo " Building Tailwind CSS..."
cd "$(dirname "$0")/assets/tailwind"

./tailwindcss-linux-x64 \
  -i ./input.css \
  -o ../css/dashboard.tailwind.css \
  --minify

echo "✅ Build selesai: ../css/dashboard.tailwind.css"
ls -lh ../css/dashboard.tailwind.css
