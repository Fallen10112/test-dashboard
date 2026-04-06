	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<?php if (in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'data.php'], true)): ?>
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
	<?php endif; ?>
	<?php require_once __DIR__ . '/../config/config.php'; ?>
	<script>
		window.DASHBOARD_API_KEY = <?php echo json_encode((string)API_KEY, JSON_UNESCAPED_SLASHES); ?>;
		window.DASHBOARD_APP_MODE = <?php echo json_encode((string)APP_MODE, JSON_UNESCAPED_SLASHES); ?>;
	</script>

	<script src="../js/core/shared.js"></script>
	<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
	<?php if ($currentPage === 'data.php'): ?>
	<script src="../js/features/data-page.js"></script>
	<?php endif; ?>
	<?php if ($currentPage === 'reports.php'): ?>
	<script src="../js/features/reports-page.js"></script>
	<?php endif; ?>
	<?php if ($currentPage === 'audit.php'): ?>
	<script src="../js/features/audit-page.js"></script>
	<?php endif; ?>
	<?php if ($currentPage === 'widget-settings.php'): ?>
	<script src="../js/features/widgets-page.js"></script>
	<?php endif; ?>
	<script src="../js/pages/app-init.js"></script>
</body>
</html>

