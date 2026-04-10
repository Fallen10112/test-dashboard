let currentFilteredItemCount = 0;
let currentTotalDataCount = 0;
let currentSortColumn = null;
let currentSortOrder = 'asc';
let selectedRecordIds = new Set();
let currentFilteredDataItems = [];
let currentPage = 1;
let pageSize = 25;
let currentPagedItems = [];
let currentCsvImportRows = [];
let currentCsvImportFileName = '';
let currentCsvImportHeaderDetected = false;
let currentCsvImportOverflowDetected = false;
let isCsvImportSubmitting = false;
const dataPageSizeStorageKey = 'data-page-size';
const csvImportMaxRows = 200;
const csvImportTemplateFilename = 'data-import-template.csv';
let dataRealtimePollTimerId = null;
let isDataRealtimePollInFlight = false;
let dataLastRefreshedTimeText = '--:--:--';
const dataPagePermissions = window.DASHBOARD_PAGE_PERMISSIONS || {};
const canCreateDataRecords = !!dataPagePermissions.create;
const canUpdateDataRecords = !!dataPagePermissions.update;
const canDeleteDataRecords = !!dataPagePermissions.delete;
const canExportDataRecords = !!dataPagePermissions.export;

function updateDataLastRefreshedTime() {
	const now = new Date();
	const hours = String(now.getHours()).padStart(2, '0');
	const minutes = String(now.getMinutes()).padStart(2, '0');
	const seconds = String(now.getSeconds()).padStart(2, '0');
	dataLastRefreshedTimeText = hours + ':' + minutes + ':' + seconds;
}


function getDataTableScrollTop() {
	const viewport = document.getElementById('data-table-viewport');
	return viewport ? viewport.scrollTop : 0;
}


function restoreDataTableScrollTop(scrollTop) {
	const viewport = document.getElementById('data-table-viewport');
	if (viewport) {
		viewport.scrollTop = scrollTop;
	}
}


function isAnyDataModalOpen() {
	return ($('#record-modal').length > 0 && !$('#record-modal').hasClass('hidden')) || ($('#csv-import-modal').length > 0 && !$('#csv-import-modal').hasClass('hidden'));
}


function resetRecordFormFields() {
	$('#record-form').attr('data-record-id', '');
	$('#record-form').attr('data-edit-mode', 'false');
	$('#record-title').val('');
	$('#record-description').val('');
}


function updateRecordModalActionButtons(isEditMode) {
	$('#save-and-add-another-btn').toggle(!isEditMode);
}


