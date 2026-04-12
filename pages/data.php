<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';
require_once __DIR__ . '/../includes/page_context.php';
startAuthSession();
requireAuth();
requirePagePermission('records', 'read');

$dataPagePermissionFlags = [
	'create' => false,
	'update' => false,
	'delete' => false,
	'export' => false,
];
try {
	$dataPageUser = $GLOBALS['auth_user'] ?? null;
	$dataPageUserId = (int)($dataPageUser['id'] ?? 0);
	if ($dataPageUserId > 0) {
		$dataPagePdo = getDashboardPdo();
		ensurePermissionsSchema($dataPagePdo);
		$dataPagePermissionFlags = dashboardBuildPermissionFlags($dataPagePdo, $dataPageUserId, [
			'create' => ['records', 'create'],
			'update' => ['records', 'update'],
			'delete' => ['records', 'delete'],
			'export' => ['records', 'export'],
		], $dataPagePermissionFlags);
	}
} catch (Throwable $e) {
	$dataPagePermissionFlags = ['create' => false, 'update' => false, 'delete' => false, 'export' => false];
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navigation.php'; ?>

<script>
	window.DASHBOARD_PAGE_PERMISSIONS = <?php echo dashboardJsonEncodeOrFallback($dataPagePermissionFlags, '{"create":false,"update":false,"delete":false,"export":false}'); ?>;
</script>
	
	
	<main class="main-content main-content-table-page">
		<section class="content-section active table-page-section">
			<h2>Data</h2>
			<div class="data-controls">
				<div class="search-container">
					<input type="text" id="search-input" class="search-box" placeholder="Search by Title or Description...">
				</div>
				<?php if ($dataPagePermissionFlags['create']): ?><button id="add-record-btn" class="btn btn-primary">+ Add New Record</button><?php endif; ?>
				<?php if ($dataPagePermissionFlags['create']): ?><button id="import-csv-btn" class="btn btn-info">Import CSV</button><?php endif; ?>
				<?php if ($dataPagePermissionFlags['delete']): ?><button id="bulk-delete-btn" class="btn btn-danger" disabled>Delete Selected (0)</button><?php endif; ?>
				<?php if ($dataPagePermissionFlags['export']): ?><button id="export-filtered-pdf-btn" class="btn btn-info">Export filtered lines to PDF</button><?php endif; ?>
				<?php if ($dataPagePermissionFlags['export']): ?><button id="export-filtered-csv-btn" class="btn btn-info">Export filtered lines to CSV</button><?php endif; ?>
			</div>
			<div id="data-container">Loading data...</div>
			
			
			<?php if ($dataPagePermissionFlags['create'] || $dataPagePermissionFlags['update']): ?>
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
			<?php endif; ?>

			<?php if ($dataPagePermissionFlags['create']): ?>
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
			<?php endif; ?>
		</section>
	</main>

	<button id="scroll-to-top" class="scroll-to-top" title="Back to top">↑ Top</button>

	<?php require_once __DIR__ . '/../includes/footer.php'; ?>

