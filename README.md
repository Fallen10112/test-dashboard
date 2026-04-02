# Dashboard Showcase

A comprehensive web-based dashboard built with PHP, jQuery, HTML, CSS, and modern web technologies. Features secure data management with AES-256-CBC encryption, complete audit trail tracking, activity logging, and report generation capabilities.

## Project Structure

```
test-dashboard/
├── index.php              # Entry point (redirects to home.php)
├── pages/
│   ├── home.php           # Home/Welcome page with project overview
│   ├── data.php           # Data management page (CRUD operations)
│   ├── reports.php        # Reports & analytics with PDF/CSV export
│   └── audit.php          # Audit trail history viewer
├── includes/
│   ├── header.php         # HTML head and fixed title bar
│   ├── navigation.php     # Fixed left sidebar navigation with dark mode toggle
│   └── footer.php         # Closing HTML tags and script includes
├── config/
│   ├── config.php         # Configuration & security settings
│   └── .env.example       # Environment variables template
├── css/
│   └── style.css          # Main stylesheet with light & dark mode support
├── js/
│   └── script.js          # jQuery functionality, AJAX calls, theme management
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
- **View Data**: Displays all records in a responsive, sortable table
- **Add Records**: Modal form to add new entries with auto-incrementing IDs
- **Edit Records**: Full editing capability for existing records
- **Delete Records**: Remove records with confirmation dialogs
- **Search & Filter**: Real-time search by title and description
- **Column Sorting**: Click column headers to sort (ascending/descending)
- **Auto-Save**: All changes immediately saved to encrypted storage
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
- **Field-Level Tracking**: See exactly what changed in each field
- **Before & After Values**: Compare old and new values
- **Search Functionality**: Find specific changes quickly
- **Timestamps**: Exact date and time of each modification
- **Change Classification**: Clearly identifies ADD, EDIT, or DELETE operations
- **Record Linking**: Associates changes with their respective record IDs

### 🎨 Dark Mode Theme
- **Theme Toggle**: Toggle switch in the bottom-left area of navigation
- **Persistent Settings**: Theme preference saved via localStorage
- **Light Mode**: Original clean white interface
- **Dark Mode**: Professional dark color palette for reduced eye strain
- **Full Coverage**: Dark mode applies to all pages and components

### 🔄 Reset Data Functionality
- **Reset Button**: Located in navigation sidebar, below the theme toggle
- **Complete Reset**: Clears all entries, logs, and audit trail
- **Fresh Start**: Restores 3 sample test entries for demonstration
- **Confirmation Dialog**: Prevents accidental data loss
- **Safe Operation**: Immediately reloads page with fresh data

### 📝 Administrative Logging System
- **Automatic Tracking**: Logs all user actions with timestamps
- **Event Types Tracked**:
  - New entry added
  - Entry edited (with field-level changes)
  - Entry deleted
  - Report generated
  - Report downloaded (PDF or CSV)
- **Encryption**: All logs encrypted at rest for security
- **Timezone Handling**: Uses a fixed one-hour offset when generating log timestamps
- **Searchable**: Logs are available through the Reports page dataset selector

### 🎯 Layout & Design
- **Fixed Title Bar**: 5% viewport height with a gradient background
- **Fixed Left Navigation**: 250px sidebar with active page highlighting
- **Responsive Main Content**: Scrollable content area that adapts to the active theme
- **Professional Styling**: Modern UI with smooth transitions and hover effects
- **Consistent Design**: Unified look across all pages

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
- **index.php**: Entry point that redirects to `home.php`
- **pages/home.php**: Comprehensive welcome with feature overview
- **pages/data.php**: Data management interface with CRUD operations
- **pages/reports.php**: Report generation with multiple export options
- **pages/audit.php**: Audit trail viewer with search functionality

#### Include Files (Reusable Components)
- **includes/header.php**: HTML head tags and fixed title bar
- **includes/navigation.php**: Fixed left sidebar with dark mode toggle
- **includes/footer.php**: Closing HTML tags, shared script includes, and reports-only PDF library include

#### Backend
- **api.php**: Handles all backend operations:
  - Get/save data from/to encrypted JSON files
  - Manage logs and audit trails
  - Track all data changes with field-level detail
  - Encrypt/decrypt using AES-256-CBC
  - Generate timestamps using a fixed one-hour offset

#### Frontend Assets
- **css/style.css**:
  - Fixed layout with responsive design
  - Sortable table headers
  - Search box styling
  - Modal dialogs
  - Button variations
  - Light and dark mode styles
- **js/script.js**:
  - AJAX operations with error handling
  - Table rendering with search and sorting
  - Modal form management
  - CRUD operations
  - Report generation
  - PDF and CSV export
  - Dark mode theme switching and persistence

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

### View Changes
1. Go to Audit Trail page
2. Search for specific records or changes
3. View exactly what changed with before/after values
4. See timestamps for each modification

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
2. Confirm the action when prompted
3. All entries, logs, and audit trail are cleared
4. Dashboard restores 3 sample test entries for a fresh start
5. Page automatically reloads with reset data

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

## Important Notes

- ✅ The `data/` directory must have write permissions for PHP
- ✅ Timestamps are generated with a fixed one-hour offset
- ✅ All logs auto-generated with detailed descriptions
- ✅ All data operations immediately saved
- ✅ Theme preference persists across pages
- ✅ Data search works on title and description fields
- ✅ Dark mode fully supported across all components

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
2. Update JavaScript handlers in `js/script.js`
3. Update audit trail tracking
4. Data automatically encrypted

## Requirements

- PHP 7.0+ (with OpenSSL extension)
- jQuery (loaded from CDN)
- Modern web browser (Chrome, Firefox, Safari, Edge)
- Write permissions on `data/` directory
- Local server (XAMPP, WAMP, or similar)
