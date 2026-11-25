/**
 * theme.global.js - Centralized Theme Controller
 * ================================================
 * Sistem tema global untuk seluruh aplikasi RAB Project
 * - Mendukung Light/Dark mode
 * - Sinkronisasi real-time antar tab
 * - Kompatibel dengan ExtJS 3.4.1
 * - Persisten via localStorage
 * 
 * Usage:
 *   1. Include di <head> sebelum CSS lain:
 *      <script src="../js-func/theme.global.js"></script>
 *   2. Tambahkan tombol toggle di HTML:
 *      <button onclick="AppTheme.toggle()">🌓 Theme</button>
 *   3. (Opsional) Dengarkan event untuk re-render konten dinamis:
 *      window.addEventListener('app-theme-changed', function(e) { ... });
 */

(function(global) {
  'use strict';

  // ===== KONFIGURASI =====
  const STORAGE_KEY = 'app_theme';
  const DEFAULT_THEME = 'dark';
  const EVENT_NAME = 'app-theme-changed';
  const CHANNEL_NAME = 'rab-theme-channel';

  // ===== STATE =====
  let currentTheme = null;
  let listeners = [];

  // ===== CORE FUNCTIONS =====
  
  /**
   * Baca tema dari localStorage atau gunakan default
   */
  function getStoredTheme() {
    try {
      return localStorage.getItem(STORAGE_KEY) || DEFAULT_THEME;
    } catch (e) {
      console.warn('Theme: localStorage tidak tersedia, gunakan default:', DEFAULT_THEME);
      return DEFAULT_THEME;
    }
  }

  /**
   * Simpan tema ke localStorage
   */
  function storeTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {
      console.warn('Theme: Gagal menyimpan ke localStorage:', e);
    }
  }

  /**
   * Terapkan tema ke DOM (set attribute + update CSS variables)
   */
  function applyTheme(theme) {
    if (!theme || (theme !== 'light' && theme !== 'dark')) {
      theme = DEFAULT_THEME;
    }
    
    // Set attribute di <html> untuk CSS selector [data-theme="..."]
    document.documentElement.setAttribute('data-theme', theme);
    currentTheme = theme;
    
    // Broadcast ke semua listener
    notifyListeners(theme);
    
    // Log untuk debugging
    if (typeof console !== 'undefined') {
      console.log(`[Theme] Applied: ${theme}`);
    }
  }

  /**
   * Toggle antara light dan dark
   */
  function toggleTheme() {
    const next = currentTheme === 'dark' ? 'light' : 'dark';
    setTheme(next);
    return next;
  }

  /**
   * Set tema secara eksplisit
   */
  function setTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') {
      console.warn(`[Theme] Invalid theme: ${theme}, must be 'light' or 'dark'`);
      return;
    }
    
    applyTheme(theme);
    storeTheme(theme);
    
    // Broadcast ke tab lain via localStorage event
    // (otomatis trigger 'storage' event di tab lain)
  }

  /**
   * Inisialisasi tema saat halaman load
   * Dipanggil otomatis saat script dimuat
   */
  function init() {
    const stored = getStoredTheme();
    applyTheme(stored);
    
    // Dengarkan perubahan dari tab lain
    window.addEventListener('storage', function(e) {
      if (e.key === STORAGE_KEY && e.newValue) {
        if (e.newValue !== currentTheme) {
          applyTheme(e.newValue);
        }
      }
    });
    
    // Dengarkan BroadcastChannel (untuk modul di tab yang sama)
    if ('BroadcastChannel' in window) {
      try {
        const bc = new BroadcastChannel(CHANNEL_NAME);
        bc.onmessage = function(e) {
          if (e.data && e.data.type === 'theme-change' && e.data.theme) {
            if (e.data.theme !== currentTheme) {
              applyTheme(e.data.theme);
            }
          }
        };
      } catch (e) {
        // BroadcastChannel tidak didukung, tidak masalah
      }
    }
  }

  // ===== EVENT SYSTEM =====
  
  function notifyListeners(theme) {
    const event = new CustomEvent(EVENT_NAME, { 
      detail: { theme: theme } 
    });
    window.dispatchEvent(event);
    
    // Broadcast ke channel lain
    if ('BroadcastChannel' in window) {
      try {
        const bc = new BroadcastChannel(CHANNEL_NAME);
        bc.postMessage({ type: 'theme-change', theme: theme });
        bc.close();
      } catch (e) {
        // Ignore
      }
    }
  }

  function onThemeChange(callback) {
    if (typeof callback === 'function') {
      listeners.push(callback);
      window.addEventListener(EVENT_NAME, function(e) {
        callback(e.detail.theme);
      });
    }
  }

  // ===== EXTJS INTEGRATION =====
  
  /**
   * Re-render ExtJS components setelah tema berubah
   * Panggil ini di modul ExtJS untuk update grid/form
   */
  function refreshExtJS() {
    if (typeof Ext === 'undefined') return;
    
    // Refresh semua grid yang visible
    Ext.ComponentMgr.each(function(cmp) {
      if (cmp.isGridPanel && cmp.rendered) {
        try {
          cmp.getView().refresh();
        } catch (e) {
          // Ignore refresh errors
        }
      }
    });
  }

  /**
   * Helper: Dapatkan warna dari CSS variable
   */
  function getCSSVar(name) {
    return getComputedStyle(document.documentElement)
      .getPropertyValue(name)
      .trim();
  }

  // ===== PUBLIC API =====
  
  global.AppTheme = {
    // Core
    get: function() { return currentTheme || getStoredTheme(); },
    set: setTheme,
    toggle: toggleTheme,
    init: init,
    
    // Events
    onChange: onThemeChange,
    
    // ExtJS helpers
    refreshExtJS: refreshExtJS,
    getCSSVar: getCSSVar,
    
    // Constants
    STORAGE_KEY: STORAGE_KEY,
    EVENT_NAME: EVENT_NAME
  };

  // ===== AUTO-INIT =====
  // Jalankan segera agar tema diterapkan sebelum render
  // Gunakan DOMContentLoaded jika DOM belum siap
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})(typeof window !== 'undefined' ? window : this);