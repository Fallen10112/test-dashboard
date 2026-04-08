let currentAuditView = 'table';
let auditCurrentPage = 1;
let auditPageSize = 25;
let auditRealtimePollTimerId = null;
let isAuditRealtimePollInFlight = false;
let auditLastRefreshedTimeText = '--:--:--';

function updateAuditLastRefreshedTime() {
	const now = new Date();
	const hours = String(now.getHours()).padStart(2, '0');
	const minutes = String(now.getMinutes()).padStart(2, '0');
	const seconds = String(now.getSeconds()).padStart(2, '0');
	auditLastRefreshedTimeText = hours + ':' + minutes + ':' + seconds;
}

function setupAuditTrailPageHandlers() {
	const debouncedAuditFilter = debounce(filterAuditTrail, 180);
	$('#search-input').on('keyup', function() {
		auditCurrentPage = 1;
		debouncedAuditFilter();
	});
	$('#audit-record-type-filter').on('change', function() {
		auditCurrentPage = 1;
		filterAuditTrail();
	});
	$('#audit-action-filter').on('change', function() {
		auditCurrentPage = 1;
		filterAuditTrail();
	});
	$('#audit-view-table').on('click', function() {
		setAuditView('table');
	});
	$('#audit-view-timeline').on('click', function() {
		setAuditView('timeline');
	});
	$('#audit-container').on('change', '#audit-page-size', function() {
		const requested = parseInt($(this).val(), 10);
		const allowed = [10, 20, 25, 50, 100];
		auditPageSize = !Number.isNaN(requested) && allowed.indexOf(requested) !== -1 ? requested : 25;
		auditCurrentPage = 1;
		filterAuditTrail();
	});
	$('#audit-container').on('click', '#audit-page-prev', function() {
		if (auditCurrentPage > 1) {
			auditCurrentPage -= 1;
			filterAuditTrail();
		}
	});
	$('#audit-container').on('click', '#audit-page-next', function() {
		auditCurrentPage += 1;
		filterAuditTrail();
	});
}


function setAuditView(viewMode) {
	currentAuditView = viewMode === 'timeline' ? 'timeline' : 'table';
	$('#audit-view-table').toggleClass('active', currentAuditView === 'table');
	$('#audit-view-timeline').toggleClass('active', currentAuditView === 'timeline');
	if (allAuditTrail && Array.isArray(allAuditTrail.entries)) {
		filterAuditTrail();
	}
}


function loadAuditTrail(onComplete) {
	$.ajax({
		url: '../api.php?action=audit_trail',
		type: 'GET',
		dataType: 'json',
		success: function(data) {
			allAuditTrail = data;
			updateAuditLastRefreshedTime();
			filterAuditTrail();
			if (typeof onComplete === 'function') {
				onComplete();
			}
		},
		error: function() {
			$('#audit-container').html('<p class="error">Error loading audit trail. Please refresh the page.</p>');
			if (typeof onComplete === 'function') {
				onComplete();
			}
		}
	});
}


function startAuditTrailRealtimeSync() {
	if (auditRealtimePollTimerId !== null) {
		clearInterval(auditRealtimePollTimerId);
		auditRealtimePollTimerId = null;
	}

	const poll = function() {
		if (document.visibilityState === 'hidden') {
			return;
		}
		if (isAuditRealtimePollInFlight) {
			return;
		}

		isAuditRealtimePollInFlight = true;
		loadAuditTrail(function() {
			if (typeof loadHeaderMetrics === 'function') {
				loadHeaderMetrics();
			}
			isAuditRealtimePollInFlight = false;
		});
	};

	auditRealtimePollTimerId = setInterval(poll, 10000);

	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'visible') {
			poll();
		}
	});
}


