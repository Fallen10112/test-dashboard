
$(document).ready(function() {
	initializeTheme();
	setupDevToolsMaintenanceHandlers();
	setupUserManagementHandlers();
	setupDevToolsCategorySelector();
	setupNotificationDropdown();
	setupUserAvatarDropdown();
	setupGlobalAjaxSessionGuard();
	setupSessionEnforcementPoller();
	if (window.DashboardHeaderWidgets && typeof window.DashboardHeaderWidgets.setupLocalTimeClock === 'function') {
		window.DashboardHeaderWidgets.setupLocalTimeClock();
	}
	const loadWidgetsPromise = (typeof loadHeaderWidgetPreferences === 'function')
		? loadHeaderWidgetPreferences()
		: $.Deferred().resolve().promise();

	$.when(loadWidgetsPromise).always(function() {
		loadHeaderMetrics();
	});

	if ($('#data-container').length > 0) {
		if (typeof initializeDataPagePreferences === 'function') {
			initializeDataPagePreferences();
		}
		if (typeof setupDataPageHandlers === 'function') {
			setupDataPageHandlers();
		}
		if (typeof loadDataPage === 'function') {
			loadDataPage();
		} else {
			loadData();
		}
		if (typeof startDataPageRealtimeSync === 'function') {
			startDataPageRealtimeSync();
		}
	}

	if ($('#report-container').length > 0 && typeof setupReportsPageHandlers === 'function') {
		setupReportsPageHandlers();
	}

	if ($('#audit-container').length > 0) {
		if (typeof initializeAuditPagePreferences === 'function') {
			initializeAuditPagePreferences();
		}
		if (typeof loadAuditTrail === 'function') {
			loadAuditTrail();
		}
		if (typeof setupAuditTrailPageHandlers === 'function') {
			setupAuditTrailPageHandlers();
		}
		if (typeof startAuditTrailRealtimeSync === 'function') {
			startAuditTrailRealtimeSync();
		}
	}

	if ($('#ui-customization-container').length > 0 && typeof setupUiCustomizationPageHandlers === 'function') {
		setupUiCustomizationPageHandlers();
	}

	if ($('#admin-container').length > 0 && typeof setupAdminPageHandlers === 'function') {
		setupAdminPageHandlers();
	}
	if ($('#admin-container').length > 0 && typeof setupAdminNotificationsManagementHandlers === 'function') {
		setupAdminNotificationsManagementHandlers();
	}
	if ($('#admin-container').length > 0 && typeof setupAdminRoleManagementHandlers === 'function') {
		setupAdminRoleManagementHandlers();
	}
	if ($('#admin-container').length > 0 && typeof setupAdminPermissionManagementHandlers === 'function') {
		setupAdminPermissionManagementHandlers();
	}

	const scrollTopBtn = $('#scroll-to-top');
	const scrollThreshold = 300;
	const $mainContent = $('.main-content');

	function getScrollPosition() {
		if ($mainContent.length > 0) {
			return $mainContent.scrollTop();
		}
		return $(window).scrollTop();
	}

	$(window).on('scroll', function() {
		if (getScrollPosition() > scrollThreshold) {
			scrollTopBtn.addClass('show');
		} else {
			scrollTopBtn.removeClass('show');
		}
	});

	scrollTopBtn.on('click', function(e) {
		e.preventDefault();
		if ($mainContent.length > 0 && $mainContent[0].scrollHeight > $mainContent.innerHeight()) {
			$mainContent.animate({ scrollTop: 0 }, 250);
			return;
		}
		$('html, body').animate({ scrollTop: 0 }, 250);
	});
});
