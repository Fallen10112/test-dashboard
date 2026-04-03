
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


$(document).ready(function() {
	initializeTheme();
	setupResetButtonHandler();
	loadHeaderMetrics();

	if ($('#data-container').length > 0) {
		initializeDataPagePreferences();
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
	// Keep client-side "today" aligned with backend timestamp generation (fixed one-hour offset).
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
	const debouncedFilter = debounce(filterAndSortTable, 180);
	const debouncedVirtualRender = debounce(renderVirtualizedRows, 16);

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
		currentPage = 1;
		debouncedFilter();
	});

	$('#data-container').on('change', '#data-page-size', function() {
		const requested = parseInt($(this).val(), 10);
		pageSize = Number.isNaN(requested) ? 25 : Math.max(1, requested);
		localStorage.setItem(dataPageSizeStorageKey, String(pageSize));
		currentPage = 1;
		renderDataTableView();
	});

	$('#data-container').on('click', '.sortable', function() {
		const column = $(this).data('column');
		if (currentSortColumn === column) {
			currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
		} else {
			currentSortColumn = column;
			currentSortOrder = 'asc';
		}
		currentPage = 1;
		filterAndSortTable();
	});

	$('#data-container').on('click', '#data-page-prev', function() {
		if (currentPage > 1) {
			currentPage -= 1;
			renderDataTableView();
		}
	});

	$('#data-container').on('click', '#data-page-next', function() {
		const totalRows = currentFilteredDataItems.length;
		const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
		if (currentPage < totalPages) {
			currentPage += 1;
			renderDataTableView();
		}
	});

	$('#data-container').on('scroll', '#data-table-viewport', function() {
		debouncedVirtualRender();
	});

	$('#data-container').on('click', '.edit-btn', function() {
		const id = $(this).data('id');
		openEditModal(id);
	});

	$('#data-container').on('click', '.delete-btn', function() {
		const id = $(this).data('id');
		deleteRecord(id);
	});

	$('#data-container').on('change', '#select-all-records', function() {
		const isChecked = $(this).is(':checked');

		currentPagedItems.forEach(function(item) {
			const id = String(item.id);
			if (isChecked) {
				selectedRecordIds.add(id);
			} else {
				selectedRecordIds.delete(id);
			}
		});

		renderVirtualizedRows();
		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
	});

	$('#data-container').on('change', '.record-select-checkbox', function() {
		const id = String($(this).data('id'));
		if ($(this).is(':checked')) {
			selectedRecordIds.add(id);
		} else {
			selectedRecordIds.delete(id);
		}

		updateBulkDeleteButtonState();
		updateSelectAllCheckboxState();
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


function initializeDataPagePreferences() {
	const storedValue = localStorage.getItem(dataPageSizeStorageKey);
	const parsed = parseInt(storedValue, 10);
	const allowedPageSizes = [25, 50, 100];

	if (!Number.isNaN(parsed) && allowedPageSizes.indexOf(parsed) !== -1) {
		pageSize = parsed;
	}
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
	const $selectAll = $('#select-all-records');

	if ($selectAll.length === 0) {
		return;
	}

	if (!Array.isArray(currentPagedItems) || currentPagedItems.length === 0) {
		$selectAll.prop('checked', false);
		$selectAll.prop('indeterminate', false);
		return;
	}

	const checkedCount = currentPagedItems.reduce(function(total, item) {
		return total + (selectedRecordIds.has(String(item.id)) ? 1 : 0);
	}, 0);

	$selectAll.prop('checked', checkedCount === currentPagedItems.length);
	$selectAll.prop('indeterminate', checkedCount > 0 && checkedCount < currentPagedItems.length);
	$('.record-select-checkbox').each(function() {
		const id = String($(this).data('id'));
		$(this).prop('checked', selectedRecordIds.has(id));
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
							fieldName: 'record',
							oldValue: 'Title: "' + item.title + '", Description: "' + item.description + '"',
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
			refreshNextRecordId();
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


function ensureSearchIndex(items) {
	(items || []).forEach(function(item) {
		const title = String(item.title || '');
		const description = String(item.description || '');

		if (item.__searchTitleSource !== title) {
			item.__searchTitle = title.toLowerCase();
			item.__searchTitleSource = title;
		}

		if (item.__searchDescriptionSource !== description) {
			item.__searchDescription = description.toLowerCase();
			item.__searchDescriptionSource = description;
		}
	});
}


function getFilteredSortedItems() {
	if (!allData || !Array.isArray(allData.items)) {
		return [];
	}

	ensureSearchIndex(allData.items);
	const searchValue = String($('#search-input').val() || '').toLowerCase();

	let filteredItems = allData.items.filter(function(item) {
		return String(item.__searchTitle || '').includes(searchValue) ||
			String(item.__searchDescription || '').includes(searchValue);
	});

	if (currentSortColumn) {
		filteredItems = filteredItems.slice().sort(function(a, b) {
			let aVal = a[currentSortColumn];
			let bVal = b[currentSortColumn];

			if (currentSortColumn === 'id') {
				aVal = parseInt(aVal, 10);
				bVal = parseInt(bVal, 10);
			}

			if (currentSortOrder === 'asc') {
				return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
			}
			return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
		});
	}

	return filteredItems;
}


function createDataTableElement() {
	const table = document.createElement('table');
	table.className = 'data-table data-records-table virtualized-table';

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');

	const selectTh = document.createElement('th');
	selectTh.className = 'select-column';
	const selectAll = document.createElement('input');
	selectAll.type = 'checkbox';
	selectAll.id = 'select-all-records';
	selectAll.setAttribute('aria-label', 'Select all records');
	selectTh.appendChild(selectAll);
	headRow.appendChild(selectTh);

	function createSortableHeader(column, label) {
		const th = document.createElement('th');
		th.className = 'sortable' + (currentSortColumn === column ? ' sorted' : '');
		th.setAttribute('data-column', column);
		th.appendChild(document.createTextNode(label + ' '));

		const indicator = document.createElement('span');
		indicator.className = 'sort-indicator';
		if (currentSortColumn === column) {
			indicator.textContent = currentSortOrder === 'asc' ? '▲' : '▼';
		}
		th.appendChild(indicator);
		return th;
	}

	headRow.appendChild(createSortableHeader('id', 'ID'));
	headRow.appendChild(createSortableHeader('title', 'Title'));
	headRow.appendChild(createSortableHeader('description', 'Description'));

	const actionsTh = document.createElement('th');
	actionsTh.textContent = 'Actions';
	headRow.appendChild(actionsTh);

	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	tbody.id = 'data-table-body';
	table.appendChild(tbody);

	return table;
}


function createDataRowElement(item) {
	const row = document.createElement('tr');

	const selectTd = document.createElement('td');
	selectTd.className = 'select-column';
	const checkbox = document.createElement('input');
	checkbox.type = 'checkbox';
	checkbox.className = 'record-select-checkbox';
	checkbox.setAttribute('data-id', String(item.id));
	checkbox.setAttribute('aria-label', 'Select record ' + item.id);
	checkbox.checked = selectedRecordIds.has(String(item.id));
	selectTd.appendChild(checkbox);
	row.appendChild(selectTd);

	const idTd = document.createElement('td');
	idTd.textContent = item.id;
	row.appendChild(idTd);

	const titleTd = document.createElement('td');
	titleTd.textContent = item.title;
	row.appendChild(titleTd);

	const descriptionTd = document.createElement('td');
	descriptionTd.textContent = item.description;
	row.appendChild(descriptionTd);

	const actionsTd = document.createElement('td');
	const editBtn = document.createElement('button');
	editBtn.type = 'button';
	editBtn.className = 'btn btn-sm btn-secondary edit-btn';
	editBtn.setAttribute('data-id', String(item.id));
	editBtn.textContent = 'Edit';
	actionsTd.appendChild(editBtn);

	const deleteBtn = document.createElement('button');
	deleteBtn.type = 'button';
	deleteBtn.className = 'btn btn-sm btn-danger delete-btn';
	deleteBtn.setAttribute('data-id', String(item.id));
	deleteBtn.textContent = 'Delete';
	actionsTd.appendChild(deleteBtn);

	row.appendChild(actionsTd);
	return row;
}


function renderVirtualizedRows() {
	const viewport = document.getElementById('data-table-viewport');
	const tbody = document.getElementById('data-table-body');

	if (!viewport || !tbody) {
		return;
	}

	tbody.textContent = '';

	if (!Array.isArray(currentPagedItems) || currentPagedItems.length === 0) {
		return;
	}

	const viewportHeight = viewport.clientHeight || 520;
	const scrollTop = viewport.scrollTop;
	const totalRows = currentPagedItems.length;
	const startIndex = Math.max(0, Math.floor(scrollTop / virtualRowHeightPx) - virtualOverscanRows);
	const visibleCount = Math.ceil(viewportHeight / virtualRowHeightPx) + (virtualOverscanRows * 2);
	const endIndex = Math.min(totalRows, startIndex + visibleCount);

	const fragment = document.createDocumentFragment();

	if (startIndex > 0) {
		const topSpacer = document.createElement('tr');
		topSpacer.className = 'virtual-spacer-row';
		const topSpacerCell = document.createElement('td');
		topSpacerCell.colSpan = 5;
		topSpacerCell.style.height = String(startIndex * virtualRowHeightPx) + 'px';
		topSpacer.appendChild(topSpacerCell);
		fragment.appendChild(topSpacer);
	}

	for (let i = startIndex; i < endIndex; i += 1) {
		fragment.appendChild(createDataRowElement(currentPagedItems[i]));
	}

	if (endIndex < totalRows) {
		const bottomSpacer = document.createElement('tr');
		bottomSpacer.className = 'virtual-spacer-row';
		const bottomSpacerCell = document.createElement('td');
		bottomSpacerCell.colSpan = 5;
		bottomSpacerCell.style.height = String((totalRows - endIndex) * virtualRowHeightPx) + 'px';
		bottomSpacer.appendChild(bottomSpacerCell);
		fragment.appendChild(bottomSpacer);
	}

	tbody.appendChild(fragment);
}


function renderDataTableView() {
	syncSelectedRecordIdsWithData();
	const container = document.getElementById('data-container');
	if (!container) {
		return;
	}

	container.textContent = '';
	const filteredItems = getFilteredSortedItems();
	currentFilteredDataItems = filteredItems.slice();

	if (filteredItems.length === 0) {
		currentPagedItems = [];
		selectedRecordIds.clear();
		container.textContent = allData && Array.isArray(allData.items) && allData.items.length > 0
			? 'No records match your search.'
			: 'No data available.';
		updateBulkDeleteButtonState();
		updateFilteredExportButtonsState();
		return;
	}

	const totalRows = filteredItems.length;
	const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
	if (currentPage > totalPages) {
		currentPage = totalPages;
	}

	const pageStart = (currentPage - 1) * pageSize;
	const pageEnd = pageStart + pageSize;
	currentPagedItems = filteredItems.slice(pageStart, pageEnd);

	const viewport = document.createElement('div');
	viewport.id = 'data-table-viewport';
	viewport.className = 'data-table-viewport';
	viewport.appendChild(createDataTableElement());
	container.appendChild(viewport);

	const pagination = document.createElement('div');
	pagination.className = 'data-pagination';
	const summary = document.createElement('span');
	summary.className = 'data-pagination-summary';
	summary.textContent = 'Showing ' + (pageStart + 1) + '-' + (pageStart + currentPagedItems.length) + ' of ' + totalRows;

	const controls = document.createElement('div');
	controls.className = 'data-pagination-controls';

	const pageSizeGroup = document.createElement('div');
	pageSizeGroup.className = 'data-page-size-group';

	const pageSizeLabel = document.createElement('label');
	pageSizeLabel.className = 'data-page-size-label';
	pageSizeLabel.htmlFor = 'data-page-size';
	pageSizeLabel.textContent = 'Rows per page';

	const pageSizeSelect = document.createElement('select');
	pageSizeSelect.id = 'data-page-size';
	pageSizeSelect.className = 'data-page-size-select';
	['25', '50', '100'].forEach(function(sizeValue) {
		const option = document.createElement('option');
		option.value = sizeValue;
		option.textContent = sizeValue;
		if (parseInt(sizeValue, 10) === pageSize) {
			option.selected = true;
		}
		pageSizeSelect.appendChild(option);
	});

	pageSizeGroup.appendChild(pageSizeLabel);
	pageSizeGroup.appendChild(pageSizeSelect);

	const prevBtn = document.createElement('button');
	prevBtn.type = 'button';
	prevBtn.id = 'data-page-prev';
	prevBtn.className = 'btn btn-sm btn-secondary';
	prevBtn.disabled = currentPage <= 1;
	prevBtn.textContent = 'Previous';

	const pageLabel = document.createElement('span');
	pageLabel.className = 'data-page-label';
	pageLabel.textContent = 'Page ' + currentPage + ' of ' + totalPages;

	const nextBtn = document.createElement('button');
	nextBtn.type = 'button';
	nextBtn.id = 'data-page-next';
	nextBtn.className = 'btn btn-sm btn-secondary';
	nextBtn.disabled = currentPage >= totalPages;
	nextBtn.textContent = 'Next';

	controls.appendChild(pageSizeGroup);
	controls.appendChild(prevBtn);
	controls.appendChild(pageLabel);
	controls.appendChild(nextBtn);
	pagination.appendChild(summary);
	pagination.appendChild(controls);
	container.appendChild(pagination);

	renderVirtualizedRows();
	updateBulkDeleteButtonState();
	updateSelectAllCheckboxState();
	updateFilteredExportButtonsState();
}


function displayDataTable(data) {
	if (data && typeof data === 'object') {
		allData = data;
	}
	renderDataTableView();
}


function filterAndSortTable() {
	renderDataTableView();
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
	const exportContainer = document.createElement('div');
	const wrapper = document.createElement('div');
	wrapper.style.fontFamily = 'Arial, sans-serif';
	wrapper.style.color = '#2c3e50';

	const title = document.createElement('h2');
	title.style.marginBottom = '8px';
	title.textContent = 'Filtered Data Export';

	const generated = document.createElement('p');
	generated.style.margin = '0 0 12px';
	const generatedStrong = document.createElement('strong');
	generatedStrong.textContent = 'Generated:';
	generated.appendChild(generatedStrong);
	generated.appendChild(document.createTextNode(' ' + exportDate));

	const totalRows = document.createElement('p');
	totalRows.style.margin = '0 0 16px';
	const totalStrong = document.createElement('strong');
	totalStrong.textContent = 'Total Rows:';
	totalRows.appendChild(totalStrong);
	totalRows.appendChild(document.createTextNode(' ' + currentFilteredDataItems.length));

	const table = document.createElement('table');
	table.style.width = '100%';
	table.style.borderCollapse = 'collapse';

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	headRow.style.background = '#667eea';
	headRow.style.color = '#ffffff';
	['ID', 'Title', 'Description'].forEach(function(label) {
		const th = document.createElement('th');
		th.style.padding = '8px';
		th.style.border = '1px solid #d4dae6';
		th.style.textAlign = 'left';
		th.textContent = label;
		headRow.appendChild(th);
	});
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	const rowFragment = document.createDocumentFragment();
	currentFilteredDataItems.forEach(function(item) {
		const row = document.createElement('tr');
		[item.id, item.title, item.description].forEach(function(value) {
			const td = document.createElement('td');
			td.style.padding = '8px';
			td.style.border = '1px solid #d4dae6';
			td.style.textAlign = 'left';
			td.textContent = String(value);
			row.appendChild(td);
		});
		rowFragment.appendChild(row);
	});
	tbody.appendChild(rowFragment);
	table.appendChild(tbody);

	wrapper.appendChild(title);
	wrapper.appendChild(generated);
	wrapper.appendChild(totalRows);
	wrapper.appendChild(table);
	exportContainer.appendChild(wrapper);

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
	return $.ajax({
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
		return $.Deferred().resolve().promise();
	}

	return $.ajax({
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


function determineNextRecordId() {
	if (!Number.isInteger(nextRecordId) || nextRecordId < 1) {
		refreshNextRecordId();
	}

	const newId = nextRecordId;
	nextRecordId += 1;
	return newId;
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
	
	function finalizeSave(eventMessage, actionType) {
		saveDataToFileWithLog(eventMessage, actionType);
		closeModal();
		displayDataTable(allData);
	}

	if (editMode) {
		const index = allData.items.findIndex(i => i.id == id);
		if (index !== -1) {
			const oldTitle = allData.items[index].title;
			const oldDescription = allData.items[index].description;
			const auditEntries = [];

			if (oldTitle !== title) {
				auditEntries.push({
					changeType: 'EDIT',
					recordId: id,
					fieldName: 'title',
					oldValue: oldTitle,
					newValue: title
				});
			}
			if (oldDescription !== description) {
				auditEntries.push({
					changeType: 'EDIT',
					recordId: id,
					fieldName: 'description',
					oldValue: oldDescription,
					newValue: description
				});
			}

			allData.items[index].title = title;
			allData.items[index].description = description;

			const eventMessage = `An entry has been edited; ID ${id} with title: "${title}", and description: "${description}"`;

			if (auditEntries.length > 0) {
				addAuditTrailEntries(auditEntries).always(function() {
					finalizeSave(eventMessage, 'edit');
				});
			} else {
				finalizeSave(eventMessage, 'edit');
			}
		}
		return;
	}

	const newId = determineNextRecordId();

	allData.items.push({
		id: newId,
		title: title,
		description: description
	});

	const addEntries = [
		{
			changeType: 'ADD',
			recordId: newId,
			fieldName: 'title',
			oldValue: '',
			newValue: title
		},
		{
			changeType: 'ADD',
			recordId: newId,
			fieldName: 'description',
			oldValue: '',
			newValue: description
		}
	];

	const eventMessage = `A new entry has been added; ID ${newId} with title: "${title}", and description: "${description}"`;

	addAuditTrailEntries(addEntries).always(function() {
		finalizeSave(eventMessage, 'add');
	});
}


function deleteRecord(id) {
	const item = allData.items.find(i => i.id == id);
	if (!item) {
		return;
	}

	showToast({
		type: 'warning',
		title: 'Delete Record?',
		message: 'This will permanently delete record ID ' + item.id + '.',
		autoCloseMs: 0,
		buttons: [
			{
				label: 'Cancel',
				className: 'btn-secondary'
			},
			{
				label: 'Delete',
				className: 'btn-danger',
				onClick: function() {
					const eventMessage = `An entry has been deleted; ID ${item.id} with title: "${item.title}", and description: "${item.description}"`;

					addAuditTrailEntry('DELETE', id, 'record', 'Title: "' + item.title + '", Description: "' + item.description + '"', '')
						.always(function() {
							allData.items = allData.items.filter(item => item.id != id);
							selectedRecordIds.delete(String(id));
							saveDataToFileWithLog(eventMessage, 'delete', {
								successToast: {
									type: 'success',
									title: 'Record Deleted',
									message: 'The record has been deleted successfully.',
									showOkayButton: true,
									autoCloseMs: 3000
								}
							});
							displayDataTable(allData);
						});
				}
			}
		]
	});
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

			if ($('#report-container').length > 0) {
				loadLogs();
			}
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
	
	const container = document.getElementById('report-container');
	container.textContent = '';

	const totalRecords = allData.items.length;
	const reportDate = new Date().toLocaleDateString();

	const report = document.createElement('div');
	report.id = 'report-content';
	report.className = 'report';

	const header = document.createElement('div');
	header.className = 'report-header';

	const title = document.createElement('h3');
	title.textContent = 'Data Report';

	const generated = document.createElement('p');
	const generatedLabel = document.createElement('strong');
	generatedLabel.textContent = 'Generated:';
	generated.appendChild(generatedLabel);
	generated.appendChild(document.createTextNode(' ' + reportDate));

	const total = document.createElement('p');
	const totalLabel = document.createElement('strong');
	totalLabel.textContent = 'Total Records:';
	total.appendChild(totalLabel);
	total.appendChild(document.createTextNode(' ' + totalRecords));

	header.appendChild(title);
	header.appendChild(generated);
	header.appendChild(total);
	report.appendChild(header);

	const table = document.createElement('table');
	table.className = 'report-table';

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	['ID', 'Title', 'Description'].forEach(function(label) {
		const th = document.createElement('th');
		th.textContent = label;
		headRow.appendChild(th);
	});
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	const rowsFragment = document.createDocumentFragment();
	allData.items.forEach(function(item) {
		const row = document.createElement('tr');
		const idTd = document.createElement('td');
		idTd.textContent = item.id;
		const titleTd = document.createElement('td');
		titleTd.textContent = item.title;
		const descTd = document.createElement('td');
		descTd.textContent = item.description;
		row.appendChild(idTd);
		row.appendChild(titleTd);
		row.appendChild(descTd);
		rowsFragment.appendChild(row);
	});
	tbody.appendChild(rowsFragment);
	table.appendChild(tbody);
	report.appendChild(table);

	const footer = document.createElement('div');
	footer.className = 'report-footer';
	const footerText = document.createElement('p');
	footerText.textContent = 'End of Report';
	footer.appendChild(footerText);
	report.appendChild(footer);

	container.appendChild(report);
	$('#download-pdf-btn').removeClass('hidden');
	$('#download-csv-btn').removeClass('hidden');
}


function generateLogsReport() {
	if (!allLogs || !allLogs.logs) {
		alert('No logs available for report');
		return;
	}
	
	const container = document.getElementById('report-container');
	container.textContent = '';

	const totalLogs = allLogs.logs.length;
	const reportDate = new Date().toLocaleDateString();

	const report = document.createElement('div');
	report.id = 'report-content';
	report.className = 'report';

	const header = document.createElement('div');
	header.className = 'report-header';

	const title = document.createElement('h3');
	title.textContent = 'Logs Report';

	const generated = document.createElement('p');
	const generatedLabel = document.createElement('strong');
	generatedLabel.textContent = 'Generated:';
	generated.appendChild(generatedLabel);
	generated.appendChild(document.createTextNode(' ' + reportDate));

	const total = document.createElement('p');
	const totalLabel = document.createElement('strong');
	totalLabel.textContent = 'Total Log Entries:';
	total.appendChild(totalLabel);
	total.appendChild(document.createTextNode(' ' + totalLogs));

	header.appendChild(title);
	header.appendChild(generated);
	header.appendChild(total);
	report.appendChild(header);

	const table = document.createElement('table');
	table.className = 'report-table';

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	['ID', 'Date', 'Time', 'Event'].forEach(function(label) {
		const th = document.createElement('th');
		th.textContent = label;
		headRow.appendChild(th);
	});
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	const rowsFragment = document.createDocumentFragment();
	allLogs.logs.forEach(function(log) {
		const localTime = convertToLocalTime(log.time, log.date);
		const row = document.createElement('tr');
		const idTd = document.createElement('td');
		idTd.textContent = log.id;
		const dateTd = document.createElement('td');
		dateTd.textContent = log.date;
		const timeTd = document.createElement('td');
		timeTd.textContent = localTime;
		const eventTd = document.createElement('td');
		eventTd.textContent = log.event;
		row.appendChild(idTd);
		row.appendChild(dateTd);
		row.appendChild(timeTd);
		row.appendChild(eventTd);
		rowsFragment.appendChild(row);
	});
	tbody.appendChild(rowsFragment);
	table.appendChild(tbody);
	report.appendChild(table);

	const footer = document.createElement('div');
	footer.className = 'report-footer';
	const footerText = document.createElement('p');
	footerText.textContent = 'End of Report';
	footer.appendChild(footerText);
	report.appendChild(footer);

	container.appendChild(report);
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

// Scroll to Top Button Functionality
$(document).ready(function() {
	const scrollTopBtn = $('#scroll-to-top');
	const scrollThreshold = 300;

	// Show/hide scroll-to-top button based on scroll position
	$(window).scroll(function() {
		if ($('.main-content').scrollTop() > scrollThreshold) {
			scrollTopBtn.addClass('show');
		} else {
			scrollTopBtn.removeClass('show');
		}
	});

	// Scroll to top smoothly when button is clicked
	scrollTopBtn.on('click', function(e) {
		e.preventDefault();
		$('.main-content').animate({ scrollTop: 0 }, 'smooth');
	});
});


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
		const oldValue = String(entry.old_value || '');
		const newValue = String(entry.new_value || '');
		const valueDiff = buildInlineDiffMarkup(oldValue, newValue);

		tableHTML += `
			<tr>
				<td>${entry.id}</td>
				<td>${entry.date}</td>
				<td>${entry.time}</td>
				<td>${entry.record_id}</td>
				<td><span class="badge badge-${entry.change_type.toLowerCase()}">${entry.change_type}</span></td>
				<td>${entry.field_name}</td>
				<td>${valueDiff.oldMarkup}</td>
				<td>${valueDiff.newMarkup}</td>
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
		const oldValue = String(entry.old_value || '');
		const newValue = String(entry.new_value || '');
		const valueDiff = buildInlineDiffMarkup(oldValue, newValue);
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
					<p class="timeline-line"><strong>Old:</strong> ${valueDiff.oldMarkup}</p>
					<p class="timeline-line"><strong>New:</strong> ${valueDiff.newMarkup}</p>
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
		return {
			oldMarkup: '<code>(empty)</code>',
			newMarkup: '<code>(empty)</code>'
		};
	}

	if (oldText === newText) {
		const escaped = escapeHtml(oldText || '(empty)');
		return {
			oldMarkup: '<code>' + escaped + '</code>',
			newMarkup: '<code>' + escaped + '</code>'
		};
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

	const oldBody =
		(oldPrefix ? '<span class="diff-unchanged">' + oldPrefix + '</span>' : '') +
		(oldChanged ? '<span class="diff-removed">' + oldChanged + '</span>' : '') +
		(oldTail ? '<span class="diff-unchanged">' + oldTail + '</span>' : '');

	const newBody =
		(newPrefix ? '<span class="diff-unchanged">' + newPrefix + '</span>' : '') +
		(newChanged ? '<span class="diff-added">' + newChanged + '</span>' : '') +
		(newTail ? '<span class="diff-unchanged">' + newTail + '</span>' : '');

	return {
		oldMarkup: '<code>' + (oldBody || '(empty)') + '</code>',
		newMarkup: '<code>' + (newBody || '(empty)') + '</code>'
	};
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

