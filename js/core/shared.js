let allData = null;
let allLogs = null;
let allAuditTrail = null;
let nextRecordId = 1;
let isHandlingForcedLogout = false;
let sessionPollTimerId = null;
let sessionPollFnRef = null;
let sessionVisibilityHandlerRef = null;
let notificationPollTimerId = null;
let notificationPollFnRef = null;
let notificationVisibilityHandlerRef = null;


let headerNotifications = [];
let headerUnreadNotificationCount = 0;
let hasNotificationBaselineLoaded = false;
let knownNotificationIds = new Set();
let localTimeWidgetTimerId = null;
const SUPPORTED_HEADER_WIDGET_KEYS = ['total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'];


function getDefaultHeaderWidgetPreferences() {
	return {
		total_entries: true,
		total_edits: true,
		adds_today: true,
		deletes_today: true,
		local_time: true
	};
}


function getHeaderWidgetPermissionState() {
	const hasPermissionState = typeof window.DASHBOARD_WIDGET_PERMISSIONS !== 'undefined';
	const permissions = hasPermissionState && window.DASHBOARD_WIDGET_PERMISSIONS && typeof window.DASHBOARD_WIDGET_PERMISSIONS === 'object'
		? window.DASHBOARD_WIDGET_PERMISSIONS
		: {};
	const allowedKeys = Array.isArray(permissions.allowed_keys)
		? permissions.allowed_keys.filter(function(widgetKey) {
			return SUPPORTED_HEADER_WIDGET_KEYS.indexOf(widgetKey) !== -1;
		})
		: (hasPermissionState ? [] : SUPPORTED_HEADER_WIDGET_KEYS.slice());
	return {
		canView: permissions.can_view === true,
		canCustomize: permissions.can_customize === true,
		allowedKeys: allowedKeys
	};
}


function formatLocalTimeHHMM(dateObj) {
	const d = dateObj instanceof Date ? dateObj : new Date();
	const hours = String(d.getHours()).padStart(2, '0');
	const minutes = String(d.getMinutes()).padStart(2, '0');
	return hours + ':' + minutes;
}


function updateLocalTimeWidgetValue() {
	setMetricValue('#metric-local-time', formatLocalTimeHHMM(new Date()));
}


function setupLocalTimeWidgetClock() {
	if (localTimeWidgetTimerId !== null) {
		clearInterval(localTimeWidgetTimerId);
		localTimeWidgetTimerId = null;
	}

	updateLocalTimeWidgetValue();
	localTimeWidgetTimerId = setInterval(updateLocalTimeWidgetValue, 1000);
}


function normalizeHeaderWidgetPreferences(rawPreferences) {
	const defaults = getDefaultHeaderWidgetPreferences();
	const source = (rawPreferences && typeof rawPreferences === 'object') ? rawPreferences : {};
	const normalized = {};
	const permissionState = getHeaderWidgetPermissionState();
	const allowedLookup = {};
	(permissionState.allowedKeys.length > 0 ? permissionState.allowedKeys : []).forEach(function(widgetKey) {
		allowedLookup[widgetKey] = true;
	});

	function normalizePreferenceFlag(value, fallbackValue) {
		if (typeof value === 'boolean') {
			return value;
		}
		if (typeof value === 'number') {
			return value !== 0;
		}
		if (typeof value === 'string') {
			const lowered = value.trim().toLowerCase();
			if (lowered === '1' || lowered === 'true' || lowered === 'yes' || lowered === 'on') {
				return true;
			}
			if (lowered === '0' || lowered === 'false' || lowered === 'no' || lowered === 'off' || lowered === '') {
				return false;
			}
		}
		return Boolean(fallbackValue);
	}

	SUPPORTED_HEADER_WIDGET_KEYS.forEach(function(widgetKey) {
		if (!allowedLookup[widgetKey]) {
			normalized[widgetKey] = false;
			return;
		}
		normalized[widgetKey] = source[widgetKey] !== undefined
			? normalizePreferenceFlag(source[widgetKey], defaults[widgetKey])
			: defaults[widgetKey];
	});

	return normalized;
}


function applyHeaderWidgetPreferences(preferences) {
	const normalized = normalizeHeaderWidgetPreferences(preferences);
	const container = document.querySelector('.header-metrics');
	if (!container) {
		return normalized;
	}

	const permissionState = getHeaderWidgetPermissionState();
	const allowedLookup = {};
	(permissionState.allowedKeys.length > 0 ? permissionState.allowedKeys : []).forEach(function(widgetKey) {
		allowedLookup[widgetKey] = true;
	});

	let visibleCount = 0;
	SUPPORTED_HEADER_WIDGET_KEYS.forEach(function(widgetKey) {
		const el = container.querySelector('[data-widget-key="' + widgetKey + '"]');
		if (!el) {
			return;
		}
		if (!allowedLookup[widgetKey]) {
			el.hidden = true;
			return;
		}

		const isVisible = normalized[widgetKey] === true;
		el.hidden = !isVisible;
		if (isVisible) {
			visibleCount += 1;
		}
	});

	container.hidden = visibleCount === 0;
	container.style.visibility = visibleCount === 0 ? 'hidden' : 'visible';
	container.classList.remove('header-metrics--loading');
	return normalized;
}


function loadHeaderWidgetPreferences() {
	if (document.querySelector('.header-metrics') === null) {
		return $.Deferred().resolve(getDefaultHeaderWidgetPreferences()).promise();
	}

	return $.ajax({
		url: '../api.php?action=widget_preferences',
		type: 'GET',
		dataType: 'json'
	}).done(function(response) {
		const preferences = response && typeof response === 'object' ? response.widgets : null;
		applyHeaderWidgetPreferences(preferences);
	}).fail(function() {
		applyHeaderWidgetPreferences(getDefaultHeaderWidgetPreferences());
	}).always(function() {
		const container = document.querySelector('.header-metrics');
		if (container) {
			if (container.hidden !== true) {
				container.style.visibility = 'visible';
			}
			container.classList.remove('header-metrics--loading');
		}
	});
}


window.DashboardHeaderWidgets = {
	getDefaults: getDefaultHeaderWidgetPreferences,
	normalize: normalizeHeaderWidgetPreferences,
	apply: applyHeaderWidgetPreferences,
	load: loadHeaderWidgetPreferences,
	keys: SUPPORTED_HEADER_WIDGET_KEYS.slice(),
	setupLocalTimeClock: setupLocalTimeWidgetClock
};


function setupUserAvatarDropdown() {
	const btn = document.getElementById('user-avatar-btn');
	const dropdown = document.getElementById('user-dropdown');
	if (!btn || !dropdown) { return; }

	btn.addEventListener('click', function(e) {
		e.stopPropagation();
		const isOpen = !dropdown.hidden;
		dropdown.hidden = isOpen;
		btn.setAttribute('aria-expanded', String(!isOpen));

		if (isOpen === false) {
			const notificationsDropdown = document.getElementById('notifications-dropdown');
			const notificationsBtn = document.getElementById('notifications-btn');
			if (notificationsDropdown && !notificationsDropdown.hidden && notificationsBtn) {
				notificationsDropdown.hidden = true;
				notificationsBtn.setAttribute('aria-expanded', 'false');
			}
		}
	});

	document.addEventListener('click', function(e) {
		if (!dropdown.hidden && !dropdown.contains(e.target) && !btn.contains(e.target)) {
			dropdown.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && !dropdown.hidden) {
			dropdown.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
			btn.focus();
		}
	});
}


function configureApiAuthentication() {
	$.ajaxSetup({
		beforeSend: function(xhr, settings) {
			if (settings && typeof settings.url === 'string' && settings.url.indexOf('api.php') !== -1) {
				xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			}
		}
	});
}


configureApiAuthentication();


function debounce(fn, delayMs) {
	let timeoutId = null;
	return function() {
		const context = this;
		const args = arguments;
		clearTimeout(timeoutId);
		timeoutId = setTimeout(function() {
			fn.apply(context, args);
		}, delayMs);
	};
}


function normalizePaginationPageValue(value, totalPages, fallbackPage) {
	const safeTotalPages = Math.max(1, parseInt(totalPages, 10) || 1);
	const safeFallbackPage = Math.min(Math.max(1, parseInt(fallbackPage, 10) || 1), safeTotalPages);
	const requestedPage = parseInt(value, 10);

	if (Number.isNaN(requestedPage)) {
		return safeFallbackPage;
	}

	return Math.min(Math.max(1, requestedPage), safeTotalPages);
}


function getPaginationPageOptions(totalPages, currentPage) {
	const safeTotalPages = Math.max(1, parseInt(totalPages, 10) || 1);
	const selectedPage = normalizePaginationPageValue(currentPage, safeTotalPages, 1);
	const options = [];

	for (let pageNumber = 1; pageNumber <= safeTotalPages; pageNumber += 1) {
		options.push({
			value: String(pageNumber),
			label: String(pageNumber),
			selected: pageNumber === selectedPage
		});
	}

	return options;
}


function buildPaginationSelectOptionsHtml(totalPages, currentPage) {
	return getPaginationPageOptions(totalPages, currentPage).map(function(option) {
		return '<option value="' + option.value + '"' + (option.selected ? ' selected' : '') + '>' + option.label + '</option>';
	}).join('');
}


function populatePaginationSelect(selectElement, totalPages, currentPage) {
	if (!selectElement) {
		return;
	}

	selectElement.innerHTML = '';
	getPaginationPageOptions(totalPages, currentPage).forEach(function(optionConfig) {
		const option = document.createElement('option');
		option.value = optionConfig.value;
		option.textContent = optionConfig.label;
		option.selected = optionConfig.selected;
		selectElement.appendChild(option);
	});
}


window.DashboardPagination = {
	normalizePageValue: normalizePaginationPageValue,
	getPageOptions: getPaginationPageOptions,
	buildOptionsHtml: buildPaginationSelectOptionsHtml,
	populateSelect: populatePaginationSelect
};


