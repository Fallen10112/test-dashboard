let currentFilteredItemCount = 0;
let currentTotalDataCount = 0;
let currentSortColumn = null;
let currentSortOrder = 'asc';
let selectedRecordIds = new Set();
let currentFilteredDataItems = [];
let currentPage = 1;
let pageSize = 25;
let currentPagedItems = [];
const virtualRowHeightPx = 52;
const virtualOverscanRows = 6;
const dataPageSizeStorageKey = 'data-page-size';


function setupDataPageHandlers() {
	const debouncedFilter = debounce(loadDataPage, 180);
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

	$('#search-input').on('input', function() {
		currentPage = 1;
		debouncedFilter();
	});

	$('#data-container').on('change', '#data-page-size', function() {
		const requested = parseInt($(this).val(), 10);
		const allowedPageSizes = [25, 50, 100];
		pageSize = !Number.isNaN(requested) && allowedPageSizes.indexOf(requested) !== -1 ? requested : 25;
		localStorage.setItem(dataPageSizeStorageKey, String(pageSize));
		currentPage = 1;
		loadDataPage();
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

	$('#data-container').on('scroll', '#data-table-viewport', function() {
		debouncedVirtualRender();
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

	$('.modal-close').on('click', closeModal);
	$('#modal-cancel').on('click', closeModal);
	$('#record-form').on('submit', function(e) {
		e.preventDefault();
		saveRecord();
	});
	$('#record-modal').on('click', function(e) {
		if (e.target.id === 'record-modal') {
			closeModal();
		}
	});

	updateBulkDeleteButtonState();
	updateFilteredExportButtonsState();
}


function initializeDataPagePreferences() {
	const storedValue = localStorage.getItem(dataPageSizeStorageKey);
	const parsed = parseInt(storedValue, 10);
	const allowedPageSizes = [25, 50, 100];
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


function loadDataPage(callback) {
	$.ajax({
		url: '../api.php',
		type: 'GET',
		dataType: 'json',
		data: buildDataPageRequestParams(),
		success: function(response) {
			renderDataTableView(response || {});
			if (typeof callback === 'function') {
				callback(response || {});
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
		}
	});
}


function updateFilteredExportButtonsState() {
	const hasRows = currentFilteredItemCount > 0;
	$('#export-filtered-pdf-btn').prop('disabled', !hasRows);
	$('#export-filtered-csv-btn').prop('disabled', !hasRows);
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


function openAddModal() {
	$('#modal-title').text('Add New Record');
	$('#record-form').attr('data-record-id', '');
	$('#record-form').attr('data-edit-mode', 'false');
	$('#record-title').val('');
	$('#record-description').val('');
	$('#record-modal').removeClass('hidden');
}


function openEditModal(id) {
	const item = currentPagedItems.find(function(entry) {
		return String(entry.id) === String(id);
	});
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


function saveRecord() {
	const id = $('#record-form').attr('data-record-id');
	const title = $('#record-title').val().trim();
	const description = $('#record-description').val().trim();
	const editMode = $('#record-form').attr('data-edit-mode') === 'true';
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
			closeModal();
			loadDataPage();
			loadHeaderMetrics();
			showToast({ type: 'success', title: 'Record Added', message: 'The new record has been added successfully.', showOkayButton: true, autoCloseMs: 3000 });
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
