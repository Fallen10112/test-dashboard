<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/sql_helpers.php';

define('AUTH_SESSION_LIFETIME', 28800);
define('AUTH_SESSION_NAME', 'dashboard_session');

function startAuthSession(): void {
	if (session_status() === PHP_SESSION_NONE) {
		session_name(AUTH_SESSION_NAME);
		session_set_cookie_params([
			'lifetime' => 0,
			'path'     => '/',
			'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
			'httponly' => true,
			'samesite' => 'Strict',
		]);
		session_start();
	}
}

function getAuthUser(): ?array {
	if (!isset($_SESSION['auth_token'], $_SESSION['auth_user_id'])) {
		return null;
	}

	$tokenHash = hash('sha256', $_SESSION['auth_token']);

	try {
		$pdo = getDashboardPdo();
		$stmt = $pdo->prepare(
			'SELECT s.id AS session_id, s.expires_at, s.revoked_at,
			        u.id, u.email, u.username, u.display_name, u.status
			   FROM user_sessions s
			   JOIN users u ON u.id = s.user_id
			  WHERE s.session_token_hash = :hash
			    AND s.user_id = :uid
			    AND s.revoked_at IS NULL
			    AND s.expires_at > NOW()
			    AND u.status = \'active\'
			    AND u.deleted_at IS NULL'
		);
		$stmt->execute([':hash' => $tokenHash, ':uid' => (int) $_SESSION['auth_user_id']]);
		$row = $stmt->fetch();

		if (!$row) {
			return null;
		}

		$primaryRole = getUserPrimaryRole($pdo, (int)$row['id']);

		try {
			$touch = $pdo->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = :sid');
			$touch->execute([':sid' => $row['session_id']]);
		} catch (Throwable $e) {
		}

		return [
			'id'           => (int) $row['id'],
			'email'        => $row['email'],
			'username'     => $row['username'],
			'display_name' => $row['display_name'],
			'role_id'      => isset($primaryRole['id']) ? (int)$primaryRole['id'] : null,
			'role_name'    => isset($primaryRole['name']) ? $primaryRole['name'] : '',
		];
	} catch (Throwable $e) {
		return null;
	}
}

function getApiAuthUserDisplayName(): string {
	startAuthSession();
	$authUser = $GLOBALS['auth_user'] ?? null;
	if (!is_array($authUser)) {
		$authUser = getAuthUser();
	}

	if (!is_array($authUser)) {
		return 'User';
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

	return 'User';
}

function requireAuth(): void {
	startAuthSession();
	$user = getAuthUser();
	if ($user === null) {
		$reason = getAuthInvalidationReason();
		if ($reason === 'replaced') {
			$_SESSION['auth_flash_toast'] = [
				'type' => 'warning',
				'title' => 'Signed Out',
				'message' => 'You were signed out because this account logged in on another device.',
			];
		} elseif ($reason === 'expired') {
			$_SESSION['auth_flash_toast'] = [
				'type' => 'info',
				'title' => 'Session Expired',
				'message' => 'Your session expired. Please sign in again.',
			];
		}

		clearAuthSessionState();
		header('Location: login.php');
		exit();
	}
	$GLOBALS['auth_user'] = $user;
}

function getAuthInvalidationReason(): ?string {
	if (!isset($_SESSION['auth_token'], $_SESSION['auth_user_id'])) {
		return null;
	}

	$tokenHash = hash('sha256', $_SESSION['auth_token']);

	try {
		$pdo = getDashboardPdo();
		$stmt = $pdo->prepare(
			'SELECT revoked_at, expires_at
			   FROM user_sessions
			  WHERE session_token_hash = :hash
			    AND user_id = :uid
			  LIMIT 1'
		);
		$stmt->execute([':hash' => $tokenHash, ':uid' => (int) $_SESSION['auth_user_id']]);
		$row = $stmt->fetch();

		if (!$row) {
			return 'missing';
		}

		if (!empty($row['revoked_at'])) {
			return 'replaced';
		}

		if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) {
			return 'expired';
		}

		return 'invalid';
	} catch (Throwable $e) {
		return 'invalid';
	}
}

function clearAuthSessionState(): void {
	unset($_SESSION['auth_token'], $_SESSION['auth_user_id']);
	session_regenerate_id(true);
}

function getCsrfToken(): string {
	startAuthSession();
	if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return (string)$_SESSION['csrf_token'];
}

function isValidCsrfToken($token): bool {
	startAuthSession();
	if (!is_string($token) || $token === '') {
		return false;
	}
	$sessionToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
		? $_SESSION['csrf_token']
		: '';
	if ($sessionToken === '') {
		return false;
	}
	return hash_equals($sessionToken, $token);
}

