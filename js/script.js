
let allData = null;
let allLogs = null;
let allAuditTrail = null;
let currentSortColumn = null;
let currentSortOrder = 'asc';
let selectedRecordIds = new Set();
let currentAuditView = 'table';
let currentFilteredDataItems = [];


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


$(document).ready(function() {
	initializeTheme();
	setupResetButtonHandler();
	loadHeaderMetrics();

	if ($('#data-container').length > 0) {
		loadData();
		setupDataPageHandlers();
	}

	if ($('#report-container').length > 0) {
		setupReportsPageHandlers();
	}

	if ($('#audit-container').length > 0) {
		loadAuditTrail();
		setupAuditTrailPageHandlers();
	}
});


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
	const now = new Date();
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
			if (fieldName !== 'title') {
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
										const currentPage = window.location.pathname.split('/').pop() || 'home.php';
										window.location.href = '../pages/' + currentPage;
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


function setupDataPageHandlers() {
	$('#add-record-btn').on('click', function() {
		openAddModal();
	});

	$('#export-filtered-pdf-btn').on('click', function() {
		exportFilteredDataAsPDF();
	});

	$('#export-filtered-csv-btn').on('click', function() {
		exportFilteredDataAsCSV();
	});

	$('#bulk-delete-btn').on('click', function() {
		bulkDeleteSelectedRecords();
	});
	
	$('#search-input').on('keyup', function() {
		filterAndSortTable();
	});
	
	$('.modal-close').on('click', function() {
		closeModal();
	});
	
	$('#modal-cancel').on('click', function() {
		closeModal();
	});
	
	$('#record-form').on('submit', function(e) {
		e.preventDefault();
		saveRecord();
	});
	
	$('#record-modal').on('click', function(e) {
		if (e.target.id === 'record-modal') {
			closeModal();
		}
	});

	updateFilteredExportButtonsState();
}


function updateFilteredExportButtonsState() {
	const hasRows = Array.isArray(currentFilteredDataItems) && currentFilteredDataItems.length > 0;
	$('#export-filtered-pdf-btn').prop('disabled', !hasRows);
	$('#export-filtered-csv-btn').prop('disabled', !hasRows);
}


function syncSelectedRecordIdsWithData() {
	if (!allData || !Array.isArray(allData.items)) {
		selectedRecordIds.clear();
		return;
	}

	const existingIds = new Set(allData.items.map(function(item) {
		return String(item.id);
	}));

	selectedRecordIds.forEach(function(id) {
		if (!existingIds.has(String(id))) {
			selectedRecordIds.delete(id);
		}
	});
}


function updateBulkDeleteButtonState() {
	const selectedCount = selectedRecordIds.size;
	const $bulkButton = $('#bulk-delete-btn');

	if ($bulkButton.length === 0) {
		return;
	}

	$bulkButton.prop('disabled', selectedCount === 0);
	$bulkButton.text('Delete Selected (' + selectedCount + ')');
}


function updateSelectAllCheckboxState() {
	const $visibleCheckboxes = $('.record-select-checkbox');
	const $selectAll = $('#select-all-records');

	if ($selectAll.length === 0) {
		return;
	}

	if ($visibleCheckboxes.length === 0) {
		$selectAll.prop('checked', false);
		$selectAll.prop('indeterminate', false);
		return;
	}

	let checkedCount = 0;
	$visibleCheckboxes.each(function() {
		if ($(this).is(':checked')) {
			checkedCount += 1;
		}
	});

	$selectAll.prop('checked', checkedCount === $visibleCheckboxes.length);
	$selectAll.prop('indeterminate', checkedCount > 0 && checkedCount < $visibleCheckboxes.length);
}


function bindSelectionHandlers() {
	$('#select-all-records').off('change').on('change', function() {
		const isChecked = $(this).is(':checked');

		$('.record-select-checkbox').each(function() {
			const id = String($(this).data('id'));
			$(this).prop('checked', isChecked);
			if (isChecked) {
				selectedRecordIds.add(id);
			} else {
				selectedRecordIds.delete(id);
			}
		});

		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
	});

	$('.record-select-checkbox').off('change').on('change', function() {
		const id = String($(this).data('id'));
		if ($(this).is(':checked')) {
			selectedRecordIds.add(id);
		} else {
			selectedRecordIds.delete(id);
		}

		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
	});
}


function bulkDeleteSelectedRecords() {
	const selectedIds = Array.from(selectedRecordIds);

	if (selectedIds.length === 0) {
		showToast({
			type: 'warning',
			title: 'No Records Selected',
			message: 'Select at least one record to use bulk delete.',
			showOkayButton: true,
			autoCloseMs: 3000
		});
		return;
	}

	showToast({
		type: 'warning',
		title: 'Delete Selected Records?',
		message: 'This will permanently delete ' + selectedIds.length + ' selected record(s).',
		autoCloseMs: 0,
		buttons: [
			{
				label: 'Cancel',
				className: 'btn-secondary'
			},
			{
				label: 'Delete Selected',
				className: 'btn-danger',
				onClick: function() {
					const idSet = new Set(selectedIds.map(String));
					const deletedItems = allData.items.filter(function(item) {
						return idSet.has(String(item.id));
					});
					const deletedIdsSummary = deletedItems.map(function(item) {
						return item.id;
					}).join(', ');
					const auditEntries = [];

					deletedItems.forEach(function(item) {
						auditEntries.push({
							changeType: 'DELETE',
							recordId: item.id,
							fieldName: 'title',
							oldValue: item.title,
							newValue: ''
						});
						auditEntries.push({
							changeType: 'DELETE',
							recordId: item.id,
							fieldName: 'description',
							oldValue: item.description,
							newValue: ''
						});
					});

					auditEntries.push({
						changeType: 'DELETE',
						recordId: 'BULK',
						fieldName: 'bulk_action',
						oldValue: 'Selected IDs: ' + deletedIdsSummary,
						newValue: 'Deleted ' + deletedItems.length + ' records'
					});

					addAuditTrailEntries(auditEntries);

					allData.items = allData.items.filter(function(item) {
						return !idSet.has(String(item.id));
					});

					selectedRecordIds.clear();
					displayDataTable(allData);

					const eventMessage = 'Bulk delete completed for ' + deletedItems.length + ' records (IDs: ' + deletedIdsSummary + ')';
					saveDataToFileWithLog(eventMessage, 'delete', {
						successToast: {
							type: 'success',
							title: 'Bulk Delete Complete',
							message: deletedItems.length + ' record(s) deleted successfully.',
							showOkayButton: true,
							autoCloseMs: 3000
						}
					});
				}
			}
		]
	});
}


function setupAuditTrailPageHandlers() {
	$('#search-input').on('keyup', function() {
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


function setupReportsPageHandlers() {
	loadData();
	loadLogs();
	
	$('#generate-report-btn').on('click', function() {
		generateReport();
	});
	
	$('#download-pdf-btn').on('click', function() {
		downloadReportAsPDF();
	});
	
	$('#download-csv-btn').on('click', function() {
		downloadReportAsCSV();
	});
	
	$('#dataset-selector').on('change', function() {
		$('#report-container').empty();
		$('#download-pdf-btn').addClass('hidden');
		$('#download-csv-btn').addClass('hidden');
	});
}


function loadData(callback) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
		success: function(data) {
			console.log("Data loaded successfully:", data);
			allData = data;
			if ($('#data-container').length > 0) {
				displayDataTable(data);
			}
			if (callback && typeof callback === 'function') {
				callback();
			}
		},
		error: function(error) {
			console.error("Error loading data:", error);
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
			console.log("Logs loaded successfully:", data);
			if (data && typeof data === 'object') {
				allLogs = data.logs ? data : (data.entries ? { logs: data.entries } : { logs: [] });
			} else {
				allLogs = { logs: [] };
			}
			if (callback && typeof callback === 'function') {
				callback();
			}
		},
		error: function(error) {
			console.error("Error loading logs:", error);
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
		console.error("Error converting time:", e);
		return timeString;
	}
}


function displayDataTable(data) {
	syncSelectedRecordIdsWithData();
	const container = $('#data-container');
	container.empty();
	
	if (data.items && data.items.length > 0) {
		currentFilteredDataItems = data.items.slice();
		let tableHTML = `
			<table class="data-table data-records-table">
				<thead>
					<tr>
						<th class="select-column"><input type="checkbox" id="select-all-records" aria-label="Select all records"></th>
						<th class="sortable" data-column="id">ID <span class="sort-indicator"></span></th>
						<th class="sortable" data-column="title">Title <span class="sort-indicator"></span></th>
						<th class="sortable" data-column="description">Description <span class="sort-indicator"></span></th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
		`;
		
		data.items.forEach(function(item) {
			const isSelected = selectedRecordIds.has(String(item.id));
			tableHTML += `
				<tr>
					<td class="select-column"><input type="checkbox" class="record-select-checkbox" data-id="${item.id}" ${isSelected ? 'checked' : ''} aria-label="Select record ${item.id}"></td>
					<td>${item.id}</td>
					<td>${item.title}</td>
					<td>${item.description}</td>
					<td>
						<button class="btn btn-sm btn-secondary edit-btn" data-id="${item.id}">Edit</button>
						<button class="btn btn-sm btn-danger delete-btn" data-id="${item.id}">Delete</button>
					</td>
				</tr>
			`;
		});
		
		tableHTML += `
				</tbody>
			</table>
		`;
		
		container.html(tableHTML);
		
		$('.sortable').on('click', function() {
			const column = $(this).data('column');
			if (currentSortColumn === column) {
				currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
			} else {
				currentSortColumn = column;
				currentSortOrder = 'asc';
			}
			filterAndSortTable();
		});
		
		$('.edit-btn').on('click', function() {
			const id = $(this).data('id');
			openEditModal(id);
		});
		
		$('.delete-btn').on('click', function() {
			const id = $(this).data('id');
			deleteRecord(id);
		});

		bindSelectionHandlers();
		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
		updateFilteredExportButtonsState();
	} else {
		container.html('<p>No data available.</p>');
		selectedRecordIds.clear();
		updateBulkDeleteButtonState();
		currentFilteredDataItems = [];
		updateFilteredExportButtonsState();
	}
}


function filterAndSortTable() {
	if (!allData || !allData.items) return;

	syncSelectedRecordIdsWithData();
	
	const searchValue = $('#search-input').val().toLowerCase();
	
	let filteredItems = allData.items.filter(function(item) {
		return item.title.toLowerCase().includes(searchValue) || 
			   item.description.toLowerCase().includes(searchValue);
	});
	
	if (currentSortColumn) {
		filteredItems.sort(function(a, b) {
			let aVal = a[currentSortColumn];
			let bVal = b[currentSortColumn];
			
			if (currentSortColumn === 'id') {
				aVal = parseInt(aVal);
				bVal = parseInt(bVal);
			}
			
			if (currentSortOrder === 'asc') {
				return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
			} else {
				return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
			}
		});
	}
	
	const container = $('#data-container');
	container.empty();
	currentFilteredDataItems = filteredItems.slice();
	
	if (filteredItems.length > 0) {
		let tableHTML = `
			<table class="data-table data-records-table">
				<thead>
					<tr>
						<th class="select-column"><input type="checkbox" id="select-all-records" aria-label="Select all records"></th>
						<th class="sortable ${currentSortColumn === 'id' ? 'sorted' : ''}" data-column="id">
							ID 
							<span class="sort-indicator">${currentSortColumn === 'id' ? (currentSortOrder === 'asc' ? '▲' : '▼') : ''}</span>
						</th>
						<th class="sortable ${currentSortColumn === 'title' ? 'sorted' : ''}" data-column="title">
							Title 
							<span class="sort-indicator">${currentSortColumn === 'title' ? (currentSortOrder === 'asc' ? '▲' : '▼') : ''}</span>
						</th>
						<th class="sortable ${currentSortColumn === 'description' ? 'sorted' : ''}" data-column="description">
							Description 
							<span class="sort-indicator">${currentSortColumn === 'description' ? (currentSortOrder === 'asc' ? '▲' : '▼') : ''}</span>
						</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
		`;
		
		filteredItems.forEach(function(item) {
			const isSelected = selectedRecordIds.has(String(item.id));
			tableHTML += `
				<tr>
					<td class="select-column"><input type="checkbox" class="record-select-checkbox" data-id="${item.id}" ${isSelected ? 'checked' : ''} aria-label="Select record ${item.id}"></td>
					<td>${item.id}</td>
					<td>${item.title}</td>
					<td>${item.description}</td>
					<td>
						<button class="btn btn-sm btn-secondary edit-btn" data-id="${item.id}">Edit</button>
						<button class="btn btn-sm btn-danger delete-btn" data-id="${item.id}">Delete</button>
					</td>
				</tr>
			`;
		});
		
		tableHTML += `
				</tbody>
			</table>
		`;
		
		container.html(tableHTML);
		
		$('.sortable').on('click', function() {
			const column = $(this).data('column');
			if (currentSortColumn === column) {
				currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
			} else {
				currentSortColumn = column;
				currentSortOrder = 'asc';
			}
			filterAndSortTable();
		});
		
		$('.edit-btn').on('click', function() {
			const id = $(this).data('id');
			openEditModal(id);
		});
		
		$('.delete-btn').on('click', function() {
			const id = $(this).data('id');
			deleteRecord(id);
		});

		bindSelectionHandlers();
		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
		updateFilteredExportButtonsState();
	} else {
		container.html('<p>No records match your search.</p>');
		updateBulkDeleteButtonState();
		updateFilteredExportButtonsState();
	}
}


function exportFilteredDataAsCSV() {
	if (!Array.isArray(currentFilteredDataItems) || currentFilteredDataItems.length === 0) {
		showToast({
			type: 'warning',
			title: 'No Filtered Rows',
			message: 'There are no visible rows to export.',
			showOkayButton: true,
			autoCloseMs: 3000
		});
		return;
	}

	let csv = 'ID,Title,Description\n';
	currentFilteredDataItems.forEach(function(item) {
		const title = '"' + String(item.title || '').replace(/"/g, '""') + '"';
		const description = '"' + String(item.description || '').replace(/"/g, '""') + '"';
		csv += item.id + ',' + title + ',' + description + '\n';
	});

	const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
	const link = document.createElement('a');
	const url = URL.createObjectURL(blob);
	link.setAttribute('href', url);
	link.setAttribute('download', 'filtered-data-' + new Date().toISOString().split('T')[0] + '.csv');
	link.style.visibility = 'hidden';
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);

	logEvent('A filtered CSV export has been downloaded for: Data (' + currentFilteredDataItems.length + ' rows)');
	showToast({
		type: 'success',
		title: 'CSV Export Complete',
		message: 'Filtered Data CSV downloaded successfully.',
		showOkayButton: true,
		autoCloseMs: 3000
	});
}


function exportFilteredDataAsPDF() {
	if (!Array.isArray(currentFilteredDataItems) || currentFilteredDataItems.length === 0) {
		showToast({
			type: 'warning',
			title: 'No Filtered Rows',
			message: 'There are no visible rows to export.',
			showOkayButton: true,
			autoCloseMs: 3000
		});
		return;
	}

	if (typeof html2pdf === 'undefined') {
		showToast({
			type: 'error',
			title: 'PDF Export Unavailable',
			message: 'PDF export library is not loaded on this page.',
			showOkayButton: true,
			autoCloseMs: 3000
		});
		return;
	}

	const exportDate = new Date().toLocaleDateString();
	let tableRows = '';
	currentFilteredDataItems.forEach(function(item) {
		tableRows += '<tr><td>' + item.id + '</td><td>' + item.title + '</td><td>' + item.description + '</td></tr>';
	});

	const exportContainer = document.createElement('div');
	exportContainer.innerHTML = `
		<div style="font-family: Arial, sans-serif; color: #2c3e50;">
			<h2 style="margin-bottom: 8px;">Filtered Data Export</h2>
			<p style="margin: 0 0 12px;"><strong>Generated:</strong> ${exportDate}</p>
			<p style="margin: 0 0 16px;"><strong>Total Rows:</strong> ${currentFilteredDataItems.length}</p>
			<table style="width:100%; border-collapse: collapse;">
				<thead>
					<tr style="background:#667eea; color:#ffffff;">
						<th style="padding:8px; border:1px solid #d4dae6; text-align:left;">ID</th>
						<th style="padding:8px; border:1px solid #d4dae6; text-align:left;">Title</th>
						<th style="padding:8px; border:1px solid #d4dae6; text-align:left;">Description</th>
					</tr>
				</thead>
				<tbody>${tableRows}</tbody>
			</table>
		</div>
	`;

	const opt = {
		margin: 10,
		filename: 'filtered-data-' + new Date().toISOString().split('T')[0] + '.pdf',
		image: { type: 'jpeg', quality: 0.98 },
		html2canvas: { scale: 2 },
		jsPDF: { orientation: 'portrait', unit: 'mm', format: 'a4' }
	};

	html2pdf().set(opt).from(exportContainer).save();
	logEvent('A filtered PDF export has been downloaded for: Data (' + currentFilteredDataItems.length + ' rows)');
	showToast({
		type: 'success',
		title: 'PDF Export Complete',
		message: 'Filtered Data PDF downloaded successfully.',
		showOkayButton: true,
		autoCloseMs: 3000
	});
}


function openAddModal() {
	$('#modal-title').text('Add New Record');
	$('#record-form').attr('data-record-id', '');
	$('#record-form').attr('data-edit-mode', 'false');
	$('#record-title').val('');
	$('#record-description').val('');
	$('#record-modal').removeClass('hidden');
}


function openEditModal(id) {
	const item = allData.items.find(i => i.id == id);
	
	if (item) {
		$('#modal-title').text('Edit Record');
		$('#record-form').attr('data-record-id', item.id);
		$('#record-form').attr('data-edit-mode', 'true');
		$('#record-title').val(item.title);
		$('#record-description').val(item.description);
		$('#record-modal').removeClass('hidden');
	}
}


function closeModal() {
	$('#record-modal').addClass('hidden');
}


function addAuditTrailEntry(changeType, recordId, fieldName, oldValue, newValue) {
	$.ajax({
		url: '../api.php?action=audit_trail',
		type: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			action: 'add_audit_entry',
			changeType: changeType,
			recordId: recordId,
			fieldName: fieldName,
			oldValue: oldValue,
			newValue: newValue
		}),
		success: function(response) {
			console.log("Audit trail entry added");
		},
		error: function(error) {
			console.error("Error adding audit entry:", error);
		}
	});
}


function addAuditTrailEntries(entries) {
	if (!Array.isArray(entries) || entries.length === 0) {
		return;
	}

	$.ajax({
		url: '../api.php?action=audit_trail',
		type: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			action: 'add_audit_entries',
			entries: entries
		}),
		success: function() {
			console.log('Audit trail entries added');
		},
		error: function(error) {
			console.error('Error adding audit entries:', error);
		}
	});
}


