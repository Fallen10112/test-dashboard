$(document).ready(function() {
	initializeTheme();
	setupResetButtonHandler();
	setupUserAvatarDropdown();
	loadHeaderMetrics();

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
	}

	if ($('#report-container').length > 0 && typeof setupReportsPageHandlers === 'function') {
		setupReportsPageHandlers();
	}

	if ($('#audit-container').length > 0) {
		if (typeof loadAuditTrail === 'function') {
			loadAuditTrail();
		}
		if (typeof setupAuditTrailPageHandlers === 'function') {
			setupAuditTrailPageHandlers();
		}
	}
});


$(document).ready(function() {
	const scrollTopBtn = $('#scroll-to-top');
	const scrollThreshold = 300;

	$(window).scroll(function() {
		if ($('.main-content').scrollTop() > scrollThreshold) {
			scrollTopBtn.addClass('show');
		} else {
			scrollTopBtn.removeClass('show');
		}
	});

	scrollTopBtn.on('click', function(e) {
		e.preventDefault();
		$('.main-content').animate({ scrollTop: 0 }, 'smooth');
	});
});
