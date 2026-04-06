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

define('API_KEY', getenv('API_KEY') ?: 'local-dev-api-key-change-me');
define('API_KEY_HEADER', 'X-API-Key');
define('DB_CONNECTION', strtolower(getenv('DB_CONNECTION') ?: 'mysql'));
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_DATABASE', getenv('DB_DATABASE') ?: 'test_dashboard');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Europe/London');

// Use a single explicit app timezone so DST is handled by PHP's timezone database.
if (!@date_default_timezone_set(APP_TIMEZONE)) {
	date_default_timezone_set('UTC');
}

function envToBool($value, $default = false) {
	if ($value === false || $value === null || $value === '') {
		return $default;
	}

	$value = strtolower(trim((string)$value));
	return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$appMode = strtolower(getenv('APP_MODE') ?: 'demo');
if (!in_array($appMode, ['demo', 'production'], true)) {
	$appMode = 'demo';
}
define('APP_MODE', $appMode);

$defaultResetOnIndexVisit = APP_MODE === 'demo';
define('RESET_ON_INDEX_VISIT', envToBool(getenv('RESET_ON_INDEX_VISIT'), $defaultResetOnIndexVisit));
?>

