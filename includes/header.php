<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: text/html; charset=utf-8');
$pageTitle = "Dashboard Showcase";
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $pageTitle; ?></title>
	<link rel="stylesheet" href="../css/style.css">
</head>
<body>
	
	<header class="title-bar">
		<h1><?php echo $pageTitle; ?></h1>
		<div class="header-metrics" aria-label="Dashboard analytics">
			<div class="metric-pill" id="metric-total-entries">
				<span class="metric-label">Entries</span>
				<span class="metric-value">--</span>
			</div>
			<div class="metric-pill" id="metric-total-edits">
				<span class="metric-label">Edits</span>
				<span class="metric-value">--</span>
			</div>
			<div class="metric-pill" id="metric-adds-today">
				<span class="metric-label">Adds Today</span>
				<span class="metric-value">--</span>
			</div>
			<div class="metric-pill" id="metric-deletes-today">
				<span class="metric-label">Deletes Today</span>
				<span class="metric-value">--</span>
			</div>
		</div>
	</header>

