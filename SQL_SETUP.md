# SQL Setup Guide

This document explains the recommended MySQL schema for dashboard storage and user authentication with fine-grained permissions.

## Goals

- Use relational tables for application storage.
- Support login sessions and user management.
- Support per-user and per-role permissions per resource.
- Preserve audit and activity history.

## Database Engine and Charset

Recommended defaults:

- Engine: InnoDB
- Charset: utf8mb4
- Collation: utf8mb4_unicode_ci

## Table Overview

- users: User accounts and identity data.
- roles: Named groups of access behavior (admin/editor/viewer).
- user_roles: Many-to-many mapping between users and roles.
- resources: Protected app areas (records, audit_log, users, etc.).
- permissions: Allowed actions (read, create, update, delete, etc.).
- role_permissions: Default permissions granted by role.
- user_permissions: Per-user allow/deny overrides.
- records: Main records table for application data.
- audit_log: Immutable change tracking table.
- activity_log: Readable operational events table.
- notifications: Per-user notification inbox with read state.
- user_sessions: Login session tracking.
- password_reset_tokens: Password reset flow support.

---

## 1) users

Purpose:

Stores account identity, login fields, password hash, and account status.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Internal unique user ID.
- email (VARCHAR(255), NOT NULL, UNIQUE): Primary login identifier.
- username (VARCHAR(100), NULL, UNIQUE): Optional secondary login/display handle.
- password_hash (VARCHAR(255), NOT NULL): Hash of user password. Never store plaintext.
- display_name (VARCHAR(150), NULL): Name shown in UI and logs.
- status (ENUM('active','disabled','pending'), NOT NULL, default 'active'): Account state.
- last_login_at (DATETIME, NULL): Timestamp of latest successful login.
- created_at (DATETIME, NOT NULL): Row creation time.
- updated_at (DATETIME, NOT NULL): Row last update time.
- deleted_at (DATETIME, NULL): Soft-delete marker.

---

## 2) roles

Purpose:

Defines reusable permission bundles.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Role ID.
- name (VARCHAR(50), NOT NULL, UNIQUE): Role key, e.g., admin, editor, viewer.
- description (VARCHAR(255), NULL): Human-readable purpose.
- created_at (DATETIME, NOT NULL): Row creation time.

---

## 3) user_roles

Purpose:

Assigns one or more roles to a user.

Columns:

- user_id (BIGINT UNSIGNED, NOT NULL, FK -> users.id): User receiving role.
- role_id (BIGINT UNSIGNED, NOT NULL, FK -> roles.id): Role assigned.
- assigned_by_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Admin who granted role.
- created_at (DATETIME, NOT NULL): Assignment timestamp.

Key:

- Primary key (user_id, role_id) to prevent duplicate role assignments.

---

## 4) resources

Purpose:

Represents protected app targets. Use app-level resource keys instead of raw DB table names.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Resource ID.
- resource_key (VARCHAR(100), NOT NULL, UNIQUE): Stable key, e.g., records, audit_log, users.
- display_name (VARCHAR(150), NOT NULL): Label for admin UI.
- description (VARCHAR(255), NULL): Resource meaning.
- created_at (DATETIME, NOT NULL): Row creation time.

---

## 5) permissions

Purpose:

Defines available actions that can be granted.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Permission ID.
- permission_key (VARCHAR(50), NOT NULL, UNIQUE): Action key, e.g., read, create, update, delete.
- display_name (VARCHAR(100), NOT NULL): Human-readable label.
- description (VARCHAR(255), NULL): What this action allows.
- created_at (DATETIME, NOT NULL): Row creation time.

---

## 6) role_permissions

Purpose:

Connects role + resource + permission to create default access policies.

Columns:

- role_id (BIGINT UNSIGNED, NOT NULL, FK -> roles.id): Role target.
- resource_id (BIGINT UNSIGNED, NOT NULL, FK -> resources.id): Protected resource.
- permission_id (BIGINT UNSIGNED, NOT NULL, FK -> permissions.id): Granted action.
- created_at (DATETIME, NOT NULL): Grant timestamp.

Key:

- Primary key (role_id, resource_id, permission_id).

---

## 7) user_permissions

Purpose:

User-specific override layer for access control.

Columns:

- user_id (BIGINT UNSIGNED, NOT NULL, FK -> users.id): User target.
- resource_id (BIGINT UNSIGNED, NOT NULL, FK -> resources.id): Resource target.
- permission_id (BIGINT UNSIGNED, NOT NULL, FK -> permissions.id): Action target.
- is_allowed (TINYINT(1), NOT NULL): 1 allow, 0 deny.
- granted_by_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Admin who set override.
- created_at (DATETIME, NOT NULL): Override creation time.
- updated_at (DATETIME, NOT NULL): Last update time.

Key:

- Primary key (user_id, resource_id, permission_id).

Note:

- This table enables per-user exceptions such as read-only across many resources, mixed read/write, and explicit deny on one resource.

---

