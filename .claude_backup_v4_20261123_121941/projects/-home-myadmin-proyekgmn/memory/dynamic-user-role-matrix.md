---
name: dynamic-user-role-matrix
description: Implementation and verification details of Dynamic User Role and Access Matrix architecture
metadata:
  type: project
---

# Dynamic User Role & Access Matrix Integration

- **Backend CRUD (`php-crud/roleCRD.php`):** Manages dynamic roles (`m_role`), form access matrix (`m_role_access_menu`), cloning, saving, and syncing to users.
- **Frontend UI (`js/m.user.role.js` & `views/m.user.role.php`):** ExtJS 3.4.1 grid management with checkboxes, active status, Level combo, Select All, Clear All, and direct bulk user sync.
- **User System Integration (`php-crud/m.user.systemCRD.php` & `js/m.user.system.js`):** Added `role_id` column to `m_user_system`, Role ComboBox & "Apply Role" button in User Editor, making form access management streamlined and centralized via roles.

**Why:** Centralizes and standardizes user authorization across modules without per-user manual permission configuration.
**How to apply:** Use `roleCRD.php` for role matrix administration and `m.user.systemCRD.php` for role assignment during user creation/editing.
