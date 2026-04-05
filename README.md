# Dashboard Showcase

A comprehensive web-based dashboard built with PHP, jQuery, HTML, CSS, and modern web technologies. Features secure data management with AES-256-CBC encryption, complete audit trail tracking, activity logging, and report generation capabilities.

## Project Structure

```
test-dashboard/
├── index.php              # Entry point (resets JSON files, then redirects to home.php)
├── pages/
│   ├── home.php           # Home/Welcome page with project overview
│   ├── data.php           # Data management page (CRUD operations)
│   ├── reports.php        # Reports & analytics with PDF/CSV export
│   └── audit.php          # Audit trail history viewer
├── includes/
│   ├── header.php         # HTML head and fixed-height title bar
│   ├── navigation.php     # Fixed left sidebar with scroll-safe navigation and utility controls
│   └── footer.php         # Closing HTML tags and script includes
├── config/
│   ├── config.php         # Configuration, app mode, and security settings
│   └── .env.example       # Environment variables template
├── css/
│   └── style.css          # Main stylesheet with light & dark mode support
├── js/
│   ├── core/
│   │   └── shared.js      # Shared state/utilities, theme, reset flow, common data/log loaders
│   ├── features/
│   │   ├── data-page.js   # Data page server-backed CRUD, pagination, sorting, and filtered export
│   │   ├── reports-page.js# Reports generation and PDF/CSV downloads
│   │   └── audit-page.js  # Audit trail filters, table/timeline rendering, diff markup
│   └── pages/
│       └── app-init.js    # Page-aware bootstrap and shared UI initialization
├── data/
│   ├── data.json          # Main data storage (encrypted)
│   ├── logs.json          # Administrative activity logs (encrypted)
│   └── audit_trail.json   # Complete change history (encrypted)
├── api.php                # Backend API for all operations
└── README.md              # This file
```

## Technologies Used

- **Backend**: PHP 7.0+ with OpenSSL encryption (AES-256-CBC)
- **Frontend**: jQuery, HTML5, CSS3 with responsive design
- **Data Storage**: Encrypted JSON files
- **Security**: AES-256-CBC encryption for all sensitive data
- **Encryption Key Management**: Environment variable support via `.env` file, with a built-in fallback key if not set

## Features

### 🏠 Home Page
- Comprehensive project overview
- Key features explanation
- Getting started guide
- Technical stack information

### 📊 Data Management Page
- **View Data**: Displays records in a responsive, sortable table powered by server-side queries
- **Server-Side Pagination**: Previous/Next navigation and page summaries are driven by the backend instead of paging the full dataset in the browser
- **Rows-Per-Page Selector**: Change visible page size from bottom-right pagination controls (25/50/100)
- **Server-Side Filtering & Sorting**: Search and column sorting are sent to the API so the browser only renders the active page results
- **Virtualized Rendering**: Only visible rows on the current page are rendered for smoother performance
- **Preference Persistence**: Selected rows-per-page value is saved in localStorage and restored automatically
- **API-Backed CRUD**: Add, edit, delete, and bulk delete actions are executed through dedicated API operations
- **Filtered Export (PDF/CSV)**: Export uses a backend-filtered dataset so downloaded files match the active search/sort state
- **Action Order**: Data controls are arranged as Add, Delete, Export PDF, Export CSV
- **Search & Filter**: Real-time search by title and description via API request
- **Column Sorting**: Click column headers to sort (ascending/descending) via API request
- **Auto-Save**: All changes are saved immediately through backend mutation endpoints
- **Activity Logging**: Every operation automatically logged with timestamps
- **Audit Integration**: All changes tracked in the audit trail with field-level detail

### 📈 Reports Page
- **Dataset Selector**: Choose between "Data" or "Logs" dataset
- **Report Generation**: Creates formatted reports on-screen
- **PDF Export**: Download reports as PDF files with timestamps
- **CSV Export**: Download reports as CSV for spreadsheet applications
- **Data Report**: Displays all records with metadata
- **Logs Report**: Shows all activities with detailed descriptions

### 🔍 Audit Trail Page
- **Complete Change History**: View all data modifications
- **Dual View Modes**: Switch between classic Table view and Timeline view
- **Field-Level Tracking**: See exactly what changed in each field
- **Before & After Values**: Compare old and new values
- **Search Functionality**: Find specific changes quickly
- **Timestamps**: Exact date and time of each modification
- **Change Classification**: Clearly identifies ADD, EDIT, or DELETE operations
- **Record Linking**: Associates changes with their respective record IDs

