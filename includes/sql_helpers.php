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

function normalizeDevToolsUserLookupQuery($value) {
	return trim((string)$value);
}

function generateRandomPasswordPlaintext($length = 16) {
	$length = max(8, (int)$length);
	$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*-_';
	$charactersLength = strlen($characters);
	$password = '';

	for ($index = 0; $index < $length; $index++) {
		$password .= $characters[random_int(0, $charactersLength - 1)];
	}

	return $password;
}

function dashboardTableExists(PDO $pdo, $tableName) {
	$table = trim((string)$tableName);
	if ($table === '') {
		return false;
	}
	$stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
	$stmt->execute([':table_name' => $table]);
	return (bool)$stmt->fetchColumn();
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

function forceDeleteUserHard(PDO $pdo, $targetUserId, $currentUserId) {
	$userId = (int)$targetUserId;
	$actorId = (int)$currentUserId;
	if ($userId < 1) {
		return ['success' => false, 'message' => 'A valid user id is required'];
	}
	if ($actorId > 0 && $userId === $actorId) {
		return ['success' => false, 'message' => 'You cannot force delete your currently signed-in account'];
	}

	$userStmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
	$userStmt->execute([':id' => $userId]);
	$target = $userStmt->fetch();
	if (!$target) {
		return ['success' => false, 'message' => 'User not found'];
	}

	try {
		$pdo->beginTransaction();
		if (dashboardTableExists($pdo, 'user_sessions')) {
			$pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute([':id' => $userId]);
		}
		if (dashboardTableExists($pdo, 'password_reset_tokens')) {
			$pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id')->execute([':id' => $userId]);
		}
		if (dashboardTableExists($pdo, 'user_roles')) {
			$pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id OR assigned_by_user_id = :assigned_by_user_id')->execute([
				':user_id' => $userId,
				':assigned_by_user_id' => $userId,
			]);
		}
		if (dashboardTableExists($pdo, 'user_permissions')) {
			$pdo->prepare('DELETE FROM user_permissions WHERE user_id = :user_id OR granted_by_user_id = :granted_by_user_id')->execute([
				':user_id' => $userId,
				':granted_by_user_id' => $userId,
			]);
		}
		if (dashboardTableExists($pdo, 'user_widget_preferences')) {
			$pdo->prepare('DELETE FROM user_widget_preferences WHERE user_id = :id')->execute([':id' => $userId]);
		}
		if (dashboardTableExists($pdo, 'notifications')) {
			$pdo->prepare('UPDATE notifications SET sent_by_user_id = NULL WHERE sent_by_user_id = :id')->execute([':id' => $userId]);
			$pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute([':id' => $userId]);
		}
		$pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to force delete user'];
	}

	return [
		'success' => true,
		'deleted_user' => [
			'id' => (int)$target['id'],
			'username' => (string)($target['username'] ?? ''),
			'email' => (string)($target['email'] ?? ''),
		],
	];
}

