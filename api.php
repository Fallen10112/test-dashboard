<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/includes/sql_helpers.php';
require_once __DIR__ . '/includes/auth.php';

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

function respondJson(int $statusCode, array $payload): void {
	http_response_code($statusCode);
	echo json_encode($payload);
	exit;
}

function requireApiSessionAuthentication(): void {
	startAuthSession();
	$authUser = getAuthUser();
	if (!$authUser) {
		respondJson(401, ['success' => false, 'message' => 'Authentication required.', 'reason' => 'unauthenticated']);
	}
	$GLOBALS['auth_user'] = $authUser;
}

function getApiAuthUserId(): int {
	if (!isset($GLOBALS['auth_user']) || !is_array($GLOBALS['auth_user'])) {
		$authUser = getAuthUser();
		if (!$authUser) {
			respondJson(401, ['success' => false, 'message' => 'Authentication required.', 'reason' => 'unauthenticated']);
		}
		$GLOBALS['auth_user'] = $authUser;
	}

	return (int)($GLOBALS['auth_user']['id'] ?? 0);
}

function requireApiPermission(string $resourceKey, string $permissionKey, int $statusCode = 403): void {
	$authUserId = getApiAuthUserId();
	$pdo = getDashboardPdo();
	ensurePermissionsSchema($pdo);
	if (!userHasPermission($pdo, $authUserId, $resourceKey, $permissionKey)) {
		respondJson($statusCode, [
			'success' => false,
			'message' => 'Permission denied.',
			'reason' => 'forbidden',
			'resource' => $resourceKey,
			'permission' => $permissionKey,
		]);
	}
}

function enforceApiRateLimit(string $bucketKey, int $limit, int $windowSeconds): void {
	startAuthSession();
	$key = trim($bucketKey);
	if ($key === '' || $limit < 1 || $windowSeconds < 1) {
		return;
	}

	if (!isset($_SESSION['_api_rate_limits']) || !is_array($_SESSION['_api_rate_limits'])) {
		$_SESSION['_api_rate_limits'] = [];
	}

	$now = time();
	$state = $_SESSION['_api_rate_limits'][$key] ?? ['count' => 0, 'window_start' => $now];
	if (!is_array($state)) {
		$state = ['count' => 0, 'window_start' => $now];
	}

	$windowStart = (int)($state['window_start'] ?? $now);
	if ($windowStart <= 0 || ($windowStart + $windowSeconds) <= $now) {
		$state = ['count' => 0, 'window_start' => $now];
	}

	$state['count'] = (int)($state['count'] ?? 0) + 1;
	$_SESSION['_api_rate_limits'][$key] = $state;

	if ($state['count'] > $limit) {
		respondJson(429, ['success' => false, 'message' => 'Rate limit exceeded']);
	}
}

function normalizeRecordText($value) {
	return trim((string)$value);
}

