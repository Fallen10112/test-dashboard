function setupAdminPageHandlers() {
	const tabs = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-btn[data-admin-tab]'));
	const panels = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-panel'));
	const accordionHeaders = Array.prototype.slice.call(document.querySelectorAll('.admin-accordion-header'));
	const tabStorageKey = 'admin-page-active-tab';

	if (tabs.length === 0 || panels.length === 0) {
		return;
	}

	function activateTab(tabKey) {
		if (!tabKey) {
			return;
		}
		const normalizedTabKey = String(tabKey || '').trim();
		const hasMatchingTab = tabs.some(function(tabBtn) {
			return String(tabBtn.getAttribute('data-admin-tab') || '') === normalizedTabKey;
		});
		if (!hasMatchingTab) {
			return;
		}
		tabs.forEach(function(tabBtn) {
			const isActive = String(tabBtn.getAttribute('data-admin-tab') || '') === normalizedTabKey;
			tabBtn.classList.toggle('active', isActive);
			tabBtn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.forEach(function(panel) {
			const shouldShow = panel.id === 'admin-tab-panel-' + normalizedTabKey;
			panel.classList.toggle('active', shouldShow);
			panel.hidden = !shouldShow;
		});

		accordionHeaders.forEach(function(header) {
			const bodyId = header.getAttribute('aria-controls');
			const body = bodyId ? document.getElementById(bodyId) : null;
			header.setAttribute('aria-expanded', 'false');
			if (body) {
				body.hidden = true;
			}
		});

		try {
			localStorage.setItem(tabStorageKey, normalizedTabKey);
		} catch (error) {
			// Ignore storage failures and keep the UI usable.
		}
	}

	tabs.forEach(function(tabBtn) {
		tabBtn.addEventListener('click', function() {
			activateTab(tabBtn.getAttribute('data-admin-tab'));
		});
	});

	let initialTab = null;
	try {
		initialTab = localStorage.getItem(tabStorageKey);
	} catch (error) {
		initialTab = null;
	}
	if (!initialTab || !tabs.some(function(tabBtn) {
		return String(tabBtn.getAttribute('data-admin-tab') || '') === String(initialTab || '').trim();
	})) {
		initialTab = tabs.length > 0 ? tabs[0].getAttribute('data-admin-tab') : null;
	}
	activateTab(initialTab);

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
	var currentRoleId = parseInt(window.ADMIN_CURRENT_ROLE_ID, 10) || 0;
	var allowedPermissionsByResource = editorData.allowed_permissions_by_resource && typeof editorData.allowed_permissions_by_resource === 'object'
		? editorData.allowed_permissions_by_resource
		: {};
	var permissionOrder = {
		'read': 10,
		'customize': 15,
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
		'widgets': 20,
		'records': 30,
		'reports': 40,
		'audit_log': 50,
		'admin': 60,
		'dev_tools': 70
	};
	var resourceGroups = [
		{
			title: 'Core access',
			description: 'Landing, records, reports, and audit visibility.',
			keys: ['home', 'records', 'reports', 'audit_log']
		},
		{
			title: 'Header widgets',
			description: 'Visibility and customization controls for the header widgets.',
			keys: ['widgets']
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
		matrixContainer.innerHTML = buildPermissionEmptyStateHtml('No resources are currently available within your edit scope.', String(permissionEditScope.label || 'No editable roles'), false);
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

	function buildPermissionToggleHtml(resourceKey, permission, checked, disabled, noteText, extraClass) {
		var grantKey = String(resourceKey || '') + ':' + String(permission.key || '');
		var checkedAttribute = checked ? ' checked' : '';
		var disabledAttribute = disabled ? ' disabled' : '';
		var className = 'permission-toggle' + (extraClass ? ' ' + extraClass : '');
		var noteHtml = noteText ? '<em class="permission-toggle-note">' + escapeHtml(noteText) + '</em>' : '';
		return '<label class="' + className + '"><input type="checkbox" data-grant-key="' + escapeHtml(grantKey) + '"' + checkedAttribute + disabledAttribute + '><span><strong>' + escapeHtml(permission.display_name || permission.key) + '</strong><em>' + escapeHtml(permission.description || '') + '</em>' + noteHtml + '</span></label>';
	}

	function buildPermissionResourceCard(resource, permissionItems) {
		return '<article class="permission-resource-card"><div class="permission-resource-card-header"><div><h6>' + escapeHtml(resource.display_name || resource.key) + '</h6><p>' + escapeHtml(resource.description || '') + '</p></div></div><div class="permission-resource-actions">' + permissionItems + '</div></article>';
	}

	function buildPermissionGroup(title, description, cards) {
		return '<section class="permission-group"><div class="permission-group-heading"><h5>' + escapeHtml(title) + '</h5><p>' + escapeHtml(description) + '</p></div><div class="permission-group-grid">' + cards + '</div></section>';
	}

	function buildPermissionEditorHeader(selectedRoleName, visibleGrantCount) {
		return [
			'<div class="permission-editor-header">',
				'<div class="permission-editor-title"><strong>' + escapeHtml(selectedRoleName) + '</strong><span>permissions</span></div>',
				'<div class="permission-editor-metrics">',
					'<div><strong>' + String(visibleGrantCount) + '</strong><span> grants</span></div>',
				'</div>',
			'</div>'
		].join('');
	}

	function buildPermissionEditorMeta(scopeLabel) {
		return '<div class="permission-editor-meta"><p class="permission-editor-role-description permission-editor-role-scope">Current scope: ' + escapeHtml(scopeLabel) + '.</p></div>';
	}

	function buildPermissionEmptyStateHtml(message, scopeLabel, includeFeatureNote) {
		var parts = ['<div class="permissions-editor-empty"><p>' + escapeHtml(message) + '</p>'];
		if (includeFeatureNote) {
			parts.push('<p class="permissions-editor-empty-note">Permissions are grouped by feature so access is easier to scan than a single wide matrix.</p>');
		}
		parts.push('<p class="permissions-editor-empty-note">Current scope: ' + escapeHtml(scopeLabel) + '.</p></div>');
		return parts.join('');
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
			var isDisabled = false;
			var isChecked = !!resourcePermissions[permission.key];
			var noteText = '';
			var extraClass = '';
			if (resource.key === 'widgets' && permission.key === 'customize' && !resourcePermissions.read) {
				isDisabled = true;
				isChecked = false;
				noteText = 'Requires Visible';
				extraClass = 'is-dependent';
			}
			if (resource.key === 'widgets' && permission.key === 'customize' && resourcePermissions.read) {
				noteText = 'Requires Visible';
				extraClass = 'is-dependent';
			}
			return buildPermissionToggleHtml(resource.key, permission, isChecked, isDisabled, noteText, extraClass);
		}).join('');
		return buildPermissionResourceCard(resource, permissionItems);
	}

	function syncWidgetCustomizeDependency() {
		var visibleCheckbox = matrixContainer.querySelector('input[data-grant-key="widgets:read"]');
		var customizeCheckbox = matrixContainer.querySelector('input[data-grant-key="widgets:customize"]');
		if (!visibleCheckbox || !customizeCheckbox) {
			return;
		}

		var visibleEnabled = visibleCheckbox.checked;
		customizeCheckbox.disabled = !visibleEnabled;
		if (!visibleEnabled) {
			customizeCheckbox.checked = false;
		}
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
			matrixContainer.innerHTML = buildPermissionEmptyStateHtml(hasEditableRoles ? 'Select a role to view and edit permissions.' : 'No roles are currently editable with your permission scope.', scopeLabel, true);
			saveButton.disabled = true;
			return;
		}

		var selectedRole = roles.find(function(role) {
			return parseInt(role.id, 10) === selectedRoleId;
		}) || null;
		var selectedRoleName = selectedRole ? String(selectedRole.name || '') : 'Role';
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
			return buildPermissionGroup(group.title, group.description, cards);
		}).join('');
		var remainingCards = sortedResources.filter(function(resource) {
			return !usedKeys[resource.key];
		}).map(function(resource) {
			return renderResourceCard(resource, currentPermissions);
		}).join('');
		var headerHtml = buildPermissionEditorHeader(selectedRoleName, visibleGrantCount);
		var metaHtml = buildPermissionEditorMeta(String(permissionEditScope.label || 'No editable roles'));
		var remainingSectionHtml = remainingCards ? '<section class="permission-group"><div class="permission-group-heading"><h5>Other resources</h5><p>Resources not covered by the main groups above.</p></div><div class="permission-group-grid">' + remainingCards + '</div></section>' : '';
		matrixContainer.innerHTML = headerHtml + metaHtml + groupedCards + remainingSectionHtml;
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

	matrixContainer.addEventListener('change', function(event) {
		var target = event.target;
		if (!target || target.tagName !== 'INPUT' || target.type !== 'checkbox') {
			return;
		}
		if ((target.getAttribute('data-grant-key') || '') === 'widgets:read') {
			syncWidgetCustomizeDependency();
		}
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
		syncWidgetCustomizeDependency();

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
		var preferredRoleOption = currentRoleId > 0
			? Array.prototype.slice.call(roleSelect.options).find(function(option) {
				return parseInt(option.value, 10) === currentRoleId;
			})
			: null;
		if (preferredRoleOption) {
			roleSelect.value = String(currentRoleId);
		} else {
			roleSelect.selectedIndex = 1;
		}
	}

	renderMatrix();
	syncWidgetCustomizeDependency();
}


function setupAdminNotificationsManagementHandlers() {
	var form = document.getElementById('admin-notifications-form');
	var lookupInput = document.getElementById('admin-notifications-lookup');
	var detectButton = document.getElementById('admin-notifications-detect-btn');
	var resultElement = document.getElementById('admin-notifications-detected');
	var tableBody = document.getElementById('admin-notifications-table-body');

	if (!form || !lookupInput || !detectButton || !resultElement || !tableBody) {
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
		var text = String(message || '').trim();
		resultElement.classList.remove('is-error', 'is-success');
		if (text === '') {
			resultElement.textContent = '';
			resultElement.setAttribute('hidden', 'hidden');
			return;
		}

		resultElement.textContent = text;
		resultElement.classList.add(isError ? 'is-error' : 'is-success');
		resultElement.removeAttribute('hidden');
	}

	function setEmptyState(message) {
		tableBody.innerHTML = '';
		var row = document.createElement('tr');
		row.className = 'admin-notifications-empty-row';
		var cell = document.createElement('td');
		cell.colSpan = 6;
		cell.textContent = message;
		row.appendChild(cell);
		tableBody.appendChild(row);
	}

	function apiPost(action, payload) {
		var requestPayload = Object.assign({ action: action }, payload || {});
		return $.ajax({
			url: '../api.php',
			type: 'POST',
			contentType: 'application/json',
			dataType: 'json',
			data: JSON.stringify(requestPayload)
		});
	}

	function normalizeType(value) {
		var normalized = String(value || '').toLowerCase();
		if (normalized === 'warn') {
			normalized = 'warning';
		}
		if (['info', 'success', 'warning', 'error'].indexOf(normalized) === -1) {
			normalized = 'info';
		}
		return normalized;
	}

	function getTypeLabel(value) {
		var normalized = normalizeType(value);
		return normalized.charAt(0).toUpperCase() + normalized.slice(1);
	}

	function getTypeClass(value) {
		return 'admin-notification-pill--' + normalizeType(value);
	}

	function getDirectionLabel(value) {
		var normalized = String(value || '').toLowerCase();
		if (normalized === 'sent') {
			return 'Sent';
		}
		return 'Received';
	}

	function getDirectionClass(value) {
		var normalized = String(value || '').toLowerCase();
		if (normalized === 'sent') {
			return 'admin-notification-pill--blue';
		}
		return 'admin-notification-pill--green';
	}

	function renderNotificationRows(notifications) {
		tableBody.innerHTML = '';
		if (!Array.isArray(notifications) || notifications.length === 0) {
			setEmptyState('No notifications found for this user.');
			return;
		}

		notifications.forEach(function(notification) {
			var row = document.createElement('tr');

			var createdCell = document.createElement('td');
			createdCell.textContent = String(notification.created_at || '');

			var directionCell = document.createElement('td');
			var directionBadge = document.createElement('span');
			directionBadge.className = 'admin-notification-pill ' + getDirectionClass(notification.direction);
			directionBadge.textContent = getDirectionLabel(notification.direction);
			directionCell.appendChild(directionBadge);

			var typeCell = document.createElement('td');
			var typeBadge = document.createElement('span');
			typeBadge.className = 'admin-notification-pill ' + getTypeClass(notification.notification_type);
			typeBadge.textContent = getTypeLabel(notification.notification_type);
			typeCell.appendChild(typeBadge);

			var titleCell = document.createElement('td');
			titleCell.className = 'notification-title-cell';
			titleCell.textContent = String(notification.title || '');

			var messageCell = document.createElement('td');
			messageCell.className = 'notification-message-cell';
			messageCell.textContent = String(notification.message || '');

			var readCell = document.createElement('td');
			readCell.className = 'notification-read-cell';
			var readLabel = notification.is_read ? 'Yes' : 'No';
			var readAt = String(notification.read_at || '').trim();
			readCell.textContent = readLabel + ' - ' + readAt;

			row.appendChild(createdCell);
			row.appendChild(directionCell);
			row.appendChild(typeCell);
			row.appendChild(titleCell);
			row.appendChild(messageCell);
			row.appendChild(readCell);
			tableBody.appendChild(row);
		});
	}

	function userLabel(user) {
		if (!user || typeof user !== 'object') {
			return '';
		}

		var id = String(user.id || '');
		var displayName = String(user.display_name || '').trim();
		var username = String(user.username || '').trim();
		var email = String(user.email || '').trim();
		var label = '#' + id;
		if (displayName !== '') {
			label += ' ' + displayName;
		} else if (username !== '') {
			label += ' ' + username;
		} else if (email !== '') {
			label += ' ' + email;
		}
		return label;
	}

	function loadNotifications() {
		var lookup = String(lookupInput.value || '').trim();
		setInlineResult('', false);

		if (lookup === '') {
			setEmptyState('Look up a user to view sent and received notifications.');
			setInlineResult('Enter a user id, username, or email address first.', true);
			return;
		}

		detectButton.disabled = true;
		setInlineResult('Loading notifications...', false);

		apiPost('admin_notifications_lookup', { lookup: lookup, limit: 100 })
			.done(function(response) {
				if (!response || !response.success) {
					setEmptyState('Look up a user to view sent and received notifications.');
					setInlineResult((response && response.message) ? response.message : 'Failed to load notifications.', true);
					return;
				}

				var user = response.user || {};
				var notifications = Array.isArray(response.notifications) ? response.notifications : [];
				setInlineResult('Loaded ' + String(notifications.length) + ' notification(s) for ' + userLabel(user) + '.', false);
				renderNotificationRows(notifications);
			})
			.fail(function(xhr) {
				setEmptyState('Look up a user to view sent and received notifications.');
				var message = xhr && xhr.responseJSON && xhr.responseJSON.message
					? xhr.responseJSON.message
					: 'Failed to load notifications.';
				setInlineResult(message, true);
			})
			.always(function() {
				detectButton.disabled = false;
			});
	}

	form.addEventListener('submit', function(event) {
		event.preventDefault();
		loadNotifications();
	});

	lookupInput.addEventListener('input', function() {
		setInlineResult('', false);
		setEmptyState('Look up a user to view sent and received notifications.');
	});

	setEmptyState('Look up a user to view sent and received notifications.');
}
