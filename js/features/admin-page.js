function setupAdminPageHandlers() {
	const tabs = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-btn[data-admin-tab]'));
	const panels = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-panel'));

	if (tabs.length === 0 || panels.length === 0) {
		return;
	}

	function activateTab(tabKey) {
		tabs.forEach(function(tabBtn) {
			const isActive = String(tabBtn.getAttribute('data-admin-tab') || '') === String(tabKey);
			tabBtn.classList.toggle('active', isActive);
			tabBtn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.forEach(function(panel) {
			const shouldShow = panel.id === 'admin-tab-panel-' + String(tabKey);
			panel.classList.toggle('active', shouldShow);
			panel.hidden = !shouldShow;
		});
	}

	tabs.forEach(function(tabBtn) {
		tabBtn.addEventListener('click', function() {
			activateTab(tabBtn.getAttribute('data-admin-tab'));
		});
	});

	activateTab('1');

	var accordionHeaders = Array.prototype.slice.call(document.querySelectorAll('.admin-accordion-header'));
	accordionHeaders.forEach(function(header) {
		header.addEventListener('click', function() {
			var expanded = header.getAttribute('aria-expanded') === 'true';
			var bodyId = header.getAttribute('aria-controls');
			var body = bodyId ? document.getElementById(bodyId) : null;
			header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			if (body) {
				body.hidden = expanded;
			}
		});
	});
}
