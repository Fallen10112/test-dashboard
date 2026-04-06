<?php
require_once __DIR__ . '/includes/sql_helpers.php';

if (APP_MODE === 'demo' && RESET_ON_INDEX_VISIT) {
	try {
		$pdo = getDashboardPdo();
		resetDashboardSqlData($pdo);
	} catch (Throwable $e) {
		// Keep redirect behavior stable even if reset fails.
	}
}

header('Location: pages/home.php');
exit();
?>