function ensureAuditLogSchema(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			record_type VARCHAR(50) NOT NULL DEFAULT "system",
			record_id BIGINT UNSIGNED NULL,
			dataset VARCHAR(100) NULL,
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
	if (!doesTableColumnExist($pdo, 'audit_log', 'dataset')) {
		$pdo->exec('ALTER TABLE audit_log ADD COLUMN dataset VARCHAR(100) NULL AFTER record_id');
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
	if (!doesTableIndexExist($pdo, 'audit_log', 'idx_audit_dataset')) {
		$pdo->exec('ALTER TABLE audit_log ADD INDEX idx_audit_dataset (dataset)');
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

function getPermissionResourceDefinitions() {
	return [
		'home' => ['display_name' => 'Home', 'description' => 'Landing page access'],
		'records' => ['display_name' => 'Data', 'description' => 'Records page and CRUD actions'],
		'widgets' => ['display_name' => 'Widgets', 'description' => 'Header widgets and customization controls'],
		'reports' => ['display_name' => 'Reports', 'description' => 'Reports page and exports'],
		'audit_log' => ['display_name' => 'Audit Trail', 'description' => 'Audit trail page access'],
		'admin' => ['display_name' => 'Admin', 'description' => 'Admin workspace access'],
		'dev_tools' => ['display_name' => 'Dev Tools', 'description' => 'Maintenance and reset tools'],
	];
}

function getPermissionActionDefinitions() {
	return [
		'read' => ['display_name' => 'Visible', 'description' => 'View the page'],
		'customize' => ['display_name' => 'Customize', 'description' => 'Change widget visibility preferences'],
		'create' => ['display_name' => 'Create', 'description' => 'Add new entries'],
		'update' => ['display_name' => 'Update', 'description' => 'Edit existing entries'],
		'delete' => ['display_name' => 'Delete', 'description' => 'Remove entries, including bulk delete'],
		'export' => ['display_name' => 'Export', 'description' => 'Download or export data'],
		'total_entries' => ['display_name' => 'Entries Widget', 'description' => 'Show the entries widget in the header'],
		'total_edits' => ['display_name' => 'Edits Widget', 'description' => 'Show the edits widget in the header'],
		'adds_today' => ['display_name' => 'Adds Today Widget', 'description' => 'Show the adds today widget in the header'],
		'deletes_today' => ['display_name' => 'Deletes Today Widget', 'description' => 'Show the deletes today widget in the header'],
		'local_time' => ['display_name' => 'Local Time Widget', 'description' => 'Show the local time widget in the header'],
		'manage_users' => ['display_name' => 'Manage Users', 'description' => 'Create and edit users'],
		'manage_permissions' => ['display_name' => 'Manage Permissions', 'description' => 'Edit role and user permissions'],
		'admin_role_create' => ['display_name' => 'Admin Role Create', 'description' => 'Add new roles'],
		'admin_role_update' => ['display_name' => 'Admin Role Update', 'description' => 'Edit existing roles'],
		'admin_role_delete' => ['display_name' => 'Admin Role Delete', 'description' => 'Delete roles and reassign users'],
		'admin_permissions_edit_lower' => ['display_name' => 'Edit Permissions Below Your Role', 'description' => 'Edit permissions for roles below your own role'],
		'admin_permissions_edit_self' => ['display_name' => 'Edit Permissions Through Your Role', 'description' => 'Edit permissions for roles up to and including your own role'],
		'admin_permissions_edit_all' => ['display_name' => 'Edit Permissions For All Roles', 'description' => 'Edit permissions for every role'],
		'admin_user_management' => ['display_name' => 'Admin User Management', 'description' => 'Open the user management tab'],
		'admin_role_management' => ['display_name' => 'Admin Role Management', 'description' => 'Open the role management tab'],
		'admin_database_management' => ['display_name' => 'Admin Database Management', 'description' => 'Open the database management tab'],
		'admin_application_management' => ['display_name' => 'Admin Application Management', 'description' => 'Open the application management tab'],
		'admin_notifications_management' => ['display_name' => 'Admin Notifications Management', 'description' => 'Open the notifications tab'],
		'admin_permissions_management' => ['display_name' => 'Admin Permissions Management', 'description' => 'Open the permissions tab'],
	];
}

function ensurePermissionsSchema(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS resources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			resource_key VARCHAR(100) NOT NULL,
			display_name VARCHAR(150) NOT NULL,
			description VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_resource_key (resource_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS permissions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			permission_key VARCHAR(50) NOT NULL,
			display_name VARCHAR(100) NOT NULL,
			description VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_permission_key (permission_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS role_permissions (
			role_id BIGINT UNSIGNED NOT NULL,
			resource_id BIGINT UNSIGNED NOT NULL,
			permission_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (role_id, resource_id, permission_id),
			KEY idx_role_permissions_role (role_id),
			KEY idx_role_permissions_resource (resource_id),
			KEY idx_role_permissions_permission (permission_id),
			CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
			CONSTRAINT fk_role_permissions_resource FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
			CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS user_permissions (
			user_id BIGINT UNSIGNED NOT NULL,
			resource_id BIGINT UNSIGNED NOT NULL,
			permission_id BIGINT UNSIGNED NOT NULL,
			is_allowed TINYINT(1) NOT NULL,
			granted_by_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (user_id, resource_id, permission_id),
			KEY idx_user_permissions_user (user_id),
			KEY idx_user_permissions_resource (resource_id),
			KEY idx_user_permissions_permission (permission_id),
			CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
			CONSTRAINT fk_user_permissions_resource FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
			CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
			CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);

	$resourceStmt = $pdo->prepare(
		'INSERT IGNORE INTO resources (resource_key, display_name, description, created_at)
		 VALUES (:resource_key, :display_name, :description, :created_at)'
	);
	foreach (getPermissionResourceDefinitions() as $resourceKey => $definition) {
		$resourceStmt->execute([
			':resource_key' => $resourceKey,
			':display_name' => substr((string)($definition['display_name'] ?? $resourceKey), 0, 150),
			':description' => isset($definition['description']) ? substr((string)$definition['description'], 0, 255) : null,
			':created_at' => getDashboardSqlTimestamp(),
		]);
	}

	$permissionStmt = $pdo->prepare(
		'INSERT IGNORE INTO permissions (permission_key, display_name, description, created_at)
		 VALUES (:permission_key, :display_name, :description, :created_at)'
	);
	$permissionUpdateStmt = $pdo->prepare(
		'UPDATE permissions
		 SET display_name = :display_name,
		     description = :description
		 WHERE permission_key = :permission_key'
	);
	foreach (getPermissionActionDefinitions() as $permissionKey => $definition) {
		$permissionStmt->execute([
			':permission_key' => $permissionKey,
			':display_name' => substr((string)($definition['display_name'] ?? $permissionKey), 0, 100),
			':description' => isset($definition['description']) ? substr((string)$definition['description'], 0, 255) : null,
			':created_at' => getDashboardSqlTimestamp(),
		]);
		$permissionUpdateStmt->execute([
			':permission_key' => $permissionKey,
			':display_name' => substr((string)($definition['display_name'] ?? $permissionKey), 0, 100),
			':description' => isset($definition['description']) ? substr((string)$definition['description'], 0, 255) : null,
		]);
	}

	$resourceUpdateStmt = $pdo->prepare(
		'UPDATE resources
		 SET display_name = :display_name,
		     description = :description
		 WHERE resource_key = :resource_key'
	);
	foreach (getPermissionResourceDefinitions() as $resourceKey => $definition) {
		$resourceUpdateStmt->execute([
			':resource_key' => $resourceKey,
			':display_name' => substr((string)($definition['display_name'] ?? $resourceKey), 0, 150),
			':description' => isset($definition['description']) ? substr((string)$definition['description'], 0, 255) : null,
		]);
	}

	seedDefaultRolePermissions($pdo);
}

function getPermissionResourceByKey(PDO $pdo, $resourceKey) {
	$key = trim((string)$resourceKey);
	if ($key === '' || !dashboardTableExists($pdo, 'resources')) {
		return null;
	}

	$stmt = $pdo->prepare('SELECT id, resource_key, display_name, description FROM resources WHERE resource_key = :resource_key LIMIT 1');
	$stmt->execute([':resource_key' => $key]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	return ['id' => (int)($row['id'] ?? 0), 'key' => (string)($row['resource_key'] ?? ''), 'display_name' => (string)($row['display_name'] ?? ''), 'description' => (string)($row['description'] ?? '')];
}

function getPermissionByKey(PDO $pdo, $permissionKey) {
	$key = trim((string)$permissionKey);
	if ($key === '' || !dashboardTableExists($pdo, 'permissions')) {
		return null;
	}

	$stmt = $pdo->prepare('SELECT id, permission_key, display_name, description FROM permissions WHERE permission_key = :permission_key LIMIT 1');
	$stmt->execute([':permission_key' => $key]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	return ['id' => (int)($row['id'] ?? 0), 'key' => (string)($row['permission_key'] ?? ''), 'display_name' => (string)($row['display_name'] ?? ''), 'description' => (string)($row['description'] ?? '')];
}

function getUserRoleIds(PDO $pdo, $userId) {
	$normalizedUserId = (int)$userId;
	if ($normalizedUserId < 1 || !dashboardTableExists($pdo, 'user_roles')) {
		return [];
	}

	try {
		$stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = :user_id ORDER BY created_at ASC, role_id ASC');
		$stmt->execute([':user_id' => $normalizedUserId]);
		$rows = $stmt->fetchAll();
	} catch (Throwable $e) {
		return [];
	}

	$roleIds = [];
	foreach ($rows as $row) {
		$roleId = (int)($row['role_id'] ?? 0);
		if ($roleId > 0) {
			$roleIds[] = $roleId;
		}
	}

	return array_values(array_unique($roleIds));
}

function getUserPermissionOverride(PDO $pdo, $userId, $resourceKey, $permissionKey) {
	$normalizedUserId = (int)$userId;
	if ($normalizedUserId < 1 || !dashboardTableExists($pdo, 'user_permissions')) {
		return null;
	}

	$resource = getPermissionResourceByKey($pdo, $resourceKey);
	$permission = getPermissionByKey($pdo, $permissionKey);
	if (!$resource || !$permission) {
		return null;
	}

	$stmt = $pdo->prepare('SELECT is_allowed FROM user_permissions WHERE user_id = :user_id AND resource_id = :resource_id AND permission_id = :permission_id LIMIT 1');
	$stmt->execute([':user_id' => $normalizedUserId, ':resource_id' => (int)$resource['id'], ':permission_id' => (int)$permission['id']]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	return ((int)($row['is_allowed'] ?? 0)) === 1;
}

function getRolePermissionGrant(PDO $pdo, $roleId, $resourceKey, $permissionKey) {
	$normalizedRoleId = (int)$roleId;
	if ($normalizedRoleId < 1 || !dashboardTableExists($pdo, 'role_permissions')) {
		return false;
	}

	$resource = getPermissionResourceByKey($pdo, $resourceKey);
	$permission = getPermissionByKey($pdo, $permissionKey);
	if (!$resource || !$permission) {
		return false;
	}

	$stmt = $pdo->prepare('SELECT 1 FROM role_permissions WHERE role_id = :role_id AND resource_id = :resource_id AND permission_id = :permission_id LIMIT 1');
	$stmt->execute([':role_id' => $normalizedRoleId, ':resource_id' => (int)$resource['id'], ':permission_id' => (int)$permission['id']]);

	return (bool)$stmt->fetchColumn();
}

function getRolePermissionsByRoleId(PDO $pdo, $roleId) {
	$normalizedRoleId = (int)$roleId;
	if ($normalizedRoleId < 1 || !dashboardTableExists($pdo, 'role_permissions')) {
		return [];
	}

	try {
		$stmt = $pdo->prepare('SELECT r.resource_key, p.permission_key FROM role_permissions rp JOIN resources r ON r.id = rp.resource_id JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :role_id');
		$stmt->execute([':role_id' => $normalizedRoleId]);
		$rows = $stmt->fetchAll();
	} catch (Throwable $e) {
		return [];
	}

	$grants = [];
	foreach ($rows as $row) {
		$resourceKey = trim((string)($row['resource_key'] ?? ''));
		$permissionKey = trim((string)($row['permission_key'] ?? ''));
		if ($resourceKey === '' || $permissionKey === '') {
			continue;
		}
		if (!isset($grants[$resourceKey])) {
			$grants[$resourceKey] = [];
		}
		$grants[$resourceKey][$permissionKey] = true;
	}

	return $grants;
}

function getAllPermissionResources(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'resources')) {
		return [];
	}

	try {
		$stmt = $pdo->query('SELECT id, resource_key, display_name, description FROM resources ORDER BY id ASC');
		$rows = $stmt ? $stmt->fetchAll() : [];
	} catch (Throwable $e) {
		return [];
	}

	$resources = [];
	foreach ($rows as $row) {
		$resources[] = ['id' => (int)($row['id'] ?? 0), 'key' => (string)($row['resource_key'] ?? ''), 'display_name' => (string)($row['display_name'] ?? ''), 'description' => (string)($row['description'] ?? '')];
	}

	return $resources;
}

function getAllPermissionActions(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'permissions')) {
		return [];
	}

	try {
		$stmt = $pdo->query('SELECT id, permission_key, display_name, description FROM permissions ORDER BY id ASC');
		$rows = $stmt ? $stmt->fetchAll() : [];
	} catch (Throwable $e) {
		return [];
	}

	$permissions = [];
	foreach ($rows as $row) {
		$permissions[] = ['id' => (int)($row['id'] ?? 0), 'key' => (string)($row['permission_key'] ?? ''), 'display_name' => (string)($row['display_name'] ?? ''), 'description' => (string)($row['description'] ?? '')];
	}

	return $permissions;
}

function getDefaultRolePermissionTemplates() {
	return [
		'Guest' => ['home' => ['read']],
		'Administrator' => ['home' => ['read'], 'records' => ['read', 'create', 'update'], 'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time']],
		'Coordinator' => ['home' => ['read'], 'records' => ['read', 'create', 'update', 'delete'], 'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time']],
		'Team Leader' => ['home' => ['read'], 'records' => ['read', 'create', 'update', 'delete', 'export'], 'reports' => ['read', 'export'], 'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time']],
		'Manager' => ['home' => ['read'], 'records' => ['read', 'create', 'update', 'delete', 'export'], 'reports' => ['read', 'export'], 'audit_log' => ['read'], 'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'], 'admin' => ['read', 'manage_users', 'manage_permissions', 'admin_user_management', 'admin_role_management', 'admin_notifications_management', 'admin_permissions_management', 'admin_role_create', 'admin_role_update', 'admin_permissions_edit_lower']],
		'Director' => ['home' => ['read'], 'records' => ['read', 'create', 'update', 'delete', 'export'], 'reports' => ['read', 'export'], 'audit_log' => ['read'], 'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'], 'admin' => ['read', 'manage_users', 'manage_permissions', 'admin_user_management', 'admin_role_management', 'admin_database_management', 'admin_application_management', 'admin_notifications_management', 'admin_permissions_management', 'admin_role_create', 'admin_role_update', 'admin_permissions_edit_self']],
		'Full Access' => ['__all__' => ['__all__']],
	];
}

function getPermissionEditScopeDefinitions() {
	return [
		'admin_permissions_edit_lower' => ['mode' => 'lower', 'label' => 'Below your role'],
		'admin_permissions_edit_self' => ['mode' => 'self', 'label' => 'Through your role'],
		'admin_permissions_edit_all' => ['mode' => 'all', 'label' => 'All roles'],
	];
}


function getPermissionEditorResourceActionMap() {
	return [
		'home' => ['read'],
		'records' => ['read', 'create', 'update', 'delete', 'export'],
		'widgets' => ['read', 'customize', 'total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'],
		'reports' => ['read', 'export'],
		'audit_log' => ['read'],
		'admin' => ['read', 'manage_users', 'manage_permissions', 'admin_user_management', 'admin_role_management', 'admin_role_create', 'admin_role_update', 'admin_role_delete', 'admin_database_management', 'admin_application_management', 'admin_notifications_management', 'admin_permissions_management'],
		'dev_tools' => ['read'],
	];
}

function getHeaderWidgetPermissionState(PDO $pdo, $userId) {
	$normalizedUserId = (int)$userId;
	$widgetKeys = ['total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'];
	$canView = $normalizedUserId > 0 && userHasPermission($pdo, $normalizedUserId, 'widgets', 'read');
	$allowedKeys = [];
	if ($canView) {
		foreach ($widgetKeys as $widgetKey) {
			if (userHasPermission($pdo, $normalizedUserId, 'widgets', $widgetKey)) {
				$allowedKeys[] = $widgetKey;
			}
		}
	}

	$canCustomize = $normalizedUserId > 0 && userHasPermission($pdo, $normalizedUserId, 'widgets', 'customize');

	return [
		'can_view' => $canView,
		'can_customize' => $canCustomize,
		'allowed_keys' => $allowedKeys,
	];
}