function saveRecord() {
	const id = $('#record-form').attr('data-record-id');
	const title = $('#record-title').val().trim();
	const description = $('#record-description').val().trim();
	const editMode = $('#record-form').attr('data-edit-mode') === 'true';
	
	if (title.length < 1) {
		alert('Title is required (minimum 1 character)');
		return;
	}
	
	if (description.length < 1) {
		alert('Description is required (minimum 1 character)');
		return;
	}
	
	let eventMessage = '';
	
	if (editMode) {
		const index = allData.items.findIndex(i => i.id == id);
		if (index !== -1) {
			const oldTitle = allData.items[index].title;
			const oldDescription = allData.items[index].description;
			
			if (oldTitle !== title) {
				addAuditTrailEntry('EDIT', id, 'title', oldTitle, title);
			}
			if (oldDescription !== description) {
				addAuditTrailEntry('EDIT', id, 'description', oldDescription, description);
			}
			
			allData.items[index].title = title;
			allData.items[index].description = description;
			eventMessage = `An entry has been edited; ID ${id} with title: "${title}", and description: "${description}"`;
		}
	} else {
		const newId = allData.items.length > 0 
			? Math.max(...allData.items.map(i => i.id)) + 1 
			: 1;
		allData.items.push({
			id: newId,
			title: title,
			description: description
		});
		
		addAuditTrailEntry('ADD', newId, 'title', '', title);
		addAuditTrailEntry('ADD', newId, 'description', '', description);
		
		eventMessage = `A new entry has been added; ID ${newId} with title: "${title}", and description: "${description}"`;
	}
	
	saveDataToFileWithLog(eventMessage, editMode ? 'edit' : 'add');
	
	closeModal();
	displayDataTable(allData);
}


