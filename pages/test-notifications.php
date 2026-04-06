<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';

startAuthSession();
requireAuth();

$authUser = $GLOBALS['auth_user'] ?? null;
$currentUserId = (int)($authUser['id'] ?? 0);
$currentDisplayName = trim((string)($authUser['display_name'] ?? ''));
if ($currentDisplayName === '') {
	$currentDisplayName = trim((string)($authUser['username'] ?? ''));
}
if ($currentDisplayName === '') {
	$currentDisplayName = trim((string)($authUser['email'] ?? ''));
}
if ($currentDisplayName === '') {
	$currentDisplayName = 'Current User';
}

$csrfToken = getCsrfToken();

$error = '';
$success = '';
$users = [];

// Check for session messages from redirect
if (isset($_SESSION['test_notif_success'])) {
	$success = $_SESSION['test_notif_success'];
	unset($_SESSION['test_notif_success']);
}
if (isset($_SESSION['test_notif_error'])) {
	$error = $_SESSION['test_notif_error'];
	unset($_SESSION['test_notif_error']);
}

try {
	$pdo = getDashboardPdo();

	$userStmt = $pdo->query(
		'SELECT id, email, username, display_name
		 FROM users
		 WHERE status = "active" AND deleted_at IS NULL
		 ORDER BY COALESCE(NULLIF(display_name, ""), NULLIF(username, ""), email) ASC'
	);
	$users = $userStmt->fetchAll();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$action = trim((string)($_POST['action'] ?? 'create'));
		$postError = '';
		$postSuccess = '';
		$postedCsrfToken = (string)($_POST['csrf_token'] ?? '');

		if (!isValidCsrfToken($postedCsrfToken)) {
			$postError = 'Invalid security token. Please refresh and try again.';
		}

		if ($action === 'create' && $postError === '') {
			$recipientUserId = (int)($_POST['recipient_user_id'] ?? 0);
			$title = trim((string)($_POST['title'] ?? ''));
			$message = trim((string)($_POST['message'] ?? ''));
			$type = trim((string)($_POST['notification_type'] ?? 'info'));
			$senderMode = trim((string)($_POST['sender_mode'] ?? 'system'));

		$allowedTypes = ['info', 'success', 'warning', 'error'];
		if (!in_array($type, $allowedTypes, true)) {
			$type = 'info';
		}

		if ($recipientUserId < 1) {
			$postError = 'Please select a recipient user.';
		} elseif ($title === '' && $message === '') {
			$postError = 'Please provide at least a title or message.';
		} else {
			$recipientExistsStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND status = "active" AND deleted_at IS NULL LIMIT 1');
			$recipientExistsStmt->execute([':id' => $recipientUserId]);
			$recipientRow = $recipientExistsStmt->fetch();

			if (!$recipientRow) {
				$postError = 'Selected recipient user is not available.';
			} else {
				$sentByUserId = null;
				if ($senderMode === 'current_user') {
					$sentByUserId = $currentUserId > 0 ? $currentUserId : null;
				}

				$insertStmt = $pdo->prepare(
					'INSERT INTO notifications (user_id, sent_by_user_id, title, message, notification_type, is_read, created_at)
					 VALUES (:user_id, :sent_by_user_id, :title, :message, :notification_type, 0, :created_at)'
				);
				$ok = $insertStmt->execute([
					':user_id' => $recipientUserId,
					':sent_by_user_id' => $sentByUserId,
					':title' => substr($title !== '' ? $title : 'Notification', 0, 160),
					':message' => substr($message, 0, 1000),
					':notification_type' => substr($type, 0, 50),
					':created_at' => getDashboardSqlTimestamp(),
				]);

				if ($ok) {
					$recipientLabel = 'user #' . $recipientUserId;
					$senderLabel = $sentByUserId === null ? 'System' : ('user #' . (int)$sentByUserId);
					writeAuditEvent($pdo, [
						'record_type' => 'notification',
						'record_id' => (int)$pdo->lastInsertId(),
						'action' => 'notification_sent',
						'details' => 'Notification (' . substr($title !== '' ? $title : '', 0, 160) . '): Sent | From ' . $senderLabel . ' to ' . $recipientLabel,
						'actor_user_id' => $currentUserId > 0 ? $currentUserId : null,
						'target_user_id' => $recipientUserId,
					]);
					$postSuccess = 'Test notification sent successfully.';
				} else {
					$postError = 'Failed to send test notification.';
				}
			}
		}
		}

		// Store message in session and redirect to prevent resubmission
		if ($postSuccess !== '') {
			$_SESSION['test_notif_success'] = $postSuccess;
		}
		if ($postError !== '') {
			$_SESSION['test_notif_error'] = $postError;
		}
		header('Location: test-notifications.php');
		exit;
	}
} catch (Throwable $e) {
	$error = 'Unable to load notification test tools right now.';
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	<main class="main-content">
		<section class="content-section active">
			<h2>Test Notification System</h2>
			<div class="account-card">
				<h3>Create Test Notification</h3>
				<p>Send a test notification to a selected user for validation and QA.</p>

				<?php if ($error !== ''): ?>
				<div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<?php if ($success !== ''): ?>
				<div class="success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<form method="POST" action="test-notifications.php" class="account-form" autocomplete="off">
					<input type="hidden" name="action" value="create">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="form-group">
						<label for="recipient-user-id">Recipient User</label>
						<select id="recipient-user-id" name="recipient_user_id" required>
							<option value="">Select a user...</option>
							<?php foreach ($users as $user): ?>
							<?php
								$userId = (int)($user['id'] ?? 0);
								$displayName = trim((string)($user['display_name'] ?? ''));
								if ($displayName === '') {
									$displayName = trim((string)($user['username'] ?? ''));
								}
								if ($displayName === '') {
									$displayName = trim((string)($user['email'] ?? 'User'));
								}
							?>
							<option value="<?php echo $userId; ?>"><?php echo htmlspecialchars($displayName . ' (ID ' . $userId . ')', ENT_QUOTES, 'UTF-8'); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="form-group">
						<label for="notification-title">Notification Title</label>
						<input type="text" id="notification-title" name="title" maxlength="160" placeholder="Enter title">
					</div>

					<div class="form-group">
						<label for="notification-message">Notification Message</label>
						<textarea id="notification-message" name="message" maxlength="1000" required placeholder="Enter notification message"></textarea>
					</div>

					<div class="form-group">
						<label for="notification-type">Notification Type</label>
						<select id="notification-type" name="notification_type" required>
							<option value="info">Info</option>
							<option value="success">Success</option>
							<option value="warning">Warning</option>
							<option value="error">Error</option>
						</select>
					</div>

					<div class="form-group">
						<label for="sender-mode">Sender</label>
						<select id="sender-mode" name="sender_mode" required>
							<option value="system">System (no sender)</option>
							<option value="current_user"><?php echo htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8'); ?> (current user)</option>
						</select>
					</div>

					<div class="form-actions">
						<button type="submit" class="btn btn-primary">Send Test Notification</button>
					</div>
				</form>

				<div class="account-card" style="margin-top: 30px;">
					<h3>Maintenance Tools</h3>
					<p>Reset the notifications table for a fresh start.</p>
					<div style="max-width: 200px;">
						<button id="reset-notif-table-btn" class="btn btn-reset">Reset Notification Table</button>
					</div>
				</div>
			</section>
		</main>

<?php include '../includes/footer.php'; ?>
