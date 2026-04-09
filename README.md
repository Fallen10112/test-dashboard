# Dashboard Showcase

A web dashboard built with PHP, MySQL, jQuery, HTML, and CSS.

The app includes:

- Session-based authentication (single-device policy)
- CRUD data management with server-side paging/filter/sort
- Audit trail (table + timeline)
- Reports and export (PDF/CSV)
- In-app notifications
- Dev tools for resets and user operations
- Per-user header widget preferences

## Project Structure

```
test-dashboard/
├── api.php                             # JSON API router for GET/POST actions (data, audit, logs, notifications, admin/dev tools)
├── index.php		                    # App entry point; enforces login and runs demo reset-on-entry flow when enabled
├── LICENSE		                        # Project license terms
├── README.md	                	    # Project documentation and setup guide
├── SQL_SETUP.md                	    # Database schema/setup reference for required tables
├── config/		                        # Runtime configuration files
│   ├── .env	                	    # Local environment values (DB connection credentials)
│   ├── .env.example	                # Template for .env keys
│   └── config.php	                    # Loads env values + app settings from DB (mode, reset behavior, timezone)
├── css/		                        # Stylesheets — 6-layer semantic architecture
│   ├── style.css	                    # Manifest entry point; Section Map + layer @imports only (no rules)
│   ├── base/		                    # 1) Base layer: resets and foundational styles
│   │   ├── index.css
│   │   └── foundation.css              # CSS reset, box-sizing, body declaration, --header-height variable
│   ├── layout/		                    # 2) Layout layer: structural shell
│   │   ├── index.css
│   │   └── app-shell.css               # title-bar, sidebar, nav-menu, main-content, content-section
│   ├── components/	                    # 3) Components layer: reusable UI patterns
│   │   ├── index.css
│   │   ├── prelude.css                 # Select dropdowns, audit controls, scrollbars, scroll-to-top
│   │   ├── core-ui.css                 # Tables, buttons, modals, toasts, forms, reports, badges, keyframes
│   │   └── header-user-menu.css        # Notifications bell + dropdown, user avatar + dropdown
│   ├── pages/		                    # 4) Pages layer: page-scoped styles
│   │   ├── index.css
│   │   ├── login.css                   # Login page styles
│   │   └── account-admin.css           # Account settings and admin/dev-tools page styles
│   ├── themes/		                    # 5) Themes layer: centralized dark-mode overrides (body.dark-mode)
│   │   ├── index.css
│   │   ├── prelude-dark.css            # Dark overrides for select dropdowns, audit toggles, scroll-to-top
│   │   ├── core-ui-dark.css            # Dark overrides for core UI components (tables, modals, forms, etc.)
│   │   ├── header-user-menu-dark.css   # Dark overrides for notifications and user dropdown
│   │   └── account-admin-dark.css      # Dark overrides for account/admin/dev-tools page elements
│   └── responsive/	                    # 6) Responsive layer: all @media breakpoint rules
│       ├── index.css
│       └── core.css                    # max-width 900px and 768px breakpoints for layout and components
├── includes/		                    # Shared PHP includes used by multiple pages
│   ├── auth.php		                # Session auth helpers (login/logout/requireAuth/CSRF/session invalidation)
│   ├── footer.php                      # Shared script includes and page-aware JS module loading
│   ├── header.php	                    # Shared page header/top bar and authenticated shell bootstrap
│   ├── navigation.php	                # Sidebar navigation and theme toggle shell
│   └── sql_helpers.php	                # PDO helpers, schema checks, resets, and SQL utility functions
├── js/			                        # Frontend JavaScript modules
│   ├── core/		                    # Cross-page shared logic
│   │   └── shared.js	                # Toasts, auth/session guards, header metrics, notifications, common helpers
│   ├── features/	                    # Feature/page-specific behavior modules
│   │   ├── audit-page.js		        # Audit page rendering, filtering, timeline/table views, realtime polling
│   │   ├── data-page.js		        # Data page CRUD UI, pagination/sort/search, bulk actions, exports, realtime polling
│   │   ├── reports-page.js	            # Reports generation and PDF/CSV download flows
│   │   └── ui-customization-page.js	# Per-user header widget visibility settings UI and save flow
│   └── pages/
│       └── app-init.js	                # Main client bootstrap; initializes only handlers needed for current page
├── pages/		                        # Server-rendered page views
│   ├── audit.php		                # Audit trail page container (filters + audit results area)
│   ├── data.php		                # Data management page container (table, actions, modals)
│   ├── dev-tools.php	                # Developer/admin tools page for resets, notifications, and user operations
│   ├── home.php		                # Landing/home page content for signed-in users
│   ├── login.php		                # Login page and logout POST handler endpoint
│   ├── reports.php	                    # Reports page container and export controls
│   ├── ui-customization.php	        # UI customization page for per-user header widget preferences
│   └── user.php		                # Account settings page
```

