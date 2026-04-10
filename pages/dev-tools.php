<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';

startAuthSession();
requireAuth();
requirePagePermission('dev_tools', 'read');

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

				$titleForValidation = $title !== '' ? $title : 'Notification';
				if (mb_strlen($titleForValidation, 'UTF-8') > 64) {
					$postError = 'Notification title must not exceed 64 characters';
				} else {
					$insertStmt = $pdo->prepare(
						'INSERT INTO notifications (user_id, sent_by_user_id, title, message, notification_type, is_read, created_at)
						 VALUES (:user_id, :sent_by_user_id, :title, :message, :notification_type, 0, :created_at)'
					);
					$ok = $insertStmt->execute([
						':user_id' => $recipientUserId,
						':sent_by_user_id' => $sentByUserId,
						':title' => substr($titleForValidation, 0, 160),
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
							'source_user_id' => $currentUserId > 0 ? $currentUserId : null,
							'target_user_id' => $recipientUserId,
						]);
						$postSuccess = 'Test notification sent successfully.';
					} else {
						$postError = 'Failed to send test notification.';
					}
				}
			}
		}
		}

		if ($postSuccess !== '') {
			$_SESSION['test_notif_success'] = $postSuccess;
		}
		if ($postError !== '') {
			$_SESSION['test_notif_error'] = $postError;
		}
		header('Location: dev-tools.php');
		exit;
	}
} catch (Throwable $e) {
	$error = 'Unable to load dev tools right now.';
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	<main class="main-content">
		<section class="content-section active">
			<h2>Dev Tools</h2>
			<div class="account-card dev-tools-card">
				<h3>Tool Category</h3>
				<p>Select a system category to load its available tools below.</p>
				<div class="form-group">
					<label for="dev-tools-system-selector">Category</label>
					<select id="dev-tools-system-selector" name="dev_tools_system">
						<option value="notifications">Notifications</option>
						<option value="system" selected>System</option>
						<option value="users">Users</option>
					</select>
				</div>
			</div>

			<div class="account-card dev-tool-module" data-dev-tool-category="notifications" hidden>
				<h3>Create Notification</h3>
				<p>Send a test notification to a selected user for validation and QA.</p>

				<?php if ($error !== ''): ?>
				<div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<?php if ($success !== ''): ?>
				<div class="success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<form method="POST" action="dev-tools.php" class="account-form" autocomplete="off">
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
						<input type="text" id="notification-title" name="title" maxlength="64" placeholder="Enter title">
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
						<button type="submit" class="btn btn-primary">Send Notification</button>
					</div>
				</form>
			</div>

			<div class="account-card dev-tool-module" data-dev-tool-category="system">
				<h3>Maintenance Tools</h3>
				<p>Run maintenance actions for targeted table resets.</p>
							<?php if (defined('RESET_ON_INDEX_VISIT') && RESET_ON_INDEX_VISIT): ?>
							<div class="dev-tools-warning" style="color:#b00;font-weight:bold;margin-bottom:1em;">
								Reset_on_index_visit is currently TRUE - all data will be reset upon visiting the landing page.
							</div>
							<?php elseif (defined('APP_MODE') && APP_MODE === 'demo' && defined('RESET_ON_INDEX_VISIT') && !RESET_ON_INDEX_VISIT): ?>
							<div class="dev-tools-warning" style="color:#007700;font-weight:bold;margin-bottom:1em;">
								Application mode is in Demo mode - reset_on_index_visit is FALSE - all data will be preserved on visiting the landing page.
							</div>
							<?php endif; ?>
				<div class="dev-tools-actions-row">
					<button id="reset-activity-log-btn" class="btn btn-reset">Reset Activity Log</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="reset-audit-log-btn" class="btn btn-reset">Reset Audit Log</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="reset-records-btn" class="btn btn-reset">Reset Records</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="reset-widget-prefs-btn" class="btn btn-reset">Reset Widget Prefs</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="reset-notif-table-btn" class="btn btn-reset">Reset Notification Table</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="logout-all-users-btn" class="btn btn-reset">Log out all users</button>
				</div>
				<div class="dev-tools-actions-row">
					<button id="reset-all-btn" class="btn btn-reset">Reset all</button>
				</div>
			</div>

			<div class="account-card dev-tool-module" data-dev-tool-category="users" hidden>
				<h3>User Tools</h3>
				<p>Force delete users or reset widget preferences for testing and admin support workflows.</p>
				<input type="hidden" id="dev-tools-current-user-id" value="<?php echo (int)$currentUserId; ?>">

				<div class="dev-tools-user-section">
					<h4>Force Delete User</h4>
					<div class="account-form" autocomplete="off">
						<div class="form-group">
										<label for="dev-users-delete-lookup">Lookup (email, username or id)</label>
							<input type="text" id="dev-users-delete-lookup" maxlength="100" placeholder="e.g. 12 or johndoe">
						</div>
						<div class="form-actions">
							<button id="dev-users-delete-detect-btn" type="button" class="btn btn-secondary">Detect User</button>
						</div>
						<p id="dev-users-delete-detected" class="dev-tools-inline-result" hidden></p>
						<div class="form-actions">
							<button id="dev-users-delete-btn" type="button" class="btn btn-danger" disabled>Force Delete User</button>
						</div>
						<p id="dev-users-delete-result" class="dev-tools-inline-result" hidden></p>
					</div>
				</div>

				<div class="dev-tools-user-section">
					<h4>Reset Widget Preferences</h4>
					<div class="account-form" autocomplete="off">
						<div class="form-group">
										<label for="dev-users-widget-reset-lookup">Lookup (email, username or id)</label>
							<input type="text" id="dev-users-widget-reset-lookup" maxlength="100" placeholder="e.g. 12 or johndoe">
						</div>
						<div class="form-actions">
							<button id="dev-users-widget-reset-detect-btn" type="button" class="btn btn-secondary">Detect User</button>
						</div>
						<p id="dev-users-widget-reset-detected" class="dev-tools-inline-result" hidden></p>
						<div class="form-actions">
							<button id="dev-users-widget-reset-btn" type="button" class="btn btn-primary" disabled>Reset Widget Preferences</button>
						</div>
						<p id="dev-users-widget-reset-result" class="dev-tools-inline-result" hidden></p>
					</div>
				</div>
			</div>
			</section>
		</main>

<?php include '../includes/footer.php'; ?>
