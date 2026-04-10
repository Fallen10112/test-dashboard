<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';
startAuthSession();
requireAuth();
requirePagePermission('admin', 'read');
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>
<?php
	$adminAuthUser = $GLOBALS['auth_user'] ?? null;
	$adminCurrentUserId = (int)($adminAuthUser['id'] ?? 0);
	$adminCurrentRoleId = (int)($adminAuthUser['role_id'] ?? 0);
	$adminRoles = [];
	$adminPermissionEditorPayload = [
		'roles' => [],
		'resources' => [],
		'permissions' => [],
		'role_permissions' => [],
		'allowed_permissions_by_resource' => [],
	];
	$adminPermissionEditScope = [
		'mode' => 'none',
		'label' => 'No editable roles',
		'current_role_id' => null,
		'max_role_id' => 0,
	];
	$adminEditableRoles = [];
	$adminTabPermissionFlags = [
		'user_management' => false,
		'role_management' => false,
		'database_management' => false,
		'application_management' => false,
		'notifications' => false,
		'permissions' => false,
	];
	try {
		$adminPdo = getDashboardPdo();
		ensurePermissionsSchema($adminPdo);
		$adminRoles = getAvailableRoles($adminPdo);
		$adminPermissionEditorPayload = getRolePermissionsEditorPayload($adminPdo, $adminCurrentUserId);
		if ($adminCurrentUserId > 0) {
			$adminPermissionEditScope = getUserPermissionEditScope($adminPdo, $adminCurrentUserId);
			$adminEditableRoles = getEditableRolesForPermissionScope($adminRoles, $adminPermissionEditScope);
			$adminTabPermissionFlags = [
				'user_management' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_user_management'),
				'role_management' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_role_management'),
				'database_management' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_database_management'),
				'application_management' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_application_management'),
				'notifications' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_notifications_management'),
				'permissions' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_permissions_management'),
			];
			$adminRoleManagementFlags = [
				'create' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_role_create'),
				'update' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_role_update'),
				'delete' => userHasPermission($adminPdo, $adminCurrentUserId, 'admin', 'admin_role_delete'),
			];
		}
	} catch (Throwable $e) {
		$adminRoles = [];
		$adminTabPermissionFlags = [
			'user_management' => false,
			'role_management' => false,
			'database_management' => false,
			'application_management' => false,
			'notifications' => false,
			'permissions' => false,
		];
		$adminRoleManagementFlags = [
			'create' => false,
			'update' => false,
			'delete' => false,
		];
	}
	$adminRolesJson = json_encode($adminRoles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminRolesJson) || $adminRolesJson === '') {
		$adminRolesJson = '[]';
	}
	$adminCurrentRoleIdJson = json_encode($adminCurrentRoleId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$adminEditableRolesJson = json_encode($adminEditableRoles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminEditableRolesJson) || $adminEditableRolesJson === '') {
		$adminEditableRolesJson = '[]';
	}
	$adminPermissionEditorJson = json_encode($adminPermissionEditorPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminPermissionEditorJson) || $adminPermissionEditorJson === '') {
		$adminPermissionEditorJson = '{"roles":[],"resources":[],"permissions":[],"role_permissions":[]}';
	}
	$adminPermissionEditScopeJson = json_encode($adminPermissionEditScope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminPermissionEditScopeJson) || $adminPermissionEditScopeJson === '') {
		$adminPermissionEditScopeJson = '{"mode":"none","label":"No editable roles","current_role_id":null,"max_role_id":0}';
	}
	$adminTabPermissionJson = json_encode($adminTabPermissionFlags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminTabPermissionJson) || $adminTabPermissionJson === '') {
		$adminTabPermissionJson = '{"user_management":false,"role_management":false,"database_management":false,"application_management":false,"notifications":false,"permissions":false}';
	}
	$adminRoleManagementJson = json_encode($adminRoleManagementFlags ?? ['create' => false, 'update' => false, 'delete' => false], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($adminRoleManagementJson) || $adminRoleManagementJson === '') {
		$adminRoleManagementJson = '{"create":false,"update":false,"delete":false}';
	}
?>

<script>
	window.ADMIN_ROLE_MANAGEMENT_ROLES = <?php echo $adminRolesJson; ?>;
	window.ADMIN_CURRENT_ROLE_ID = <?php echo $adminCurrentRoleIdJson; ?>;
	window.ADMIN_EDITABLE_ROLES = <?php echo $adminEditableRolesJson; ?>;
	window.ADMIN_ROLE_PERMISSION_EDITOR = <?php echo $adminPermissionEditorJson; ?>;
	window.ADMIN_PERMISSION_EDIT_SCOPE = <?php echo $adminPermissionEditScopeJson; ?>;
	window.ADMIN_PAGE_PERMISSIONS = <?php echo $adminTabPermissionJson; ?>;
	window.ADMIN_ROLE_MANAGEMENT_FLAGS = <?php echo $adminRoleManagementJson; ?>;
