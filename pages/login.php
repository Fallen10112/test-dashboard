<?php
require_once __DIR__ . '/../includes/auth.php';

startAuthSession();

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
	logoutUser();
	header('Location: login.php');
	exit();
}

// Already logged in
if (getAuthUser() !== null) {
	header('Location: home.php');
	exit();
}

$error = '';
$flashToast = null;

if (isset($_SESSION['auth_flash_toast']) && is_array($_SESSION['auth_flash_toast'])) {
	$flashToast = $_SESSION['auth_flash_toast'];
	unset($_SESSION['auth_flash_toast']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$identifier = trim($_POST['identifier'] ?? '');
	$password   = $_POST['password'] ?? '';

	if ($identifier === '' || $password === '') {
		$error = 'Username/email and password are required.';
	} elseif (!loginUser($identifier, $password)) {
		$error = 'Invalid credentials. Please try again.';
	} else {
		header('Location: home.php');
		exit();
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login — Dashboard Showcase</title>
	<link rel="stylesheet" href="../css/style.css">
</head>
<body class="login-body">

	<div class="login-wrapper">
		<div class="login-card">
			<div class="login-header">
				<h1>Dashboard Showcase</h1>
				<p>Sign in to continue</p>
			</div>

			<?php if ($error !== ''): ?>
			<div class="login-error" role="alert">
				<?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
			</div>
			<?php endif; ?>

			<form class="login-form" method="POST" action="login.php" autocomplete="on">
				<div class="login-field">
					<label for="identifier">Email or Username</label>
					<input
						type="text"
						id="identifier"
						name="identifier"
						value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
						placeholder="Email or username"
						autocomplete="username"
						required
						autofocus
					>
				</div>

				<div class="login-field">
					<label for="password">Password</label>
					<input
						type="password"
						id="password"
						name="password"
						placeholder="Enter your password"
						autocomplete="current-password"
						required
					>
				</div>

				<button type="submit" class="btn btn-login">Sign In</button>
			</form>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="../js/core/shared.js"></script>
	<?php if (is_array($flashToast)): ?>
	<script>
		$(function() {
			showToast({
				type: <?php echo json_encode((string)($flashToast['type'] ?? 'info'), JSON_UNESCAPED_SLASHES); ?>,
				title: <?php echo json_encode((string)($flashToast['title'] ?? 'Notice'), JSON_UNESCAPED_SLASHES); ?>,
				message: <?php echo json_encode((string)($flashToast['message'] ?? ''), JSON_UNESCAPED_SLASHES); ?>,
				showOkayButton: true,
				autoCloseMs: 5000
			});
		});
	</script>
	<?php endif; ?>

</body>
</html>