function renderAuditTrail(entries, emptyMessage) {
	const container = $('#audit-container');
	container.empty();
	if (!Array.isArray(entries) || entries.length === 0) {
		container.html('<p>' + emptyMessage + '</p>');
		return;
	}
	const totalEntries = entries.length;
	const totalPages = Math.max(1, Math.ceil(totalEntries / auditPageSize));
	if (auditCurrentPage > totalPages) {
		auditCurrentPage = totalPages;
	}
	const pageStart = (auditCurrentPage - 1) * auditPageSize;
	const pagedEntries = entries.slice(pageStart, pageStart + auditPageSize);
	if (currentAuditView === 'timeline') {
		renderAuditTimeline(pagedEntries);
	} else {
		renderAuditTable(pagedEntries);
	}

	const pageEnd = pageStart + pagedEntries.length;
	let paginationHTML = '<div class="data-pagination">';
	paginationHTML += '<span class="data-pagination-summary">Showing ' + (pageStart + 1) + '-' + pageEnd + ' of ' + totalEntries + '</span>';
	paginationHTML += '<div class="data-pagination-controls-wrap">';
	paginationHTML += '<div class="data-pagination-controls">';
	paginationHTML += '<div class="data-page-size-group">';
	paginationHTML += '<label class="data-page-size-label" for="audit-page-size">Rows per page</label>';
	paginationHTML += '<select id="audit-page-size" class="data-page-size-select">';
	paginationHTML += '<option value="10"' + (auditPageSize === 10 ? ' selected' : '') + '>10</option>';
	paginationHTML += '<option value="20"' + (auditPageSize === 20 ? ' selected' : '') + '>20</option>';
	paginationHTML += '<option value="25"' + (auditPageSize === 25 ? ' selected' : '') + '>25</option>';
	paginationHTML += '<option value="50"' + (auditPageSize === 50 ? ' selected' : '') + '>50</option>';
	paginationHTML += '<option value="100"' + (auditPageSize === 100 ? ' selected' : '') + '>100</option>';
	paginationHTML += '</select>';
	paginationHTML += '</div>';
	paginationHTML += '<button type="button" id="audit-page-prev" class="btn btn-sm btn-secondary"' + (auditCurrentPage <= 1 ? ' disabled' : '') + '>Previous</button>';
	paginationHTML += '<span class="data-page-label">Page ' + auditCurrentPage + ' of ' + totalPages + '</span>';
	paginationHTML += '<button type="button" id="audit-page-next" class="btn btn-sm btn-secondary"' + (auditCurrentPage >= totalPages ? ' disabled' : '') + '>Next</button>';
	paginationHTML += '</div>';
	paginationHTML += '<div class="audit-refresh-status">Last refreshed ' + auditLastRefreshedTimeText + '</div>';
	paginationHTML += '</div>';
	paginationHTML += '</div>';

	container.append(paginationHTML);
}


function renderAuditTable(entries) {
	const container = $('#audit-container');
	let tableHTML = '<table class="data-table audit-table-wide"><thead><tr><th>ID</th><th>Date</th><th>Time</th><th>Type</th><th>Action</th><th>Source</th><th>IP</th><th>Dataset</th><th class="col-record-id">Record ID</th><th class="col-details">Details</th></tr></thead><tbody>';
	entries.forEach(function(entry) {
		const detailsMarkup = buildDetailsMarkup(entry);
		const datasetDisplay = String(entry.dataset_display_name || entry.dataset || '');
		tableHTML += '<tr><td>' + entry.id + '</td><td>' + entry.date + '</td><td>' + entry.time + '</td><td>' + (entry.record_type || '') + '</td><td>' + getActionBadgeHtml(entry.action) + '</td><td>' + (entry.source_display_name || 'System') + '</td><td>' + (entry.ip_address || '') + '</td><td>' + escapeHtml(datasetDisplay) + '</td><td>' + escapeHtml(String(entry.record_id == null ? '' : entry.record_id)) + '</td><td>' + detailsMarkup + '</td></tr>';
	});
	tableHTML += '</tbody></table>';
	container.html('<div class="audit-table-scroll">' + tableHTML + '</div>');
}


function renderAuditTimeline(entries) {
	const container = $('#audit-container');
	const sortedEntries = entries.slice().sort(function(a, b) {
		return Number(b.id || 0) - Number(a.id || 0);
	});

	let timelineHTML = '<div class="audit-timeline">';
	let currentDate = '';

	sortedEntries.forEach(function(entry) {
		if (entry.date !== currentDate) {
			if (currentDate !== '') {
				timelineHTML += '</div>';
			}
			currentDate = entry.date;
			timelineHTML += '<div class="timeline-group"><h3 class="timeline-date">' + currentDate + '</h3>';
		}

		const detailsMarkup = buildDetailsMarkup(entry);
		const datasetDisplay = String(entry.dataset_display_name || entry.dataset || '');
		timelineHTML += '<div class="timeline-item"><div class="timeline-dot ' + getActionDotClass(entry.action) + '"></div><div class="timeline-card"><div class="timeline-card-header">' + getActionBadgeHtml(entry.action) + '<span class="timeline-time">' + entry.time + '</span><span class="timeline-id">#' + entry.id + '</span></div><p class="timeline-line"><strong>Type:</strong> ' + (entry.record_type || '') + ' | <strong>Source:</strong> ' + (entry.source_display_name || 'System') + '</p><p class="timeline-line"><strong>Dataset:</strong> ' + escapeHtml(datasetDisplay) + ' | <strong>Record:</strong> ' + escapeHtml(String(entry.record_id == null ? '' : entry.record_id)) + '</p><p class="timeline-line"><strong>Details:</strong> ' + detailsMarkup + '</p></div></div>';
	});

	if (currentDate !== '') {
		timelineHTML += '</div>';
	}

	timelineHTML += '</div>';
	container.html(timelineHTML);
}