</script>

	<main class="main-content">
		<section class="content-section active admin-section" id="admin-container">
			<h2>Admin</h2>
			<div class="account-card admin-card">
				<h3>Admin Workspace</h3>
				<p>Basic tabbed framework for future expansion.</p>

				<div class="admin-tabs" role="tablist" aria-label="Admin sections">
					<?php if ($adminTabPermissionFlags['user_management']): ?><button type="button" class="admin-tab-btn active" id="admin-tab-btn-1" role="tab" aria-selected="true" aria-controls="admin-tab-panel-1" data-admin-tab="1">User management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['role_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-2" role="tab" aria-selected="false" aria-controls="admin-tab-panel-2" data-admin-tab="2">Role management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['database_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-3" role="tab" aria-selected="false" aria-controls="admin-tab-panel-3" data-admin-tab="3">Database management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['application_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-4" role="tab" aria-selected="false" aria-controls="admin-tab-panel-4" data-admin-tab="4">Application management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['notifications']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-5" role="tab" aria-selected="false" aria-controls="admin-tab-panel-5" data-admin-tab="5">Notifications</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['permissions']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-6" role="tab" aria-selected="false" aria-controls="admin-tab-panel-6" data-admin-tab="6">Permissions</button><?php endif; ?>
				</div>

				<?php if ($adminTabPermissionFlags['user_management']): ?><div class="admin-tab-panel active" id="admin-tab-panel-1" role="tabpanel" aria-labelledby="admin-tab-btn-1">
					<h4>User Management</h4>
					<p>Create new users and update existing users details.</p>
					<input type="hidden" id="dev-tools-current-user-id" value="<?php echo $adminCurrentUserId; ?>">

					<div class="admin-accordion">
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-create">
								<span>Create New User</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-create" hidden>
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
										<label for="dev-users-create-role">Role</label>
										<select id="dev-users-create-role">
											<option value="" selected disabled>Select role</option>
											<?php foreach ($adminRoles as $adminRole): ?>
												<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
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
						</div>

						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-update">
								<span>Update User</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-update" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="dev-users-update-lookup">Lookup (email, username or id)</label>
										<input type="text" id="dev-users-update-lookup" maxlength="100" placeholder="e.g. 12 or johndoe">
									</div>
									<div class="form-actions">
										<button id="dev-users-update-detect-btn" type="button" class="btn btn-secondary">Detect User</button>
									</div>
									<p id="dev-users-update-detected" class="dev-tools-inline-result" hidden></p>

									<div id="dev-users-update-fields" hidden>
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
											<label for="dev-users-update-role">Role</label>
											<select id="dev-users-update-role" disabled>
												<option value="" selected disabled>Select role</option>
												<?php foreach ($adminRoles as $adminRole): ?>
													<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
												<?php endforeach; ?>
											</select>
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
											<select id="dev-users-update-reset-password" disabled>
												<option value="no" selected>No</option>
												<option value="yes">Yes</option>
											</select>
										</div>
									</div>
									<div class="form-actions" id="dev-users-update-actions" hidden>
										<button id="dev-users-update-btn" type="button" class="btn btn-primary" disabled>Update User</button>
									</div>
									<p id="dev-users-update-result" class="dev-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['role_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-2" role="tabpanel" aria-labelledby="admin-tab-btn-2" hidden>
					<h4>Role Management</h4>
					<p>Create, update, and remove roles. Deleting a role will reassign affected users to the next lower role ID, or the next higher role ID if nothing exists below the deleted role.</p>

					<div class="admin-role-table-wrap">
						<table class="data-table admin-role-table" id="admin-role-table">
							<thead>
								<tr>
									<th>ID</th>
									<th>Name</th>
									<th>Description</th>
								</tr>
							</thead>
							<tbody id="admin-role-table-body">
								<?php if (empty($adminRoles)): ?>
									<tr class="admin-role-empty-row"><td colspan="3">No roles found.</td></tr>
								<?php else: ?>
									<?php foreach ($adminRoles as $adminRole): ?>
										<tr>
											<td><?php echo (int)($adminRole['id'] ?? 0); ?></td>
											<td><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($adminRole['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="admin-accordion">
						<?php if (!empty($adminRoleManagementFlags['create'])): ?>
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-create">
								<span>Add New Role</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-create" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="dev-roles-create-name">Name</label>
										<input type="text" id="dev-roles-create-name" maxlength="50" placeholder="viewer">
									</div>
									<div class="form-group">
										<label for="dev-roles-create-description">Description</label>
										<textarea id="dev-roles-create-description" maxlength="255" rows="3" placeholder="Read-only access role"></textarea>
									</div>
									<div class="form-actions">
										<button id="dev-roles-create-btn" type="button" class="btn btn-primary">Create Role</button>
									</div>
									<p id="dev-roles-create-result" class="dev-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<?php if (!empty($adminRoleManagementFlags['update'])): ?>
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-update">
								<span>Update Role</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-update" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="dev-roles-update-lookup">Lookup (id or name)</label>
										<input type="text" id="dev-roles-update-lookup" maxlength="100" placeholder="e.g. 7 or viewer">
									</div>
									<div class="form-actions">
										<button id="dev-roles-update-detect-btn" type="button" class="btn btn-secondary">Detect Role</button>
									</div>
									<p id="dev-roles-update-detected" class="dev-tools-inline-result" hidden></p>

									<div id="dev-roles-update-fields" hidden>
										<div class="form-group">
											<label for="dev-roles-update-name">Name</label>
											<input type="text" id="dev-roles-update-name" maxlength="50" placeholder="viewer" disabled>
										</div>
										<div class="form-group">
											<label for="dev-roles-update-description">Description</label>
											<textarea id="dev-roles-update-description" maxlength="255" rows="3" placeholder="Read-only access role" disabled></textarea>
										</div>
									</div>
									<div class="form-actions" id="dev-roles-update-actions" hidden>
										<button id="dev-roles-update-btn" type="button" class="btn btn-primary" disabled>Update Role</button>
									</div>
									<p id="dev-roles-update-result" class="dev-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<?php if (!empty($adminRoleManagementFlags['delete'])): ?>
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-delete">
								<span>Delete Role and Reassign Users</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-delete" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="dev-roles-delete-role">Role</label>
										<select id="dev-roles-delete-role">
											<option value="" selected disabled>Select role to delete</option>
											<?php foreach ($adminRoles as $adminRole): ?>
												<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo (int)($adminRole['id'] ?? 0); ?>: <?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-actions">
										<button id="dev-roles-delete-btn" type="button" class="btn btn-danger" disabled>Delete Role</button>
									</div>
									<p id="dev-roles-delete-detected" class="dev-tools-inline-result" hidden></p>
									<p id="dev-roles-delete-result" class="dev-tools-inline-result" hidden></p>
									<div id="dev-roles-delete-details" class="role-delete-details" hidden></div>
								</div>
							</div>
						</div>
					<?php endif; ?>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['database_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-3" role="tabpanel" aria-labelledby="admin-tab-btn-3" hidden>
					<h4>Tab 3 Content</h4>
					<ul>
						<li>Placeholder item G</li>
						<li>Placeholder item H</li>
						<li>Placeholder item I</li>
					</ul>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['application_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-4" role="tabpanel" aria-labelledby="admin-tab-btn-4" hidden>
					<h4>Application management</h4>
					<ul>
						<li>Placeholder item G</li>
						<li>Placeholder item H</li>
						<li>Placeholder item I</li>
					</ul>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['notifications']): ?><div class="admin-tab-panel" id="admin-tab-panel-5" role="tabpanel" aria-labelledby="admin-tab-btn-5" hidden>
					<h4>Notifications Content</h4>
					<ul>
						<li>Placeholder item J</li>
						<li>Placeholder item K</li>
						<li>Placeholder item L</li>
					</ul>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['permissions']): ?><div class="admin-tab-panel" id="admin-tab-panel-6" role="tabpanel" aria-labelledby="admin-tab-btn-6" hidden>
						<h4>Role Permissions</h4>
						<p>Choose a role, then adjust access by section.</p>

						<div class="permissions-editor-shell">
							<div class="permissions-editor-summary">
								<div class="permissions-editor-summary-copy">
									<p class="permissions-editor-kicker">Role-based access</p>
									<h5>Pick a role to review its grants</h5>
									<p class="permissions-editor-scope-note">Current edit scope: <strong><?php echo htmlspecialchars($adminPermissionEditScope['label'] ?? 'No editable roles', ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
								</div>

								<div class="permissions-editor-controls">
									<div class="form-group">
										<label for="admin-permissions-role-select">Role</label>
										<select id="admin-permissions-role-select">
											<option value="" selected disabled>Select role</option>
											<?php foreach ($adminEditableRoles as $adminRole): ?>
												<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo (int)($adminRole['id'] ?? 0); ?>: <?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-actions permissions-editor-actions">
										<button id="admin-permissions-save-btn" type="button" class="btn btn-primary">Save Permissions</button>
									</div>
								</div>
							</div>

							<div id="admin-permissions-matrix" class="admin-permissions-matrix"></div>
							<p id="admin-permissions-result" class="dev-tools-inline-result" hidden></p>
						</div>
				</div><?php endif; ?>
			</div>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>
