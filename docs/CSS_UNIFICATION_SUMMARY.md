# CSS Unification Standard & Implementation Summary

**Tanggal:** 2026-11-27
**Status:** Audit & Konsolidasi CSS

## 1. CSS Terpusat (SSOT)
File `css/app-theme.css` ditetapkan sebagai Single Source of Truth untuk seluruh tema aplikasi, variabel warna (Light, Dark, Default), dan komponen UI (Button, Grid, Form, Modal).

## 2. Kelas CSS Standar
| Komponen | Kelas ExtJS (cls) | Kelas HTML/Tailwind | Deskripsi |
| :--- | :--- | :--- | :--- |
| Primary/Save | `btn-primary` | `app-btn-primary` | Aksi utama/submit |
| Print/Export | `btn-dark` | `app-btn-dark` | Aksi pendukung/cetak |
| Danger/Delete| `btn-danger` | `app-btn-danger` | Aksi hapus/batal |
| Default | (kosong) | `app-btn` | Aksi umum/neutral |

## 3. Komponen UI Terstandarisasi
- **Grid:** Menggunakan zebra row (alternating colors), hover state, dan selected row dengan kontras yang disesuaikan berdasarkan variabel tema.
- **Form Editor (Modal):** Header modal diseragamkan (`x-window-header-text` dengan warna header tema).
- **Tombol:** Semua tombol mengikuti sistem "Industrial Slate & Steel Amber".
- **Input Field:** Menggunakan border-color dan background-color yang terikat dengan tema.

## 4. Panduan Implementasi
1. Dilarang menggunakan inline CSS (`style: '...'`) pada file `js/*.js`.
2. Gunakan `cls` untuk ExtJS dan `class` untuk HTML non-ExtJS.
3. Pastikan `css/app-theme.css` dimuat di header template utama.
4. Jika diperlukan penyesuaian khusus (layout/width), gunakan class tambahan atau inline style struktural (layout only, bukan visual).
