<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>
<?php
	$adminAuthUser = $GLOBALS['auth_user'] ?? null;
	$adminCurrentUserId = (int)($adminAuthUser['id'] ?? 0);
?>

	<main class="main-content">
		<section class="content-section active admin-section" id="admin-container">
			<h2>Admin</h2>
			<div class="account-card admin-card">
				<h3>Admin Workspace</h3>
				<p>Basic tabbed framework for future expansion.</p>

				<div class="admin-tabs" role="tablist" aria-label="Admin sections">
					<button type="button" class="admin-tab-btn active" id="admin-tab-btn-1" role="tab" aria-selected="true" aria-controls="admin-tab-panel-1" data-admin-tab="1">User management</button>
					<button type="button" class="admin-tab-btn" id="admin-tab-btn-2" role="tab" aria-selected="false" aria-controls="admin-tab-panel-2" data-admin-tab="2">Database management</button>
					<button type="button" class="admin-tab-btn" id="admin-tab-btn-3" role="tab" aria-selected="false" aria-controls="admin-tab-panel-3" data-admin-tab="3">Application management</button>
					<button type="button" class="admin-tab-btn" id="admin-tab-btn-4" role="tab" aria-selected="false" aria-controls="admin-tab-panel-4" data-admin-tab="4">Notifications</button>
				</div>

				<div class="admin-tab-panel active" id="admin-tab-panel-1" role="tabpanel" aria-labelledby="admin-tab-btn-1">
					<h4>User Management</h4>
					<p>Create new users and update existing users details.</p>
					<input type="hidden" id="dev-tools-current-user-id" value="<?php echo $adminCurrentUserId; ?>">

					<div class="admin-user-grid">
						<div class="dev-tools-user-section">
							<h4>Create New User</h4>
							<div class="account-form" autocomplete="off">
								<div class="form-group">
									<label for="dev-users-create-email">Email</label>
									<input type="email" id="dev-users-create-email" maxlength="255" placeholder="new.user@example.com">
								</div>
								<div class="form-group">
									<label for="dev-users-create-username">Username</label>
									<input type="text" id="dev-users-create-username" maxlength="100" placeholder="newuser">
								</div>
								<div class="form-group">
									<label for="dev-users-create-display-name">Display Name</label>
									<input type="text" id="dev-users-create-display-name" maxlength="150" placeholder="New User">
								</div>
								<div class="form-group">
									<label for="dev-users-create-status">Status</label>
									<select id="dev-users-create-status">
										<option value="active" selected>active</option>
										<option value="disabled">disabled</option>
									</select>
								</div>
								<div class="form-actions">
									<button id="dev-users-create-btn" type="button" class="btn btn-primary">Create User</button>
								</div>
								<p id="dev-users-create-result" class="dev-tools-inline-result" hidden></p>
							</div>
						</div>

						<div class="dev-tools-user-section">
							<h4>Update User</h4>
							<div class="account-form" autocomplete="off">
								<div class="form-group">
									<label for="dev-users-update-lookup">Lookup (username or id)</label>
									<input type="text" id="dev-users-update-lookup" maxlength="100" placeholder="e.g. 12 or johndoe">
								</div>
								<div class="form-actions">
									<button id="dev-users-update-detect-btn" type="button" class="btn btn-secondary">Detect User</button>
								</div>
								<p id="dev-users-update-detected" class="dev-tools-inline-result" hidden></p>

								<div class="form-group">
									<label for="dev-users-update-email">Email</label>
									<input type="email" id="dev-users-update-email" maxlength="255" placeholder="user@example.com" disabled>
								</div>
								<div class="form-group">
									<label for="dev-users-update-username">Username</label>
									<input type="text" id="dev-users-update-username" maxlength="100" placeholder="username" disabled>
								</div>
								<div class="form-group">
									<label for="dev-users-update-display-name">Display Name</label>
									<input type="text" id="dev-users-update-display-name" maxlength="150" placeholder="Display name" disabled>
								</div>
								<div class="form-group">
									<label for="dev-users-update-status">Status</label>
									<select id="dev-users-update-status" disabled>
										<option value="active">active</option>
										<option value="disabled">disabled</option>
									</select>
								</div>
								<div class="form-group">
									<label for="dev-users-update-reset-password">Reset Password</label>
									<input type="checkbox" id="dev-users-update-reset-password" disabled>
								</div>
								<div class="form-actions">
									<button id="dev-users-update-btn" type="button" class="btn btn-primary" disabled>Update User</button>
								</div>
								<p id="dev-users-update-result" class="dev-tools-inline-result" hidden></p>
							</div>
						</div>
					</div>
				</div>

				<div class="admin-tab-panel" id="admin-tab-panel-2" role="tabpanel" aria-labelledby="admin-tab-btn-2" hidden>
					<h4>Tab 2 Content</h4>
					<ul>
						<li>Placeholder item D</li>
						<li>Placeholder item E</li>
						<li>Placeholder item F</li>
					</ul>
				</div>

				<div class="admin-tab-panel" id="admin-tab-panel-3" role="tabpanel" aria-labelledby="admin-tab-btn-3" hidden>
					<h4>Tab 3 Content</h4>
					<ul>
						<li>Placeholder item G</li>
						<li>Placeholder item H</li>
						<li>Placeholder item I</li>
					</ul>
				</div>

				<div class="admin-tab-panel" id="admin-tab-panel-4" role="tabpanel" aria-labelledby="admin-tab-btn-4" hidden>
					<h4>Notifications Content</h4>
					<ul>
						<li>Placeholder item J</li>
						<li>Placeholder item K</li>
						<li>Placeholder item L</li>
					</ul>
				</div>
			</div>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>
