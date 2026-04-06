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
	const tableScroll = document.createElement('div');
	tableScroll.className = 'report-table-scroll';
	tableScroll.appendChild(table);
	report.appendChild(tableScroll);

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
	const tableScroll = document.createElement('div');
	tableScroll.className = 'report-table-scroll';
	tableScroll.appendChild(table);
	report.appendChild(tableScroll);

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
