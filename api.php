<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/includes/sql_helpers.php';
require_once __DIR__ . '/includes/auth.php';

$action = $_GET['action'] ?? null;


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

	$queryApiKey = isset($_GET['api_key']) ? trim((string)$_GET['api_key']) : '';
	if ($queryApiKey !== '') {
		return $queryApiKey;
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
	$allowed = [25, 50, 100];
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
	return strtoupper((string)$action);
}


function getDataPayload(PDO $pdo) {
	$stmt = $pdo->query('SELECT id, title, description FROM records WHERE deleted_at IS NULL ORDER BY id ASC');
	$items = $stmt->fetchAll();
	return ['items' => is_array($items) ? $items : []];
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
	$stmt = $pdo->query('SELECT id, DATE_FORMAT(created_at, "%Y-%m-%d") AS `date`, DATE_FORMAT(created_at, "%H:%i:%s") AS `time`, message AS event FROM activity_log ORDER BY id ASC');
	$rows = $stmt->fetchAll();
	return ['logs' => is_array($rows) ? $rows : []];
}


function getAuditPayload(PDO $pdo) {
	$stmt = $pdo->query('SELECT id, DATE_FORMAT(created_at, "%Y-%m-%d") AS `date`, DATE_FORMAT(created_at, "%H:%i:%s") AS `time`, action, record_id, field_name, old_value, new_value FROM audit_log ORDER BY id ASC');
	$rows = $stmt->fetchAll();
	$entries = [];

	foreach ($rows as $row) {
		$fieldName = (string)($row['field_name'] ?? '');
		$recordId = $row['record_id'];
		if ($recordId === null && $fieldName === 'bulk_action') {
			$recordId = 'BULK';
		}
		if ($recordId === null) {
			$recordId = '';
		}

		$entries[] = [
			'id' => (int)$row['id'],
			'date' => (string)$row['date'],
			'time' => (string)$row['time'],
			'change_type' => mapAuditActionToChangeType($row['action'] ?? '', $fieldName),
			'record_id' => $recordId,
			'field_name' => $fieldName,
			'old_value' => (string)($row['old_value'] ?? ''),
			'new_value' => (string)($row['new_value'] ?? ''),
		];
	}

	return ['entries' => $entries];
}


function addLog(PDO $pdo, $event, $eventType = 'event', $relatedRecordType = null, $relatedRecordId = null) {
	$message = trim((string)$event);
	if ($message === '') {
		return true;
	}

	$timestamp = getDashboardSqlTimestamp();
	$stmt = $pdo->prepare('INSERT INTO activity_log (event_type, message, related_record_type, related_record_id, created_at) VALUES (:event_type, :message, :related_record_type, :related_record_id, :created_at)');
	return $stmt->execute([
		':event_type' => (string)$eventType,
		':message' => $message,
		':related_record_type' => $relatedRecordType,
		':related_record_id' => $relatedRecordId,
		':created_at' => $timestamp,
	]);
}