### 📌 Header Analytics Widgets
- **Total Entries**: Live count of records in `data.json`
- **Total Edits**: Total number of EDIT events in audit history
- **Adds Today**: Number of records added today
- **Deletes Today**: Number of records deleted today
- **Always Visible**: Displayed in the top-right of the fixed header for quick status checks

### 🎨 Dark Mode Theme
- **Theme Toggle**: Toggle switch in the bottom-left area of navigation
- **Small-Window Safe Layout**: Navigation links scroll independently so bottom controls never overlap page links
- **Visual Separation**: Subtle divider line separates navigation links from utility controls
- **Persistent Settings**: Theme preference saved via localStorage
- **Light Mode**: Original clean white interface
- **Dark Mode**: Professional dark color palette for reduced eye strain
- **Full Coverage**: Dark mode applies to all pages and components

### ⚙️ Environment Configuration
- **API_KEY**: Shared secret required by `api.php` for GET and POST requests
  - Send using `X-API-Key` header (preferred) or `Authorization: Bearer <key>`
  - Default local value is for development only; change it before deployment
- **APP_MODE**: Set to `demo` (default) or `production`
  - Demo mode: Auto-resets all data when `index.php` is loaded (fresh start for testing)
  - Production mode: Preserves all data across application restarts
- **RESET_ON_INDEX_VISIT**: Override auto-reset behavior (true/false)
  - Can be set to `true` in production mode if manual resets are needed
  - Can be set to `false` in demo mode if data persistence is desired for testing
- **STORAGE_DRIVER**: Set to `json` (current default) or reserve `sql` for the upcoming database-backed implementation
- **DB_CONNECTION**: Planned SQL driver, currently defaults to `mysql`
- **DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_CHARSET**: SQL connection settings staged for future database support
- **Configuration Location**: Edit `.env` file in the `config/` directory or use system environment variables
- **Default Behavior**: Demo mode resets on index visit; production mode does not

### 🔄 Reset Data Functionality
- **Reset Button**: Located in navigation sidebar, below the theme toggle
- **Bottom Utility Area**: Anchored with the theme toggle in a dedicated sidebar footer section
- **Complete Reset**: Clears all entries, logs, and audit trail
- **Fresh Start**: Restores 3 sample test entries for demonstration
- **Toast Confirmation Prompt**: Uses custom top-center prompt with action buttons (Cancel/Reset)
- **Safe Operation**: Immediately reloads page with fresh data

### 🔔 Custom Toast Notification System
- **Top-Center Toasts**: Small notifications shown at the top-center of the page
- **Consistent Styling**: Matches the dashboard visual design in both light and dark mode
- **Interactive Option**: Toasts can include action buttons (for example, confirmation prompts)
- **Dismiss Option**: Success/error toasts support an `Okay` button or auto-fade after 3 seconds
- **Implemented Flows**:
  - Reset Data confirmation prompt now uses custom toast actions instead of browser confirm
  - Data Add success now shows a confirmation toast
  - Data Edit success now shows a confirmation toast
  - Bulk Delete confirmation prompt now uses custom toast actions

### 🧹 Bulk Actions (Data Page Only)
- **Multi-Select Support**: Use row checkboxes and Select All in the Data table
- **Single Bulk Action**: Bulk delete selected records from the Data page
- **Scope**: Applies to Data records and data JSON updates only
- **Confirmation Required**: Bulk delete always asks for confirmation via custom toast prompt
- **Audit Logging**: Each deleted record still generates DELETE audit trail entries, plus one dedicated bulk-action summary entry

### ♻️ Automatic Reset on Entry
- **Configurable Reset**: Reset-on-index behavior is controlled by environment mode/settings
- **Demo Mode Default**: `APP_MODE=demo` enables reset on index by default
- **Production Mode Default**: `APP_MODE=production` disables reset on index by default
- **Override Available**: `RESET_ON_INDEX_VISIT=true|false` can explicitly control behavior
- **Clean Test Environment**: Demo mode provides predictable showcase startup

### 📝 Administrative Logging System
- **Automatic Tracking**: Logs all user actions with timestamps
- **Event Types Tracked**:
  - New entry added
  - Entry edited (with field-level changes)
  - Entry deleted
  - Filtered Data export downloaded (PDF/CSV)
  - Report generated
  - Report downloaded (PDF or CSV)
- **Encryption**: All logs encrypted at rest for security
- **Timezone Handling**: Uses a fixed one-hour offset when generating log timestamps
- **Searchable**: Logs are available through the Reports page dataset selector

