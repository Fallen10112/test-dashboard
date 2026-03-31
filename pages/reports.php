<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>
	
	
	<main class="main-content">
		<section class="content-section active">
			<h2>Reports</h2>
			<div class="reports-controls">
				<div class="form-group">
					<label for="dataset-selector">Select Dataset:</label>
					<select id="dataset-selector" class="dataset-selector">
						<option value="data">Data</option>
						<option value="logs">Logs</option>
					</select>
				</div>
				<button id="generate-report-btn" class="btn btn-primary">Generate Report</button>
				<button id="download-pdf-btn" class="btn btn-success hidden">Download as PDF</button>
				<button id="download-csv-btn" class="btn btn-info hidden">Download as CSV</button>
			</div>
			<div id="report-container"></div>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>