function addAuditEntries(PDO $pdo, $entries) {
	if (!is_array($entries) || empty($entries)) {
		return false;
	}

	$timestamp = getDashboardSqlTimestamp();
	$stmt = $pdo->prepare('INSERT INTO audit_log (record_type, record_id, action, field_name, old_value, new_value, created_at) VALUES (:record_type, :record_id, :action, :field_name, :old_value, :new_value, :created_at)');

	foreach ($entries as $entry) {
		$recordIdRaw = $entry['recordId'] ?? null;
		$recordId = is_numeric($recordIdRaw) ? (int)$recordIdRaw : null;
		$fieldName = (string)($entry['fieldName'] ?? '');
		$action = mapChangeTypeToAuditAction($entry['changeType'] ?? '');

		$ok = $stmt->execute([
			':record_type' => 'record',
			':record_id' => $recordId,
			':action' => $action,
			':field_name' => $fieldName !== '' ? $fieldName : null,
			':old_value' => isset($entry['oldValue']) ? (string)$entry['oldValue'] : null,
			':new_value' => isset($entry['newValue']) ? (string)$entry['newValue'] : null,
			':created_at' => $timestamp,
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

	$filteredCountStmt = $pdo->prepare('SELECT COUNT(*) FROM records ' . $where);
	$filteredCountStmt->execute($bindings);
	$filteredCount = (int)$filteredCountStmt->fetchColumn();

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


$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}

requireApiKeyAuthentication();
requireApiSessionAuthentication();

try {
	$pdo = getDashboardPdo();
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

	if ($postAction === 'reset_data') {
		if (APP_MODE !== 'demo') {
			respondJson(403, ['success' => false, 'message' => 'Reset is only allowed in demo mode']);
		}

		if (resetDashboardSqlData($pdo)) {
			respondJson(200, ['success' => true, 'message' => 'Data reset successfully']);
		}

		respondJson(500, ['success' => false, 'message' => 'Failed to reset data']);
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
		ensureNotificationsTable($pdo);

		$pendingSenderStmt = $pdo->prepare('SELECT sent_by_user_id, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE user_id = :user_id AND is_read = 0 AND sent_by_user_id IS NOT NULL');
		$pendingSenderStmt->execute([':user_id' => $userId]);
		$pendingSenderRows = $pendingSenderStmt->fetchAll();

		$updateStmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = :read_at WHERE user_id = :user_id AND is_read = 0');
		$updateStmt->execute([
			':read_at' => getDashboardSqlTimestamp(),
			':user_id' => $userId,
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
		ensureNotificationsTable($pdo);

		$senderStmt = $pdo->prepare('SELECT sent_by_user_id, title, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS sent_at_label FROM notifications WHERE user_id = :user_id AND sent_by_user_id IS NOT NULL');
		$senderStmt->execute([':user_id' => $userId]);
		$senderRows = $senderStmt->fetchAll();

		$deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = :user_id');
		$deleteStmt->execute([':user_id' => $userId]);

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

	if ($postAction === 'data_create') {
		$title = normalizeRecordText($data['title'] ?? '');
		$description = normalizeRecordText($data['description'] ?? '');

		if ($title === '' || $description === '') {
			respondJson(400, ['success' => false, 'message' => 'Title and description are required']);
		}

		$timestamp = getDashboardSqlTimestamp();
		$stmt = $pdo->prepare('INSERT INTO records (title, description, created_at, updated_at) VALUES (:title, :description, :created_at, :updated_at)');
		$ok = $stmt->execute([
			':title' => $title,
			':description' => $description,
			':created_at' => $timestamp,
			':updated_at' => $timestamp,
		]);

		if (!$ok) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		$newId = (int)$pdo->lastInsertId();
		$newItem = ['id' => $newId, 'title' => $title, 'description' => $description];

		addAuditEntries($pdo, [
			['changeType' => 'ADD', 'recordId' => $newId, 'fieldName' => 'title', 'oldValue' => '', 'newValue' => $title],
			['changeType' => 'ADD', 'recordId' => $newId, 'fieldName' => 'description', 'oldValue' => '', 'newValue' => $description]
		]);
		addLog($pdo, 'A new entry has been added; ID ' . $newId . ' with title: "' . $title . '", and description: "' . $description . '"', 'record_created', 'record', $newId);

		respondJson(200, ['success' => true, 'message' => 'Record created successfully', 'item' => $newItem]);
	}

	if ($postAction === 'data_update') {
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

		$updateStmt = $pdo->prepare('UPDATE records SET title = :title, description = :description, updated_at = :updated_at WHERE id = :id');
		$ok = $updateStmt->execute([
			':title' => $title,
			':description' => $description,
			':updated_at' => getDashboardSqlTimestamp(),
			':id' => $recordId,
		]);

		if (!$ok) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		$auditEntries = [];
		if ((string)$existingItem['title'] !== $title) {
			$auditEntries[] = ['changeType' => 'EDIT', 'recordId' => $recordId, 'fieldName' => 'title', 'oldValue' => $existingItem['title'], 'newValue' => $title];
		}
		if ((string)$existingItem['description'] !== $description) {
			$auditEntries[] = ['changeType' => 'EDIT', 'recordId' => $recordId, 'fieldName' => 'description', 'oldValue' => $existingItem['description'], 'newValue' => $description];
		}
		if (!empty($auditEntries)) {
			addAuditEntries($pdo, $auditEntries);
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

		addAuditEntry($pdo, 'DELETE', $recordId, 'record', 'Title: "' . $deletedItem['title'] . '", Description: "' . $deletedItem['description'] . '"', '');
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
				'fieldName' => 'record',
				'oldValue' => 'Title: "' . $item['title'] . '", Description: "' . $item['description'] . '"',
				'newValue' => ''
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
			$insertStmt = $pdo->prepare('INSERT INTO records (id, title, description, created_at, updated_at) VALUES (:id, :title, :description, :created_at, :updated_at)');
			$ts = getDashboardSqlTimestamp();
			$maxId = 0;
			foreach ($items as $item) {
				$insertStmt->execute([
					':id' => $item['id'],
					':title' => $item['title'],
					':description' => $item['description'],
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

	if ($action === 'data_page') {
		echo json_encode(buildDataPagePayload($pdo, false));
		exit;
	}

	if ($action === 'data_filtered_export') {
		echo json_encode(buildDataPagePayload($pdo, true));
		exit;
	}

	echo json_encode(getDataPayload($pdo));
	exit;
}


respondJson(405, ['success' => false, 'message' => 'Method not allowed']);
?>