### 🔒 API Reliability (Lock + Retry)
- **Write Locking**: API file writes use exclusive file locks to reduce concurrent write collisions
- **Retry Logic**: Failed lock/write attempts are retried automatically with short delay
- **Coverage**: Data, logs, audit trail, reset operations, and initialization writes use lock+retry path

### 🎯 Layout & Design
- **Fixed Title Bar**: Consistent fixed height (72px) with a gradient background
- **Fixed Left Navigation**: 250px sidebar with active page highlighting
- **Scroll-Safe Sidebar**: Menu area scrolls while bottom utility controls remain visible and non-overlapping
- **Responsive Main Content**: Scrollable content area that adapts to the active theme
- **Mobile-Optimized Layout**: At smaller breakpoints, the fixed desktop shell converts to a stacked flow with sticky header, full-width navigation, and touch-friendly spacing
- **Mobile-Friendly Tables**: Data and report tables use horizontal overflow on small screens to preserve readability
- **Professional Styling**: Modern UI with smooth transitions and hover effects
- **Consistent Design**: Unified look across all pages

### 🧩 Modular Frontend Architecture
- **Shared Core Module**: Common state, utilities, theme, reset flow, and shared loaders in `js/core/shared.js`
- **Feature Modules**: Page-focused logic split into `js/features/data-page.js`, `js/features/reports-page.js`, and `js/features/audit-page.js`
- **Page-Aware Bootstrap**: `js/pages/app-init.js` initializes only the handlers needed for the current page
- **Conditional Script Loading**: `includes/footer.php` loads only relevant feature scripts for each page

## Getting Started

### 1. Installation
- Place the folder in your XAMPP `htdocs/` directory
- Ensure the `data/` directory has write permissions (755 or 777)

### 2. First Use
- Home page explains all features
- Start by adding records on the Data page
- View changes in the Audit Trail
- Generate reports on the Reports page
- Toggle dark mode using the switch in the sidebar

### 3. File Descriptions

#### Main Pages
- **index.php**: Entry point that resets `data.json`, `logs.json`, and `audit_trail.json`, then redirects to `home.php`
- **pages/home.php**: Comprehensive welcome with feature overview
- **pages/data.php**: Data management interface with CRUD operations and bulk delete selection
- **pages/reports.php**: Report generation with multiple export options
- **pages/audit.php**: Audit trail viewer with search, plus table/timeline display toggle

#### Include Files (Reusable Components)
- **includes/header.php**: HTML head tags and fixed-height title bar
- **includes/navigation.php**: Fixed left sidebar with independent menu scrolling and bottom utility controls
- **includes/footer.php**: Closing HTML tags and page-aware script includes, including PDF library loading for Reports and Data pages

#### Backend
- **api.php**: Handles all backend operations:
  - Get/save data from/to encrypted JSON files
  - Serve paginated, filtered, and sorted Data page responses
  - Process dedicated Data page create, update, delete, and bulk delete requests
  - Manage logs and audit trails
  - Track all data changes with field-level detail
  - Encrypt/decrypt using AES-256-CBC
  - Apply file locking and retry logic to write operations
  - Generate timestamps using a fixed one-hour offset

#### API Endpoints

All responses are JSON.

Authentication (required):
- Header (recommended): `X-API-Key: <your_api_key>`
- Bearer alternative: `Authorization: Bearer <your_api_key>`
- Query fallback/testing: `?api_key=<your_api_key>`

##### GET Endpoints

| Endpoint | Purpose | Key Params |
| --- | --- | --- |
| `GET api.php?action=data_page` | Paged, filtered, sorted data for Data page | `search`, `sortColumn(id/title/description)`, `sortOrder(asc/desc)`, `page`, `pageSize(25/50/100)` |
| `GET api.php?action=data_filtered_export` | Full filtered/sorted data (no pagination) | `search`, `sortColumn`, `sortOrder` |
| `GET api.php?action=audit_trail` | Full audit history | none |
| `GET api.php?action=logs` | Full activity logs | none |
| `GET api.php` | Full raw data payload | none |

Auth usage pattern for every GET endpoint:
- Header auth: append required endpoint params and send `X-API-Key` header
- Query auth: append `&api_key=YOUR_API_KEY` (or `?api_key=` if no query exists)

##### POST Endpoints

All POST endpoints require `Content-Type: application/json` and JSON body containing `action`.

