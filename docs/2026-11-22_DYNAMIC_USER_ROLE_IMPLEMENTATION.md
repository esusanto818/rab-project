# DOCUMENTATION: Dynamic User Role & Access Matrix Implementation
Date: 2026-11-22
Author: System Agent
Module: Master User Role & Dynamic Access Matrix

## 1. Overview
The **Dynamic User Role & Access Matrix** implementation replaces rigid or hardcoded role logic with a fully dynamic database-driven role and menu access system. It integrates directly with the existing `Employee -> User System -> m_user_access_menu` architecture.

## 2. Database Schema & Migration (`sql/2026-11-22_dynamic_user_role.sql`)
- **`public.m_role`**: Stores role metadata (`id`, `role_code`, `role_name`, `description`, `is_active`, `created_at`, `updated_at`).
- **`public.m_role_access_menu`**: Stores the default access matrix per role (`id`, `role_id`, `form_id`, `access_level`).
- **`public.m_user_system`**: Added column `role_id INT NULL REFERENCES public.m_role(id) ON DELETE SET NULL`.
- **Default Roles Seeded**: `ADMINISTRATOR`, `FINANCE`, `PURCHASING`, `PROJECT`, `WAREHOUSE`, `VIEWER`.
- **Form Registration**: Registered **Master User Role** (`views/m.user.role.php`, `js/m.user.role.js`) under utility group (`9905`) with full administrator access.

## 3. Backend Endpoints
- **`php-crud/roleCRD.php`**: Handles all role operations using PostgreSQL transactions (`BEGIN/COMMIT/ROLLBACK`) and `pg_query_params` / prepared statements.
  - `read_roles`: Lists all roles with aggregated user count and form access count.
  - `save_role` / `update_role`: Creates and updates role records.
  - `delete_role`: Deletes roles with safety checks preventing deletion if active users are assigned.
  - `clone_role`: Duplicates a role and its entire permission matrix.
  - `read_role_matrix`: Loads all active system forms grouped by menu group with access levels for a given role.
  - `save_role_matrix`: Batch saves form permission levels for a role.
  - `sync_role_to_users`: Synchronizes/copies the role's access matrix into `m_user_access_menu` for all active users assigned to that role.

## 4. Frontend ExtJS 3.4.1 UI
- **`views/m.user.role.php` & `js/m.user.role.js`**: Provides the UI with:
  - Top grid listing roles, active status, user counts, and form counts.
  - Action buttons: Add Role, Edit Role, Clone Role, Delete, and Manage/View Access Matrix.
  - Master-Detail Access Matrix window with Search, Select All, Clear All, Save Matrix, and **Sync to Users** (1-click propagation).

## 5. Verification & Testing
- Checked with `php -l`: **0 syntax errors**.
- Migration successfully applied to PostgreSQL database `proyekGMN`.
