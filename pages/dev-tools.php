<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';
require_once __DIR__ . '/../includes/page_context.php';

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

$devToolsSampleSeedConfig = [
	'record_count' => 150,
	'activity_count' => 150,
	'audit_count' => 150,
	'user_count' => 10,
	'seed_prefix' => 'devtools_sample',
];

$devToolsMaintenanceActions = [
	[
		'buttonId' => 'reset-activity-log-btn',
		'action' => 'reset_activity_log',
		'buttonClass' => 'btn btn-danger',
		'label' => 'Reset Activity Log',
		'confirmTitle' => 'Reset Activity Log?',
		'confirmMessage' => 'This clears the activity_log table and resets its auto-increment key.',
		'successTitle' => 'Activity Log Reset',
		'successMessage' => 'The activity_log table has been reset and reseeded from 1.',
	],
	[
		'buttonId' => 'reset-audit-log-btn',
		'action' => 'reset_audit_log',
		'buttonClass' => 'btn btn-danger',
		'label' => 'Reset Audit Log',
		'confirmTitle' => 'Reset Audit Log?',
		'confirmMessage' => 'This clears the audit_log table and resets its auto-increment key.',
		'successTitle' => 'Audit Log Reset',
		'successMessage' => 'The audit_log table has been reset and reseeded from 1.',
	],
	[
		'buttonId' => 'reset-records-btn',
		'action' => 'reset_records',
		'buttonClass' => 'btn btn-danger',
		'label' => 'Reset Records',
		'confirmTitle' => 'Reset Records?',
		'confirmMessage' => 'This resets the records table back to 3 sample entries and resets its key.',
		'successTitle' => 'Records Reset',
		'successMessage' => 'The records table has been reset to 3 sample entries.',
	],
	[
		'buttonId' => 'reset-data-tables-btn',
		'action' => 'reset_data_tables',
		'buttonClass' => 'btn btn-danger',
		'label' => 'Reset Data Tables',
		'confirmTitle' => 'Reset Data Tables?',
		'confirmMessage' => 'This resets activity_log, audit_log, and records together.',
		'successTitle' => 'Data Tables Reset',
		'successMessage' => 'Activity log, audit log, and records were reset successfully.',
	],
	[
		'buttonId' => 'reset-users-btn',
		'action' => 'reset_users',
		'buttonClass' => 'btn btn-danger',
		'label' => 'Reset Users',
		'confirmTitle' => 'Reset Users?',
		'confirmMessage' => 'This deletes all users except your current account and clears related user data.',
		'successTitle' => 'Users Reset',
		'successMessage' => 'All users except the current account were deleted.',
	],
	[
		'buttonId' => 'reset-notifications-btn',
		'action' => 'reset_notifications_table',
		'buttonClass' => 'btn btn-secondary',
		'label' => 'Reset Notifications',
		'confirmTitle' => 'Reset Notifications?',
		'confirmMessage' => 'This clears the notifications table and resets its key.',
		'successTitle' => 'Notifications Reset',
		'successMessage' => 'The notifications table has been reset successfully.',
	],
	[
		'buttonId' => 'reset-widget-prefs-btn',
		'action' => 'reset_widget_prefs',
		'buttonClass' => 'btn btn-info',
		'label' => 'Reset Widget Prefs',
		'confirmTitle' => 'Reset Widget Prefs?',
		'confirmMessage' => 'This resets user_widget_preferences and reseeds default widgets for all users.',
		'successTitle' => 'Widget Prefs Reset',
		'successMessage' => 'Widget preferences have been reset to defaults for all users.',
	],
	[
		'buttonId' => 'logout-all-users-btn',
		'action' => 'logout_all_users',
		'buttonClass' => 'btn btn-secondary',
		'label' => 'Log out all users',
		'confirmTitle' => 'Log out all users?',
		'confirmMessage' => 'This revokes all sessions and forces every user to sign in again.',
		'successTitle' => 'All Users Logged Out',
		'successMessage' => 'All user sessions were reset successfully.',
	],
	[
		'buttonId' => 'add-sample-data-btn',
		'action' => 'add_sample_data',
		'buttonClass' => 'btn btn-success',
		'label' => 'Add Sample Data',
		'confirmTitle' => 'Add Sample Data?',
		'confirmMessage' => 'This creates 150 sample records, 150 activity log entries, 150 audit log entries, and 10 sample users.',
		'successTitle' => 'Sample Data Added',
		'successMessage' => 'Sample data has been seeded successfully and can be removed later by prefix.',
		'requestPayload' => $devToolsSampleSeedConfig,
	],
];