function getPermissionEditorVisibleResources(PDO $pdo, $userId) {
	$normalizedUserId = (int)$userId;
	$resourceDefinitions = getPermissionResourceDefinitions();
	$resources = getAllPermissionResources($pdo);
	$visibleResources = [];

	foreach ($resources as $resource) {
		$resourceKey = (string)($resource['key'] ?? '');
		if ($resourceKey === '') {
			continue;
		}

		if ($normalizedUserId > 0 && !userHasPermission($pdo, $normalizedUserId, $resourceKey, 'read')) {
			continue;
		}

		$visibleResources[] = [
			'key' => $resourceKey,
			'display_name' => (string)($resourceDefinitions[$resourceKey]['display_name'] ?? $resourceKey),
			'description' => (string)($resourceDefinitions[$resourceKey]['description'] ?? ''),
		];
	}

	return $visibleResources;
}

function getUserPermissionEditScope(PDO $pdo, $userId) {
	$normalizedUserId = (int)$userId;
	$primaryRole = getUserPrimaryRole($pdo, $normalizedUserId);
	$primaryRoleId = (int)($primaryRole['id'] ?? 0);
	$primaryRoleName = trim((string)($primaryRole['name'] ?? ''));
	$defaultScope = [
		'mode' => 'none',
		'label' => 'No editable roles',
		'current_role_id' => $primaryRoleId > 0 ? $primaryRoleId : null,
		'max_role_id' => 0,
	];

	if ($normalizedUserId < 1 || $primaryRoleId < 1) {
		return $defaultScope;
	}

	if ($primaryRoleName === 'Full Access') {
		return [
			'mode' => 'all',
			'label' => 'All roles',
			'current_role_id' => $primaryRoleId,
			'max_role_id' => null,
		];
	}

	if (userHasPermission($pdo, $normalizedUserId, 'admin', 'admin_permissions_edit_all')) {
		return [
			'mode' => 'all',
			'label' => 'All roles',
			'current_role_id' => $primaryRoleId,
			'max_role_id' => null,
		];
	}

	if (userHasPermission($pdo, $normalizedUserId, 'admin', 'admin_permissions_edit_self')) {
		return [
			'mode' => 'self',
			'label' => 'Up to and including your role',
			'current_role_id' => $primaryRoleId,
			'max_role_id' => $primaryRoleId,
		];
	}

	if (userHasPermission($pdo, $normalizedUserId, 'admin', 'admin_permissions_edit_lower')) {
		return [
			'mode' => 'lower',
			'label' => 'Below your role',
			'current_role_id' => $primaryRoleId,
			'max_role_id' => max(0, $primaryRoleId - 1),
		];
	}

	return $defaultScope;
}

function canUserEditRolePermissions(PDO $pdo, $userId, $targetRoleId) {
	$normalizedTargetRoleId = (int)$targetRoleId;
	if ($normalizedTargetRoleId < 1) {
		return false;
	}

	$scope = getUserPermissionEditScope($pdo, $userId);
	$mode = (string)($scope['mode'] ?? 'none');
	$currentRoleId = (int)($scope['current_role_id'] ?? 0);

	if ($mode === 'all') {
		return true;
	}
	if ($mode === 'self') {
		return $currentRoleId > 0 && $normalizedTargetRoleId <= $currentRoleId;
	}
	if ($mode === 'lower') {
		return $currentRoleId > 0 && $normalizedTargetRoleId < $currentRoleId;
	}

	return false;
}

function getEditableRolesForPermissionScope(array $roles, array $scope) {
	$mode = (string)($scope['mode'] ?? 'none');
	$currentRoleId = (int)($scope['current_role_id'] ?? 0);
	$maxRoleId = isset($scope['max_role_id']) ? $scope['max_role_id'] : 0;
	$maxRoleId = $maxRoleId === null ? null : (int)$maxRoleId;

	$editableRoles = [];
	foreach ($roles as $role) {
		$roleId = (int)($role['id'] ?? 0);
		if ($roleId < 1) {
			continue;
		}
		if ($mode === 'all') {
			$editableRoles[] = $role;
			continue;
		}
		if ($mode === 'self' && $currentRoleId > 0 && $roleId <= $currentRoleId) {
			$editableRoles[] = $role;
			continue;
		}
		if ($mode === 'lower' && $currentRoleId > 0 && $roleId <= (int)$maxRoleId) {
			$editableRoles[] = $role;
		}
	}

	return $editableRoles;
}

function seedDefaultRolePermissions(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'roles') || !dashboardTableExists($pdo, 'resources') || !dashboardTableExists($pdo, 'permissions') || !dashboardTableExists($pdo, 'role_permissions')) {
		return;
	}

	$resources = getAllPermissionResources($pdo);
	$permissions = getAllPermissionActions($pdo);
	if (empty($resources) || empty($permissions)) {
		return;
	}

	$roleTemplates = getDefaultRolePermissionTemplates();
	$roleStmt = $pdo->query('SELECT id, name FROM roles');
	$roleRows = $roleStmt ? $roleStmt->fetchAll() : [];
	$resourceLookup = [];
	foreach ($resources as $resource) {
		$resourceLookup[$resource['key']] = $resource;
	}
	$permissionLookup = [];
	foreach ($permissions as $permission) {
		$permissionLookup[$permission['key']] = $permission;
	}
	$roleHasPermissionsStmt = $pdo->prepare('SELECT 1 FROM role_permissions WHERE role_id = :role_id LIMIT 1');

	$insertStmt = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, resource_id, permission_id, created_at) VALUES (:role_id, :resource_id, :permission_id, :created_at)');

	foreach ($roleRows as $roleRow) {
		$roleId = (int)($roleRow['id'] ?? 0);
		$roleName = trim((string)($roleRow['name'] ?? ''));
		if ($roleId < 1 || $roleName === '' || !isset($roleTemplates[$roleName])) {
			continue;
		}

		$roleHasPermissionsStmt->execute([':role_id' => $roleId]);
		if ($roleHasPermissionsStmt->fetchColumn()) {
			continue;
		}

		$template = $roleTemplates[$roleName];
		if (isset($template['__all__'])) {
			foreach ($resources as $resource) {
				foreach ($permissions as $permission) {
					$insertStmt->execute([':role_id' => $roleId, ':resource_id' => (int)$resource['id'], ':permission_id' => (int)$permission['id'], ':created_at' => getDashboardSqlTimestamp()]);
				}
			}
			continue;
		}

		foreach ($template as $resourceKey => $allowedPermissions) {
			if (!isset($resourceLookup[$resourceKey]) || !is_array($allowedPermissions)) {
				continue;
			}
			foreach ($allowedPermissions as $permissionKey) {
				if (!isset($permissionLookup[$permissionKey])) {
					continue;
				}
				$insertStmt->execute([':role_id' => $roleId, ':resource_id' => (int)$resourceLookup[$resourceKey]['id'], ':permission_id' => (int)$permissionLookup[$permissionKey]['id'], ':created_at' => getDashboardSqlTimestamp()]);
			}
		}
	}
}

function setRolePermissions(PDO $pdo, $roleId, array $grants) {
	$normalizedRoleId = (int)$roleId;
	if ($normalizedRoleId < 1 || !dashboardTableExists($pdo, 'roles') || !dashboardTableExists($pdo, 'resources') || !dashboardTableExists($pdo, 'permissions') || !dashboardTableExists($pdo, 'role_permissions')) {
		return false;
	}

	$normalizedGrants = [];
	foreach ($grants as $grant) {
		$grant = trim((string)$grant);
		if ($grant === '' || strpos($grant, ':') === false) {
			continue;
		}
		list($resourceKey, $permissionKey) = array_pad(explode(':', $grant, 2), 2, '');
		$resourceKey = trim((string)$resourceKey);
		$permissionKey = trim((string)$permissionKey);
		if ($resourceKey === '' || $permissionKey === '') {
			continue;
		}
		$normalizedGrants[$resourceKey . ':' . $permissionKey] = ['resource_key' => $resourceKey, 'permission_key' => $permissionKey];
	}

	try {
		$pdo->beginTransaction();
		$pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute([':role_id' => $normalizedRoleId]);

		if (!empty($normalizedGrants)) {
			$resourceLookup = [];
			foreach (getAllPermissionResources($pdo) as $resource) {
				$resourceLookup[$resource['key']] = $resource;
			}
			$permissionLookup = [];
			foreach (getAllPermissionActions($pdo) as $permission) {
				$permissionLookup[$permission['key']] = $permission;
			}
			$insertStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, resource_id, permission_id, created_at) VALUES (:role_id, :resource_id, :permission_id, :created_at)');
			foreach ($normalizedGrants as $grant) {
				$resourceKey = $grant['resource_key'];
				$permissionKey = $grant['permission_key'];
				if (!isset($resourceLookup[$resourceKey], $permissionLookup[$permissionKey])) {
					continue;
				}
				$insertStmt->execute([':role_id' => $normalizedRoleId, ':resource_id' => (int)$resourceLookup[$resourceKey]['id'], ':permission_id' => (int)$permissionLookup[$permissionKey]['id'], ':created_at' => getDashboardSqlTimestamp()]);
			}
		}
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return false;
	}

	return true;
}

function getAllRolePermissionsMatrix(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'roles') || !dashboardTableExists($pdo, 'role_permissions')) {
		return [];
	}

	try {
		$stmt = $pdo->query('SELECT rp.role_id, r.resource_key, p.permission_key FROM role_permissions rp JOIN resources r ON r.id = rp.resource_id JOIN permissions p ON p.id = rp.permission_id ORDER BY rp.role_id ASC, r.id ASC, p.id ASC');
		$rows = $stmt ? $stmt->fetchAll() : [];
	} catch (Throwable $e) {
		return [];
	}

	$matrix = [];
	foreach ($rows as $row) {
		$roleId = (int)($row['role_id'] ?? 0);
		$resourceKey = trim((string)($row['resource_key'] ?? ''));
		$permissionKey = trim((string)($row['permission_key'] ?? ''));
		if ($roleId < 1 || $resourceKey === '' || $permissionKey === '') {
			continue;
		}
		if (!isset($matrix[$roleId])) {
			$matrix[$roleId] = [];
		}
		if (!isset($matrix[$roleId][$resourceKey])) {
			$matrix[$roleId][$resourceKey] = [];
		}
		$matrix[$roleId][$resourceKey][$permissionKey] = true;
	}

	return $matrix;
}


