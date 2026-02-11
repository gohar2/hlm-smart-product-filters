(function ($) {
  'use strict';

  /* ==========================================================================
   * Configuration & State
   * ========================================================================== */

  var config = {
    DEBOUNCE_DELAY: 120,
    ERROR_DISMISS_MS: 5000,
    SLIDE_DURATION: 200,
    OVERLAY_CLEANUP_DELAY: 10,
  };

  var state = {
    currentRequest: null,
    debounceTimer: null,
  };

  var selectors = {
    form: 'form.hlm-filters',
    wrap: '.hlm-filters-wrap',
    filter: '.hlm-filter',
    showMore: '.hlm-show-more',
    overlay: '.hlm-filters-loading',
    toggle: '.hlm-filter-toggle',
    pagination: '.woocommerce-pagination a',
    swatchInput: '.hlm-swatch input, .hlm-swatch-list input',
    swatchImage: '.hlm-swatch-image',
    liveRegion: '#hlm-live-region',
    errorNotice: '.hlm-error-notice',
  };

  /* ==========================================================================
   * Utility Helpers
   * ========================================================================== */

  /**
   * Get plugin settings from localized script data.
   */
  function getSetting(key, fallback) {
    return (window.HLMFilters && window.HLMFilters[key] !== undefined)
      ? window.HLMFilters[key]
      : fallback;
  }

  /**
   * Check if AJAX mode is enabled.
   */
  function isAjaxEnabled() {
    return !!getSetting('enableAjax', false);
  }

  /**
   * Safe localStorage wrapper.
   */
  var storage = {
    get: function (key, fallback) {
      try {
        var val = localStorage.getItem(key);
        return val !== null ? JSON.parse(val) : fallback;
      } catch (e) {
        return fallback;
      }
    },
    set: function (key, value) {
      try {
        localStorage.setItem(key, JSON.stringify(value));
      } catch (e) {
        // localStorage unavailable
      }
    },
  };

  /* ==========================================================================
   * Form Serialization & URL Management
   * ========================================================================== */

  function serializeForm($form) {
    return $form.serialize();
  }

  function updateUrl($form) {
    var base = window.location.origin + window.location.pathname;
    var query = serializeForm($form);
    var url = query ? base + '?' + query : base;
    window.history.pushState({ hlmFilters: true }, '', url);
  }

  function applyUrlState($form, location) {
    var params = new URLSearchParams(location.search);
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

      var $input = $form.find('[name="' + key + '"]');
      if ($input.length) {
        $input.val(value);
      }
    });
  }

  /* ==========================================================================
   * Pagination Helpers
   * ========================================================================== */

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

  /* ==========================================================================
   * Loading Overlay
   * ========================================================================== */

  function getGlobalOverlay() {
    var $overlay = $(selectors.overlay).first();

    if ($overlay.length) {
      if (!$overlay.parent().is('body')) {
        $overlay.detach().appendTo('body');
      }
      return $overlay;
    }

    // Create overlay if none exists in the DOM
    $overlay = $(
      '<div class="hlm-filters-loading" role="status" aria-live="polite" aria-hidden="true">' +
        '<div class="hlm-filters-loading-inner" role="alert" aria-busy="true">' +
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
        '</div>' +
      '</div>'
    );

    $('body').append($overlay);
    return $overlay;
  }

  function showLoading($form) {
    var $overlay = getGlobalOverlay();

    if (!$overlay.parent().is('body')) {
      $overlay.detach().appendTo('body');
    }

    $overlay
      .addClass('is-active')
      .attr('aria-hidden', 'false')
      .css({
        display: 'flex',
        position: 'fixed',
        top: 0, left: 0, right: 0, bottom: 0,
        width: '100%', height: '100%',
        zIndex: 999999,
        background: 'rgba(15, 23, 42, 0.4)',
        backdropFilter: 'blur(4px)',
        margin: 0, padding: 0,
        visibility: 'visible',
        opacity: 1,
        pointerEvents: 'auto',
      })
      .show();

    $form.attr('aria-busy', 'true').addClass('is-loading');

    var resultSelector = $form.data('results') || '.products';
    $(resultSelector).first().attr('aria-busy', 'true');

    $('body').css('overflow', 'hidden');
  }

  function hideLoading($form) {
    if ($form) {
      $form.removeAttr('aria-busy').removeClass('is-loading');

      var resultSelector = $form.data('results') || '.products';
      $(resultSelector).first().removeAttr('aria-busy');
    }

    // Remove inline styles entirely so showLoading can work next time
    $(selectors.overlay).each(function () {
      $(this)
        .removeClass('is-active')
        .attr('aria-hidden', 'true')
        .removeAttr('style')
        .hide();
    });

    $('body').css('overflow', '');
  }

  /* ==========================================================================
   * User Feedback (Errors & Screen-Reader Announcements)
   * ========================================================================== */

  function showError(message) {
    var $wrap = $(selectors.wrap).first();
    if (!$wrap.length) return;

    $wrap.find(selectors.errorNotice).remove();

    var text = message || getSetting('errorMessage', 'Unable to load results. Please try again.');
    var $error = $('<div class="hlm-error-notice" role="alert"></div>').text(text);
    $wrap.prepend($error);

    setTimeout(function () {
      $error.fadeOut(300, function () { $(this).remove(); });
    }, config.ERROR_DISMISS_MS);
  }

  function announceResults(total) {
    var $region = $(selectors.liveRegion);

    if (!$region.length) {
      $region = $('<div id="hlm-live-region" class="hlm-sr-only" aria-live="polite" aria-atomic="true"></div>');
      $(selectors.wrap).first().prepend($region);
    }

    var message;
    if (total === 0) {
      message = getSetting('noResultsText', 'No products found');
    } else if (total === 1) {
      message = getSetting('oneProductText', '1 product found');
    } else {
      message = getSetting('productsFoundText', '%d products found').replace('%d', total);
    }

    $region.text(message);
  }

  /* ==========================================================================
   * "Show More / Hide All" – Batched Reveal
   * ========================================================================== */

  /**
   * Save which filters have their "Show More" expanded before an AJAX swap.
   */
  function saveShowMoreState() {
    var map = {};

    $(selectors.showMore).each(function () {
      var $btn = $(this);
      var key = $btn.closest(selectors.filter).find('input, select').first().attr('name');
      if (key) {
        map[key] = $btn.data('expanded') === true;
      }
    });

    return map;
  }

  /**
   * After AJAX replaces the filter HTML, re-expand any that were open.
   */
  function restoreShowMoreState(map) {
    if (!map || !Object.keys(map).length) return;

    $(selectors.filter).each(function () {
      var $fieldset = $(this);
      var key = $fieldset.find('input, select').first().attr('name');

      if (key && map[key] === true) {
        var $btn = $fieldset.find(selectors.showMore);
        if ($btn.length && $btn.data('expanded') !== true) {
          $btn.trigger('click');
        }
      }
    });
  }

  /**
   * Re-apply data-hlm-hidden after AJAX replaces filter markup.
   */
  function reapplyThresholds() {
    $(selectors.showMore).each(function () {
      var $btn = $(this);
      var threshold = parseInt($btn.data('threshold') || '0', 10);

      if (threshold > 0 && $btn.data('expanded') !== true) {
        $btn.closest(selectors.filter).find('ul > li').each(function (i) {
          if (i >= threshold) {
            $(this).attr('data-hlm-hidden', 'true');
          }
        });
      }
    });
  }

  /* ==========================================================================
   * Collapsible Filter Panels
   * ========================================================================== */

  function saveCollapseState(filterKey, isExpanded) {
    var all = storage.get('hlm_filter_state', {});
    all[filterKey] = isExpanded;
    storage.set('hlm_filter_state', all);
  }

  function restoreCollapseStates() {
    var all = storage.get('hlm_filter_state', {});

    $(selectors.toggle).each(function () {
      var $btn = $(this);
      var key = $btn.closest(selectors.filter).data('filter-key');

      if (key && all[key] !== undefined) {
        $btn.addClass('hlm-user-expanded');

        if (all[key]) {
          $btn.attr('aria-expanded', 'true');
          $btn.parent().next('.hlm-filter-body').show();
        } else {
          $btn.attr('aria-expanded', 'false');
          $btn.parent().next('.hlm-filter-body').hide();
        }
      }
    });
  }

  function initCollapsible() {
    var isMobile = window.matchMedia('(pointer: coarse)').matches;

    if (isMobile) {
      $(selectors.toggle).each(function () {
        var $btn = $(this);
        if (!$btn.hasClass('hlm-user-expanded')) {
          $btn.attr('aria-expanded', 'false');
          $btn.parent().next('.hlm-filter-body').hide();
        }
      });
    }

    restoreCollapseStates();
  }

  /* ==========================================================================
   * AJAX Result Updates
   * ========================================================================== */

  function updateResults(payload, $form) {
    var resultSel = $form.data('results') || '.products';
    var pagSel    = $form.data('pagination') || '.woocommerce-pagination';
    var countSel  = $form.data('resultCount') || '.woocommerce-result-count';

    var $results     = $(resultSel).first();
    var $pagination  = $(pagSel).first();
    var $resultCount = $(countSel).first();
    var $filtersWrap = $(selectors.wrap).first();

    // Preserve Show More state across the DOM swap
    var showMoreState = saveShowMoreState();

    // -- Products --
    if ($results.length && payload.html !== undefined) {
      var $temp = $('<div>').html(payload.html);
      var $wrapper = $temp.find('ul[class*="products"]').first();

      if ($wrapper.length) {
        $results.empty().append($wrapper.children('li'));
      } else {
        var $items = $temp.find('li');
        if ($items.length) {
          $results.empty().append($items);
        } else {
          $results.html(payload.html);
        }
      }
    }

    // -- Pagination --
    if ($pagination.length && payload.pagination !== undefined) {
      $pagination.html(payload.pagination);
    } else if (payload.pagination && $results.length) {
      var $nav = $('<div>').html(payload.pagination).find('.woocommerce-pagination').first();
      if ($nav.length) {
        $results.after($nav);
      }
    }

    // -- Filter sidebar --
    if ($filtersWrap.length && payload.filters) {
      $filtersWrap.replaceWith(payload.filters);

      setTimeout(function () {
        hideLoading(null);
      }, config.OVERLAY_CLEANUP_DELAY);
    }

    // -- Result count --
    if (payload.result_count !== undefined) {
      if ($resultCount.length) {
        $resultCount.replaceWith(payload.result_count);
      } else if ($results.length) {
        $results.before(payload.result_count);
      }
    }

    // Restore threshold hiding & expanded states
    reapplyThresholds();
    restoreShowMoreState(showMoreState);

    // Let other code react (e.g. collapse state restore)
    $(document).trigger('hlm_filters_updated');
  }

  /* ==========================================================================
   * Event Handlers
   * ========================================================================== */

  function handleSubmit(event) {
   var $form = $(event.target).closest(selectors.form);
    if (!$form.length || !isAjaxEnabled()) return;
    event.preventDefault();
    event.stopPropagation();
    // Abort any in-flight request
    if (state.currentRequest && state.currentRequest.readyState !== 4) {
      state.currentRequest.abort();
    }

    showLoading($form);

    state.currentRequest = $.ajax({
      url: getSetting('ajaxUrl', ''),
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'hlm_apply_filters',
        nonce: getSetting('nonce', ''),
        form: serializeForm($form),
      },
    })
      .done(function (response) {
        hideLoading($form);

        if (response && response.success) {
          updateResults(response.data || {}, $form);
          updateUrl($form);
          announceResults((response.data && response.data.total) || 0);
        } else {
          var msg = (response && response.data && response.data.message) || null;
          showError(msg);
        }
      })
      .fail(function (_xhr, status) {
        hideLoading($form);
        if (status !== 'abort') showError();
      })
      .always(function () {
        hideLoading($form);

        // Final safety net
        setTimeout(function () {
          hideLoading(null);
        }, config.OVERLAY_CLEANUP_DELAY);

        state.currentRequest = null;
      });
  }

  function handleAutoApply(event) {
    var $form = $(event.target).closest(selectors.form);
    if (!$form.length || !isAjaxEnabled() || getSetting('enableApply', false)) return;

    clearTimeout(state.debounceTimer);
    state.debounceTimer = setTimeout(function () {
      clearPage($form);
      $form.trigger('submit');
      state.debounceTimer = null;
    }, config.DEBOUNCE_DELAY);
  }

  function handleShowMore(event) {
    var $button = $(event.target).closest(selectors.showMore);
    if (!$button.length) return;

    var $fieldset = $button.closest(selectors.filter);
    var threshold = parseInt($button.data('threshold') || '0', 10);
    if (threshold <= 0) return;

    var $allItems = $fieldset.find('ul > li');
    var isExpanded = $button.data('expanded') === true;

    // Collapse all back to threshold
    if (isExpanded) {
      $allItems.each(function (i) {
        if (i >= threshold) $(this).attr('data-hlm-hidden', 'true');
      });
      $button.text('Show more').attr('aria-expanded', 'false').data('expanded', false);
      return;
    }

    // Reveal next batch
    var revealed = 0;
    $allItems.each(function () {
      if (revealed >= threshold) return false;
      var $item = $(this);
      if ($item.attr('data-hlm-hidden') === 'true') {
        $item.removeAttr('data-hlm-hidden');
        revealed++;
      }
    });

    // All visible? Switch to "Hide All"
    if (!$fieldset.find('[data-hlm-hidden="true"]').length) {
      $button.text('Hide All').attr('aria-expanded', 'true').data('expanded', true);
    }
  }

  function handlePaginationClick(event) {
    var $link = $(event.target).closest(selectors.pagination);
    if (!$link.length) return;

    var $form = $(selectors.form).first();
    if (!$form.length || !isAjaxEnabled()) return;

    event.preventDefault();

    var page = new URL($link.attr('href')).searchParams.get('paged') || '1';
    setPage($form, page);
    $form.trigger('submit');
  }

  function handleFilterToggle(event) {
    var $button = $(event.target).closest(selectors.toggle);
    if (!$button.length) return;

    event.preventDefault();

    var isExpanded = $button.attr('aria-expanded') === 'true';
    var $body = $button.parent().next('.hlm-filter-body');

    $button.addClass('hlm-user-expanded');

    if (isExpanded) {
      $button.attr('aria-expanded', 'false');
      $body.slideUp(config.SLIDE_DURATION);
    } else {
      $button.attr('aria-expanded', 'true');
      $body.slideDown(config.SLIDE_DURATION);
    }

    var filterKey = $button.closest(selectors.filter).data('filter-key');
    if (filterKey) saveCollapseState(filterKey, !isExpanded);
  }

  function handleSwatchKeyboard(event) {
    var $target = $(event.target);
    if (!$target.is(selectors.swatchInput)) return;

    var arrows = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
    var dir = arrows[event.key];
    if (!dir) return;

    event.preventDefault();

    var $inputs = $target.closest('.hlm-swatch-list, .hlm-filter-list').find('input:visible');
    var idx = ($inputs.index($target) + dir + $inputs.length) % $inputs.length;
    $inputs.eq(idx).focus();
  }

  function handleImageError(event) {
    var $img = $(event.target);
    if (!$img.hasClass('hlm-swatch-image')) return;

    $img
      .removeClass('hlm-swatch-image')
      .addClass('hlm-swatch-text')
      .removeAttr('style')
      .text($img.data('fallback') || '?');
  }

  function handlePopState() {
    var $form = $(selectors.form).first();
    if (!$form.length || !isAjaxEnabled()) return;

    applyUrlState($form, window.location);
    $form.trigger('submit');
  }

  function handleClearAll(event) {
      var $link = $(event.target).closest('.hlm-filter-actions a');
      if (!$link.length) return;

      var $form = $(selectors.form).first();
      if (!$form.length || !isAjaxEnabled()) return;

      event.preventDefault();

      // Uncheck all checkboxes
      $form.find('input[type="checkbox"]').prop('checked', false);

      // Reset all selects to first option
      $form.find('select[name^="hlm_filters"]').each(function () {
          $(this).find('option').prop('selected', false);
          $(this).find('option:first').prop('selected', true);
      });

      clearPage($form);
      $form.trigger('submit');
  }

  /* ==========================================================================
   * Bind Events & Initialize
   * ========================================================================== */

  // Delegated events (survive AJAX replacements)
  $(document)
    .on('click', selectors.pagination, handlePaginationClick)
    .on('click', selectors.showMore, handleShowMore)
    .on('click', selectors.toggle, handleFilterToggle)
    .on('click', '.hlm-filter-actions a', handleClearAll)
    .on('change', selectors.form + ' input[type="checkbox"], ' + selectors.form + ' select', handleAutoApply)
    .on('keydown', selectors.swatchInput, handleSwatchKeyboard)
    .on('error', selectors.swatchImage, handleImageError)
    .on('hlm_filters_updated', restoreCollapseStates);

  $(window).on('popstate', handlePopState);

  // DOM ready
  $(function () {
    initCollapsible();
    getGlobalOverlay();

    if (isAjaxEnabled()) {
      $(document).off('submit.hlm').on('submit.hlm', selectors.form, handleSubmit);
    }
  });

})(jQuery);