<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/includes/sql_helpers.php';
require_once __DIR__ . '/includes/auth.php';

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';


function respondJson($statusCode, $payload) {
	http_response_code($statusCode);
	echo json_encode($payload);
	exit;
}


function getHeaderValue($headerName) {
	$serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
	if (isset($_SERVER[$serverKey])) {
		return trim((string)$_SERVER[$serverKey]);
	}

	if (function_exists('getallheaders')) {
		$headers = getallheaders();
		if (is_array($headers)) {
			foreach ($headers as $name => $value) {
				if (strcasecmp($name, $headerName) === 0) {
					return trim((string)$value);
				}
			}
		}
	}

	return '';
}


function getProvidedApiKey() {
	$headerApiKey = getHeaderValue(API_KEY_HEADER);
	if ($headerApiKey !== '') {
		return $headerApiKey;
	}

	$authorization = getHeaderValue('Authorization');
	if (stripos($authorization, 'Bearer ') === 0) {
		return trim(substr($authorization, 7));
	}

	return '';
}


function requireApiKeyAuthentication() {
	$expectedApiKey = trim((string)API_KEY);
	if ($expectedApiKey === '') {
		respondJson(500, ['success' => false, 'message' => 'Server API key is not configured']);
	}

	$providedApiKey = getProvidedApiKey();
	if ($providedApiKey === '' || !hash_equals($expectedApiKey, $providedApiKey)) {
		respondJson(401, ['success' => false, 'message' => 'Unauthorized: valid API key required']);
	}
}


function requireApiSessionAuthentication() {
	startAuthSession();
	$user = getAuthUser();
	if ($user !== null) {
		$GLOBALS['auth_user'] = $user;
		return;
	}

	$reason = getAuthInvalidationReason();
	if ($reason === 'replaced') {
		$_SESSION['auth_flash_toast'] = [
			'type' => 'warning',
			'title' => 'Signed Out',
			'message' => 'You were signed out because this account logged in on another device.',
		];
		clearAuthSessionState();
		respondJson(401, [
			'success' => false,
			'message' => 'Session ended because this account was used to log in on another device.',
			'reason' => 'session_replaced',
		]);
	}

	if ($reason === 'expired') {
		$_SESSION['auth_flash_toast'] = [
			'type' => 'info',
			'title' => 'Session Expired',
			'message' => 'Your session expired. Please sign in again.',
		];
		clearAuthSessionState();
		respondJson(401, [
			'success' => false,
			'message' => 'Session expired. Please sign in again.',
			'reason' => 'session_expired',
		]);
	}

	clearAuthSessionState();
	respondJson(401, [
		'success' => false,
		'message' => 'Authentication required.',
		'reason' => 'unauthenticated',
	]);
}


function normalizeRecordText($value) {
	return trim((string)$value);
}


function getValidatedPageSize($value) {
	$pageSize = (int)$value;
	$allowed = [10, 20, 25, 50, 100];
	return in_array($pageSize, $allowed, true) ? $pageSize : 25;
}


function getValidatedPage($value) {
	$page = (int)$value;
	return $page > 0 ? $page : 1;
}


function getValidatedSortColumn($value) {
	$allowed = ['id', 'title', 'description'];
	return in_array($value, $allowed, true) ? $value : 'id';
}


function getValidatedSortOrder($value) {
	return strtolower((string)$value) === 'desc' ? 'desc' : 'asc';
}


function getDataQueryParams() {
	return [
		'search' => trim((string)($_GET['search'] ?? '')),
		'sortColumn' => getValidatedSortColumn($_GET['sortColumn'] ?? 'id'),
		'sortOrder' => getValidatedSortOrder($_GET['sortOrder'] ?? 'asc'),
		'page' => getValidatedPage($_GET['page'] ?? 1),
		'pageSize' => getValidatedPageSize($_GET['pageSize'] ?? 25)
	];
}


function mapChangeTypeToAuditAction($changeType) {
	$type = strtoupper(trim((string)$changeType));
	if ($type === 'ADD') {
		return 'create';
	}
	if ($type === 'EDIT') {
		return 'update';
	}
	if ($type === 'DELETE') {
		return 'delete';
	}
	if ($type === 'BULK_DELETE') {
		return 'bulk_delete';
	}
	if ($type === 'RESET') {
		return 'reset';
	}
	return 'update';
}


function mapAuditActionToChangeType($action, $fieldName = '') {
	$action = strtolower((string)$action);
	if ($action === 'create') {
		return 'ADD';
	}
	if ($action === 'update') {
		return 'EDIT';
	}
	if ($action === 'delete' || $action === 'bulk_delete') {
		return 'DELETE';
	}
	if ($action === 'reset') {
		return 'RESET';
	}
	if ($action === 'login' || $action === 'logout' || $action === 'password_change') {
		return 'AUTH';
	}
	if (strpos($action, 'notification_') === 0) {
		return 'NOTIFY';
	}
	return strtoupper((string)$action);
}


function getApiAuthUserId() {
	$authUser = $GLOBALS['auth_user'] ?? null;
	$userId = isset($authUser['id']) ? (int)$authUser['id'] : 0;
	if ($userId < 1) {
		respondJson(401, [
			'success' => false,
			'message' => 'Authentication required.',
			'reason' => 'unauthenticated',
		]);
	}
	return $userId;
}


function getApiAuthUserDisplayName() {
	$authUser = $GLOBALS['auth_user'] ?? null;
	if (!is_array($authUser)) {
		return 'A user';
	}

	$displayName = trim((string)($authUser['display_name'] ?? ''));
	if ($displayName !== '') {
		return $displayName;
	}

	$username = trim((string)($authUser['username'] ?? ''));
	if ($username !== '') {
		return $username;
	}

	$email = trim((string)($authUser['email'] ?? ''));
	if ($email !== '') {
		return $email;
	}

	return 'A user';
}


function generateRandomPasswordPlaintext($length = 16) {
	$targetLength = (int)$length;
	if ($targetLength < 12) {
		$targetLength = 12;
	}
	if ($targetLength > 64) {
		$targetLength = 64;
	}

	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^*()-_=+';
	$maxIndex = strlen($alphabet) - 1;
	$result = '';
	for ($i = 0; $i < $targetLength; $i += 1) {
		$randomIndex = random_int(0, $maxIndex);
		$result .= $alphabet[$randomIndex];
	}
	return $result;
}


function normalizeDevToolsUserLookupQuery($value) {
	return trim((string)$value);
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
		   AND (id = :id_candidate OR username = :username_candidate)
		 LIMIT 1'
	);
	$stmt->execute([
		':id_candidate' => $idCandidate,
		':username_candidate' => $query,
	]);
	$row = $stmt->fetch();
	if (!$row) {
		return null;
	}

	return [
		'id' => (int)$row['id'],
		'email' => (string)($row['email'] ?? ''),
		'username' => (string)($row['username'] ?? ''),
		'display_name' => (string)($row['display_name'] ?? ''),
		'status' => (string)($row['status'] ?? ''),
		'created_at' => (string)($row['created_at'] ?? ''),
	];
}


