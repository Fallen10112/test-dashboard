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
	return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');
}

function getRequestIpAddress() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
	if ($ip === '') {
		return null;
	}
	return substr($ip, 0, 45);
}

function getRequestUserAgent() {
	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
	if ($ua === '') {
		return null;
	}
	return substr($ua, 0, 255);
}

function doesTableColumnExist(PDO $pdo, $tableName, $columnName) {
	$stmt = $pdo->prepare(
		'SELECT 1
		 FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = :table_name
		   AND COLUMN_NAME = :column_name
		 LIMIT 1'
	);
	$stmt->execute([
		':table_name' => (string)$tableName,
		':column_name' => (string)$columnName,
	]);
	return (bool)$stmt->fetchColumn();
}

function doesTableIndexExist(PDO $pdo, $tableName, $indexName) {
	$stmt = $pdo->prepare(
		'SELECT 1
		 FROM information_schema.STATISTICS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = :table_name
		   AND INDEX_NAME = :index_name
		 LIMIT 1'
	);
	$stmt->execute([
		':table_name' => (string)$tableName,
		':index_name' => (string)$indexName,
	]);
	return (bool)$stmt->fetchColumn();
}

function ensureActivityLogSchema(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS activity_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(100) NOT NULL,
			message TEXT NOT NULL,
			related_record_type VARCHAR(50) NULL,
			related_record_id BIGINT UNSIGNED NULL,
			source_user_id BIGINT UNSIGNED NULL,
			ip_address VARCHAR(45) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);

	if (!doesTableColumnExist($pdo, 'activity_log', 'source_user_id')) {
		$pdo->exec('ALTER TABLE activity_log ADD COLUMN source_user_id BIGINT UNSIGNED NULL AFTER related_record_id');
	}
	if (!doesTableColumnExist($pdo, 'activity_log', 'ip_address')) {
		$pdo->exec('ALTER TABLE activity_log ADD COLUMN ip_address VARCHAR(45) NULL AFTER source_user_id');
	}

	if (!doesTableIndexExist($pdo, 'activity_log', 'idx_activity_created_at')) {
		$pdo->exec('ALTER TABLE activity_log ADD INDEX idx_activity_created_at (created_at)');
	}
	if (!doesTableIndexExist($pdo, 'activity_log', 'idx_activity_source')) {
		$pdo->exec('ALTER TABLE activity_log ADD INDEX idx_activity_source (source_user_id)');
	}
}

function ensureAuditLogSchema(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			record_type VARCHAR(50) NOT NULL DEFAULT "system",
			record_id BIGINT UNSIGNED NULL,
			action VARCHAR(50) NOT NULL DEFAULT "update",
			details TEXT NULL,
			source_user_id BIGINT UNSIGNED NULL,
			target_user_id BIGINT UNSIGNED NULL,
			ip_address VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);

	if (!doesTableColumnExist($pdo, 'audit_log', 'details')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN details TEXT NULL AFTER action');
	}

	if (doesTableColumnExist($pdo, 'audit_log', 'field_name')) {
		$pdo->exec('ALTER TABLE audit_log DROP COLUMN field_name');
	}

	if (!doesTableColumnExist($pdo, 'audit_log', 'source_user_id')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN source_user_id BIGINT UNSIGNED NULL AFTER details');
	}
	if (!doesTableColumnExist($pdo, 'audit_log', 'target_user_id')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN target_user_id BIGINT UNSIGNED NULL AFTER source_user_id');
	}
	if (!doesTableColumnExist($pdo, 'audit_log', 'ip_address')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN ip_address VARCHAR(45) NULL AFTER target_user_id');
	}
	if (!doesTableColumnExist($pdo, 'audit_log', 'user_agent')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address');
	}

	$actionTypeStmt = $pdo->query(
		'SELECT DATA_TYPE
		 FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = "audit_log"
		   AND COLUMN_NAME = "action"
		 LIMIT 1'
	);
	$actionDataType = strtolower((string)$actionTypeStmt->fetchColumn());
	if ($actionDataType === 'enum') {
		$pdo->exec('ALTER TABLE audit_log MODIFY COLUMN action VARCHAR(50) NOT NULL');
	}

	if (!doesTableIndexExist($pdo, 'audit_log', 'idx_audit_created_at')) {
		$pdo->exec('ALTER TABLE audit_log ADD INDEX idx_audit_created_at (created_at)');
	}
	if (!doesTableIndexExist($pdo, 'audit_log', 'idx_audit_source')) {
		$pdo->exec('ALTER TABLE audit_log ADD INDEX idx_audit_source (source_user_id)');
	}
	if (!doesTableIndexExist($pdo, 'audit_log', 'idx_audit_target')) {
		$pdo->exec('ALTER TABLE audit_log ADD INDEX idx_audit_target (target_user_id)');
	}
	if (!doesTableIndexExist($pdo, 'audit_log', 'idx_audit_record')) {
		$pdo->exec('ALTER TABLE audit_log ADD INDEX idx_audit_record (record_type, record_id)');
	}
}