function setMetricValue(metricId, value) {
	$(metricId + ' .metric-value').text(value);
}


function ensureToastHost() {
	if ($('#toast-host').length === 0) {
		$('body').append('<div id="toast-host" class="toast-host" aria-live="polite" aria-atomic="true"></div>');
	}
}


function closeToast($toast, onClose) {
	if (!$toast || $toast.length === 0 || $toast.data('isClosing') === true) {
		return;
	}

	$toast.data('isClosing', true);
	$toast.addClass('is-closing');

	setTimeout(function() {
		$toast.remove();
		if (typeof onClose === 'function') {
			onClose();
		}
	}, 260);
}


function showToast(options) {
	ensureToastHost();

	const settings = Object.assign({
		type: 'info',
		title: '',
		message: '',
		autoCloseMs: 3000,
		showOkayButton: false,
		onClose: null,
		buttons: []
	}, options || {});

	const $host = $('#toast-host');
	$host.empty();

	const $toast = $('<div class="custom-toast" role="status"></div>');
	$toast.addClass('custom-toast-' + settings.type);

	const $content = $('<div class="custom-toast-content"></div>');
	if (settings.title) {
		$content.append($('<h4 class="custom-toast-title"></h4>').text(settings.title));
	}
	if (settings.message) {
		$content.append($('<p class="custom-toast-message"></p>').text(settings.message));
	}
	$toast.append($content);

	const hasCustomButtons = Array.isArray(settings.buttons) && settings.buttons.length > 0;
	if (hasCustomButtons || settings.showOkayButton) {
		const $actions = $('<div class="custom-toast-actions"></div>');

		if (hasCustomButtons) {
			settings.buttons.forEach(function(btn) {
				const $button = $('<button type="button" class="btn btn-sm"></button>')
					.addClass(btn.className || 'btn-secondary')
					.text(btn.label || 'Action')
					.on('click', function() {
						closeToast($toast, settings.onClose);
						if (typeof btn.onClick === 'function') {
							btn.onClick();
						}
					});
				$actions.append($button);
			});
		}

		if (settings.showOkayButton && !hasCustomButtons) {
			$actions.append(
				$('<button type="button" class="btn btn-sm btn-primary">Okay</button>').on('click', function() {
					closeToast($toast, settings.onClose);
				})
			);
		}

		$toast.append($actions);
	}

	$host.append($toast);

	if (!hasCustomButtons && settings.autoCloseMs > 0) {
		setTimeout(function() {
			closeToast($toast, settings.onClose);
		}, settings.autoCloseMs);
	}
}


function redirectToLoginPage() {
	window.location.href = '../pages/login.php';
}


function getForcedLogoutMessage(reason, fallbackMessage) {
	if (reason === 'session_replaced') {
		return 'You were signed out because this account logged in on another device.';
	}
	if (reason === 'session_expired') {
		return 'Your session expired. Please sign in again.';
	}
	return fallbackMessage || 'Your session is no longer valid. Please sign in again.';
}


function handleSessionAuthFailure(xhr) {
	if (!xhr || xhr.status !== 401) {
		return false;
	}

	const response = xhr.responseJSON || {};
	const reason = String(response.reason || '');
	const knownReason = reason === 'session_replaced' || reason === 'session_expired' || reason === 'unauthenticated';
	if (!knownReason) {
		return false;
	}

	if (isHandlingForcedLogout) {
		return true;
	}
	isHandlingForcedLogout = true;

	if (sessionPollTimerId !== null) {
		clearInterval(sessionPollTimerId);
		sessionPollTimerId = null;
	}

	if (notificationPollTimerId !== null) {
		clearInterval(notificationPollTimerId);
		notificationPollTimerId = null;
	}

	showToast({
		type: 'warning',
		title: 'Signed Out',
		message: getForcedLogoutMessage(reason, response.message),
		showOkayButton: true,
		autoCloseMs: 0
	});

	setTimeout(function() {
		redirectToLoginPage();
	}, 1200);

	return true;
}


function setupGlobalAjaxSessionGuard() {
	$(document).off('ajaxError.sessionGuard').on('ajaxError.sessionGuard', function(event, xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function renderHeaderNotifications() {
	const listEl = document.getElementById('notifications-list');
	const countEl = document.getElementById('notifications-count');
	const markAllReadBtn = document.getElementById('notifications-mark-all-read');
	const deleteAllBtn = document.getElementById('notifications-delete-all');
	if (!listEl || !countEl) {
		return;
	}

	listEl.innerHTML = '';

	if (!Array.isArray(headerNotifications) || headerNotifications.length === 0) {
		const emptyEl = document.createElement('div');
		emptyEl.className = 'notifications-empty';
		emptyEl.textContent = 'No notifications.';
		listEl.appendChild(emptyEl);
		countEl.textContent = '';
		countEl.hidden = true;
		if (markAllReadBtn) {
			markAllReadBtn.hidden = true;
		}
		if (deleteAllBtn) {
			deleteAllBtn.hidden = true;
		}
		return;
	}

	headerNotifications.forEach(function(notification) {
		const itemEl = document.createElement('div');
		itemEl.className = 'notification-item';
		const rawNotificationType = String(notification.type || 'info').trim().toLowerCase();
		const notificationType = rawNotificationType === 'warn'
			? 'warning'
			: (rawNotificationType === 'err' ? 'error' : rawNotificationType);
		itemEl.classList.add('notification-item-type-' + notificationType);
		if (notification.isRead !== true) {
			itemEl.classList.add('notification-item-unread');
		}

		const bodyEl = document.createElement('div');
		bodyEl.className = 'notification-item-body';

		const iconEl = document.createElement('span');
		iconEl.className = 'notification-item-type-icon';
		iconEl.setAttribute('aria-hidden', 'true');
		if (notificationType === 'success') {
			iconEl.textContent = '\u2713';
		} else if (notificationType === 'warning') {
			iconEl.textContent = '';
		} else if (notificationType === 'error') {
			iconEl.textContent = '';
		} else {
			iconEl.textContent = 'i';
		}

		const contentEl = document.createElement('div');
		contentEl.className = 'notification-item-content';

		const topRowEl = document.createElement('div');
		topRowEl.className = 'notification-item-top-row';

		const titleEl = document.createElement('div');
		titleEl.className = 'notification-item-title';
		titleEl.textContent = String(notification.title || 'Notification');
		topRowEl.appendChild(titleEl);

		const actionWrapEl = document.createElement('div');
		actionWrapEl.className = 'notification-item-actions';

		if (notification.isRead !== true && Number(notification.id || 0) > 0) {
			const markReadBtn = document.createElement('button');
			markReadBtn.type = 'button';
			markReadBtn.className = 'notification-item-mark-read-btn';
			markReadBtn.textContent = '\u2713';
			markReadBtn.title = 'Mark as read';
			markReadBtn.setAttribute('aria-label', 'Mark notification as read');
			markReadBtn.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				markHeaderNotificationRead(notification.id);
			});
			actionWrapEl.appendChild(markReadBtn);
		}

		if (Number(notification.id || 0) > 0) {
			const deleteBtn = document.createElement('button');
			deleteBtn.type = 'button';
			deleteBtn.className = 'notification-item-delete-btn';
			deleteBtn.textContent = '\u2715';
			deleteBtn.title = 'Delete notification';
			deleteBtn.setAttribute('aria-label', 'Delete notification');
			deleteBtn.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				deleteHeaderNotification(notification.id);
			});
			actionWrapEl.appendChild(deleteBtn);
		}

		if (actionWrapEl.childElementCount > 0) {
			topRowEl.appendChild(actionWrapEl);
		}

		const messageEl = document.createElement('div');
		messageEl.className = 'notification-item-message';
		messageEl.textContent = String(notification.message || '');

		const timeEl = document.createElement('div');
		timeEl.className = 'notification-item-time';
		timeEl.textContent = String(notification.timestamp || '').trim();

		const senderName = String(notification.sentByDisplayName || '').trim();
		const senderEl = document.createElement('div');
		senderEl.className = 'notification-item-sender';
		senderEl.textContent = senderName !== '' ? ('Sent by ' + senderName) : '';

		const metaEl = document.createElement('div');
		metaEl.className = 'notification-item-meta';
		if (timeEl.textContent !== '') {
			metaEl.appendChild(timeEl);
		}
		if (senderEl.textContent !== '') {
			metaEl.appendChild(senderEl);
		}

		contentEl.appendChild(topRowEl);
		contentEl.appendChild(messageEl);
		if (metaEl.childElementCount > 0) {
			contentEl.appendChild(metaEl);
		}
		bodyEl.appendChild(iconEl);
		bodyEl.appendChild(contentEl);
		itemEl.appendChild(bodyEl);

		itemEl.style.cursor = 'pointer';
		itemEl.addEventListener('click', function(e) {
			const clickedButton = e.target.closest('.notification-item-mark-read-btn, .notification-item-delete-btn');
			if (clickedButton) {
				return;
			}
			showNotificationDetailsToast(notification);
		});

		listEl.appendChild(itemEl);
	});

	if (markAllReadBtn) {
		markAllReadBtn.hidden = headerUnreadNotificationCount < 1;
	}
	if (deleteAllBtn) {
		deleteAllBtn.hidden = headerNotifications.length < 1;
	}

	const count = headerUnreadNotificationCount;
	countEl.textContent = String(count > 99 ? '99+' : count);
	countEl.hidden = count === 0;
}