function setupDataPageHandlers() {
	const debouncedFilter = debounce(loadDataPage, 180);
	applyDataPagePermissionVisibility();

	if (canCreateDataRecords) {
		$('#add-record-btn').on('click', function() {
			openAddModal();
		});

		$('#import-csv-btn').on('click', function() {
			openCsvImportModal();
		});
	}

	if (canExportDataRecords) {
		$('#export-filtered-pdf-btn').on('click', function() {
			exportFilteredDataAsPDF();
		});

		$('#export-filtered-csv-btn').on('click', function() {
			exportFilteredDataAsCSV();
		});
	}

	if (canDeleteDataRecords) {
		$('#bulk-delete-btn').on('click', function() {
			bulkDeleteSelectedRecords();
		});
	}

	$('#search-input').on('input', function() {
		currentPage = 1;
		debouncedFilter();
	});

	$('#data-container').on('change', '#data-page-size', function() {
		const requested = parseInt($(this).val(), 10);
		const allowedPageSizes = [10, 20, 25, 50, 100];
		pageSize = !Number.isNaN(requested) && allowedPageSizes.indexOf(requested) !== -1 ? requested : 25;
		localStorage.setItem(dataPageSizeStorageKey, String(pageSize));
		currentPage = 1;
		loadDataPage();
	});

	$('#data-container').on('change', '#data-page-jump', function() {
		const totalPages = Math.max(1, $(this).find('option').length);
		const requestedPage = window.DashboardPagination.normalizePageValue($(this).val(), totalPages, currentPage);
		if (requestedPage !== currentPage) {
			currentPage = requestedPage;
			loadDataPage();
		}
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
		loadDataPage();
	});

	$('#data-container').on('click', '#data-page-prev', function() {
		if (currentPage > 1) {
			currentPage -= 1;
			loadDataPage();
		}
	});

	$('#data-container').on('click', '#data-page-next', function() {
		const totalPages = Math.max(1, Math.ceil(currentFilteredItemCount / pageSize));
		if (currentPage < totalPages) {
			currentPage += 1;
			loadDataPage();
		}
	});

	$('#data-container').on('click', '.edit-btn', function() {
		openEditModal($(this).data('id'));
	});

	$('#data-container').on('click', '.delete-btn', function() {
		deleteRecord($(this).data('id'));
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

	$('.modal-close').on('click', closeDataModals);
	$('#modal-cancel').on('click', closeModal);
	if (canCreateDataRecords || canUpdateDataRecords) {
		$('#save-and-add-another-btn').on('click', function() {
			saveRecord({ keepOpen: true });
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
	}
	if (canCreateDataRecords) {
		$('#csv-import-download-template-btn').on('click', function() {
			downloadCsvImportTemplate();
		});
		$('#csv-import-file-input').on('change', function() {
			const file = this.files && this.files[0] ? this.files[0] : null;
			handleCsvImportFileSelect(file);
		});
		$('#csv-import-submit-btn').on('click', function() {
			submitCsvImportRecords();
		});
		$('#csv-import-cancel-btn').on('click', function() {
			closeCsvImportModal();
		});
		$('#csv-import-modal').on('click', function(e) {
			if (e.target.id === 'csv-import-modal') {
				closeCsvImportModal();
			}
		});
	}

	updateBulkDeleteButtonState();
	updateFilteredExportButtonsState();
}


function initializeDataPagePreferences() {
	const storedValue = localStorage.getItem(dataPageSizeStorageKey);
	const parsed = parseInt(storedValue, 10);
	const allowedPageSizes = [10, 20, 25, 50, 100];
	if (!Number.isNaN(parsed) && allowedPageSizes.indexOf(parsed) !== -1) {
		pageSize = parsed;
	}
	if (!currentSortColumn) {
		currentSortColumn = 'id';
	}
	if (!currentSortOrder) {
		currentSortOrder = 'asc';
	}
}


function buildDataPageRequestParams() {
	return {
		action: 'data_page',
		search: String($('#search-input').val() || '').trim(),
		sortColumn: currentSortColumn || 'id',
		sortOrder: currentSortOrder || 'asc',
		page: currentPage,
		pageSize: pageSize
	};
}


function loadDataPage(callback, onComplete) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
		data: buildDataPageRequestParams(),
		success: function(response) {
			updateDataLastRefreshedTime();
			renderDataTableView(response || {});
			if (typeof callback === 'function') {
				callback(response || {});
			}
			if (typeof onComplete === 'function') {
				onComplete();
			}
		},
		error: function() {
			$('#data-container').html('<p class="error">Error loading data. Please refresh the page.</p>');
			currentPagedItems = [];
			currentFilteredDataItems = [];
			currentFilteredItemCount = 0;
			currentTotalDataCount = 0;
			updateBulkDeleteButtonState();
			updateFilteredExportButtonsState();
			if (typeof onComplete === 'function') {
				onComplete();
			}
		}
	});
}


function startDataPageRealtimeSync() {
	if (dataRealtimePollTimerId !== null) {
		clearInterval(dataRealtimePollTimerId);
		dataRealtimePollTimerId = null;
	}

	const poll = function() {
		if (document.visibilityState === 'hidden') {
			return;
		}
		if (isDataRealtimePollInFlight) {
			return;
		}
		if (isAnyDataModalOpen()) {
			return;
		}

		const scrollTop = getDataTableScrollTop();
		isDataRealtimePollInFlight = true;
		loadDataPage(function() {
			if (typeof loadHeaderMetrics === 'function') {
				loadHeaderMetrics();
			}
		}, function() {
			restoreDataTableScrollTop(scrollTop);
			isDataRealtimePollInFlight = false;
		});
	};

	dataRealtimePollTimerId = setInterval(poll, 10000);

	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'visible') {
			poll();
		}
	});
}