function escapeHtml(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}


function getActionBadgeInfo(action) {
	var a = String(action || '').toLowerCase();
	var map = {
		'create':                     { label: 'Create',               color: 'green' },
		'update':                     { label: 'Update',               color: 'blue' },
		'delete':                     { label: 'Delete',               color: 'red' },
		'bulk_delete':                { label: 'Bulk Delete',          color: 'red' },
		'reset':                      { label: 'Reset',                color: 'orange' },
		'widget visibility':          { label: 'Widget Visibility',    color: 'blue' },
		'login':                      { label: 'Login',                color: 'green' },
		'logout':                     { label: 'Logout',               color: 'red' },
		'password_change':            { label: 'Change',               color: 'blue' },
		'notification_sent':          { label: 'Sent',                 color: 'blue' },
		'notification_read':          { label: 'Read',                 color: 'green' },
		'notification_mark_all_read': { label: 'Read',                 color: 'green' },
		'notification_deleted':       { label: 'Delete',               color: 'red' },
		'notification_delete_all':    { label: 'Delete',               color: 'red' },
		'generate':                   { label: 'Generate',             color: 'blue' },
		'download_pdf':               { label: 'Download',             color: 'blue' },
		'download_csv':               { label: 'Download',             color: 'blue' }
	};
	return map[a] || { label: a || 'Unknown', color: 'blue' };
}

function getActionBadgeHtml(action) {
	var info = getActionBadgeInfo(action);
	return '<span class="badge badge-' + info.color + '">' + escapeHtml(info.label) + '</span>';
}

function getActionDotClass(action) {
	return 'timeline-dot-' + getActionBadgeInfo(action).color;
}

function buildInlineDiffMarkup(oldValue, newValue) {
	const oldText = String(oldValue || '');
	const newText = String(newValue || '');
	if (oldText === '' && newText === '') {
		return { oldMarkup: '<code>(empty)</code>', newMarkup: '<code>(empty)</code>' };
	}
	if (oldText === newText) {
		const escaped = escapeHtml(oldText || '(empty)');
		return { oldMarkup: '<code>' + escaped + '</code>', newMarkup: '<code>' + escaped + '</code>' };
	}

	let prefix = 0;
	const minLen = Math.min(oldText.length, newText.length);
	while (prefix < minLen && oldText[prefix] === newText[prefix]) {
		prefix += 1;
	}
	let oldSuffix = oldText.length;
	let newSuffix = newText.length;
	while (oldSuffix > prefix && newSuffix > prefix && oldText[oldSuffix - 1] === newText[newSuffix - 1]) {
		oldSuffix -= 1;
		newSuffix -= 1;
	}

	const oldPrefix = escapeHtml(oldText.slice(0, prefix));
	const oldChanged = escapeHtml(oldText.slice(prefix, oldSuffix));
	const oldTail = escapeHtml(oldText.slice(oldSuffix));
	const newPrefix = escapeHtml(newText.slice(0, prefix));
	const newChanged = escapeHtml(newText.slice(prefix, newSuffix));
	const newTail = escapeHtml(newText.slice(newSuffix));

	const oldBody = (oldPrefix ? '<span class="diff-unchanged">' + oldPrefix + '</span>' : '') + (oldChanged ? '<span class="diff-removed">' + oldChanged + '</span>' : '') + (oldTail ? '<span class="diff-unchanged">' + oldTail + '</span>' : '');
	const newBody = (newPrefix ? '<span class="diff-unchanged">' + newPrefix + '</span>' : '') + (newChanged ? '<span class="diff-added">' + newChanged + '</span>' : '') + (newTail ? '<span class="diff-unchanged">' + newTail + '</span>' : '');

	return { oldMarkup: '<code>' + (oldBody || '(empty)') + '</code>', newMarkup: '<code>' + (newBody || '(empty)') + '</code>' };
}


