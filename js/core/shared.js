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

	let visibleCount = 0;
	SUPPORTED_HEADER_WIDGET_KEYS.forEach(function(widgetKey) {
		const el = container.querySelector('[data-widget-key="' + widgetKey + '"]');
		if (!el) {
			return;
		}

		const isVisible = normalized[widgetKey] === true;
		el.hidden = !isVisible;
		if (isVisible) {
			visibleCount += 1;
		}
	});

	container.hidden = visibleCount === 0;
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

		// Make notification item clickable to show full details
		itemEl.style.cursor = 'pointer';
		itemEl.addEventListener('click', function(e) {
			// Don't trigger on button clicks
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

	// Add meta information
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

	// Mark as read button (if not already read)
	if (!settings.isRead && settings.id > 0) {
		const $markReadBtn = $('<button type="button" class="btn btn-sm btn-secondary">Mark as Read</button>');
		$markReadBtn.on('click', function() {
			closeToast($toast);
			markHeaderNotificationRead(settings.id);
		});
		$actions.append($markReadBtn);
	}

	// Delete button
	if (settings.id > 0) {
		const $deleteBtn = $('<button type="button" class="btn btn-sm btn-danger">Delete</button>');
		$deleteBtn.on('click', function() {
			closeToast($toast);
			deleteHeaderNotification(settings.id);
		});
		$actions.append($deleteBtn);
	}

	// Close button
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
	
	// Validate title length
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
	$.when(
		$.ajax({
			url: '../api.php',
			type: 'GET',
			dataType: 'json'
		}),
		$.ajax({
			url: '../api.php?action=audit_trail',
			type: 'GET',
			dataType: 'json'
		})
	).done(function(dataResponse, auditResponse) {
		const dataPayload = dataResponse[0] || {};
		const auditPayload = auditResponse[0] || {};
		const auditEntries = Array.isArray(auditPayload.entries) ? auditPayload.entries : [];

		const totalEntries = Array.isArray(dataPayload.items) ? dataPayload.items.length : 0;
		const totalEdits = auditEntries.length > 0
			? auditEntries.filter(function(entry) {
				return String(entry.change_type || '').toUpperCase() === 'EDIT';
			}).length
			: 0;
		const addsToday = countUniqueAuditRecordsForToday(auditEntries, 'ADD');
		const deletesToday = countUniqueAuditRecordsForToday(auditEntries, 'DELETE');

		setMetricValue('#metric-total-entries', totalEntries);
		setMetricValue('#metric-total-edits', totalEdits);
		setMetricValue('#metric-adds-today', addsToday);
		setMetricValue('#metric-deletes-today', deletesToday);
	}).fail(function() {
		setMetricValue('#metric-total-entries', '--');
		setMetricValue('#metric-total-edits', '--');
		setMetricValue('#metric-adds-today', '--');
		setMetricValue('#metric-deletes-today', '--');
	});
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
	const resetActions = [
		{
			buttonId: '#reset-activity-log-btn',
			action: 'reset_activity_log',
			confirmTitle: 'Reset Activity Log?',
			confirmMessage: 'This will clear all entries from the activity_log table.',
			successTitle: 'Activity Log Reset',
			successMessage: 'The activity_log table has been reset successfully.'
		},
		{
			buttonId: '#reset-audit-log-btn',
			action: 'reset_audit_log',
			confirmTitle: 'Reset Audit Log?',
			confirmMessage: 'This will clear all entries from the audit_log table.',
			successTitle: 'Audit Log Reset',
			successMessage: 'The audit_log table has been reset successfully.'
		},
		{
			buttonId: '#reset-records-btn',
			action: 'reset_records',
			confirmTitle: 'Reset Records?',
			confirmMessage: 'This will reset the records table and restore 3 sample entries.',
			successTitle: 'Records Reset',
			successMessage: 'The records table has been reset to 3 sample entries.'
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
			buttonId: '#reset-notif-table-btn',
			action: 'reset_notifications_table',
			confirmTitle: 'Reset Notifications Table?',
			confirmMessage: 'This will clear all entries from the notifications table.',
			successTitle: 'Notifications Table Reset',
			successMessage: 'The notifications table has been reset successfully.'
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
			buttonId: '#reset-all-btn',
			action: 'reset_all',
			confirmTitle: 'Reset all?',
			confirmMessage: 'This will reset activity_log, audit_log, records (to 3 sample entries), and notifications tables.',
			successTitle: 'Reset All Complete',
			successMessage: 'All target tables were reset successfully.'
		}
	];

	const hasAnyButtons = resetActions.some(function(cfg) {
		return $(cfg.buttonId).length > 0;
	});
	if (!hasAnyButtons) {
		return;
	}

	resetActions.forEach(function(cfg) {
		const $button = $(cfg.buttonId);
		if ($button.length === 0) {
			return;
		}

		$button.on('click', function() {
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
								data: JSON.stringify({ action: cfg.action }),
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


function setupDevToolsUserManagementHandlers() {
	const $createButton = $('#dev-users-create-btn');
	const $updateDetectButton = $('#dev-users-update-detect-btn');
	const $updateButton = $('#dev-users-update-btn');
	const $deleteDetectButton = $('#dev-users-delete-detect-btn');
	const $deleteButton = $('#dev-users-delete-btn');
	const $widgetResetDetectButton = $('#dev-users-widget-reset-detect-btn');
	const $widgetResetButton = $('#dev-users-widget-reset-btn');

	if ($createButton.length === 0 && $updateDetectButton.length === 0 && $deleteDetectButton.length === 0 && $widgetResetDetectButton.length === 0) {
		return;
	}

	let updateTargetUserId = 0;
	let deleteTargetUserId = 0;
	let widgetResetTargetUserId = 0;
	let updateDetectedLabel = '';
	let deleteDetectedLabel = '';
	let widgetResetDetectedLabel = '';
	const currentUserId = parseInt($('#dev-tools-current-user-id').val(), 10) || 0;

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

	const userLabel = function(user) {
		if (!user || typeof user !== 'object') {
			return '';
		}
		const id = String(user.id || '');
		const displayName = String(user.display_name || '');
		return 'Detected user ' + id + ', ' + (displayName !== '' ? displayName : '(no display name)');
	};

	const setUpdateControlsEnabled = function(enabled) {
		const canEdit = !!enabled;
		const $updateFields = $('#dev-users-update-fields');
		const $updateActions = $('#dev-users-update-actions');
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
		$('#dev-users-update-email').prop('disabled', !canEdit);
		$('#dev-users-update-username').prop('disabled', !canEdit);
		$('#dev-users-update-display-name').prop('disabled', !canEdit);
		$('#dev-users-update-status').prop('disabled', !canEdit);
		$('#dev-users-update-reset-password').prop('disabled', !canEdit);
		$updateButton.prop('disabled', !canEdit);
	};

	setUpdateControlsEnabled(false);

	$('#dev-users-update-lookup').on('input', function() {
		updateTargetUserId = 0;
		updateDetectedLabel = '';
		setUpdateControlsEnabled(false);
		setInlineResult('#dev-users-update-detected', '', false);
	});

	$('#dev-users-delete-lookup').on('input', function() {
		deleteTargetUserId = 0;
		deleteDetectedLabel = '';
		$deleteButton.prop('disabled', true);
		setInlineResult('#dev-users-delete-detected', '', false);
	});

	$('#dev-users-widget-reset-lookup').on('input', function() {
		widgetResetTargetUserId = 0;
		widgetResetDetectedLabel = '';
		$widgetResetButton.prop('disabled', true);
		setInlineResult('#dev-users-widget-reset-detected', '', false);
		setInlineResult('#dev-users-widget-reset-result', '', false);
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
		setInlineResult('#dev-users-create-result', '', false);
		const email = String($('#dev-users-create-email').val() || '').trim();
		const username = String($('#dev-users-create-username').val() || '').trim();
		const displayName = String($('#dev-users-create-display-name').val() || '').trim();
		const status = String($('#dev-users-create-status').val() || 'active').toLowerCase();

		apiPost('admin_user_create', {
			email: email,
			username: username,
			display_name: displayName,
			status: status
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('#dev-users-create-result', 'Failed to create user.', true);
				return;
			}

			const generatedPassword = String(response.generated_password || '');
			const user = response.user || {};
			setInlineResult(
				'#dev-users-create-result',
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
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to create user.';
			setInlineResult('#dev-users-create-result', message, true);
		});
	});

	$updateDetectButton.on('click', function() {
		setInlineResult('#dev-users-update-result', '', false);
		lookupUser($('#dev-users-update-lookup').val(), function(user) {
			updateTargetUserId = parseInt(user.id, 10) || 0;
			updateDetectedLabel = userLabel(user);
			$('#dev-users-update-email').val(String(user.email || ''));
			$('#dev-users-update-username').val(String(user.username || ''));
			$('#dev-users-update-display-name').val(String(user.display_name || ''));
			$('#dev-users-update-status').val(String(user.status || 'active').toLowerCase());
			$('#dev-users-update-reset-password').val('no');
			setUpdateControlsEnabled(updateTargetUserId > 0);
			setInlineResult('#dev-users-update-detected', updateDetectedLabel, false);
		}, function(message) {
			updateTargetUserId = 0;
			updateDetectedLabel = '';
			$('#dev-users-update-reset-password').val('no');
			setUpdateControlsEnabled(false);
			setInlineResult('#dev-users-update-detected', message, true);
		});
	});

	$updateButton.on('click', function() {
		if (updateTargetUserId < 1) {
			setInlineResult('#dev-users-update-result', 'Detect a user before updating.', true);
			return;
		}

		apiPost('admin_user_update', {
			user_id: updateTargetUserId,
			email: String($('#dev-users-update-email').val() || '').trim(),
			username: String($('#dev-users-update-username').val() || '').trim(),
			display_name: String($('#dev-users-update-display-name').val() || '').trim(),
			status: String($('#dev-users-update-status').val() || 'active').toLowerCase(),
			reset_password: String($('#dev-users-update-reset-password').val() || 'no').toLowerCase() === 'yes'
		}).done(function(response) {
			if (!response || !response.success) {
				setInlineResult('#dev-users-update-result', 'Failed to update user.', true);
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
			setInlineResult('#dev-users-update-result', message, false);
			updateDetectedLabel = userLabel(user);
			setInlineResult('#dev-users-update-detected', updateDetectedLabel, false);
			$('#dev-users-update-reset-password').val('no');
		}).fail(function(xhr) {
			const message = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to update user.';
			setInlineResult('#dev-users-update-result', message, true);
		});
	});

	$deleteDetectButton.on('click', function() {
		setInlineResult('#dev-users-delete-result', '', false);
		lookupUser($('#dev-users-delete-lookup').val(), function(user) {
			deleteTargetUserId = parseInt(user.id, 10) || 0;
			deleteDetectedLabel = userLabel(user);
			if (currentUserId > 0 && deleteTargetUserId === currentUserId) {
				$deleteButton.prop('disabled', true);
				setInlineResult('#dev-users-delete-detected', deleteDetectedLabel, false);
				setInlineResult('#dev-users-delete-result', 'You cannot force delete your own account.', true);
				return;
			}
			$deleteButton.prop('disabled', deleteTargetUserId < 1);
			setInlineResult('#dev-users-delete-detected', deleteDetectedLabel, false);
		}, function(message) {
			deleteTargetUserId = 0;
			deleteDetectedLabel = '';
			$deleteButton.prop('disabled', true);
			setInlineResult('#dev-users-delete-detected', message, true);
		});
	});

	$deleteButton.on('click', function() {
		if (deleteTargetUserId < 1) {
			setInlineResult('#dev-users-delete-result', 'Detect a user before deleting.', true);
			return;
		}
		if (currentUserId > 0 && deleteTargetUserId === currentUserId) {
			$deleteButton.prop('disabled', true);
			setInlineResult('#dev-users-delete-result', 'You cannot force delete your own account.', true);
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
									setInlineResult('#dev-users-delete-result', 'Failed to force delete user.', true);
									return;
								}

								const deleted = response.deleted_user || {};
								setInlineResult(
									'#dev-users-delete-result',
									'Force deleted user #' + String(deleted.id || deleteTargetUserId) + ' (' + String(deleted.username || '') + ')',
									false
								);
								setInlineResult('#dev-users-delete-detected', '', false);
								deleteTargetUserId = 0;
								deleteDetectedLabel = '';
								$deleteButton.prop('disabled', true);
								$('#dev-users-delete-lookup').val('');
							}
							)
							.fail(function(xhr) {
								const message = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: 'Failed to force delete user.';
								setInlineResult('#dev-users-delete-result', message, true);
							});
					}
				}
			]
		});
	});

	$widgetResetDetectButton.on('click', function() {
		setInlineResult('#dev-users-widget-reset-result', '', false);
		lookupUser($('#dev-users-widget-reset-lookup').val(), function(user) {
			widgetResetTargetUserId = parseInt(user.id, 10) || 0;
			widgetResetDetectedLabel = userLabel(user);
			$widgetResetButton.prop('disabled', widgetResetTargetUserId < 1);
			setInlineResult('#dev-users-widget-reset-detected', widgetResetDetectedLabel, false);
		}, function(message) {
			widgetResetTargetUserId = 0;
			widgetResetDetectedLabel = '';
			$widgetResetButton.prop('disabled', true);
			setInlineResult('#dev-users-widget-reset-detected', message, true);
		});
	});

	$widgetResetButton.on('click', function() {
		if (widgetResetTargetUserId < 1) {
			setInlineResult('#dev-users-widget-reset-result', 'Detect a user before resetting widget preferences.', true);
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
									setInlineResult('#dev-users-widget-reset-result', 'Failed to reset widget preferences.', true);
									return;
								}

								setInlineResult('#dev-users-widget-reset-result', 'Widget preferences reset. ' + widgetResetDetectedLabel, false);
							}
							)
							.fail(function(xhr) {
								const message = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: 'Failed to reset widget preferences.';
								setInlineResult('#dev-users-widget-reset-result', message, true);
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
