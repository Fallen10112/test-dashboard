$(function() {
  // Fetch current value
  function loadResetOnIndexVisit() {
    $.ajax({
      url: '../api.php?action=app_setting&key=reset_on_index_visit',
      type: 'GET',
      dataType: 'json',
      success: function(resp) {
        if (resp && typeof resp.value !== 'undefined') {
          $('#reset-on-index-visit-toggle').prop('checked', resp.value === true || resp.value === '1' || resp.value === 1 || resp.value === 'true');
          $('#reset-on-index-visit-status').text(resp.value ? 'On' : 'Off');
        }
      },
      error: function() {
        $('#reset-on-index-visit-status').text('Error');
      }
    });
  }

  $('#reset-on-index-visit-toggle').on('change', function() {
    var checked = $(this).is(':checked');
    $('#reset-on-index-visit-status').text('Saving...');
    $.ajax({
      url: '../api.php',
      type: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        action: 'set_app_setting',
        key: 'reset_on_index_visit',
        value: checked ? '1' : '0'
      }),
      success: function(resp) {
        $('#reset-on-index-visit-status').text(checked ? 'On' : 'Off');
      },
      error: function() {
        $('#reset-on-index-visit-status').text('Error');
      }
    });
  });

  loadResetOnIndexVisit();
});