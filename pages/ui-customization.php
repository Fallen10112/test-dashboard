<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	<main class="main-content">
		<section class="content-section active" id="ui-customization-container">
			<h2>UI Customization</h2>
			<div class="account-card ui-customization-card">
				<h3>Header Metrics Visibility</h3>
				<p>Select which metric widgets are shown in the top-right header. Preferences are saved to your account and sync across devices.</p>

				<div id="ui-customization-status" hidden aria-live="polite"></div>

				<form id="ui-customization-form" class="account-form" autocomplete="off">
					<div class="widget-options-grid">
						<label class="widget-option-item" for="widget-total-entries">
							<input type="checkbox" id="widget-total-entries" name="total_entries" checked>
							<span class="widget-option-label">Entries</span>
						</label>

						<label class="widget-option-item" for="widget-total-edits">
							<input type="checkbox" id="widget-total-edits" name="total_edits" checked>
							<span class="widget-option-label">Edits</span>
						</label>

						<label class="widget-option-item" for="widget-adds-today">
							<input type="checkbox" id="widget-adds-today" name="adds_today" checked>
							<span class="widget-option-label">Adds Today</span>
						</label>

						<label class="widget-option-item" for="widget-deletes-today">
							<input type="checkbox" id="widget-deletes-today" name="deletes_today" checked>
							<span class="widget-option-label">Deletes Today</span>
						</label>

						<label class="widget-option-item" for="widget-local-time">
							<input type="checkbox" id="widget-local-time" name="local_time" checked>
							<span class="widget-option-label">Local Time</span>
						</label>
					</div>

					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="ui-customization-save-btn">Save Preferences</button>
					</div>
				</form>
			</div>
		</section>
	</main>

<?php include '../includes/footer.php'; ?>