function loginUser(string $identifier, string $password): bool {
	try {
		$pdo = getDashboardPdo();
		$stmt = $pdo->prepare(
			'SELECT id, password_hash, display_name, status, created_at, last_login_at
			   FROM users
			  WHERE (email = :identifier OR username = :identifier2)
			    AND deleted_at IS NULL
			  LIMIT 1'
		);
		$stmt->execute([':identifier' => $identifier, ':identifier2' => $identifier]);
		$user = $stmt->fetch();

		if (!$user) {
			password_verify($password, '$2y$12$fakehashtopreventtimingattack.......');
			return false;
		}

		if ($user['status'] !== 'active') {
			return false;
		}

		if (!password_verify($password, $user['password_hash'])) {
			return false;
		}

		if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
			$newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
			$pdo->prepare('UPDATE users SET password_hash = :h, updated_at = NOW() WHERE id = :id')
			    ->execute([':h' => $newHash, ':id' => $user['id']]);
		}

		$loginAt = getDashboardSqlTimestamp();
		$statsWindowStart = trim((string)($user['last_login_at'] ?? ''));
		if ($statsWindowStart === '') {
			$statsWindowStart = trim((string)($user['created_at'] ?? ''));
		}
		try {
			$loginUpdateNotificationsEnabled = true;
			if (function_exists('getStoredHeaderWidgetPreferences')) {
				$widgets = getStoredHeaderWidgetPreferences($pdo, (int)$user['id']);
				if (array_key_exists('login_updates', $widgets)) {
					$loginUpdateNotificationsEnabled = ((bool)$widgets['login_updates']);
				}
			}

			if ($statsWindowStart !== '' && $loginUpdateNotificationsEnabled) {
				$summary = getLoginUpdateNotificationSummary($pdo, $statsWindowStart, $loginAt);
				$summaryParts = [
					'<strong>Entries added:</strong> ' . number_format((int)($summary['entries_added'] ?? 0)),
					'<strong>Lines deleted:</strong> ' . number_format((int)($summary['lines_deleted'] ?? 0)),
				];
				if (trim((string)($user['last_login_at'] ?? '')) === '') {
					$summaryParts[] = '<strong>Window:</strong> since your account was created';
				}

				createNotification(
					$pdo,
					(int)$user['id'],
					'Updates since your last log on...',
					implode('<br>', $summaryParts),
					'info',
					null
				);
			}
		} catch (Throwable $e) {
		}

		$token     = bin2hex(random_bytes(32));
		$tokenHash = hash('sha256', $token);
		$expiresAt = date('Y-m-d H:i:s', time() + AUTH_SESSION_LIFETIME);
		$ip        = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
		$ua        = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

		$pdo->prepare(
			'UPDATE user_sessions
			    SET revoked_at = NOW()
			  WHERE user_id = :uid
			    AND revoked_at IS NULL'
		)->execute([':uid' => $user['id']]);

		$pdo->prepare(
			'INSERT INTO user_sessions (user_id, session_token_hash, expires_at, ip_address, user_agent, created_at)
			 VALUES (:uid, :hash, :expires, :ip, :ua, NOW())'
		)->execute([
			':uid'    => $user['id'],
			':hash'   => $tokenHash,
			':expires' => $expiresAt,
			':ip'     => $ip,
			':ua'     => $ua,
		]);

		$pdo->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id')
		    ->execute([
		    	':last_login_at' => $loginAt,
		    	':updated_at' => $loginAt,
		    	':id' => $user['id'],
		    ]);

		writeAuditEvent($pdo, [
			'record_type' => 'auth',
			'record_id' => null,
			'action' => 'login',
			'details' => 'Signed out -> Signed in successfully',
			'source_user_id' => (int)$user['id'],
		]);

		startAuthSession();
		session_regenerate_id(true);
		$_SESSION['auth_token']   = $token;
		$_SESSION['auth_user_id'] = $user['id'];

		return true;
	} catch (Throwable $e) {
		return false;
	}
}

function logoutUser(): void {
	startAuthSession();
	$authUserId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
	if (isset($_SESSION['auth_token'])) {
		$tokenHash = hash('sha256', $_SESSION['auth_token']);
		try {
			$pdo = getDashboardPdo();
			$pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_token_hash = :hash')
			    ->execute([':hash' => $tokenHash]);
			if ($authUserId > 0) {
				writeAuditEvent($pdo, [
					'record_type' => 'auth',
					'record_id' => null,
					'action' => 'logout',
					'details' => 'Signed in -> Signed out',
						'source_user_id' => $authUserId,
				]);
			}
		} catch (Throwable $e) {
		}
	}
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(
			session_name(),
			'',
			time() - 42000,
			$params['path'],
			$params['domain'],
			$params['secure'],
			$params['httponly']
		);
	}
	session_destroy();
}