function deleteRecord(id) {
	if (confirm('Are you sure you want to delete this record?')) {
		const item = allData.items.find(i => i.id == id);
		
		addAuditTrailEntry('DELETE', id, 'title', item.title, '');
		addAuditTrailEntry('DELETE', id, 'description', item.description, '');
		
		const eventMessage = `An entry has been deleted; ID ${item.id} with title: "${item.title}", and description: "${item.description}"`;
		
		allData.items = allData.items.filter(item => item.id != id);
		selectedRecordIds.delete(String(id));
		saveDataToFileWithLog(eventMessage, 'delete');
		displayDataTable(allData);
	}
}


function saveDataToFileWithLog(eventMessage, action, options) {
	const settings = options || {};

	$.ajax({
		url: '../api.php?action=' + action,
		type: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			data: allData,
			event: eventMessage
		}),
		dataType: 'json',
		success: function(response) {
			console.log("Data saved successfully:", response);
			if (response && response.success === false) {
				showToast({
					type: 'error',
					title: 'Save Failed',
					message: 'Error saving data: ' + (response.message || 'Unknown error'),
					showOkayButton: true,
					autoCloseMs: 3000
				});
				return;
			}

			if (action === 'add') {
				showToast({
					type: 'success',
					title: 'Record Added',
					message: 'The new record has been added successfully.',
					showOkayButton: true,
					autoCloseMs: 3000
				});
			} else if (action === 'edit') {
				showToast({
					type: 'success',
					title: 'Record Updated',
					message: 'The record has been edited successfully.',
					showOkayButton: true,
					autoCloseMs: 3000
				});
				} else if (settings.successToast) {
					showToast(settings.successToast);
			}

			loadLogs();
			loadHeaderMetrics();
		},
		error: function(xhr, status, error) {
			console.error("Error saving data:", {status, error, responseText: xhr.responseText});
			showToast({
				type: 'error',
				title: 'Save Failed',
				message: 'Error saving data. Please try again.',
				showOkayButton: true,
				autoCloseMs: 3000
			});
		}
	});
}



