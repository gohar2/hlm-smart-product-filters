(function ($) {
  'use strict';

  function switchTab(tabName) {
    // Update buttons
    $('.hlm-tab-button').removeClass('active').attr('aria-selected', 'false');
    $('.hlm-tab-button[data-tab="' + tabName + '"]').addClass('active').attr('aria-selected', 'true');
    
    // Hide all panels
    $('.hlm-tab-panel').removeClass('active');
    
    // Show target panel
    var $targetPanel = $('.hlm-tab-panel[data-tab="' + tabName + '"]');
    if ($targetPanel.length) {
      $targetPanel.addClass('active');
    }
  }

  $(document).ready(function () {
    // Initialize color pickers
    if ($.fn.wpColorPicker) {
      $('.hlm-color-field').wpColorPicker();
    }

    // Tab switching - use direct binding for better reliability
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
