<?php
require_once __DIR__ . '/config/config.php';

function encryptDataForReset($data, $key, $cipher) {
	$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
	$encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);
	return base64_encode($iv . $encrypted);
}

function resetDashboardFiles() {
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

	$emptyLogs = json_encode(['logs' => []]);
	$emptyAudit = json_encode(['entries' => []]);
	$auditFile = DATA_DIR . '/audit_trail.json';

	file_put_contents(DATA_FILE, encryptDataForReset($testData, ENCRYPTION_KEY, ENCRYPTION_CIPHER));
	file_put_contents(LOGS_FILE, encryptDataForReset($emptyLogs, ENCRYPTION_KEY, ENCRYPTION_CIPHER));
	file_put_contents($auditFile, encryptDataForReset($emptyAudit, ENCRYPTION_KEY, ENCRYPTION_CIPHER));
}

if (RESET_ON_INDEX_VISIT) {
	resetDashboardFiles();
}

header('Location: pages/home.php');
exit();
?>