function generateReport() {
	const datasetType = $('#dataset-selector').val();
	
	if (datasetType === 'data') {
		loadData(function() {
			generateDataReport();
			logEvent('A report has been generated for: Data');
		});
	} else if (datasetType === 'logs') {
		loadLogs(function() {
			generateLogsReport();
			logEvent('A report has been generated for: Logs');
		});
	}
}


function generateDataReport() {
	if (!allData || !allData.items) {
		alert('No data available for report');
		return;
	}
	
	const container = $('#report-container');
	container.empty();
	
	const totalRecords = allData.items.length;
	const reportDate = new Date().toLocaleDateString();
	
	let reportHTML = `
		<div id="report-content" class="report">
			<div class="report-header">
				<h3>Data Report</h3>
				<p><strong>Generated:</strong> ${reportDate}</p>
				<p><strong>Total Records:</strong> ${totalRecords}</p>
			</div>
			
			<table class="report-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Title</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
	`;
	
	allData.items.forEach(function(item) {
		reportHTML += `
			<tr>
				<td>${item.id}</td>
				<td>${item.title}</td>
				<td>${item.description}</td>
			</tr>
		`;
	});
	
	reportHTML += `
				</tbody>
			</table>
			
			<div class="report-footer">
				<p>End of Report</p>
			</div>
		</div>
	`;
	
	container.html(reportHTML);
	$('#download-pdf-btn').removeClass('hidden');
	$('#download-csv-btn').removeClass('hidden');
}


