<?php include '../includes/header.php'; ?>
<?php include '../includes/navigation.php'; ?>

	
	<main class="main-content">
		<section class="content-section active">
			<h2>Audit Trail</h2>
			<div class="data-controls audit-controls-row">
				<div class="search-container">
					<input type="text" id="search-input" class="search-box" placeholder="Search audit trail...">
				</div>
				<div class="form-group audit-filter-group">
					<select id="audit-record-type-filter" class="dataset-selector" aria-label="Filter by record type">
						<option value="">All Types</option>
						<option value="record">Data Records</option>
						<option value="notification">Notifications</option>
						<option value="auth">Authentication</option>
						<option value="user">Users</option>
						<option value="system">System</option>
					</select>
				</div>
				<div class="form-group audit-filter-group">
					<select id="audit-action-filter" class="dataset-selector" aria-label="Filter by action">
						<option value="">All Actions</option>
						<option value="create">Create</option>
						<option value="update">Update</option>
						<option value="delete">Delete</option>
						<option value="reset">Reset</option>
						<option value="login">Login</option>
						<option value="logout">Logout</option>
						<option value="password_change">Password Change</option>
						<option value="notification_sent">Notification Sent</option>
						<option value="notification_read">Notification Read</option>
						<option value="notification_deleted">Notification Deleted</option>
					</select>
				</div>
				<div class="audit-view-toggle" role="group" aria-label="Audit view mode">
					<button id="audit-view-table" class="btn btn-secondary btn-sm active" type="button">Table View</button>
					<button id="audit-view-timeline" class="btn btn-secondary btn-sm" type="button">Timeline View</button>
				</div>
			</div>
			<div id="audit-container" class="audit-container">Loading audit trail...</div>
		</section>
	</main>
	
	<button id="scroll-to-top" class="scroll-to-top" title="Back to top">↑ Top</button>

	<?php include '../includes/footer.php'; ?>