function showNotificationDetailsToast(notification) {
	if (!notification || (!notification.title && !notification.message)) {
		return;
	}

	ensureToastHost();

	const settings = {
		type: String(notification.type || 'info'),
		title: String(notification.title || 'Notification'),
		message: String(notification.message || ''),
		timestamp: String(notification.timestamp || '').trim(),
		sentByDisplayName: String(notification.sentByDisplayName || '').trim(),
		id: Number(notification.id || 0),
		isRead: notification.isRead === true
	};

	const $host = $('#toast-host');
	$host.empty();

	const $toast = $('<div class="custom-toast custom-toast-details" role="status"></div>');
	$toast.addClass('custom-toast-' + settings.type);

	const $content = $('<div class="custom-toast-content"></div>');
	if (settings.title) {
		$content.append($('<h4 class="custom-toast-title"></h4>').text(settings.title));
	}
	if (settings.message) {
		$content.append($('<p class="custom-toast-message"></p>').text(settings.message));
	}

	const $meta = $('<div class="custom-toast-meta"></div>');
	if (settings.timestamp) {
		$meta.append($('<div class="custom-toast-meta-item"></div>').text('Date: ' + settings.timestamp));
	}
	if (settings.sentByDisplayName) {
		$meta.append($('<div class="custom-toast-meta-item"></div>').text('From: ' + settings.sentByDisplayName));
	}
	if ($meta.children().length > 0) {
		$content.append($meta);
	}

	$toast.append($content);

	const $actions = $('<div class="custom-toast-actions"></div>');

	if (!settings.isRead && settings.id > 0) {
		const $markReadBtn = $('<button type="button" class="btn btn-sm btn-secondary">Mark as Read</button>');
		$markReadBtn.on('click', function() {
			closeToast($toast);
			markHeaderNotificationRead(settings.id);
		});
		$actions.append($markReadBtn);
	}

	if (settings.id > 0) {
		const $deleteBtn = $('<button type="button" class="btn btn-sm btn-danger">Delete</button>');
		$deleteBtn.on('click', function() {
			closeToast($toast);
			deleteHeaderNotification(settings.id);
		});
		$actions.append($deleteBtn);
	}

	const $closeBtn = $('<button type="button" class="btn btn-sm btn-secondary">Close</button>');
	$closeBtn.on('click', function() {
		closeToast($toast);
	});
	$actions.append($closeBtn);

	$toast.append($actions);
	$host.append($toast);
}


function showIncomingNotificationToast(notification) {
	if (!notification || (!notification.title && !notification.message)) {
		return;
	}

	showToast({
		type: 'info',
		title: String(notification.title || 'New Notification'),
		message: String(notification.message || ''),
		autoCloseMs: 2600,
		showOkayButton: false
	});
}


function setHeaderNotifications(notifications, unreadCount) {
	if (!Array.isArray(notifications)) {
		headerNotifications = [];
		headerUnreadNotificationCount = 0;
		hasNotificationBaselineLoaded = true;
		knownNotificationIds = new Set();
		renderHeaderNotifications();
		return;
	}

	const mappedNotifications = notifications
		.filter(function(item) {
			return item && (item.title || item.message);
		})
		.map(function(item) {
			const datePart = String(item.date || '').trim();
			const timePart = String(item.time || '').trim();
			const timestamp = [datePart, timePart].filter(function(v) { return v !== ''; }).join(' ');
			const rawType = String(item.type || item.notification_type || 'info').trim().toLowerCase();
			const normalizedType = rawType === 'warn' ? 'warning' : (rawType === 'err' ? 'error' : rawType);
			return {
				id: Number(item.id || 0),
				title: String(item.title || 'Notification'),
				message: String(item.message || ''),
				type: normalizedType,
				isRead: item.is_read === true || item.isRead === true,
				sentByDisplayName: String(item.sent_by_display_name || item.sentByDisplayName || ''),
				timestamp: timestamp
			};
		});

	const nextKnownIds = new Set();
	const incomingNotifications = [];
	mappedNotifications.forEach(function(item) {
		const parsedId = Number(item.id || 0);
		if (parsedId > 0) {
			nextKnownIds.add(parsedId);
			if (hasNotificationBaselineLoaded && !knownNotificationIds.has(parsedId)) {
				incomingNotifications.push(item);
			}
		}
	});

	headerNotifications = mappedNotifications;
	knownNotificationIds = nextKnownIds;
	hasNotificationBaselineLoaded = true;

	if (typeof unreadCount === 'number' && unreadCount >= 0) {
		headerUnreadNotificationCount = unreadCount;
	} else {
		headerUnreadNotificationCount = headerNotifications.filter(function(item) {
			return item.isRead !== true;
		}).length;
	}

	renderHeaderNotifications();

	incomingNotifications
		.sort(function(a, b) {
			return Number(a.id || 0) - Number(b.id || 0);
		})
		.forEach(function(notification) {
			showIncomingNotificationToast(notification);
		});
}


function loadHeaderNotificationsFromServer() {
	return $.ajax({
		url: '../api.php?action=notifications&limit=25',
		type: 'GET',
		dataType: 'json'
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function createNotificationOnServer(item) {
	const title = String((item && item.title) || 'Notification');
	
	if (title.length > 64) {
		showToast({
			type: 'error',
			title: 'Validation Error',
			message: 'Notification title must not exceed 64 characters',
			autoCloseMs: 3000,
			showOkayButton: false
		});
		return $.Deferred().reject().promise();
	}

	return $.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'notification_create',
			title: title,
			message: String((item && item.message) || ''),
			type: String((item && item.type) || 'info')
		})
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		} else if (response && response.success === false) {
			showToast({
				type: 'error',
				title: 'Notification Error',
				message: String(response.message || 'Failed to create notification'),
				autoCloseMs: 3000,
				showOkayButton: false
			});
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function markAllHeaderNotificationsRead() {
	return $.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'notifications_mark_all_read'
		})
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function markHeaderNotificationRead(notificationId) {
	const parsedId = Number(notificationId || 0);
	if (!Number.isFinite(parsedId) || parsedId < 1) {
		return $.Deferred().resolve().promise();
	}

	return $.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'notification_mark_read',
			id: parsedId
		})
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function deleteHeaderNotification(notificationId) {
	const parsedId = Number(notificationId || 0);
	if (!Number.isFinite(parsedId) || parsedId < 1) {
		return $.Deferred().resolve().promise();
	}

	return $.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'notification_delete',
			id: parsedId
		})
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function deleteAllHeaderNotifications() {
	return $.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'notifications_delete_all'
		})
	}).done(function(response) {
		if (response && response.success === true) {
			setHeaderNotifications(response.items || [], Number(response.unread_count || 0));
		}
	}).fail(function(xhr) {
		handleSessionAuthFailure(xhr);
	});
}


function setupNotificationRealtimeSync() {
	if (notificationPollTimerId !== null) {
		clearInterval(notificationPollTimerId);
		notificationPollTimerId = null;
	}

	if (notificationPollFnRef !== null) {
		window.removeEventListener('focus', notificationPollFnRef);
	}
	if (notificationVisibilityHandlerRef !== null) {
		document.removeEventListener('visibilitychange', notificationVisibilityHandlerRef);
	}

	const poll = function() {
		if (document.visibilityState === 'hidden' || isHandlingForcedLogout) {
			return;
		}
		loadHeaderNotificationsFromServer();
	};

	notificationPollFnRef = poll;
	window.addEventListener('focus', notificationPollFnRef);
	notificationVisibilityHandlerRef = function() {
		if (document.visibilityState === 'visible' && notificationPollFnRef) {
			notificationPollFnRef();
		}
	};
	document.addEventListener('visibilitychange', notificationVisibilityHandlerRef);

	poll();
	notificationPollTimerId = setInterval(poll, 3000);
}


function setupNotificationDropdown() {
	const btn = document.getElementById('notifications-btn');
	const dropdown = document.getElementById('notifications-dropdown');
	const markAllReadBtn = document.getElementById('notifications-mark-all-read');
	const deleteAllBtn = document.getElementById('notifications-delete-all');
	if (!btn || !dropdown) {
		return;
	}

	loadHeaderNotificationsFromServer();
	setupNotificationRealtimeSync();

	btn.addEventListener('click', function(e) {
		e.stopPropagation();
		const isOpen = !dropdown.hidden;
		dropdown.hidden = isOpen;
		btn.setAttribute('aria-expanded', String(!isOpen));
		if (isOpen === false) {
			loadHeaderNotificationsFromServer();
		}

		const userDropdown = document.getElementById('user-dropdown');
		const userBtn = document.getElementById('user-avatar-btn');
		if (userDropdown && !userDropdown.hidden && userBtn) {
			userDropdown.hidden = true;
			userBtn.setAttribute('aria-expanded', 'false');
		}
	});

	document.addEventListener('click', function(e) {
		if (!dropdown.hidden && !dropdown.contains(e.target) && !btn.contains(e.target)) {
			dropdown.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && !dropdown.hidden) {
			dropdown.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
			btn.focus();
		}
	});

	if (markAllReadBtn) {
		markAllReadBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			markAllHeaderNotificationsRead();
		});
	}

	if (deleteAllBtn) {
		deleteAllBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			deleteAllHeaderNotifications();
		});
	}

	window.DashboardNotifications = {
		set: function(items) {
			setHeaderNotifications(items);
		},
		refresh: function() {
			return loadHeaderNotificationsFromServer();
		},
		add: function(item) {
			if (!item || (!item.title && !item.message)) {
				return $.Deferred().resolve().promise();
			}
			return createNotificationOnServer(item);
		},
		clear: function() {
			headerNotifications = [];
			headerUnreadNotificationCount = 0;
			renderHeaderNotifications();
		},
		markAllRead: function() {
			return markAllHeaderNotificationsRead();
		},
		markRead: function(notificationId) {
			return markHeaderNotificationRead(notificationId);
		},
		delete: function(notificationId) {
			return deleteHeaderNotification(notificationId);
		},
		deleteAll: function() {
			return deleteAllHeaderNotifications();
		}
	};
}