function createDevToolsUser(PDO $pdo, $email, $username, $displayName, $status) {
	$normalizedEmail = trim((string)$email);
	$normalizedUsername = trim((string)$username);
	$normalizedDisplayName = trim((string)$displayName);
	$normalizedStatus = strtolower(trim((string)$status));

	if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
		return ['success' => false, 'message' => 'A valid email is required'];
	}
	if ($normalizedUsername === '') {
		return ['success' => false, 'message' => 'Username is required'];
	}
	if (!in_array($normalizedStatus, ['active', 'disabled'], true)) {
		$normalizedStatus = 'active';
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
		ensureDefaultHeaderWidgetPreferencesForUser($pdo, $userId);
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return ['success' => false, 'message' => 'Failed to create user (email/username may already exist)'];
	}

	return [
		'success' => true,
		'user_id' => $userId,
		'generated_password' => $passwordPlain,
	];
}


function updateDevToolsUser(PDO $pdo, $targetUserId, array $updates, $resetPassword) {
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

	if (empty($fields)) {
		return ['success' => false, 'message' => 'No updates were provided'];
	}

	$fields[] = 'updated_at = :updated_at';
	try {
		$stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
		$stmt->execute($params);
	} catch (Throwable $e) {
		return ['success' => false, 'message' => 'Failed to update user (email/username may already exist)'];
	}

	return [
		'success' => true,
		'generated_password' => $generatedPassword,
	];
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
			$pdo->prepare('DELETE FROM user_roles WHERE user_id = :id OR assigned_by_user_id = :id')->execute([':id' => $userId]);
		}
		if (dashboardTableExists($pdo, 'user_permissions')) {
			$pdo->prepare('DELETE FROM user_permissions WHERE user_id = :id OR granted_by_user_id = :id')->execute([':id' => $userId]);
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


function enforceApiRateLimit(string $bucketKey, int $limit, int $windowSeconds) {
	startAuthSession();
	$now = time();
	if (!isset($_SESSION['api_rate_limits']) || !is_array($_SESSION['api_rate_limits'])) {
		$_SESSION['api_rate_limits'] = [];
	}

	$bucket = $_SESSION['api_rate_limits'][$bucketKey] ?? null;
	if (!is_array($bucket) || !isset($bucket['count'], $bucket['window_start'])) {
		$bucket = ['count' => 0, 'window_start' => $now];
	}

	if (($now - (int)$bucket['window_start']) >= $windowSeconds) {
		$bucket['count'] = 0;
		$bucket['window_start'] = $now;
	}

	$bucket['count'] = (int)$bucket['count'] + 1;
	$_SESSION['api_rate_limits'][$bucketKey] = $bucket;

	if ((int)$bucket['count'] > $limit) {
		respondJson(429, [
			'success' => false,
			'message' => 'Too many requests. Please slow down and try again.',
			'reason' => 'rate_limited',
		]);
	}
}


function ensureNotificationsTable(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			sent_by_user_id BIGINT UNSIGNED NULL,
			title VARCHAR(160) NOT NULL,
			message TEXT NOT NULL,
			notification_type VARCHAR(50) NOT NULL DEFAULT "info",
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			read_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_notifications_user_created (user_id, created_at),
			KEY idx_notifications_user_read (user_id, is_read),
			KEY idx_notifications_sent_by (sent_by_user_id),
			CONSTRAINT fk_notifications_sent_by_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
			CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
}


function getNotificationsPayload(PDO $pdo, $userId, $limit = 25) {
	ensureNotificationsTable($pdo);

	$safeLimit = (int)$limit;
	if ($safeLimit < 1) {
		$safeLimit = 25;
	}
	if ($safeLimit > 100) {
		$safeLimit = 100;
	}

	$countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
	$countStmt->execute([':user_id' => $userId]);
	$unreadCount = (int)$countStmt->fetchColumn();

	$listStmt = $pdo->prepare(
		'SELECT n.id, n.title, n.message, n.notification_type, n.is_read,
		        DATE_FORMAT(n.created_at, "%Y-%m-%d") AS `date`, DATE_FORMAT(n.created_at, "%H:%i:%s") AS `time`,
		        n.sent_by_user_id,
		        COALESCE(NULLIF(u.display_name, ""), NULLIF(u.username, ""), u.email) AS sent_by_display_name
		 FROM notifications n
		 LEFT JOIN users u ON u.id = n.sent_by_user_id
		 WHERE n.user_id = :user_id
		 ORDER BY n.id DESC
		 LIMIT :limit'
	);
	$listStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
	$listStmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
	$listStmt->execute();
	$rows = $listStmt->fetchAll();

	$items = [];
	foreach ($rows as $row) {
		$items[] = [
			'id' => (int)$row['id'],
			'title' => (string)($row['title'] ?? ''),
			'message' => (string)($row['message'] ?? ''),
			'type' => (string)($row['notification_type'] ?? 'info'),
			'is_read' => ((int)($row['is_read'] ?? 0)) === 1,
			'sent_by_user_id' => isset($row['sent_by_user_id']) ? (int)$row['sent_by_user_id'] : null,
			'sent_by_display_name' => isset($row['sent_by_display_name']) ? (string)$row['sent_by_display_name'] : '',
			'date' => (string)($row['date'] ?? ''),
			'time' => (string)($row['time'] ?? ''),
		];
	}

	return [
		'success' => true,
		'unread_count' => $unreadCount,
		'items' => $items,
	];
}


function createNotification(PDO $pdo, $userId, $title, $message, $type = 'info', $sentByUserId = null) {
	ensureNotificationsTable($pdo);

	$normalizedTitle = trim((string)$title);
	$normalizedMessage = trim((string)$message);
	$normalizedType = trim((string)$type);

	if ($normalizedTitle === '' && $normalizedMessage === '') {
		return ['success' => false, 'message' => 'Notification title or message is required'];
	}
	if ($normalizedTitle === '') {
		$normalizedTitle = 'Notification';
	}
	if ($normalizedType === '') {
		$normalizedType = 'info';
	}

	$normalizedSentByUserId = null;
	if ($sentByUserId !== null && $sentByUserId !== '') {
		$parsedSentBy = (int)$sentByUserId;
		if ($parsedSentBy > 0) {
			$normalizedSentByUserId = $parsedSentBy;
		}
	}

	$stmt = $pdo->prepare(
		'INSERT INTO notifications (user_id, sent_by_user_id, title, message, notification_type, is_read, created_at)
		 VALUES (:user_id, :sent_by_user_id, :title, :message, :notification_type, 0, :created_at)'
	);
	$ok = $stmt->execute([
		':user_id' => $userId,
		':sent_by_user_id' => $normalizedSentByUserId,
		':title' => substr($normalizedTitle, 0, 160),
		':message' => substr($normalizedMessage, 0, 1000),
		':notification_type' => substr($normalizedType, 0, 50),
		':created_at' => getDashboardSqlTimestamp(),
	]);

	if (!$ok) {
		return ['success' => false, 'message' => 'Failed to save notification'];
	}

	return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}


function getSupportedHeaderWidgetKeys() {
	return ['total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'];
}


function getDefaultHeaderWidgetPreferences() {
	$defaults = [];
	foreach (getSupportedHeaderWidgetKeys() as $key) {
		$defaults[$key] = true;
	}
	return $defaults;
}


function getHeaderWidgetPreferencesTableName() {
	return 'user_widget_preferences';
}


function encodeHeaderWidgetPreferencesJson(array $preferences) {
	$json = json_encode($preferences);
	return $json === false ? '{}' : $json;
}


function decodeHeaderWidgetPreferencesJson($json) {
	if (!is_string($json) || trim($json) === '') {
		return getDefaultHeaderWidgetPreferences();
	}

	$decoded = json_decode($json, true);
	if (!is_array($decoded)) {
		return getDefaultHeaderWidgetPreferences();
	}

	return normalizeHeaderWidgetPreferencesInput($decoded);
}


function ensureDefaultHeaderWidgetPreferencesForUser(PDO $pdo, $userId) {
	ensureUserWidgetPreferencesSchema($pdo);
	$tableName = getHeaderWidgetPreferencesTableName();

	$existsStmt = $pdo->prepare('SELECT id FROM ' . $tableName . ' WHERE user_id = :user_id LIMIT 1');
	$existsStmt->execute([':user_id' => (int)$userId]);
	if ($existsStmt->fetch()) {
		return;
	}

	$preferences = getDefaultHeaderWidgetPreferences();

	$now = getDashboardSqlTimestamp();
	$insertStmt = $pdo->prepare(
		'INSERT INTO ' . $tableName . ' (user_id, widgets_json, created_at, updated_at)
		 VALUES (:user_id, :widgets_json, :created_at, :updated_at)
		 ON DUPLICATE KEY UPDATE widgets_json = VALUES(widgets_json), updated_at = VALUES(updated_at)'
	);
	$insertStmt->execute([
		':user_id' => (int)$userId,
		':widgets_json' => encodeHeaderWidgetPreferencesJson($preferences),
		':created_at' => $now,
		':updated_at' => $now,
	]);
}


function getHeaderWidgetPreferencesPayload(PDO $pdo, $userId) {
	ensureDefaultHeaderWidgetPreferencesForUser($pdo, $userId);
	$tableName = getHeaderWidgetPreferencesTableName();

	$stmt = $pdo->prepare(
		'SELECT widgets_json
		 FROM ' . $tableName . '
		 WHERE user_id = :user_id'
	);
	$stmt->execute([':user_id' => (int)$userId]);
	$row = $stmt->fetch();
	$preferences = decodeHeaderWidgetPreferencesJson((string)($row['widgets_json'] ?? ''));

	return [
		'success' => true,
		'widgets' => $preferences,
	];
}


function normalizeHeaderWidgetPreferencesInput($rawInput) {
	$defaults = getDefaultHeaderWidgetPreferences();
	if (!is_array($rawInput)) {
		return $defaults;
	}

	$normalized = $defaults;
	foreach ($defaults as $key => $defaultValue) {
		$normalized[$key] = !empty($rawInput[$key]);
	}

	return $normalized;
}


function updateHeaderWidgetPreferences(PDO $pdo, $userId, $rawInput) {
	$preferences = normalizeHeaderWidgetPreferencesInput($rawInput);
	ensureUserWidgetPreferencesSchema($pdo);
	$tableName = getHeaderWidgetPreferencesTableName();

	$stmt = $pdo->prepare(
		'INSERT INTO ' . $tableName . ' (user_id, widgets_json, created_at, updated_at)
		 VALUES (:user_id, :widgets_json, :created_at, :updated_at)
		 ON DUPLICATE KEY UPDATE widgets_json = VALUES(widgets_json), updated_at = VALUES(updated_at)'
	);
	$now = getDashboardSqlTimestamp();
	$stmt->execute([
		':user_id' => (int)$userId,
		':widgets_json' => encodeHeaderWidgetPreferencesJson($preferences),
		':created_at' => $now,
		':updated_at' => $now,
	]);

	return [
		'success' => true,
		'widgets' => $preferences,
	];
}


function notifyOriginalSenderOfRecipientAction(PDO $pdo, $recipientUserId, $originalSenderUserId, $recipientDisplayName, $verbPastTense, $originalNotificationTitle = '', $originalNotificationSentAt = '') {
	$senderId = (int)$originalSenderUserId;
	$recipientId = (int)$recipientUserId;
	if ($senderId < 1 || $recipientId < 1 || $senderId === $recipientId) {
		return;
	}

	$verb = trim((string)$verbPastTense);
	if ($verb === '') {
		return;
	}

	$actorName = trim((string)$recipientDisplayName);
	if ($actorName === '') {
		$actorName = 'A user';
	}

	$notificationTitle = trim((string)$originalNotificationTitle);
	if ($notificationTitle === '') {
		$notificationTitle = 'Untitled Notification';
	}

	$sentAtLabel = trim((string)$originalNotificationSentAt);
	if ($sentAtLabel === '') {
		$sentAtLabel = getDashboardSqlTimestamp();
	}

	createNotification(
		$pdo,
		$senderId,
		'Recipient Update',
		$actorName . ' has ' . $verb . ' the notification "' . $notificationTitle . '" that you sent on "' . $sentAtLabel . '".',
		'info',
		null
	);
}


function getLogsPayload(PDO $pdo) {
	ensureActivityLogSchema($pdo);
	$stmt = $pdo->query('SELECT id, DATE_FORMAT(created_at, "%Y-%m-%d") AS `date`, DATE_FORMAT(created_at, "%H:%i:%s") AS `time`, message AS event FROM activity_log ORDER BY id ASC');
	$rows = $stmt->fetchAll();
	return ['logs' => is_array($rows) ? $rows : []];
}


function getAuditPayload(PDO $pdo) {
	ensureAuditLogSchema($pdo);
	$stmt = $pdo->query(
		'SELECT a.id,
		        DATE_FORMAT(a.created_at, "%Y-%m-%d") AS `date`,
		        DATE_FORMAT(a.created_at, "%H:%i:%s") AS `time`,
		        a.record_type,
		        a.action,
		        a.record_id,
		        a.details,
		        a.ip_address,
		        COALESCE(NULLIF(u.display_name, ""), NULLIF(u.username, ""), u.email, "System") AS source_display_name,
		        COALESCE(NULLIF(tu.display_name, ""), NULLIF(tu.username, ""), tu.email) AS target_display_name
		 FROM audit_log a
		 LEFT JOIN users u ON u.id = a.source_user_id
		 LEFT JOIN users tu ON tu.id = a.target_user_id
		 ORDER BY a.id ASC'
	);
	$rows = $stmt->fetchAll();
	$entries = [];

	foreach ($rows as $row) {
		$recordId = $row['record_id'];
		if ($recordId === null && strtolower((string)($row['action'] ?? '')) === 'bulk_delete') {
			$recordId = 'BULK';
		}
		if ($recordId === null) {
			$recordId = '';
		}

		$entries[] = [
			'id' => (int)$row['id'],
			'date' => (string)$row['date'],
			'time' => (string)$row['time'],
			'record_type' => (string)($row['record_type'] ?? ''),
			'action' => (string)($row['action'] ?? ''),
			'source_display_name' => (string)($row['source_display_name'] ?? ''),
			'target_display_name' => (string)($row['target_display_name'] ?? ''),
			'ip_address' => (string)($row['ip_address'] ?? ''),
			'change_type' => mapAuditActionToChangeType($row['action'] ?? '', ''),
			'record_id' => $recordId,
			'details' => (string)($row['details'] ?? ''),
		];
	}

	return ['entries' => $entries];
}


function addLog(PDO $pdo, $event, $eventType = 'event', $relatedRecordType = null, $relatedRecordId = null) {
	$message = trim((string)$event);
	if ($message === '') {
		return true;
	}

	ensureActivityLogSchema($pdo);

	$sourceUserId = null;
	$authUser = $GLOBALS['auth_user'] ?? null;
	if (is_array($authUser) && isset($authUser['id'])) {
		$candidate = (int)$authUser['id'];
		if ($candidate > 0) {
			$sourceUserId = $candidate;
		}
	}

	$timestamp = getDashboardSqlTimestamp();
	$stmt = $pdo->prepare('INSERT INTO activity_log (event_type, message, related_record_type, related_record_id, source_user_id, ip_address, created_at) VALUES (:event_type, :message, :related_record_type, :related_record_id, :source_user_id, :ip_address, :created_at)');
	return $stmt->execute([
		':event_type' => (string)$eventType,
		':message' => $message,
		':related_record_type' => $relatedRecordType,
		':related_record_id' => $relatedRecordId,
		':source_user_id' => $sourceUserId,
		':ip_address' => getRequestIpAddress(),
		':created_at' => $timestamp,
	]);
}


function addAuditEntries(PDO $pdo, $entries) {
	if (!is_array($entries) || empty($entries)) {
		return false;
	}

	$sourceUserId = null;
	$authUser = $GLOBALS['auth_user'] ?? null;
	if (is_array($authUser) && isset($authUser['id'])) {
		$candidate = (int)$authUser['id'];
		if ($candidate > 0) {
			$sourceUserId = $candidate;
		}
	}

	foreach ($entries as $entry) {
		$recordIdRaw = $entry['recordId'] ?? null;
		$recordId = is_numeric($recordIdRaw) ? (int)$recordIdRaw : null;
		$action = mapChangeTypeToAuditAction($entry['changeType'] ?? '');
		$details = '';
		if (isset($entry['details'])) {
			$details = trim((string)$entry['details']);
		} elseif (isset($entry['oldValue']) || isset($entry['newValue'])) {
			$fieldName = strtolower(trim((string)($entry['fieldName'] ?? '')));
			$fieldLabel = '';
			if ($fieldName === 'title') {
				$fieldLabel = 'Title';
			} elseif ($fieldName === 'description') {
				$fieldLabel = 'Description';
			}

			$oldValue = (string)($entry['oldValue'] ?? '');
			$newValue = (string)($entry['newValue'] ?? '');
			$details = ($fieldLabel !== '' ? ($fieldLabel . ': ') : '') . $oldValue . ' -> ' . $newValue;
		}

		$ok = writeAuditEvent($pdo, [
			'record_type' => 'record',
			'record_id' => $recordId,
			'action' => $action,
			'details' => $details,
			'source_user_id' => $sourceUserId,
		]);

		if (!$ok) {
			return false;
		}
	}

	return true;
}


function addAuditEntry(PDO $pdo, $changeType, $recordId, $fieldName, $oldValue, $newValue) {
	return addAuditEntries($pdo, [[
		'changeType' => $changeType,
		'recordId' => $recordId,
		'fieldName' => $fieldName,
		'oldValue' => $oldValue,
		'newValue' => $newValue
	]]);
}


function buildDataPagePayload(PDO $pdo, $includeAllFilteredItems = false) {
	$params = getDataQueryParams();
	$search = $params['search'];
	$sortColumn = $params['sortColumn'];
	$sortOrder = $params['sortOrder'];
	$page = $params['page'];
	$pageSize = $params['pageSize'];

	$where = 'WHERE deleted_at IS NULL';
	$bindings = [];
	if ($search !== '') {
		$where .= ' AND (title LIKE :search OR description LIKE :search)';
		$bindings[':search'] = '%' . $search . '%';
	}

	$totalCountStmt = $pdo->query('SELECT COUNT(*) FROM records WHERE deleted_at IS NULL');
	$totalCount = (int)$totalCountStmt->fetchColumn();
	$filteredCount = $totalCount;
	if ($search !== '') {
		$filteredCountStmt = $pdo->prepare('SELECT COUNT(*) FROM records ' . $where);
		$filteredCountStmt->execute($bindings);
		$filteredCount = (int)$filteredCountStmt->fetchColumn();
	}

	$totalPages = max(1, (int)ceil($filteredCount / $pageSize));
	$page = min($page, $totalPages);
	$offset = ($page - 1) * $pageSize;

	$dataSql = 'SELECT id, title, description FROM records ' . $where . ' ORDER BY ' . $sortColumn . ' ' . $sortOrder . ' LIMIT :limit OFFSET :offset';
	$dataStmt = $pdo->prepare($dataSql);
	foreach ($bindings as $key => $value) {
		$dataStmt->bindValue($key, $value, PDO::PARAM_STR);
	}
	$dataStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
	$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
	$dataStmt->execute();
	$pagedItems = $dataStmt->fetchAll();

	$payload = [
		'items' => is_array($pagedItems) ? $pagedItems : [],
		'page' => $page,
		'pageSize' => $pageSize,
		'totalPages' => $totalPages,
		'totalCount' => $totalCount,
		'filteredCount' => $filteredCount,
		'search' => $search,
		'sortColumn' => $sortColumn,
		'sortOrder' => $sortOrder
	];

	if ($includeAllFilteredItems) {
		$allFilteredSql = 'SELECT id, title, description FROM records ' . $where . ' ORDER BY ' . $sortColumn . ' ' . $sortOrder;
		$allFilteredStmt = $pdo->prepare($allFilteredSql);
		$allFilteredStmt->execute($bindings);
		$payload['filteredItems'] = $allFilteredStmt->fetchAll();
	}

	return $payload;
}


function getDataPayload(PDO $pdo) {
	$stmt = $pdo->query('SELECT id, title, description FROM records WHERE deleted_at IS NULL ORDER BY id ASC');
	$items = $stmt->fetchAll();
	return ['items' => is_array($items) ? $items : []];
}


$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}

