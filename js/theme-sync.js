// Theme Sync Module for RAB-Project
// Handles theme state persistence, cross-tab synchronization, and theme application

// Constants
// Constants
const THEME_KEY = 'app-theme';
const DEFAULT_THEME = 'default-california-dream';
const THEMES = [
  'default-california-dream',
  'dark-warm-obsidian',
  'light-soft-sage'
];

// DOM Elements
const themeSwitcher = document.getElementById('theme-switcher');
const themeIcon = document.getElementById('theme-icon');
const themeLbl = document.getElementById('theme_lbl');

// Initialize theme
function initTheme() {
  let currentTheme = localStorage.getItem(THEME_KEY) || DEFAULT_THEME;
  if (!THEMES.includes(currentTheme)) {
    currentTheme = DEFAULT_THEME;
  }
  applyTheme(currentTheme);
  updateUI(currentTheme);
  setupThemeSync();
}

// Apply theme to document
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
}

// Update UI elements (icon, label)
function updateUI(theme) {
  if (!themeIcon || !themeLbl) return;

  switch (theme) {
    case 'dark-warm-obsidian':
      themeIcon.textContent = '🌙';
      themeLbl.textContent = 'Dark';
      break;
    case 'light-soft-sage':
      themeIcon.textContent = '☀️';
      themeLbl.textContent = 'Light';
      break;
    default:
      themeIcon.textContent = '🌙';
      themeLbl.textContent = 'Dark';
  }
}

// Cycle through themes
function cycleTheme() {
  const currentTheme = document.documentElement.getAttribute('data-theme') || DEFAULT_THEME;
  const index = THEMES.indexOf(currentTheme);
  const nextTheme = THEMES[(index + 1) % THEMES.length];
  applyTheme(nextTheme);
  localStorage.setItem(THEME_KEY, nextTheme);
  updateUI(nextTheme);
  broadcastThemeChange(nextTheme);
}

// Toggle between light/dark themes
function toggle() {
  const currentTheme = document.documentElement.getAttribute('data-theme') || DEFAULT_THEME;
  const nextTheme = currentTheme === 'dark-warm-obsidian' ? 'light-soft-sage' : 'dark-warm-obsidian';
  applyTheme(nextTheme);
  localStorage.setItem(THEME_KEY, nextTheme);
  updateUI(nextTheme);
  broadcastThemeChange(nextTheme);
}

// Cross-tab communication
function setupThemeSync() {
  window.addEventListener('storage', function(e) {
    if (e.key === THEME_KEY) {
      applyTheme(e.newValue);
      updateUI(e.newValue);
    }
  });
  broadcastThemeChange(localStorage.getItem(THEME_KEY) || DEFAULT_THEME);
}

// Broadcast theme change to other tabs
function broadcastThemeChange(theme) {
  localStorage.setItem(THEME_KEY, theme);
}

// Initialize when DOM is loaded
function init() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initTheme();
    });
  } else {
    initTheme();
  }
}

// Expose to global window object
window.RabThemeSync = {
  getTheme: () => document.documentElement.getAttribute('data-theme') || DEFAULT_THEME,
  setTheme: (theme) => {
    if (THEMES.includes(theme)) {
      applyTheme(theme);
      localStorage.setItem(THEME_KEY, theme);
      updateUI(theme);
      broadcastThemeChange(theme);
    }
  },
  cycleTheme: cycleTheme,
  toggle: toggle,
  updateUI: updateUI,
  init: init
};

// Initialize on load
window.RabThemeSync.init();