$devToolsMaintenanceGroups = [
	[
		'title' => 'Table resets',
		'description' => 'Clear the tables used to validate record, activity, and audit flows.',
		'actions' => ['reset-activity-log-btn', 'reset-audit-log-btn', 'reset-records-btn', 'reset-data-tables-btn'],
	],
	[
		'title' => 'User and session resets',
		'description' => 'Clear user-facing state without touching the signed-in account.',
		'actions' => ['reset-users-btn', 'reset-notifications-btn', 'reset-widget-prefs-btn', 'logout-all-users-btn'],
	],
	[
		'title' => 'Sample data',
		'description' => 'Create a clearly prefixed dataset that can be removed later in one pass.',
		'actions' => ['add-sample-data-btn'],
	],
];

$devToolsMaintenanceActionsJson = dashboardJsonEncodeOrFallback($devToolsMaintenanceActions, '{"actions":[]}');
$devToolsMaintenanceGroupsJson = dashboardJsonEncodeOrFallback($devToolsMaintenanceGroups, '{"groups":[]}');
$devToolsSampleSeedConfigJson = dashboardJsonEncodeOrFallback($devToolsSampleSeedConfig, '{"record_count":150,"activity_count":150,"audit_count":150,"user_count":10,"seed_prefix":"devtools_sample"}');

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
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navigation.php'; ?>

<script>
	window.DEV_TOOLS_MAINTENANCE_ACTIONS = <?php echo $devToolsMaintenanceActionsJson; ?>;
	window.DEV_TOOLS_MAINTENANCE_GROUPS = <?php echo $devToolsMaintenanceGroupsJson; ?>;
	window.DEV_TOOLS_SAMPLE_SEED = <?php echo $devToolsSampleSeedConfigJson; ?>;
</script>

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
				<p>Run local maintenance actions for targeted resets and disposable sample data.</p>
							<?php if (defined('RESET_ON_INDEX_VISIT') && RESET_ON_INDEX_VISIT): ?>
							<div class="dev-tools-warning dev-tools-warning--danger">
								Reset_on_index_visit is currently TRUE - all records/audit/log data will be reset upon visiting the landing page.
							</div>
							<?php elseif (defined('APP_MODE') && APP_MODE === 'demo' && defined('RESET_ON_INDEX_VISIT') && !RESET_ON_INDEX_VISIT): ?>
							<div class="dev-tools-warning dev-tools-warning--success">
								Application mode is in Demo mode - reset_on_index_visit is FALSE - all data will be preserved on visiting the landing page.
							</div>
							<?php endif; ?>
				<div class="dev-tools-action-groups">
					<?php foreach ($devToolsMaintenanceGroups as $maintenanceGroup): ?>
						<section class="dev-tools-action-group">
							<h4><?php echo htmlspecialchars((string)$maintenanceGroup['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
							<p><?php echo htmlspecialchars((string)$maintenanceGroup['description'], ENT_QUOTES, 'UTF-8'); ?></p>
							<div class="dev-tools-action-grid">
								<?php foreach ($maintenanceGroup['actions'] as $actionButtonId): ?>
									<?php
									$maintenanceAction = null;
									foreach ($devToolsMaintenanceActions as $actionConfig) {
										if (($actionConfig['buttonId'] ?? '') === $actionButtonId) {
											$maintenanceAction = $actionConfig;
											break;
										}
									}
									if (!is_array($maintenanceAction)) {
										continue;
									}
									?>
									<button
										type="button"
										id="<?php echo htmlspecialchars((string)$maintenanceAction['buttonId'], ENT_QUOTES, 'UTF-8'); ?>"
										class="<?php echo htmlspecialchars((string)$maintenanceAction['buttonClass'], ENT_QUOTES, 'UTF-8'); ?>"
										data-maintenance-action="<?php echo htmlspecialchars((string)$maintenanceAction['action'], ENT_QUOTES, 'UTF-8'); ?>"
										data-confirm-title="<?php echo htmlspecialchars((string)$maintenanceAction['confirmTitle'], ENT_QUOTES, 'UTF-8'); ?>"
										data-confirm-message="<?php echo htmlspecialchars((string)$maintenanceAction['confirmMessage'], ENT_QUOTES, 'UTF-8'); ?>"
										data-success-title="<?php echo htmlspecialchars((string)$maintenanceAction['successTitle'], ENT_QUOTES, 'UTF-8'); ?>"
										data-success-message="<?php echo htmlspecialchars((string)$maintenanceAction['successMessage'], ENT_QUOTES, 'UTF-8'); ?>"
									>
										<?php echo htmlspecialchars((string)$maintenanceAction['label'], ENT_QUOTES, 'UTF-8'); ?>
									</button>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
