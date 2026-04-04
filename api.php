<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once 'config/config.php';

$dataFile = DATA_FILE;
$logsFile = LOGS_FILE;
$action = $_GET['action'] ?? null;

$encryptionKey = ENCRYPTION_KEY;
$encryptionCipher = ENCRYPTION_CIPHER;


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


function encryptData($data, $key, $cipher) {
	$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
	$encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);
	return base64_encode($iv . $encrypted);
}


function decryptData($encryptedData, $key, $cipher) {
	$data = base64_decode($encryptedData);
	$ivLength = openssl_cipher_iv_length($cipher);
	$iv = substr($data, 0, $ivLength);
	$encrypted = substr($data, $ivLength);
	return openssl_decrypt($encrypted, $cipher, $key, 0, $iv);
}


function writeFileWithLockRetry($filePath, $content, $maxRetries = 3, $retryDelayMicros = 120000) {
	$attempt = 0;

	while ($attempt <= $maxRetries) {
		$dir = dirname($filePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$fp = fopen($filePath, 'c+');
		if ($fp !== false) {
			if (flock($fp, LOCK_EX)) {
				ftruncate($fp, 0);
				rewind($fp);
				$bytesWritten = fwrite($fp, $content);
				fflush($fp);
				flock($fp, LOCK_UN);
				fclose($fp);

				if ($bytesWritten !== false) {
					return true;
				}
			} else {
				fclose($fp);
			}
		}

		$attempt++;
		if ($attempt <= $maxRetries) {
			usleep($retryDelayMicros);
		}
	}

	return false;
}


function ensureDataDirectory() {
	if (!is_dir(DATA_DIR)) {
		mkdir(DATA_DIR, 0755, true);
	}
}


function writeEncryptedJsonFile($filePath, $payload) {
	global $encryptionKey, $encryptionCipher;

	$jsonContent = json_encode($payload, JSON_UNESCAPED_SLASHES);
	$encryptedContent = encryptData($jsonContent, $encryptionKey, $encryptionCipher);
	return writeFileWithLockRetry($filePath, $encryptedContent);
}


function readEncryptedJsonFile($filePath, $defaultData) {
	global $encryptionKey, $encryptionCipher;

	ensureDataDirectory();

	if (!file_exists($filePath) || filesize($filePath) === 0) {
		writeEncryptedJsonFile($filePath, $defaultData);
		return $defaultData;
	}

	$fileContent = file_get_contents($filePath);
	$decryptedContent = decryptData($fileContent, $encryptionKey, $encryptionCipher);

	if ($decryptedContent !== false && $decryptedContent !== '') {
		$parsed = json_decode($decryptedContent, true);
		if (is_array($parsed)) {
			return $parsed;
		}
	}

	$parsed = json_decode($fileContent, true);
	if (is_array($parsed)) {
		writeEncryptedJsonFile($filePath, $parsed);
		return $parsed;
	}

	writeEncryptedJsonFile($filePath, $defaultData);
	return $defaultData;
}


function getDataPayload() {
	return readEncryptedJsonFile(DATA_FILE, ['items' => []]);
}


function getLogsPayload() {
	return readEncryptedJsonFile(LOGS_FILE, ['logs' => []]);
}


function getAuditPayload() {
	return readEncryptedJsonFile(DATA_DIR . '/audit_trail.json', ['entries' => []]);
}


function saveDataPayload($payload) {
	return writeEncryptedJsonFile(DATA_FILE, $payload);
}


function sanitizeDataPayload($payload) {
	$items = [];
	$rawItems = is_array($payload) && isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

	foreach ($rawItems as $item) {
		$id = isset($item['id']) ? (int)$item['id'] : 0;
		if ($id < 1) {
			continue;
		}

		$items[] = [
			'id' => $id,
			'title' => trim((string)($item['title'] ?? '')),
			'description' => trim((string)($item['description'] ?? ''))
		];
	}

	return ['items' => $items];
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


function filterAndSortItems($items, $search, $sortColumn, $sortOrder) {
	$normalizedSearch = strtolower($search);
	$filteredItems = [];

	foreach ($items as $item) {
		$title = strtolower((string)($item['title'] ?? ''));
		$description = strtolower((string)($item['description'] ?? ''));
		if ($normalizedSearch === '' || strpos($title, $normalizedSearch) !== false || strpos($description, $normalizedSearch) !== false) {
			$filteredItems[] = $item;
		}
	}

	usort($filteredItems, function($left, $right) use ($sortColumn, $sortOrder) {
		$leftValue = $left[$sortColumn] ?? '';
		$rightValue = $right[$sortColumn] ?? '';

		if ($sortColumn === 'id') {
			$leftValue = (int)$leftValue;
			$rightValue = (int)$rightValue;
		} else {
			$leftValue = strtolower((string)$leftValue);
			$rightValue = strtolower((string)$rightValue);
		}

		if ($leftValue === $rightValue) {
			return 0;
		}

		if ($sortOrder === 'asc') {
			return $leftValue < $rightValue ? -1 : 1;
		}

		return $leftValue > $rightValue ? -1 : 1;
	});

	return $filteredItems;
}


function buildDataPagePayload($includeAllFilteredItems = false) {
	$dataPayload = sanitizeDataPayload(getDataPayload());
	$params = getDataQueryParams();
	$allItems = $dataPayload['items'];
	$filteredItems = filterAndSortItems($allItems, $params['search'], $params['sortColumn'], $params['sortOrder']);
	$totalCount = count($allItems);
	$filteredCount = count($filteredItems);
	$totalPages = max(1, (int)ceil($filteredCount / $params['pageSize']));
	$page = min($params['page'], $totalPages);
	$offset = ($page - 1) * $params['pageSize'];
	$pagedItems = array_slice($filteredItems, $offset, $params['pageSize']);

	$payload = [
		'items' => $pagedItems,
		'page' => $page,
		'pageSize' => $params['pageSize'],
		'totalPages' => $totalPages,
		'totalCount' => $totalCount,
		'filteredCount' => $filteredCount,
		'search' => $params['search'],
		'sortColumn' => $params['sortColumn'],
		'sortOrder' => $params['sortOrder']
	];

	if ($includeAllFilteredItems) {
		$payload['filteredItems'] = $filteredItems;
	}

	return $payload;
}


function addLog($event) {
	$logsData = getLogsPayload();
	$logs = isset($logsData['logs']) && is_array($logsData['logs']) ? $logsData['logs'] : [];
	$date = date('Y-m-d', time() - 3600);
	$time = date('H:i:s', time() - 3600);
	$newId = 1;

	if (!empty($logs)) {
		$ids = array_column($logs, 'id');
		if (!empty($ids)) {
			$newId = max($ids) + 1;
		}
	}

	$logs[] = [
		'id' => $newId,
		'date' => $date,
		'time' => $time,
		'event' => $event
	];

	return writeEncryptedJsonFile(LOGS_FILE, ['logs' => $logs]);
}


function addAuditEntries($entries) {
	if (!is_array($entries) || empty($entries)) {
		return false;
	}

	$auditData = getAuditPayload();
	$auditEntries = isset($auditData['entries']) && is_array($auditData['entries']) ? $auditData['entries'] : [];
	$date = date('Y-m-d', time() - 3600);
	$time = date('H:i:s', time() - 3600);
	$newId = 1;

	if (!empty($auditEntries)) {
		$ids = array_column($auditEntries, 'id');
		if (!empty($ids)) {
			$newId = max($ids) + 1;
		}
	}

	foreach ($entries as $entry) {
		$auditEntries[] = [
			'id' => $newId,
			'date' => $date,
			'time' => $time,
			'change_type' => $entry['changeType'] ?? '',
			'record_id' => $entry['recordId'] ?? '',
			'field_name' => $entry['fieldName'] ?? '',
			'old_value' => $entry['oldValue'] ?? '',
			'new_value' => $entry['newValue'] ?? ''
		];
		$newId++;
	}

	return writeEncryptedJsonFile(DATA_DIR . '/audit_trail.json', ['entries' => $auditEntries]);
}


function addAuditEntry($changeType, $recordId, $fieldName, $oldValue, $newValue) {
	return addAuditEntries([[
		'changeType' => $changeType,
		'recordId' => $recordId,
		'fieldName' => $fieldName,
		'oldValue' => $oldValue,
		'newValue' => $newValue
	]]);
}


function getNextDataRecordId($items) {
	$maxId = 0;
	foreach ($items as $item) {
		$itemId = isset($item['id']) ? (int)$item['id'] : 0;
		if ($itemId > $maxId) {
			$maxId = $itemId;
		}
	}
	return $maxId + 1;
}


function normalizeRecordText($value) {
	return trim((string)$value);
}


$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}

requireApiKeyAuthentication();

$initFile = DATA_DIR . '/.encrypted_init';
if (!file_exists($initFile) && (file_exists($dataFile) || file_exists($logsFile))) {
	if (file_exists($dataFile)) {
		$dataContent = file_get_contents($dataFile);
		if (substr($dataContent, 0, 4) !== 'base') {
			$encryptedData = encryptData($dataContent, $encryptionKey, $encryptionCipher);
			writeFileWithLockRetry($dataFile, $encryptedData);
		}
	}

	if (file_exists($logsFile)) {
		$logsContent = file_get_contents($logsFile);
		if (substr($logsContent, 0, 4) !== 'base') {
			$encryptedLogs = encryptData($logsContent, $encryptionKey, $encryptionCipher);
			writeFileWithLockRetry($logsFile, $encryptedLogs);
		}
	}

	writeFileWithLockRetry($initFile, 'initialized');
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
		$testData = [
			'items' => [
				['id' => 1, 'title' => 'Sample Entry 1', 'description' => 'This is a test entry to demonstrate the system.'],
				['id' => 2, 'title' => 'Sample Entry 2', 'description' => 'Another test entry showing the data management features.'],
				['id' => 3, 'title' => 'Sample Entry 3', 'description' => 'A third test entry to provide a complete example.']
			]
		];

		$dataWriteSuccess = writeEncryptedJsonFile($dataFile, $testData);
		$logsWriteSuccess = writeEncryptedJsonFile($logsFile, ['logs' => []]);
		$auditWriteSuccess = writeEncryptedJsonFile(DATA_DIR . '/audit_trail.json', ['entries' => []]);

		if ($dataWriteSuccess && $logsWriteSuccess && $auditWriteSuccess) {
			respondJson(200, ['success' => true, 'message' => 'Data reset successfully']);
		}

		respondJson(500, ['success' => false, 'message' => 'Failed to reset one or more data files']);
	}

	if ($postAction === 'add_audit_entry') {
		$success = addAuditEntry($data['changeType'] ?? '', $data['recordId'] ?? '', $data['fieldName'] ?? '', $data['oldValue'] ?? '', $data['newValue'] ?? '');
		if ($success) {
			respondJson(200, ['success' => true, 'message' => 'Audit entry added']);
		}
		respondJson(500, ['success' => false, 'message' => 'Failed to save audit entry']);
	}

	if ($postAction === 'add_audit_entries') {
		$success = addAuditEntries($data['entries'] ?? []);
		if ($success) {
			respondJson(200, ['success' => true, 'message' => 'Audit entries added']);
		}
		respondJson(500, ['success' => false, 'message' => 'Failed to save audit entries']);
	}

	if ($postAction === 'data_create') {
		$title = normalizeRecordText($data['title'] ?? '');
		$description = normalizeRecordText($data['description'] ?? '');

		if ($title === '' || $description === '') {
			respondJson(400, ['success' => false, 'message' => 'Title and description are required']);
		}

		$dataPayload = sanitizeDataPayload(getDataPayload());
		$newId = getNextDataRecordId($dataPayload['items']);
		$newItem = ['id' => $newId, 'title' => $title, 'description' => $description];
		$dataPayload['items'][] = $newItem;

		if (!saveDataPayload($dataPayload)) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		addAuditEntries([
			['changeType' => 'ADD', 'recordId' => $newId, 'fieldName' => 'title', 'oldValue' => '', 'newValue' => $title],
			['changeType' => 'ADD', 'recordId' => $newId, 'fieldName' => 'description', 'oldValue' => '', 'newValue' => $description]
		]);
		addLog('A new entry has been added; ID ' . $newId . ' with title: "' . $title . '", and description: "' . $description . '"');

		respondJson(200, ['success' => true, 'message' => 'Record created successfully', 'item' => $newItem]);
	}

	if ($postAction === 'data_update') {
		$recordId = isset($data['id']) ? (int)$data['id'] : 0;
		$title = normalizeRecordText($data['title'] ?? '');
		$description = normalizeRecordText($data['description'] ?? '');

		if ($recordId < 1 || $title === '' || $description === '') {
			respondJson(400, ['success' => false, 'message' => 'Valid id, title, and description are required']);
		}

		$dataPayload = sanitizeDataPayload(getDataPayload());
		$items = $dataPayload['items'];
		$foundIndex = -1;

		for ($i = 0; $i < count($items); $i++) {
			if ((int)$items[$i]['id'] === $recordId) {
				$foundIndex = $i;
				break;
			}
		}

		if ($foundIndex === -1) {
			respondJson(404, ['success' => false, 'message' => 'Record not found']);
		}

		$existingItem = $items[$foundIndex];
		$auditEntries = [];
		if ($existingItem['title'] !== $title) {
			$auditEntries[] = ['changeType' => 'EDIT', 'recordId' => $recordId, 'fieldName' => 'title', 'oldValue' => $existingItem['title'], 'newValue' => $title];
		}
		if ($existingItem['description'] !== $description) {
			$auditEntries[] = ['changeType' => 'EDIT', 'recordId' => $recordId, 'fieldName' => 'description', 'oldValue' => $existingItem['description'], 'newValue' => $description];
		}

		$items[$foundIndex]['title'] = $title;
		$items[$foundIndex]['description'] = $description;
		$dataPayload['items'] = $items;

		if (!saveDataPayload($dataPayload)) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		if (!empty($auditEntries)) {
			addAuditEntries($auditEntries);
		}
		addLog('An entry has been edited; ID ' . $recordId . ' with title: "' . $title . '", and description: "' . $description . '"');

		respondJson(200, ['success' => true, 'message' => 'Record updated successfully', 'item' => $items[$foundIndex]]);
	}

	if ($postAction === 'data_delete') {
		$recordId = isset($data['id']) ? (int)$data['id'] : 0;
		if ($recordId < 1) {
			respondJson(400, ['success' => false, 'message' => 'A valid record id is required']);
		}

		$dataPayload = sanitizeDataPayload(getDataPayload());
		$deletedItem = null;
		$remainingItems = [];

		foreach ($dataPayload['items'] as $item) {
			if ((int)$item['id'] === $recordId) {
				$deletedItem = $item;
				continue;
			}
			$remainingItems[] = $item;
		}

		if ($deletedItem === null) {
			respondJson(404, ['success' => false, 'message' => 'Record not found']);
		}

		$dataPayload['items'] = $remainingItems;
		if (!saveDataPayload($dataPayload)) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		addAuditEntry('DELETE', $recordId, 'record', 'Title: "' . $deletedItem['title'] . '", Description: "' . $deletedItem['description'] . '"', '');
		addLog('An entry has been deleted; ID ' . $deletedItem['id'] . ' with title: "' . $deletedItem['title'] . '", and description: "' . $deletedItem['description'] . '"');

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

		$dataPayload = sanitizeDataPayload(getDataPayload());
		$deletedItems = [];
		$remainingItems = [];

		foreach ($dataPayload['items'] as $item) {
			$itemId = (int)$item['id'];
			if (isset($normalizedIds[$itemId])) {
				$deletedItems[] = $item;
				continue;
			}
			$remainingItems[] = $item;
		}

		if (empty($deletedItems)) {
			respondJson(404, ['success' => false, 'message' => 'No matching records were found']);
		}

		$dataPayload['items'] = $remainingItems;
		if (!saveDataPayload($dataPayload)) {
			respondJson(500, ['success' => false, 'message' => 'Failed to save data']);
		}

		$deletedIds = [];
		$auditEntries = [];
		foreach ($deletedItems as $item) {
			$deletedIds[] = $item['id'];
			$auditEntries[] = [
				'changeType' => 'DELETE',
				'recordId' => $item['id'],
				'fieldName' => 'record',
				'oldValue' => 'Title: "' . $item['title'] . '", Description: "' . $item['description'] . '"',
				'newValue' => ''
			];
		}

		$auditEntries[] = [
			'changeType' => 'DELETE',
			'recordId' => 'BULK',
			'fieldName' => 'bulk_action',
			'oldValue' => 'Selected IDs: ' . implode(', ', $deletedIds),
			'newValue' => 'Deleted ' . count($deletedItems) . ' records'
		];

		addAuditEntries($auditEntries);
		addLog('Bulk delete completed for ' . count($deletedItems) . ' records (IDs: ' . implode(', ', $deletedIds) . ')');

		respondJson(200, ['success' => true, 'message' => 'Bulk delete completed successfully', 'deletedCount' => count($deletedItems), 'deletedIds' => $deletedIds]);
	}

	$eventMessage = $data['event'] ?? '';
	$logSuccess = true;
	if ($eventMessage !== '') {
		$logSuccess = addLog($eventMessage);
	}

	if ($action !== 'report_generated' && $action !== 'report_downloaded') {
		$payload = sanitizeDataPayload($data['data'] ?? ['items' => []]);
		if (saveDataPayload($payload) && $logSuccess) {
			respondJson(200, ['success' => true, 'message' => 'Data saved successfully']);
		}

		$errorMsg = $logSuccess ? 'Failed to save data' : 'Failed to save log entry';
		respondJson(500, ['success' => false, 'message' => $errorMsg]);
	}

	respondJson(200, ['success' => true, 'message' => 'Event logged successfully']);
}


if ($method === 'GET') {
	if ($action === 'audit_trail') {
		echo json_encode(getAuditPayload());
		exit;
	}

	if ($action === 'logs') {
		echo json_encode(getLogsPayload());
		exit;
	}

	if ($action === 'data_page') {
		echo json_encode(buildDataPagePayload(false));
		exit;
	}

	if ($action === 'data_filtered_export') {
		echo json_encode(buildDataPagePayload(true));
		exit;
	}

	echo json_encode(getDataPayload());
	exit;
}


respondJson(405, ['success' => false, 'message' => 'Method not allowed']);
?>

