<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>
	
	
	<main class="main-content main-content-table-page">
		<section class="content-section active table-page-section">
			<h2>Data</h2>
			<div class="data-controls">
				<div class="search-container">
					<input type="text" id="search-input" class="search-box" placeholder="Search by Title or Description...">
				</div>
				<button id="add-record-btn" class="btn btn-primary">+ Add New Record</button>
				<button id="import-csv-btn" class="btn btn-info">Import CSV</button>
				<button id="bulk-delete-btn" class="btn btn-danger" disabled>Delete Selected (0)</button>
				<button id="export-filtered-pdf-btn" class="btn btn-info">Export filtered lines to PDF</button>
				<button id="export-filtered-csv-btn" class="btn btn-info">Export filtered lines to CSV</button>
			</div>
			<div id="data-container">Loading data...</div>
			
			
			<div id="record-modal" class="modal hidden">
				<div class="modal-content">
					<span class="modal-close">&times;</span>
					<h3 id="modal-title">Add New Record</h3>
					<form id="record-form">
						<div class="form-group">
							<label for="record-title">Title:</label>
							<input type="text" id="record-title" required>
						</div>
						<div class="form-group">
							<label for="record-description">Description:</label>
							<textarea id="record-description" required></textarea>
						</div>
						<div class="form-actions">
							<button type="submit" class="btn btn-primary">Save Record</button>
							<button type="button" class="btn btn-success" id="save-and-add-another-btn">Save & add another record</button>
							<button type="button" class="btn btn-secondary" id="modal-cancel">Cancel</button>
						</div>
					</form>
				</div>
			</div>

			<div id="csv-import-modal" class="modal hidden">
				<div class="modal-content modal-wide csv-import-modal-content">
					<span class="modal-close">&times;</span>
					<h3>Import CSV</h3>
					<p class="csv-import-help">Download the template CSV, fill in the title and description columns, then upload the completed file to add records in bulk.</p>
					<div class="csv-import-actions">
						<button type="button" class="btn btn-secondary" id="csv-import-download-template-btn">Download Template CSV</button>
					</div>
					<div class="form-group">
						<label for="csv-import-file-input">Upload CSV:</label>
						<input type="file" id="csv-import-file-input" class="csv-import-file-input" accept=".csv,text/csv">
					</div>
					<div id="csv-import-summary" class="csv-import-summary">No CSV file selected yet.</div>
					<div id="csv-import-preview" class="csv-import-preview-shell">
						<div class="csv-import-preview-empty">Upload a CSV file to preview the parsed rows here.</div>
					</div>
					<div class="form-actions">
						<button type="button" class="btn btn-primary" id="csv-import-submit-btn" disabled>Import CSV</button>
						<button type="button" class="btn btn-secondary" id="csv-import-cancel-btn">Cancel</button>
					</div>
				</div>
			</div>
		</section>
	</main>

	<button id="scroll-to-top" class="scroll-to-top" title="Back to top">↑ Top</button>

<?php include '../includes/footer.php'; ?>