## Tech Stack

- Backend: PHP 7+ with PDO (MySQL)
- Frontend: jQuery + vanilla JS modules
- Database: MySQL/MariaDB
- Exports: html2pdf.js for client-side PDF generation

## Authentication and Security

- Login is required for app pages and API usage.
- Auth uses session cookie + server-side session records (`user_sessions`).
- Single-device sign-in policy: new login revokes previous active sessions for that account.
- API auth is not API-key based.
- SQL queries use prepared statements.
- Password updates are available in Account Settings (`pages/user.php`) with CSRF protection.

## Configuration

`config/.env` is used for database connection values:

- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_CHARSET`

Application behavior settings are loaded from the `app_settings` table (not from `.env`):

- `app_mode` (`demo` or `production`)
- `reset_on_index_visit` (`true`/`false`)
- `app_timezone`

`index.php` redirects unauthenticated users to login, and in demo mode can reset data on entry when `reset_on_index_visit` is enabled.

## Key Features

### Data Page

- Server-side search, sort, pagination (`10/20/25/50/100`)
- Virtualized table rendering for the current page
- Add/Edit/Delete + bulk delete
- Add New Record includes a "Save & add another record" action for rapid entry
- Filtered export endpoint support for CSV/PDF flows
- Realtime refresh every 10 seconds
- Footer status text: `Last refreshed HH:MM:SS`

### Audit Trail Page

- Table and timeline modes
- Search + type/action filters
- Diff-friendly details rendering for update events
- Realtime refresh every 10 seconds
- Active filters persist across realtime refreshes
- Footer status text: `Last refreshed HH:MM:SS`

### Reports Page

- Dataset selector (Data or Logs)
- On-screen report generation
- PDF and CSV download actions

### Notifications

- Header bell dropdown with unread badge
- Mark read, mark all read, delete, delete all
- Realtime sync polling
- Toast display for incoming notifications

### Dev Tools

- System maintenance actions (logs/audit/records/widget prefs/notifications/reset-all)
- User management actions (lookup/create/update/force delete/reset widget prefs)

### UI and Personalization

- Dark mode toggle with persistence
- Header metric widgets with per-user visibility preferences
- UI customization page for widget visibility

## Realtime Polling Intervals

- Data page poll: 10 seconds
- Audit page poll: 10 seconds
- Notifications poll: 3 seconds
- Session enforcement poll: 2 seconds

## API Overview

All responses are JSON. Most app actions are served through `api.php`.

GET actions:

- `action=session_status`
- `action=audit_trail`
- `action=logs`
- `action=notifications`
- `action=widget_preferences`
- `action=data_page`
- `action=data_filtered_export`
- `action=data` (or empty action)

POST actions (`Content-Type: application/json`):

- Data: `data_create`, `data_update`, `data_delete`, `data_bulk_delete`
- Audit write helpers: `add_audit_entry`, `add_audit_entries`
- Notifications: `notification_create`, `notification_mark_read`, `notifications_mark_all_read`, `notification_delete`, `notifications_delete_all`
- Widget preferences: `widget_preferences_update`
- Dev tools/system: `reset_activity_log`, `reset_audit_log`, `reset_records`, `reset_widget_prefs`, `reset_notifications_table`, `reset_all`, `reset_data`, `logout_all_users`
- Dev tools/users: `admin_user_lookup`, `admin_user_create`, `admin_user_update`, `admin_user_force_delete`, `admin_user_reset_widget_prefs`

## Setup

1. Place the project in your web root (for example XAMPP `htdocs`).
2. Configure DB credentials in `config/.env`.
3. Ensure required SQL schema/tables exist (see `SQL_SETUP.md`).
4. Start Apache + MySQL.
5. Open the app and sign in at `pages/login.php`.

## Notes

- This repo is session-authenticated; references to API-key auth are outdated.
- App mode/reset/timezone are database settings (`app_settings`).
- If testing from a phone on LAN, use your machine IP (not `localhost`).