| Action (`POST api.php`) | Purpose | Required Body Fields |
| --- | --- | --- |
| `data_create` | Create one record | `title`, `description` |
| `data_update` | Update one record | `id`, `title`, `description` |
| `data_delete` | Delete one record | `id` |
| `data_bulk_delete` | Delete many records | `ids` (array) |
| `add_audit_entry` | Write one audit entry | `changeType`, `recordId`, `fieldName`, `oldValue`, `newValue` |
| `add_audit_entries` | Write multiple audit entries | `entries` (array of audit entry objects) |
| `reset_data` | Reset data/logs/audit to sample state | `action` |

Auth usage pattern for every POST endpoint:
- Header auth (recommended):
  - URL: `POST /api.php`
  - Headers: `X-API-Key: YOUR_API_KEY`, `Content-Type: application/json`
  - Body: JSON with selected `action` + required fields
- Query auth fallback:
  - URL: `POST /api.php?api_key=YOUR_API_KEY`
  - Header: `Content-Type: application/json`
  - Body: JSON with selected `action` + required fields

#### Frontend Assets
- **css/style.css**:
  - Fixed layout with responsive design
  - Sortable table headers
  - Search box styling
  - Modal dialogs
  - Button variations
  - Light and dark mode styles
- **js/core/shared.js**:
  - Shared app state and utility helpers
  - Header analytics metric calculations and refresh
  - Theme initialization/persistence and reset workflow
  - Shared data and logs loading helpers
- **js/features/data-page.js**:
  - Server-backed Data table rendering with search, sorting, and pagination queries
  - Modal form management with API-driven create/update/delete actions
  - Data-page bulk selection and bulk delete logic
  - Row virtualization for the active page and filtered PDF/CSV export
- **js/features/reports-page.js**:
  - Report generation and report PDF/CSV download handlers
- **js/features/audit-page.js**:
  - Audit Trail table/timeline rendering, filtering, and diff markup
- **js/pages/app-init.js**:
  - Page-aware bootstrap that initializes only relevant feature modules

#### Data Storage
- **data/data.json**: Main data storage (encrypted)
- **data/logs.json**: Administrative activity logs (encrypted)
- **data/audit_trail.json**: Complete change history (encrypted)

## Usage Examples

### Add a New Record
1. Navigate to Data page
2. Click "Add New Record" button
3. Enter title and description
4. Click "Save" - record is added, logged, and tracked

### Bulk Delete Records
1. Navigate to Data page
2. Select records using row checkboxes (or use Select All)
3. Click "Delete Selected"
4. Confirm in the custom toast prompt
5. Selected records are removed, saved to data JSON, and logged in audit trail (including a bulk-action summary line)

### Export Filtered Data
1. Navigate to Data page and apply search/sort filters
2. Use action buttons in order: Add, Delete, Export PDF, Export CSV
3. Click "Export Filtered PDF" or "Export Filtered CSV"
4. The export endpoint returns the full filtered/sorted dataset that matches the active Data page query
5. Export action is logged in the system logs

### View Changes
1. Go to Audit Trail page
2. Choose Table View or Timeline View
3. Search for specific records or changes
4. View exactly what changed with before/after values
5. See timestamps for each modification

### Header Analytics
1. Look at the top-right of the header bar
2. Review total entries and total edits
3. Review adds today and deletes today for quick daily activity insight

### Generate and Export Reports
1. Go to Reports page
2. Select dataset: "Data" or "Logs"
3. Click "Generate Report"
4. Choose export: "Download as PDF" or "Download as CSV"

### Switch Theme
1. Look for the toggle switch in the bottom-left area of the navigation sidebar
2. Click to switch between Light and Dark mode
3. Theme preference saves automatically and persists across pages

### Reset Data
1. Click the "Reset Data" button in the navigation sidebar (below the theme toggle)
2. Confirm the action in the custom top-center toast prompt
3. All entries, logs, and audit trail are cleared
4. Dashboard restores 3 sample test entries for a fresh start
5. Page automatically reloads with reset data

### Toast Notifications
1. Add or edit a record on the Data page
2. A custom top-center success toast appears
3. You can click `Okay` to dismiss immediately, or allow it to auto-fade after 3 seconds

## Data Structures

### Data Format
```json
{
  "items": [
    { "id": 1, "title": "Project Name", "description": "Description text" }
  ]
}
```

### Logs Format
```json
{
  "logs": [
    {
      "id": 1,
      "date": "2026-03-31",
      "time": "15:57",
      "event": "A new entry has been added..."
    }
  ]
}
```

### Audit Trail Format
```json
{
  "entries": [
    {
      "id": 1,
      "date": "2026-03-31",
      "time": "15:57",
      "change_type": "ADD",
      "record_id": 1,
      "field_name": "title",
      "old_value": "",
      "new_value": "Project Name"
    }
  ]
}
```

