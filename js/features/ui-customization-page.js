function setupUiCustomizationPageHandlers() {
	const $form = $('#ui-customization-form');
	if ($form.length === 0) {
		return;
	}

	const $status = $('#ui-customization-status');
	const $saveBtn = $('#ui-customization-save-btn');
	const widgetPermissions = window.DASHBOARD_WIDGET_PERMISSIONS && typeof window.DASHBOARD_WIDGET_PERMISSIONS === 'object'
		? window.DASHBOARD_WIDGET_PERMISSIONS
		: {};
	const allowedKeys = Array.isArray(widgetPermissions.allowed_keys)
		? widgetPermissions.allowed_keys
		: [];
	const canCustomizeWidgets = widgetPermissions.can_customize === true;
	const widgetKeys = ['total_entries', 'total_edits', 'adds_today', 'deletes_today', 'local_time'];

	function setStatus(message, isError) {
		if (!$status.length) {
			return;
		}
		$status.removeClass('error success');
		if (message === '') {
			$status.attr('hidden', 'hidden').text('');
			return;
		}
		$status.addClass(isError ? 'error' : 'success').removeAttr('hidden').text(message);
	}

	function getWidgetsPayloadFromForm() {
		const payload = {};
		widgetKeys.forEach(function(widgetKey) {
			if (allowedKeys.length > 0 && allowedKeys.indexOf(widgetKey) === -1) {
				return;
			}
			payload[widgetKey] = $('#widget-' + widgetKey.replace(/_/g, '-')).is(':checked');
		});
		return payload;
	}

	function applyFormFromPreferences(preferences) {
		const normalized = (window.DashboardHeaderWidgets && typeof window.DashboardHeaderWidgets.normalize === 'function')
			? window.DashboardHeaderWidgets.normalize(preferences)
			: preferences;

		widgetKeys.forEach(function(widgetKey) {
			const widgetId = '#widget-' + widgetKey.replace(/_/g, '-');
			const $input = $(widgetId);
			const isAllowed = allowedKeys.length === 0 || allowedKeys.indexOf(widgetKey) !== -1;
			$input.prop('disabled', !isAllowed);
			$input.closest('.widget-option-item').toggle(isAllowed);
		});

		$('#widget-total-entries').prop('checked', !!normalized.total_entries);
		$('#widget-total-edits').prop('checked', !!normalized.total_edits);
		$('#widget-adds-today').prop('checked', !!normalized.adds_today);
		$('#widget-deletes-today').prop('checked', !!normalized.deletes_today);
		$('#widget-local-time').prop('checked', !!normalized.local_time);
	}

	function loadPreferences() {
		setStatus('', false);
		if (!canCustomizeWidgets) {
			setStatus('You do not have permission to customize widget visibility.', true);
			$saveBtn.prop('disabled', true).text('Save Preferences');
			$form.find('input[type="checkbox"]').prop('disabled', true);
			return;
		}
		if (allowedKeys.length === 0) {
			setStatus('No widget options are available for your role.', true);
			$saveBtn.prop('disabled', true).text('Save Preferences');
			$form.find('input[type="checkbox"]').prop('disabled', true);
			return;
		}
		$saveBtn.prop('disabled', true).text('Loading...');
		$.ajax({
			url: '../api.php?action=widget_preferences',
			type: 'GET',
			dataType: 'json'
		}).done(function(response) {
			const widgets = response && typeof response === 'object' ? response.widgets : null;
			applyFormFromPreferences(widgets || {});
			if (window.DashboardHeaderWidgets && typeof window.DashboardHeaderWidgets.apply === 'function') {
				window.DashboardHeaderWidgets.apply(widgets || {});
			}
		}).fail(function(xhr) {
			const msg = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Unable to load UI customization settings right now.';
			setStatus(msg, true);
		}).always(function() {
			$saveBtn.prop('disabled', false).text('Save Preferences');
		});
	}

	$form.on('submit', function(e) {
		e.preventDefault();
		if (!canCustomizeWidgets) {
			setStatus('You do not have permission to customize widget visibility.', true);
			return;
		}
		setStatus('', false);
		$saveBtn.prop('disabled', true).text('Saving...');

		const widgets = getWidgetsPayloadFromForm();
		$.ajax({
			url: '../api.php',
			type: 'POST',
			contentType: 'application/json',
			dataType: 'json',
			data: JSON.stringify({
				action: 'widget_preferences_update',
				widgets: widgets
			})
		}).done(function(response) {
			const updatedWidgets = response && typeof response === 'object' ? response.widgets : widgets;
			if (window.DashboardHeaderWidgets && typeof window.DashboardHeaderWidgets.apply === 'function') {
				window.DashboardHeaderWidgets.apply(updatedWidgets);
			}
			if (window.DashboardHeaderWidgets && typeof window.DashboardHeaderWidgets.load === 'function') {
				window.DashboardHeaderWidgets.load();
			}
			setStatus('UI customization preferences saved.', false);
			showToast({
				type: 'success',
				title: 'Preferences Saved',
				message: 'Header widget visibility has been updated.',
				autoCloseMs: 2500,
				showOkayButton: true
			});
		}).fail(function(xhr) {
			const msg = xhr && xhr.responseJSON && xhr.responseJSON.message
				? xhr.responseJSON.message
				: 'Failed to save widget preferences.';
			setStatus(msg, true);
			showToast({
				type: 'error',
				title: 'Save Failed',
				message: msg,
				autoCloseMs: 3000,
				showOkayButton: true
			});
		}).always(function() {
			$saveBtn.prop('disabled', false).text('Save Preferences');
		});
	});

	loadPreferences();
}