function setupSessionEnforcementPoller() {
	if (sessionPollTimerId !== null) {
		clearInterval(sessionPollTimerId);
		sessionPollTimerId = null;
	}

	if (sessionPollFnRef !== null) {
		window.removeEventListener('focus', sessionPollFnRef);
	}
	if (sessionVisibilityHandlerRef !== null) {
		document.removeEventListener('visibilitychange', sessionVisibilityHandlerRef);
	}

	const poll = function() {
		if (document.visibilityState === 'hidden' || isHandlingForcedLogout) {
			return;
		}

		$.ajax({
			url: '../api.php?action=session_status',
			type: 'GET',
			dataType: 'json'
		}).fail(function(xhr) {
			handleSessionAuthFailure(xhr);
		});
	};
	sessionPollFnRef = poll;
	window.addEventListener('focus', sessionPollFnRef);
	sessionVisibilityHandlerRef = function() {
		if (document.visibilityState === 'visible' && sessionPollFnRef) {
			sessionPollFnRef();
		}
	};
	document.addEventListener('visibilitychange', sessionVisibilityHandlerRef);

	poll();
	sessionPollTimerId = setInterval(poll, 2000);
}


function loadHeaderMetrics() {
	$.ajax({
		url: '../api.php?action=header_metrics',
		type: 'GET',
		dataType: 'json'
	}).done(function(response) {
		const metrics = response && typeof response === 'object' ? response : {};
		setMetricValue('#metric-total-entries', typeof metrics.total_entries === 'number' ? metrics.total_entries : '--');
		setMetricValue('#metric-total-edits', typeof metrics.total_edits === 'number' ? metrics.total_edits : '--');
		setMetricValue('#metric-adds-today', typeof metrics.adds_today === 'number' ? metrics.adds_today : '--');
		setMetricValue('#metric-deletes-today', typeof metrics.deletes_today === 'number' ? metrics.deletes_today : '--');
	}).fail(function() {
		setMetricValue('#metric-total-entries', '--');
		setMetricValue('#metric-total-edits', '--');
		setMetricValue('#metric-adds-today', '--');
		setMetricValue('#metric-deletes-today', '--');
	});
}


function isRecordAuditEntry(entry) {
	if (!entry || typeof entry !== 'object') {
		return false;
	}

	const recordType = String(entry.record_type || '').toLowerCase();
	if (recordType === 'record') {
		return true;
	}

	const dataset = String(entry.dataset || '').toLowerCase();
	return dataset === 'records';
}


function getTodayDateString() {
	const now = new Date(Date.now() - 3600 * 1000);
	const year = now.getFullYear();
	const month = String(now.getMonth() + 1).padStart(2, '0');
	const day = String(now.getDate()).padStart(2, '0');
	return year + '-' + month + '-' + day;
}


function countUniqueAuditRecordsForToday(entries, changeType) {
	const today = getTodayDateString();
	const normalizedType = String(changeType || '').toUpperCase();
	const uniqueIds = new Set();

	(entries || []).forEach(function(entry) {
		if (String(entry.date || '') !== today) {
			return;
		}
		if (String(entry.change_type || '').toUpperCase() !== normalizedType) {
			return;
		}

		const recordId = String(entry.record_id || '');

		if (normalizedType === 'DELETE' && recordId.toUpperCase() === 'BULK') {
			return;
		}

		if (recordId !== '') {
			uniqueIds.add(recordId);
		}
	});

	return uniqueIds.size;
}


function initializeTheme() {
	const isDarkMode = localStorage.getItem('theme-mode') === 'dark';
	const themeToggle = $('#theme-toggle-checkbox');

	if (isDarkMode) {
		$('body').addClass('dark-mode');
		themeToggle.prop('checked', true);
		$('#theme-label').text('Dark');
	}

	themeToggle.on('change', function() {
		toggleTheme();
	});
}


function toggleTheme() {
	const isDarkMode = $('#theme-toggle-checkbox').is(':checked');

	if (isDarkMode) {
		$('body').addClass('dark-mode');
		localStorage.setItem('theme-mode', 'dark');
		$('#theme-label').text('Dark');
	} else {
		$('body').removeClass('dark-mode');
		localStorage.setItem('theme-mode', 'light');
		$('#theme-label').text('Light');
	}
}


function setupDevToolsMaintenanceHandlers() {
	const pageActions = Array.isArray(window.DEV_TOOLS_MAINTENANCE_ACTIONS) ? window.DEV_TOOLS_MAINTENANCE_ACTIONS.slice() : [];
	const resetActions = pageActions.length > 0 ? pageActions : [
		{
			buttonId: '#reset-activity-log-btn',
			action: 'reset_activity_log',
			confirmTitle: 'Reset Activity Log?',
			confirmMessage: 'This will clear the activity_log table and reset its auto-increment key.',
			successTitle: 'Activity Log Reset',
			successMessage: 'The activity_log table has been reset successfully.'
		},
		{
			buttonId: '#reset-audit-log-btn',
			action: 'reset_audit_log',
			confirmTitle: 'Reset Audit Log?',
			confirmMessage: 'This will clear the audit_log table and reset its auto-increment key.',
			successTitle: 'Audit Log Reset',
			successMessage: 'The audit_log table has been reset successfully.'
		},
		{
			buttonId: '#reset-records-btn',
			action: 'reset_records',
			confirmTitle: 'Reset Records?',
			confirmMessage: 'This will reset the records table back to 3 sample entries and reset its key.',
			successTitle: 'Records Reset',
			successMessage: 'The records table has been reset to 3 sample entries.'
		},
		{
			buttonId: '#reset-data-tables-btn',
			action: 'reset_data_tables',
			confirmTitle: 'Reset Data Tables?',
			confirmMessage: 'This will reset activity_log, audit_log, and records together.',
			successTitle: 'Data Tables Reset',
			successMessage: 'Activity log, audit log, and records were reset successfully.'
		},
		{
			buttonId: '#reset-users-btn',
			action: 'reset_users',
			confirmTitle: 'Reset Users?',
			confirmMessage: 'This will delete all users except your current account and clear related user data.',
			successTitle: 'Users Reset',
			successMessage: 'All users except the current account were deleted.'
		},
		{
			buttonId: '#reset-notifications-btn',
			action: 'reset_notifications_table',
			confirmTitle: 'Reset Notifications?',
			confirmMessage: 'This will clear the notifications table and reset its key.',
			successTitle: 'Notifications Reset',
			successMessage: 'The notifications table has been reset successfully.'
		},
		{
			buttonId: '#reset-widget-prefs-btn',
			action: 'reset_widget_prefs',
			confirmTitle: 'Reset Widget Prefs?',
			confirmMessage: 'This will reset user_widget_preferences and reseed default widgets for all users.',
			successTitle: 'Widget Prefs Reset',
			successMessage: 'Widget preferences have been reset to defaults for all users.'
		},
		{
			buttonId: '#logout-all-users-btn',
			action: 'logout_all_users',
			confirmTitle: 'Log out all users?',
			confirmMessage: 'This will revoke all sessions and force every user to sign in again.',
			successTitle: 'All Users Logged Out',
			successMessage: 'All user sessions were reset successfully.'
		},
		{
			buttonId: '#add-sample-data-btn',
			action: 'add_sample_data',
			confirmTitle: 'Add Sample Data?',
			confirmMessage: 'This will create 150 sample records, 150 activity log entries, 150 audit log entries, and 10 sample users.',
			successTitle: 'Sample Data Added',
			successMessage: 'Sample data has been seeded successfully.'
		}
	];

	const hasAnyButtons = resetActions.some(function(cfg) {
		const selector = typeof cfg.buttonId === 'string' && cfg.buttonId.charAt(0) === '#'
			? cfg.buttonId
			: '#' + String(cfg.buttonId || '');
		return selector !== '#' && $(selector).length > 0;
	});
	if (!hasAnyButtons) {
		return;
	}

	resetActions.forEach(function(cfg) {
		const buttonSelector = typeof cfg.buttonId === 'string' && cfg.buttonId.charAt(0) === '#' ? cfg.buttonId : '#' + String(cfg.buttonId || '');
		const $button = $(buttonSelector);
		if ($button.length === 0) {
			return;
		}

		$button.on('click', function() {
			const payload = Object.assign({}, cfg.requestPayload || {});
			showToast({
				type: 'warning',
				title: cfg.confirmTitle,
				message: cfg.confirmMessage,
				autoCloseMs: 0,
				buttons: [
					{ label: 'Cancel', className: 'btn-secondary' },
					{
						label: 'Proceed',
						className: 'btn-danger',
						onClick: function() {
							$.ajax({
								url: '../api.php',
								type: 'POST',
								contentType: 'application/json',
								dataType: 'json',
								data: JSON.stringify(Object.assign({ action: cfg.action }, payload)),
								success: function() {
									if (typeof loadHeaderMetrics === 'function') {
										loadHeaderMetrics();
									}
									const shouldRedirectToLogin = cfg.action === 'logout_all_users';
									showToast({
										type: 'success',
										title: cfg.successTitle,
										message: cfg.successMessage,
										showOkayButton: true,
										autoCloseMs: 3000,
										onClose: function() {
											if (shouldRedirectToLogin) {
												window.location.href = 'login.php';
												return;
											}
											window.location.reload();
										}
									});
								},
								error: function(xhr, status, error) {
									const responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
										? xhr.responseJSON.message
										: '';
									showToast({
										type: 'error',
										title: 'Reset Failed',
										message: responseMessage || ('Error resetting table: ' + error),
										showOkayButton: true,
										autoCloseMs: 3000
									});
								}
							});
						}
					}
				]
			});
		});
	});
}