function getRolePermissionsEditorPayload(PDO $pdo, $userId = null) {
	return [
		'roles' => getAvailableRoles($pdo),
		'resources' => getPermissionEditorVisibleResources($pdo, $userId),
		'permissions' => getAllPermissionActions($pdo),
		'role_permissions' => getAllRolePermissionsMatrix($pdo),
		'allowed_permissions_by_resource' => getPermissionEditorResourceActionMap(),
	];
}

function filterRolePermissionGrantsForUser(PDO $pdo, $userId, array $grants) {
	$accessMap = getPermissionEditorResourceActionMap();
	$filtered = [];
	foreach ($grants as $grant) {
		$grant = trim((string)$grant);
		if ($grant === '' || strpos($grant, ':') === false) {
			continue;
		}
		list($resourceKey, $permissionKey) = array_pad(explode(':', $grant, 2), 2, '');
		$resourceKey = trim((string)$resourceKey);
		$permissionKey = trim((string)$permissionKey);
		if ($resourceKey === '' || $permissionKey === '' || !isset($accessMap[$resourceKey]) || !is_array($accessMap[$resourceKey])) {
			continue;
		}
		if (!in_array($permissionKey, $accessMap[$resourceKey], true)) {
			continue;
		}
		$filtered[] = $resourceKey . ':' . $permissionKey;
	}

	if (in_array('widgets:customize', $filtered, true) && !in_array('widgets:read', $filtered, true)) {
		$filtered = array_values(array_filter($filtered, static function($grant) {
			return $grant !== 'widgets:customize';
		}));
	}

	return array_values(array_unique($filtered));
}

function userHasPermission(PDO $pdo, $userId, $resourceKey, $permissionKey) {
	$normalizedUserId = (int)$userId;
	if ($normalizedUserId < 1) {
		return false;
	}

	$resourceKey = trim((string)$resourceKey);
	$permissionKey = trim((string)$permissionKey);
	if ($resourceKey === '' || $permissionKey === '') {
		return false;
	}

	$override = getUserPermissionOverride($pdo, $normalizedUserId, $resourceKey, $permissionKey);
	if ($override !== null) {
		if (!$override) {
			return false;
		}
		if ($resourceKey === 'widgets' && $permissionKey === 'customize' && !userHasPermission($pdo, $normalizedUserId, 'widgets', 'read')) {
			return false;
		}
		return true;
	}

	$roleIds = getUserRoleIds($pdo, $normalizedUserId);
	if (empty($roleIds)) {
		return false;
	}

	foreach ($roleIds as $roleId) {
		if (!getRolePermissionGrant($pdo, $roleId, $resourceKey, $permissionKey)) {
			continue;
		}
		if ($resourceKey === 'widgets' && $permissionKey === 'customize' && !userHasPermission($pdo, $normalizedUserId, 'widgets', 'read')) {
			continue;
		}
		if ($resourceKey === 'widgets' && $permissionKey === 'customize') {
			return true;
		}
		if (getRolePermissionGrant($pdo, $roleId, $resourceKey, $permissionKey)) {
			return true;
		}
	}

	return false;
}

function requirePagePermission($resourceKey, $permissionKey = 'read', $denyRedirect = '../pages/home.php') {
	startAuthSession();
	$user = $GLOBALS['auth_user'] ?? null;
	if (!is_array($user) || empty($user['id'])) {
		header('Location: login.php');
		exit();
	}

	$pdo = getDashboardPdo();
	ensurePermissionsSchema($pdo);
	if (!userHasPermission($pdo, (int)$user['id'], $resourceKey, $permissionKey)) {
		if (is_string($denyRedirect) && $denyRedirect !== '') {
			header('Location: ' . $denyRedirect);
			exit();
		}
		http_response_code(403);
		echo 'Forbidden';
		exit();
	}
}

function getAvailableRoles(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'roles')) {
		return [];
	}

	try {
		$stmt = $pdo->query('SELECT id, name, description FROM roles ORDER BY id ASC');
		$rows = $stmt ? $stmt->fetchAll() : [];
	} catch (Throwable $e) {
		return [];
	}

	$roles = [];
	foreach ($rows as $row) {
		$roles[] = [
			'id' => (int)($row['id'] ?? 0),
			'name' => (string)($row['name'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
		];
	}

	return $roles;
}


function getRoleByLookup(PDO $pdo, $lookup) {
	$query = trim((string)$lookup);
	if ($query === '' || !dashboardTableExists($pdo, 'roles')) {
		return null;
	}

	$idCandidate = ctype_digit($query) ? (int)$query : 0;

	try {
		$stmt = $pdo->prepare(
			'SELECT id, name, description
			 FROM roles
			 WHERE id = :id_candidate OR name = :name_candidate
			 LIMIT 1'
		);
		$stmt->execute([
			':id_candidate' => $idCandidate,
			':name_candidate' => $query,
		]);
		$row = $stmt->fetch();
	} catch (Throwable $e) {
		return null;
	}

	if (!$row) {
		return null;
	}

	return [
		'id' => (int)($row['id'] ?? 0),
		'name' => (string)($row['name'] ?? ''),
		'description' => (string)($row['description'] ?? ''),
	];
}


function getRoleReplacementForDeletion(PDO $pdo, $deletedRoleId) {
	$normalizedRoleId = (int)$deletedRoleId;
	if ($normalizedRoleId < 1 || !dashboardTableExists($pdo, 'roles')) {
		return null;
	}

	try {
		$stmt = $pdo->prepare(
			'SELECT id, name, description
			 FROM roles
			 WHERE id < :id
			 ORDER BY id DESC
			 LIMIT 1'
		);
		$stmt->execute([':id' => $normalizedRoleId]);
		$row = $stmt->fetch();
		if (!$row) {
			$stmt = $pdo->prepare(
				'SELECT id, name, description
				 FROM roles
				 WHERE id > :id
				 ORDER BY id ASC
				 LIMIT 1'
			);
			$stmt->execute([':id' => $normalizedRoleId]);
			$row = $stmt->fetch();
		}
	} catch (Throwable $e) {
		return null;
	}

	if (!$row) {
		return null;
	}

	return [
		'id' => (int)($row['id'] ?? 0),
		'name' => (string)($row['name'] ?? ''),
		'description' => (string)($row['description'] ?? ''),
	];
}


function createAdminRole(PDO $pdo, $name, $description) {
	$normalizedName = trim((string)$name);
	$normalizedDescription = trim((string)$description);

	if ($normalizedName === '') {
		return ['success' => false, 'message' => 'Role name is required'];
	}
	if (strlen($normalizedName) > 50) {
		return ['success' => false, 'message' => 'Role name must not exceed 50 characters'];
	}
	if (strlen($normalizedDescription) > 255) {
		return ['success' => false, 'message' => 'Role description must not exceed 255 characters'];
	}
	if (!dashboardTableExists($pdo, 'roles')) {
		return ['success' => false, 'message' => 'Roles table is not available'];
	}

	$columns = ['name'];
	$placeholders = [':name'];
	$params = [
		':name' => substr($normalizedName, 0, 50),
	];

	if (doesTableColumnExist($pdo, 'roles', 'description')) {
		$columns[] = 'description';
		$placeholders[] = ':description';
		$params[':description'] = $normalizedDescription !== '' ? substr($normalizedDescription, 0, 255) : null;
	}
	if (doesTableColumnExist($pdo, 'roles', 'created_at')) {
		$columns[] = 'created_at';
		$placeholders[] = ':created_at';
		$params[':created_at'] = getDashboardSqlTimestamp();
	}

	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare(
			'INSERT INTO roles (' . implode(', ', $columns) . ')
			 VALUES (' . implode(', ', $placeholders) . ')'
		);
		$stmt->execute($params);
		$roleId = (int)$pdo->lastInsertId();
		$pdo->commit();
	} catch (PDOException $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		if ((string)$e->getCode() === '23000') {
			return ['success' => false, 'message' => 'A role with that name already exists'];
		}
		return ['success' => false, 'message' => 'Failed to create role'];
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to create role'];
	}

	return [
		'success' => true,
		'role' => getRoleById($pdo, $roleId),
		'roles' => getAvailableRoles($pdo),
	];
}


function updateAdminRole(PDO $pdo, $targetRoleId, array $updates) {
	$roleId = (int)$targetRoleId;
	if ($roleId < 1) {
		return ['success' => false, 'message' => 'A valid role id is required'];
	}
	if (!dashboardTableExists($pdo, 'roles')) {
		return ['success' => false, 'message' => 'Roles table is not available'];
	}

	$existingRole = getRoleById($pdo, $roleId);
	if (!$existingRole) {
		return ['success' => false, 'message' => 'Role not found'];
	}

	$fields = [];
	$params = [':id' => $roleId];

	if (array_key_exists('name', $updates)) {
		$normalizedName = trim((string)$updates['name']);
		if ($normalizedName === '') {
			return ['success' => false, 'message' => 'Role name is required'];
		}
		if (strlen($normalizedName) > 50) {
			return ['success' => false, 'message' => 'Role name must not exceed 50 characters'];
		}
		$fields[] = 'name = :name';
		$params[':name'] = substr($normalizedName, 0, 50);
	}

	if (array_key_exists('description', $updates)) {
		$normalizedDescription = trim((string)$updates['description']);
		if (strlen($normalizedDescription) > 255) {
			return ['success' => false, 'message' => 'Role description must not exceed 255 characters'];
		}
		if (doesTableColumnExist($pdo, 'roles', 'description')) {
			$fields[] = 'description = :description';
			$params[':description'] = $normalizedDescription !== '' ? substr($normalizedDescription, 0, 255) : null;
		}
	}

	if (empty($fields)) {
		return ['success' => false, 'message' => 'No updates were provided'];
	}

	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare('UPDATE roles SET ' . implode(', ', $fields) . ' WHERE id = :id');
		$stmt->execute($params);
		$pdo->commit();
	} catch (PDOException $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		if ((string)$e->getCode() === '23000') {
			return ['success' => false, 'message' => 'A role with that name already exists'];
		}
		return ['success' => false, 'message' => 'Failed to update role'];
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to update role'];
	}

	return [
		'success' => true,
		'role' => getRoleById($pdo, $roleId),
		'roles' => getAvailableRoles($pdo),
	];
}


