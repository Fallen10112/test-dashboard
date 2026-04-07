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
		$stmt = $pdo->query('SELECT `key`, `value` FROM app_settings');
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

define('API_KEY', getSetting($appSettings, 'api_key', 'local-dev-api-key-change-me'));
define('API_KEY_HEADER', 'X-API-Key');

$appTimezone = getSetting($appSettings, 'app_timezone', 'Europe/London');
define('APP_TIMEZONE', $appTimezone);
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

$appMode = strtolower(getSetting($appSettings, 'app_mode', 'demo'));
if (!in_array($appMode, ['demo', 'production'], true)) {
	$appMode = 'demo';
}
define('APP_MODE', $appMode);

$defaultResetOnIndexVisit = APP_MODE === 'demo';
$resetOnIndexVisit = getSetting($appSettings, 'reset_on_index_visit', null);
define('RESET_ON_INDEX_VISIT', envToBool($resetOnIndexVisit, $defaultResetOnIndexVisit));