function getNormalizedStringLength($value) {
	$text = (string)$value;
	if (function_exists('mb_strlen')) {
		return mb_strlen($text, 'UTF-8');
	}
	return strlen($text);
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

	if (mb_strlen($normalizedTitle, 'UTF-8') > 64) {
		return ['success' => false, 'message' => 'Notification title must not exceed 64 characters'];
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
	$permissionState = getHeaderWidgetPermissionState($pdo, $userId);
	$tableName = getHeaderWidgetPreferencesTableName();

	$stmt = $pdo->prepare(
		'SELECT widgets_json
		 FROM ' . $tableName . '
		 WHERE user_id = :user_id'
	);
	$stmt->execute([':user_id' => (int)$userId]);
	$row = $stmt->fetch();
	$preferences = decodeHeaderWidgetPreferencesJson((string)($row['widgets_json'] ?? ''));
	$allowedKeys = isset($permissionState['allowed_keys']) && is_array($permissionState['allowed_keys']) ? $permissionState['allowed_keys'] : [];
	$preferences = normalizeHeaderWidgetPreferencesInput($preferences, $allowedKeys);

	return [
		'success' => true,
		'widgets' => $preferences,
		'can_customize' => (bool)($permissionState['can_customize'] ?? false),
		'allowed_keys' => $allowedKeys,
	];
}


function normalizeHeaderWidgetPreferencesInput($rawInput, array $allowedKeys = []) {
	$defaults = getDefaultHeaderWidgetPreferences();
	if (!is_array($rawInput)) {
		return $defaults;
	}

	$normalized = $defaults;
	$allowedLookup = [];
	if (!empty($allowedKeys)) {
		foreach ($allowedKeys as $allowedKey) {
			$allowedLookup[(string)$allowedKey] = true;
		}
	}
	foreach ($defaults as $key => $defaultValue) {
		if (!empty($allowedLookup) && !isset($allowedLookup[$key])) {
			$normalized[$key] = false;
			continue;
		}
		$normalized[$key] = !empty($rawInput[$key]);
	}

	return $normalized;
}


function updateHeaderWidgetPreferences(PDO $pdo, $userId, $rawInput) {
	$permissionState = getHeaderWidgetPermissionState($pdo, $userId);
	$allowedKeys = isset($permissionState['allowed_keys']) && is_array($permissionState['allowed_keys']) ? $permissionState['allowed_keys'] : [];
	$existingPreferences = getHeaderWidgetPreferencesPayload($pdo, $userId)['widgets'] ?? getDefaultHeaderWidgetPreferences();
	$mergedInput = is_array($rawInput) ? array_merge($existingPreferences, $rawInput) : $existingPreferences;
	$preferences = normalizeHeaderWidgetPreferencesInput($mergedInput, $allowedKeys);
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


function getAuditDatasetDefinitions() {
	return [
		'records' => ['friendly_name' => 'Data'],
		'notifications' => ['friendly_name' => 'Notifications'],
		'users' => ['friendly_name' => 'Users'],
		'user_sessions' => ['friendly_name' => 'Sessions'],
	];
}


function getAuditDatasetDisplayName($dataset) {
	$key = strtolower(trim((string)$dataset));
	if ($key === '') {
		return '';
	}

	$definitions = getAuditDatasetDefinitions();
	if (isset($definitions[$key]) && is_array($definitions[$key])) {
		$name = trim((string)($definitions[$key]['friendly_name'] ?? ''));
		if ($name !== '') {
			return $name;
		}
	}

	return $key;
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
			        a.dataset,
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

		$dataset = trim((string)($row['dataset'] ?? ''));

		$entries[] = [
			'id' => (int)$row['id'],
			'date' => (string)$row['date'],
			'time' => (string)$row['time'],
			'record_type' => (string)($row['record_type'] ?? ''),
			'action' => (string)($row['action'] ?? ''),
			'source_display_name' => (string)($row['source_display_name'] ?? ''),
			'target_display_name' => (string)($row['target_display_name'] ?? ''),
			'ip_address' => (string)($row['ip_address'] ?? ''),
			'dataset' => $dataset,
			'dataset_display_name' => getAuditDatasetDisplayName($dataset),
			'change_type' => mapAuditActionToChangeType($row['action'] ?? '', ''),
			'record_id' => $recordId,
			'details' => (string)($row['details'] ?? ''),
		];
	}

	return ['entries' => $entries];
}


function getHeaderMetricsPayload(PDO $pdo) {
	ensureAuditLogSchema($pdo);

	$totalEntriesStmt = $pdo->query('SELECT COUNT(*) FROM records WHERE deleted_at IS NULL');
	$totalEntries = (int)$totalEntriesStmt->fetchColumn();

	$today = substr(getDashboardSqlTimestamp(), 0, 10);
	$totalEditsStmt = $pdo->query('SELECT COUNT(*) FROM audit_log WHERE record_type = "record" AND action = "update"');
	$totalEdits = (int)$totalEditsStmt->fetchColumn();

	$addsTodayStmt = $pdo->prepare(
		'SELECT COUNT(DISTINCT record_id)
		 FROM audit_log
		 WHERE record_type = :record_type
		   AND action = "create"
		   AND record_id IS NOT NULL
		   AND DATE(created_at) = :today'
	);
	$addsTodayStmt->execute([
		':record_type' => 'record',
		':today' => $today,
	]);
	$addsToday = (int)$addsTodayStmt->fetchColumn();

	$deletesTodayStmt = $pdo->prepare(
		'SELECT COUNT(DISTINCT record_id)
		 FROM audit_log
		 WHERE record_type = :record_type
		   AND action = "delete"
		   AND record_id IS NOT NULL
		   AND DATE(created_at) = :today'
	);
	$deletesTodayStmt->execute([
		':record_type' => 'record',
		':today' => $today,
	]);
	$deletesToday = (int)$deletesTodayStmt->fetchColumn();

	return [
		'success' => true,
		'total_entries' => $totalEntries,
		'total_edits' => $totalEdits,
		'adds_today' => $addsToday,
		'deletes_today' => $deletesToday,
	];
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

requireApiSessionAuthentication();

try {
	$pdo = getDashboardPdo();
	ensureActivityLogSchema($pdo);
	ensureAuditLogSchema($pdo);
	ensureUserWidgetPreferencesSchema($pdo);
	ensurePermissionsSchema($pdo);
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
		requireApiPermission('dev_tools', 'read');
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
		requireApiPermission('dev_tools', 'read');
		try {
			resetAuditLogEntries($pdo);
			respondJson(200, ['success' => true, 'message' => 'Audit log table reset successfully']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset audit log table']);
		}
	}

	if ($postAction === 'reset_records') {
		requireApiPermission('dev_tools', 'read');
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
		requireApiPermission('dev_tools', 'read');
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

	if ($postAction === 'logout_all_users') {
		requireApiPermission('dev_tools', 'read');
		$userId = getApiAuthUserId();
		try {
			writeAuditEvent($pdo, [
				'record_type' => 'auth',
				'record_id' => null,
				'action' => 'reset',
				'details' => 'All active user sessions were reset',
				'source_user_id' => $userId,
			]);
			resetUserSessionsTable($pdo);
			clearAuthSessionState();
			respondJson(200, ['success' => true, 'message' => 'All users were logged out and sessions were reset']);
		} catch (Throwable $e) {
			respondJson(500, ['success' => false, 'message' => 'Failed to reset user sessions']);
		}
	}

	if ($postAction === 'reset_all') {
		requireApiPermission('dev_tools', 'read');
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
		requireApiPermission('dev_tools', 'read');
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
		requireApiPermission('admin', 'manage_users');
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

	if ($postAction === 'admin_role_list') {
		requireApiPermission('admin', 'manage_permissions');
		respondJson(200, [
			'success' => true,
			'message' => 'Roles loaded',
			'roles' => getAvailableRoles($pdo),
		]);
	}

	if ($postAction === 'admin_role_permissions') {
		requireApiPermission('admin', 'manage_permissions');
		$payload = getRolePermissionsEditorPayload($pdo, getApiAuthUserId());
		respondJson(200, [
			'success' => true,
			'message' => 'Role permissions loaded',
			'roles' => $payload['roles'],
			'resources' => $payload['resources'],
			'permissions' => $payload['permissions'],
			'role_permissions' => $payload['role_permissions'],
		]);
	}

	if ($postAction === 'admin_role_permissions_update') {
		requireApiPermission('admin', 'manage_permissions');
		$actorUserId = getApiAuthUserId();
		$targetId = isset($data['role_id']) ? (int)$data['role_id'] : 0;
		$grants = isset($data['grants']) && is_array($data['grants']) ? $data['grants'] : [];
		if ($targetId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid role id is required']);
		}
		if (!canUserEditRolePermissions($pdo, $actorUserId, $targetId)) {
			respondJson(403, ['success' => false, 'message' => 'You do not have permission to edit that role']);
		}
		$normalizedRequestedGrants = [];
		foreach ($grants as $grant) {
			$grant = trim((string)$grant);
			if ($grant === '') {
				continue;
			}
			$normalizedRequestedGrants[$grant] = true;
		}
		$filteredGrants = filterRolePermissionGrantsForUser($pdo, $actorUserId, $grants);
		if (count($filteredGrants) !== count($normalizedRequestedGrants)) {
			respondJson(403, ['success' => false, 'message' => 'You do not have permission to edit one or more selected permissions']);
		}

		$result = setRolePermissions($pdo, $targetId, $filteredGrants);
		if (!$result) {
			respondJson(400, ['success' => false, 'message' => 'Failed to update role permissions']);
		}

		$role = getRoleById($pdo, $targetId);
		try {
			writeAuditEvent($pdo, [
				'record_type' => 'role',
				'record_id' => $targetId,
				'action' => 'update',
				'details' => 'Updated permissions for role #' . $targetId . ' (' . (string)($role['name'] ?? '') . ')',
				'source_user_id' => $actorUserId,
			]);
			} catch (Throwable $e) {
			}

		respondJson(200, [
			'success' => true,
			'message' => 'Role permissions updated successfully',
			'role' => $role,
			'role_permissions' => getAllRolePermissionsMatrix($pdo),
		]);
	}

	if ($postAction === 'admin_role_lookup') {
		requireApiPermission('admin', 'manage_permissions');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_role_lookup_' . $actorUserId, 120, 60);
		$lookup = $data['lookup'] ?? '';
		$role = getRoleByLookup($pdo, $lookup);
		if (!$role) {
			respondJson(404, ['success' => false, 'message' => 'Role not found']);
		}

		respondJson(200, [
			'success' => true,
			'message' => 'Role found',
			'role' => $role,
		]);
	}

	if ($postAction === 'admin_role_create') {
		requireApiPermission('admin', 'admin_role_create');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_role_create_' . $actorUserId, 20, 60);
		$result = createAdminRole(
			$pdo,
			$data['name'] ?? '',
			$data['description'] ?? ''
		);

		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to create role']);
		}

		$createdRole = $result['role'] ?? null;
		if ($createdRole) {
			try {
				writeAuditEvent($pdo, [
					'record_type' => 'role',
					'record_id' => (int)($createdRole['id'] ?? 0),
					'action' => 'create',
					'details' => 'Created role #' . (int)($createdRole['id'] ?? 0) . ' (' . (string)($createdRole['name'] ?? '') . ')',
					'source_user_id' => $actorUserId,
				]);
			} catch (Throwable $e) {
			}
		}

		respondJson(200, [
			'success' => true,
			'message' => 'Role created successfully',
			'role' => $createdRole,
			'roles' => $result['roles'] ?? getAvailableRoles($pdo),
		]);
	}

	if ($postAction === 'admin_role_update') {
		requireApiPermission('admin', 'admin_role_update');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_role_update_' . $actorUserId, 30, 60);
		$targetId = isset($data['role_id']) ? (int)$data['role_id'] : 0;
		$existingRole = getRoleById($pdo, $targetId);
		$result = updateAdminRole(
			$pdo,
			$targetId,
			[
				'name' => $data['name'] ?? '',
				'description' => $data['description'] ?? '',
			]
		);

		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to update role']);
		}

		$updatedRole = $result['role'] ?? null;
		if ($updatedRole) {
			try {
				writeAuditEvent($pdo, [
					'record_type' => 'role',
					'record_id' => $targetId,
					'action' => 'update',
					'details' => 'Updated role #' . $targetId
						. ' name: ' . (string)($existingRole['name'] ?? '') . ' -> ' . (string)($updatedRole['name'] ?? '')
						. '; description: ' . (string)($existingRole['description'] ?? '') . ' -> ' . (string)($updatedRole['description'] ?? ''),
					'source_user_id' => $actorUserId,
				]);
			} catch (Throwable $e) {
			}
		}

		respondJson(200, [
			'success' => true,
			'message' => 'Role updated successfully',
			'role' => $updatedRole,
			'roles' => $result['roles'] ?? getAvailableRoles($pdo),
		]);
	}

	if ($postAction === 'admin_role_delete') {
		requireApiPermission('admin', 'admin_role_delete');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_role_delete_' . $actorUserId, 12, 60);
		$targetId = isset($data['role_id']) ? (int)$data['role_id'] : 0;
		$deletedRole = getRoleById($pdo, $targetId);
		$result = deleteAdminRole($pdo, $targetId);
		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to delete role']);
		}

		$replacementRole = $result['replacement_role'] ?? null;
		$affectedUsers = is_array($result['affected_users'] ?? null) ? $result['affected_users'] : [];
		try {
			$deleteDetails = 'Deleted role #' . $targetId . ' (' . (string)($deletedRole['name'] ?? '') . ')';
			if (!empty($affectedUsers) && $replacementRole) {
				$deleteDetails .= '; reassigned ' . count($affectedUsers) . ' user(s) to #' . (int)($replacementRole['id'] ?? 0) . ' (' . (string)($replacementRole['name'] ?? '') . ')';
			} elseif (empty($affectedUsers)) {
				$deleteDetails .= '; no users were assigned to this role';
			}
			writeAuditEvent($pdo, [
				'record_type' => 'role',
				'record_id' => $targetId,
				'action' => 'delete',
				'details' => $deleteDetails,
				'source_user_id' => $actorUserId,
			]);

			if (!empty($affectedUsers) && $replacementRole) {
				foreach ($affectedUsers as $affectedUser) {
					$affectedUserId = (int)($affectedUser['id'] ?? 0);
					if ($affectedUserId < 1) {
						continue;
					}
					writeAuditEvent($pdo, [
						'record_type' => 'users',
						'record_id' => $affectedUserId,
						'action' => 'update',
						'details' => 'Role reassigned from #' . $targetId . ' (' . (string)($deletedRole['name'] ?? '') . ') to #' . (int)($replacementRole['id'] ?? 0) . ' (' . (string)($replacementRole['name'] ?? '') . ')',
						'source_user_id' => $actorUserId,
						'target_user_id' => $affectedUserId,
					]);
				}
			}
		} catch (Throwable $e) {
		}

		respondJson(200, [
			'success' => true,
			'message' => 'Role deleted successfully',
			'deleted_role' => $result['deleted_role'] ?? $deletedRole,
			'replacement_role' => $replacementRole,
			'affected_users' => $affectedUsers,
			'roles' => $result['roles'] ?? getAvailableRoles($pdo),
		]);
	}

	if ($postAction === 'admin_user_create') {
		requireApiPermission('admin', 'manage_users');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_create_' . $actorUserId, 20, 60);
		$result = createDevToolsUser(
			$pdo,
			$data['email'] ?? '',
			$data['username'] ?? '',
			$data['display_name'] ?? '',
			$data['status'] ?? 'active',
			$data['role_id'] ?? 0,
			$actorUserId
		);

		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to create user']);
		}

		$createdUser = null;
		try {
			$createdUser = getUserByIdOrUsername($pdo, (string)($result['user_id'] ?? '0'));
		} catch (Throwable $e) {
			$createdUser = null;
		}
		if (!$createdUser) {
			$role = getRoleById($pdo, (int)($data['role_id'] ?? 0));
			$createdUser = [
				'id' => (int)($result['user_id'] ?? 0),
				'email' => (string)($data['email'] ?? ''),
				'username' => (string)($data['username'] ?? ''),
				'display_name' => (string)($data['display_name'] ?? ''),
				'status' => (string)($data['status'] ?? 'active'),
				'created_at' => '',
				'role_id' => isset($role['id']) ? (int)$role['id'] : null,
				'role_name' => isset($role['name']) ? (string)$role['name'] : '',
				'role_description' => isset($role['description']) ? (string)$role['description'] : '',
			];
		}
		try {
			writeAuditEvent($pdo, [
				'record_type' => 'users',
				'record_id' => (int)($result['user_id'] ?? 0),
				'action' => 'create',
				'details' => 'Created user ' . trim((string)($createdUser['username'] ?? '')) . ' / ' . trim((string)($createdUser['display_name'] ?? '')) . ' with ID ' . (int)($result['user_id'] ?? 0),
				'source_user_id' => $actorUserId,
			]);
		} catch (Throwable $e) {
		}

		respondJson(200, [
			'success' => true,
			'message' => 'User created successfully',
			'user' => $createdUser,
			'generated_password' => (string)($result['generated_password'] ?? ''),
		]);
	}

	if ($postAction === 'admin_user_update') {
		requireApiPermission('admin', 'manage_users');
		$actorUserId = getApiAuthUserId();
		enforceApiRateLimit('admin_user_update_' . $actorUserId, 30, 60);
		$targetId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
		$existingUser = null;
		try {
			$existingUser = getUserByIdOrUsername($pdo, (string)$targetId);
		} catch (Throwable $e) {
			$existingUser = null;
		}
		$updates = [
			'email' => $data['email'] ?? '',
			'username' => $data['username'] ?? '',
			'display_name' => $data['display_name'] ?? '',
			'status' => $data['status'] ?? 'active',
			'role_id' => $data['role_id'] ?? 0,
		];
		$resetPassword = !empty($data['reset_password']);

		$result = updateDevToolsUser($pdo, $targetId, $updates, $resetPassword, $actorUserId);
		if (!$result['success']) {
			respondJson(400, ['success' => false, 'message' => $result['message'] ?? 'Failed to update user']);
		}

		$updatedUser = null;
		try {
			$updatedUser = getUserByIdOrUsername($pdo, (string)$targetId);
		} catch (Throwable $e) {
			$updatedUser = null;
		}
		if (!$updatedUser) {
			$role = getRoleById($pdo, (int)($updates['role_id'] ?? 0));
			$updatedUser = [
				'id' => $targetId,
				'email' => (string)($updates['email'] ?? ''),
				'username' => (string)($updates['username'] ?? ''),
				'display_name' => (string)($updates['display_name'] ?? ''),
				'status' => (string)($updates['status'] ?? 'active'),
				'created_at' => '',
				'role_id' => isset($role['id']) ? (int)$role['id'] : null,
				'role_name' => isset($role['name']) ? (string)$role['name'] : '',
				'role_description' => isset($role['description']) ? (string)$role['description'] : '',
			];
		}
		$changeParts = [];
		if (is_array($existingUser)) {
			$trackedFields = [
				'email' => 'Email',
				'username' => 'Username',
				'display_name' => 'Display Name',
				'status' => 'Status',
			];
			foreach ($trackedFields as $fieldKey => $fieldLabel) {
				$beforeValue = trim((string)($existingUser[$fieldKey] ?? ''));
				$afterValue = trim((string)($updatedUser[$fieldKey] ?? $updates[$fieldKey] ?? ''));
				if ($beforeValue !== $afterValue) {
					$changeParts[] = $fieldLabel . ': ' . ($beforeValue !== '' ? $beforeValue : '(empty)') . ' -> ' . ($afterValue !== '' ? $afterValue : '(empty)');
				}
			}

			$beforeRoleId = (int)($existingUser['role_id'] ?? 0);
			$afterRoleId = (int)($updatedUser['role_id'] ?? ($updates['role_id'] ?? 0));
			$beforeRoleName = trim((string)($existingUser['role_name'] ?? ''));
			$afterRoleName = trim((string)($updatedUser['role_name'] ?? ''));
			if ($beforeRoleId !== $afterRoleId || $beforeRoleName !== $afterRoleName) {
				$beforeRoleLabel = $beforeRoleId > 0
					? '#' . $beforeRoleId . ($beforeRoleName !== '' ? ' (' . $beforeRoleName . ')' : '')
					: '(none)';
				$afterRoleLabel = $afterRoleId > 0
					? '#' . $afterRoleId . ($afterRoleName !== '' ? ' (' . $afterRoleName . ')' : '')
					: '(none)';
				$changeParts[] = 'Role: ' . $beforeRoleLabel . ' -> ' . $afterRoleLabel;
			}
		}
		$changeParts[] = 'Password reset: ' . ($resetPassword ? 'Yes' : 'No');
		try {
			writeAuditEvent($pdo, [
				'record_type' => 'users',
				'record_id' => $targetId,
				'action' => 'update',
				'details' => 'Updated user ID ' . $targetId . ' - ' . implode('; ', $changeParts),
				'source_user_id' => $actorUserId,
			]);
		} catch (Throwable $e) {
		}

		respondJson(200, [
			'success' => true,
			'message' => 'User updated successfully',
			'user' => $updatedUser,
			'generated_password' => $result['generated_password'],
		]);
	}

	if ($postAction === 'admin_user_force_delete') {
		requireApiPermission('admin', 'manage_users');
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
		requireApiPermission('admin', 'manage_users');
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
		requireApiPermission('widgets', 'customize');
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
		requireApiPermission('records', 'create');
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

	if ($postAction === 'data_bulk_create') {
		requireApiPermission('records', 'create');
		$actorUserId = getApiAuthUserId();
		$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
		$maxRows = 200;

		if (empty($items)) {
			respondJson(400, ['success' => false, 'message' => 'At least one valid record is required']);
		}

		if (count($items) > $maxRows) {
			respondJson(400, ['success' => false, 'message' => 'Bulk import supports up to ' . $maxRows . ' records at a time']);
		}

		$normalizedItems = [];
		$validationErrors = [];
		foreach ($items as $index => $item) {
			$rowNumber = (int)$index + 1;
			if (!is_array($item)) {
				$validationErrors[] = ['row' => $rowNumber, 'message' => 'Each row must include a title and description'];
				continue;
			}

			$title = normalizeRecordText($item['title'] ?? '');
			$description = normalizeRecordText($item['description'] ?? '');

			if ($title === '' || $description === '') {
				$validationErrors[] = ['row' => $rowNumber, 'message' => 'Title and description are required'];
				continue;
			}

			if (getNormalizedStringLength($title) > 255) {
				$validationErrors[] = ['row' => $rowNumber, 'message' => 'Title must be 255 characters or fewer'];
				continue;
			}

			$normalizedItems[] = [
				'title' => $title,
				'description' => $description,
			];
		}

		if (empty($normalizedItems)) {
			respondJson(400, ['success' => false, 'message' => 'No valid rows were provided', 'errors' => $validationErrors]);
		}

		if (!empty($validationErrors)) {
			respondJson(400, ['success' => false, 'message' => 'Fix the invalid rows before importing', 'errors' => $validationErrors]);
		}

		$timestamp = getDashboardSqlTimestamp();
		$createdItems = [];
		$auditEntries = [];

		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare('INSERT INTO records (title, description, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:title, :description, :created_by_user_id, :updated_by_user_id, :created_at, :updated_at)');

			foreach ($normalizedItems as $row) {
				$ok = $stmt->execute([
					':title' => $row['title'],
					':description' => $row['description'],
					':created_by_user_id' => $actorUserId,
					':updated_by_user_id' => $actorUserId,
					':created_at' => $timestamp,
					':updated_at' => $timestamp,
				]);

				if (!$ok) {
					throw new RuntimeException('Failed to save data');
				}

				$newId = (int)$pdo->lastInsertId();
				$createdItems[] = ['id' => $newId, 'title' => $row['title'], 'description' => $row['description']];
				$auditEntries[] = [
					'changeType' => 'ADD',
					'recordId' => $newId,
					'details' => 'Title:  -> ' . $row['title'] . ', Description:  -> ' . $row['description']
				];
			}
			$pdo->commit();
		} catch (PDOException $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			$errorCode = (string)($e->getCode() ?? '');
			if ($errorCode === '23000') {
				respondJson(409, ['success' => false, 'message' => 'One of the rows could not be saved because it conflicts with an existing record']);
			}

			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		try {
			if (!empty($auditEntries)) {
				addAuditEntries($pdo, $auditEntries);
			}
			addLog($pdo, 'Bulk CSV import completed for ' . count($createdItems) . ' records', 'record_bulk_created', 'record', null);
		} catch (Throwable $e) {
		}

		respondJson(200, [
			'success' => true,
			'message' => 'Bulk records created successfully',
			'createdCount' => count($createdItems),
			'createdItems' => $createdItems,
		]);
	}

	if ($postAction === 'data_update') {
		requireApiPermission('records', 'update');
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
		requireApiPermission('records', 'delete');
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
		requireApiPermission('records', 'delete');
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

		$dataset = strtolower(trim((string)($data['dataset'] ?? '')));
		$auditDataset = '';
		if ($dataset === 'data') {
			$auditDataset = 'records';
		} elseif ($dataset === 'logs') {
			$auditDataset = 'activity_log';
		}

		if ($action === 'report_generated') {
			writeAuditEvent($pdo, [
				'record_type' => 'report',
				'action' => 'generate',
				'dataset' => $auditDataset,
				'details' => 'Report generated for dataset: ' . $dataset,
				'source_user_id' => $GLOBALS['auth_user']['id'] ?? null,
			]);
		} elseif ($action === 'report_downloaded' && strpos(strtolower($eventMessage), 'pdf') !== false) {
			writeAuditEvent($pdo, [
				'record_type' => 'report',
				'action' => 'download_pdf',
				'dataset' => $auditDataset,
				'details' => 'PDF report downloaded for dataset: ' . $dataset,
				'source_user_id' => $GLOBALS['auth_user']['id'] ?? null,
			]);
		} elseif ($action === 'report_downloaded' && strpos(strtolower($eventMessage), 'csv') !== false) {
			writeAuditEvent($pdo, [
				'record_type' => 'report',
				'action' => 'download_csv',
				'dataset' => $auditDataset,
				'details' => 'CSV report downloaded for dataset: ' . $dataset,
				'source_user_id' => $GLOBALS['auth_user']['id'] ?? null,
			]);
		}
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
		requireApiPermission('audit_log', 'read');
		echo json_encode(getAuditPayload($pdo));
		exit;
	}

	if ($action === 'header_metrics') {
		requireApiPermission('records', 'read');
		echo json_encode(getHeaderMetricsPayload($pdo));
		exit;
	}

	if ($action === 'logs') {
		requireApiPermission('dev_tools', 'read');
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
		requireApiPermission('records', 'read');
		echo json_encode(buildDataPagePayload($pdo, false));
		exit;
	}

	if ($action === 'data_filtered_export') {
		requireApiPermission('records', 'export');
		echo json_encode(buildDataPagePayload($pdo, true));
		exit;
	}

	if ($action === '' || $action === 'data') {
		requireApiPermission('records', 'read');
		echo json_encode(getDataPayload($pdo));
		exit;
	}

	respondJson(400, ['success' => false, 'message' => 'Unknown GET action']);
}


respondJson(405, ['success' => false, 'message' => 'Method not allowed']);
?>
