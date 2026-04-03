function setupAuditTrailPageHandlers() {
	const debouncedAuditFilter = debounce(filterAuditTrail, 180);
	$('#search-input').on('keyup', function() {
		debouncedAuditFilter();
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
	let tableHTML = '<table class="data-table"><thead><tr><th>ID</th><th>Date</th><th>Time</th><th>Record ID</th><th>Change Type</th><th>Field Name</th><th>Old Value</th><th>New Value</th></tr></thead><tbody>';
	entries.forEach(function(entry) {
		const oldValue = String(entry.old_value || '');
		const newValue = String(entry.new_value || '');
		const valueDiff = buildInlineDiffMarkup(oldValue, newValue);
		tableHTML += '<tr><td>' + entry.id + '</td><td>' + entry.date + '</td><td>' + entry.time + '</td><td>' + entry.record_id + '</td><td><span class="badge badge-' + entry.change_type.toLowerCase() + '">' + entry.change_type + '</span></td><td>' + entry.field_name + '</td><td>' + valueDiff.oldMarkup + '</td><td>' + valueDiff.newMarkup + '</td></tr>';
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

		const typeClass = String(entry.change_type || '').toLowerCase();
		const valueDiff = buildInlineDiffMarkup(String(entry.old_value || ''), String(entry.new_value || ''));
		timelineHTML += '<div class="timeline-item"><div class="timeline-dot timeline-dot-' + typeClass + '"></div><div class="timeline-card"><div class="timeline-card-header"><span class="badge badge-' + typeClass + '">' + entry.change_type + '</span><span class="timeline-time">' + entry.time + '</span><span class="timeline-id">#' + entry.id + '</span></div><p class="timeline-line"><strong>Record:</strong> ' + entry.record_id + ' | <strong>Field:</strong> ' + entry.field_name + '</p><p class="timeline-line"><strong>Old:</strong> ' + valueDiff.oldMarkup + '</p><p class="timeline-line"><strong>New:</strong> ' + valueDiff.newMarkup + '</p></div></div>';
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


function displayAuditTrail(data) {
	renderAuditTrail(data.entries || [], 'No audit trail entries yet.');
}


function filterAuditTrail() {
	if (!allAuditTrail || !allAuditTrail.entries) {
		return;
	}
	const searchValue = $('#search-input').val().toLowerCase();
	const filteredEntries = allAuditTrail.entries.filter(function(entry) {
		const oldValue = String(entry.old_value || '').toLowerCase();
		const newValue = String(entry.new_value || '').toLowerCase();
		return entry.record_id.toString().includes(searchValue) ||
			entry.change_type.toLowerCase().includes(searchValue) ||
			entry.field_name.toLowerCase().includes(searchValue) ||
			oldValue.includes(searchValue) ||
			newValue.includes(searchValue) ||
			entry.date.includes(searchValue) ||
			entry.time.includes(searchValue);
	});
	return renderAuditTrail(filteredEntries, 'No audit trail entries match your search.');
}
