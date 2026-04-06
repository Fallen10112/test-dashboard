<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';

startAuthSession();
requireAuth();

$authUser = $GLOBALS['auth_user'] ?? null;
$currentUserId = (int)($authUser['id'] ?? 0);
$csrfToken = getCsrfToken();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrfToken = (string)($_POST['csrf_token'] ?? '');
	if (!isValidCsrfToken($postedCsrfToken)) {
		$error = 'Invalid security token. Please refresh and try again.';
	}

	$currentPassword = (string)($_POST['current_password'] ?? '');
	$newPassword = (string)($_POST['new_password'] ?? '');
	$confirmPassword = (string)($_POST['confirm_password'] ?? '');

	if ($error === '' && ($currentPassword === '' || $newPassword === '' || $confirmPassword === '')) {
		$error = 'All password fields are required.';
	} elseif ($error === '' && strlen($newPassword) < 8) {
		$error = 'New password must be at least 8 characters long.';
	} elseif ($error === '' && !hash_equals($newPassword, $confirmPassword)) {
		$error = 'New password and confirmation do not match.';
	} elseif ($error === '') {
		try {
			$pdo = getDashboardPdo();
			$stmt = $pdo->prepare(
				'SELECT password_hash
				   FROM users
				  WHERE id = :id
				    AND status = \'active\'
				    AND deleted_at IS NULL
				  LIMIT 1'
			);
			$stmt->execute([':id' => $currentUserId]);
			$userRow = $stmt->fetch();

			if (!$userRow || !password_verify($currentPassword, (string)$userRow['password_hash'])) {
				$error = 'Current password is incorrect.';
			} elseif (password_verify($newPassword, (string)$userRow['password_hash'])) {
				$error = 'New password must be different from your current password.';
			} else {
				$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
				$update = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
				$update->execute([':hash' => $newHash, ':id' => $currentUserId]);
				writeAuditEvent($pdo, [
					'record_type' => 'user',
					'record_id' => $currentUserId,
					'action' => 'password_change',
					'details' => '[REDACTED] -> [REDACTED]',
					'actor_user_id' => $currentUserId,
				]);
				$success = 'Password updated successfully.';
			}
		} catch (Throwable $e) {
			$error = 'Unable to update password right now. Please try again.';
		}
	}
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	<main class="main-content">
		<section class="content-section active">
			<h2>Account Settings</h2>
			<div class="account-card">
				<h3>Change Password</h3>
				<p>Update your password for this account.</p>

				<?php if ($error !== ''): ?>
				<div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<?php if ($success !== ''): ?>
				<div class="success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<form method="POST" action="user.php" class="account-form" autocomplete="off">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="form-group">
						<label for="current-password">Current Password</label>
						<input type="password" id="current-password" name="current_password" required>
					</div>

					<div class="form-group">
						<label for="new-password">New Password</label>
						<input type="password" id="new-password" name="new_password" minlength="8" required>
					</div>

					<div class="form-group">
						<label for="confirm-password">Confirm New Password</label>
						<input type="password" id="confirm-password" name="confirm_password" minlength="8" required>
					</div>

					<div class="form-actions">
						<button type="submit" class="btn btn-primary">Update Password</button>
					</div>
				</form>
			</div>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>
