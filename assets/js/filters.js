(function ($) {
  function serializeForm($form) {
    return $form.serialize();
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

    if ($results.length && payload.html !== undefined) {
      $results.html(payload.html);
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
    }

    if (payload.result_count !== undefined) {
      if ($resultCount.length) {
        $resultCount.replaceWith(payload.result_count);
      } else if ($results.length && payload.result_count) {
        $results.before(payload.result_count);
      }
    }
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
    var $overlay = $('#hlm-global-loading');
    if ($overlay.length) {
      return $overlay;
    }

    var $source = $('.hlm-filters-loading').first();
    var html = $source.length ? $source.html() : '<div class=\"hlm-filters-loading-inner\"><span>Loading…</span></div>';
    $overlay = $('<div id=\"hlm-global-loading\" class=\"hlm-filters-loading\" role=\"status\" aria-live=\"polite\" aria-hidden=\"true\"></div>');
    $overlay.html(html);

    $overlay.css({
      position: 'fixed',
      inset: 0,
      display: 'none',
      alignItems: 'center',
      justifyContent: 'center',
      zIndex: 100000
    });

    $('body').append($overlay);
    return $overlay;
  }

  function toggleLoading($form, isLoading) {
    var $overlay = getGlobalOverlay();

    if (isLoading) {
      $form.attr('aria-busy', 'true');
      $overlay.addClass('is-active').attr('aria-hidden', 'false').css('display', 'flex');
      var resultSelector = $form.data('results') || '.products';
      $(resultSelector).first().attr('aria-busy', 'true');
      return;
    }

    $form.removeAttr('aria-busy');
    $overlay.removeClass('is-active').attr('aria-hidden', 'true').css('display', 'none');
    var resultSelector = $form.data('results') || '.products';
    $(resultSelector).first().removeAttr('aria-busy');
  }

  function handleSubmit(event) {
    var $form = $(event.target);
    if (!$form.hasClass('hlm-filters')) {
      return;
    }

    if (!window.HLMFilters || !window.HLMFilters.enableAjax) {
      return;
    }

    event.preventDefault();
    toggleLoading($form, true);

    $.ajax({
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
        if (response && response.success) {
          updateResults(response.data || {}, $form);
          updateUrl($form);
        }
      })
      .always(function () {
        toggleLoading($form, false);
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
    clearPage($form);
    $form.trigger('submit');
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
    .on('submit', handleSubmit)
    .on('click', handlePaginationClick)
    .on('click', handleShowMore)
    .on('change', handleAutoApply);

  $(window).on('popstate', handlePopState);
})(jQuery);