function deleteAdminRole(PDO $pdo, $targetRoleId) {
	$roleId = (int)$targetRoleId;
	if ($roleId < 1) {
		return ['success' => false, 'message' => 'A valid role id is required'];
	}
	if (!dashboardTableExists($pdo, 'roles')) {
		return ['success' => false, 'message' => 'Roles table is not available'];
	}

	$deletedRole = getRoleById($pdo, $roleId);
	if (!$deletedRole) {
		return ['success' => false, 'message' => 'Role not found'];
	}

	$affectedUsers = [];
	if (dashboardTableExists($pdo, 'user_roles')) {
		try {
			$affectedStmt = $pdo->prepare(
				'SELECT DISTINCT user_id
				 FROM user_roles
				 WHERE role_id = :role_id
				 ORDER BY user_id ASC'
			);
			$affectedStmt->execute([':role_id' => $roleId]);
			$affectedUsers = array_map(static function($row) {
				return (int)($row['user_id'] ?? 0);
			}, $affectedStmt->fetchAll());
		} catch (Throwable $e) {
			$affectedUsers = [];
		}
	}

	$replacementRole = null;
	if (!empty($affectedUsers)) {
		$replacementRole = getRoleReplacementForDeletion($pdo, $roleId);
		if ($replacementRole === null) {
			return ['success' => false, 'message' => 'Unable to delete this role because no replacement role exists for reassignment'];
		}
	}

	$affectedUserRows = [];
	foreach ($affectedUsers as $affectedUserId) {
		$userRow = getUserByIdOrUsername($pdo, (string)$affectedUserId);
		if ($userRow) {
			$affectedUserRows[] = $userRow;
		} else {
			$affectedUserRows[] = [
				'id' => $affectedUserId,
				'email' => '',
				'username' => '',
				'display_name' => '',
				'role_id' => null,
				'role_name' => '',
				'role_description' => '',
			];
		}
	}

	try {
		$pdo->beginTransaction();

		if (dashboardTableExists($pdo, 'role_permissions')) {
			$pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute([':role_id' => $roleId]);
		}

		if (!empty($affectedUsers) && dashboardTableExists($pdo, 'user_roles')) {
			$deleteAssignmentStmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id');
			$insertColumns = ['user_id', 'role_id'];
			$insertPlaceholders = [':user_id', ':role_id'];
			$insertParams = [
				':role_id' => (int)($replacementRole['id'] ?? 0),
			];
			if (doesTableColumnExist($pdo, 'user_roles', 'assigned_by_user_id')) {
				$insertColumns[] = 'assigned_by_user_id';
				$insertPlaceholders[] = ':assigned_by_user_id';
				$insertParams[':assigned_by_user_id'] = null;
			}
			if (doesTableColumnExist($pdo, 'user_roles', 'created_at')) {
				$insertColumns[] = 'created_at';
				$insertPlaceholders[] = ':created_at';
				$insertParams[':created_at'] = getDashboardSqlTimestamp();
			}
			$insertStmt = $pdo->prepare(
				'INSERT IGNORE INTO user_roles (' . implode(', ', $insertColumns) . ')
				 VALUES (' . implode(', ', $insertPlaceholders) . ')'
			);

			foreach ($affectedUsers as $affectedUserId) {
				$deleteAssignmentStmt->execute([
					':user_id' => $affectedUserId,
					':role_id' => $roleId,
				]);
				$insertStmt->execute(array_merge($insertParams, [':user_id' => $affectedUserId]));
			}
		}

		$pdo->prepare('DELETE FROM roles WHERE id = :id')->execute([':id' => $roleId]);
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to delete role'];
	}

	return [
		'success' => true,
		'deleted_role' => $deletedRole,
		'replacement_role' => $replacementRole,
		'affected_users' => $affectedUserRows,
		'roles' => getAvailableRoles($pdo),
	];
}


function getRoleById(PDO $pdo, $roleId) {
	$normalizedRoleId = (int)$roleId;
	if ($normalizedRoleId < 1 || !dashboardTableExists($pdo, 'roles')) {
		return null;
	}

	$stmt = $pdo->prepare('SELECT id, name, description FROM roles WHERE id = :id LIMIT 1');
	$stmt->execute([':id' => $normalizedRoleId]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	return [
		'id' => (int)($row['id'] ?? 0),
		'name' => (string)($row['name'] ?? ''),
		'description' => (string)($row['description'] ?? ''),
	];
}


function getUserPrimaryRole(PDO $pdo, $userId) {
	$normalizedUserId = (int)$userId;
	if ($normalizedUserId < 1 || !dashboardTableExists($pdo, 'user_roles') || !dashboardTableExists($pdo, 'roles')) {
		return null;
	}

	try {
		$stmt = $pdo->prepare(
			'SELECT r.id, r.name, r.description
			 FROM user_roles ur
			 JOIN roles r ON r.id = ur.role_id
			 WHERE ur.user_id = :user_id
			 ORDER BY ur.created_at ASC, ur.role_id ASC
			 LIMIT 1'
		);
		$stmt->execute([':user_id' => $normalizedUserId]);
		$row = $stmt->fetch();
	} catch (Throwable $e) {
		return null;
	}

	if (!$row) {
		return null;
	}

	return [
		'id' => (int)($row['id'] ?? 0),
		'name' => (string)($row['name'] ?? ''),
		'description' => (string)($row['description'] ?? ''),
	];
}


function syncUserPrimaryRole(PDO $pdo, $userId, $roleId, $assignedByUserId = null) {
	$normalizedUserId = (int)$userId;
	$normalizedRoleId = (int)$roleId;
	$normalizedAssignedByUserId = (int)$assignedByUserId;

	if ($normalizedUserId < 1 || $normalizedRoleId < 1) {
		return false;
	}

	if (!dashboardTableExists($pdo, 'user_roles') || !dashboardTableExists($pdo, 'roles')) {
		return false;
	}

	if (getRoleById($pdo, $normalizedRoleId) === null) {
		return false;
	}

	$columns = ['user_id', 'role_id'];
	$placeholders = [':user_id', ':role_id'];
	$params = [
		':user_id' => $normalizedUserId,
		':role_id' => $normalizedRoleId,
	];

	if (doesTableColumnExist($pdo, 'user_roles', 'assigned_by_user_id')) {
		$columns[] = 'assigned_by_user_id';
		$placeholders[] = ':assigned_by_user_id';
		$params[':assigned_by_user_id'] = $normalizedAssignedByUserId > 0 ? $normalizedAssignedByUserId : null;
	}
	if (doesTableColumnExist($pdo, 'user_roles', 'created_at')) {
		$columns[] = 'created_at';
		$placeholders[] = ':created_at';
		$params[':created_at'] = getDashboardSqlTimestamp();
	}

	$deleteStmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
	$insertStmt = $pdo->prepare(
		'INSERT INTO user_roles (' . implode(', ', $columns) . ')
		 VALUES (' . implode(', ', $placeholders) . ')'
	);

	$deleteStmt->execute([':user_id' => $normalizedUserId]);
	return $insertStmt->execute($params);
}


function getUserByIdOrUsername(PDO $pdo, $lookupQuery) {
	$query = normalizeDevToolsUserLookupQuery($lookupQuery);
	if ($query === '') {
		return null;
	}

	$idCandidate = ctype_digit($query) ? (int)$query : 0;
	$stmt = $pdo->prepare(
		'SELECT id, email, username, display_name, status, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at
		 FROM users
		 WHERE deleted_at IS NULL
		   AND (id = :id_candidate OR username = :username_candidate OR email = :email_candidate)
		 LIMIT 1'
	);
	$stmt->execute([
		':id_candidate' => $idCandidate,
		':username_candidate' => $query,
		':email_candidate' => $query,
	]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	$primaryRole = getUserPrimaryRole($pdo, (int)$row['id']);

	return [
		'id' => (int)$row['id'],
		'email' => (string)($row['email'] ?? ''),
		'username' => (string)($row['username'] ?? ''),
		'display_name' => (string)($row['display_name'] ?? ''),
		'status' => (string)($row['status'] ?? ''),
		'created_at' => (string)($row['created_at'] ?? ''),
		'role_id' => isset($primaryRole['id']) ? (int)$primaryRole['id'] : null,
		'role_name' => isset($primaryRole['name']) ? (string)$primaryRole['name'] : '',
		'role_description' => isset($primaryRole['description']) ? (string)$primaryRole['description'] : '',
	];
}


function getAdminUserListPayload(PDO $pdo, $statusFilter = 'all') {
	if (!dashboardTableExists($pdo, 'users')) {
		return [
			'success' => true,
			'users' => [],
			'status_filter' => 'all',
		];
	}

	$normalizedStatusFilter = strtolower(trim((string)$statusFilter));
	if (!in_array($normalizedStatusFilter, ['all', 'active', 'disabled'], true)) {
		$normalizedStatusFilter = 'all';
	}

	$whereClauses = ['u.deleted_at IS NULL'];
	$params = [];
	if ($normalizedStatusFilter !== 'all' && doesTableColumnExist($pdo, 'users', 'status')) {
		$whereClauses[] = 'u.status = :status';
		$params[':status'] = $normalizedStatusFilter;
	}

	$selectColumns = [
		'u.id',
		'u.username',
		'u.email',
		'u.display_name',
	];
	if (doesTableColumnExist($pdo, 'users', 'status')) {
		$selectColumns[] = 'u.status';
	} else {
		$selectColumns[] = "'' AS status";
	}
	if (doesTableColumnExist($pdo, 'users', 'last_login_at')) {
		$selectColumns[] = 'DATE_FORMAT(u.last_login_at, "%Y-%m-%d %H:%i:%s") AS last_login_at';
	} else {
		$selectColumns[] = 'NULL AS last_login_at';
	}

	try {
		$stmt = $pdo->prepare(
			'SELECT ' . implode(', ', $selectColumns) . '
			 FROM users u
			 WHERE ' . implode(' AND ', $whereClauses) . '
			 ORDER BY u.id ASC'
		);
		$stmt->execute($params);
		$rows = $stmt->fetchAll();
	} catch (Throwable $e) {
		$rows = [];
	}

	$users = array_map(static function($row) {
		return [
			'id' => (int)($row['id'] ?? 0),
			'username' => (string)($row['username'] ?? ''),
			'email' => (string)($row['email'] ?? ''),
			'display_name' => (string)($row['display_name'] ?? ''),
			'status' => strtolower((string)($row['status'] ?? '')),
			'last_login_at' => (string)($row['last_login_at'] ?? ''),
		];
	}, is_array($rows) ? $rows : []);

	return [
		'success' => true,
		'users' => $users,
		'status_filter' => $normalizedStatusFilter,
	];
}


function createDevToolsUser(PDO $pdo, $email, $username, $displayName, $status, $roleId, $assignedByUserId = null) {
	$normalizedEmail = trim((string)$email);
	$normalizedUsername = trim((string)$username);
	$normalizedDisplayName = trim((string)$displayName);
	$normalizedStatus = strtolower(trim((string)$status));
	$normalizedRoleId = (int)$roleId;
	$normalizedAssignedByUserId = (int)$assignedByUserId;

	if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
		return ['success' => false, 'message' => 'A valid email is required'];
	}
	if ($normalizedUsername === '') {
		return ['success' => false, 'message' => 'Username is required'];
	}
	if (!in_array($normalizedStatus, ['active', 'disabled'], true)) {
		$normalizedStatus = 'active';
	}
	if ($normalizedRoleId < 1 || getRoleById($pdo, $normalizedRoleId) === null) {
		return ['success' => false, 'message' => 'A valid role is required'];
	}

	$passwordPlain = generateRandomPasswordPlaintext(16);
	$passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT, ['cost' => 12]);
	$now = getDashboardSqlTimestamp();
	$userId = 0;

	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare(
			'INSERT INTO users (email, username, password_hash, display_name, status, created_at, updated_at, deleted_at)
			 VALUES (:email, :username, :password_hash, :display_name, :status, :created_at, :updated_at, NULL)'
		);
		$stmt->execute([
			':email' => substr($normalizedEmail, 0, 255),
			':username' => substr($normalizedUsername, 0, 100),
			':password_hash' => $passwordHash,
			':display_name' => $normalizedDisplayName !== '' ? substr($normalizedDisplayName, 0, 150) : null,
			':status' => $normalizedStatus,
			':created_at' => $now,
			':updated_at' => $now,
		]);

		$userId = (int)$pdo->lastInsertId();
		if (!syncUserPrimaryRole($pdo, $userId, $normalizedRoleId, $normalizedAssignedByUserId > 0 ? $normalizedAssignedByUserId : null)) {
			throw new RuntimeException('Failed to assign role');
		}
		$pdo->commit();
	} catch (PDOException $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}

		$errorCode = (string)($e->getCode() ?? '');
		if ($errorCode === '23000') {
			return ['success' => false, 'message' => 'Failed to create user (email/username may already exist)'];
		}

		return ['success' => false, 'message' => 'Failed to create user'];
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to create user'];
	}

	try {
		ensureDefaultHeaderWidgetPreferencesForUser($pdo, $userId);
	} catch (Throwable $e) {
	}

	return [
		'success' => true,
		'user_id' => $userId,
		'generated_password' => $passwordPlain,
	];
}


