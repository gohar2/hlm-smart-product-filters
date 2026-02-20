(function ($) {
  'use strict';

  /* ------------------------------------------------------------------
   * Utilities
   * ----------------------------------------------------------------*/
  var _previewTimer    = null;
  var _colorCodesCache = null; // Cached result of hlm_get_color_codes AJAX call

  function debounce(fn, delay) {
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(_previewTimer);
      _previewTimer = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
  }

  function normalizeKey(value) {
    return value.toString().trim().toLowerCase().replace(/\s+/g, '_');
  }

  function updateIndices($list) {
    $list.find('.hlm-filter-row').each(function (index) {
      $(this).find('[name^="filters["]').each(function () {
        this.name = this.name.replace(/filters\[[^\]]+\]/, 'filters[' + index + ']');
      });
    });
  }

  /* ------------------------------------------------------------------
   * Auto-fill helpers
   * ----------------------------------------------------------------*/
  function applyAutoValues($row) {
    var dataSource   = $row.find('.hlm-data-source').val();
    var $sourceKey   = $row.find('.hlm-source-key');
    var $labelInput  = $row.find('[name*="[label]"]');
    var $keyInput    = $row.find('[name*="[key]"]');
    var $idInput     = $row.find('[name*="[id]"]');

    if (dataSource === 'product_cat') { $sourceKey.val('product_cat'); }
    if (dataSource === 'product_tag') { $sourceKey.val('product_tag'); }

    if (!$keyInput.val() && $labelInput.val()) {
      $keyInput.val(normalizeKey($labelInput.val()));
    }
    if (!$idInput.val() && $keyInput.val()) {
      $idInput.val(normalizeKey($keyInput.val()));
    }
  }

  function autoFillFromLabel($row, displayLabel, rawValue) {
    var $label = $row.find('[name*="[label]"]');
    var $key   = $row.find('[name*="[key]"]');
    var $id    = $row.find('[name*="[id]"]');

    if (!$label.val() && displayLabel) {
      $label.val(displayLabel);
      $row.find('.hlm-filter-title-text').text(displayLabel);
    }
    if (!$key.val() && rawValue) { $key.val(normalizeKey(rawValue)); }
    if (!$id.val() && rawValue)  { $id.val(normalizeKey(rawValue)); }
  }

  /* ------------------------------------------------------------------
   * Type visibility
   * ----------------------------------------------------------------*/
  function updateTypeVisibility($row) {
    var type     = $row.find('[name*="[type]"]').val() || 'checkbox';
    var isSwatch = type === 'swatch';
    var isRange  = type === 'range' || type === 'slider';
    var isSlider = type === 'slider';
    var showMore = type === 'checkbox' || type === 'swatch';
    var isList   = type === 'checkbox';

    $row.find('.hlm-swatch-only').toggleClass('is-hidden', !isSwatch);
    $row.find('.hlm-show-more-only').toggleClass('is-hidden', !showMore);
    $row.find('.hlm-list-only').toggleClass('is-hidden', !isList);
    $row.find('.hlm-range-only').toggleClass('is-hidden', !isRange);
    $row.find('.hlm-slider-only').toggleClass('is-hidden', !isSlider);
  }

  /* ------------------------------------------------------------------
   * Source picker <-> hidden fields sync
   * ----------------------------------------------------------------*/
  function updateSourceFromPicker($row) {
    var value       = $row.find('.hlm-source-picker').val() || '';
    var $custom     = $row.find('[name*="[custom_source]"]').closest('.hlm-custom-source');
    var $customIn   = $row.find('[name*="[custom_source]"]');
    var $meta       = $row.find('[name*="[meta_source]"]').closest('.hlm-meta-source');
    var $metaIn     = $row.find('[name*="[meta_source]"]');
    var $dataSource = $row.find('.hlm-data-source');
    var $sourceKey  = $row.find('.hlm-source-key');
    var $picker     = $row.find('.hlm-source-picker');
    var displayLabel = $picker.find('option:selected').text().trim();

    $custom.addClass('is-hidden');
    $meta.addClass('is-hidden');

    if (!value) { return; }

    if (value === 'product_cat' || value === 'product_tag') {
      $dataSource.val(value);
      $sourceKey.val(value);
      autoFillFromLabel($row, displayLabel, value);
    } else if (value === 'meta') {
      $dataSource.val('meta');
      $meta.removeClass('is-hidden');
      if ($metaIn.val()) { $sourceKey.val($metaIn.val()); }
      autoFillFromLabel($row, 'Price Range', 'price');

      var $typeSelect = $row.find('[name*="[type]"]');
      if ($typeSelect.val() !== 'range' && $typeSelect.val() !== 'slider') {
        $typeSelect.val('range').trigger('change');
      }
    } else if (value === 'custom') {
      $dataSource.val('taxonomy');
      $custom.removeClass('is-hidden');
      $picker.val('custom');
      if ($customIn.val()) {
        $sourceKey.val($customIn.val());
        var cleanLabel = $customIn.val().replace(/^pa_/, '').replace(/_/g, ' ');
        cleanLabel = cleanLabel.charAt(0).toUpperCase() + cleanLabel.slice(1);
        autoFillFromLabel($row, cleanLabel, $customIn.val());
      }
    } else {
      // Attribute
      $dataSource.val('attribute');
      $sourceKey.val(value);
      autoFillFromLabel($row, displayLabel, value);
    }

    applyAutoValues($row);
  }

  function updateSourcePickerFromFields($row) {
    var dataSource = $row.find('.hlm-data-source').val();
    var sourceKey  = $row.find('.hlm-source-key').val();
    var $picker    = $row.find('.hlm-source-picker');
    var $custom    = $row.find('[name*="[custom_source]"]').closest('.hlm-custom-source');
    var $customIn  = $row.find('[name*="[custom_source]"]');
    var $meta      = $row.find('[name*="[meta_source]"]').closest('.hlm-meta-source');
    var $metaIn    = $row.find('[name*="[meta_source]"]');
    var current    = $picker.val();

    if (!$picker.length) { return; }

    $custom.addClass('is-hidden');
    $meta.addClass('is-hidden');

    if (dataSource === 'product_cat' || dataSource === 'product_tag') {
      $picker.val(dataSource);
    } else if (dataSource === 'meta') {
      $picker.val('meta');
      $meta.removeClass('is-hidden');
      if (sourceKey) { $metaIn.val(sourceKey); }
    } else if (dataSource === 'attribute' && sourceKey) {
      $picker.val(sourceKey);
    } else if (dataSource === 'taxonomy') {
      if (current === 'custom' || sourceKey) {
        $picker.val('custom');
        if (sourceKey) { $customIn.val(sourceKey); }
        $custom.removeClass('is-hidden');
      }
    }
  }

  /* ------------------------------------------------------------------
   * Tab switching (filter row context)
   * ----------------------------------------------------------------*/
  function switchTab($row, tabName) {
    var $tabs    = $row.find('> .hlm-filter-card > .hlm-filter-tabs');
    var $buttons = $tabs.children('.hlm-tab-nav').children('.hlm-tab-button');
    var $panels  = $tabs.children('.hlm-tab-panels').children('.hlm-tab-panel');

    $buttons.removeClass('active').attr('aria-selected', 'false');
    $panels.removeClass('active');

    $buttons.filter('[data-tab="' + tabName + '"]').addClass('active').attr('aria-selected', 'true');
    $panels.filter('[data-tab="' + tabName + '"]').addClass('active');
  }

  /* ------------------------------------------------------------------
   * Expand / Collapse
   * ----------------------------------------------------------------*/
  function expandAllFilters() {
    $('#hlm-filters-list .hlm-filter-row').each(function () {
      $(this).find('.hlm-filter-tabs').show();
      switchTab($(this), 'general');
    });
  }

  function collapseAllFilters() {
    $('#hlm-filters-list .hlm-filter-row').each(function () {
      $(this).find('.hlm-filter-tabs').hide();
    });
  }

  /* ------------------------------------------------------------------
   * Preview
   * ----------------------------------------------------------------*/
  var debouncedPreview = debounce(renderPreview, 150);

  function renderPreview() {
    var $preview = $('#hlm-filters-preview');
    if (!$preview.length) { return; }
    $preview.empty();

    $('#hlm-filters-list .hlm-filter-row').each(function () {
      var $row       = $(this);
      var label      = $row.find('[name*="[label]"]').val() || 'Filter';
      var type       = $row.find('[name*="[type]"]').val() || 'checkbox';
      var swatchType = $row.find('[name*="[ui][swatch_type]"]').val() || 'color';

      var $card   = $('<div class="hlm-admin-preview-card"></div>');
      var $header = $('<div class="hlm-admin-preview-header"></div>')
        .append('<h4>' + $('<span>').text(label).html() + '</h4>')
        .append('<span class="hlm-admin-preview-badge">' + $('<span>').text(type).html() + '</span>');
      $card.append($header);

      var $items = $('<div class="hlm-admin-preview-items"></div>');

      if (type === 'swatch') {
        $items.addClass('hlm-preview-swatches');
        if (swatchType === 'color') {
          ['#ef4444', '#3b82f6', '#22c55e', '#f59e0b'].forEach(function (c) {
            $items.append($('<span class="hlm-admin-swatch"></span>').css('background', c));
          });
        } else if (swatchType === 'image') {
          for (var i = 0; i < 3; i++) {
            $items.append('<span class="hlm-admin-swatch hlm-swatch-image">\uD83D\uDDBC\uFE0F</span>');
          }
        } else {
          ['S', 'M', 'L', 'XL'].forEach(function (s) {
            $items.append('<span class="hlm-admin-swatch hlm-swatch-text">' + s + '</span>');
          });
        }
      } else if (type === 'dropdown') {
        $items.addClass('hlm-preview-dropdown');
        $items.append('<div class="hlm-admin-dropdown"><span>Select option</span><span class="dashicons dashicons-arrow-down-alt2"></span></div>');
      } else if (type === 'range') {
        $items.addClass('hlm-preview-range');
        $items.append('<div class="hlm-admin-range"><span>Min</span><span class="hlm-range-separator">\u2014</span><span>Max</span></div>');
      } else if (type === 'slider') {
        $items.addClass('hlm-preview-range');
        $items.append('<div class="hlm-admin-range"><span>Min</span><span class="hlm-range-slider"></span><span>Max</span></div>');
      } else {
        $items.addClass('hlm-preview-checkboxes');
        ['Option A', 'Option B', 'Option C'].forEach(function (o) {
          $items.append('<label class="hlm-admin-checkbox"><input type="checkbox" disabled><span>' + o + '</span></label>');
        });
      }

      $card.append($items);
      $preview.append($card);
    });
  }

  /* ------------------------------------------------------------------
   * Predefined Color Code Population
   * ----------------------------------------------------------------*/

  /**
   * Build lookup indexes from the color codes array.
   * Returns { byId, bySlug, byName } for the three match tiers.
   */
  function buildColorIndex(colorData) {
    var byId   = {};
    var bySlug = {};
    var byName = {};

    colorData.forEach(function (entry) {
      var hex = (entry.hex || '').trim();
      if (!hex) { return; }
      if (entry.term_id) { byId[entry.term_id]                        = hex; }
      if (entry.slug)    { bySlug[entry.slug.toLowerCase()]            = hex; }
      if (entry.name)    { byName[entry.name.toLowerCase().trim()]     = hex; }
    });

    return { byId: byId, bySlug: bySlug, byName: byName };
  }

  /**
   * Match each color input against the predefined color table.
   * Match order: term_id → slug → display name (case-insensitive).
   * Fires .trigger('input') on each updated field so the preview updates.
   */
  function populatePredefinedColors($list, $actions) {
    var $btn = $actions.find('.hlm-populate-colors');

    function applyColors(colorData) {
      var idx     = buildColorIndex(colorData);
      var matched = 0;
      var total   = 0;

      $list.find('input[data-term-id]').each(function () {
        total++;
        var $input   = $(this);
        var termId   = parseInt($input.attr('data-term-id'), 10);
        var termSlug = ($input.attr('data-term-slug') || '').toLowerCase();
        var termName = ($input.attr('data-term-name') || '').toLowerCase().trim();

        // Tier 1: exact term_id  →  Tier 2: slug  →  Tier 3: display name
        var hex = idx.byId[termId] || idx.bySlug[termSlug] || idx.byName[termName] || null;
        if (hex) {
          $input.val(hex).trigger('input');
          matched++;
        }
      });

      // Upsert the status notice inside the actions bar
      var $notice = $actions.find('.hlm-populate-notice');
      if (!$notice.length) {
        $notice = $('<span class="hlm-populate-notice"></span>');
        $actions.prepend($notice);
      }
      $notice
        .text('Matched ' + matched + ' of ' + total + ' terms')
        .toggleClass('hlm-populate-notice--partial', matched < total)
        .toggleClass('hlm-populate-notice--full',    matched === total && total > 0);
    }

    // Use in-request cache so repeated clicks don't re-fetch
    if (_colorCodesCache) {
      applyColors(_colorCodesCache);
      return;
    }

    $btn.prop('disabled', true).addClass('is-loading').text('Loading\u2026');

    $.post(HLMFiltersAdmin.ajaxUrl, {
      action: 'hlm_get_color_codes',
      nonce:  HLMFiltersAdmin.nonce
    }).done(function (response) {
      if (response && response.success && Array.isArray(response.data)) {
        _colorCodesCache = response.data;
        applyColors(_colorCodesCache);
      } else {
        alert('Could not load predefined color codes.');
      }
    }).fail(function () {
      alert('Failed to fetch predefined color codes.');
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading').text('Populate Predefined Colors');
    });
  }

  /* ------------------------------------------------------------------
   * Swatch Modal
   * ----------------------------------------------------------------*/
  function parseSwatchMap(text) {
    var map = {};
    text.split(/\r?\n/).forEach(function (line) {
      var parts = line.split(':');
      if (parts.length < 2) { return; }
      var id    = parts.shift().trim();
      var value = parts.join(':').trim();
      if (id) { map[id] = value; }
    });
    return map;
  }

  function serializeSwatchMap(map) {
    return Object.keys(map)
      .filter(function (k) { return map[k] !== ''; })
      .map(function (k) { return k + ': ' + map[k]; })
      .join('\n');
  }

  function openSwatchModal($row) {
    var dataSource = $row.find('.hlm-data-source').val();
    var sourceKey  = $row.find('.hlm-source-key').val();
    var swatchType = $row.find('[name*="[ui][swatch_type]"]').val() || 'color';
    var $textarea  = $row.find('textarea[name*="[swatch_map]"]');
    var map        = parseSwatchMap($textarea.val() || '');

    var taxonomy = sourceKey;
    if (dataSource === 'attribute' && sourceKey) {
      taxonomy = 'pa_' + sourceKey.replace(/^pa_/, '');
    }

    if (!taxonomy) {
      alert(HLMFiltersAdmin.i18n.noSource);
      return;
    }

    $.post(HLMFiltersAdmin.ajaxUrl, {
      action: 'hlm_get_terms',
      nonce: HLMFiltersAdmin.nonce,
      taxonomy: taxonomy
    }).done(function (response) {
      if (!response || !response.success) {
        alert(HLMFiltersAdmin.i18n.termLoadFail);
        return;
      }

      var $overlay = $('<div class="hlm-admin-modal" role="dialog" aria-modal="true"></div>');
      var $content = $('<div class="hlm-admin-modal-content"></div>');

      var $header = $('<div class="hlm-admin-modal-header"></div>');
      $header.append('<h3>Swatch Editor</h3>');
      var $close = $('<button type="button" class="button">Close</button>');
      $header.append($close);

      var $list = $('<div class="hlm-admin-modal-list"></div>');

      response.data.terms.forEach(function (term) {
        var value = map[term.id] || term.meta.color || term.meta.swatch_color || '';
        var $mrow = $('<div class="hlm-admin-modal-row"></div>');

        var $label   = $('<div class="hlm-admin-modal-row-label"></div>');
        var $preview = $('<div class="hlm-swatch-preview"></div>');

        if (swatchType === 'color' && value) {
          $preview.addClass('is-color').css('background-color', value);
        } else if (swatchType === 'image' && value) {
          $preview.append($('<img alt="">').attr('src', value));
        } else if (swatchType === 'text') {
          $preview.text(value || '?');
        }

        $label.append($preview);
        $label.append('<span>' + $('<span>').text(term.name).html() + ' <small>(#' + term.id + ')</small></span>');
        $mrow.append($label);

        var inputType   = swatchType === 'color' ? 'color' : 'text';
        var placeholder = swatchType === 'color' ? '#000000' : (swatchType === 'image' ? 'https://...' : 'Text label');
        var $input      = $('<input>')
          .attr({
            type: inputType,
            placeholder: placeholder,
            'data-term-id':   term.id,
            'data-term-slug': term.slug || '',
            'data-term-name': term.name || ''
          })
          .val(value);

        $input.on('input', function () {
          var nv = $(this).val();
          if (swatchType === 'color') {
            $preview.css('background-color', nv);
          } else if (swatchType === 'image') {
            $preview.find('img').remove();
            if (nv) { $preview.append($('<img alt="">').attr('src', nv)); }
          } else {
            $preview.text(nv || '?');
          }
        });

        $mrow.append($input);
        $list.append($mrow);
      });

      var $actions  = $('<div class="hlm-admin-modal-actions"></div>');
      var $save     = $('<button type="button" class="button button-primary">Save</button>');

      if (swatchType === 'color') {
        var $populate = $('<button type="button" class="button hlm-populate-colors">Populate Predefined Colors</button>');
        $actions.append($populate);
        $populate.on('click', function () {
          populatePredefinedColors($list, $actions);
        });
      }

      $actions.append($save);

      $content.append($header, $list, $actions);
      $overlay.append($content);
      $('body').append($overlay);

      $close.on('click', function () { $overlay.remove(); });
      $overlay.on('click', function (e) {
        if (e.target === this) { $overlay.remove(); }
      });

      $save.on('click', function () {
        var nextMap = {};
        $list.find('input').each(function () {
          nextMap[$(this).data('term-id')] = $(this).val();
        });
        $textarea.val(serializeSwatchMap(nextMap));
        $overlay.remove();
      });
    });
  }

  /* ------------------------------------------------------------------
   * Validation
   * ----------------------------------------------------------------*/
  function validateField($field) {
    var $input  = $field.find('input[data-required], select[data-required]');
    if (!$input.length) { return true; }
    var isValid = !!($input.val() && $input.val().trim());
    $field.toggleClass('is-invalid', !isValid);
    return isValid;
  }

  function validateAllFilters() {
    var isValid = true;
    $('#hlm-filters-list .hlm-filter-row .hlm-filter-field').each(function () {
      if (!validateField($(this))) { isValid = false; }
    });
    return isValid;
  }

  /* ------------------------------------------------------------------
   * Visibility mode
   * ----------------------------------------------------------------*/
  function updateVisibilityMode($row, type) {
    var selector   = type === 'category' ? '.hlm-category-select' : '.hlm-tag-select';
    var $container = $row.find(selector);
    var mode       = $row.find('[name*="[' + type + '_mode]"]:checked').val() || 'all';

    $container.attr('data-mode', mode);
    $container.find('.hlm-visibility-include').toggle(mode === 'include');
    $container.find('.hlm-visibility-exclude').toggle(mode === 'exclude');
  }

  /* ------------------------------------------------------------------
   * Add filter
   * ----------------------------------------------------------------*/
  function addFilter() {
    var template = $('#hlm-filter-template').html();
    var index    = $('#hlm-filters-list .hlm-filter-row').length;
    var html     = template.replace(/__INDEX__/g, index);

    $('#hlm-filters-list').append(html);
    var $row = $('#hlm-filters-list .hlm-filter-row').last();

    applyAutoValues($row);
    updateTypeVisibility($row);
    updateSourcePickerFromFields($row);
    updateVisibilityMode($row, 'category');
    updateVisibilityMode($row, 'tag');
    populateExcludeTerms($row, false);
    renderPreview();
  }

  /* ------------------------------------------------------------------
   * DOM Ready
   * ----------------------------------------------------------------*/
  $(function () {
    var $list = $('#hlm-filters-list');

    // Sortable
    if ($list.length) {
      $list.sortable({
        handle: '.hlm-filter-handle',
        update: function () {
          updateIndices($list);
          renderPreview();
        }
      });
    }

    // Add filter
    $('#hlm-add-filter').on('click', function (e) {
      e.preventDefault();
      addFilter();
    });

    // Remove filter (with confirm)
    $(document).on('click', '.hlm-remove-filter', function (e) {
      e.preventDefault();
      var label = $(this).closest('.hlm-filter-row').find('.hlm-filter-title-text').text();
      if (!confirm(HLMFiltersAdmin.i18n.confirmRemove)) { return; }
      $(this).closest('.hlm-filter-row').remove();
      updateIndices($list);
      renderPreview();
    });

    // Tab switching
    $(document).on('click', '.hlm-filter-row .hlm-tab-button', function (e) {
      e.preventDefault();
      switchTab($(this).closest('.hlm-filter-row'), $(this).data('tab'));
    });

    // Header click toggles tabs
    $(document).on('click', '.hlm-filter-header', function (e) {
      if ($(e.target).closest('button').length) { return; }
      var $tabs = $(this).closest('.hlm-filter-card').find('.hlm-filter-tabs');
      $tabs.toggle();
    });

    // Expand / Collapse
    $('#hlm-expand-all').on('click', function (e) { e.preventDefault(); expandAllFilters(); });
    $('#hlm-collapse-all').on('click', function (e) { e.preventDefault(); collapseAllFilters(); });

    // Title live update
    $(document).on('input', '[name*="[label]"]', function () {
      $(this).closest('.hlm-filter-row').find('.hlm-filter-title-text').text($(this).val() || 'New Filter');
    });

    // Label blur -> auto-fill key/id
    $(document).on('blur', '[name*="[label]"]', function () {
      applyAutoValues($(this).closest('.hlm-filter-row'));
      debouncedPreview();
    });

    // Type change
    $(document).on('change', '[name*="[type]"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-badge-type').text($(this).val());
      updateTypeVisibility($row);
      renderPreview();
    });

    // Source picker change
    $(document).on('change', '.hlm-source-picker', function () {
      var $row = $(this).closest('.hlm-filter-row');
      updateSourceFromPicker($row);
      $row.find('.hlm-badge-source').text($row.find('.hlm-data-source').val());
      renderPreview();
    });

    // Data source hidden field change
    $(document).on('change', '.hlm-data-source', function () {
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-badge-source').text($(this).val());
      applyAutoValues($row);
      updateSourcePickerFromFields($row);
      renderPreview();
    });

    // Meta source input
    $(document).on('input', '[name*="[meta_source]"]', function () {
      var $row     = $(this).closest('.hlm-filter-row');
      var metaVal  = $(this).val();

      $row.find('.hlm-source-key').val(metaVal);
      $row.find('.hlm-data-source').val('meta');

      if (metaVal) {
        var cleanLabel = metaVal.replace(/^_/, '').replace(/_/g, ' ');
        cleanLabel = cleanLabel.charAt(0).toUpperCase() + cleanLabel.slice(1);
        autoFillFromLabel($row, cleanLabel + ' Range', metaVal.replace(/^_/, ''));
      }
      debouncedPreview();
    });

    // Custom source input
    $(document).on('input', '[name*="[custom_source]"]', function () {
      var $row     = $(this).closest('.hlm-filter-row');
      var customVal = $(this).val();

      $row.find('.hlm-source-key').val(customVal);
      $row.find('.hlm-data-source').val('taxonomy');

      if (customVal) {
        var cleanLabel = customVal.replace(/^pa_/, '').replace(/_/g, ' ');
        cleanLabel = cleanLabel.charAt(0).toUpperCase() + cleanLabel.slice(1);
        autoFillFromLabel($row, cleanLabel, customVal);
      }

      updateSourcePickerFromFields($row);
      debouncedPreview();
    });

    // Source key hidden change
    $(document).on('change', '.hlm-source-key', function () {
      updateSourcePickerFromFields($(this).closest('.hlm-filter-row'));
    });

    // Swatch editor
    $(document).on('click', '.hlm-edit-swatch', function (e) {
      e.preventDefault();
      openSwatchModal($(this).closest('.hlm-filter-row'));
    });

    // Swatch type change -> preview update
    $(document).on('change', '[name*="[ui][swatch_type]"]', function () { renderPreview(); });

    // Select all / Clear all
    $(document).on('click', '.hlm-select-all', function (e) {
      e.preventDefault();
      $('#' + $(this).data('target')).find('option').prop('selected', true);
    });
    $(document).on('click', '.hlm-clear-all', function (e) {
      e.preventDefault();
      $('#' + $(this).data('target')).find('option').prop('selected', false);
    });

    // Visibility mode radios
    $(document).on('change', '[name*="[category_mode]"]', function () {
      updateVisibilityMode($(this).closest('.hlm-filter-row'), 'category');
    });
    $(document).on('change', '[name*="[tag_mode]"]', function () {
      updateVisibilityMode($(this).closest('.hlm-filter-row'), 'tag');
    });

    // Validation
    $(document).on('blur', 'input[data-required], select[data-required]', function () {
      validateField($(this).closest('.hlm-filter-field'));
    });
    $(document).on('input change', 'input[data-required], select[data-required]', function () {
      var $field = $(this).closest('.hlm-filter-field');
      if ($field.hasClass('is-invalid')) { validateField($field); }
    });

    // Form submit validation
    $('form').on('submit', function (e) {
      if (!validateAllFilters()) {
        e.preventDefault();
        alert(HLMFiltersAdmin.i18n.validationFail);
        var $first = $('.hlm-filter-field.is-invalid').first();
        if ($first.length) {
          var $row   = $first.closest('.hlm-filter-row');
          var $panel = $first.closest('.hlm-tab-panel');
          if ($panel.length) { switchTab($row, $panel.data('tab')); }
          $row.find('.hlm-filter-tabs').show();
          $first.find('input, select').focus();
        }
        return false;
      }
    });

    // Import file handling
    $(document).on('change', '.hlm-import-input', function () {
      var $label  = $(this).closest('.hlm-import-label');
      var $submit = $(this).closest('.hlm-import-form').find('.hlm-import-submit');
      var $text   = $label.find('.hlm-import-text');
      if (this.files && this.files.length > 0) {
        $label.addClass('has-file');
        $text.text(this.files[0].name);
        $submit.prop('disabled', false);
      } else {
        $label.removeClass('has-file');
        $text.text('Import Filters');
        $submit.prop('disabled', true);
      }
    });
    $(document).on('submit', '.hlm-import-form', function () {
      return confirm(HLMFiltersAdmin.i18n.confirmReplace);
    });

    // Exclude terms: Select all/Clear
    $(document).on('click', '.hlm-select-all-terms', function (e) {
      e.preventDefault();
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-exclude-terms-select option').prop('selected', true);
    });
    $(document).on('click', '.hlm-clear-all-terms', function (e) {
      e.preventDefault();
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-exclude-terms-select option').prop('selected', false);
    });

    // Populate exclude terms when source changes
    function populateExcludeTerms($row, showLoader) {
      var dataSource = $row.find('.hlm-data-source').val();
      var sourceKey  = $row.find('.hlm-source-key').val();
      var $select    = $row.find('.hlm-exclude-terms-select');
      var $loader    = $row.find('.hlm-exclude-terms-loader');
      var $actions   = $row.find('.hlm-exclude-terms-actions');

      if (!$select.length) { return; }

      // Determine taxonomy
      var taxonomy = sourceKey;
      if (dataSource === 'attribute' && sourceKey) {
        taxonomy = 'pa_' + sourceKey.replace(/^pa_/, '');
      } else if (dataSource === 'meta') {
        // Meta filters don't have terms to exclude
        $select.empty().prop('disabled', true).hide();
        $loader.hide();
        $actions.hide();
        return;
      }

      if (!taxonomy) {
        $select.empty().prop('disabled', true).hide();
        $loader.hide();
        $actions.hide();
        return;
      }

      // Store currently selected values
      var selectedValues = [];
      $select.find('option:selected').each(function () {
        selectedValues.push($(this).val());
      });

      // Show loader if requested
      if (showLoader) {
        $select.hide();
        $actions.hide();
        $loader.show();
      } else {
        $select.prop('disabled', true);
      }

      // Fetch terms via AJAX
      $.post(HLMFiltersAdmin.ajaxUrl, {
        action: 'hlm_get_terms',
        nonce: HLMFiltersAdmin.nonce,
        taxonomy: taxonomy
      }).done(function (response) {
        if (response && response.success && response.data && response.data.terms) {
          $select.empty();
          response.data.terms.forEach(function (term) {
            var $option = $('<option></option>')
              .val(term.id)
              .text(term.name);
            if (selectedValues.indexOf(String(term.id)) !== -1) {
              $option.prop('selected', true);
            }
            $select.append($option);
          });
          $select.prop('disabled', false).show();
          $select.data('terms-loaded', true); // Mark as fully loaded
          $actions.show();
        } else {
          $select.empty().prop('disabled', true).show();
          $actions.show();
        }
        $loader.hide();
      }).fail(function () {
        $select.empty().prop('disabled', true).show();
        $actions.show();
        $loader.hide();
      });
    }

    // Trigger population when source changes
    $(document).on('change', '.hlm-source-picker', function () {
      var $row = $(this).closest('.hlm-filter-row');
      setTimeout(function () { populateExcludeTerms($row, false); }, 100);
    });
    $(document).on('change input', '[name*="[custom_source]"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      setTimeout(function () { populateExcludeTerms($row, false); }, 100);
    });
    $(document).on('change', '.hlm-data-source, .hlm-source-key', function () {
      var $row = $(this).closest('.hlm-filter-row');
      setTimeout(function () { populateExcludeTerms($row, false); }, 100);
    });

    // When visibility tab is clicked, load terms with loader
    $(document).on('click', '.hlm-filter-row .hlm-tab-button[data-tab="visibility"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      var $select = $row.find('.hlm-exclude-terms-select');
      // Check if we need to fetch terms:
      // - Select is empty (not yet loaded), OR
      // - Select only has selected options (pre-populated from server but missing full list)
      var needsFetch = $select.length && (
        $select.find('option').length === 0 ||
        ($select.find('option:selected').length > 0 && !$select.data('terms-loaded'))
      );
      if (needsFetch) {
        setTimeout(function () { populateExcludeTerms($row, true); }, 50);
      }
    });

    // Prevent drag-to-select behavior on multi-select
    // Only allow click-based selection
    $(document).on('mousedown', '.hlm-exclude-terms-select', function (e) {
      e.preventDefault();
      var $option = $(e.target);

      if ($option.is('option')) {
        var wasSelected = $option.prop('selected');
        $option.prop('selected', !wasSelected);
      }

      return false;
    });

    // Prevent text selection during interaction
    $(document).on('mousemove', '.hlm-exclude-terms-select', function (e) {
      e.preventDefault();
      return false;
    });

    // Initialize existing rows
    $list.find('.hlm-filter-row').each(function () {
      var $row = $(this);
      updateTypeVisibility($row);
      updateSourcePickerFromFields($row);
      updateVisibilityMode($row, 'category');
      updateVisibilityMode($row, 'tag');
      // Don't auto-populate on page load - only when visibility tab is clicked
      // This avoids unnecessary AJAX calls for all filters on page load
    });

    renderPreview();
  });
})(jQuery);