## 8) records

Purpose:

Main application data table for dashboard records.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Record ID.
- title (VARCHAR(255), NOT NULL): Record title.
- description (TEXT, NOT NULL): Record body/description.
- created_by_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Creator.
- updated_by_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Last editor.
- created_at (DATETIME, NOT NULL): Create time.
- updated_at (DATETIME, NOT NULL): Last update time.
- deleted_at (DATETIME, NULL): Soft-delete marker.

---

## 9) audit_log

Purpose:

Detailed immutable history for security and compliance.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Audit row ID.
- record_type (VARCHAR(50), NOT NULL): Entity kind, e.g., record, user, permission.
- record_id (BIGINT UNSIGNED, NULL): Entity ID when available.
- action (VARCHAR(50), NOT NULL): Event type (examples: create, update, delete, bulk_delete, login, logout, password_change, notification_sent, notification_read, notification_deleted, reset).
- details (TEXT, NULL): Event details payload. For updates, stores `old_value -> new_value` text.
- actor_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Who performed action.
- target_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Optional target user for user-to-user events.
- ip_address (VARCHAR(45), NULL): Source IP (IPv4/IPv6).
- user_agent (VARCHAR(255), NULL): Client user agent string.
- created_at (DATETIME, NOT NULL): Event timestamp.

Notes:

- If `actor_user_id` is NULL, the UI displays actor as `System`.

---

## 10) activity_log

Purpose:

Operational timeline for user-visible log entries and system events.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Activity row ID.
- event_type (VARCHAR(100), NOT NULL): Machine key, e.g., record_created.
- message (TEXT, NOT NULL): Human-readable event details.
- related_record_type (VARCHAR(50), NULL): Entity kind related to event.
- related_record_id (BIGINT UNSIGNED, NULL): Entity ID related to event.
- actor_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Responsible user.
- ip_address (VARCHAR(45), NULL): Source IP.
- created_at (DATETIME, NOT NULL): Event timestamp.

---

## 11) user_sessions

Purpose:

Tracks active and historical login sessions.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Session row ID.
- user_id (BIGINT UNSIGNED, NOT NULL, FK -> users.id): Session owner.
- session_token_hash (VARCHAR(255), NOT NULL, UNIQUE): Hash of session token. Never store raw token.
- expires_at (DATETIME, NOT NULL): Session expiration.
- last_seen_at (DATETIME, NULL): Most recent request timestamp.
- ip_address (VARCHAR(45), NULL): Session source IP.
- user_agent (VARCHAR(255), NULL): Session client fingerprint.
- created_at (DATETIME, NOT NULL): Session creation time.
- revoked_at (DATETIME, NULL): Manual invalidation time.

---

## 12) password_reset_tokens

Purpose:

Supports password reset flow with one-time token lifecycle.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Reset row ID.
- user_id (BIGINT UNSIGNED, NOT NULL, FK -> users.id): User requesting reset.
- token_hash (VARCHAR(255), NOT NULL, UNIQUE): Hash of reset token.
- expires_at (DATETIME, NOT NULL): Token expiry time.
- used_at (DATETIME, NULL): When token was consumed.
- created_at (DATETIME, NOT NULL): Token creation time.

---

## 13) notifications

Purpose:

Stores user-scoped in-app notifications for the header bell and dropdown.

Columns:

- id (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Notification row ID.
- user_id (BIGINT UNSIGNED, NOT NULL, FK -> users.id): Notification owner.
- sent_by_user_id (BIGINT UNSIGNED, NULL, FK -> users.id): Optional sender when pushed by another user; null for system-generated notifications.
- title (VARCHAR(160), NOT NULL): Short notification headline.
- message (TEXT, NOT NULL): Notification body text.
- notification_type (VARCHAR(50), NOT NULL, default 'info'): Classification such as info/success/warning/error.
- is_read (TINYINT(1), NOT NULL, default 0): Read marker (0 unread, 1 read).
- read_at (DATETIME, NULL): Timestamp when user marked it read.
- created_at (DATETIME, NOT NULL): Notification creation time.

Recommended indexes:

- INDEX idx_notifications_user_created (user_id, created_at)
- INDEX idx_notifications_user_read (user_id, is_read)
- INDEX idx_notifications_sent_by (sent_by_user_id)

---

## Recommended Permission Keys

- read: View data.
- create: Add new data.
- update: Edit existing data.
- delete: Remove data.
- manage_users: Create/disable users.
- manage_permissions: Grant/revoke roles and permission overrides.
- export: Download/export data.

## Recommended Resource Keys

- records
- activity_log
- audit_log
- notifications
- users
- roles
- permissions

## Access Evaluation Order (Important)

Use this order in your auth middleware:

1. Start with deny by default.
2. Apply role-based grants from role_permissions.
3. Apply per-user overrides from user_permissions.
4. If an explicit deny exists (is_allowed = 0), deny even if role allows.

This pattern supports scenarios like mixed access across resources and table-specific restrictions per user.