function updateDevToolsUser(PDO $pdo, $targetUserId, array $updates, $resetPassword, $assignedByUserId = null) {
	$userId = (int)$targetUserId;
	if ($userId < 1) {
		return ['success' => false, 'message' => 'A valid user id is required'];
	}

	$checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
	$checkStmt->execute([':id' => $userId]);
	if (!$checkStmt->fetch()) {
		return ['success' => false, 'message' => 'User not found'];
	}

	$fields = [];
	$params = [':id' => $userId, ':updated_at' => getDashboardSqlTimestamp()];
	$normalizedRoleId = null;
	if (array_key_exists('role_id', $updates)) {
		$normalizedRoleId = (int)$updates['role_id'];
		if ($normalizedRoleId < 1 || getRoleById($pdo, $normalizedRoleId) === null) {
			return ['success' => false, 'message' => 'A valid role is required'];
		}
	}

	if (array_key_exists('email', $updates)) {
		$email = trim((string)$updates['email']);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['success' => false, 'message' => 'A valid email is required'];
		}
		$fields[] = 'email = :email';
		$params[':email'] = substr($email, 0, 255);
	}

	if (array_key_exists('username', $updates)) {
		$username = trim((string)$updates['username']);
		if ($username === '') {
			return ['success' => false, 'message' => 'Username is required'];
		}
		$fields[] = 'username = :username';
		$params[':username'] = substr($username, 0, 100);
	}

	if (array_key_exists('display_name', $updates)) {
		$displayName = trim((string)$updates['display_name']);
		$fields[] = 'display_name = :display_name';
		$params[':display_name'] = $displayName !== '' ? substr($displayName, 0, 150) : null;
	}

	if (array_key_exists('status', $updates)) {
		$status = strtolower(trim((string)$updates['status']));
		if (!in_array($status, ['active', 'disabled'], true)) {
			return ['success' => false, 'message' => 'Status must be active or disabled'];
		}
		$fields[] = 'status = :status';
		$params[':status'] = $status;
	}

	$generatedPassword = null;
	if ($resetPassword) {
		$generatedPassword = generateRandomPasswordPlaintext(16);
		$fields[] = 'password_hash = :password_hash';
		$params[':password_hash'] = password_hash($generatedPassword, PASSWORD_BCRYPT, ['cost' => 12]);
	}

	if (empty($fields) && $normalizedRoleId === null) {
		return ['success' => false, 'message' => 'No updates were provided'];
	}

	$fields[] = 'updated_at = :updated_at';
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
		$stmt->execute($params);
		if ($normalizedRoleId !== null && !syncUserPrimaryRole($pdo, $userId, $normalizedRoleId, (int)$assignedByUserId > 0 ? (int)$assignedByUserId : null)) {
			throw new RuntimeException('Failed to assign role');
		}
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to update user (email/username may already exist)'];
	}

	return [
		'success' => true,
		'generated_password' => $generatedPassword,
	];
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

function resetUserSessionsTable(PDO $pdo) {
	$pdo->exec('TRUNCATE TABLE user_sessions');
}

function resetNotificationsTable(PDO $pdo) {
	if (!dashboardTableExists($pdo, 'notifications')) {
		return true;
	}

	$pdo->exec('TRUNCATE TABLE notifications');
	return true;
}

function resetDevToolsDataTables(PDO $pdo) {
	resetActivityLogTable($pdo);
	resetAuditLogEntries($pdo);
	return resetRecordsTableToSample($pdo);
}