function setupUserManagementHandlers() {
	const $createButton = $('[id$="-users-create-btn"]');
	const $updateDetectButton = $('[id$="-users-update-detect-btn"]');
	const $updateButton = $('[id$="-users-update-btn"]');
	const $deleteDetectButton = $('[id$="-users-delete-detect-btn"]');
	const $deleteButton = $('[id$="-users-delete-btn"]');
	const $widgetResetDetectButton = $('[id$="-users-widget-reset-detect-btn"]');
	const $widgetResetButton = $('[id$="-users-widget-reset-btn"]');

	if ($createButton.length === 0 && $updateDetectButton.length === 0 && $deleteDetectButton.length === 0 && $widgetResetDetectButton.length === 0) {
		return;
	}

	let updateTargetUserId = 0;
	let deleteTargetUserId = 0;
	let widgetResetTargetUserId = 0;
	let updateDetectedLabel = '';
	let deleteDetectedLabel = '';
	let widgetResetDetectedLabel = '';
	const currentUserId = parseInt($('#admin-current-user-id').length > 0 ? $('#admin-current-user-id').val() : $('#dev-tools-current-user-id').val(), 10) || 0;

	const setInlineResult = function(selector, message, isError) {
		const $target = $(selector);
		if ($target.length === 0) {
			return;
		}

		$target.removeClass('is-error is-success');
		if (message && String(message).trim() !== '') {
			$target.text(String(message));
			$target.addClass(isError ? 'is-error' : 'is-success');
			$target.removeAttr('hidden');
		} else {
			$target.text('');
			$target.attr('hidden', 'hidden');
		}
	};

	const apiPost = function(action, payload) {
		const requestPayload = $.extend({ action: action }, payload || {});
		return $.ajax({
			url: '../api.php',
			type: 'POST',
			contentType: 'application/json',
			dataType: 'json',
			data: JSON.stringify(requestPayload)
		});
	};

	const initialUserListPayload = window.ADMIN_USER_LIST && typeof window.ADMIN_USER_LIST === 'object'
		? window.ADMIN_USER_LIST
		: {};
	const $userListStatusFilter = $('[id$="-users-list-status-filter"]');
	const $userListTableBody = $('#admin-user-list-table-body');
	const $updateSectionBody = $('#admin-accordion-body-update');
	const $updateSectionHeader = $('[aria-controls="admin-accordion-body-update"]');
	let currentUserList = [];
	let currentUserListStatus = 'all';

	const normalizeUserListEntry = function(user) {
		if (!user || typeof user !== 'object') {
			return null;
		}

		const id = parseInt(user.id, 10) || 0;
		if (id < 1) {
			return null;
		}

		return {
			id: id,
			username: String(user.username || ''),
			email: String(user.email || ''),
			display_name: String(user.display_name || ''),
			status: String(user.status || '').toLowerCase(),
			last_login_at: String(user.last_login_at || '')
		};
	};

	const getUserListStatusFilter = function() {
		const filterValue = String($userListStatusFilter.val() || 'all').toLowerCase();
		return ['all', 'active', 'disabled'].indexOf(filterValue) !== -1 ? filterValue : 'all';
	};

	const formatUserLastLogin = function(value) {
		const text = String(value || '').trim();
		return text !== '' ? text : 'Never';
	};

	const renderUserListTable = function() {
		if ($userListTableBody.length === 0) {
			return;
		}

		currentUserListStatus = getUserListStatusFilter();
		const filteredUsers = currentUserList.filter(function(user) {
			if (currentUserListStatus === 'all') {
				return true;
			}
			return String(user.status || '').toLowerCase() === currentUserListStatus;
		});

		$userListTableBody.empty();
		if (filteredUsers.length === 0) {
			const emptyRow = document.createElement('tr');
			emptyRow.className = 'admin-user-list-empty-row';
			const emptyCell = document.createElement('td');
			emptyCell.colSpan = 6;
			emptyCell.textContent = currentUserList.length === 0 ? 'No users found.' : 'No users match this status filter.';
			emptyRow.appendChild(emptyCell);
			$userListTableBody.append(emptyRow);
			return;
		}

		filteredUsers.forEach(function(user) {
			const row = document.createElement('tr');
			const idCell = document.createElement('td');
			const usernameCell = document.createElement('td');
			const emailCell = document.createElement('td');
			const displayNameCell = document.createElement('td');
			const lastLoginCell = document.createElement('td');
			const actionCell = document.createElement('td');
			const editButton = document.createElement('button');
			idCell.textContent = String(user.id || '');
			usernameCell.textContent = String(user.username || '');
			emailCell.textContent = String(user.email || '');
			displayNameCell.textContent = String(user.display_name || '');
			lastLoginCell.textContent = formatUserLastLogin(user.last_login_at);
			editButton.type = 'button';
			editButton.className = 'btn btn-secondary btn-sm admin-users-edit-user-btn';
			editButton.textContent = 'Edit User';
			editButton.setAttribute('data-user-id', String(user.id || ''));
			editButton.addEventListener('click', function() {
				editUserFromList(user.id);
			});
			actionCell.appendChild(editButton);
			row.appendChild(idCell);
			row.appendChild(usernameCell);
			row.appendChild(emailCell);
			row.appendChild(displayNameCell);
			row.appendChild(lastLoginCell);
			row.appendChild(actionCell);
			$userListTableBody.append(row);
		});
	};

	const setUserList = function(users) {
		currentUserList = Array.isArray(users)
			? users.map(normalizeUserListEntry).filter(function(user) {
				return user !== null;
			})
			: [];
		renderUserListTable();
	};

	const refreshUserList = function() {
		return $.ajax({
			url: '../api.php',
			type: 'GET',
			dataType: 'json',
			data: {
				action: 'admin_user_list',
				status: 'all'
			}
		}).done(function(response) {
			if (response && response.success && Array.isArray(response.users)) {
				setUserList(response.users);
			}
		});
	};

	const openUpdateUserSection = function() {
		if ($updateSectionBody.length > 0) {
			$updateSectionBody.removeAttr('hidden');
		}
		if ($updateSectionHeader.length > 0) {
			$updateSectionHeader.attr('aria-expanded', 'true');
		}
	};

	const editUserFromList = function(userId) {
		const normalizedUserId = parseInt(userId, 10) || 0;
		if (normalizedUserId < 1) {
			return;
		}

		openUpdateUserSection();
		$('[id$="-users-update-lookup"]').val(String(normalizedUserId));
		setInlineResult('[id$="-users-update-result"]', '', false);
		$updateDetectButton.trigger('click');
		const updateBodyEl = $updateSectionBody.get(0);
		if (updateBodyEl && typeof updateBodyEl.scrollIntoView === 'function') {
			updateBodyEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	};

	if ($userListStatusFilter.length > 0) {
		$userListStatusFilter.on('change', function() {
			renderUserListTable();
		});
	}

	setUserList(Array.isArray(initialUserListPayload.users) ? initialUserListPayload.users : []);

	const userLabel = function(user) {
		if (!user || typeof user !== 'object') {
			return '';
		}
		const id = String(user.id || '');
		const displayName = String(user.display_name || '');
		const roleName = String(user.role_name || '');
		let label = 'Detected user ' + id + ', ' + (displayName !== '' ? displayName : '(no display name)');
		if (roleName !== '') {
			label += ' (' + roleName + ')';
		}
		return label;
	};

	const setUpdateControlsEnabled = function(enabled) {
		const canEdit = !!enabled;
		const $updateFields = $('[id$="-users-update-fields"]');
		const $updateActions = $('[id$="-users-update-actions"]');
		if ($updateFields.length > 0) {
			if (canEdit) {
				$updateFields.removeAttr('hidden');
			} else {
				$updateFields.attr('hidden', 'hidden');
			}
		}
		if ($updateActions.length > 0) {
			if (canEdit) {
				$updateActions.removeAttr('hidden');
			} else {
				$updateActions.attr('hidden', 'hidden');
			}
		}
		$('[id$="-users-update-email"]').prop('disabled', !canEdit);
		$('[id$="-users-update-username"]').prop('disabled', !canEdit);
		$('[id$="-users-update-display-name"]').prop('disabled', !canEdit);
		$('[id$="-users-update-role"]').prop('disabled', !canEdit);
		$('[id$="-users-update-status"]').prop('disabled', !canEdit);
		$('[id$="-users-update-reset-password"]').prop('disabled', !canEdit);
		$updateButton.prop('disabled', !canEdit);
	};

	setUpdateControlsEnabled(false);

	$('[id$="-users-update-lookup"]').on('input', function() {
		updateTargetUserId = 0;
		updateDetectedLabel = '';
		setUpdateControlsEnabled(false);
		setInlineResult('[id$="-users-update-detected"]', '', false);
	});

	$('[id$="-users-delete-lookup"]').on('input', function() {
		deleteTargetUserId = 0;
		deleteDetectedLabel = '';
		$deleteButton.prop('disabled', true);
		setInlineResult('[id$="-users-delete-detected"]', '', false);
	});

	$('[id$="-users-widget-reset-lookup"]').on('input', function() {
		widgetResetTargetUserId = 0;
		widgetResetDetectedLabel = '';
		$widgetResetButton.prop('disabled', true);
		setInlineResult('[id$="-users-widget-reset-detected"]', '', false);
		setInlineResult('[id$="-users-widget-reset-result"]', '', false);
	});

	const lookupUser = function(lookupValue, onSuccess, onError) {
		const lookup = String(lookupValue || '').trim();
		if (lookup === '') {
			onError('Enter a username or id first.');
			return;
		}

		apiPost('admin_user_lookup', { lookup: lookup })
			.done(function(response) {
				if (response && response.success && response.user) {
					onSuccess(response.user);
					return;
				}
				onError('User not found.');
			})
			.fail(function(xhr) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.message
					? xhr.responseJSON.message
					: 'User lookup failed.';
				onError(message);
			});
	};

	$createButton.on('click', function() {
		setInlineResult('[id$="-users-create-result"]', '', false);
		const email = String($('[id$="-users-create-email"]').val() || '').trim();
		const username = String($('[id$="-users-create-username"]').val() || '').trim();
		const displayName = String($('[id$="-users-create-display-name"]').val() || '').trim();
		const roleId = parseInt($('[id$="-users-create-role"]').val(), 10) || 0;
		const status = String($('[id$="-users-create-status"]').val() || 'active').toLowerCase();
		if (roleId < 1) {
			setInlineResult('[id$="-users-create-result"]', 'Select a role before creating a user.', true);
			return;
		}

		apiPost('admin_user_create', {
			email: email,
			username: username,
			display_name: displayName,
			status: status,
			role_id: roleId
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('[id$="-users-create-result"]', 'Failed to create user.', true);
				return;
			}

			const generatedPassword = String(response.generated_password || '');
			const user = response.user || {};
			setInlineResult(
				'[id$="-users-create-result"]',
				'User created. ' + userLabel(user) + ' | Generated password: ' + generatedPassword,
				false
			);
			showToast({
				type: 'success',
				title: 'User Created',
				message: 'Generated password: ' + generatedPassword,
				showOkayButton: true,
				autoCloseMs: 0
			});
			refreshUserList();
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to create user.';
			setInlineResult('[id$="-users-create-result"]', message, true);
		});
	});

	$updateDetectButton.on('click', function() {
		setInlineResult('[id$="-users-update-result"]', '', false);
		lookupUser($('[id$="-users-update-lookup"]').val(), function(user) {
			updateTargetUserId = parseInt(user.id, 10) || 0;
			updateDetectedLabel = userLabel(user);
			$('[id$="-users-update-email"]').val(String(user.email || ''));
			$('[id$="-users-update-username"]').val(String(user.username || ''));
			$('[id$="-users-update-display-name"]').val(String(user.display_name || ''));
			$('[id$="-users-update-role"]').val(String(user.role_id || ''));
			$('[id$="-users-update-status"]').val(String(user.status || 'active').toLowerCase());
			$('[id$="-users-update-reset-password"]').val('no');
			setUpdateControlsEnabled(updateTargetUserId > 0);
			setInlineResult('[id$="-users-update-detected"]', updateDetectedLabel, false);
		}, function(message) {
			updateTargetUserId = 0;
			updateDetectedLabel = '';
			$('[id$="-users-update-reset-password"]').val('no');
			setUpdateControlsEnabled(false);
			setInlineResult('[id$="-users-update-detected"]', message, true);
		});
	});

	$updateButton.on('click', function() {
		if (updateTargetUserId < 1) {
			setInlineResult('[id$="-users-update-result"]', 'Detect a user before updating.', true);
			return;
		}

		apiPost('admin_user_update', {
			user_id: updateTargetUserId,
			email: String($('[id$="-users-update-email"]').val() || '').trim(),
			username: String($('[id$="-users-update-username"]').val() || '').trim(),
			display_name: String($('[id$="-users-update-display-name"]').val() || '').trim(),
			role_id: parseInt($('[id$="-users-update-role"]').val(), 10) || 0,
			status: String($('[id$="-users-update-status"]').val() || 'active').toLowerCase(),
			reset_password: String($('[id$="-users-update-reset-password"]').val() || 'no').toLowerCase() === 'yes'
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('[id$="-users-update-result"]', 'Failed to update user.', true);
				return;
			}

			const user = response.user || {};
			const generatedPassword = String(response.generated_password || '');
			let message = 'User updated. ' + userLabel(user);
			if (generatedPassword !== '') {
				message += ' | New random password: ' + generatedPassword;
				showToast({
					type: 'success',
					title: 'Password Reset',
					message: 'New random password: ' + generatedPassword,
					showOkayButton: true,
					autoCloseMs: 0
				});
			}
			setInlineResult('[id$="-users-update-result"]', message, false);
			updateDetectedLabel = userLabel(user);
			setInlineResult('[id$="-users-update-detected"]', updateDetectedLabel, false);
			$('[id$="-users-update-reset-password"]').val('no');
			refreshUserList();
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to update user.';
			setInlineResult('[id$="-users-update-result"]', message, true);
		});
	});

	$deleteDetectButton.on('click', function() {
		setInlineResult('[id$="-users-delete-result"]', '', false);
		lookupUser($('[id$="-users-delete-lookup"]').val(), function(user) {
			deleteTargetUserId = parseInt(user.id, 10) || 0;
			deleteDetectedLabel = userLabel(user);
			if (currentUserId > 0 && deleteTargetUserId === currentUserId) {
				$deleteButton.prop('disabled', true);
				setInlineResult('[id$="-users-delete-detected"]', deleteDetectedLabel, false);
				setInlineResult('[id$="-users-delete-result"]', 'You cannot force delete your own account.', true);
				return;
			}
			$deleteButton.prop('disabled', deleteTargetUserId < 1);
			setInlineResult('[id$="-users-delete-detected"]', deleteDetectedLabel, false);
		}, function(message) {
			deleteTargetUserId = 0;
			deleteDetectedLabel = '';
			$deleteButton.prop('disabled', true);
			setInlineResult('[id$="-users-delete-detected"]', message, true);
		});
	});

	$deleteButton.on('click', function() {
		if (deleteTargetUserId < 1) {
			setInlineResult('[id$="-users-delete-result"]', 'Detect a user before deleting.', true);
			return;
		}
		if (currentUserId > 0 && deleteTargetUserId === currentUserId) {
			$deleteButton.prop('disabled', true);
			setInlineResult('[id$="-users-delete-result"]', 'You cannot force delete your own account.', true);
			return;
		}

		showToast({
			type: 'warning',
			title: 'Force Delete User?',
			message: (deleteDetectedLabel !== '' ? deleteDetectedLabel + '. ' : '') + 'This permanently removes this user. This cannot be undone.',
			autoCloseMs: 0,
			buttons: [
				{ label: 'Cancel', className: 'btn-secondary' },
				{
					label: 'Force Delete',
					className: 'btn-danger',
					onClick: function() {
						apiPost('admin_user_force_delete', { user_id: deleteTargetUserId })
							.done(function(response) {
								if (!response || !response.success) {
									setInlineResult('[id$="-users-delete-result"]', 'Failed to force delete user.', true);
									return;
								}

								const deleted = response.deleted_user || {};
								setInlineResult(
									'[id$="-users-delete-result"]',
									'Force deleted user #' + String(deleted.id || deleteTargetUserId) + ' (' + String(deleted.username || '') + ')',
									false
								);
								setInlineResult('[id$="-users-delete-detected"]', '', false);
								deleteTargetUserId = 0;
								deleteDetectedLabel = '';
								$deleteButton.prop('disabled', true);
								$('[id$="-users-delete-lookup"]').val('');
								refreshUserList();
							}
							)
							.fail(function(xhr) {
								const message = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: 'Failed to force delete user.';
								setInlineResult('[id$="-users-delete-result"]', message, true);
							});
					}
				}
			]
		});
	});

	$widgetResetDetectButton.on('click', function() {
		setInlineResult('[id$="-users-widget-reset-result"]', '', false);
		lookupUser($('[id$="-users-widget-reset-lookup"]').val(), function(user) {
			widgetResetTargetUserId = parseInt(user.id, 10) || 0;
			widgetResetDetectedLabel = userLabel(user);
			$widgetResetButton.prop('disabled', widgetResetTargetUserId < 1);
			setInlineResult('[id$="-users-widget-reset-detected"]', widgetResetDetectedLabel, false);
		}, function(message) {
			widgetResetTargetUserId = 0;
			widgetResetDetectedLabel = '';
			$widgetResetButton.prop('disabled', true);
			setInlineResult('[id$="-users-widget-reset-detected"]', message, true);
		});
	});

	$widgetResetButton.on('click', function() {
		if (widgetResetTargetUserId < 1) {
			setInlineResult('[id$="-users-widget-reset-result"]', 'Detect a user before resetting widget preferences.', true);
			return;
		}

		showToast({
			type: 'warning',
			title: 'Reset Widget Preferences?',
			message: (widgetResetDetectedLabel !== '' ? widgetResetDetectedLabel + '. ' : '') + 'This resets this user to default widget visibility.',
			autoCloseMs: 0,
			buttons: [
				{ label: 'Cancel', className: 'btn-secondary' },
				{
					label: 'Reset',
					className: 'btn-danger',
					onClick: function() {
						apiPost('admin_user_reset_widget_prefs', { user_id: widgetResetTargetUserId })
							.done(function(response) {
								if (!response || !response.success) {
									setInlineResult('[id$="-users-widget-reset-result"]', 'Failed to reset widget preferences.', true);
									return;
								}

								setInlineResult('[id$="-users-widget-reset-result"]', 'Widget preferences reset. ' + widgetResetDetectedLabel, false);
							}
							)
							.fail(function(xhr) {
								const message = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: 'Failed to reset widget preferences.';
								setInlineResult('[id$="-users-widget-reset-result"]', message, true);
							});
					}
				}
			]
		});
	});
}


