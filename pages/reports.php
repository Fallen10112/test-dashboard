<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';
startAuthSession();
requireAuth();
requirePagePermission('reports', 'read');

$reportsPagePermissionFlags = [
	'export' => false,
];
try {
	$reportsPageUser = $GLOBALS['auth_user'] ?? null;
	$reportsPageUserId = (int)($reportsPageUser['id'] ?? 0);
	if ($reportsPageUserId > 0) {
		$reportsPagePdo = getDashboardPdo();
		ensurePermissionsSchema($reportsPagePdo);
		$reportsPagePermissionFlags['export'] = userHasPermission($reportsPagePdo, $reportsPageUserId, 'reports', 'export');
	}
} catch (Throwable $e) {
	$reportsPagePermissionFlags = ['export' => false];
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

<script>
	window.DASHBOARD_PAGE_PERMISSIONS = <?php echo json_encode($reportsPagePermissionFlags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
	
	
	<main class="main-content">
		<section class="content-section active">
			<h2>Reports</h2>
			<div class="reports-controls">
				<div class="form-group">
					<label for="dataset-selector">Select Dataset:</label>
					<select id="dataset-selector" class="dataset-selector">
						<option value="data">Data</option>
						<option value="logs">Logs</option>
					</select>
				</div>
				<button id="generate-report-btn" class="btn btn-primary">Generate Report</button>
				<?php if ($reportsPagePermissionFlags['export']): ?><button id="download-pdf-btn" class="btn btn-success hidden">Download as PDF</button><?php endif; ?>
				<?php if ($reportsPagePermissionFlags['export']): ?><button id="download-csv-btn" class="btn btn-info hidden">Download as CSV</button><?php endif; ?>
			</div>
			<div id="report-container"></div>
		</section>
	</main>

	<button id="scroll-to-top" class="scroll-to-top" title="Back to top">↑ Top</button>

<?php include '../includes/footer.php'; ?>

