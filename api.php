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


function encryptData($data, $key, $cipher) {
	$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
	$encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);
	return base64_encode($iv . $encrypted);
}


function decryptData($encryptedData, $key, $cipher) {
	$data = base64_decode($encryptedData);
	$iv = substr($data, 0, openssl_cipher_iv_length($cipher));
	$encrypted = substr($data, openssl_cipher_iv_length($cipher));
	return openssl_decrypt($encrypted, $cipher, $key, 0, $iv);
}

function addLog($event) {
	global $encryptionKey, $encryptionCipher;
	
	$logsFile = LOGS_FILE;
	
	if (!is_dir(DATA_DIR)) {
		mkdir(DATA_DIR, 0755, true);
	}
	
	$logs = [];
	if (file_exists($logsFile)) {
		$encryptedContent = file_get_contents($logsFile);
		$decryptedContent = decryptData($encryptedContent, $encryptionKey, $encryptionCipher);
		
		if ($decryptedContent !== false) {
			$logsData = json_decode($decryptedContent, true);
			$logs = is_array($logsData) && isset($logsData['logs']) ? $logsData['logs'] : [];
		}
	}
	
	$date = date('Y-m-d', time() - 3600);
	$time = date('H:i:s', time() - 3600);
	
	$newId = 1;
	if (!empty($logs) && is_array($logs)) {
		$ids = array_column($logs, 'id');
		if (!empty($ids)) {
			$newId = max($ids) + 1;
		}
	}
	
	$logEntry = [
		'id' => $newId,
		'date' => $date,
		'time' => $time,
		'event' => $event
	];
	
	$logs[] = $logEntry;
	
	$logsData = ['logs' => $logs];
	$jsonContent = json_encode($logsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$encryptedContent = encryptData($jsonContent, $encryptionKey, $encryptionCipher);
	return file_put_contents($logsFile, $encryptedContent) !== false;
}

function addAuditEntries($entries) {
	global $encryptionKey, $encryptionCipher;
	
	$auditFile = DATA_DIR . '/audit_trail.json';
	
	if (!is_dir(DATA_DIR)) {
		mkdir(DATA_DIR, 0755, true);
	}
	
	$auditEntries = [];
	if (file_exists($auditFile)) {
		$encryptedContent = file_get_contents($auditFile);
		$decryptedContent = decryptData($encryptedContent, $encryptionKey, $encryptionCipher);
		
		if ($decryptedContent !== false) {
			$auditData = json_decode($decryptedContent, true);
			$auditEntries = is_array($auditData) && isset($auditData['entries']) ? $auditData['entries'] : [];
		}
	}
	
	$date = date('Y-m-d', time() - 3600);
	$time = date('H:i:s', time() - 3600);
	
	$newId = 1;
	if (!empty($auditEntries) && is_array($auditEntries)) {
		$ids = array_column($auditEntries, 'id');
		if (!empty($ids)) {
			$newId = max($ids) + 1;
		}
	}

	if (!is_array($entries) || empty($entries)) {
		return false;
	}

	foreach ($entries as $entry) {
		$auditEntry = [
			'id' => $newId,
			'date' => $date,
			'time' => $time,
			'change_type' => $entry['changeType'] ?? '',
			'record_id' => $entry['recordId'] ?? '',
			'field_name' => $entry['fieldName'] ?? '',
			'old_value' => $entry['oldValue'] ?? '',
			'new_value' => $entry['newValue'] ?? ''
		];

		$auditEntries[] = $auditEntry;
		$newId++;
	}
	
	$auditData = ['entries' => $auditEntries];
	$jsonContent = json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$encryptedContent = encryptData($jsonContent, $encryptionKey, $encryptionCipher);
	return file_put_contents($auditFile, $encryptedContent) !== false;
}

function addAuditEntry($changeType, $recordId, $fieldName, $oldValue, $newValue) {
	$entries = [[
		'changeType' => $changeType,
		'recordId' => $recordId,
		'fieldName' => $fieldName,
		'oldValue' => $oldValue,
		'newValue' => $newValue
	]];

	return addAuditEntries($entries);
}

$method = $_SERVER['REQUEST_METHOD'];

$initFile = DATA_DIR . '/.encrypted_init';
if (!file_exists($initFile) && (file_exists($dataFile) || file_exists($logsFile))) {
	if (file_exists($dataFile)) {
		$dataContent = file_get_contents($dataFile);
		if (substr($dataContent, 0, 4) !== 'base') { // Check if not already encrypted
			$encryptedData = encryptData($dataContent, $encryptionKey, $encryptionCipher);
			file_put_contents($dataFile, $encryptedData);
		}
	}
	
	if (file_exists($logsFile)) {
		$logsContent = file_get_contents($logsFile);
		if (substr($logsContent, 0, 4) !== 'base') { // Check if not already encrypted
			$encryptedLogs = encryptData($logsContent, $encryptionKey, $encryptionCipher);
			file_put_contents($logsFile, $encryptedLogs);
		}
	}
	
	file_put_contents($initFile, 'initialized');
}

if ($method === 'POST') {
	$input = file_get_contents('php://input');
	$data = json_decode($input, true);
	
	if ($data !== null) {
		if (!is_dir(DATA_DIR)) {
			mkdir(DATA_DIR, 0755, true);
		}
		
		if (($data['action'] ?? '') === 'reset_data') {
			
			$testData = json_encode([
				'items' => [
					[
						'id' => 1,
						'title' => 'Sample Entry 1',
						'description' => 'This is a test entry to demonstrate the system.'
					],
					[
						'id' => 2,
						'title' => 'Sample Entry 2',
						'description' => 'Another test entry showing the data management features.'
					],
					[
						'id' => 3,
						'title' => 'Sample Entry 3',
						'description' => 'A third test entry to provide a complete example.'
					]
				]
			]);
			
			$encryptedData = encryptData($testData, $encryptionKey, $encryptionCipher);
			file_put_contents($dataFile, $encryptedData);
			
			$emptyLogs = json_encode(['logs' => []]);
			$encryptedLogs = encryptData($emptyLogs, $encryptionKey, $encryptionCipher);
			file_put_contents($logsFile, $encryptedLogs);
			
			$auditFile = DATA_DIR . '/audit_trail.json';
			$emptyAudit = json_encode(['entries' => []]);
			$encryptedAudit = encryptData($emptyAudit, $encryptionKey, $encryptionCipher);
			file_put_contents($auditFile, $encryptedAudit);
			
			http_response_code(200);
			echo json_encode(['success' => true, 'message' => 'Data reset successfully']);
			exit;
		}
		
		if (($data['action'] ?? '') === 'add_audit_entry') {
			$changeType = $data['changeType'] ?? '';
			$recordId = $data['recordId'] ?? '';
			$fieldName = $data['fieldName'] ?? '';
			$oldValue = $data['oldValue'] ?? '';
			$newValue = $data['newValue'] ?? '';
			
			$success = addAuditEntry($changeType, $recordId, $fieldName, $oldValue, $newValue);
			if ($success) {
				http_response_code(200);
				echo json_encode(['success' => true, 'message' => 'Audit entry added']);
			} else {
				http_response_code(500);
				echo json_encode(['success' => false, 'message' => 'Failed to save audit entry']);
			}
			exit;
		}

		if (($data['action'] ?? '') === 'add_audit_entries') {
			$entries = $data['entries'] ?? [];

			$success = addAuditEntries($entries);
			if ($success) {
				http_response_code(200);
				echo json_encode(['success' => true, 'message' => 'Audit entries added']);
			} else {
				http_response_code(500);
				echo json_encode(['success' => false, 'message' => 'Failed to save audit entries']);
			}
			exit;
		}else {
			$eventMessage = $data['event'] ?? '';
			
			$logSuccess = true;
			if ($eventMessage !== '') {
				$logSuccess = addLog($eventMessage);
			}
			
			if ($action !== 'report_generated' && $action !== 'report_downloaded') {
				$jsonContent = json_encode($data['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				$encryptedContent = encryptData($jsonContent, $encryptionKey, $encryptionCipher);
				
				if (file_put_contents($dataFile, $encryptedContent) && $logSuccess) {
					http_response_code(200);
					echo json_encode(['success' => true, 'message' => 'Data saved successfully']);
				} else {
					http_response_code(500);
					$errorMsg = 'Failed to save data';
					if (!$logSuccess) {
						$errorMsg = 'Failed to save log entry';
					}
					echo json_encode(['success' => false, 'message' => $errorMsg]);
				}
			} else {
				http_response_code(200);
				echo json_encode(['success' => true, 'message' => 'Event logged successfully']);
			}
			exit;
		}
	} else {
		http_response_code(400);
		echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
	}
	exit;
} else if ($method === 'GET') {

	if ($action === 'audit_trail') {
		$file = DATA_DIR . '/audit_trail.json';
		
		if (!file_exists($file) || filesize($file) < 50) {
			$initialData = json_encode(['entries' => []]);
			$encryptedData = encryptData($initialData, $encryptionKey, $encryptionCipher);
			file_put_contents($file, $encryptedData);
			echo $initialData;
		} else {
			$fileContent = file_get_contents($file);
			
			$decryptedContent = decryptData($fileContent, $encryptionKey, $encryptionCipher);
			if ($decryptedContent !== false && !empty($decryptedContent)) {
				header('Content-Type: application/json');
				echo $decryptedContent;
			} else {
				$parsed = json_decode($fileContent, true);
				if ($parsed !== null) {
					$encryptedData = encryptData($fileContent, $encryptionKey, $encryptionCipher);
					file_put_contents($file, $encryptedData);
					header('Content-Type: application/json');
					echo $fileContent;
				} else {
					$initialData = json_encode(['entries' => []]);
					$encryptedData = encryptData($initialData, $encryptionKey, $encryptionCipher);
					file_put_contents($file, $encryptedData);
					echo $initialData;
				}
			}
		}
	} else {
		$file = ($action === 'logs') ? $logsFile : $dataFile;
		
		if (file_exists($file)) {
			$fileContent = file_get_contents($file);
			
			$decryptedContent = decryptData($fileContent, $encryptionKey, $encryptionCipher);
			if ($decryptedContent !== false) {
				header('Content-Type: application/json');
				echo $decryptedContent;
			} else {
				header('Content-Type: application/json');
				echo $fileContent;
			}
		} else {
			if ($action === 'logs') {
				$initialData = json_encode(['logs' => []]);
			} else {
				$initialData = json_encode(['items' => []]);
			}
			$encryptedData = encryptData($initialData, $encryptionKey, $encryptionCipher);
			file_put_contents($file, $encryptedData);
			header('Content-Type: application/json');
			echo $initialData;
		}
	}
	exit;
} else {
	http_response_code(405);
	echo json_encode(['success' => false, 'message' => 'Method not allowed']);
	exit;
}
?>