function setupAdminRoleManagementHandlers() {
	const $createButton = $('[id$="-roles-create-btn"]');
	const $updateDetectButton = $('[id$="-roles-update-detect-btn"]');
	const $updateButton = $('[id$="-roles-update-btn"]');
	const $deleteSelect = $('[id$="-roles-delete-role"]');
	const $deleteButton = $('[id$="-roles-delete-btn"]');

	if ($createButton.length === 0 && $updateDetectButton.length === 0 && $deleteSelect.length === 0) {
		return;
	}

	let currentRoles = Array.isArray(window.ADMIN_ROLE_MANAGEMENT_ROLES)
		? window.ADMIN_ROLE_MANAGEMENT_ROLES.slice()
		: [];
	let updateTargetRoleId = 0;
	let deleteTargetRoleId = parseInt($deleteSelect.val(), 10) || 0;
	let updateDetectedLabel = '';
	let deleteDetectedLabel = '';

	const setInlineResult = function(selector, message, isError) {
		const $target = $(selector);
		if ($target.length === 0) {
			return;
		}

		$target.removeClass('is-error is-success');
		if (message && String(message).trim() !== '') {
			$target.text(String(message));
			$target.addClass(isError ? 'is-error' : 'is-success');
			$target.removeAttr('hidden');
		} else {
			$target.text('');
			$target.attr('hidden', 'hidden');
		}
	};

	const apiPost = function(action, payload) {
		const requestPayload = $.extend({ action: action }, payload || {});
		return $.ajax({
			url: '../api.php',
			type: 'POST',
			contentType: 'application/json',
			dataType: 'json',
			data: JSON.stringify(requestPayload)
		});
	};

	const normalizeRole = function(role) {
		if (!role || typeof role !== 'object') {
			return null;
		}

		const id = parseInt(role.id, 10) || 0;
		if (id < 1) {
			return null;
		}

		return {
			id: id,
			name: String(role.name || ''),
			description: String(role.description || '')
		};
	};

	const roleLabel = function(role) {
		if (!role || typeof role !== 'object') {
			return '';
		}
		return String(role.id || '') + ': ' + String(role.name || '');
	};

	const roleLookupLabel = function(role) {
		if (!role || typeof role !== 'object') {
			return '';
		}
		return 'Role #' + String(role.id || '') + ' (' + String(role.name || '') + ')';
	};

	const userLabel = function(user) {
		if (!user || typeof user !== 'object') {
			return '';
		}

		const id = String(user.id || '');
		const displayName = String(user.display_name || '').trim();
		const username = String(user.username || '').trim();
		const email = String(user.email || '').trim();
		let label = '#' + id;
		if (displayName !== '') {
			label += ' ' + displayName;
		} else if (username !== '') {
			label += ' ' + username;
		} else if (email !== '') {
			label += ' ' + email;
		}
		return label;
	};

	const getRoleByIdFromState = function(roleId) {
		const targetId = parseInt(roleId, 10) || 0;
		if (targetId < 1) {
			return null;
		}
		for (let index = 0; index < currentRoles.length; index += 1) {
			if (parseInt(currentRoles[index].id, 10) === targetId) {
				return currentRoles[index];
			}
		}
		return null;
	};

	const getReassignmentRolePreview = function(roleId) {
		const targetId = parseInt(roleId, 10) || 0;
		if (targetId < 1) {
			return null;
		}
		const lowerRoles = currentRoles.filter(function(role) {
			return parseInt(role.id, 10) < targetId;
		}).sort(function(left, right) {
			return parseInt(right.id, 10) - parseInt(left.id, 10);
		});
		if (lowerRoles.length > 0) {
			return lowerRoles[0];
		}
		const higherRoles = currentRoles.filter(function(role) {
			return parseInt(role.id, 10) > targetId;
		}).sort(function(left, right) {
			return parseInt(left.id, 10) - parseInt(right.id, 10);
		});
		return higherRoles.length > 0 ? higherRoles[0] : null;
	};

	const renderRoleTable = function() {
		const $tableBody = $('#admin-role-table-body');
		if ($tableBody.length === 0) {
			return;
		}

		$tableBody.empty();
		if (!Array.isArray(currentRoles) || currentRoles.length === 0) {
			const emptyRow = document.createElement('tr');
			emptyRow.className = 'admin-role-empty-row';
			const emptyCell = document.createElement('td');
			emptyCell.colSpan = 3;
			emptyCell.textContent = 'No roles found.';
			emptyRow.appendChild(emptyCell);
			$tableBody.append(emptyRow);
			return;
		}

		currentRoles.forEach(function(role) {
			const row = document.createElement('tr');
			const idCell = document.createElement('td');
			const nameCell = document.createElement('td');
			const descriptionCell = document.createElement('td');
			idCell.textContent = String(role.id || '');
			nameCell.textContent = String(role.name || '');
			descriptionCell.textContent = String(role.description || '');
			row.appendChild(idCell);
			row.appendChild(nameCell);
			row.appendChild(descriptionCell);
			$tableBody.append(row);
		});
	};

	const populateRoleSelect = function($select, placeholderText, labelFormatter, selectedValue) {
		if (!$select || $select.length === 0) {
			return;
		}

		const preservedValue = selectedValue !== undefined && selectedValue !== null && String(selectedValue) !== ''
			? String(selectedValue)
			: String($select.val() || '');
		$select.empty();

		const placeholderOption = document.createElement('option');
		placeholderOption.value = '';
		placeholderOption.disabled = true;
		placeholderOption.selected = true;
		placeholderOption.textContent = placeholderText;
		$select.append(placeholderOption);

		currentRoles.forEach(function(role) {
			const option = document.createElement('option');
			option.value = String(role.id || '');
			option.textContent = labelFormatter(role);
			$select.append(option);
		});

		const hasSelectedValue = currentRoles.some(function(role) {
			return String(role.id || '') === preservedValue;
		});
		$select.val(hasSelectedValue ? preservedValue : '');
	};

	const renderRoleSelects = function() {
		populateRoleSelect($('[id$="-users-create-role"]'), 'Select role', function(role) {
			return String(role.name || '');
		});
		populateRoleSelect($('[id$="-users-update-role"]'), 'Select role', function(role) {
			return String(role.name || '');
		}, $('[id$="-users-update-role"]').val());
		populateRoleSelect($('[id$="-roles-delete-role"]'), 'Select role to delete', roleLabel, deleteTargetRoleId > 0 ? String(deleteTargetRoleId) : '');
	};

	const setRoleUpdateControlsEnabled = function(enabled) {
		const canEdit = !!enabled;
		const $updateFields = $('[id$="-roles-update-fields"]');
		const $updateActions = $('[id$="-roles-update-actions"]');
		if ($updateFields.length > 0) {
			if (canEdit) {
				$updateFields.removeAttr('hidden');
			} else {
				$updateFields.attr('hidden', 'hidden');
			}
		}
		if ($updateActions.length > 0) {
			if (canEdit) {
				$updateActions.removeAttr('hidden');
			} else {
				$updateActions.attr('hidden', 'hidden');
			}
		}
		$('[id$="-roles-update-name"]').prop('disabled', !canEdit);
		$('[id$="-roles-update-description"]').prop('disabled', !canEdit);
		$updateButton.prop('disabled', !canEdit);
	};

	const setRoleDeleteDetails = function(affectedUsers, replacementRole) {
		const detailsEl = document.querySelector('[id$="-roles-delete-details"]');
		if (!detailsEl) {
			return;
		}

		detailsEl.innerHTML = '';
		const users = Array.isArray(affectedUsers) ? affectedUsers : [];
		if (users.length === 0) {
			detailsEl.hidden = true;
			return;
		}

		const title = document.createElement('p');
		title.className = 'role-delete-details-title';
		title.textContent = 'Affected users';
		detailsEl.appendChild(title);

		const list = document.createElement('ul');
		list.className = 'role-delete-user-list';
		users.forEach(function(user) {
			const listItem = document.createElement('li');
			listItem.textContent = userLabel(user) + ' -> ' + roleLabel(replacementRole);
			list.appendChild(listItem);
		});
		detailsEl.appendChild(list);
		detailsEl.hidden = false;
	};

	const setCurrentRoles = function(roles) {
		currentRoles = Array.isArray(roles)
			? roles.map(normalizeRole).filter(function(role) {
				return role !== null;
			}).sort(function(left, right) {
				return left.id - right.id;
			})
			: [];
		window.ADMIN_ROLE_MANAGEMENT_ROLES = currentRoles.slice();
		renderRoleTable();
		renderRoleSelects();
		if (updateTargetRoleId > 0 && getRoleByIdFromState(updateTargetRoleId) === null) {
			updateTargetRoleId = 0;
			updateDetectedLabel = '';
			setRoleUpdateControlsEnabled(false);
			setInlineResult('[id$="-roles-update-detected"]', '', false);
			setInlineResult('[id$="-roles-update-result"]', '', false);
			$('[id$="-roles-update-name"]').val('');
			$('[id$="-roles-update-description"]').val('');
		}
		if (deleteTargetRoleId > 0 && getRoleByIdFromState(deleteTargetRoleId) === null) {
			deleteTargetRoleId = 0;
			deleteDetectedLabel = '';
			$deleteButton.prop('disabled', true);
			setInlineResult('[id$="-roles-delete-result"]', '', false);
			setRoleDeleteDetails([], null);
		}
	};

	const lookupRole = function(lookupValue, onSuccess, onError) {
		const lookup = String(lookupValue || '').trim();
		if (lookup === '') {
			onError('Enter a role id or name first.');
			return;
		}

		apiPost('admin_role_lookup', { lookup: lookup })
			.done(function(response) {
				if (response && response.success && response.role) {
					onSuccess(response.role);
					return;
				}
				onError('Role not found.');
			})
			.fail(function(xhr) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.message
					? xhr.responseJSON.message
					: 'Role lookup failed.';
				onError(message);
			});
	};

	setCurrentRoles(currentRoles);
	setRoleUpdateControlsEnabled(false);
	$deleteButton.prop('disabled', deleteTargetRoleId < 1);

	$('[id$="-roles-create-name"]').on('input', function() {
		setInlineResult('[id$="-roles-create-result"]', '', false);
	});
	$('[id$="-roles-create-description"]').on('input', function() {
		setInlineResult('[id$="-roles-create-result"]', '', false);
	});

	$('[id$="-roles-update-lookup"]').on('input', function() {
		updateTargetRoleId = 0;
		updateDetectedLabel = '';
		setRoleUpdateControlsEnabled(false);
		setInlineResult('[id$="-roles-update-detected"]', '', false);
		setInlineResult('[id$="-roles-update-result"]', '', false);
		$('[id$="-roles-update-name"]').val('');
		$('[id$="-roles-update-description"]').val('');
	});

	$deleteSelect.on('change', function() {
		deleteTargetRoleId = parseInt($(this).val(), 10) || 0;
		deleteDetectedLabel = deleteTargetRoleId > 0 ? roleLookupLabel(getRoleByIdFromState(deleteTargetRoleId)) : '';
		$deleteButton.prop('disabled', deleteTargetRoleId < 1);
		setInlineResult('[id$="-roles-delete-detected"]', deleteDetectedLabel, false);
		setInlineResult('[id$="-roles-delete-result"]', '', false);
		setRoleDeleteDetails([], null);
	});

	$createButton.on('click', function() {
		setInlineResult('[id$="-roles-create-result"]', '', false);
		const name = String($('[id$="-roles-create-name"]').val() || '').trim();
		const description = String($('[id$="-roles-create-description"]').val() || '').trim();
		if (name === '') {
			setInlineResult('[id$="-roles-create-result"]', 'Enter a role name before creating a role.', true);
			return;
		}

		apiPost('admin_role_create', {
			name: name,
			description: description
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('[id$="-roles-create-result"]', 'Failed to create role.', true);
				return;
			}

			setCurrentRoles(response.roles || []);
			$('[id$="-roles-create-name"]').val('');
			$('[id$="-roles-create-description"]').val('');
			const createdRole = response.role || {};
			const message = 'Role created. ' + roleLookupLabel(createdRole);
			setInlineResult('[id$="-roles-create-result"]', message, false);
			showToast({
				type: 'success',
				title: 'Role Created',
				message: message,
				showOkayButton: true,
				autoCloseMs: 0
			});
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to create role.';
			setInlineResult('[id$="-roles-create-result"]', message, true);
		});
	});

	$updateDetectButton.on('click', function() {
		setInlineResult('[id$="-roles-update-result"]', '', false);
		lookupRole($('[id$="-roles-update-lookup"]').val(), function(role) {
			updateTargetRoleId = parseInt(role.id, 10) || 0;
			updateDetectedLabel = roleLookupLabel(role);
			$('[id$="-roles-update-name"]').val(String(role.name || ''));
			$('[id$="-roles-update-description"]').val(String(role.description || ''));
			setRoleUpdateControlsEnabled(updateTargetRoleId > 0);
			setInlineResult('[id$="-roles-update-detected"]', updateDetectedLabel, false);
		}, function(message) {
			updateTargetRoleId = 0;
			updateDetectedLabel = '';
			setRoleUpdateControlsEnabled(false);
			setInlineResult('[id$="-roles-update-detected"]', message, true);
		});
	});

	$updateButton.on('click', function() {
		if (updateTargetRoleId < 1) {
			setInlineResult('[id$="-roles-update-result"]', 'Detect a role before updating.', true);
			return;
		}

		apiPost('admin_role_update', {
			role_id: updateTargetRoleId,
			name: String($('[id$="-roles-update-name"]').val() || '').trim(),
			description: String($('[id$="-roles-update-description"]').val() || '').trim()
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('[id$="-roles-update-result"]', 'Failed to update role.', true);
				return;
			}

			setCurrentRoles(response.roles || []);
			const role = response.role || {};
			updateDetectedLabel = roleLookupLabel(role);
			setInlineResult('[id$="-roles-update-detected"]', updateDetectedLabel, false);
			setInlineResult('[id$="-roles-update-result"]', 'Role updated. ' + updateDetectedLabel, false);
			showToast({
				type: 'success',
				title: 'Role Updated',
				message: 'Role updated: ' + updateDetectedLabel,
				showOkayButton: true,
				autoCloseMs: 0
			});
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to update role.';
			setInlineResult('[id$="-roles-update-result"]', message, true);
		});
	});

	$deleteButton.on('click', function() {
		if (deleteTargetRoleId < 1) {
			setInlineResult('[id$="-roles-delete-result"]', 'Select a role before deleting.', true);
			return;
		}

		const selectedRole = getRoleByIdFromState(deleteTargetRoleId);
		const previewRole = getReassignmentRolePreview(deleteTargetRoleId);
		const deleteMessage = deleteDetectedLabel !== ''
			? deleteDetectedLabel + '. '
			: (selectedRole ? roleLookupLabel(selectedRole) + '. ' : '');
		showToast({
			type: 'warning',
			title: 'Delete Role?',
			message: deleteMessage + (previewRole ? ('Affected users will be reassigned to ' + roleLookupLabel(previewRole) + '.') : 'Affected users will be reassigned only if a replacement role exists.'),
			autoCloseMs: 0,
			buttons: [
				{ label: 'Cancel', className: 'btn-secondary' },
				{
					label: 'Delete Role',
					className: 'btn-danger',
					onClick: function() {
						apiPost('admin_role_delete', { role_id: deleteTargetRoleId })
							.done(function(response) {
								if (!response || !response.success) {
									setInlineResult('[id$="-roles-delete-result"]', 'Failed to delete role.', true);
									return;
								}

								setCurrentRoles(response.roles || []);
								const deletedRole = response.deleted_role || selectedRole || {};
								const replacementRole = response.replacement_role || previewRole || null;
								const affectedUsers = Array.isArray(response.affected_users) ? response.affected_users : [];
								const summaryMessage = 'Deleted role #' + String(deletedRole.id || deleteTargetRoleId) + ' (' + String(deletedRole.name || '') + ')';
								const reassignmentMessage = affectedUsers.length > 0 && replacementRole
									? (' ' + String(affectedUsers.length) + ' user(s) reassigned to ' + roleLookupLabel(replacementRole) + '.')
									: ' No users were affected.';
								setInlineResult('[id$="-roles-delete-result"]', summaryMessage + reassignmentMessage, false);
								setRoleDeleteDetails(affectedUsers, replacementRole);
								setInlineResult('[id$="-roles-delete-detected"]', '', false);
								deleteTargetRoleId = 0;
								deleteDetectedLabel = '';
								$deleteSelect.val('');
								$deleteButton.prop('disabled', true);
								showToast({
									type: 'success',
									title: 'Role Deleted',
									message: summaryMessage,
									showOkayButton: true,
									autoCloseMs: 0
								});
							})
							.fail(function(xhr) {
								const message = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: 'Failed to delete role.';
								setInlineResult('[id$="-roles-delete-result"]', message, true);
							});
					}
				}
			]
		});
	});
}


