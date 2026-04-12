	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<?php require_once __DIR__ . '/page_context.php'; ?>
	<?php if (isCurrentPage('reports.php') || isCurrentPage('data.php')): ?>
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
	<?php endif; ?>
	<script>
		window.DASHBOARD_APP_MODE = <?php echo json_encode((string)APP_MODE, JSON_UNESCAPED_SLASHES); ?>;
	</script>

	<script src="../js/core/shared.js"></script>
	<?php
	$currentPage = getCurrentPageSlug();
	$featureScripts = [
		'data.php' => '../js/features/data-page.js',
		'reports.php' => '../js/features/reports-page.js',
		'audit.php' => '../js/features/audit-page.js',
		'ui-customization.php' => '../js/features/ui-customization-page.js',
		'admin.php' => '../js/features/admin-page.js',
	];
	foreach ($featureScripts as $pageSlug => $scriptPath):
		if ($currentPage !== $pageSlug) {
			continue;
		}
	?>
	<script src="<?php echo htmlspecialchars($scriptPath, ENT_QUOTES, 'UTF-8'); ?>"></script>
	<?php endforeach; ?>
	<script src="../js/pages/app-init.js"></script>
</body>
</html>

