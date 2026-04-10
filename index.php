<?php
require_once __DIR__ . '/includes/sql_helpers.php';
require_once __DIR__ . '/includes/auth.php';

startAuthSession();

if (getAuthUser() === null) {
	header('Location: pages/login.php');
	exit();
}

if (APP_MODE === 'demo' && RESET_ON_INDEX_VISIT) {
	try {
		$pdo = getDashboardPdo();
		resetDashboardSqlData($pdo);
	} catch (Throwable $e) {
	}
}

header('Location: pages/home.php');
exit();
?>