function setupDevToolsCategorySelector() {
	const $selector = $('#dev-tools-system-selector');
	if ($selector.length === 0) {
		return;
	}

	const $modules = $('.dev-tool-module');
	if ($modules.length === 0) {
		return;
	}

	const devToolsCategoryStorageKey = 'dev-tools-selected-category';
	const allowedCategories = $selector.find('option').map(function() {
		return String($(this).val() || '').toLowerCase();
	}).get();

	const normalizeCategory = function(value) {
		const normalized = String(value || '').toLowerCase();
		return allowedCategories.indexOf(normalized) !== -1 ? normalized : '';
	};

	const applyCategory = function(category) {
		const normalizedCategory = String(category || '').toLowerCase();
		$modules.each(function() {
			const $module = $(this);
			const moduleCategory = String($module.attr('data-dev-tool-category') || '').toLowerCase();
			const shouldShow = moduleCategory === normalizedCategory;

			if (shouldShow) {
				$module.removeAttr('hidden').removeClass('hidden');
			} else {
				$module.attr('hidden', 'hidden').addClass('hidden');
			}
		});
	};

	$selector.on('change', function() {
		const selectedCategory = normalizeCategory($(this).val());
		if (selectedCategory !== '') {
			localStorage.setItem(devToolsCategoryStorageKey, selectedCategory);
			applyCategory(selectedCategory);
		}
	});

	const storedCategory = normalizeCategory(localStorage.getItem(devToolsCategoryStorageKey));
	const selectedCategory = normalizeCategory($selector.val());
	const initialCategory = storedCategory !== ''
		? storedCategory
		: (selectedCategory !== '' ? selectedCategory : 'system');
	if (initialCategory !== '') {
		$selector.val(initialCategory);
		localStorage.setItem(devToolsCategoryStorageKey, initialCategory);
		applyCategory(initialCategory);
	}
}


