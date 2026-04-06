let allData = null;
let allLogs = null;
let allAuditTrail = null;
let currentSortColumn = null;
let currentSortOrder = 'asc';
let selectedRecordIds = new Set();
let currentAuditView = 'table';
let currentFilteredDataItems = [];
let nextRecordId = 1;
let currentPage = 1;
let pageSize = 25;
let currentPagedItems = [];
const virtualRowHeightPx = 52;
const virtualOverscanRows = 6;
const dataPageSizeStorageKey = 'data-page-size';
let isHandlingForcedLogout = false;
let sessionPollTimerId = null;
let sessionPollFnRef = null;
let sessionVisibilityHandlerRef = null;


let headerNotifications = [];
function setupUserAvatarDropdown() {
	const btn = document.getElementById('user-avatar-btn');
	const dropdown = document.getElementById('user-dropdown');
	if (!btn || !dropdown) { return; }

	btn.addEventListener('click', function(e) {
		e.stopPropagation();
		const isOpen = !dropdown.hidden;
		dropdown.hidden = isOpen;
		btn.setAttribute('aria-expanded', String(!isOpen));
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
	const apiKey = String(window.DASHBOARD_API_KEY || '').trim();
	if (apiKey === '') {
		return;
	}

	$.ajaxSetup({
		beforeSend: function(xhr, settings) {
			if (settings && typeof settings.url === 'string' && settings.url.indexOf('api.php') !== -1) {
				xhr.setRequestHeader('X-API-Key', apiKey);
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
		return;
	}

	headerNotifications.forEach(function(notification) {
		const itemEl = document.createElement('div');
		itemEl.className = 'notification-item';

		const titleEl = document.createElement('div');
		titleEl.className = 'notification-item-title';
		titleEl.textContent = String(notification.title || 'Notification');

		const messageEl = document.createElement('div');
		messageEl.className = 'notification-item-message';
		messageEl.textContent = String(notification.message || '');

		itemEl.appendChild(titleEl);
		itemEl.appendChild(messageEl);
		listEl.appendChild(itemEl);
	});

	const count = headerNotifications.length;
	countEl.textContent = String(count > 99 ? '99+' : count);
	countEl.hidden = count === 0;
}


function setHeaderNotifications(notifications) {
	if (!Array.isArray(notifications)) {
		headerNotifications = [];
		renderHeaderNotifications();
		return;
	}

	headerNotifications = notifications
		.filter(function(item) {
			return item && (item.title || item.message);
		})
		.map(function(item) {
			return {
				title: String(item.title || 'Notification'),
				message: String(item.message || '')
			};
		});

	renderHeaderNotifications();
}


function setupNotificationDropdown() {
	const btn = document.getElementById('notifications-btn');
	const dropdown = document.getElementById('notifications-dropdown');
	if (!btn || !dropdown) {
		return;
	}

	renderHeaderNotifications();

	btn.addEventListener('click', function(e) {
		e.stopPropagation();
		const isOpen = !dropdown.hidden;
		dropdown.hidden = isOpen;
		btn.setAttribute('aria-expanded', String(!isOpen));
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

	window.DashboardNotifications = {
		set: function(items) {
			setHeaderNotifications(items);
		},
		add: function(item) {
			if (!item || (!item.title && !item.message)) {
				return;
			}
			headerNotifications.unshift({
				title: String(item.title || 'Notification'),
				message: String(item.message || '')
			});
			renderHeaderNotifications();
		},
		clear: function() {
			headerNotifications = [];
			renderHeaderNotifications();
		}
	};
}


function setupSessionEnforcementPoller() {
	if (sessionPollTimerId !== null) {
		clearInterval(sessionPollTimerId);
		sessionPollTimerId = null;
	}

	const apiKey = String(window.DASHBOARD_API_KEY || '').trim();
	if (apiKey === '') {
		return;
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

		const fieldName = String(entry.field_name || '').toLowerCase();
		const recordId = String(entry.record_id || '');

		if (normalizedType === 'ADD' && fieldName !== 'title') {
			return;
		}

		if (normalizedType === 'DELETE') {
			if (fieldName !== 'title' && fieldName !== 'record') {
				return;
			}
			if (recordId.toUpperCase() === 'BULK') {
				return;
			}
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


function setupResetButtonHandler() {
	if ($('#reset-data-btn').length === 0) {
		return;
	}

	$('#reset-data-btn').on('click', function() {
		showToast({
			type: 'warning',
			title: 'Reset Dashboard Data?',
			message: 'This will clear all entries, logs, and audit trail, then restore 3 sample entries.',
			autoCloseMs: 0,
			buttons: [
				{
					label: 'Cancel',
					className: 'btn-secondary'
				},
				{
					label: 'Reset Data',
					className: 'btn-danger',
					onClick: function() {
						$.ajax({
							url: '../api.php',
							type: 'POST',
							contentType: 'application/json',
							data: JSON.stringify({ action: 'reset_data' }),
							success: function() {
								showToast({
									type: 'success',
									title: 'Data Reset Complete',
									message: 'Your dashboard has been reset to the sample test state.',
									showOkayButton: true,
									autoCloseMs: 3000,
									onClose: function() {
										const currentPageName = window.location.pathname.split('/').pop() || 'home.php';
										window.location.href = '../pages/' + currentPageName;
									}
								});
							},
							error: function(xhr, status, error) {
								const responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
									? xhr.responseJSON.message
									: '';
								const fallbackMessage = (xhr && xhr.status === 403)
									? 'Reset is only available when APP_MODE is demo.'
									: ('Error resetting data: ' + error);
								showToast({
									type: 'error',
									title: 'Reset Failed',
									message: responseMessage || fallbackMessage,
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
}


function loadData(callback) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
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
