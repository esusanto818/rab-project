---
name: theme-styler-editor
description: Audit, consolidate, and edit theme switchers and cross-module styles into a single source of truth for multi-view web applications, including ExtJS 3.4.1 interfaces. Use when the user asks to audit theme switching, unify or consolidate theme CSS and switcher state into a single file or source, fix modules that do not follow theme changes, or apply scoped theme patches across views, routers, and stylesheets.
---

# theme-styler-editor

Audit theme switcher behavior across views and stylesheets, consolidate theme state and styling rules into a single source of truth, and apply scoped edits across modules without breaking existing layouts or components.

## When to Use

* A theme switcher (such as light/dark toggle) works on one page or dashboard but does not update other views, routes, grids, or detail panels.
* Theme colors, variables, or overrides are scattered across multiple CSS files, inline style blocks, or component scripts.
* You need to consolidate theme state and styling rules into one shared source and apply scoped patches across modules.
* You are working on multi-view PHP/HTML/JS applications that include ExtJS 3.4.1 panels, grids, or windows.

## Steps

1. **Audit Theme State and Stylesheets**
   * Scan views, routers, layout templates, and stylesheets to locate every theme toggle control, storage key, CSS class or attribute, and style override.
   * Identify why switching themes does not propagate across modules (missing shared state, late or missing initialization on sub-routes, conflicting selector specificity, or hard-coded page colors).
   * Map component-level overrides (such as ExtJS panels, grids, headers, and modals) so edits stay scoped to intended containers.

2. **Consolidate to a Single Source of Truth**
   * Centralize theme state management (light or dark mode, persistence, cross-tab synchronization, and root attribute/class assignment) in one shared script.
   * Consolidate theme tokens and shared theme rules into one primary stylesheet instead of maintaining conflicting copies across individual pages.
   * Ensure root theme attributes or classes are applied early during page load to prevent flash of unstyled or mismatched themes.

3. **Apply Scoped Edits Across Modules**
   * Wire shared theme initialization and stylesheets into entry views, routers, and module templates with cache-busting query parameters when updating assets.
   * Keep component and layout rules scoped carefully so theme changes apply across modules without breaking grid structure, permissions, or business logic.

4. **Verify Cross-Module Behavior**
   * Check that toggling the theme updates the active view and persists when navigating to other routes or reloading with a hard refresh.
   * Confirm there are no leftover duplicate overrides or unverified files before finishing.

## Gotchas

* **Order of CSS loading:** Generic resets or theme overrides loaded in the wrong order relative to framework or component stylesheets can fail to apply or accidentally restyle unrelated elements.
* **Inline style overrides:** Page-level style tags or inline colors with high specificity can keep a sub-view stuck in one mode unless addressed cleanly.
* **Cross-module scope leak:** Broad unscoped rules can alter unintended panels or windows; scope theme selectors cleanly to root and module containers.
