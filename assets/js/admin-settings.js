(function ($) {
  'use strict';

  function switchTab(tabName) {
    $('.hlm-tab-button')
      .removeClass('active')
      .attr('aria-selected', 'false');

    $('.hlm-tab-button[data-tab="' + tabName + '"]')
      .addClass('active')
      .attr('aria-selected', 'true');

    $('.hlm-tab-panel').removeClass('active');
    $('.hlm-tab-panel[data-tab="' + tabName + '"]').addClass('active');
  }

  $(document).ready(function () {
    // Color pickers
    if ($.fn.wpColorPicker) {
      $('.hlm-color-field').wpColorPicker();
    }

    // Tab switching
    $('.hlm-tab-button').on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var tabName = $(this).attr('data-tab');
      if (tabName) {
        switchTab(tabName);
      }
    });
  });
})(jQuery);