requireApiKeyAuthentication();
requireApiSessionAuthentication();

try {
	$pdo = getDashboardPdo();
	ensureActivityLogSchema($pdo);
	ensureAuditLogSchema($pdo);
	ensureUserWidgetPreferencesSchema($pdo);
} catch (Throwable $e) {
	respondJson(500, ['success' => false, 'message' => 'Database connection failed']);
}


if ($method === 'POST') {
	$input = file_get_contents('php://input');
	$data = json_decode($input, true);

	if ($data === null && trim($input) !== '') {
		respondJson(400, ['success' => false, 'message' => 'Invalid JSON data']);
	}

	$data = is_array($data) ? $data : [];
	$postAction = $data['action'] ?? '';

	if ($postAction === 'reset_activity_log') {
		$userId = getApiAuthUserId();
		try {
			resetActivityLogTable($pdo);
			writeAuditEvent($pdo, [
				'record_type' => 'system',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'Activity log table reset',
				'source_user_id' => $userId,
			]);
			respondJson(200, ['success' => true, 'message' => 'Activity log table reset successfully']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset activity log table']);
		}
	}

	if ($postAction === 'reset_audit_log') {
		try {
			resetAuditLogEntries($pdo);
			respondJson(200, ['success' => true, 'message' => 'Audit log table reset successfully']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset audit log table']);
		}
	}

	if ($postAction === 'reset_records') {
		if (!resetRecordsTableToSample($pdo)) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset records table']);
		}

		writeAuditEvent($pdo, [
			'record_type' => 'record',
			'record_id' => null,
			'action' => 'reset',
			'details' => 'Records table reset to 3 sample entries',
			'source_user_id' => getApiAuthUserId(),
		]);

		respondJson(200, ['success' => true, 'message' => 'Records table reset successfully']);
	}

	if ($postAction === 'reset_widget_prefs') {
		$userId = getApiAuthUserId();
		try {
			$seededCount = (int)resetUserWidgetPreferencesTable($pdo);
			writeAuditEvent($pdo, [
				'record_type' => 'users',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'user_widget_preferences reset and reseeded for ' . $seededCount . ' users',
				'source_user_id' => $userId,
			]);
			respondJson(200, [
				'success' => true,
				'message' => 'Widget preferences reset to defaults for ' . $seededCount . ' users',
				'seeded_users' => $seededCount,
			]);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset widget preferences table']);
		}
	}

	if ($postAction === 'reset_all') {
		$userId = getApiAuthUserId();
		try {
			resetActivityLogTable($pdo);
			resetAuditLogEntries($pdo);
			if (!resetRecordsTableToSample($pdo)) {
				respondJson(500, ['success' => false, 'message' => 'Failed to reset records table']);
			}
			$pdo->exec('TRUNCATE TABLE notifications');

			writeAuditEvent($pdo, [
				'record_type' => 'system',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'Reset all executed for activity_log, audit_log, records, and notifications',
				'source_user_id' => $userId,
			]);

			respondJson(200, ['success' => true, 'message' => 'All target tables reset successfully']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to run reset all operation']);
		}
	}

	if ($postAction === 'reset_data') {
		if (APP_MODE !== 'demo') {
			respondJson(403, ['success' => false, 'message' => 'Reset is only allowed in demo mode']);
		}

		if (resetDashboardSqlData($pdo)) {
			writeAuditEvent($pdo, [
				'record_type' => 'system',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'Dashboard data reset to seed state',
				'source_user_id' => getApiAuthUserId(),
			]);
			respondJson(200, ['success' => true, 'message' => 'Data reset successfully']);
		}

		respondJson(500, ['success' => false, 'message' => 'Failed to reset data']);
	}

	if ($postAction === 'admin_user_lookup') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_lookup_' . $userId, 120, 60);
		$lookup = $data['lookup'] ?? '';
		$user = getUserByIdOrUsername($pdo, $lookup);
		if (!$user) {
			respondJson(404, ['success' => false, 'message' => 'User not found']);
		}

		respondJson(200, [
			'success' => true,
			'message' => 'User found',
			'user' => $user,
		]);
	}

	if ($postAction === 'admin_user_create') {
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_create_' . $actorUserId, 20, 60);
		$result = createDevToolsUser(
			$pdo,
			$data['email'] ?? '',
			$data['username'] ?? '',
			$data['display_name'] ?? '',
			$data['status'] ?? 'active'
		);

		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to create user']);
		}

		$createdUser = getUserByIdOrUsername($pdo, (string)($result['user_id'] ?? '0'));
		writeAuditEvent($pdo, [
			'record_type' => 'users',
			'record_id' => (int)($result['user_id'] ?? 0),
			'action' => 'create',
			'details' => 'Dev tools created user #' . (int)($result['user_id'] ?? 0),
			'source_user_id' => $actorUserId,
		]);

		respondJson(200, [
			'success' => true,
			'message' => 'User created successfully',
			'user' => $createdUser,
			'generated_password' => (string)($result['generated_password'] ?? ''),
		]);
	}

	if ($postAction === 'admin_user_update') {
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_update_' . $actorUserId, 30, 60);
		$targetId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
		$updates = [
			'email' => $data['email'] ?? '',
			'username' => $data['username'] ?? '',
			'display_name' => $data['display_name'] ?? '',
			'status' => $data['status'] ?? 'active',
		];
		$resetPassword = !empty($data['reset_password']);

		$result = updateDevToolsUser($pdo, $targetId, $updates, $resetPassword);
		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to update user']);
		}

		$updatedUser = getUserByIdOrUsername($pdo, (string)$targetId);
		writeAuditEvent($pdo, [
			'record_type' => 'users',
			'record_id' => $targetId,
			'action' => 'update',
			'details' => 'Dev tools updated user #' . $targetId,
			'source_user_id' => $actorUserId,
		]);

		respondJson(200, [
			'success' => true,
			'message' => 'User updated successfully',
			'user' => $updatedUser,
			'generated_password' => $result['generated_password'],
		]);
	}

	if ($postAction === 'admin_user_force_delete') {
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_force_delete_' . $actorUserId, 12, 60);
		$targetId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
		$result = forceDeleteUserHard($pdo, $targetId, $actorUserId);
		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to force delete user']);
		}

		writeAuditEvent($pdo, [
			'record_type' => 'users',
			'record_id' => $targetId,
			'action' => 'delete',
			'details' => 'Dev tools force deleted user #' . $targetId,
			'source_user_id' => $actorUserId,
		]);

		respondJson(200, [
			'success' => true,
			'message' => 'User force deleted successfully',
			'deleted_user' => $result['deleted_user'],
		]);
	}

	if ($postAction === 'admin_user_reset_widget_prefs') {
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_reset_widget_prefs_' . $actorUserId, 30, 60);
		$targetId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
		if ($targetId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid user id is required']);
		}

		$targetUser = getUserByIdOrUsername($pdo, (string)$targetId);
		if (!$targetUser) {
			respondJson(404, ['success' => false, 'message' => 'User not found']);
		}

		$updated = updateHeaderWidgetPreferences($pdo, $targetId, getDefaultHeaderWidgetPreferences());
		writeAuditEvent($pdo, [
			'record_type' => 'users',
			'record_id' => $targetId,
			'action' => 'reset',
			'details' => 'Widget preferences reset to defaults for user #' . $targetId,
			'source_user_id' => $actorUserId,
		]);

		respondJson(200, [
			'success' => true,
			'message' => 'Widget preferences reset for selected user',
			'user' => $targetUser,
			'widgets' => $updated['widgets'] ?? getDefaultHeaderWidgetPreferences(),
		]);
	}

	if ($postAction === 'reset_notifications_table') {
		$userId = getApiAuthUserId();
		try {
			$pdo->exec('TRUNCATE TABLE notifications');
			writeAuditEvent($pdo, [
				'record_type' => 'notification',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'Notifications table truncated',
				'source_user_id' => $userId,
			]);
			respondJson(200, ['success' => true, 'message' => 'Notifications table reset successfully']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset notifications table']);
		}
	}

	if ($postAction === 'add_audit_entry') {
		$success = addAuditEntry($pdo, $data['changeType'] ?? '', $data['recordId'] ?? '', $data['fieldName'] ?? '', $data['oldValue'] ?? '', $data['newValue'] ?? '');
		if ($success) {
			respondJson(200, ['success' => true, 'message' => 'Audit entry added']);
		}
		respondJson(500, ['success' => false, 'message' => 'Failed to save audit entry']);
	}

	if ($postAction === 'add_audit_entries') {
		$success = addAuditEntries($pdo, $data['entries'] ?? []);
		if ($success) {
			respondJson(200, ['success' => true, 'message' => 'Audit entries added']);
		}
		respondJson(500, ['success' => false, 'message' => 'Failed to save audit entries']);
	}

	if ($postAction === 'notification_create') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('notification_create_' . $userId, 30, 60);
		$created = createNotification(
			$pdo,
			$userId,
			$data['title'] ?? '',
			$data['message'] ?? '',
			$data['type'] ?? 'info',
			$data['sent_by_user_id'] ?? null
		);

		if (!$created['success']) {
			respondJson(400, ['success' => false, 'message' => $created['message'] ?? 'Failed to save notification']);
		}

		writeAuditEvent($pdo, [
			'record_type' => 'notification',
			'record_id' => (int)$created['id'],
			'action' => 'notification_sent',
			'details' => 'Notification (' . substr((string)($data['title'] ?? ''), 0, 160) . '): Sent by user #' . $userId . ' to user #' . $userId,
			'source_user_id' => $userId,
			'target_user_id' => $userId,
		]);

		$payload = getNotificationsPayload($pdo, $userId, 25);
		respondJson(200, [
			'success' => true,
			'message' => 'Notification created',
			'notification_id' => (int)$created['id'],
			'unread_count' => (int)$payload['unread_count'],
			'items' => $payload['items'],
		]);
	}

	if ($postAction === 'notification_mark_read') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('notification_mark_read_' . $userId, 120, 60);
		ensureNotificationsTable($pdo);
		$notificationId = isset($data['id']) ? (int)$data['id'] : 0;
		if ($notificationId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid notification id is required']);
		}

		$selectStmt = $pdo->prepare('SELECT id, sent_by_user_id, is_read, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
		$selectStmt->execute([
			':id' => $notificationId,
			':user_id' => $userId,
		]);
		$notification = $selectStmt->fetch();
		if (!$notification) {
			respondJson(404, ['success' => false, 'message' => 'Notification not found']);
		}

		$updateStmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = :read_at WHERE id = :id AND user_id = :user_id');
		$updateStmt->execute([
			':read_at' => getDashboardSqlTimestamp(),
			':id' => $notificationId,
			':user_id' => $userId,
		]);

		if ((int)($notification['is_read'] ?? 0) === 0) {
			writeAuditEvent($pdo, [
				'record_type' => 'notification',
				'record_id' => $notificationId,
				'action' => 'notification_read',
				'details' => 'Notification (' . substr((string)($notification['title'] ?? ''), 0, 160) . '): Unread -> Read',
				'source_user_id' => $userId,
			]);

			notifyOriginalSenderOfRecipientAction(
				$pdo,
				$userId,
				(int)($notification['sent_by_user_id'] ?? 0),
				getApiAuthUserDisplayName(),
					'read',
					(string)($notification['title'] ?? ''),
					(string)($notification['sent_at_label'] ?? '')
			);
		}

		$payload = getNotificationsPayload($pdo, $userId, 25);
		respondJson(200, [
			'success' => true,
			'message' => 'Notification marked as read',
			'unread_count' => (int)$payload['unread_count'],
			'items' => $payload['items'],
		]);
	}

	if ($postAction === 'notifications_mark_all_read') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('notifications_mark_all_read_' . $userId, 20, 60);
		ensureNotificationsTable($pdo);

		$pendingSenderStmt = $pdo->prepare('SELECT sent_by_user_id, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE user_id = :user_id AND is_read = 0 AND sent_by_user_id IS NOT NULL');
		$pendingSenderStmt->execute([':user_id' => $userId]);
		$pendingSenderRows = $pendingSenderStmt->fetchAll();

		$updateStmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = :read_at WHERE user_id = :user_id AND is_read = 0');
		$updateStmt->execute([
			':read_at' => getDashboardSqlTimestamp(),
			':user_id' => $userId,
		]);

		writeAuditEvent($pdo, [
			'record_type' => 'notification',
			'record_id' => null,
			'action' => 'notification_mark_all_read',
			'details' => 'Marked all notifications as read',
			'source_user_id' => $userId,
		]);

		$recipientDisplayName = getApiAuthUserDisplayName();
		foreach ($pendingSenderRows as $row) {
			notifyOriginalSenderOfRecipientAction(
				$pdo,
				$userId,
				(int)($row['sent_by_user_id'] ?? 0),
				$recipientDisplayName,
					'read',
					(string)($row['title'] ?? ''),
					(string)($row['sent_at_label'] ?? '')
			);
		}

		$payload = getNotificationsPayload($pdo, $userId, 25);
		respondJson(200, [
			'success' => true,
			'message' => 'All notifications marked as read',
			'unread_count' => (int)$payload['unread_count'],
			'items' => $payload['items'],
		]);
	}

	if ($postAction === 'notification_delete') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('notification_delete_' . $userId, 120, 60);
		ensureNotificationsTable($pdo);
		$notificationId = isset($data['id']) ? (int)$data['id'] : 0;
		if ($notificationId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid notification id is required']);
		}

		$selectStmt = $pdo->prepare('SELECT id, sent_by_user_id, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
		$selectStmt->execute([
			':id' => $notificationId,
			':user_id' => $userId,
		]);
		$notification = $selectStmt->fetch();
		if (!$notification) {
			respondJson(404, ['success' => false, 'message' => 'Notification not found']);
		}

		$deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id');
		$deleteStmt->execute([
			':id' => $notificationId,
			':user_id' => $userId,
		]);

		writeAuditEvent($pdo, [
			'record_type' => 'notification',
			'record_id' => $notificationId,
			'action' => 'notification_deleted',
			'details' => 'Notification (' . substr((string)($notification['title'] ?? ''), 0, 160) . '): existed -> deleted',
			'source_user_id' => $userId,
		]);

		notifyOriginalSenderOfRecipientAction(
			$pdo,
			$userId,
			(int)($notification['sent_by_user_id'] ?? 0),
			getApiAuthUserDisplayName(),
			'deleted',
			(string)($notification['title'] ?? ''),
			(string)($notification['sent_at_label'] ?? '')
		);

		$payload = getNotificationsPayload($pdo, $userId, 25);
		respondJson(200, [
			'success' => true,
			'message' => 'Notification deleted',
			'unread_count' => (int)$payload['unread_count'],
			'items' => $payload['items'],
		]);
	}

	if ($postAction === 'notifications_delete_all') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('notifications_delete_all_' . $userId, 20, 60);
		ensureNotificationsTable($pdo);

		$senderStmt = $pdo->prepare('SELECT sent_by_user_id, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE user_id = :user_id AND sent_by_user_id IS NOT NULL');
		$senderStmt->execute([':user_id' => $userId]);
		$senderRows = $senderStmt->fetchAll();

		$deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = :user_id');
		$deleteStmt->execute([':user_id' => $userId]);

		writeAuditEvent($pdo, [
			'record_type' => 'notification',
			'record_id' => null,
			'action' => 'notification_delete_all',
			'details' => 'Deleted all notifications',
			'source_user_id' => $userId,
		]);

		$recipientDisplayName = getApiAuthUserDisplayName();
		foreach ($senderRows as $row) {
			notifyOriginalSenderOfRecipientAction(
				$pdo,
				$userId,
				(int)($row['sent_by_user_id'] ?? 0),
				$recipientDisplayName,
				'deleted',
				(string)($row['title'] ?? ''),
				(string)($row['sent_at_label'] ?? '')
			);
		}

		$payload = getNotificationsPayload($pdo, $userId, 25);
		respondJson(200, [
			'success' => true,
			'message' => 'All notifications deleted',
			'unread_count' => (int)$payload['unread_count'],
			'items' => $payload['items'],
		]);
	}

	if ($postAction === 'widget_preferences_update') {
		$userId = getApiAuthUserId();
		enforceApiRateLimit('widget_preferences_update_' . $userId, 40, 60);
		$updated = updateHeaderWidgetPreferences($pdo, $userId, $data['widgets'] ?? []);

		writeAuditEvent($pdo, [
			'record_type' => 'users',
			'record_id' => null,
			'action' => 'Widget Visibility',
			'details' => 'Header widget visibility preferences updated',
			'source_user_id' => $userId,
		]);

		respondJson(200, [
			'success' => true,
			'message' => 'Widget preferences updated',
			'widgets' => $updated['widgets'],
		]);
	}

	if ($postAction === 'data_create') {
		$actorUserId = getApiAuthUserId();
		$title = normalizeRecordText($data['title'] ?? '');
		$description = normalizeRecordText($data['description'] ?? '');

		if ($title === '' || $description === '') {
			respondJson(400, ['success' => false, 'message' => 'Title and description are required']);
		}

		$timestamp = getDashboardSqlTimestamp();
		$stmt = $pdo->prepare('INSERT INTO records (title, description, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:title, :description, :created_by_user_id, :updated_by_user_id, :created_at, :updated_at)');
		$ok = $stmt->execute([
			':title' => $title,
			':description' => $description,
			':created_by_user_id' => $actorUserId,
			':updated_by_user_id' => $actorUserId,
			':created_at' => $timestamp,
			':updated_at' => $timestamp,
		]);

		if (!$ok) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		$newId = (int)$pdo->lastInsertId();
		$newItem = ['id' => $newId, 'title' => $title, 'description' => $description];
		$createDetails = 'Title:  -> ' . $title . ', Description:  -> ' . $description;

		addAuditEntries($pdo, [
			['changeType' => 'ADD', 'recordId' => $newId, 'details' => $createDetails]
		]);
		addLog($pdo, 'A new entry has been added; ID ' . $newId . ' with title: "' . $title . '", and description: "' . $description . '"', 'record_created', 'record', $newId);

		respondJson(200, ['success' => true, 'message' => 'Record created successfully', 'item' => $newItem]);
	}

	if ($postAction === 'data_update') {
		$actorUserId = getApiAuthUserId();
		$recordId = isset($data['id']) ? (int)$data['id'] : 0;
		$title = normalizeRecordText($data['title'] ?? '');
		$description = normalizeRecordText($data['description'] ?? '');

		if ($recordId < 1 || $title === '' || $description === '') {
			respondJson(400, ['success' => false, 'message' => 'Valid id, title, and description are required']);
		}

		$findStmt = $pdo->prepare('SELECT id, title, description FROM records WHERE id = :id AND deleted_at IS NULL LIMIT 1');
		$findStmt->execute([':id' => $recordId]);
		$existingItem = $findStmt->fetch();

		if (!$existingItem) {
			respondJson(404, ['success' => false, 'message' => 'Record not found']);
		}

		$updateStmt = $pdo->prepare('UPDATE records SET title = :title, description = :description, updated_by_user_id = :updated_by_user_id, updated_at = :updated_at WHERE id = :id');
		$ok = $updateStmt->execute([
			':title' => $title,
			':description' => $description,
			':updated_by_user_id' => $actorUserId,
			':updated_at' => getDashboardSqlTimestamp(),
			':id' => $recordId,
		]);

		if (!$ok) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		$detailParts = [];
		if ((string)$existingItem['title'] !== $title) {
			$detailParts[] = 'Title: ' . (string)$existingItem['title'] . ' -> ' . $title;
		}
		if ((string)$existingItem['description'] !== $description) {
			$detailParts[] = 'Description: ' . (string)$existingItem['description'] . ' -> ' . $description;
		}
		if (!empty($detailParts)) {
			addAuditEntries($pdo, [[
				'changeType' => 'EDIT',
				'recordId' => $recordId,
				'details' => implode(', ', $detailParts)
			]]);
		}

		addLog($pdo, 'An entry has been edited; ID ' . $recordId . ' with title: "' . $title . '", and description: "' . $description . '"', 'record_updated', 'record', $recordId);

		respondJson(200, ['success' => true, 'message' => 'Record updated successfully', 'item' => ['id' => $recordId, 'title' => $title, 'description' => $description]]);
	}

	if ($postAction === 'data_delete') {
		$recordId = isset($data['id']) ? (int)$data['id'] : 0;
		if ($recordId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid record id is required']);
		}

		$findStmt = $pdo->prepare('SELECT id, title, description FROM records WHERE id = :id AND deleted_at IS NULL LIMIT 1');
		$findStmt->execute([':id' => $recordId]);
		$deletedItem = $findStmt->fetch();

		if (!$deletedItem) {
			respondJson(404, ['success' => false, 'message' => 'Record not found']);
		}

		$deleteStmt = $pdo->prepare('DELETE FROM records WHERE id = :id');
		$ok = $deleteStmt->execute([':id' => $recordId]);
		if (!$ok) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		addAuditEntries($pdo, [[
			'changeType' => 'DELETE',
			'recordId' => $recordId,
			'details' => 'Title: ' . (string)$deletedItem['title'] . ' -> (deleted), Description: ' . (string)$deletedItem['description'] . ' -> (deleted)'
		]]);
		addLog($pdo, 'An entry has been deleted; ID ' . $deletedItem['id'] . ' with title: "' . $deletedItem['title'] . '", and description: "' . $deletedItem['description'] . '"', 'record_deleted', 'record', (int)$deletedItem['id']);

		respondJson(200, ['success' => true, 'message' => 'Record deleted successfully', 'item' => $deletedItem]);
	}

	if ($postAction === 'data_bulk_delete') {
		$ids = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
		$normalizedIds = [];
		foreach ($ids as $id) {
			$parsedId = (int)$id;
			if ($parsedId > 0) {
				$normalizedIds[$parsedId] = true;
			}
		}

		if (empty($normalizedIds)) {
			respondJson(400, ['success' => false, 'message' => 'At least one valid record id is required']);
		}

		$placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
		$idValues = array_keys($normalizedIds);
		$selectSql = 'SELECT id, title, description FROM records WHERE id IN (' . $placeholders . ') AND deleted_at IS NULL ORDER BY id ASC';
		$selectStmt = $pdo->prepare($selectSql);
		$selectStmt->execute($idValues);
		$deletedItems = $selectStmt->fetchAll();

		if (empty($deletedItems)) {
			respondJson(404, ['success' => false, 'message' => 'No matching records were found']);
		}

		$deleteSql = 'DELETE FROM records WHERE id IN (' . $placeholders . ')';
		$deleteStmt = $pdo->prepare($deleteSql);
		$deleteStmt->execute($idValues);

		$deletedIds = [];
		$auditEntries = [];
		foreach ($deletedItems as $item) {
			$deletedIds[] = (int)$item['id'];
			$auditEntries[] = [
				'changeType' => 'DELETE',
				'recordId' => (int)$item['id'],
				'details' => 'Title: ' . (string)$item['title'] . ' -> (deleted), Description: ' . (string)$item['description'] . ' -> (deleted)'
			];
		}

		$auditEntries[] = [
			'changeType' => 'BULK_DELETE',
			'recordId' => null,
			'fieldName' => 'bulk_action',
			'oldValue' => 'Selected IDs: ' . implode(', ', $deletedIds),
			'newValue' => 'Deleted ' . count($deletedItems) . ' records'
		];
		addAuditEntries($pdo, $auditEntries);
		addLog($pdo, 'Bulk delete completed for ' . count($deletedItems) . ' records (IDs: ' . implode(', ', $deletedIds) . ')', 'record_bulk_deleted', 'record', null);

		respondJson(200, ['success' => true, 'message' => 'Bulk delete completed successfully', 'deletedCount' => count($deletedItems), 'deletedIds' => $deletedIds]);
	}

	$eventMessage = $data['event'] ?? '';
	$logSuccess = true;
	if ($eventMessage !== '') {
		$eventType = ($action === 'report_downloaded') ? 'report_downloaded' : (($action === 'report_generated') ? 'report_generated' : 'event');
		$logSuccess = addLog($pdo, $eventMessage, $eventType, null, null);
	}

	if ($action !== 'report_generated' && $action !== 'report_downloaded') {
		$actorUserId = getApiAuthUserId();
		$items = [];
		$rawItems = is_array($data['data'] ?? null) && is_array(($data['data']['items'] ?? null)) ? $data['data']['items'] : [];
		foreach ($rawItems as $item) {
			$id = isset($item['id']) ? (int)$item['id'] : 0;
			$title = normalizeRecordText($item['title'] ?? '');
			$description = normalizeRecordText($item['description'] ?? '');
			if ($id > 0 && $title !== '' && $description !== '') {
				$items[] = ['id' => $id, 'title' => $title, 'description' => $description];
			}
		}

		try {
			$pdo->beginTransaction();
			$pdo->exec('DELETE FROM records');
			$insertStmt = $pdo->prepare('INSERT INTO records (id, title, description, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:id, :title, :description, :created_by_user_id, :updated_by_user_id, :created_at, :updated_at)');
			$ts = getDashboardSqlTimestamp();
			$maxId = 0;
			foreach ($items as $item) {
				$insertStmt->execute([
					':id' => $item['id'],
					':title' => $item['title'],
					':description' => $item['description'],
					':created_by_user_id' => $actorUserId,
					':updated_by_user_id' => $actorUserId,
					':created_at' => $ts,
					':updated_at' => $ts,
				]);
				if ($item['id'] > $maxId) {
					$maxId = $item['id'];
				}
			}
			$pdo->exec('ALTER TABLE records AUTO_INCREMENT = ' . ((int)$maxId + 1));
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		if ($logSuccess) {
			respondJson(200, ['success' => true, 'message' => 'Data saved successfully']);
		}

		respondJson(500, ['success' => false, 'message' => 'Failed to save log entry']);
	}

	respondJson(200, ['success' => true, 'message' => 'Event logged successfully']);
}


if ($method === 'GET') {
	if ($action === 'session_status') {
		respondJson(200, ['success' => true, 'authenticated' => true]);
	}

	if ($action === 'audit_trail') {
		echo json_encode(getAuditPayload($pdo));
		exit;
	}

	if ($action === 'logs') {
		echo json_encode(getLogsPayload($pdo));
		exit;
	}

	if ($action === 'notifications') {
		$userId = getApiAuthUserId();
		$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
		echo json_encode(getNotificationsPayload($pdo, $userId, $limit));
		exit;
	}

	if ($action === 'widget_preferences') {
		$userId = getApiAuthUserId();
		echo json_encode(getHeaderWidgetPreferencesPayload($pdo, $userId));
		exit;
	}

	if ($action === 'data_page') {
		echo json_encode(buildDataPagePayload($pdo, false));
		exit;
	}

	if ($action === 'data_filtered_export') {
		echo json_encode(buildDataPagePayload($pdo, true));
		exit;
	}

	if ($action === '' || $action === 'data') {
		echo json_encode(getDataPayload($pdo));
		exit;
	}

	respondJson(400, ['success' => false, 'message' => 'Unknown GET action']);
}


respondJson(405, ['success' => false, 'message' => 'Method not allowed']);
?>