function resetUsersExceptCurrentUser(PDO $pdo, $keepUserId) {
	$normalizedKeepUserId = (int)$keepUserId;
	if ($normalizedKeepUserId < 1 || !dashboardTableExists($pdo, 'users')) {
		return 0;
	}

	try {
		$stmt = $pdo->prepare(
			'SELECT id
			 FROM users
			 WHERE deleted_at IS NULL
			   AND id <> :keep_user_id
			 ORDER BY id ASC'
		);
		$stmt->execute([':keep_user_id' => $normalizedKeepUserId]);
		$userRows = $stmt->fetchAll();
	} catch (Throwable $e) {
		return 0;
	}

	$deletedCount = 0;
	foreach ($userRows as $row) {
		$targetUserId = (int)($row['id'] ?? 0);
		if ($targetUserId < 1) {
			continue;
		}
		$result = forceDeleteUserHard($pdo, $targetUserId, $normalizedKeepUserId);
		if (is_array($result) && ($result['success'] ?? false)) {
			$deletedCount++;
		}
	}

	return $deletedCount;
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

function seedDevToolsSampleData(PDO $pdo, array $options = []) {
	$recordCount = max(1, (int)($options['record_count'] ?? 150));
	$activityCount = max(1, (int)($options['activity_count'] ?? 150));
	$auditCount = max(1, (int)($options['audit_count'] ?? 150));
	$userCount = max(1, (int)($options['user_count'] ?? 10));
	$seedPrefix = trim((string)($options['seed_prefix'] ?? 'devtools_sample'));
	if ($seedPrefix === '') {
		$seedPrefix = 'devtools_sample';
	}
	$seedPrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $seedPrefix);
	if (!is_string($seedPrefix) || $seedPrefix === '') {
		$seedPrefix = 'devtools_sample';
	}
	$seedRunId = date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
	$actorUserId = (int)($options['actor_user_id'] ?? 0);
	$timelineNow = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
	$buildSeedTimestamp = static function (int $index, int $total, int $startDaysAgo, int $endDaysAgo, int $offsetSeed = 0) use ($timelineNow) {
		$safeTotal = max(1, $total);
		$safeIndex = max(1, min($index, $safeTotal));
		$progress = $safeTotal > 1 ? (($safeIndex - 1) / ($safeTotal - 1)) : 0.0;
		$daysBack = (int)round($startDaysAgo - (($startDaysAgo - $endDaysAgo) * $progress));
		$hoursBack = ($offsetSeed + ($safeIndex * 5)) % 24;
		$minutesBack = ($offsetSeed + ($safeIndex * 13)) % 60;
		$secondsBack = ($offsetSeed + ($safeIndex * 17)) % 60;
		return $timelineNow->modify(sprintf('-%d days -%d hours -%d minutes -%d seconds', $daysBack, $hoursBack, $minutesBack, $secondsBack))->format('Y-m-d H:i:s');
	};
	$sampleUserIds = [];
	$sampleRecordIds = [];
	$sampleUsers = [];
	$sampleRecords = [];
	$samplePeople = [
		['first_name' => 'Avery', 'last_name' => 'Collins', 'display_name' => 'Avery Collins'],
		['first_name' => 'Jordan', 'last_name' => 'Lee', 'display_name' => 'Jordan Lee'],
		['first_name' => 'Morgan', 'last_name' => 'Patel', 'display_name' => 'Morgan Patel'],
		['first_name' => 'Casey', 'last_name' => 'Nguyen', 'display_name' => 'Casey Nguyen'],
		['first_name' => 'Riley', 'last_name' => 'Turner', 'display_name' => 'Riley Turner'],
		['first_name' => 'Taylor', 'last_name' => 'Brooks', 'display_name' => 'Taylor Brooks'],
		['first_name' => 'Quinn', 'last_name' => 'Santos', 'display_name' => 'Quinn Santos'],
		['first_name' => 'Parker', 'last_name' => 'Adams', 'display_name' => 'Parker Adams'],
		['first_name' => 'Hayden', 'last_name' => 'Foster', 'display_name' => 'Hayden Foster'],
		['first_name' => 'Emerson', 'last_name' => 'Reed', 'display_name' => 'Emerson Reed'],
	];
	$sampleRecordTemplates = [
		['title' => 'Quarterly Access Review', 'description' => 'Review of current access assignments and pending approvals.'],
		['title' => 'Invoice Dispute Follow-Up', 'description' => 'Notes from the latest review of a billing discrepancy and vendor response.'],
		['title' => 'Client Onboarding Checklist', 'description' => 'Status update for onboarding tasks, ownership, and open action items.'],
		['title' => 'Inventory Reconciliation', 'description' => 'Comparison of expected and counted stock levels with follow-up notes.'],
		['title' => 'Support Escalation Review', 'description' => 'Summary of recent support cases that need manager review.'],
		['title' => 'Training Completion Audit', 'description' => 'Current completion status for assigned training modules and overdue items.'],
		['title' => 'Compliance Exception Log', 'description' => 'Documented exception review with resolution notes and next steps.'],
		['title' => 'Scheduling Adjustment Request', 'description' => 'Requested shift changes and coverage updates for the current cycle.'],
		['title' => 'Vendor Follow-Up Notes', 'description' => 'Summary of vendor communication, open questions, and agreed actions.'],
		['title' => 'Operations Status Update', 'description' => 'General operational status notes for the current workstream.'],
	];
	$sampleNotificationTopics = [
		'Quarterly access review reminder',
		'Invoice follow-up reminder',
		'Onboarding checklist review',
		'Inventory reconciliation update',
		'Support escalation status',
		'Training completion reminder',
		'Compliance exception follow-up',
		'Scheduling adjustment notice',
		'Vendor follow-up summary',
		'Operations status check-in',
	];

	if (!dashboardTableExists($pdo, 'records') || !dashboardTableExists($pdo, 'activity_log') || !dashboardTableExists($pdo, 'audit_log') || !dashboardTableExists($pdo, 'users')) {
		return ['success' => false, 'message' => 'Required tables are not available'];
	}

	$roles = getAvailableRoles($pdo);
	$sampleRoles = array_values(array_filter($roles, static function($role) {
		return strcasecmp((string)($role['name'] ?? ''), 'Guest') !== 0;
	}));
	if (empty($sampleRoles)) {
		$sampleRoles = $roles;
	}
	if (empty($sampleRoles)) {
		return ['success' => false, 'message' => 'No roles are available for sample users'];
	}

	try {
		for ($index = 1; $index <= $userCount; $index++) {
			$roleIndex = ($index - 1) % count($sampleRoles);
			$roleId = (int)($sampleRoles[$roleIndex]['id'] ?? 0);
			$person = $samplePeople[($index - 1) % count($samplePeople)];
			$status = ($index % 4 === 0) ? 'disabled' : 'active';
			$userSuffix = str_pad((string)$index, 2, '0', STR_PAD_LEFT);
			$personSlug = strtolower($person['first_name'] . '.' . $person['last_name']);
			$email = $personSlug . '.' . $seedRunId . '@example.test';
			$username = $personSlug . '.' . substr($seedRunId, -8) . '.' . $userSuffix;
			$createdAt = $buildSeedTimestamp($index, $userCount, 330, 220, 7);
			$lastLoginAt = $buildSeedTimestamp($index, $userCount, 90, 4, 19);
			$created = createDevToolsUser(
				$pdo,
				$email,
				$username,
				$person['display_name'],
				$status,
				$roleId,
				$actorUserId > 0 ? $actorUserId : null
			);
			if (empty($created['success'])) {
				throw new RuntimeException((string)($created['message'] ?? 'Failed to create sample users'));
			}
			$userId = (int)($created['user_id'] ?? 0);
			$sampleUserIds[] = $userId;
			$sampleUsers[] = [
				'id' => $userId,
				'email' => $email,
				'username' => $username,
				'display_name' => $person['display_name'],
				'status' => $status,
				'created_at' => $createdAt,
				'last_login_at' => $lastLoginAt,
			];
			$updateSql = 'UPDATE users SET created_at = :created_at, updated_at = :updated_at';
			$updateParams = [
				':created_at' => $createdAt,
				':updated_at' => $createdAt,
				':id' => $userId,
			];
			if (doesTableColumnExist($pdo, 'users', 'last_login_at')) {
				$updateSql .= ', last_login_at = :last_login_at';
				$updateParams[':last_login_at'] = $lastLoginAt;
			}
			$updateSql .= ' WHERE id = :id';
			$pdo->prepare($updateSql)->execute($updateParams);
		}

		if (empty($sampleUserIds)) {
			return ['success' => false, 'message' => 'No sample users were created'];
		}

		$hasCreatedByUserId = doesTableColumnExist($pdo, 'records', 'created_by_user_id');
		$hasUpdatedByUserId = doesTableColumnExist($pdo, 'records', 'updated_by_user_id');
		$hasCreatedAt = doesTableColumnExist($pdo, 'records', 'created_at');
		$hasUpdatedAt = doesTableColumnExist($pdo, 'records', 'updated_at');

		$recordColumns = ['title', 'description'];
		$recordPlaceholders = [':title', ':description'];
		if ($hasCreatedByUserId) {
			$recordColumns[] = 'created_by_user_id';
			$recordPlaceholders[] = ':created_by_user_id';
		}
		if ($hasUpdatedByUserId) {
			$recordColumns[] = 'updated_by_user_id';
			$recordPlaceholders[] = ':updated_by_user_id';
		}
		if ($hasCreatedAt) {
			$recordColumns[] = 'created_at';
			$recordPlaceholders[] = ':created_at';
		}
		if ($hasUpdatedAt) {
			$recordColumns[] = 'updated_at';
			$recordPlaceholders[] = ':updated_at';
		}

		$insertRecordStmt = $pdo->prepare('INSERT INTO records (' . implode(', ', $recordColumns) . ') VALUES (' . implode(', ', $recordPlaceholders) . ')');
		for ($index = 1; $index <= $recordCount; $index++) {
			$template = $sampleRecordTemplates[($index - 1) % count($sampleRecordTemplates)];
			$sampleUserId = $sampleUserIds[($index - 1) % count($sampleUserIds)];
			$recordSuffix = str_pad((string)$index, 3, '0', STR_PAD_LEFT);
			$recordCreatedAt = $buildSeedTimestamp($index, $recordCount, 310, 45, 11);
			$recordUpdatedAt = $buildSeedTimestamp($index, $recordCount, 305, 6, 13);
			$recordTitle = $template['title'] . ' ' . $recordSuffix;
			$recordDescription = $template['description'];
			$recordUpdatedTitle = $recordTitle . ' - Reviewed';
			$recordUpdatedDescription = $recordDescription . ' Follow-up completed.';
			$insertParams = [
				':title' => $recordTitle,
				':description' => $recordDescription,
			];
			if ($hasCreatedByUserId) {
				$insertParams[':created_by_user_id'] = $sampleUserId;
			}
			if ($hasUpdatedByUserId) {
				$insertParams[':updated_by_user_id'] = $sampleUserId;
			}
			if ($hasCreatedAt) {
				$insertParams[':created_at'] = $recordCreatedAt;
			}
			if ($hasUpdatedAt) {
				$insertParams[':updated_at'] = $recordUpdatedAt;
			}
			$insertRecordStmt->execute($insertParams);
			$recordId = (int)$pdo->lastInsertId();
			$sampleRecordIds[] = $recordId;
			$sampleRecords[] = [
				'id' => $recordId,
				'title' => $recordTitle,
				'description' => $recordDescription,
				'updated_title' => $recordUpdatedTitle,
				'updated_description' => $recordUpdatedDescription,
				'created_by_user_id' => $sampleUserId,
			];
		}

		$insertActivityStmt = $pdo->prepare(
			'INSERT INTO activity_log (event_type, message, related_record_type, related_record_id, source_user_id, ip_address, created_at)
			 VALUES (:event_type, :message, :related_record_type, :related_record_id, :source_user_id, :ip_address, :created_at)'
		);
		for ($index = 1; $index <= $activityCount; $index++) {
			$sampleUser = $sampleUsers[($index - 1) % count($sampleUsers)];
			$sampleRecord = $sampleRecords[($index - 1) % count($sampleRecords)];
			$activityCreatedAt = $buildSeedTimestamp($index, $activityCount, 300, 2, 23);
			$activityMode = ($index - 1) % 5;
			$activityMessage = '';
			$activityEventType = 'record_updated';
			$activityRelatedRecordId = $sampleRecord['id'];
			switch ($activityMode) {
				case 0:
					$activityEventType = 'record_created';
					$activityMessage = 'A new entry has been added; ID ' . $sampleRecord['id'] . ' with title: "' . $sampleRecord['title'] . '", and description: "' . $sampleRecord['description'] . '"';
					break;
				case 1:
					$activityEventType = 'record_updated';
					$activityMessage = 'An entry has been edited; ID ' . $sampleRecord['id'] . ' with title: "' . $sampleRecord['updated_title'] . '", and description: "' . $sampleRecord['updated_description'] . '"';
					break;
				case 2:
					$activityEventType = 'record_deleted';
					$activityMessage = 'An entry has been deleted; ID ' . $sampleRecord['id'] . ' with title: "' . $sampleRecord['title'] . '", and description: "' . $sampleRecord['description'] . '"';
					break;
				case 3:
					$activityEventType = 'record_bulk_created';
					$bulkCreateCount = min(12, max(3, count($sampleRecords)));
					$activityRelatedRecordId = null;
					$activityMessage = 'Bulk CSV import completed for ' . $bulkCreateCount . ' records';
					break;
				case 4:
					$activityEventType = 'record_bulk_deleted';
					$activityRelatedRecordId = null;
					$bulkIds = array_slice($sampleRecordIds, max(0, $index - 3), 3);
					if (empty($bulkIds)) {
						$bulkIds = array_slice($sampleRecordIds, 0, min(3, count($sampleRecordIds)));
					}
					$activityMessage = 'Bulk delete completed for ' . count($bulkIds) . ' records (IDs: ' . implode(', ', $bulkIds) . ')';
					break;
			}
			$insertActivityStmt->execute([
				':event_type' => $activityEventType,
				':message' => $activityMessage,
				':related_record_type' => 'record',
				':related_record_id' => $activityRelatedRecordId,
				':source_user_id' => $sampleUser['id'],
				':ip_address' => '127.0.0.1',
				':created_at' => $activityCreatedAt,
			]);
		}

		$insertAuditStmt = $pdo->prepare(
			'INSERT INTO audit_log (record_type, record_id, dataset, action, details, source_user_id, target_user_id, ip_address, user_agent, created_at)
			 VALUES (:record_type, :record_id, :dataset, :action, :details, :source_user_id, :target_user_id, :ip_address, :user_agent, :created_at)'
		);
		for ($index = 1; $index <= $auditCount; $index++) {
			$auditCreatedAt = $buildSeedTimestamp($index, $auditCount, 340, 1, 31);
			$sampleUser = $sampleUsers[($index - 1) % count($sampleUsers)];
			$targetUser = $sampleUsers[$index % count($sampleUsers)];
			$sampleRecord = $sampleRecords[($index - 1) % count($sampleRecords)];
			$auditMode = ($index - 1) % 10;
			$recordType = 'record';
			$recordId = $sampleRecord['id'];
			$dataset = 'records';
			$action = 'create';
			$detail = '';
			$sourceUserId = $sampleUser['id'];
			$targetUserId = null;
			switch ($auditMode) {
				case 0:
					$recordType = 'users';
					$dataset = 'users';
					$action = 'create';
					$recordId = $sampleUser['id'];
					$detail = 'Created user ' . $sampleUser['username'] . ' / ' . $sampleUser['display_name'] . ' with ID ' . $sampleUser['id'];
					break;
				case 1:
					$recordType = 'record';
					$dataset = 'records';
					$action = 'create';
					$detail = 'Title:  -> ' . $sampleRecord['title'] . ', Description:  -> ' . $sampleRecord['description'];
					break;
				case 2:
					$recordType = 'record';
					$dataset = 'records';
					$action = 'update';
					$detail = 'Title: ' . $sampleRecord['title'] . ' -> ' . $sampleRecord['updated_title'] . ', Description: ' . $sampleRecord['description'] . ' -> ' . $sampleRecord['updated_description'];
					break;
				case 3:
					$recordType = 'record';
					$dataset = 'records';
					$action = 'delete';
					$detail = 'Title: ' . $sampleRecord['title'] . ' -> (deleted), Description: ' . $sampleRecord['description'] . ' -> (deleted)';
					break;
				case 4:
					$recordType = 'users';
					$dataset = 'users';
					$action = 'update';
					$updatedEmail = preg_replace('/@example\.test$/', '.updated@example.test', $sampleUser['email']);
					if (!is_string($updatedEmail) || $updatedEmail === '') {
						$updatedEmail = $sampleUser['email'] . '.updated';
					}
					$updatedUsername = $sampleUser['username'] . '.reviewed';
					$updatedDisplayName = $sampleUser['display_name'] . ' (Updated)';
					$updatedStatus = $sampleUser['status'] === 'active' ? 'Disabled' : 'Active';
					$detail = 'Updated user ID ' . $sampleUser['id'] . ' - Email: ' . $sampleUser['email'] . ' -> ' . $updatedEmail . '; Username: ' . $sampleUser['username'] . ' -> ' . $updatedUsername . '; Display Name: ' . $sampleUser['display_name'] . ' -> ' . $updatedDisplayName . '; Status: ' . ucfirst($sampleUser['status']) . ' -> ' . $updatedStatus . '; Password reset: No';
					break;
				case 5:
					$recordType = 'auth';
					$dataset = 'user_sessions';
					$action = 'login';
					$recordId = null;
					$detail = 'Signed out -> Signed in successfully';
					break;
				case 6:
					$recordType = 'auth';
					$dataset = 'user_sessions';
					$action = 'logout';
					$recordId = null;
					$detail = 'Signed in -> Signed out';
					break;
				case 7:
					$recordType = 'notification';
					$dataset = 'notifications';
					$action = 'notification_sent';
					$recordId = 10000 + $index;
					$notificationTitle = $sampleNotificationTopics[($index - 1) % count($sampleNotificationTopics)];
					$targetUserId = $targetUser['id'];
					$detail = 'Notification (' . $notificationTitle . '): Sent by user #' . $sourceUserId . ' to user #' . $targetUserId;
					break;
				case 8:
					$recordType = 'notification';
					$dataset = 'notifications';
					$action = 'notification_read';
					$recordId = 10000 + $index;
					$notificationTitle = $sampleNotificationTopics[($index - 1) % count($sampleNotificationTopics)];
					$sourceUserId = $targetUser['id'];
					$targetUserId = null;
					$detail = 'Notification (' . $notificationTitle . '): Unread -> Read';
					break;
				case 9:
					$recordType = 'notification';
					$dataset = 'notifications';
					$action = 'notification_deleted';
					$recordId = 10000 + $index;
					$notificationTitle = $sampleNotificationTopics[($index - 1) % count($sampleNotificationTopics)];
					$sourceUserId = $targetUser['id'];
					$targetUserId = null;
					$detail = 'Notification (' . $notificationTitle . '): existed -> deleted';
					break;
			}
			$insertAuditStmt->execute([
				':record_type' => $recordType,
				':record_id' => $recordId,
				':dataset' => $dataset,
				':action' => $action,
				':details' => $detail,
				':source_user_id' => $sourceUserId,
				':target_user_id' => $targetUserId,
				':ip_address' => '127.0.0.1',
				':user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
				':created_at' => $auditCreatedAt,
			]);
		}
	} catch (Throwable $e) {
		return ['success' => false, 'message' => 'Failed to seed sample data'];
	}

	return [
		'success' => true,
		'prefix' => $seedPrefix,
		'run_id' => $seedRunId,
		'users_created' => count($sampleUserIds),
		'records_created' => count($sampleRecordIds),
		'activity_entries_created' => $activityCount,
		'audit_entries_created' => $auditCount,
	];
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

function writeAuditEvent(PDO $pdo, array $entry) {
	ensureAuditLogSchema($pdo);

	$recordType = trim((string)($entry['record_type'] ?? 'system'));
	$action = trim((string)($entry['action'] ?? 'event'));
	$datasetRaw = trim((string)($entry['dataset'] ?? ''));
	$recordIdRaw = $entry['record_id'] ?? null;
	$recordId = is_numeric($recordIdRaw) ? (int)$recordIdRaw : null;
	$sourceUserIdRaw = $entry['source_user_id'] ?? null;
	$sourceUserId = is_numeric($sourceUserIdRaw) ? (int)$sourceUserIdRaw : null;
	$targetUserIdRaw = $entry['target_user_id'] ?? null;
	$targetUserId = is_numeric($targetUserIdRaw) ? (int)$targetUserIdRaw : null;
	$details = isset($entry['details']) ? (string)$entry['details'] : '';
	$dataset = $datasetRaw;
	if ($dataset === '') {
		$typeKey = strtolower($recordType);
		if ($typeKey === 'record') {
			$dataset = 'records';
		} elseif ($typeKey === 'notification') {
			$dataset = 'notifications';
		} elseif ($typeKey === 'users' || $typeKey === 'user') {
			$dataset = 'users';
		} elseif ($typeKey === 'role' || $typeKey === 'roles') {
			$dataset = 'roles';
		} elseif ($typeKey === 'auth') {
			$dataset = 'user_sessions';
		}
	}

	$stmt = $pdo->prepare(
		'INSERT INTO audit_log (record_type, record_id, dataset, action, details, source_user_id, target_user_id, ip_address, user_agent, created_at)
		 VALUES (:record_type, :record_id, :dataset, :action, :details, :source_user_id, :target_user_id, :ip_address, :user_agent, :created_at)'
	);

	return $stmt->execute([
		':record_type' => $recordType !== '' ? substr($recordType, 0, 50) : 'system',
		':record_id' => $recordId,
		':dataset' => $dataset !== '' ? substr($dataset, 0, 100) : null,
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

