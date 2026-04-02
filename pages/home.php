<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>
	
	
	<main class="main-content">
		<section class="content-section active">
			<h2>Welcome to Dashboard Showcase</h2>
			<h3 style="margin-top: 30px; color: #2c3e50; font-size: 1.3em;">About This Project</h3>
			<p>This dashboard application demonstrates a full-featured data management system with encryption, audit trails, and reporting capabilities. Built using PHP, jQuery, HTML, CSS, and secure encryption protocols, it provides an interface for managing structured data with complete change tracking. Future iterations may include additional features and enhancements, such as SQL database integration instead of JSON files.</p>
			
			<h3 style="margin-top: 30px; color: #2c3e50; font-size: 1.3em; ">Key Features</h3>
			<ul style="margin-bottom: 20px;">
				<li><strong>Data Management</strong> - Create, edit, and delete records with a clean, intuitive interface</li>
				<li><strong>Bulk Delete (Data Page)</strong> - Select multiple records and remove them in a single action from the Data page, with a dedicated bulk-action audit entry</li>
				<li><strong>Filtered View Export</strong> - Export only the currently visible Data page results as PDF or CSV, with export logging</li>
				<li><strong>Header Analytics Widgets</strong> - Quick KPI pills in the header for total entries, total edits, adds today, and deletes today</li>
				<li><strong>Encryption</strong> - All data and logs are encrypted using AES-256-CBC for security</li>
				<li><strong>Audit Trail</strong> - Complete tracking of every data change with timestamps, record IDs, and field-level modifications, available in both table and timeline views</li>
				<li><strong>Write Safety</strong> - API write operations use file locking with retry logic for improved reliability during concurrent actions</li>
				<li><strong>Event Logging</strong> - Automatic event logging for all operations performed on the system</li>
				<li><strong>Reports</strong> - Generate comprehensive reports and export data as PDF or CSV</li>
				<li><strong>Search & Sort</strong> - Quickly find data with powerful search and sorting capabilities</li>
				<li><strong>Dark Mode</strong> - Toggle between light and dark themes with persistent settings</li>
				<li><strong>Custom Toast Notifications</strong> - Top-center prompts for confirmations and success messages with action buttons or timed fade-out</li>
			</ul>
			
			<h3 style="margin-top: 30px; color: #2c3e50; font-size: 1.3em;">Getting Started</h3>
			<ul>
				<li><strong>Data Page</strong> - Manage your records. Add new entries, edit existing ones, delete single records, or bulk delete selected records. You can also export the current filtered view as PDF or CSV, and all changes are automatically logged in the audit trail, including a bulk-action summary entry for batch deletes.</li>
				<li><strong>Reports Page</strong> - Generate reports from your data or system logs. Export reports as PDF or CSV files for sharing and analysis.</li>
				<li><strong>Audit Trail Page</strong> - View the complete history of all data modifications in table or timeline view. Search for specific changes or record IDs to see exactly what changed and when.</li>
				<li><strong>Header Widgets</strong> - Use the top-right analytics pills for quick daily and total activity counts.</li>
				<li><strong>Theme Toggle</strong> - Use the toggle in the bottom left of the navigation to switch between light and dark mode.</li>
				<li><strong>Toast Prompts</strong> - Reset Data now uses a custom confirmation toast, and successful add/edit actions show auto-fading confirmation toasts.</li>
			</ul>
			
			<h3 style="margin-top: 30px; color: #2c3e50; font-size: 1.3em;">Technical Stack</h3>
			<ul>
				<li><strong>Backend</strong> - PHP with OpenSSL encryption (AES-256-CBC)</li>
				<li><strong>Frontend</strong> - jQuery for interactive features, HTML5, and modern CSS</li>
				<li><strong>Data Storage</strong> - Encrypted JSON files for persistent data storage</li>
				<li><strong>Security</strong> - Encrypted audit trails and event logs</li>
			</ul>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>

