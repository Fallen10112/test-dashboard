<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
requireAuth();
header('Content-Type: text/html; charset=utf-8');
$pageTitle = "Dashboard Showcase";
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $pageTitle; ?></title>
	<link rel="stylesheet" href="../css/style.css">
</head>
<body>
	
	<?php
	$_headerUser = $GLOBALS['auth_user'] ?? null;
	$_headerDisplay = ($_headerUser && $_headerUser['display_name'] !== null && $_headerUser['display_name'] !== '')
		? $_headerUser['display_name']
		: ($_headerUser['email'] ?? 'User');
	$_headerRole = trim((string)($_headerUser['role_name'] ?? ''));
	if ($_headerRole === '') {
		$_headerRole = 'Unassigned';
	}
	$_headerEmail = htmlspecialchars($_headerUser['email'] ?? '', ENT_QUOTES, 'UTF-8');
	$_words = preg_split('/\s+/', trim($_headerDisplay));
	$_initials = '';
	foreach (array_slice($_words, 0, 2) as $_w) {
		if ($_w !== '') $_initials .= mb_strtoupper(mb_substr($_w, 0, 1, 'UTF-8'), 'UTF-8');
	}
	if ($_initials === '') $_initials = 'U';
	?>
	<header class="title-bar">
		<h1><?php echo $pageTitle; ?></h1>
		<div class="title-bar-right">
			<div class="header-metrics" aria-label="Dashboard analytics">
				<div class="metric-pill" id="metric-total-entries" data-widget-key="total_entries">
					<span class="metric-label">Entries</span>
					<span class="metric-value">--</span>
				</div>
				<div class="metric-pill" id="metric-total-edits" data-widget-key="total_edits">
					<span class="metric-label">Edits</span>
					<span class="metric-value">--</span>
				</div>
				<div class="metric-pill" id="metric-adds-today" data-widget-key="adds_today">
					<span class="metric-label">Adds Today</span>
					<span class="metric-value">--</span>
				</div>
				<div class="metric-pill" id="metric-deletes-today" data-widget-key="deletes_today">
					<span class="metric-label">Deletes Today</span>
					<span class="metric-value">--</span>
				</div>
				<div class="metric-pill" id="metric-local-time" data-widget-key="local_time">
					<span class="metric-label">Local Time</span>
					<span class="metric-value">--:--</span>
				</div>
			</div>

			<div class="notifications-wrap" id="notifications-wrap">
				<button
					class="notifications-btn"
					id="notifications-btn"
					aria-expanded="false"
					aria-haspopup="true"
					aria-label="Open notifications"
					title="Notifications"
				>
					<span class="notifications-bell" aria-hidden="true">&#128276;</span>
					<span class="notifications-count" id="notifications-count" hidden>0</span>
				</button>

				<div class="notifications-dropdown" id="notifications-dropdown" hidden>
					<div class="notifications-dropdown-header">
						<span class="notifications-dropdown-title">Notifications</span>
						<div class="notifications-header-actions">
							<button type="button" class="notifications-mark-read-btn" id="notifications-mark-all-read" hidden>Mark all read</button>
							<button type="button" class="notifications-delete-all-btn" id="notifications-delete-all" hidden>Delete all</button>
						</div>
					</div>
					<div class="notifications-dropdown-divider"></div>
					<div class="notifications-list" id="notifications-list" role="list" aria-live="polite">
						<div class="notifications-empty">No notifications.</div>
					</div>
				</div>
			</div>

			<div class="user-avatar-wrap" id="user-avatar-wrap">
				<button
					class="user-avatar-btn"
					id="user-avatar-btn"
					aria-expanded="false"
					aria-haspopup="true"
					title="<?php echo htmlspecialchars($_headerDisplay, ENT_QUOTES, 'UTF-8'); ?>"
				><?php echo htmlspecialchars($_initials, ENT_QUOTES, 'UTF-8'); ?></button>

				<div class="user-dropdown" id="user-dropdown" hidden>
					<div class="user-dropdown-info">
						<span class="user-dropdown-name"><?php echo htmlspecialchars($_headerDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
						<span class="user-dropdown-role"><?php echo htmlspecialchars($_headerRole, ENT_QUOTES, 'UTF-8'); ?></span>
						<span class="user-dropdown-email"><?php echo $_headerEmail; ?></span>
					</div>
					<div class="user-dropdown-divider"></div>
					<a href="user.php" class="user-dropdown-item">Account Settings</a>
					<a href="ui-customization.php" class="user-dropdown-item">UI Customization</a>
					<a href="dev-tools.php" class="user-dropdown-item">Dev Tools</a>
					<a href="admin.php" class="user-dropdown-item">Admin</a>
					<form method="POST" action="login.php">
						<input type="hidden" name="action" value="logout">
						<button type="submit" class="user-dropdown-item user-dropdown-signout">Sign Out</button>
					</form>
				</div>
			</div>
		</div>
	</header>

