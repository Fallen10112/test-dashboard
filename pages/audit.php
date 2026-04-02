<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	
	<main class="main-content">
		<section class="content-section active">
			<h2>Audit Trail</h2>
			<div class="data-controls">
				<div class="search-container">
					<input type="text" id="search-input" class="search-box" placeholder="Search audit trail...">
				</div>
				<div class="audit-view-toggle" role="group" aria-label="Audit view mode">
					<button id="audit-view-table" class="btn btn-secondary btn-sm active" type="button">Table View</button>
					<button id="audit-view-timeline" class="btn btn-secondary btn-sm" type="button">Timeline View</button>
				</div>
			</div>
			<div id="audit-container" class="audit-container">Loading audit trail...</div>
		</section>
	</main>
	
	<?php include '../includes/footer.php'; ?>