function ensureUserWidgetPreferencesSchema(PDO $pdo) {
	$tableName = 'user_widget_preferences';
	$pdo->exec('DROP TABLE IF EXISTS user_widget_preferences_compact');
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS ' . $tableName . ' (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			widgets_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_widget_pref_user (user_id),
			KEY idx_widget_pref_user (user_id),
			CONSTRAINT fk_widget_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);

	if (!doesTableColumnExist($pdo, $tableName, 'updated_at')) {
		$pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN updated_at DATETIME NOT NULL AFTER created_at');
	}
	if (!doesTableIndexExist($pdo, $tableName, 'uniq_widget_pref_user')) {
		$pdo->exec('ALTER TABLE ' . $tableName . ' ADD UNIQUE INDEX uniq_widget_pref_user (user_id)');
	}
	if (!doesTableIndexExist($pdo, $tableName, 'idx_widget_pref_user')) {
		$pdo->exec('ALTER TABLE ' . $tableName . ' ADD INDEX idx_widget_pref_user (user_id)');
	}
}

function resetAuditLogTable(PDO $pdo) {
	$pdo->exec('DROP TABLE IF EXISTS audit_log');
	ensureAuditLogSchema($pdo);
}

function resetActivityLogTable(PDO $pdo) {
	ensureActivityLogSchema($pdo);
	$pdo->exec('TRUNCATE TABLE activity_log');
}

function resetAuditLogEntries(PDO $pdo) {
	ensureAuditLogSchema($pdo);
	$pdo->exec('TRUNCATE TABLE audit_log');
}

function resetUserWidgetPreferencesTable(PDO $pdo) {
	ensureUserWidgetPreferencesSchema($pdo);
	$defaultWidgets = [
		'total_entries' => true,
		'total_edits' => true,
		'adds_today' => true,
		'deletes_today' => true,
		'local_time' => true,
	];
	$defaultJson = json_encode($defaultWidgets);
	if (!is_string($defaultJson) || $defaultJson === '') {
		$defaultJson = '{}';
	}

	$seededCount = 0;
	$now = getDashboardSqlTimestamp();

	$pdo->beginTransaction();
	try {
		$pdo->exec('TRUNCATE TABLE user_widget_preferences');

		$userIdsStmt = $pdo->query('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC');
		$userRows = $userIdsStmt ? $userIdsStmt->fetchAll() : [];

		if (is_array($userRows) && !empty($userRows)) {
			$insertStmt = $pdo->prepare(
				'INSERT INTO user_widget_preferences (user_id, widgets_json, created_at, updated_at)
				 VALUES (:user_id, :widgets_json, :created_at, :updated_at)'
			);

			foreach ($userRows as $row) {
				$userId = (int)($row['id'] ?? 0);
				if ($userId < 1) {
					continue;
				}
				$insertStmt->execute([
					':user_id' => $userId,
					':widgets_json' => $defaultJson,
					':created_at' => $now,
					':updated_at' => $now,
				]);
				$seededCount += 1;
			}
		}

		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $e;
	}

	return $seededCount;
}

