<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sql_helpers.php';
require_once __DIR__ . '/../includes/page_context.php';
startAuthSession();
requireAuth();
requirePagePermission('admin', 'read');
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navigation.php'; ?>
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
	$adminUserListPayload = [
		'success' => true,
		'users' => [],
		'status_filter' => 'all',
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
	$adminRoleManagementFlags = [
		'create' => false,
		'update' => false,
		'delete' => false,
	];
	$adminAppSettingsPayload = [
		'success' => true,
		'settings' => [
			'app_api_key' => '',
			'app_timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC',
			'app_mode' => defined('APP_MODE') ? APP_MODE : 'demo',
			'reset_on_index_visit' => defined('RESET_ON_INDEX_VISIT') ? RESET_ON_INDEX_VISIT : false,
		],
	];
	$adminDatabaseManagementPayload = [
		'success' => true,
		'database_name' => '',
		'summary' => [
			'table_count' => 0,
			'total_rows' => 0,
			'healthy_tables' => 0,
			'needs_review_tables' => 0,
			'empty_tables' => 0,
			'data_size_bytes' => 0,
			'index_size_bytes' => 0,
		],
		'tables' => [],
	];
	$adminTimezoneOptions = timezone_identifiers_list();
	if (!in_array('UTC', $adminTimezoneOptions, true)) {
		array_unshift($adminTimezoneOptions, 'UTC');
	}
	sort($adminTimezoneOptions);
	$adminPdo = null;
	try {
		$adminPdo = getDashboardPdo();
		ensurePermissionsSchema($adminPdo);
		$adminRoles = getAvailableRoles($adminPdo);
	} catch (Throwable $e) {
		$adminRoles = [];
		$adminPdo = null;
	}
	try {
		if ($adminPdo instanceof PDO) {
			$adminUserListPayload = getAdminUserListPayload($adminPdo, 'all');
		}
	} catch (Throwable $e) {
		$adminUserListPayload = [
			'success' => true,
			'users' => [],
			'status_filter' => 'all',
		];
	}
	try {
		if ($adminPdo instanceof PDO) {
			$adminPermissionEditorPayload = getRolePermissionsEditorPayload($adminPdo, $adminCurrentUserId);
		}
	} catch (Throwable $e) {
		$adminPermissionEditorPayload = [
			'roles' => [],
			'resources' => [],
			'permissions' => [],
			'role_permissions' => [],
			'allowed_permissions_by_resource' => [],
		];
	}
	try {
		if ($adminPdo instanceof PDO && $adminCurrentUserId > 0) {
			$adminPermissionEditScope = getUserPermissionEditScope($adminPdo, $adminCurrentUserId);
			$adminEditableRoles = getEditableRolesForPermissionScope($adminRoles, $adminPermissionEditScope);
		}
	} catch (Throwable $e) {
		$adminPermissionEditScope = [
			'mode' => 'none',
			'label' => 'No editable roles',
			'current_role_id' => null,
			'max_role_id' => 0,
		];
		$adminEditableRoles = [];
	}
	try {
		if ($adminPdo instanceof PDO) {
			if ($adminCurrentUserId > 0) {
				$adminTabPermissionFlags = dashboardBuildPermissionFlags($adminPdo, $adminCurrentUserId, [
					'user_management' => ['admin', 'admin_user_management'],
					'role_management' => ['admin', 'admin_role_management'],
					'database_management' => ['admin', 'admin_database_management'],
					'application_management' => ['admin', 'admin_application_management'],
					'notifications' => ['admin', 'admin_notifications_management'],
					'permissions' => ['admin', 'admin_permissions_management'],
				], $adminTabPermissionFlags);
				$adminRoleManagementFlags = dashboardBuildPermissionFlags($adminPdo, $adminCurrentUserId, [
					'create' => ['admin', 'admin_role_create'],
					'update' => ['admin', 'admin_role_update'],
					'delete' => ['admin', 'admin_role_delete'],
				], $adminRoleManagementFlags);
			}
		}
	} catch (Throwable $e) {
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
	try {
		if ($adminPdo instanceof PDO) {
			$adminAppSettingsPayload = getApplicationManagementSettingsPayload($adminPdo);
		}
	} catch (Throwable $e) {
		$adminAppSettingsPayload = [
			'success' => true,
			'settings' => [
				'app_api_key' => '',
				'app_timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC',
				'app_mode' => defined('APP_MODE') ? APP_MODE : 'demo',
				'reset_on_index_visit' => defined('RESET_ON_INDEX_VISIT') ? RESET_ON_INDEX_VISIT : false,
			],
		];
	}
	try {
		if ($adminPdo instanceof PDO) {
			$adminDatabaseManagementPayload = getDatabaseManagementPayload($adminPdo);
		}
	} catch (Throwable $e) {
		$adminDatabaseManagementPayload = [
			'success' => false,
			'database_name' => '',
			'summary' => [
				'table_count' => 0,
				'total_rows' => 0,
				'healthy_tables' => 0,
				'needs_review_tables' => 0,
				'empty_tables' => 0,
				'data_size_bytes' => 0,
				'index_size_bytes' => 0,
			],
			'tables' => [],
		];
	}
	$adminHasAnyTabAccess = false;
	foreach ($adminTabPermissionFlags as $adminTabPermissionFlagValue) {
		if ($adminTabPermissionFlagValue === true) {
			$adminHasAnyTabAccess = true;
			break;
		}
	}
	if (!$adminHasAnyTabAccess && $adminCurrentUserId > 0) {
		$adminTabPermissionFlags = [
			'user_management' => true,
			'role_management' => true,
			'database_management' => true,
			'application_management' => true,
			'notifications' => true,
			'permissions' => true,
		];
	}
	$adminRolesJson = dashboardJsonEncodeOrFallback($adminRoles, '[]');
	$adminCurrentRoleIdJson = dashboardJsonEncodeOrFallback($adminCurrentRoleId, '0');
	$adminEditableRolesJson = dashboardJsonEncodeOrFallback($adminEditableRoles, '[]');
	$adminPermissionEditorJson = dashboardJsonEncodeOrFallback($adminPermissionEditorPayload, '{"roles":[],"resources":[],"permissions":[],"role_permissions":[]}');
	$adminUserListJson = dashboardJsonEncodeOrFallback($adminUserListPayload, '{"success":true,"users":[],"status_filter":"all"}');
	$adminPermissionEditScopeJson = dashboardJsonEncodeOrFallback($adminPermissionEditScope, '{"mode":"none","label":"No editable roles","current_role_id":null,"max_role_id":0}');
	$adminTabPermissionJson = dashboardJsonEncodeOrFallback($adminTabPermissionFlags, '{"user_management":false,"role_management":false,"database_management":false,"application_management":false,"notifications":false,"permissions":false}');
	$adminRoleManagementJson = dashboardJsonEncodeOrFallback($adminRoleManagementFlags ?? ['create' => false, 'update' => false, 'delete' => false], '{"create":false,"update":false,"delete":false}');
	$adminAppSettingsJson = dashboardJsonEncodeOrFallback($adminAppSettingsPayload, '{"success":true,"settings":{"app_api_key":"","app_timezone":"UTC","app_mode":"demo","reset_on_index_visit":false}}');
	$adminDatabaseManagementJson = dashboardJsonEncodeOrFallback($adminDatabaseManagementPayload, '{"success":true,"database_name":"","summary":{"table_count":0,"total_rows":0,"healthy_tables":0,"needs_review_tables":0,"empty_tables":0,"data_size_bytes":0,"index_size_bytes":0},"tables":[]}');
?>

<script>
	window.ADMIN_ROLE_MANAGEMENT_ROLES = <?php echo $adminRolesJson; ?>;
	window.ADMIN_CURRENT_ROLE_ID = <?php echo $adminCurrentRoleIdJson; ?>;
	window.ADMIN_EDITABLE_ROLES = <?php echo $adminEditableRolesJson; ?>;
	window.ADMIN_ROLE_PERMISSION_EDITOR = <?php echo $adminPermissionEditorJson; ?>;
	window.ADMIN_USER_LIST = <?php echo $adminUserListJson; ?>;
	window.ADMIN_PERMISSION_EDIT_SCOPE = <?php echo $adminPermissionEditScopeJson; ?>;
	window.ADMIN_PAGE_PERMISSIONS = <?php echo $adminTabPermissionJson; ?>;
	window.ADMIN_ROLE_MANAGEMENT_FLAGS = <?php echo $adminRoleManagementJson; ?>;
	window.ADMIN_APP_SETTINGS = <?php echo $adminAppSettingsJson; ?>;
	window.ADMIN_DATABASE_MANAGEMENT = <?php echo $adminDatabaseManagementJson; ?>;
</script>

	<main class="main-content">
		<section class="content-section active admin-section" id="admin-container">
			<h2>Admin</h2>
			<div class="account-card admin-card">
				<h3>Admin Workspace</h3>

				<div class="admin-tabs" role="tablist" aria-label="Admin sections">
					<?php if ($adminTabPermissionFlags['user_management']): ?><button type="button" class="admin-tab-btn active" id="admin-tab-btn-user-management" role="tab" aria-selected="true" aria-controls="admin-tab-panel-user-management" data-admin-tab="user-management">User Management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['role_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-role-management" role="tab" aria-selected="false" aria-controls="admin-tab-panel-role-management" data-admin-tab="role-management">Role Management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['permissions']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-role-permissions" role="tab" aria-selected="false" aria-controls="admin-tab-panel-role-permissions" data-admin-tab="role-permissions">Role Permissions</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['notifications']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-notifications-management" role="tab" aria-selected="false" aria-controls="admin-tab-panel-notifications-management" data-admin-tab="notifications-management">Notifications Management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['application_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-application-management" role="tab" aria-selected="false" aria-controls="admin-tab-panel-application-management" data-admin-tab="application-management">Application Management</button><?php endif; ?>
					<?php if ($adminTabPermissionFlags['database_management']): ?><button type="button" class="admin-tab-btn" id="admin-tab-btn-database-management" role="tab" aria-selected="false" aria-controls="admin-tab-panel-database-management" data-admin-tab="database-management">Database Management</button><?php endif; ?>
				</div>

				<?php if ($adminTabPermissionFlags['user_management']): ?><div class="admin-tab-panel active" id="admin-tab-panel-user-management" role="tabpanel" aria-labelledby="admin-tab-btn-user-management">
					<h4>User Management</h4>
					<p>Create new users and update existing users details.</p>
					<input type="hidden" id="admin-current-user-id" value="<?php echo $adminCurrentUserId; ?>">

					<div class="admin-accordion">
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-create">
								<span>Create New User</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-create" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="admin-users-create-email">Email</label>
										<input type="email" id="admin-users-create-email" maxlength="255" placeholder="new.user@example.com">
									</div>
									<div class="form-group">
										<label for="admin-users-create-username">Username</label>
										<input type="text" id="admin-users-create-username" maxlength="100" placeholder="newuser">
									</div>
									<div class="form-group">
										<label for="admin-users-create-display-name">Display Name</label>
										<input type="text" id="admin-users-create-display-name" maxlength="150" placeholder="New User">
									</div>
									<div class="form-group">
										<label for="admin-users-create-role">Role</label>
										<select id="admin-users-create-role">
											<option value="" selected disabled>Select role</option>
											<?php foreach ($adminRoles as $adminRole): ?>
												<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-group">
										<label for="admin-users-create-status">Status</label>
										<select id="admin-users-create-status">
											<option value="active" selected>active</option>
											<option value="disabled">disabled</option>
										</select>
									</div>
									<div class="form-actions">
										<button id="admin-users-create-btn" type="button" class="btn btn-primary">Create User</button>
									</div>
									<p id="admin-users-create-result" class="admin-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>

						<div class="admin-accordion-section admin-user-list-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-user-list">
								<span>User List</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-user-list" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group admin-user-list-filter-group">
										<label for="admin-users-list-status-filter">Status</label>
										<select id="admin-users-list-status-filter">
											<option value="all" selected>All</option>
											<option value="active">Active</option>
											<option value="disabled">Deactivated</option>
										</select>
									</div>
									<div class="admin-user-list-wrap">
										<table class="data-table admin-user-list-table" id="admin-user-list-table">
											<thead>
												<tr>
													<th>ID</th>
													<th>Username</th>
													<th>Email</th>
													<th>Display Name</th>
													<th>Last Login</th>
													<th>Edit User</th>
												</tr>
											</thead>
											<tbody id="admin-user-list-table-body">
												<?php if (empty($adminUserListPayload['users'])): ?>
													<tr class="admin-user-list-empty-row"><td colspan="6">No users found.</td></tr>
												<?php else: ?>
													<?php foreach ($adminUserListPayload['users'] as $adminUser): ?>
														<tr>
															<td><?php echo (int)($adminUser['id'] ?? 0); ?></td>
															<td><?php echo htmlspecialchars($adminUser['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
															<td><?php echo htmlspecialchars($adminUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
															<td><?php echo htmlspecialchars($adminUser['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
															<td><?php echo htmlspecialchars(($adminUser['last_login_at'] ?? '') !== '' ? (string)$adminUser['last_login_at'] : 'Never', ENT_QUOTES, 'UTF-8'); ?></td>
															<td><button type="button" class="btn btn-secondary btn-sm admin-users-edit-user-btn" data-user-id="<?php echo (int)($adminUser['id'] ?? 0); ?>">Edit User</button></td>
														</tr>
													<?php endforeach; ?>
												<?php endif; ?>
											</tbody>
										</table>
									</div>
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
										<label for="admin-users-update-lookup">Lookup (email, username or id)</label>
										<input type="text" id="admin-users-update-lookup" maxlength="100" placeholder="e.g. 12 or johndoe">
									</div>
									<div class="form-actions">
										<button id="admin-users-update-detect-btn" type="button" class="btn btn-secondary">Detect User</button>
									</div>
									<p id="admin-users-update-detected" class="admin-tools-inline-result" hidden></p>

									<div id="admin-users-update-fields" hidden>
										<div class="form-group">
											<label for="admin-users-update-email">Email</label>
											<input type="email" id="admin-users-update-email" maxlength="255" placeholder="user@example.com" disabled>
										</div>
										<div class="form-group">
											<label for="admin-users-update-username">Username</label>
											<input type="text" id="admin-users-update-username" maxlength="100" placeholder="username" disabled>
										</div>
										<div class="form-group">
											<label for="admin-users-update-display-name">Display Name</label>
											<input type="text" id="admin-users-update-display-name" maxlength="150" placeholder="Display name" disabled>
										</div>
										<div class="form-group">
											<label for="admin-users-update-role">Role</label>
											<select id="admin-users-update-role" disabled>
												<option value="" selected disabled>Select role</option>
												<?php foreach ($adminRoles as $adminRole): ?>
													<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="form-group">
											<label for="admin-users-update-status">Status</label>
											<select id="admin-users-update-status" disabled>
												<option value="active">active</option>
												<option value="disabled">disabled</option>
											</select>
										</div>
										<div class="form-group">
											<label for="admin-users-update-reset-password">Reset Password</label>
											<select id="admin-users-update-reset-password" disabled>
												<option value="no" selected>No</option>
												<option value="yes">Yes</option>
											</select>
										</div>
									</div>
									<div class="form-actions" id="admin-users-update-actions" hidden>
										<button id="admin-users-update-btn" type="button" class="btn btn-primary" disabled>Update User</button>
									</div>
									<p id="admin-users-update-result" class="admin-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['role_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-role-management" role="tabpanel" aria-labelledby="admin-tab-btn-role-management" hidden>
					<h4>Role Management</h4>
					<p>Create, update, and remove roles. Deleting a role will reassign affected users to the next lower role ID, or the next higher role ID if nothing exists below the deleted role.</p>

					<div class="admin-role-table-wrap">
						<table class="data-table admin-role-table" id="admin-role-table">
							<thead>
								<tr>
									<th>ID</th>
									<th>Name</th>
									<th>Description</th>
									<th>Edit Role</th>
								</tr>
							</thead>
							<tbody id="admin-role-table-body">
								<?php if (empty($adminRoles)): ?>
									<tr class="admin-role-empty-row"><td colspan="4">No roles found.</td></tr>
								<?php else: ?>
									<?php foreach ($adminRoles as $adminRole): ?>
										<tr>
											<td><?php echo (int)($adminRole['id'] ?? 0); ?></td>
											<td><?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($adminRole['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><button type="button" class="btn btn-secondary btn-sm admin-roles-edit-role-btn" data-role-id="<?php echo (int)($adminRole['id'] ?? 0); ?>">Edit Role</button></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="admin-accordion">
						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-create">
								<span>Add New Role</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-create" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="admin-roles-create-name">Name</label>
										<input type="text" id="admin-roles-create-name" maxlength="50" placeholder="viewer">
									</div>
									<div class="form-group">
										<label for="admin-roles-create-description">Description</label>
										<textarea id="admin-roles-create-description" maxlength="255" rows="3" placeholder="Read-only access role"></textarea>
									</div>
									<div class="form-actions">
										<button id="admin-roles-create-btn" type="button" class="btn btn-primary">Create Role</button>
									</div>
									<p id="admin-roles-create-result" class="admin-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>

						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-update">
								<span>Update Role</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-update" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="admin-roles-update-lookup">Lookup (id or name)</label>
										<input type="text" id="admin-roles-update-lookup" maxlength="100" placeholder="e.g. 7 or viewer">
									</div>
									<div class="form-actions">
										<button id="admin-roles-update-detect-btn" type="button" class="btn btn-secondary">Detect Role</button>
									</div>
									<p id="admin-roles-update-detected" class="admin-tools-inline-result" hidden></p>

									<div id="admin-roles-update-fields" hidden>
										<div class="form-group">
											<label for="admin-roles-update-name">Name</label>
											<input type="text" id="admin-roles-update-name" maxlength="50" placeholder="viewer" disabled>
										</div>
										<div class="form-group">
											<label for="admin-roles-update-description">Description</label>
											<textarea id="admin-roles-update-description" maxlength="255" rows="3" placeholder="Read-only access role" disabled></textarea>
										</div>
									</div>
									<div class="form-actions" id="admin-roles-update-actions" hidden>
										<button id="admin-roles-update-btn" type="button" class="btn btn-primary" disabled>Update Role</button>
									</div>
									<p id="admin-roles-update-result" class="admin-tools-inline-result" hidden></p>
								</div>
							</div>
						</div>

						<div class="admin-accordion-section">
							<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-role-delete">
								<span>Delete Role and Reassign Users</span>
								<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<div class="admin-accordion-body" id="admin-accordion-body-role-delete" hidden>
								<div class="account-form" autocomplete="off">
									<div class="form-group">
										<label for="admin-roles-delete-role">Role</label>
										<select id="admin-roles-delete-role">
											<option value="" selected disabled>Select role to delete</option>
											<?php foreach ($adminRoles as $adminRole): ?>
												<option value="<?php echo (int)($adminRole['id'] ?? 0); ?>"><?php echo (int)($adminRole['id'] ?? 0); ?>: <?php echo htmlspecialchars($adminRole['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-actions">
										<button id="admin-roles-delete-btn" type="button" class="btn btn-danger" disabled>Delete Role</button>
									</div>
									<p id="admin-roles-delete-detected" class="admin-tools-inline-result" hidden></p>
									<p id="admin-roles-delete-result" class="admin-tools-inline-result" hidden></p>
									<div id="admin-roles-delete-details" class="role-delete-details" hidden></div>
								</div>
							</div>
						</div>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['permissions']): ?><div class="admin-tab-panel" id="admin-tab-panel-role-permissions" role="tabpanel" aria-labelledby="admin-tab-btn-role-permissions" hidden>
						<h4>Role Permissions</h4>
						<p>Choose a role, then adjust access by section.</p>

						<div class="permissions-editor-shell">
							<div class="permissions-editor-summary" id="admin-permissions-summary" data-sticky="false">
								<button type="button" class="admin-permissions-summary-pin" id="admin-permissions-summary-pin" aria-pressed="false" aria-label="Pin Role Permissions card" title="Pin Role Permissions card">
									<svg class="admin-permissions-summary-pin-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
										<path d="M14.7 2.5l6.8 6.8-1.8 1.8-2-2-3.1 3.1 2.8 2.8-1.2 1.2-4.7-4.7-4.2 4.2-1.7-1.7 4.2-4.2-4.7-4.7 1.2-1.2 2.8 2.8 3.1-3.1-2-2 1.8-1.8z"></path>
									</svg>
								</button>
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
									<p id="admin-permissions-result" class="admin-tools-inline-result" hidden></p>
								</div>
							</div>

							<div id="admin-permissions-matrix" class="admin-permissions-matrix"></div>
						</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['notifications']): ?><div class="admin-tab-panel" id="admin-tab-panel-notifications-management" role="tabpanel" aria-labelledby="admin-tab-btn-notifications-management" hidden>
					<h4>Notifications Management</h4>
					<p>Look up a user by id, username, or e-mail address, then review the notifications they have received and sent.</p>

					<div class="admin-notifications-shell">
						<section class="admin-notifications-search-card">
							<form id="admin-notifications-form" class="account-form" autocomplete="off">
								<div class="form-group">
									<label for="admin-notifications-lookup">Lookup (id, username, or e-mail)</label>
									<input type="text" id="admin-notifications-lookup" maxlength="255" placeholder="e.g. 12, johndoe, or user@example.com">
								</div>
								<div class="form-actions">
									<button id="admin-notifications-detect-btn" type="submit" class="btn btn-secondary">Load Notifications</button>
								</div>
								<p id="admin-notifications-detected" class="admin-tools-inline-result" hidden></p>
							</form>
						</section>

						<section class="admin-notifications-table-section">
							<div class="admin-notifications-table-wrap">
								<table class="data-table admin-notifications-table" id="admin-notifications-table">
									<thead>
										<tr>
											<th>Date created</th>
											<th>Sent/Received</th>
											<th>Notification type</th>
											<th>Title</th>
											<th>Message</th>
											<th>Read</th>
										</tr>
									</thead>
									<tbody id="admin-notifications-table-body">
										<tr class="admin-notifications-empty-row">
											<td colspan="6">Look up a user to view sent and received notifications.</td>
										</tr>
									</tbody>
								</table>
							</div>
						</section>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['application_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-application-management" role="tabpanel" aria-labelledby="admin-tab-btn-application-management" hidden>
					<h4>Application Management</h4>
					<p>Update the dashboard-wide values stored in <strong>app_settings</strong>.</p>

					<div class="admin-app-shell">
						<div class="admin-app-summary">
							<p class="permissions-editor-kicker">Application configuration</p>
							<h5>Manage the secret API key and runtime defaults from one place.</h5>
							<div class="form-group admin-app-current-key-group">
								<label for="admin-app-api-key-current">Current API key</label>
								<div class="admin-api-key-field">
									<input type="password" id="admin-app-api-key-current" readonly value="<?php echo htmlspecialchars((string)($adminAppSettingsPayload['settings']['api_key'] ?? ($adminAppSettingsPayload['settings']['app_api_key'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
									<button type="button" class="admin-api-key-toggle" id="admin-app-api-key-toggle" aria-pressed="false" aria-label="Show API key" title="Show API key">
										<svg class="admin-api-key-toggle-icon admin-api-key-toggle-icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5c5.5 0 9.9 4.1 11 7-1.1 2.9-5.5 7-11 7S2.1 14.9 1 12c1.1-2.9 5.5-7 11-7Zm0 2C8 7 4.6 9.7 3.5 12 4.6 14.3 8 17 12 17s7.4-2.7 8.5-5C19.4 9.7 16 7 12 7Zm0 1.8A3.2 3.2 0 1 1 12 15.2a3.2 3.2 0 0 1 0-6.4Zm0 2A1.2 1.2 0 1 0 12 13.2a1.2 1.2 0 0 0 0-2.4Z"/></svg>
										<svg class="admin-api-key-toggle-icon admin-api-key-toggle-icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4L20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
									</button>
								</div>
							</div>
							<p class="permissions-editor-copy">These values are read by <code>config.php</code> on every request, so changes take effect immediately for new page loads.</p>
						</div>

						<div class="admin-accordion">
							<div class="admin-accordion-section admin-app-section">
								<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-app-key">
									<span>API Key</span>
									<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
								</button>
								<div class="admin-accordion-body" id="admin-accordion-body-app-key" hidden>
									<div class="account-form" autocomplete="off">
										<div class="form-group">
											<label for="admin-app-api-key-new">New API key</label>
											<input type="text" id="admin-app-api-key-new" maxlength="512" placeholder="Paste a new API key to replace the current one">
										</div>
										<div class="form-actions">
											<button id="admin-app-api-key-save" type="button" class="btn btn-primary">Update API Key</button>
										</div>
										<p class="admin-app-note">The current key is displayed as read-only. Use the eye icon to reveal or hide it, then enter a replacement key below if you need to rotate it.</p>
										<p id="admin-app-api-key-result" class="admin-tools-inline-result" hidden></p>
									</div>
								</div>
							</div>

							<div class="admin-accordion-section admin-app-section">
								<button type="button" class="admin-accordion-header" aria-expanded="false" aria-controls="admin-accordion-body-app-runtime">
									<span>Runtime Defaults</span>
									<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
								</button>
								<div class="admin-accordion-body" id="admin-accordion-body-app-runtime" hidden>
									<div class="account-form" autocomplete="off">
										<div class="form-group">
											<label for="admin-app-timezone">app_timezone</label>
											<select id="admin-app-timezone">
												<?php foreach ($adminTimezoneOptions as $adminTimezoneOption): ?>
													<option value="<?php echo htmlspecialchars($adminTimezoneOption, ENT_QUOTES, 'UTF-8'); ?>"<?php echo (($adminAppSettingsPayload['settings']['app_timezone'] ?? '') === $adminTimezoneOption) ? ' selected' : ''; ?>><?php echo htmlspecialchars($adminTimezoneOption, ENT_QUOTES, 'UTF-8'); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="form-group">
											<label for="admin-app-mode">app_mode</label>
											<select id="admin-app-mode">
												<option value="demo"<?php echo (($adminAppSettingsPayload['settings']['app_mode'] ?? '') === 'demo') ? ' selected' : ''; ?>>Demo</option>
												<option value="production"<?php echo (($adminAppSettingsPayload['settings']['app_mode'] ?? '') === 'production') ? ' selected' : ''; ?>>Production</option>
											</select>
										</div>
										<div class="form-group">
											<label for="admin-app-reset-on-index-visit">reset_on_index_visit</label>
											<select id="admin-app-reset-on-index-visit">
												<option value="true"<?php echo !empty($adminAppSettingsPayload['settings']['reset_on_index_visit']) ? ' selected' : ''; ?>>True</option>
												<option value="false"<?php echo empty($adminAppSettingsPayload['settings']['reset_on_index_visit']) ? ' selected' : ''; ?>>False</option>
											</select>
										</div>
										<div class="form-actions">
											<button id="admin-app-settings-save" type="button" class="btn btn-primary">Save Application Settings</button>
										</div>
										<p class="admin-app-note">Timezone, mode, and reset behavior are stored together so the runtime defaults stay aligned with the dashboard configuration.</p>
										<p id="admin-app-settings-result" class="admin-tools-inline-result" hidden></p>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div><?php endif; ?>

				<?php if ($adminTabPermissionFlags['database_management']): ?><div class="admin-tab-panel" id="admin-tab-panel-database-management" role="tabpanel" aria-labelledby="admin-tab-btn-database-management" hidden>
					<h4>Database Management</h4>
					<?php
						$adminDatabaseSummary = $adminDatabaseManagementPayload['summary'] ?? [];
						$adminDatabaseTables = $adminDatabaseManagementPayload['tables'] ?? [];
						$adminDatabaseName = trim((string)($adminDatabaseManagementPayload['database_name'] ?? ''));
						$adminDatabaseTableCount = (int)($adminDatabaseSummary['table_count'] ?? 0);
						$adminDatabaseTotalRows = (int)($adminDatabaseSummary['total_rows'] ?? 0);
						$adminDatabaseHealthyTables = (int)($adminDatabaseSummary['healthy_tables'] ?? 0);
						$adminDatabaseNeedsReviewTables = (int)($adminDatabaseSummary['needs_review_tables'] ?? 0);
						$adminDatabaseEmptyTables = (int)($adminDatabaseSummary['empty_tables'] ?? 0);
						$adminDatabaseDataSize = (int)($adminDatabaseSummary['data_size_bytes'] ?? 0);
						$adminDatabaseIndexSize = (int)($adminDatabaseSummary['index_size_bytes'] ?? 0);
						$adminDatabaseTotalSize = $adminDatabaseDataSize + $adminDatabaseIndexSize;
					?>
					<div class="admin-db-shell">
						<div class="admin-db-toolbar">
							<p class="admin-db-toolbar-copy">Current database: <code><?php echo htmlspecialchars($adminDatabaseName !== '' ? $adminDatabaseName : 'unknown', ENT_QUOTES, 'UTF-8'); ?></code></p>
							<div class="admin-db-toolbar-actions">
								<button type="button" class="btn btn-secondary btn-sm" data-admin-db-toggle-all="open">Expand all</button>
								<button type="button" class="btn btn-secondary btn-sm" data-admin-db-toggle-all="close">Collapse all</button>
							</div>
						</div>
						<div class="admin-db-summary-grid">
							<div class="admin-db-summary-card">
								<span>Tables</span>
								<strong><?php echo number_format($adminDatabaseTableCount); ?></strong>
							</div>
							<div class="admin-db-summary-card">
								<span>Total rows</span>
								<strong><?php echo number_format($adminDatabaseTotalRows); ?></strong>
							</div>
							<div class="admin-db-summary-card">
								<span>Healthy tables</span>
								<strong><?php echo number_format($adminDatabaseHealthyTables); ?></strong>
							</div>
							<div class="admin-db-summary-card">
								<span>Needs review</span>
								<strong><?php echo number_format($adminDatabaseNeedsReviewTables); ?></strong>
							</div>
						</div>
						<p class="admin-db-summary-note">Storage usage: <strong><?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseTotalSize), ENT_QUOTES, 'UTF-8'); ?></strong> total, including <?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseDataSize), ENT_QUOTES, 'UTF-8'); ?> data and <?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseIndexSize), ENT_QUOTES, 'UTF-8'); ?> indexes. Empty tables: <strong><?php echo number_format($adminDatabaseEmptyTables); ?></strong>.</p>
						<?php if (count($adminDatabaseTables) === 0): ?>
							<div class="permissions-editor-empty">
								<p>No database tables were found in this schema.</p>
								<p class="permissions-editor-empty-note">Add application tables or check the database connection before using this tab.</p>
							</div>
						<?php else: ?>
							<div class="admin-accordion admin-db-accordion">
								<?php foreach ($adminDatabaseTables as $adminDatabaseTable): ?>
									<?php
										$adminDatabaseTableName = trim((string)($adminDatabaseTable['name'] ?? ''));
										$adminDatabaseTableDisplayName = trim((string)($adminDatabaseTable['display_name'] ?? ''));
										$adminDatabaseTableHealth = $adminDatabaseTable['health'] ?? [];
										$adminDatabaseTableRowCount = (int)($adminDatabaseTable['row_count'] ?? 0);
										$adminDatabaseTableColumnCount = (int)($adminDatabaseTable['column_count'] ?? 0);
										$adminDatabaseTablePrimaryKeys = array_values(array_filter(array_map('trim', $adminDatabaseTable['primary_key_columns'] ?? [])));
										$adminDatabaseTableDataLength = (int)($adminDatabaseTable['data_length_bytes'] ?? 0);
										$adminDatabaseTableIndexLength = (int)($adminDatabaseTable['index_length_bytes'] ?? 0);
										$adminDatabaseTableTotalSize = (int)($adminDatabaseTable['total_size_bytes'] ?? ($adminDatabaseTableDataLength + $adminDatabaseTableIndexLength));
										$adminDatabaseTableEngine = trim((string)($adminDatabaseTable['engine'] ?? ''));
										$adminDatabaseTableCollation = trim((string)($adminDatabaseTable['collation'] ?? ''));
										$adminDatabaseTableCreateTime = trim((string)($adminDatabaseTable['create_time'] ?? ''));
										$adminDatabaseTableUpdateTime = trim((string)($adminDatabaseTable['update_time'] ?? ''));
										$adminDatabaseTableComment = trim((string)($adminDatabaseTable['table_comment'] ?? ''));
										$adminDatabaseTableAutoIncrement = isset($adminDatabaseTable['auto_increment']) && $adminDatabaseTable['auto_increment'] !== null ? (int)$adminDatabaseTable['auto_increment'] : null;
										$adminDatabaseHealthKey = trim((string)($adminDatabaseTableHealth['key'] ?? 'healthy'));
										$adminDatabaseHealthLabel = trim((string)($adminDatabaseTableHealth['label'] ?? 'Healthy'));
										$adminDatabaseHealthDetail = trim((string)($adminDatabaseTableHealth['detail'] ?? ''));
										$adminDatabaseHealthBadgeClass = 'badge-green';
										if ($adminDatabaseHealthKey === 'warning') {
											$adminDatabaseHealthBadgeClass = 'badge-orange';
										} elseif ($adminDatabaseHealthKey === 'empty') {
											$adminDatabaseHealthBadgeClass = 'badge-blue';
										} elseif ($adminDatabaseHealthKey === 'error') {
											$adminDatabaseHealthBadgeClass = 'badge-red';
										}
										$adminDatabaseHeaderId = 'admin-db-table-' . preg_replace('/[^a-z0-9_-]+/i', '-', $adminDatabaseTableName);
										$adminDatabaseBodyId = $adminDatabaseHeaderId . '-body';
									?>
									<div class="admin-accordion-section admin-db-table-section">
										<button type="button" class="admin-accordion-header admin-db-table-header" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($adminDatabaseBodyId, ENT_QUOTES, 'UTF-8'); ?>">
											<span class="admin-db-table-header-copy">
												<strong><?php echo htmlspecialchars($adminDatabaseTableDisplayName !== '' ? $adminDatabaseTableDisplayName : $adminDatabaseTableName, ENT_QUOTES, 'UTF-8'); ?></strong>
												<span><?php echo htmlspecialchars($adminDatabaseTableName, ENT_QUOTES, 'UTF-8'); ?></span>
											</span>
											<span class="admin-db-table-header-meta">
													<span class="admin-db-table-header-meta-stack">
														<span class="badge <?php echo htmlspecialchars($adminDatabaseHealthBadgeClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminDatabaseHealthLabel, ENT_QUOTES, 'UTF-8'); ?></span>
														<span class="admin-db-table-rowcount"><?php echo number_format($adminDatabaseTableRowCount); ?> rows</span>
													</span>
											</span>
											<svg class="admin-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
										</button>
										<div class="admin-accordion-body admin-db-table-body" id="<?php echo htmlspecialchars($adminDatabaseBodyId, ENT_QUOTES, 'UTF-8'); ?>" hidden>
											<div class="admin-db-table-stat-grid">
												<div class="admin-db-stat">
													<span>Rows</span>
													<strong><?php echo number_format($adminDatabaseTableRowCount); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Health</span>
													<strong><?php echo htmlspecialchars($adminDatabaseHealthLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Engine</span>
													<strong><?php echo htmlspecialchars($adminDatabaseTableEngine !== '' ? $adminDatabaseTableEngine : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Columns</span>
													<strong><?php echo number_format($adminDatabaseTableColumnCount); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Data size</span>
													<strong><?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseTableDataLength), ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Index size</span>
													<strong><?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseTableIndexLength), ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Total size</span>
													<strong><?php echo htmlspecialchars(dashboardFormatBytes($adminDatabaseTableTotalSize), ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
												<div class="admin-db-stat">
													<span>Primary key</span>
													<strong><?php echo htmlspecialchars(count($adminDatabaseTablePrimaryKeys) > 0 ? implode(', ', $adminDatabaseTablePrimaryKeys) : 'None', ENT_QUOTES, 'UTF-8'); ?></strong>
												</div>
											</div>
											<p class="admin-db-table-note"><?php echo htmlspecialchars($adminDatabaseHealthDetail, ENT_QUOTES, 'UTF-8'); ?><?php if ($adminDatabaseTableComment !== ''): ?>. <?php echo htmlspecialchars($adminDatabaseTableComment, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
											<div class="admin-db-table-meta">
												<span>Created: <?php echo htmlspecialchars($adminDatabaseTableCreateTime !== '' ? $adminDatabaseTableCreateTime : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
												<span>Updated: <?php echo htmlspecialchars($adminDatabaseTableUpdateTime !== '' ? $adminDatabaseTableUpdateTime : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
												<span>Auto increment: <?php echo htmlspecialchars($adminDatabaseTableAutoIncrement !== null ? number_format($adminDatabaseTableAutoIncrement) : 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
												<?php if ($adminDatabaseTableCollation !== ''): ?><span>Collation: <?php echo htmlspecialchars($adminDatabaseTableCollation, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div><?php endif; ?>
			</div>
		</section>
	</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
