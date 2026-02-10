(function ($) {
  'use strict';

  $(document).ready(function () {
    // Initialize color pickers
    if ($.fn.wpColorPicker) {
      $('.hlm-color-field').wpColorPicker();
    }

    // Tab switching
    $(document).on('click', '.hlm-tab-button', function (e) {
      e.preventDefault();
      e.stopPropagation();
      
      var $button = $(this);
      var tabName = $button.attr('data-tab') || $button.data('tab');
      
      if (!tabName) {
        console.warn('Tab button missing data-tab attribute');
        return;
      }
      
      // Update buttons
      $('.hlm-tab-button').removeClass('active').attr('aria-selected', 'false');
      $button.addClass('active').attr('aria-selected', 'true');
      
      // Update panels - use explicit CSS to ensure visibility
      $('.hlm-tab-panel').removeClass('active').each(function() {
        $(this).css({
          'display': 'none',
          'visibility': 'hidden',
          'opacity': '0'
        });
      });
      
      var $targetPanel = $('.hlm-tab-panel[data-tab="' + tabName + '"]');
      if ($targetPanel.length) {
        $targetPanel.addClass('active').css({
          'display': 'block !important',
          'visibility': 'visible',
          'opacity': '1'
        });
      } else {
        console.warn('Tab panel not found for:', tabName);
      }
    });
  });
})(jQuery);