function generateLogsReport() {
	if (!allLogs || !allLogs.logs) {
		alert('No logs available for report');
		return;
	}
	
	const container = $('#report-container');
	container.empty();
	
	const totalLogs = allLogs.logs.length;
	const reportDate = new Date().toLocaleDateString();
	
	let reportHTML = `
		<div id="report-content" class="report">
			<div class="report-header">
				<h3>Logs Report</h3>
				<p><strong>Generated:</strong> ${reportDate}</p>
				<p><strong>Total Log Entries:</strong> ${totalLogs}</p>
			</div>
			
			<table class="report-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Date</th>
						<th>Time</th>
						<th>Event</th>
					</tr>
				</thead>
				<tbody>
	`;
	
	allLogs.logs.forEach(function(log) {
		const localTime = convertToLocalTime(log.time, log.date);
		reportHTML += `
			<tr>
				<td>${log.id}</td>
				<td>${log.date}</td>
				<td>${localTime}</td>
				<td>${log.event}</td>
			</tr>
		`;
	});
	
	reportHTML += `
				</tbody>
			</table>
			
			<div class="report-footer">
				<p>End of Report</p>
			</div>
		</div>
	`;
	
	container.html(reportHTML);
	$('#download-pdf-btn').removeClass('hidden');
	$('#download-csv-btn').removeClass('hidden');
}


