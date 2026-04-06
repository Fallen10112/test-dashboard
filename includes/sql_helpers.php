<?php

require_once __DIR__ . '/../config/config.php';

function getDashboardPdo() {
	static $pdo = null;

	if ($pdo instanceof PDO) {
		return $pdo;
	}

	$dsn = sprintf(
		'mysql:host=%s;port=%s;dbname=%s;charset=%s',
		DB_HOST,
		DB_PORT,
		DB_DATABASE,
		DB_CHARSET
	);

	$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	];

	$pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
	return $pdo;
}

function getDashboardSqlTimestamp() {
	return date('Y-m-d H:i:s', time() - 3600);
}

function resetDashboardSqlData(PDO $pdo) {
	$sampleItems = [
		['title' => 'Sample Entry 1', 'description' => 'This is a test entry to demonstrate the system.'],
		['title' => 'Sample Entry 2', 'description' => 'Another test entry showing the data management features.'],
		['title' => 'Sample Entry 3', 'description' => 'A third test entry to provide a complete example.'],
	];

	try {
		$pdo->beginTransaction();
		$pdo->exec('DELETE FROM audit_log');
		$pdo->exec('DELETE FROM activity_log');
		$pdo->exec('DELETE FROM records');
		$pdo->commit();

		// Reset counters while tables are empty, then insert deterministic sample IDs.
		$pdo->exec('ALTER TABLE records AUTO_INCREMENT = 1');
		$pdo->exec('ALTER TABLE activity_log AUTO_INCREMENT = 1');
		$pdo->exec('ALTER TABLE audit_log AUTO_INCREMENT = 1');

		$insertRecord = $pdo->prepare('INSERT INTO records (id, title, description, created_at, updated_at) VALUES (:id, :title, :description, :created_at, :updated_at)');
		$now = getDashboardSqlTimestamp();
		$seedId = 1;
		foreach ($sampleItems as $item) {
			$insertRecord->execute([
				':id' => $seedId,
				':title' => $item['title'],
				':description' => $item['description'],
				':created_at' => $now,
				':updated_at' => $now,
			]);
			$seedId++;
		}

		return true;
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return false;
	}
}
