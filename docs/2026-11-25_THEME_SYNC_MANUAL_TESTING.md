# Manual Testing Guide: Theme Synchronization System
Date: 2026-11-25
Module: Theme Sync & Dashboard UI

## Overview
This document provides instructions for manually verifying the implementation of the theme synchronization system in the RAB-Project. The system ensures theme persistence, cross-tab synchronization, and dynamic UI updates across the dashboard and other views.

---

## 1. Prerequisites
- Access to the development URL: `http://127.0.0.1/rab-project/dev/views/dashboard.php`
- Browser developer tools (F12) for console monitoring.
- Multiple browser tabs/windows for cross-tab testing.

---

## 2. Test Scenarios

### Scenario A: Initial Load & Persistence
1. **Action:** Open the dashboard page.
2. **Expected:** 
   - The dashboard loads with the last saved theme from `localStorage`.
   - If no theme is saved, it defaults to `default-california-dream`.
   - The theme icon and label in the header match the current theme state.
3. **Action:** Refresh the page.
4. **Expected:** The theme remains unchanged.

### Scenario B: Theme Cycling
1. **Action:** Click the theme toggle button (🌙/☀️ icon) in the header.
2. **Expected:** 
   - The theme cycles: `dark-warm-obsidian` -> `light-soft-sage` -> `default-california-dream` -> `dark-warm-obsidian`.
   - The `html` attribute `data-theme` updates accordingly.
   - The background (`--bg-app`) and text color (`--text-primary`) update immediately via CSS variables.
   - The icon and label text (`Dark`/`Light`) update to reflect the new state.

### Scenario C: Cross-Tab Synchronization
1. **Action:** Open `dashboard.php` in two separate browser tabs.
2. **Action:** Change the theme in Tab 1.
3. **Expected:** Tab 2 automatically updates its theme, icon, and label without a page refresh.

### Scenario D: UI & Chart Integrity
1. **Action:** Switch to "Keuangan" or "Material & RAB" tabs.
2. **Action:** Change the theme.
3. **Expected:** 
   - Charts (Chart.js) re-render with colors matching the new theme (via the `rethemeCharts()` call).
   - All dashboard cards, tables, and buttons maintain legible contrast.

---

## 3. Technical Verification (Developer Tools)

### CSS Check
- Inspect the `body` element.
- Ensure `background-color` is set to `var(--bg-app) !important` and `color` is `var(--text-primary) !important`.

### JavaScript Check
- Open the console and type `window.RabThemeSync`.
- Verify it returns an object with methods: `getTheme`, `setTheme`, `cycleTheme`, `toggle`, `updateUI`, `init`.
- Check for any "Red" console errors during theme transitions.

### Storage Check
- Go to **Application -> Local Storage**.
- Verify that the key `app-theme` matches the current `data-theme` attribute on the `html` tag.

---

## 4. Troubleshooting
If the theme fails to sync:
1. Ensure `js/theme-sync.js` is correctly loaded in the network tab.
2. Clear `localStorage` via console: `localStorage.clear()` and refresh.
3. Check if any hardcoded styles in `dashboard.php` are overriding the CSS variables.
