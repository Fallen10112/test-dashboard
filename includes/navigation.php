	
	<nav class="sidebar">
		<ul class="nav-menu">
			<li><a href="../pages/home.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'home.php') ? 'active' : ''; ?>">Home</a></li>
			<li><a href="../pages/data.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'data.php') ? 'active' : ''; ?>">Data</a></li>
			<li><a href="../pages/reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'reports.php') ? 'active' : ''; ?>">Reports</a></li>
			<li><a href="../pages/audit.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'audit.php') ? 'active' : ''; ?>">Audit Trail</a></li>
		</ul>
		
		
		<div class="theme-toggle-container">
			<label class="theme-toggle">
				<input type="checkbox" id="theme-toggle-checkbox" aria-label="Toggle dark mode">
				<span class="toggle-slider"></span>
			</label>
			<span class="theme-label" id="theme-label">Light</span>
		</div>
		
		
		<div class="reset-button-container">
			<button id="reset-data-btn" class="btn btn-reset">Reset Data</button>
		</div>
	</nav>

