<?php


$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
	$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
			list($key, $value) = explode('=', $line, 2);
			$key = trim($key);
			$value = trim($value, '\'"');
			if (!getenv($key)) {
				putenv("{$key}={$value}");
			}
		}
	}
}

define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'showcase-dashboard-encryption-key-2026');
define('ENCRYPTION_CIPHER', 'AES-256-CBC');

define('DATA_DIR', __DIR__ . '/../data');
define('DATA_FILE', DATA_DIR . '/data.json');
define('LOGS_FILE', DATA_DIR . '/logs.json');

if (!is_dir(DATA_DIR)) {
	mkdir(DATA_DIR, 0755, true);
}
?>

