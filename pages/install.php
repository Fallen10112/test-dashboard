<?php

function installLoadEnvFile($envFile) {
	$values = [];
	if (!is_file($envFile)) {
		return $values;
	}

	$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!is_array($lines)) {
		return $values;
	}

	foreach ($lines as $line) {
		$trimmedLine = trim((string)$line);
		if ($trimmedLine === '' || strpos($trimmedLine, '#') === 0 || strpos($trimmedLine, '=') === false) {
			continue;
		}

		list($key, $value) = explode('=', $trimmedLine, 2);
		$key = trim($key);
		if ($key === '') {
			continue;
		}

		$values[$key] = trim($value, "\"'");
	}

	return $values;
}

function installResolveDatabaseConfig() {
	$envFile = __DIR__ . '/../config/.env';
	$values = installLoadEnvFile($envFile);

	$connection = strtolower((string)(getenv('DB_CONNECTION') ?: ($values['DB_CONNECTION'] ?? 'mysql')));
	$host = trim((string)(getenv('DB_HOST') ?: ($values['DB_HOST'] ?? '127.0.0.1')));
	$port = trim((string)(getenv('DB_PORT') ?: ($values['DB_PORT'] ?? '3306')));
	$database = trim((string)(getenv('DB_DATABASE') ?: ($values['DB_DATABASE'] ?? 'test_dashboard')));
	$username = trim((string)(getenv('DB_USERNAME') ?: ($values['DB_USERNAME'] ?? 'root')));
	$password = (string)(getenv('DB_PASSWORD') ?: ($values['DB_PASSWORD'] ?? ''));
	$charset = trim((string)(getenv('DB_CHARSET') ?: ($values['DB_CHARSET'] ?? 'utf8mb4')));

	return [
		'connection' => $connection === '' ? 'mysql' : $connection,
		'host' => $host === '' ? '127.0.0.1' : $host,
		'port' => $port === '' ? '3306' : $port,
		'database' => $database === '' ? 'test_dashboard' : $database,
		'username' => $username,
		'password' => $password,
		'charset' => $charset === '' ? 'utf8mb4' : $charset,
	];
}