function updateFilteredExportButtonsState() {
	if (!canExportDataRecords) {
		$('#export-filtered-pdf-btn').addClass('hidden');
		$('#export-filtered-csv-btn').addClass('hidden');
		return;
	}
	const hasRows = currentFilteredItemCount > 0;
	$('#export-filtered-pdf-btn').prop('disabled', !hasRows);
	$('#export-filtered-csv-btn').prop('disabled', !hasRows);
}


function updateBulkDeleteButtonState() {
	if (!canDeleteDataRecords) {
		$('#bulk-delete-btn').addClass('hidden');
		return;
	}
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


function performDataMutation(payload, onSuccess, errorMessage) {
	$.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify(payload),
		success: function(response) {
			if (response && response.success === false) {
				showToast({ type: 'error', title: 'Action Failed', message: response.message || errorMessage || 'The request could not be completed.', showOkayButton: true, autoCloseMs: 3000 });
				return;
			}
			if (typeof onSuccess === 'function') {
				onSuccess(response || {});
			}
		},
		error: function(xhr) {
			if (typeof handleSessionAuthFailure === 'function' && handleSessionAuthFailure(xhr)) {
				return;
			}
			const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
			showToast({ type: 'error', title: 'Action Failed', message: (response && response.message) || errorMessage || 'The request could not be completed.', showOkayButton: true, autoCloseMs: 3000 });
		}
	});
}


