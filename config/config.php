<?php

// Load only DB connection settings from .env
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

define('DB_CONNECTION', strtolower(getenv('DB_CONNECTION') ?: 'mysql'));
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_DATABASE', getenv('DB_DATABASE') ?: 'test_dashboard');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Connect to DB and fetch app settings
function getAppSettingsFromDb() {
	try {
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
		// Use correct column names for your schema
		$stmt = $pdo->query('SELECT setting_key AS `key`, setting_value AS `value` FROM app_settings');
		$settings = [];
		foreach ($stmt as $row) {
			$settings[$row['key']] = $row['value'];
		}
		return $settings;
	} catch (Exception $e) {
		return [];
	}
}

$appSettings = getAppSettingsFromDb();

function getSetting($settings, $key, $default = null) {
	return isset($settings[$key]) ? $settings[$key] : $default;
}


// Helper to require a setting from the DB
function requireSetting($settings, $key) {
	if (!isset($settings[$key]) || $settings[$key] === '' || $settings[$key] === null) {
		die("FATAL: Required app setting '$key' missing from app_settings table.");
	}
	return $settings[$key];
}

define('API_KEY', requireSetting($appSettings, 'api_key'));
define('API_KEY_HEADER', 'X-API-Key');

$appTimezone = requireSetting($appSettings, 'app_timezone');
define('APP_TIMEZONE', $appTimezone);
if (!@date_default_timezone_set(APP_TIMEZONE)) {
	die("FATAL: Invalid timezone in app_settings: '" . $appTimezone . "'");
}

function envToBool($value) {
	if ($value === false || $value === null || $value === '') {
		return false;
	}
	$value = strtolower(trim((string)$value));
	return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$appMode = strtolower(requireSetting($appSettings, 'app_mode'));
if (!in_array($appMode, ['demo', 'production'], true)) {
	die("FATAL: Invalid app_mode in app_settings: '" . $appMode . "'. Allowed: demo, production");
}
define('APP_MODE', $appMode);

$resetOnIndexVisit = requireSetting($appSettings, 'reset_on_index_visit');
define('RESET_ON_INDEX_VISIT', envToBool($resetOnIndexVisit));