function buildDetailsMarkup(entry) {
	let details = String(entry.details || '');
	const recordType = String(entry.record_type || '').toLowerCase();
	const action = String(entry.action || '').toLowerCase();
	const allowDiff = recordType === 'record' && (action === 'create' || action === 'update' || action === 'delete');

	if (!allowDiff) {
		return escapeHtml(details || '(empty)');
	}

	const detailsPrefixMatch = details.match(/^([^:]{1,40}):\s+(?=(?:Title|Description):\s*)/);
	if (detailsPrefixMatch) {
		details = details.slice(detailsPrefixMatch[0].length);
	}

	const labeledSegments = [];
	const labeledPattern = /(?:^|,\s*)(Title|Description):\s*(.*?)(?=,\s*(?:Title|Description):\s*|$)/g;
	let labeledMatch = labeledPattern.exec(details);
	while (labeledMatch !== null) {
		const label = labeledMatch[1];
		const valueChunk = String(labeledMatch[2] || '');
		const arrowAt = valueChunk.indexOf(' -> ');
		if (arrowAt !== -1) {
			const oldValue = valueChunk.slice(0, arrowAt);
			const newValue = valueChunk.slice(arrowAt + 4);
			const diff = buildInlineDiffMarkup(oldValue, newValue);
			labeledSegments.push('<span class="audit-details-part"><strong>' + escapeHtml(label) + ':</strong> ' + diff.oldMarkup + ' <span class="audit-details-arrow">-&gt;</span> ' + diff.newMarkup + '</span>');
		}
		labeledMatch = labeledPattern.exec(details);
	}

	if (labeledSegments.length > 0) {
		return '<span class="audit-details-diff">' + labeledSegments.join(', ') + '</span>';
	}

	const arrowIndex = details.indexOf(' -> ');
	if (arrowIndex === -1) {
		return escapeHtml(details || '(empty)');
	}

	const oldValue = details.slice(0, arrowIndex);
	const newValue = details.slice(arrowIndex + 4);
	const diff = buildInlineDiffMarkup(oldValue, newValue);
	return '<span class="audit-details-diff">' + diff.oldMarkup + ' <span class="audit-details-arrow">-&gt;</span> ' + diff.newMarkup + '</span>';
}


function displayAuditTrail(data) {
	renderAuditTrail(data.entries || [], 'No audit trail entries yet.');
}


function filterAuditTrail() {
	if (!allAuditTrail || !allAuditTrail.entries) {
		return;
	}
	const searchValue = $('#search-input').val().toLowerCase();
	const recordTypeFilter = String($('#audit-record-type-filter').val() || '').toLowerCase();
	const actionFilter = String($('#audit-action-filter').val() || '').toLowerCase();
	const filteredEntries = allAuditTrail.entries.filter(function(entry) {
		const details = String(entry.details || '').toLowerCase();
		const recordType = String(entry.record_type || '').toLowerCase();
		const action = String(entry.action || '').toLowerCase();
		const dataset = String(entry.dataset || '').toLowerCase();
		const datasetDisplay = String(entry.dataset_display_name || '').toLowerCase();
		const source = String(entry.source_display_name || '').toLowerCase();
		const target = String(entry.target_display_name || '').toLowerCase();
		const ipAddress = String(entry.ip_address || '').toLowerCase();

		if (recordTypeFilter !== '' && recordType !== recordTypeFilter) {
			return false;
		}
		if (actionFilter !== '' && action !== actionFilter) {
			return false;
		}

		return entry.record_id.toString().includes(searchValue) ||
			recordType.includes(searchValue) ||
			action.includes(searchValue) ||
			dataset.includes(searchValue) ||
			datasetDisplay.includes(searchValue) ||
			source.includes(searchValue) ||
			target.includes(searchValue) ||
			ipAddress.includes(searchValue) ||
			entry.change_type.toLowerCase().includes(searchValue) ||
			details.includes(searchValue) ||
			entry.date.includes(searchValue) ||
			entry.time.includes(searchValue);
	});
	return renderAuditTrail(filteredEntries, 'No audit trail entries match your search.');
}
