(function ($) {
  // Store current AJAX request for abort functionality
  var currentRequest = null;
  // Debounce timer for checkbox changes
  var debounceTimer = null;
  var DEBOUNCE_DELAY = 120;

  function serializeForm($form) {
    return $form.serialize();
  }

  // Save expanded Show More states before AJAX replacement
  function saveShowMoreState() {
    var state = {};
    $('.hlm-show-more').each(function () {
      var $btn = $(this);
      var $fieldset = $btn.closest('.hlm-filter');
      var filterKey = $fieldset.find('input, select').first().attr('name');
      if (filterKey) {
        state[filterKey] = $btn.data('expanded') === true;
      }
    });
    return state;
  }

  // Restore expanded Show More states after AJAX replacement
  function restoreShowMoreState(state) {
    if (!state || Object.keys(state).length === 0) return;

    $('.hlm-filter').each(function () {
      var $fieldset = $(this);
      var filterKey = $fieldset.find('input, select').first().attr('name');
      if (filterKey && state[filterKey] === true) {
        var $btn = $fieldset.find('.hlm-show-more');
        if ($btn.length && $btn.data('expanded') !== true) {
          $btn.trigger('click');
        }
      }
    });
  }

  // Show error message to user
  function showError(message) {
    var $wrap = $('.hlm-filters-wrap').first();
    if (!$wrap.length) return;

    // Remove existing error
    $wrap.find('.hlm-error-notice').remove();

    var errorMsg = message || (window.HLMFilters && window.HLMFilters.errorMessage) || 'Unable to load results. Please try again.';
    var $error = $('<div class="hlm-error-notice" role="alert"></div>').text(errorMsg);
    $wrap.prepend($error);

    // Auto-dismiss after 5 seconds
    setTimeout(function () {
      $error.fadeOut(300, function () {
        $(this).remove();
      });
    }, 5000);
  }

  // Announce results to screen readers
  function announceResults(total) {
    var $liveRegion = $('#hlm-live-region');
    if (!$liveRegion.length) {
      $liveRegion = $('<div id="hlm-live-region" class="hlm-sr-only" aria-live="polite" aria-atomic="true"></div>');
      $('.hlm-filters-wrap').first().prepend($liveRegion);
    }

    var message;
    if (total === 0) {
      message = window.HLMFilters && window.HLMFilters.noResultsText
        ? window.HLMFilters.noResultsText
        : 'No products found';
    } else if (total === 1) {
      message = window.HLMFilters && window.HLMFilters.oneProductText
        ? window.HLMFilters.oneProductText
        : '1 product found';
    } else {
      message = window.HLMFilters && window.HLMFilters.productsFoundText
        ? window.HLMFilters.productsFoundText.replace('%d', total)
        : total + ' products found';
    }

    $liveRegion.text(message);
  }

  // Handle image fallback for broken swatch images
  function handleImageError(event) {
    var $img = $(event.target);
    if (!$img.hasClass('hlm-swatch-image')) return;

    var fallback = $img.data('fallback') || '?';
    $img.removeClass('hlm-swatch-image')
        .addClass('hlm-swatch-text')
        .removeAttr('style')
        .text(fallback);
  }

  // Keyboard navigation for swatch grids
  function handleSwatchKeyboard(event) {
    var $target = $(event.target);
    if (!$target.is('.hlm-swatch input, .hlm-swatch-list input')) return;

    var $list = $target.closest('.hlm-swatch-list, .hlm-filter-list');
    var $inputs = $list.find('input:visible');
    var currentIndex = $inputs.index($target);

    if (currentIndex === -1) return;

    var newIndex = currentIndex;
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        event.preventDefault();
        newIndex = (currentIndex + 1) % $inputs.length;
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault();
        newIndex = (currentIndex - 1 + $inputs.length) % $inputs.length;
        break;
      default:
        return;
    }

    $inputs.eq(newIndex).focus();
  }

  // Handle collapsible filter panels
  function handleFilterToggle(event) {
    var $button = $(event.target).closest('.hlm-filter-toggle');
    if (!$button.length) return;

    event.preventDefault();

    var isExpanded = $button.attr('aria-expanded') === 'true';
    var $body = $button.parent().next('.hlm-filter-body');

    // Mark as user-expanded to override mobile default
    $button.addClass('hlm-user-expanded');

    if (isExpanded) {
      $button.attr('aria-expanded', 'false');
      $body.slideUp(200);
    } else {
      $button.attr('aria-expanded', 'true');
      $body.slideDown(200);
    }

    // Save state to localStorage
    var filterKey = $button.closest('.hlm-filter').data('filter-key');
    if (filterKey) {
      saveFilterCollapseState(filterKey, !isExpanded);
    }
  }

  // Save/restore filter collapse state
  function saveFilterCollapseState(key, isExpanded) {
    try {
      var state = JSON.parse(localStorage.getItem('hlm_filter_state') || '{}');
      state[key] = isExpanded;
      localStorage.setItem('hlm_filter_state', JSON.stringify(state));
    } catch (e) {
      // localStorage not available
    }
  }

  function restoreFilterCollapseStates() {
    try {
      var state = JSON.parse(localStorage.getItem('hlm_filter_state') || '{}');
      $('.hlm-filter-toggle').each(function () {
        var $button = $(this);
        var filterKey = $button.closest('.hlm-filter').data('filter-key');
        if (filterKey && state[filterKey] !== undefined) {
          $button.addClass('hlm-user-expanded');
          if (state[filterKey]) {
            $button.attr('aria-expanded', 'true');
            $button.parent().next('.hlm-filter-body').show();
          } else {
            $button.attr('aria-expanded', 'false');
            $button.parent().next('.hlm-filter-body').hide();
          }
        }
      });
    } catch (e) {
      // localStorage not available
    }
  }

  // Initialize on page load
  function initCollapsible() {
    // Check if mobile (coarse pointer)
    var isMobile = window.matchMedia('(pointer: coarse)').matches;

    if (isMobile) {
      // Collapse all filters by default on mobile (unless user has saved state)
      $('.hlm-filter-toggle').each(function () {
        var $button = $(this);
        if (!$button.hasClass('hlm-user-expanded')) {
          $button.attr('aria-expanded', 'false');
          $button.parent().next('.hlm-filter-body').hide();
        }
      });
    }

    // Restore user-saved states
    restoreFilterCollapseStates();
    
    // Re-initialize event handlers after AJAX updates
    $(document).on('hlm_filters_updated', function() {
      restoreFilterCollapseStates();
    });
  }

  function updateUrl($form) {
    var base = window.location.origin + window.location.pathname;
    var query = serializeForm($form);
    var url = query ? base + '?' + query : base;
    window.history.pushState({ hlmFilters: true }, '', url);
  }

  function updateResults(payload, $form) {
    var resultSelector = $form.data('results') || '.products';
    var paginationSelector = $form.data('pagination') || '.woocommerce-pagination';
    var resultCountSelector = $form.data('resultCount') || '.woocommerce-result-count';
    var $results = $(resultSelector).first();
    var $pagination = $(paginationSelector).first();
    var $resultCount = $(resultCountSelector).first();
    var $filtersWrap = $('.hlm-filters-wrap').first();

    // Save Show More expanded state before replacing filters
    var showMoreState = saveShowMoreState();

    if ($results.length && payload.html !== undefined) {
      // Extract only the <li> items from payload.html to avoid duplicate <ul> wrappers
      // payload.html contains the full <ul class="products">...</ul> wrapper from WooCommerce
      // but $results is already the .products container, so we only need the inner <li> items
      var $temp = $('<div></div>').html(payload.html);
      
      // First, try to find the .products wrapper and extract its direct children (<li> items)
      var $wrapper = $temp.find('ul.products').first();
      if ($wrapper.length) {
        // Extract only direct children (<li> items) to avoid nested structures
        $results.empty().append($wrapper.children('li'));
      } else {
        // Fallback: if no .products wrapper found, try to find any <li> items
        var $productItems = $temp.find('li');
        if ($productItems.length > 0) {
          $results.empty().append($productItems);
        } else {
          // Last resort: use the HTML as-is (shouldn't happen, but safe fallback)
          $results.html(payload.html);
        }
      }
    }

    if ($pagination.length && payload.pagination !== undefined) {
      $pagination.html(payload.pagination);
    } else if (payload.pagination && $results.length) {
      var $temp = $('<div></div>').html(payload.pagination);
      var $nav = $temp.find('.woocommerce-pagination').first();
      if ($nav.length) {
        $results.after($nav);
      }
    }

    if ($filtersWrap.length && payload.filters) {
      $filtersWrap.replaceWith(payload.filters);
      // Ensure overlay is hidden after filter replacement
      setTimeout(function() {
        toggleLoading(null, false);
        $('.hlm-filters-loading').removeClass('is-active')
          .attr('aria-hidden', 'true')
          .removeAttr('style')
          .css('display', 'none')
          .hide();
        $('body').css('overflow', '');
      }, 10);
    }

    if (payload.result_count !== undefined) {
      if ($resultCount.length) {
        $resultCount.replaceWith(payload.result_count);
      } else if ($results.length && payload.result_count) {
        $results.before(payload.result_count);
      }
    }

    // Re-hide items based on threshold after replacing filters
    $('.hlm-show-more').each(function() {
      var $button = $(this);
      var $fieldset = $button.closest('.hlm-filter');
      var threshold = parseInt($button.data('threshold') || '0', 10);
      var isExpanded = $button.data('expanded') === true;
      
      if (threshold > 0 && !isExpanded) {
        $fieldset.find('ul > li').each(function(index) {
          if (index >= threshold) {
            $(this).attr('data-hlm-hidden', 'true');
          }
        });
      }
    });
    
    // Restore Show More expanded state after replacing filters
    restoreShowMoreState(showMoreState);
    
    // Trigger custom event for re-initialization
    $(document).trigger('hlm_filters_updated');
  }

  function setPage($form, page) {
    var $input = $form.find('input[name="paged"]');
    if (!$input.length) {
      $input = $('<input type="hidden" name="paged" />').appendTo($form);
    }
    $input.val(page);
  }

  function clearPage($form) {
    var $input = $form.find('input[name="paged"]');
    if ($input.length) {
      $input.val('1');
    }
  }

  function getGlobalOverlay() {
    // Find existing overlay from template or create new one
    var $overlay = $('.hlm-filters-loading').first();
    
    // If found, ensure it's in body for proper positioning
    if ($overlay.length) {
      if (!$overlay.parent().is('body')) {
        $overlay.detach().appendTo('body');
      }
      return $overlay;
    }

    // Create new overlay if none exists
    var html = '<div class="hlm-filters-loading-inner" role="alert" aria-busy="true">' +
      '<svg class="hlm-loader" viewBox="0 0 120 120" aria-hidden="true" focusable="false">' +
      '<defs>' +
      '<linearGradient id="hlm-loader-gradient" x1="0" y1="0" x2="1" y2="1">' +
      '<stop offset="0%" stop-color="#0f766e"/>' +
      '<stop offset="100%" stop-color="#14b8a6"/>' +
      '</linearGradient>' +
      '</defs>' +
      '<circle class="hlm-loader-track" cx="60" cy="60" r="44" />' +
      '<circle class="hlm-loader-ring" cx="60" cy="60" r="44" />' +
      '</svg>' +
      '<div class="hlm-loader-text">' +
      '<strong>Updating results</strong>' +
      '<span>Applying filters…</span>' +
      '</div>' +
      '</div>';
    
    $overlay = $('<div class="hlm-filters-loading" role="status" aria-live="polite" aria-hidden="true"></div>');
    $overlay.html(html);
    $('body').append($overlay);
    return $overlay;
  }

  function toggleLoading($form, isLoading) {
    // Always use global overlay for full-screen coverage
    var $overlay = getGlobalOverlay();

    if (isLoading) {
      // Ensure overlay is in body for proper positioning
      if (!$overlay.parent().is('body')) {
        $overlay.detach();
        $('body').append($overlay);
      }
      
      // Show overlay immediately - use class and inline styles
      $overlay
        .addClass('is-active')
        .attr('aria-hidden', 'false')
        .css({
          'display': 'flex',
          'position': 'fixed',
          'top': '0',
          'left': '0',
          'right': '0',
          'bottom': '0',
          'width': '100%',
          'height': '100%',
          'z-index': '999999',
          'background': 'rgba(15, 23, 42, 0.4)',
          'backdrop-filter': 'blur(4px)',
          'margin': '0',
          'padding': '0',
          'visibility': 'visible',
          'opacity': '1',
          'pointer-events': 'auto'
        })
        .show();
      
      $form.attr('aria-busy', 'true').addClass('is-loading');
      var resultSelector = $form.data('results') || '.products';
      $(resultSelector).first().attr('aria-busy', 'true');
      // Prevent body scroll when overlay is active
      $('body').css('overflow', 'hidden');
      return;
    }

    if ($form) {
      $form.removeAttr('aria-busy').removeClass('is-loading');
    }
    
    // Force hide ALL overlay elements - find them all and hide them
    $('.hlm-filters-loading').each(function() {
      var $el = $(this);
      $el
        .removeClass('is-active')
        .attr('aria-hidden', 'true')
        .hide();
      
      // Use attr to set style with !important (jQuery css() doesn't support !important)
      var existingStyle = $el.attr('style') || '';
      $el.attr('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important; position: fixed;');
    });
    
    if ($form) {
      var resultSelector = $form.data('results') || '.products';
      $(resultSelector).first().removeAttr('aria-busy');
    }
    
    // Restore body scroll
    $('body').css('overflow', '');
  }

  function handleSubmit(event) {
    var $form = $(event.target).closest('form.hlm-filters');
    if (!$form.length || !$form.hasClass('hlm-filters')) {
      return;
    }

    if (!window.HLMFilters || !window.HLMFilters.enableAjax) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    // Abort any in-flight request to prevent race conditions
    if (currentRequest && currentRequest.readyState !== 4) {
      currentRequest.abort();
    }

    // Show overlay immediately - no delays
    toggleLoading($form, true);
    
    // Start AJAX request
    currentRequest = $.ajax({
      url: window.HLMFilters.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'hlm_apply_filters',
        nonce: window.HLMFilters.nonce || '',
        form: serializeForm($form)
      }
    })
      .done(function (response) {
        // Hide overlay immediately on response
        toggleLoading($form, false);
        
        if (response && response.success) {
          updateResults(response.data || {}, $form);
          updateUrl($form);
          // Announce results to screen readers
          var total = response.data && response.data.total !== undefined ? response.data.total : 0;
          announceResults(total);
        } else {
          // Server returned error response
          var errorMsg = (response && response.data && response.data.message) ? response.data.message : null;
          showError(errorMsg);
        }
      })
      .fail(function (_xhr, status) {
        // Hide overlay on error
        toggleLoading($form, false);
        
        // Skip error message for aborted requests
        if (status === 'abort') {
          return;
        }
        // Show user-friendly error message
        showError();
      })
      .always(function () {
        // Final safety check - force hide overlay
        toggleLoading($form, false);
        
        // Additional force hide after a tiny delay
        setTimeout(function() {
          $('.hlm-filters-loading').each(function() {
            var $el = $(this);
            $el.removeClass('is-active')
              .attr('aria-hidden', 'true')
              .hide()
              .attr('style', 'display: none !important; visibility: hidden !important; opacity: 0 !important;');
          });
          $('body').css('overflow', '');
        }, 10);
        
        currentRequest = null;
      });
  }

  function handleAutoApply(event) {
    var $form = $(event.target).closest('form.hlm-filters');
    if (!$form.length) {
      return;
    }
    if (!window.HLMFilters || !window.HLMFilters.enableAjax) {
      return;
    }
    if (window.HLMFilters.enableApply) {
      return;
    }

    // Debounce rapid checkbox clicks
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(function () {
      clearPage($form);
      $form.trigger('submit');
      debounceTimer = null;
    }, DEBOUNCE_DELAY);
  }

  function handleShowMore(event) {
    var $button = $(event.target).closest('.hlm-show-more');
    if (!$button.length) {
      return;
    }

    var $fieldset = $button.closest('.hlm-filter');
    var isExpanded = $button.data('expanded') === true;

    if (!isExpanded) {
      $fieldset.find('[data-hlm-hidden="true"]').removeAttr('data-hlm-hidden');
      $button.text('Show less');
      $button.attr('aria-expanded', 'true');
      $button.data('expanded', true);
      return;
    }

    var threshold = parseInt($button.data('threshold') || '0', 10);
    $fieldset.find('ul > li').each(function (index) {
      if (threshold > 0 && index >= threshold) {
        $(this).attr('data-hlm-hidden', 'true');
      }
    });
    $button.text('Show more');
    $button.attr('aria-expanded', 'false');
    $button.data('expanded', false);
  }

  function handlePaginationClick(event) {
    var $link = $(event.target).closest('.woocommerce-pagination a');
    if (!$link.length) {
      return;
    }

    var $form = $('form.hlm-filters').first();
    if (!$form.length || !window.HLMFilters || !window.HLMFilters.enableAjax) {
      return;
    }

    event.preventDefault();

    var url = new URL($link.attr('href'));
    var page = url.searchParams.get('paged') || '1';
    setPage($form, page);
    $form.trigger('submit');
  }

  function applyUrlState($form, url) {
    var params = new URLSearchParams(url.search);
    $form.get(0).reset();

    params.forEach(function (value, key) {
      if (key.indexOf('hlm_filters[') === 0) {
        var $field = $form.find('[name="' + key + '"]');
        if ($field.length && $field.is('select')) {
          if ($field.prop('multiple')) {
            $field.find('option[value="' + value + '"]').prop('selected', true);
          } else {
            $field.val(value);
          }
          return;
        }
        $form.find('[name="' + key + '"][value="' + value + '"]').prop('checked', true);
        return;
      }

      var $field = $form.find('[name="' + key + '"]');
      if ($field.length) {
        $field.val(value);
      }
    });
  }

  function handlePopState() {
    var $form = $('form.hlm-filters').first();
    if (!$form.length || !window.HLMFilters || !window.HLMFilters.enableAjax) {
      return;
    }
    applyUrlState($form, window.location);
    $form.trigger('submit');
  }

  $(document)
    .on('click', handlePaginationClick)
    .on('click', handleShowMore)
    .on('click', '.hlm-filter-toggle', handleFilterToggle)
    .on('change', 'form.hlm-filters input[type="checkbox"], form.hlm-filters select', handleAutoApply)
    .on('keydown', '.hlm-swatch-list input, .hlm-filter-list input', handleSwatchKeyboard)
    .on('error', '.hlm-swatch-image', handleImageError);

  $(window).on('popstate', handlePopState);

  // Initialize collapsible on DOM ready
  $(function () {
    initCollapsible();
    
    // Pre-initialize the global overlay so it's always available
    getGlobalOverlay();
    
    // Ensure AJAX handlers are properly attached
    if (window.HLMFilters && window.HLMFilters.enableAjax) {
      // Remove any existing handlers and attach with namespace to avoid conflicts
      $(document).off('submit.hlm', 'form.hlm-filters').on('submit.hlm', 'form.hlm-filters', handleSubmit);
    }
  });
  
  // Also attach on document ready for dynamically added forms
  $(document).ready(function() {
    // Pre-initialize the global overlay so it's always available
    getGlobalOverlay();
    
    if (window.HLMFilters && window.HLMFilters.enableAjax) {
      // Remove any existing handlers and attach with namespace to avoid conflicts
      $(document).off('submit.hlm', 'form.hlm-filters').on('submit.hlm', 'form.hlm-filters', handleSubmit);
    }
  });
})(jQuery);