function loadData(callback) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
		data: { action: 'data' },
		success: function(data) {
			allData = data;
			refreshNextRecordId();
			if ($('#data-container').length > 0 && typeof displayDataTable === 'function') {
				displayDataTable(data);
			}
			if (callback && typeof callback === 'function') {
				callback();
			}
		},
		error: function(error) {
			if ($('#data-container').length > 0) {
				$('#data-container').html('<p class="error">Error loading data. Please refresh the page.</p>');
			}
		}
	});
}


function loadLogs(callback) {
	$.ajax({
		url: '../api.php?action=logs',
		type: 'GET',
		dataType: 'json',
		success: function(data) {
			if (data && typeof data === 'object') {
				allLogs = data.logs ? data : (data.entries ? { logs: data.entries } : { logs: [] });
			} else {
				allLogs = { logs: [] };
			}
			if (callback && typeof callback === 'function') {
				callback();
			}
		},
		error: function() {
			allLogs = { logs: [] };
		}
	});
}


function convertToLocalTime(timeString, dateString) {
	try {
		const serverDate = new Date(`${dateString}T${timeString}`);
		const localDate = new Date(serverDate.getTime());
		const adjustedHours = String(localDate.getHours()).padStart(2, '0');
		const adjustedMinutes = String(localDate.getMinutes()).padStart(2, '0');
		const adjustedSeconds = String(localDate.getSeconds()).padStart(2, '0');
		return `${adjustedHours}:${adjustedMinutes}:${adjustedSeconds}`;
	} catch (e) {
		return timeString;
	}
}


function refreshNextRecordId() {
	const currentItems = Array.isArray(allData && allData.items) ? allData.items : [];
	nextRecordId = currentItems.reduce(function(maxId, item) {
		const parsed = parseInt(item.id, 10);
		if (!Number.isNaN(parsed) && parsed > maxId) {
			return parsed;
		}
		return maxId;
	}, 0) + 1;
}


function logEvent(eventMessage) {
	$.ajax({
		url: '../api.php?action=report_' + (eventMessage.includes('downloaded') ? 'downloaded' : 'generated'),
		type: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			dataset: eventMessage.includes('Data') ? 'Data' : 'Logs',
			event: eventMessage
		}),
		success: function() {
			if (typeof loadLogs === 'function') {
				loadLogs();
			}
		}
	});
}
