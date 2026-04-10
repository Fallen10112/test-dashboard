function setupAdminPageHandlers() {
	const tabs = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-btn[data-admin-tab]'));
	const panels = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-panel'));

	if (tabs.length === 0 || panels.length === 0) {
		return;
	}

	function activateTab(tabKey) {
		if (!tabKey) {
			return;
		}
		tabs.forEach(function(tabBtn) {
			const isActive = String(tabBtn.getAttribute('data-admin-tab') || '') === String(tabKey);
			tabBtn.classList.toggle('active', isActive);
			tabBtn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.forEach(function(panel) {
			const shouldShow = panel.id === 'admin-tab-panel-' + String(tabKey);
			panel.classList.toggle('active', shouldShow);
			panel.hidden = !shouldShow;
		});
	}

	tabs.forEach(function(tabBtn) {
		tabBtn.addEventListener('click', function() {
			activateTab(tabBtn.getAttribute('data-admin-tab'));
		});
	});

	const initialTab = tabs.length > 0 ? tabs[0].getAttribute('data-admin-tab') : null;
	activateTab(initialTab);

	var accordionHeaders = Array.prototype.slice.call(document.querySelectorAll('.admin-accordion-header'));
	accordionHeaders.forEach(function(header) {
		header.addEventListener('click', function() {
			var expanded = header.getAttribute('aria-expanded') === 'true';
			var bodyId = header.getAttribute('aria-controls');
			var body = bodyId ? document.getElementById(bodyId) : null;
			header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			if (body) {
				body.hidden = expanded;
			}
		});
	});
}

function setupAdminPermissionManagementHandlers() {
	var roleSelect = document.getElementById('admin-permissions-role-select');
	var matrixContainer = document.getElementById('admin-permissions-matrix');
	var saveButton = document.getElementById('admin-permissions-save-btn');
	var resultElement = document.getElementById('admin-permissions-result');
	var editorData = window.ADMIN_ROLE_PERMISSION_EDITOR || {};
	var roles = Array.isArray(editorData.roles) ? editorData.roles.slice() : [];
	var resources = Array.isArray(editorData.resources) ? editorData.resources.slice() : [];
	var permissions = Array.isArray(editorData.permissions) ? editorData.permissions.slice() : [];
	var rolePermissions = editorData.role_permissions && typeof editorData.role_permissions === 'object'
		? editorData.role_permissions
		: {};
	var permissionEditScope = window.ADMIN_PERMISSION_EDIT_SCOPE || {};
	var allowedPermissionsByResource = editorData.allowed_permissions_by_resource && typeof editorData.allowed_permissions_by_resource === 'object'
		? editorData.allowed_permissions_by_resource
		: {};
	var permissionOrder = {
		'read': 10,
		'create': 20,
		'update': 30,
		'delete': 40,
		'export': 50,
		'manage_users': 60,
		'manage_permissions': 70,
		'admin_user_management': 80,
		'admin_role_management': 90,
		'admin_role_create': 100,
		'admin_role_update': 110,
		'admin_role_delete': 120,
		'admin_database_management': 130,
		'admin_application_management': 140,
		'admin_notifications_management': 150,
		'admin_permissions_management': 160
	};
	var resourceOrder = {
		'home': 10,
		'records': 20,
		'reports': 30,
		'audit_log': 40,
		'admin': 50,
		'dev_tools': 60
	};
	var resourceGroups = [
		{
			title: 'Core access',
			description: 'Landing, records, reports, and audit visibility.',
			keys: ['home', 'records', 'reports', 'audit_log']
		},
		{
			title: 'Admin workspace',
			description: 'Tabs inside the admin area, including role and user tools.',
			keys: ['admin']
		},
		{
			title: 'Maintenance tools',
			description: 'Developer and reset utilities used for maintenance work.',
			keys: ['dev_tools']
		}
	];

	if (!roleSelect || !matrixContainer || !saveButton || !resultElement) {
		return;
	}

	if (roles.length === 0 || permissions.length === 0) {
		matrixContainer.innerHTML = '<p>No permission metadata is available yet.</p>';
		saveButton.disabled = true;
		return;
	}

	if (resources.length === 0) {
		matrixContainer.innerHTML = '<div class="permissions-editor-empty"><p>No resources are currently available within your edit scope.</p><p class="permissions-editor-empty-note">Current scope: ' + escapeHtml(String(permissionEditScope.label || 'No editable roles')) + '.</p></div>';
		saveButton.disabled = true;
		return;
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function(character) {
			return ({
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			})[character] || character;
		});
	}

	function setInlineResult(message, isError) {
		if (!message) {
			resultElement.textContent = '';
			resultElement.setAttribute('hidden', 'hidden');
			resultElement.classList.remove('is-error', 'is-success');
			return;
		}

		resultElement.textContent = String(message);
		resultElement.classList.remove('is-error', 'is-success');
		resultElement.classList.add(isError ? 'is-error' : 'is-success');
		resultElement.removeAttribute('hidden');
	}

	function getSelectedRoleId() {
		return parseInt(roleSelect.value, 10) || 0;
	}

	function getSelectedRolePermissions() {
		var roleId = getSelectedRoleId();
		if (roleId < 1) {
			return {};
		}
		return rolePermissions[String(roleId)] || rolePermissions[roleId] || {};
	}

	function getResourcePermissionList(resourceKey) {
		var allowedKeys = Array.isArray(allowedPermissionsByResource[resourceKey])
			? allowedPermissionsByResource[resourceKey]
			: [];
		if (allowedKeys.length === 0) {
			return [];
		}
		var filteredPermissions = permissions.filter(function(permission) {
			return allowedKeys.indexOf(permission.key) !== -1;
		});
		return filteredPermissions.sort(function(left, right) {
			var leftOrder = Object.prototype.hasOwnProperty.call(permissionOrder, left.key) ? permissionOrder[left.key] : 999;
			var rightOrder = Object.prototype.hasOwnProperty.call(permissionOrder, right.key) ? permissionOrder[right.key] : 999;
			if (leftOrder !== rightOrder) {
				return leftOrder - rightOrder;
			}
			return String(left.display_name || left.key || '').localeCompare(String(right.display_name || right.key || ''));
		});
	}

	function renderResourceCard(resource, currentPermissions) {
		var resourcePermissions = currentPermissions[resource.key] || {};
		var permissionList = getResourcePermissionList(resource.key);
		var permissionItems = permissionList.map(function(permission) {
			var grantKey = String(resource.key || '') + ':' + String(permission.key || '');
			var checked = resourcePermissions[permission.key] ? ' checked' : '';
			return '<label class="permission-toggle"><input type="checkbox" data-grant-key="' + escapeHtml(grantKey) + '"' + checked + '><span><strong>' + escapeHtml(permission.display_name || permission.key) + '</strong><em>' + escapeHtml(permission.description || '') + '</em></span></label>';
		}).join('');
		return '<article class="permission-resource-card"><div class="permission-resource-card-header"><div><h6>' + escapeHtml(resource.display_name || resource.key) + '</h6><p>' + escapeHtml(resource.description || '') + '</p></div></div><div class="permission-resource-actions">' + permissionItems + '</div></article>';
	}

	function countCurrentGrants(currentPermissions) {
		var grantCount = 0;
		Object.keys(currentPermissions).forEach(function(resourceKey) {
			var permissionSet = currentPermissions[resourceKey] || {};
			Object.keys(permissionSet).forEach(function(permissionKey) {
				if (permissionSet[permissionKey]) {
					grantCount++;
				}
			});
		});
		return grantCount;
	}

	function renderMatrix() {
		var selectedRoleId = getSelectedRoleId();
		if (selectedRoleId < 1) {
			var scopeLabel = String(permissionEditScope.label || 'No editable roles');
			var hasEditableRoles = roleSelect.options.length > 1;
			matrixContainer.innerHTML = '<div class="permissions-editor-empty"><p>' + (hasEditableRoles ? 'Select a role to view and edit permissions.' : 'No roles are currently editable with your permission scope.') + '</p><p class="permissions-editor-empty-note">Permissions are grouped by feature so access is easier to scan than a single wide matrix.</p><p class="permissions-editor-empty-note">Current scope: ' + escapeHtml(scopeLabel) + '.</p></div>';
			saveButton.disabled = true;
			return;
		}

		var selectedRole = roles.find(function(role) {
			return parseInt(role.id, 10) === selectedRoleId;
		}) || null;
		var selectedRoleName = selectedRole ? String(selectedRole.name || '') : 'Role';
		var selectedRoleDescription = selectedRole ? String(selectedRole.description || '') : '';
		var currentPermissions = getSelectedRolePermissions();
		var sortedResources = resources.slice().sort(function(left, right) {
			var leftOrder = Object.prototype.hasOwnProperty.call(resourceOrder, left.key) ? resourceOrder[left.key] : 999;
			var rightOrder = Object.prototype.hasOwnProperty.call(resourceOrder, right.key) ? resourceOrder[right.key] : 999;
			if (leftOrder !== rightOrder) {
				return leftOrder - rightOrder;
			}
			return String(left.display_name || left.key || '').localeCompare(String(right.display_name || right.key || ''));
		});
		var visibleGrantCount = 0;
		var visibleAvailableCount = 0;
		sortedResources.forEach(function(resource) {
			var permissionList = getResourcePermissionList(resource.key);
			if (permissionList.length === 0) {
				return;
			}
			visibleAvailableCount += permissionList.length;
			var resourcePermissions = currentPermissions[resource.key] || {};
			permissionList.forEach(function(permission) {
				if (resourcePermissions[permission.key]) {
					visibleGrantCount++;
				}
			});
		});
		var usedKeys = {};
		var groupedCards = resourceGroups.map(function(group) {
			var cards = sortedResources.filter(function(resource) {
				return group.keys.indexOf(resource.key) !== -1;
			}).map(function(resource) {
				usedKeys[resource.key] = true;
				return renderResourceCard(resource, currentPermissions);
			}).join('');
			if (cards === '') {
				return '';
			}
			return '<section class="permission-group"><div class="permission-group-heading"><h5>' + escapeHtml(group.title) + '</h5><p>' + escapeHtml(group.description) + '</p></div><div class="permission-group-grid">' + cards + '</div></section>';
		}).join('');
		var remainingCards = sortedResources.filter(function(resource) {
			return !usedKeys[resource.key];
		}).map(function(resource) {
			return renderResourceCard(resource, currentPermissions);
		}).join('');
		matrixContainer.innerHTML = '<div class="permission-editor-header"><div><strong>' + escapeHtml(selectedRoleName) + '</strong><span>permissions</span></div><div class="permission-editor-metrics"><div><strong>' + String(visibleGrantCount) + '</strong><span>visible grants</span></div><div><strong>' + String(visibleAvailableCount) + '</strong><span>visible slots</span></div></div></div><p class="permission-editor-role-description">Current scope: ' + escapeHtml(String(permissionEditScope.label || 'No editable roles')) + '.</p>' + (selectedRoleDescription ? '<p class="permission-editor-role-description">' + escapeHtml(selectedRoleDescription) + '</p>' : '') + groupedCards + (remainingCards ? '<section class="permission-group"><div class="permission-group-heading"><h5>Other resources</h5><p>Resources not covered by the main groups above.</p></div><div class="permission-group-grid">' + remainingCards + '</div></section>' : '');
		saveButton.disabled = false;
	}

	function apiPost(action, payload) {
		var requestPayload = Object.assign({ action: action }, payload || {});
		return fetch('../api.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(requestPayload)
		}).then(function(response) {
			return response.json();
		});
	}

	roleSelect.addEventListener('change', function() {
		setInlineResult('', false);
		renderMatrix();
	});

	saveButton.addEventListener('click', function() {
		var selectedRoleId = getSelectedRoleId();
		if (selectedRoleId < 1) {
			setInlineResult('Select a role first.', true);
			return;
		}

		var grants = Array.prototype.slice.call(matrixContainer.querySelectorAll('input[type="checkbox"][data-grant-key]:checked')).map(function(checkbox) {
			return checkbox.getAttribute('data-grant-key') || '';
		}).filter(function(value) {
			return value !== '';
		});

		saveButton.disabled = true;
		setInlineResult('Saving permissions...', false);

		apiPost('admin_role_permissions_update', {
			role_id: selectedRoleId,
			grants: grants
		}).then(function(response) {
			if (!response || !response.success) {
				setInlineResult((response && response.message) ? response.message : 'Failed to save permissions.', true);
				return;
			}

			if (response.role_permissions && typeof response.role_permissions === 'object') {
				rolePermissions = response.role_permissions;
				window.ADMIN_ROLE_PERMISSION_EDITOR.role_permissions = rolePermissions;
			}
			setInlineResult(response.message || 'Role permissions updated.', false);
			renderMatrix();
		}).catch(function() {
			setInlineResult('Failed to save permissions.', true);
		}).finally(function() {
			saveButton.disabled = false;
		});
	});

	if (!roleSelect.value && roleSelect.options.length > 1) {
		roleSelect.selectedIndex = 1;
	}

	renderMatrix();
}
