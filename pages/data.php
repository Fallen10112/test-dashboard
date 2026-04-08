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
							<button type="button" class="btn btn-secondary" id="modal-cancel">Cancel</button>
						</div>
					</form>
				</div>
			</div>
		</section>
	</main>

	<button id="scroll-to-top" class="scroll-to-top" title="Back to top">↑ Top</button>

<?php include '../includes/footer.php'; ?>

