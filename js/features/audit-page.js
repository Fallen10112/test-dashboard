let currentAuditView = 'table';

function setupAuditTrailPageHandlers() {
	const debouncedAuditFilter = debounce(filterAuditTrail, 180);
	$('#search-input').on('keyup', function() {
		debouncedAuditFilter();
	});
	$('#audit-record-type-filter').on('change', function() {
		filterAuditTrail();
	});
	$('#audit-action-filter').on('change', function() {
		filterAuditTrail();
	});
	$('#audit-view-table').on('click', function() {
		setAuditView('table');
	});
	$('#audit-view-timeline').on('click', function() {
		setAuditView('timeline');
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


function loadAuditTrail() {
	$.ajax({
		url: '../api.php?action=audit_trail',
		type: 'GET',
		dataType: 'json',
		success: function(data) {
			allAuditTrail = data;
			displayAuditTrail(data);
		},
		error: function() {
			$('#audit-container').html('<p class="error">Error loading audit trail. Please refresh the page.</p>');
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
	if (currentAuditView === 'timeline') {
		renderAuditTimeline(entries);
	} else {
		renderAuditTable(entries);
	}
}


function renderAuditTable(entries) {
	const container = $('#audit-container');
	let tableHTML = '<table class="data-table"><thead><tr><th>ID</th><th>Date</th><th>Time</th><th>Type</th><th>Action</th><th>Actor</th><th>IP</th><th>Record ID</th><th>Details</th></tr></thead><tbody>';
	entries.forEach(function(entry) {
		const detailsMarkup = buildDetailsMarkup(entry);
		tableHTML += '<tr><td>' + entry.id + '</td><td>' + entry.date + '</td><td>' + entry.time + '</td><td>' + (entry.record_type || '') + '</td><td>' + getActionBadgeHtml(entry.action) + '</td><td>' + (entry.actor_display_name || 'System') + '</td><td>' + (entry.ip_address || '') + '</td><td>' + entry.record_id + '</td><td>' + detailsMarkup + '</td></tr>';
	});
	tableHTML += '</tbody></table>';
	container.html(tableHTML);
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
		timelineHTML += '<div class="timeline-item"><div class="timeline-dot ' + getActionDotClass(entry.action) + '"></div><div class="timeline-card"><div class="timeline-card-header">' + getActionBadgeHtml(entry.action) + '<span class="timeline-time">' + entry.time + '</span><span class="timeline-id">#' + entry.id + '</span></div><p class="timeline-line"><strong>Type:</strong> ' + (entry.record_type || '') + ' | <strong>Actor:</strong> ' + (entry.actor_display_name || 'System') + '</p><p class="timeline-line"><strong>Record:</strong> ' + entry.record_id + '</p><p class="timeline-line"><strong>Details:</strong> ' + detailsMarkup + '</p></div></div>';
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
		'login':                      { label: 'Login',                color: 'green' },
		'logout':                     { label: 'Logout',               color: 'red' },
		'password_change':            { label: 'Changed',              color: 'blue' },
		'notification_sent':          { label: 'Sent',                 color: 'blue' },
		'notification_read':          { label: 'Read',                 color: 'green' },
		'notification_mark_all_read': { label: 'Read',                 color: 'green' },
		'notification_deleted':       { label: 'Deleted',              color: 'red' },
		'notification_delete_all':    { label: 'Deleted',              color: 'red' }
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
	const details = String(entry.details || '');
	const recordType = String(entry.record_type || '').toLowerCase();
	const action = String(entry.action || '').toLowerCase();
	const allowDiff = recordType === 'record' && (action === 'create' || action === 'update' || action === 'delete');

	if (!allowDiff) {
		return escapeHtml(details || '(empty)');
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
		const actor = String(entry.actor_display_name || '').toLowerCase();
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
			actor.includes(searchValue) ||
			target.includes(searchValue) ||
			ipAddress.includes(searchValue) ||
			entry.change_type.toLowerCase().includes(searchValue) ||
			details.includes(searchValue) ||
			entry.date.includes(searchValue) ||
			entry.time.includes(searchValue);
	});
	return renderAuditTrail(filteredEntries, 'No audit trail entries match your search.');
}
