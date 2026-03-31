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
				<li><strong>Encryption</strong> - All data and logs are encrypted using AES-256-CBC for security</li>
				<li><strong>Audit Trail</strong> - Complete tracking of every data change with timestamps, record IDs, and field-level modifications</li>
				<li><strong>Event Logging</strong> - Automatic event logging for all operations performed on the system</li>
				<li><strong>Reports</strong> - Generate comprehensive reports and export data as PDF or CSV</li>
				<li><strong>Search & Sort</strong> - Quickly find data with powerful search and sorting capabilities</li>
				<li><strong>Dark Mode</strong> - Toggle between light and dark themes with persistent settings</li>
			</ul>
			
			<h3 style="margin-top: 30px; color: #2c3e50; font-size: 1.3em;">Getting Started</h3>
			<ul>
				<li><strong>Data Page</strong> - Manage your records. Add new entries, edit existing ones, or delete records. All changes are automatically logged in the audit trail.</li>
				<li><strong>Reports Page</strong> - Generate reports from your data or system logs. Export reports as PDF or CSV files for sharing and analysis.</li>
				<li><strong>Audit Trail Page</strong> - View the complete history of all data modifications. Search for specific changes or record IDs to see exactly what changed and when.</li>
				<li><strong>Theme Toggle</strong> - Use the toggle in the bottom left of the navigation to switch between light and dark mode.</li>
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