function downloadReportAsPDF() {
	const element = document.getElementById('report-content');
	const datasetType = $('#dataset-selector').val();
	
	if (!element) {
		alert('No report generated. Please generate a report first.');
		return;
	}
	
	const opt = {
		margin: 10,
		filename: 'report-' + datasetType + '-' + new Date().toISOString().split('T')[0] + '.pdf',
		image: { type: 'jpeg', quality: 0.98 },
		html2canvas: { scale: 2 },
		jsPDF: { orientation: 'portrait', unit: 'mm', format: 'a4' }
	};
	
	html2pdf().set(opt).from(element).save();
	
	logEvent('A PDF report has been downloaded for: ' + (datasetType === 'data' ? 'Data' : 'Logs'));
}


function downloadReportAsCSV() {
	const datasetType = $('#dataset-selector').val();
	let csv = '';
	let filename = '';
	
	if (datasetType === 'data') {
		if (!allData || !allData.items) {
			alert('No data available for export');
			return;
		}
		
		csv = 'ID,Title,Description\n';
		allData.items.forEach(function(item) {
			const title = '"' + item.title.replace(/"/g, '""') + '"';
			const desc = '"' + item.description.replace(/"/g, '""') + '"';
			csv += item.id + ',' + title + ',' + desc + '\n';
		});
		
		filename = 'report-data-' + new Date().toISOString().split('T')[0] + '.csv';
	} else if (datasetType === 'logs') {
		if (!allLogs || !allLogs.logs) {
			alert('No logs available for export');
			return;
		}
		
		csv = 'ID,Date,Time,Event\n';
		allLogs.logs.forEach(function(log) {
			const localTime = convertToLocalTime(log.time, log.date);
			const event = '"' + log.event.replace(/"/g, '""') + '"';
			csv += log.id + ',' + log.date + ',' + localTime + ',' + event + '\n';
		});
		
		filename = 'report-logs-' + new Date().toISOString().split('T')[0] + '.csv';
	}
	
	const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
	const link = document.createElement('a');
	const url = URL.createObjectURL(blob);
	
	link.setAttribute('href', url);
	link.setAttribute('download', filename);
	link.style.visibility = 'hidden';
	
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	
	logEvent('A CSV report has been downloaded for: ' + (datasetType === 'data' ? 'Data' : 'Logs'));
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
		success: function(response) {
			console.log("Event logged successfully");
			loadLogs();
		},
		error: function(error) {
			console.error("Error logging event:", error);
		}
	});
}


function loadAuditTrail() {
	$.ajax({
		url: '../api.php?action=audit_trail',
		type: 'GET',
		dataType: 'json',
		success: function(data) {
			console.log("Audit trail loaded successfully:", data);
			allAuditTrail = data;
			displayAuditTrail(data);
		},
		error: function(error) {
			console.error("Error loading audit trail:", error);
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
	let tableHTML = `
		<table class="data-table">
			<thead>
				<tr>
					<th>ID</th>
					<th>Date</th>
					<th>Time</th>
					<th>Record ID</th>
					<th>Change Type</th>
					<th>Field Name</th>
					<th>Old Value</th>
					<th>New Value</th>
				</tr>
			</thead>
			<tbody>
	`;

	entries.forEach(function(entry) {
		tableHTML += `
			<tr>
				<td>${entry.id}</td>
				<td>${entry.date}</td>
				<td>${entry.time}</td>
				<td>${entry.record_id}</td>
				<td><span class="badge badge-${entry.change_type.toLowerCase()}">${entry.change_type}</span></td>
				<td>${entry.field_name}</td>
				<td><code>${entry.old_value || '(empty)'}</code></td>
				<td><code>${entry.new_value || '(empty)'}</code></td>
			</tr>
		`;
	});

	tableHTML += `
			</tbody>
		</table>
	`;

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
			timelineHTML += `
				<div class="timeline-group">
					<h3 class="timeline-date">${currentDate}</h3>
			`;
		}

		const typeClass = String(entry.change_type || '').toLowerCase();
		timelineHTML += `
			<div class="timeline-item">
				<div class="timeline-dot timeline-dot-${typeClass}"></div>
				<div class="timeline-card">
					<div class="timeline-card-header">
						<span class="badge badge-${typeClass}">${entry.change_type}</span>
						<span class="timeline-time">${entry.time}</span>
						<span class="timeline-id">#${entry.id}</span>
					</div>
					<p class="timeline-line"><strong>Record:</strong> ${entry.record_id} | <strong>Field:</strong> ${entry.field_name}</p>
					<p class="timeline-line"><strong>Old:</strong> <code>${entry.old_value || '(empty)'}</code></p>
					<p class="timeline-line"><strong>New:</strong> <code>${entry.new_value || '(empty)'}</code></p>
				</div>
			</div>
		`;
	});

	if (currentDate !== '') {
		timelineHTML += '</div>';
	}

	timelineHTML += '</div>';
	container.html(timelineHTML);
}


function displayAuditTrail(data) {
	renderAuditTrail(data.entries || [], 'No audit trail entries yet.');
}


function filterAuditTrail() {
	if (!allAuditTrail || !allAuditTrail.entries) return;
	
	const searchValue = $('#search-input').val().toLowerCase();
	
	let filteredEntries = allAuditTrail.entries.filter(function(entry) {
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
	
	renderAuditTrail(filteredEntries, 'No audit trail entries match your search.');
}