function resetRecordsTableToSample(PDO $pdo) {
	$sampleItems = [
		['title' => 'Sample Entry 1', 'description' => 'This is a test entry to demonstrate the system.'],
		['title' => 'Sample Entry 2', 'description' => 'Another test entry showing the data management features.'],
		['title' => 'Sample Entry 3', 'description' => 'A third test entry to provide a complete example.'],
	];

	try {
		$pdo->exec('DELETE FROM records');
		$pdo->exec('ALTER TABLE records AUTO_INCREMENT = 1');

		$hasCreatedByUserId = doesTableColumnExist($pdo, 'records', 'created_by_user_id');
		$hasUpdatedByUserId = doesTableColumnExist($pdo, 'records', 'updated_by_user_id');
		$hasCreatedAt = doesTableColumnExist($pdo, 'records', 'created_at');
		$hasUpdatedAt = doesTableColumnExist($pdo, 'records', 'updated_at');

		$columns = ['title', 'description'];
		$placeholders = [':title', ':description'];
		if ($hasCreatedByUserId) {
			$columns[] = 'created_by_user_id';
			$placeholders[] = ':created_by_user_id';
		}
		if ($hasUpdatedByUserId) {
			$columns[] = 'updated_by_user_id';
			$placeholders[] = ':updated_by_user_id';
		}
		if ($hasCreatedAt) {
			$columns[] = 'created_at';
			$placeholders[] = ':created_at';
		}
		if ($hasUpdatedAt) {
			$columns[] = 'updated_at';
			$placeholders[] = ':updated_at';
		}

		$insertRecord = $pdo->prepare('INSERT INTO records (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
		$now = getDashboardSqlTimestamp();
		foreach ($sampleItems as $item) {
			$params = [
				':title' => $item['title'],
				':description' => $item['description'],
			];
			if ($hasCreatedByUserId) {
				$params[':created_by_user_id'] = null;
			}
			if ($hasUpdatedByUserId) {
				$params[':updated_by_user_id'] = null;
			}
			if ($hasCreatedAt) {
				$params[':created_at'] = $now;
			}
			if ($hasUpdatedAt) {
				$params[':updated_at'] = $now;
			}
			$insertRecord->execute($params);
		}
		return true;
	} catch (Throwable $e) {
		return false;
	}
}

function writeAuditEvent(PDO $pdo, array $entry) {
	ensureAuditLogSchema($pdo);

	$recordType = trim((string)($entry['record_type'] ?? 'system'));
	$action = trim((string)($entry['action'] ?? 'event'));
	$recordIdRaw = $entry['record_id'] ?? null;
	$recordId = is_numeric($recordIdRaw) ? (int)$recordIdRaw : null;
	$sourceUserIdRaw = $entry['source_user_id'] ?? null;
	$sourceUserId = is_numeric($sourceUserIdRaw) ? (int)$sourceUserIdRaw : null;
	$targetUserIdRaw = $entry['target_user_id'] ?? null;
	$targetUserId = is_numeric($targetUserIdRaw) ? (int)$targetUserIdRaw : null;
	$details = isset($entry['details']) ? (string)$entry['details'] : '';

	$stmt = $pdo->prepare(
		'INSERT INTO audit_log (record_type, record_id, action, details, source_user_id, target_user_id, ip_address, user_agent, created_at)
		 VALUES (:record_type, :record_id, :action, :details, :source_user_id, :target_user_id, :ip_address, :user_agent, :created_at)'
	);

	return $stmt->execute([
		':record_type' => $recordType !== '' ? substr($recordType, 0, 50) : 'system',
		':record_id' => $recordId,
		':action' => $action !== '' ? substr($action, 0, 50) : 'event',
		':details' => $details !== '' ? $details : null,
		':source_user_id' => $sourceUserId,
		':target_user_id' => $targetUserId,
		':ip_address' => isset($entry['ip_address']) ? (string)$entry['ip_address'] : getRequestIpAddress(),
		':user_agent' => isset($entry['user_agent']) ? (string)$entry['user_agent'] : getRequestUserAgent(),
		':created_at' => isset($entry['created_at']) ? (string)$entry['created_at'] : getDashboardSqlTimestamp(),
	]);
}

function writeAuditEvents(PDO $pdo, array $entries) {
	if (empty($entries)) {
		return false;
	}

	$allOk = true;
	foreach ($entries as $entry) {
		if (!is_array($entry) || !writeAuditEvent($pdo, $entry)) {
			$allOk = false;
		}
	}

	return $allOk;
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

		$insertRecord = $pdo->prepare('INSERT INTO records (id, title, description, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:id, :title, :description, :created_by_user_id, :updated_by_user_id, :created_at, :updated_at)');
		$now = getDashboardSqlTimestamp();
		$seedId = 1;
		foreach ($sampleItems as $item) {
			$insertRecord->execute([
				':id' => $seedId,
				':title' => $item['title'],
				':description' => $item['description'],
				':created_by_user_id' => null,
				':updated_by_user_id' => null,
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