function installQuoteIdentifier($identifier) {
	return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function installBuildPdo(array $databaseConfig, $includeDatabase = true) {
	$dsn = $includeDatabase
		? sprintf(
			'mysql:host=%s;port=%s;dbname=%s;charset=%s',
			$databaseConfig['host'],
			$databaseConfig['port'],
			$databaseConfig['database'],
			$databaseConfig['charset']
		)
		: sprintf(
			'mysql:host=%s;port=%s;charset=%s',
			$databaseConfig['host'],
			$databaseConfig['port'],
			$databaseConfig['charset']
		);

	return new PDO($dsn, $databaseConfig['username'], $databaseConfig['password'], [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
}

function installEnsureDatabaseExists(array $databaseConfig) {
	$serverPdo = installBuildPdo($databaseConfig, false);
	$charset = preg_match('/^[a-z0-9_]+$/i', $databaseConfig['charset']) ? $databaseConfig['charset'] : 'utf8mb4';
	$collation = stripos($charset, 'utf8mb4') === 0 ? 'utf8mb4_unicode_ci' : 'utf8mb4_unicode_ci';
	$serverPdo->exec(
		'CREATE DATABASE IF NOT EXISTS ' . installQuoteIdentifier($databaseConfig['database']) .
		' CHARACTER SET ' . $charset . ' COLLATE ' . $collation
	);
}

function installCreateAppSettingsTable(PDO $pdo) {
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS app_settings (
			id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key VARCHAR(100) NOT NULL,
			setting_value VARCHAR(255) DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_app_setting_key (setting_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
}

function installUpsertAppSetting(PDO $pdo, $settingKey, $settingValue) {
	$stmt = $pdo->prepare(
		'INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
		 VALUES (:setting_key, :setting_value, :created_at, :updated_at)
		 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
	);
	$timestamp = date('Y-m-d H:i:s');
	return $stmt->execute([
		':setting_key' => substr(trim((string)$settingKey), 0, 100),
		':setting_value' => substr((string)$settingValue, 0, 255),
		':created_at' => $timestamp,
		':updated_at' => $timestamp,
	]);
}

function installImportSchemaDump(PDO $pdo, $schemaFile) {
	if (!is_file($schemaFile)) {
		throw new RuntimeException('Schema file was not found.');
	}

	$schemaSql = file_get_contents($schemaFile);
	if ($schemaSql === false) {
		throw new RuntimeException('Unable to read the schema file.');
	}

	$schemaSql = preg_replace('/^\xEF\xBB\xBF/', '', $schemaSql);
	$lines = preg_split('/\R/', $schemaSql);
	$filteredLines = [];
	foreach ($lines as $line) {
		$trimmedLine = trim((string)$line);
		if ($trimmedLine === '' || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '/*!') === 0) {
			continue;
		}
		$filteredLines[] = $line;
	}

	$chunks = preg_split('/;\s*(?:\R|$)/', implode("\n", $filteredLines));
	foreach ($chunks as $chunk) {
		$statement = trim((string)$chunk);
		if ($statement === '') {
			continue;
		}

		if (preg_match('/^(SET|START TRANSACTION|COMMIT)\b/i', $statement)) {
			continue;
		}

		if (preg_match('/^(CREATE TABLE|ALTER TABLE)\s+`?app_settings`?\b/i', $statement)) {
			continue;
		}

		$pdo->exec($statement);
	}
}

function installDetectInstallationState(PDO $pdo) {
	try {
		if (!installTableExists($pdo, 'users') || !installTableExists($pdo, 'roles')) {
			return false;
		}

		$settingsStmt = $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key IN ('app_timezone', 'app_mode', 'reset_on_index_visit')");
		$settingCount = (int)$settingsStmt->fetchColumn();
		$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

		return $settingCount >= 3 && $userCount > 0;
	} catch (Throwable $e) {
		return false;
	}
}

function installTableExists(PDO $pdo, $tableName) {
	$stmt = $pdo->prepare(
		'SELECT 1
		 FROM information_schema.tables
		 WHERE table_schema = DATABASE()
		   AND table_name = :table_name
		 LIMIT 1'
	);
	$stmt->execute([':table_name' => (string)$tableName]);
	return (bool)$stmt->fetchColumn();
}

function installDeleteSelfWhenDone($installFile) {
	register_shutdown_function(static function () use ($installFile) {
		if (is_file($installFile)) {
			@unlink($installFile);
		}
	});
}

function installValidateRequest(array $requestData) {
	$errors = [];
	$displayName = trim((string)($requestData['display_name'] ?? ''));
	$username = trim((string)($requestData['username'] ?? ''));
	$email = trim((string)($requestData['email'] ?? ''));
	$password = (string)($requestData['password'] ?? '');

	if ($displayName === '') {
		$errors[] = 'Display name is required.';
	} elseif (strlen($displayName) > 150) {
		$errors[] = 'Display name must not exceed 150 characters.';
	}

	if ($username === '') {
		$errors[] = 'Username is required.';
	} elseif (strlen($username) > 100) {
		$errors[] = 'Username must not exceed 100 characters.';
	}

	if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'A valid email address is required.';
	} elseif (strlen($email) > 255) {
		$errors[] = 'Email must not exceed 255 characters.';
	}

	if (strlen($password) < 8) {
		$errors[] = 'Password must be at least 8 characters long.';
	}

	return [
		'errors' => $errors,
		'display_name' => $displayName,
		'username' => $username,
		'email' => $email,
		'password' => $password,
	];
}

session_start();

$databaseConfig = installResolveDatabaseConfig();
$schemaFile = __DIR__ . '/../test_dashboard.sql';
$errors = [];
$successPayload = null;
$existingInstall = false;
$submittedValues = [
	'display_name' => '',
	'username' => '',
	'email' => '',
];

try {
	if (strtolower((string)$databaseConfig['connection']) !== 'mysql') {
		throw new RuntimeException('Only MySQL connections are supported by the installer.');
	}

	installEnsureDatabaseExists($databaseConfig);
	$bootstrapPdo = installBuildPdo($databaseConfig, true);
	installCreateAppSettingsTable($bootstrapPdo);

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$submittedValues = [
			'display_name' => trim((string)($_POST['display_name'] ?? '')),
			'username' => trim((string)($_POST['username'] ?? '')),
			'email' => trim((string)($_POST['email'] ?? '')),
		];
		$validated = installValidateRequest($_POST);
		$errors = $validated['errors'];

		if (empty($errors)) {
			installUpsertAppSetting($bootstrapPdo, 'app_timezone', 'UTC');
			installUpsertAppSetting($bootstrapPdo, 'app_mode', 'production');
			installUpsertAppSetting($bootstrapPdo, 'reset_on_index_visit', 'false');
			$appApiKey = 'ak_' . bin2hex(random_bytes(32));
			installUpsertAppSetting($bootstrapPdo, 'api_key', $appApiKey);
			installUpsertAppSetting($bootstrapPdo, 'app_api_key', $appApiKey);

			require_once __DIR__ . '/../config/config.php';
			require_once __DIR__ . '/../includes/sql_helpers.php';

			installImportSchemaDump($bootstrapPdo, $schemaFile);

			$roleStmt = $bootstrapPdo->prepare(
				'INSERT INTO roles (id, name, description, created_at)
				 VALUES (1, :name, :description, :created_at)
				 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
			);
			$roleStmt->execute([
				':name' => 'Full Access',
				':description' => 'Full access to every page, resource, and action.',
				':created_at' => getDashboardSqlTimestamp(),
			]);

			ensurePermissionsSchema($bootstrapPdo);

			$passwordHash = password_hash($validated['password'], PASSWORD_BCRYPT, ['cost' => 12]);
			$userStmt = $bootstrapPdo->prepare(
				'INSERT INTO users (email, username, password_hash, display_name, status, created_at, updated_at, deleted_at)
				 VALUES (:email, :username, :password_hash, :display_name, :status, :created_at, :updated_at, NULL)'
			);
			$userStmt->execute([
				':email' => substr($validated['email'], 0, 255),
				':username' => substr($validated['username'], 0, 100),
				':password_hash' => $passwordHash,
				':display_name' => substr($validated['display_name'], 0, 150),
				':status' => 'active',
				':created_at' => getDashboardSqlTimestamp(),
				':updated_at' => getDashboardSqlTimestamp(),
			]);
			$userId = (int)$bootstrapPdo->lastInsertId();
			if (!syncUserPrimaryRole($bootstrapPdo, $userId, 1, null)) {
				throw new RuntimeException('The new user could not be assigned to the full access role.');
			}

			installDeleteSelfWhenDone(__FILE__);
			$successPayload = [
				'display_name' => $validated['display_name'],
				'username' => $validated['username'],
				'email' => $validated['email'],
			];
		}
	}

	if ($successPayload === null) {
		$existingInstall = installDetectInstallationState($bootstrapPdo);
	}
} catch (Throwable $e) {
	$errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'Installation failed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Install Dashboard Showcase</title>
	<link rel="stylesheet" href="../css/style.css">
</head>
<body class="install-body">
	<div class="install-wrapper">
		<div class="install-card">
			<div class="install-header">
				<div class="install-header-copy">
					<div class="install-badge"><span class="install-badge-dot" aria-hidden="true"></span> First-time setup</div>
					<h1>Install Dashboard Showcase</h1>
					<p>Use this page once to create the database schema, seed the permissions model, and create the first full-access account. After success, the installer removes itself for security.</p>
				</div>
			</div>

			<div class="install-note">
				<strong>What this creates:</strong> all core tables, every permission/resource row, a default role with <strong>id 1</strong> named <strong>Full Access</strong>, and the initial user account you submit below.
			</div>

			<?php if (!empty($errors)): ?>
			<div class="install-status install-status--error" role="alert">
				<span class="install-status-title">Installation could not be completed.</span>
				<?php foreach ($errors as $errorMessage): ?>
				<div><?php echo htmlspecialchars((string)$errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if (is_array($successPayload)): ?>
			<div class="install-status install-status--success" role="status">
				<span class="install-status-title">Installation complete.</span>
				The dashboard schema was created, the full-access role was seeded, and your first user account is ready.
			</div>

			<div class="install-success-panel">
				<h2>Next step</h2>
				<p><strong>Username:</strong> <?php echo htmlspecialchars((string)$successPayload['username'], ENT_QUOTES, 'UTF-8'); ?><br>
				<strong>Email:</strong> <?php echo htmlspecialchars((string)$successPayload['email'], ENT_QUOTES, 'UTF-8'); ?><br>
				<strong>Display name:</strong> <?php echo htmlspecialchars((string)$successPayload['display_name'], ENT_QUOTES, 'UTF-8'); ?></p>
				<p>The installer has been scheduled for deletion. Open the login page and sign in with the account you just created.</p>
				<div class="install-success-actions">
					<a class="install-link" href="login.php">Go to Login</a>
					<a class="install-link install-link--secondary" href="../index.php">Open Landing Page</a>
				</div>
			</div>
			<?php elseif ($existingInstall): ?>
			<div class="install-status install-status--success" role="status">
				<span class="install-status-title">This installer is no longer needed.</span>
				The database already contains an installation. If you are expecting a fresh setup, remove the existing data first.
			</div>
			<div class="install-success-panel">
				<h2>Open the application</h2>
				<p>The login page should now be available for existing accounts.</p>
				<div class="install-success-actions">
					<a class="install-link" href="login.php">Go to Login</a>
					<a class="install-link install-link--secondary" href="../index.php">Open Landing Page</a>
				</div>
			</div>
			<?php else: ?>
			<div class="install-layout">
				<section class="install-side-card">
					<h3>Installation checklist</h3>
					<ol class="install-step-list">
						<li>Creates the database if it does not already exist.</li>
						<li>Creates the project schema from the bundled SQL layout.</li>
						<li>Seeds every resource and permission used by the app.</li>
						<li>Creates role <strong>1</strong> as <strong>Full Access</strong>.</li>
						<li>Creates your first user and assigns that role.</li>
						<li>Deletes this installer after a successful setup.</li>
					</ol>
				</section>

				<section class="install-side-card">
					<h3>Create the first account</h3>
					<form class="install-form login-form" method="POST" action="install.php" autocomplete="off">
						<div class="login-field">
							<label for="display_name">Display Name</label>
							<input type="text" id="display_name" name="display_name" value="<?php echo htmlspecialchars((string)$submittedValues['display_name'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your name" maxlength="150" required autofocus>
						</div>

						<div class="login-field">
							<label for="username">Username</label>
							<input type="text" id="username" name="username" value="<?php echo htmlspecialchars((string)$submittedValues['username'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="admin" maxlength="100" required>
						</div>

						<div class="login-field">
							<label for="email">Email</label>
							<input type="email" id="email" name="email" value="<?php echo htmlspecialchars((string)$submittedValues['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="admin@example.com" maxlength="255" required>
						</div>

						<div class="login-field">
							<label for="password">Password</label>
							<input type="password" id="password" name="password" placeholder="Create a strong password" minlength="8" required>
						</div>

						<button type="submit" class="btn btn-login">Create Installation</button>
					</form>
				</section>
			</div>
			<?php endif; ?>
		</div>

		<div class="install-footer">
			This page is intended for first-time deployments only. After a successful install, use the <strong>Login</strong> page to access the dashboard.
		</div>
	</div>
</body>
</html>