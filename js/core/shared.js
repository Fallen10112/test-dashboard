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
								showToast({
									type: 'error',
									title: 'Reset Failed',
									message: 'Error resetting data: ' + error,
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