function bulkDeleteSelectedRecords() {
	const selectedIds = Array.from(selectedRecordIds);
	if (selectedIds.length === 0) {
		showToast({ type: 'warning', title: 'No Records Selected', message: 'Select at least one record to use bulk delete.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	showToast({
		type: 'warning',
		title: 'Delete Selected Records?',
		message: 'This will permanently delete ' + selectedIds.length + ' selected record(s).',
		autoCloseMs: 0,
		buttons: [
			{ label: 'Cancel', className: 'btn-secondary' },
			{
				label: 'Delete Selected',
				className: 'btn-danger',
				onClick: function() {
					performDataMutation(
						{ action: 'data_bulk_delete', ids: selectedIds },
						function(response) {
							selectedRecordIds.clear();
							loadDataPage();
							loadHeaderMetrics();
							showToast({
								type: 'success',
								title: 'Bulk Delete Complete',
								message: String(response.deletedCount || selectedIds.length) + ' record(s) deleted successfully.',
								showOkayButton: true,
								autoCloseMs: 3000
							});
						},
						'Error deleting selected records.'
					);
				}
			}
		]
	});
}


function createDataTableElement() {
	const table = document.createElement('table');
	table.className = 'data-table data-records-table virtualized-table';
	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	if (canDeleteDataRecords) {
		const selectTh = document.createElement('th');
		selectTh.className = 'select-column';
		const selectAll = document.createElement('input');
		selectAll.type = 'checkbox';
		selectAll.id = 'select-all-records';
		selectAll.setAttribute('aria-label', 'Select all records');
		selectTh.appendChild(selectAll);
		headRow.appendChild(selectTh);
	}

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
	if (canUpdateDataRecords || canDeleteDataRecords) {
		const actionsTh = document.createElement('th');
		actionsTh.textContent = 'Actions';
		headRow.appendChild(actionsTh);
	}
	thead.appendChild(headRow);
	table.appendChild(thead);
	const tbody = document.createElement('tbody');
	tbody.id = 'data-table-body';
	table.appendChild(tbody);
	return table;
}


function createDataRowElement(item) {
	const row = document.createElement('tr');
	if (canDeleteDataRecords) {
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
	}

	const idTd = document.createElement('td');
	idTd.textContent = item.id;
	row.appendChild(idTd);
	const titleTd = document.createElement('td');
	titleTd.textContent = item.title;
	row.appendChild(titleTd);
	const descriptionTd = document.createElement('td');
	descriptionTd.textContent = item.description;
	row.appendChild(descriptionTd);

	if (canUpdateDataRecords || canDeleteDataRecords) {
		const actionsTd = document.createElement('td');
		if (canUpdateDataRecords) {
			const editBtn = document.createElement('button');
			editBtn.type = 'button';
			editBtn.className = 'btn btn-sm btn-secondary edit-btn';
			editBtn.setAttribute('data-id', String(item.id));
			editBtn.textContent = 'Edit';
			actionsTd.appendChild(editBtn);
		}
		if (canDeleteDataRecords) {
			const deleteBtn = document.createElement('button');
			deleteBtn.type = 'button';
			deleteBtn.className = 'btn btn-sm btn-danger delete-btn';
			deleteBtn.setAttribute('data-id', String(item.id));
			deleteBtn.textContent = 'Delete';
			actionsTd.appendChild(deleteBtn);
		}
		row.appendChild(actionsTd);
	}
	return row;
}


function applyDataPagePermissionVisibility() {
	if (!canCreateDataRecords) {
		$('#add-record-btn').addClass('hidden');
		$('#import-csv-btn').addClass('hidden');
		$('#csv-import-modal').remove();
	}
	if (!canCreateDataRecords && !canUpdateDataRecords) {
		$('#record-modal').remove();
	}
	if (!canDeleteDataRecords) {
		$('#bulk-delete-btn').addClass('hidden');
	}
	if (!canExportDataRecords) {
		$('#export-filtered-pdf-btn').addClass('hidden');
		$('#export-filtered-csv-btn').addClass('hidden');
	}
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
	const fragment = document.createDocumentFragment();
	currentPagedItems.forEach(function(item) {
		fragment.appendChild(createDataRowElement(item));
	});

	tbody.appendChild(fragment);
}


function renderDataTableView(response) {
	const container = document.getElementById('data-container');
	if (!container) {
		return;
	}

	const payload = response && typeof response === 'object' ? response : {};
	currentPage = parseInt(payload.page, 10) || 1;
	pageSize = parseInt(payload.pageSize, 10) || pageSize;
	currentFilteredItemCount = parseInt(payload.filteredCount, 10) || 0;
	currentTotalDataCount = parseInt(payload.totalCount, 10) || 0;
	currentSortColumn = payload.sortColumn || currentSortColumn || 'id';
	currentSortOrder = payload.sortOrder || currentSortOrder || 'asc';
	currentPagedItems = Array.isArray(payload.items) ? payload.items.slice() : [];
	currentFilteredDataItems = currentPagedItems.slice();

	container.textContent = '';

	if (currentFilteredItemCount === 0) {
		currentPagedItems = [];
		currentFilteredDataItems = [];
		if (currentTotalDataCount === 0) {
			selectedRecordIds.clear();
		}
		container.textContent = currentTotalDataCount > 0 ? 'No records match your search.' : 'No data available.';
		updateBulkDeleteButtonState();
		updateFilteredExportButtonsState();
		return;
	}

	const pageStart = (currentPage - 1) * pageSize;
	const totalPages = Math.max(1, parseInt(payload.totalPages, 10) || Math.ceil(currentFilteredItemCount / pageSize));

	const viewport = document.createElement('div');
	viewport.id = 'data-table-viewport';
	viewport.className = 'data-table-viewport';
	viewport.appendChild(createDataTableElement());
	container.appendChild(viewport);

	const pagination = document.createElement('div');
	pagination.className = 'data-pagination';
	const summary = document.createElement('span');
	summary.className = 'data-pagination-summary';
	summary.textContent = 'Showing ' + (pageStart + 1) + '-' + (pageStart + currentPagedItems.length) + ' of ' + currentFilteredItemCount;

	const controlsWrap = document.createElement('div');
	controlsWrap.className = 'data-pagination-controls-wrap';

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
	['10', '20', '25', '50', '100'].forEach(function(sizeValue) {
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

	const pageJumpGroup = document.createElement('div');
	pageJumpGroup.className = 'data-page-jump-group';
	const pageJumpLabel = document.createElement('label');
	pageJumpLabel.className = 'data-page-jump-label';
	pageJumpLabel.htmlFor = 'data-page-jump';
	pageJumpLabel.textContent = 'Jump to';
	const pageJumpSelect = document.createElement('select');
	pageJumpSelect.id = 'data-page-jump';
	pageJumpSelect.className = 'data-page-size-select data-page-jump-select';
	window.DashboardPagination.populateSelect(pageJumpSelect, totalPages, currentPage);
	pageJumpSelect.disabled = totalPages <= 1;
	pageJumpGroup.appendChild(pageJumpLabel);
	pageJumpGroup.appendChild(pageJumpSelect);

	controls.appendChild(pageSizeGroup);
	controls.appendChild(prevBtn);
	controls.appendChild(pageLabel);
	controls.appendChild(nextBtn);
	controls.appendChild(pageJumpGroup);

	const refreshStatus = document.createElement('div');
	refreshStatus.className = 'data-refresh-status';
	refreshStatus.textContent = 'Last refreshed ' + dataLastRefreshedTimeText;

	controlsWrap.appendChild(controls);
	controlsWrap.appendChild(refreshStatus);
	pagination.appendChild(summary);
	pagination.appendChild(controlsWrap);
	container.appendChild(pagination);

	renderVirtualizedRows();
	updateBulkDeleteButtonState();
	updateSelectAllCheckboxState();
	updateFilteredExportButtonsState();
}


function displayDataTable(data) {
	const items = data && Array.isArray(data.items) ? data.items : [];
	renderDataTableView({
		items: items,
		page: 1,
		pageSize: pageSize,
		totalPages: 1,
		totalCount: items.length,
		filteredCount: items.length,
		sortColumn: currentSortColumn || 'id',
		sortOrder: currentSortOrder || 'asc'
	});
}


function filterAndSortTable() {
	loadDataPage();
}


function exportFilteredDataAsCSV() {
	if (currentFilteredItemCount === 0) {
		showToast({ type: 'warning', title: 'No Filtered Rows', message: 'There are no visible rows to export.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	fetchFilteredItemsForExport(function(filteredItems) {
		let csv = 'ID,Title,Description\n';
		filteredItems.forEach(function(item) {
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

		logEvent('A filtered CSV export has been downloaded for: Data (' + filteredItems.length + ' rows)');
		showToast({ type: 'success', title: 'CSV Export Complete', message: 'Filtered Data CSV downloaded successfully.', showOkayButton: true, autoCloseMs: 3000 });
	});
}


function exportFilteredDataAsPDF() {
	if (currentFilteredItemCount === 0) {
		showToast({ type: 'warning', title: 'No Filtered Rows', message: 'There are no visible rows to export.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	if (typeof html2pdf === 'undefined') {
		showToast({ type: 'error', title: 'PDF Export Unavailable', message: 'PDF export library is not loaded on this page.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	fetchFilteredItemsForExport(function(filteredItems) {
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
		totalRows.appendChild(document.createTextNode(' ' + filteredItems.length));

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
		filteredItems.forEach(function(item) {
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
		logEvent('A filtered PDF export has been downloaded for: Data (' + filteredItems.length + ' rows)');
		showToast({ type: 'success', title: 'PDF Export Complete', message: 'Filtered Data PDF downloaded successfully.', showOkayButton: true, autoCloseMs: 3000 });
	});
}


function fetchFilteredItemsForExport(callback) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
		data: {
			action: 'data_filtered_export',
			search: String($('#search-input').val() || '').trim(),
			sortColumn: currentSortColumn || 'id',
			sortOrder: currentSortOrder || 'asc',
			page: currentPage,
			pageSize: pageSize
		},
		success: function(response) {
			const filteredItems = response && Array.isArray(response.filteredItems) ? response.filteredItems : [];
			if (filteredItems.length === 0) {
				showToast({ type: 'warning', title: 'No Filtered Rows', message: 'There are no visible rows to export.', showOkayButton: true, autoCloseMs: 3000 });
				return;
			}
			callback(filteredItems);
		},
		error: function() {
			showToast({ type: 'error', title: 'Export Failed', message: 'Unable to load filtered rows for export.', showOkayButton: true, autoCloseMs: 3000 });
		}
	});
}


function closeDataModals() {
	$('#record-modal').addClass('hidden');
	$('#csv-import-modal').addClass('hidden');
	isCsvImportSubmitting = false;
	updateCsvImportPreview();
}


function resetCsvImportState() {
	currentCsvImportRows = [];
	currentCsvImportFileName = '';
	currentCsvImportHeaderDetected = false;
	currentCsvImportOverflowDetected = false;
	isCsvImportSubmitting = false;
	const fileInput = document.getElementById('csv-import-file-input');
	if (fileInput) {
		fileInput.value = '';
	}
	updateCsvImportPreview();
}


function openCsvImportModal() {
	closeDataModals();
	resetCsvImportState();
	$('#csv-import-modal').removeClass('hidden');
	setTimeout(function() {
		$('#csv-import-file-input').trigger('focus');
	}, 0);
}


function closeCsvImportModal() {
	$('#csv-import-modal').addClass('hidden');
	isCsvImportSubmitting = false;
}


function downloadTextFile(content, filename, mimeType) {
	const blob = new Blob([content], { type: mimeType || 'text/plain;charset=utf-8;' });
	const link = document.createElement('a');
	const url = URL.createObjectURL(blob);
	link.setAttribute('href', url);
	link.setAttribute('download', filename);
	link.style.visibility = 'hidden';
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	setTimeout(function() {
		URL.revokeObjectURL(url);
	}, 0);
}


function downloadCsvImportTemplate() {
	const templateCsv = 'title,description\nSample title,Sample description\n';
	downloadTextFile(templateCsv, csvImportTemplateFilename, 'text/csv;charset=utf-8;');
	showToast({ type: 'success', title: 'Template Downloaded', message: 'The CSV template has been downloaded.', showOkayButton: true, autoCloseMs: 3000 });
}


function parseCsvRows(rawText) {
	const text = String(rawText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
	const rows = [];
	let row = [];
	let field = '';
	let inQuotes = false;

	for (let index = 0; index < text.length; index += 1) {
		const character = text[index];
		if (inQuotes) {
			if (character === '"') {
				if (text[index + 1] === '"') {
					field += '"';
					index += 1;
				} else {
					inQuotes = false;
				}
			} else {
				field += character;
			}
			continue;
		}

		if (character === '"') {
			inQuotes = true;
			continue;
		}

		if (character === ',') {
			row.push(field);
			field = '';
			continue;
		}

		if (character === '\n') {
			row.push(field);
			rows.push(row);
			row = [];
			field = '';
			continue;
		}

		field += character;
	}

	row.push(field);
	rows.push(row);
	return rows;
}


function isCsvImportHeaderRow(row) {
	if (!Array.isArray(row) || row.length < 2) {
		return false;
	}
	const first = String(row[0] || '').trim().toLowerCase();
	const second = String(row[1] || '').trim().toLowerCase();
	return first === 'title' && second === 'description';
}


function buildCsvImportState(rawText) {
	const parsedRows = parseCsvRows(rawText);
	const rows = [];
	let headerDetected = false;
	let overflowDetected = false;

	parsedRows.forEach(function(csvRow) {
		if (overflowDetected) {
			return;
		}
		if (!Array.isArray(csvRow) || csvRow.every(function(cell) {
			return String(cell || '').trim() === '';
		})) {
			return;
		}

		if (rows.length === 0 && isCsvImportHeaderRow(csvRow)) {
			headerDetected = true;
			return;
		}

		if (rows.length >= csvImportMaxRows) {
			overflowDetected = true;
			return;
		}

		const title = String(csvRow[0] || '').trim();
		const description = String(csvRow[1] || '').trim();
		let valid = true;
		let message = '';

		if (csvRow.length !== 2) {
			valid = false;
			message = 'Each row must have exactly 2 columns: title and description.';
		} else if (title === '' || description === '') {
			valid = false;
			message = 'Title and description are required.';
		} else if (title.length > 255) {
			valid = false;
			message = 'Title must be 255 characters or fewer.';
		}

		rows.push({
			rowNumber: rows.length + 1,
			title: title,
			description: description,
			valid: valid,
			message: message
		});
	});

	return {
		rows: rows,
		headerDetected: headerDetected,
		overflowDetected: overflowDetected
	};
}


function handleCsvImportFileSelect(file) {
	if (!file) {
		resetCsvImportState();
		return;
	}

	currentCsvImportFileName = file.name || 'selected.csv';
	const reader = new FileReader();
	reader.onload = function() {
		const parsed = buildCsvImportState(String(reader.result || ''));
		currentCsvImportRows = parsed.rows;
		currentCsvImportHeaderDetected = parsed.headerDetected;
		currentCsvImportOverflowDetected = parsed.overflowDetected;
		updateCsvImportPreview();
	};
	reader.onerror = function() {
		showToast({ type: 'error', title: 'CSV Read Failed', message: 'The selected file could not be read.', showOkayButton: true, autoCloseMs: 3000 });
		resetCsvImportState();
	};
	reader.readAsText(file);
}


function updateCsvImportPreview() {
	const $summary = $('#csv-import-summary');
	const $preview = $('#csv-import-preview');
	const $submitButton = $('#csv-import-submit-btn');

	if ($summary.length === 0 || $preview.length === 0 || $submitButton.length === 0) {
		return;
	}

	const validRows = currentCsvImportRows.filter(function(row) {
		return row.valid;
	});
	const invalidRows = currentCsvImportRows.length - validRows.length;
	const canImport = currentCsvImportRows.length > 0 && invalidRows === 0 && currentCsvImportOverflowDetected === false && isCsvImportSubmitting === false;

	let summaryText = currentCsvImportFileName !== '' ? 'Previewing ' + currentCsvImportRows.length + ' row(s) from "' + currentCsvImportFileName + '".' : 'No CSV file selected yet.';
	if (currentCsvImportRows.length > 0) {
		if (currentCsvImportHeaderDetected) {
			summaryText += ' Header row detected and skipped.';
		}
		if (invalidRows > 0) {
			summaryText += ' ' + invalidRows + ' row(s) need fixes before import.';
		}
		if (currentCsvImportOverflowDetected) {
			summaryText += ' Only the first ' + csvImportMaxRows + ' row(s) can be imported at once.';
		}
	}

	$summary.text(summaryText);
	$submitButton.prop('disabled', !canImport);
	$submitButton.text(isCsvImportSubmitting ? 'Importing...' : 'Import CSV');

	$preview.empty();
	if (currentCsvImportRows.length === 0) {
		$preview.append($('<div>').addClass('csv-import-preview-empty').text('Upload a CSV file to preview the parsed rows here.'));
		return;
	}

	const $table = $('<table>').addClass('csv-import-table');
	const $thead = $('<thead>');
	const $headRow = $('<tr>');
	['Row', 'Title', 'Description', 'Status'].forEach(function(label) {
		$headRow.append($('<th>').text(label));
	});
	$thead.append($headRow);
	$table.append($thead);

	const $tbody = $('<tbody>');
	currentCsvImportRows.forEach(function(row) {
		const $tr = $('<tr>').addClass(row.valid ? 'is-valid' : 'is-invalid');
		$tr.append($('<td>').text(String(row.rowNumber)));
		$tr.append($('<td>').text(row.title || ''));
		$tr.append($('<td>').text(row.description || ''));
		$tr.append($('<td>').append(
			$('<span>').addClass('csv-import-preview-status ' + (row.valid ? 'csv-import-status-valid' : 'csv-import-status-invalid')).text(row.valid ? 'Ready' : row.message)
		));
		$tbody.append($tr);
	});
	$table.append($tbody);
	$preview.append($table);
}


function submitCsvImportRecords() {
	if (isCsvImportSubmitting) {
		return;
	}

	const validRows = currentCsvImportRows.filter(function(row) {
		return row.valid;
	});

	if (validRows.length === 0) {
		showToast({ type: 'warning', title: 'No Valid Rows', message: 'Upload a CSV with at least one valid title and description row.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	if (currentCsvImportRows.length !== validRows.length || currentCsvImportOverflowDetected) {
		showToast({ type: 'warning', title: 'Fix CSV Rows First', message: 'All rows must be valid before importing.', showOkayButton: true, autoCloseMs: 3000 });
		return;
	}

	isCsvImportSubmitting = true;
	updateCsvImportPreview();

	$.ajax({
		url: '../api.php',
		type: 'POST',
		contentType: 'application/json',
		dataType: 'json',
		data: JSON.stringify({
			action: 'data_bulk_create',
			items: validRows.map(function(row) {
				return {
					title: row.title,
					description: row.description
				};
			})
		}),
		success: function(response) {
			isCsvImportSubmitting = false;
			if (response && response.success === false) {
				updateCsvImportPreview();
				showToast({ type: 'error', title: 'CSV Import Failed', message: response.message || 'The CSV import could not be completed.', showOkayButton: true, autoCloseMs: 3000 });
				return;
			}

			const createdCount = parseInt(response && response.createdCount, 10) || validRows.length;
			closeCsvImportModal();
			loadDataPage();
			loadHeaderMetrics();
			showToast({ type: 'success', title: 'CSV Import Complete', message: createdCount + ' record(s) imported successfully.', showOkayButton: true, autoCloseMs: 3000 });
		},
		error: function(xhr) {
			isCsvImportSubmitting = false;
			updateCsvImportPreview();
			if (typeof handleSessionAuthFailure === 'function' && handleSessionAuthFailure(xhr)) {
				return;
			}
			const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
			showToast({ type: 'error', title: 'CSV Import Failed', message: (response && response.message) || 'The CSV import could not be completed.', showOkayButton: true, autoCloseMs: 3000 });
		}
	});
}


function openAddModal() {
	closeDataModals();
	$('#modal-title').text('Add New Record');
	resetRecordFormFields();
	updateRecordModalActionButtons(false);
	$('#record-modal').removeClass('hidden');
	setTimeout(function() {
		$('#record-title').trigger('focus');
	}, 0);
}


function openEditModal(id) {
	closeDataModals();
	const item = currentPagedItems.find(function(entry) {
		return String(entry.id) === String(id);
	});
	if (item) {
		$('#modal-title').text('Edit Record');
		$('#record-form').attr('data-record-id', item.id);
		$('#record-form').attr('data-edit-mode', 'true');
		$('#record-title').val(item.title);
		$('#record-description').val(item.description);
		updateRecordModalActionButtons(true);
		$('#record-modal').removeClass('hidden');
	}
}


function closeModal() {
	closeDataModals();
}


function saveRecord(options) {
	const keepOpen = options && options.keepOpen === true;
	const id = $('#record-form').attr('data-record-id');
	const title = $('#record-title').val().trim();
	const description = $('#record-description').val().trim();
	const editMode = $('#record-form').attr('data-edit-mode') === 'true';
	const shouldKeepOpen = keepOpen && !editMode;
	if (title.length < 1 || description.length < 1) {
		alert('Title and description are required (minimum 1 character)');
		return;
	}

	if (editMode) {
		performDataMutation(
			{ action: 'data_update', id: id, title: title, description: description },
			function() {
				closeModal();
				loadDataPage();
				loadHeaderMetrics();
				showToast({ type: 'success', title: 'Record Updated', message: 'The record has been edited successfully.', showOkayButton: true, autoCloseMs: 3000 });
			},
			'Error updating record.'
		);
		return;
	}

	performDataMutation(
		{ action: 'data_create', title: title, description: description },
		function() {
			if (shouldKeepOpen) {
				resetRecordFormFields();
				updateRecordModalActionButtons(false);
				setTimeout(function() {
					$('#record-title').trigger('focus');
				}, 0);
			} else {
				closeModal();
			}
			loadDataPage();
			loadHeaderMetrics();
			showToast({
				type: 'success',
				title: 'Record Added',
				message: shouldKeepOpen ? 'The new record has been added. You can enter another one now.' : 'The new record has been added successfully.',
				showOkayButton: true,
				autoCloseMs: 3000
			});
		},
		'Error creating record.'
	);
}


function deleteRecord(id) {
	const item = currentPagedItems.find(function(entry) {
		return String(entry.id) === String(id);
	});
	if (!item) {
		return;
	}
	showToast({
		type: 'warning',
		title: 'Delete Record?',
		message: 'This will permanently delete record ID ' + item.id + '.',
		autoCloseMs: 0,
		buttons: [
			{ label: 'Cancel', className: 'btn-secondary' },
			{
				label: 'Delete',
				className: 'btn-danger',
				onClick: function() {
					performDataMutation(
						{ action: 'data_delete', id: id },
						function() {
							selectedRecordIds.delete(String(id));
							loadDataPage();
							loadHeaderMetrics();
							showToast({ type: 'success', title: 'Record Deleted', message: 'The record has been deleted successfully.', showOkayButton: true, autoCloseMs: 3000 });
						},
						'Error deleting record.'
					);
				}
			}
		]
	});
}