## Security Features

### Data Validation
- **Required Fields**: All fields must be non-empty
- **Minimum Length**: At least 1 character required
- **Client-side Validation**: Real-time feedback
- **Error Messages**: Clear, user-friendly guidance

### Data Encryption
- **Method**: AES-256-CBC
- **Coverage**: All data, logs, and audit trail files encrypted at rest
- **Transparent**: Automatic encryption on save, decryption on load
- **Key Management**: Uses environment variable if available, otherwise uses built-in fallback key
- **Security**: Cannot be accessed without the encryption key

### Error Handling
- **Fixed Issues**:
  - Proper JSON response handling
  - No extra output corruption
  - Graceful error messages
  - Proper HTTP status codes
- **Error Suppression**: PHP errors won't break JSON responses

## Implemented Enhancements

- ✅ Search/filtering for data records (title/description) and audit trail entries  
- ✅ Data sorting by columns with visual indicators  
- ✅ Data export to CSV format  
- ✅ Comprehensive administrative logging  
- ✅ Dual report generation (Data and Logs)  
- ✅ Multiple export formats (PDF and CSV)  
- ✅ Data validation with helpful error messages  
- ✅ Data encryption at rest (AES-256-CBC)  
- ✅ Production-ready environment configuration  
- ✅ **Audit Trail System** with field-level tracking  
- ✅ **Dark Mode Theme** with persistent settings  
- ✅ **Fixed JSON Response Handling** for reliable operations  
- ✅ **Reset Data Functionality** for fresh starts
- ✅ **Bulk Delete Actions (Data Page)** with checkbox selection and confirmation prompt
- ✅ **Bulk Delete Audit Summary Entry** added for each batch delete action
- ✅ **Header Analytics Widgets** with total and daily activity counts
- ✅ **Audit Trail Timeline View** with date-grouped change history
- ✅ **Filtered Data Export (PDF/CSV)** for current visible results
- ✅ **Paginated Data Grid** with bottom-right rows-per-page selector and persistent preference
- ✅ **Virtualized Row Rendering** for improved Data page performance on larger lists
- ✅ **Server-Side Data Queries** for Data page pagination, filtering, and sorting
- ✅ **API-Driven Data CRUD** for Data page add, edit, delete, and bulk delete actions
- ✅ **API File Lock + Retry Writes** for improved reliability under concurrent operations
- ✅ **Deployable Environment Modes** (`demo` and `production`) with configurable index reset behavior
- ✅ **Modular JavaScript Loading** with shared core + page-specific feature modules
- ✅ **Mobile Responsive Shell** with phone-first layout overrides for header, navigation, controls, and content flow
- ✅ **Small-Screen Table Handling** with touch scrolling support and safer table sizing

## Important Notes

- ✅ The `data/` directory must have write permissions for PHP
- ✅ Timestamps are generated with a fixed one-hour offset
- ✅ All logs auto-generated with detailed descriptions
- ✅ All data operations immediately saved
- ✅ Theme preference persists across pages
- ✅ Data search works on title and description fields
- ✅ Dark mode fully supported across all components
- ✅ `index.php` reset behavior is controlled by environment mode/settings (`APP_MODE` and `RESET_ON_INDEX_VISIT`)
- ✅ Bulk actions are currently limited to bulk delete on the Data page only
- ✅ Audit Trail supports both Table and Timeline views
- ✅ Data-page filtered exports log export events and include the full filtered/sorted dataset returned by the backend export endpoint
- ✅ Data page rows-per-page preference persists across reloads
- ✅ Legacy monolithic `js/script.js` has been retired in favor of modular files
- ✅ For real phone testing on a local server, open the app using your computer's LAN IP (not `localhost`)

## Maintenance & Customization

### Adding a New Page
1. Create new page with reusable includes
2. Add link to `includes/navigation.php`
3. Theme support is applied automatically

### Updating Styling
- Modify `css/style.css` and all pages update automatically
- Both light and dark mode styles included

### Adding New Fields
1. Update the form in `pages/data.php`
2. Update the relevant feature module in `js/features/` (for example `data-page.js`)
3. Update audit trail tracking
4. Data automatically encrypted

## Requirements

- PHP 7.0+ (with OpenSSL extension)
- jQuery (loaded from CDN)
- Modern web browser (Chrome, Firefox, Safari, Edge)
- Write permissions on `data/` directory
- Local server (XAMPP, WAMP, or similar)
