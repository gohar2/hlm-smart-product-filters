(function ($) {
  function updateIndices($list) {
    $list.find('.hlm-filter-row').each(function (index) {
      $(this)
        .find('[name^="filters["]')
        .each(function () {
          var name = $(this).attr('name');
          var updated = name.replace(/filters\[[^\]]+\]/, 'filters[' + index + ']');
          $(this).attr('name', updated);
        });
    });
  }

  function normalizeKey(value) {
    return value.toString().trim().toLowerCase().replace(/\s+/g, '_');
  }

  function applyAutoValues($row) {
    var dataSource = $row.find('[name*="[data_source]"]').val();
    var sourceKeyInput = $row.find('[name*="[source_key]"]');
    if (dataSource === 'product_cat') {
      sourceKeyInput.val('product_cat');
    }
    if (dataSource === 'product_tag') {
      sourceKeyInput.val('product_tag');
    }

    var labelInput = $row.find('[name*="[label]"]');
    var keyInput = $row.find('[name*="[key]"]');
    if (!keyInput.val() && labelInput.val()) {
      keyInput.val(normalizeKey(labelInput.val()));
    }
    var idInput = $row.find('[name*="[id]"]');
    if (!idInput.val() && keyInput.val()) {
      idInput.val(normalizeKey(keyInput.val()));
    }
  }

  function renderPreview() {
    var $preview = $('#hlm-filters-preview');
    if (!$preview.length) {
      return;
    }
    $preview.empty();

    $('#hlm-filters-list .hlm-filter-row').each(function () {
      var $row = $(this);
      var label = $row.find('[name*="[label]"]').val() || 'Filter';
      var type = $row.find('[name*="[type]"]').val() || 'checkbox';
      var dataSource = $row.find('[name*="[data_source]"]').val() || 'taxonomy';
      var swatchType = $row.find('[name*="[ui][swatch_type]"]').val() || 'color';

      var card = $('<div class=\"hlm-admin-preview-card\"></div>');

      var header = $('<div class=\"hlm-admin-preview-header\"></div>');
      header.append('<h4>' + label + '</h4>');
      header.append('<span class=\"hlm-admin-preview-badge\">' + type + '</span>');
      card.append(header);

      var items = $('<div class=\"hlm-admin-preview-items\"></div>');

      if (type === 'swatch') {
        items.addClass('hlm-preview-swatches');
        if (swatchType === 'color') {
          items.append('<span class=\"hlm-admin-swatch\" style=\"background: #ef4444;\"></span>');
          items.append('<span class=\"hlm-admin-swatch\" style=\"background: #3b82f6;\"></span>');
          items.append('<span class=\"hlm-admin-swatch\" style=\"background: #22c55e;\"></span>');
          items.append('<span class=\"hlm-admin-swatch\" style=\"background: #f59e0b;\"></span>');
        } else if (swatchType === 'image') {
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-image\">🖼️</span>');
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-image\">🖼️</span>');
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-image\">🖼️</span>');
        } else {
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-text\">S</span>');
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-text\">M</span>');
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-text\">L</span>');
          items.append('<span class=\"hlm-admin-swatch hlm-swatch-text\">XL</span>');
        }
      } else if (type === 'dropdown') {
        items.addClass('hlm-preview-dropdown');
        items.append('<div class=\"hlm-admin-dropdown\"><span>Select ' + dataSource + '</span><span class=\"dashicons dashicons-arrow-down-alt2\"></span></div>');
      } else if (type === 'range') {
        items.addClass('hlm-preview-range');
        items.append('<div class=\"hlm-admin-range\"><span>Min</span><span class=\"hlm-range-slider\"></span><span>Max</span></div>');
      } else {
        items.addClass('hlm-preview-checkboxes');
        items.append('<label class=\"hlm-admin-checkbox\"><input type=\"checkbox\"><span>Option A</span></label>');
        items.append('<label class=\"hlm-admin-checkbox\"><input type=\"checkbox\"><span>Option B</span></label>');
        items.append('<label class=\"hlm-admin-checkbox\"><input type=\"checkbox\"><span>Option C</span></label>');
      }

      card.append(items);
      $preview.append(card);
    });
  }

  function updateTypeVisibility($row) {
    var type = $row.find('[name*="[type]"]').val() || 'checkbox';
    var isSwatch = type === 'swatch';
    var showMore = type === 'checkbox' || type === 'swatch';
    var isList = type === 'checkbox';
    $row.find('.hlm-swatch-only').toggleClass('is-hidden', !isSwatch);
    $row.find('.hlm-show-more-only').toggleClass('is-hidden', !showMore);
    $row.find('.hlm-list-only').toggleClass('is-hidden', !isList);
  }

  function updateSourceFromPicker($row) {
    var value = $row.find('.hlm-source-picker').val() || '';
    var $customField = $row.find('[name*="[custom_source]"]').closest('.hlm-custom-source');
    var $customInput = $row.find('[name*="[custom_source]"]');
    var $dataSource = $row.find('.hlm-data-source');
    var $sourceKey = $row.find('.hlm-source-key');
    if (!value) {
      $customField.addClass('is-hidden');
      return;
    }
    if (value === 'product_cat' || value === 'product_tag') {
      $dataSource.val(value);
      $sourceKey.val(value);
      $customField.addClass('is-hidden');
    } else {
      if (value === 'custom') {
        $dataSource.val('taxonomy');
        $customField.removeClass('is-hidden');
        if ($customInput.val()) {
          $sourceKey.val($customInput.val());
        }
      } else {
        $dataSource.val('attribute');
        $sourceKey.val(value);
        $customField.addClass('is-hidden');
      }
    }

    var keyInput = $row.find('[name*="[key]"]');
    if (!keyInput.val() && value !== 'custom') {
      keyInput.val(normalizeKey(value));
    }
    applyAutoValues($row);
    $dataSource.trigger('change');
    $sourceKey.trigger('change');
  }

  function updateSourcePickerFromFields($row) {
    var dataSource = $row.find('.hlm-data-source').val();
    var sourceKey = $row.find('.hlm-source-key').val();
    var $picker = $row.find('.hlm-source-picker');
    var $customField = $row.find('[name*="[custom_source]"]').closest('.hlm-custom-source');
    var $customInput = $row.find('[name*="[custom_source]"]');
    if (!$picker.length) {
      return;
    }
    if (dataSource === 'product_cat' || dataSource === 'product_tag') {
      $picker.val(dataSource);
      $customField.addClass('is-hidden');
      return;
    }
    if (dataSource === 'attribute' && sourceKey) {
      $picker.val(sourceKey);
      $customField.addClass('is-hidden');
      return;
    }
    if (dataSource === 'taxonomy' && sourceKey) {
      $picker.val('custom');
      $customInput.val(sourceKey);
      $customField.removeClass('is-hidden');
      return;
    }
    $picker.val('');
    $customField.addClass('is-hidden');
  }

  function expandAllFilters() {
    $('#hlm-filters-list .hlm-filter-card').prop('open', true);
  }

  function collapseAllFilters() {
    $('#hlm-filters-list .hlm-filter-card').prop('open', false);
  }

  function parseSwatchMap(text) {
    var map = {};
    text.split(/\\r?\\n/).forEach(function (line) {
      var parts = line.split(':');
      if (parts.length < 2) {
        return;
      }
      var id = parts.shift().trim();
      var value = parts.join(':').trim();
      if (id) {
        map[id] = value;
      }
    });
    return map;
  }

  function serializeSwatchMap(map) {
    return Object.keys(map)
      .filter(function (key) {
        return map[key] !== '';
      })
      .map(function (key) {
        return key + ': ' + map[key];
      })
      .join('\\n');
  }

  function openSwatchModal($row) {
    var dataSource = $row.find('[name*="[data_source]"]').val();
    var sourceKey = $row.find('[name*="[source_key]"]').val();
    var swatchType = $row.find('[name*="[ui][swatch_type]"]').val() || 'color';
    var $textarea = $row.find('textarea[name*="[swatch_map]"]');
    var map = parseSwatchMap($textarea.val() || '');

    var taxonomy = sourceKey;
    if (dataSource === 'attribute' && sourceKey) {
      taxonomy = 'pa_' + sourceKey.replace(/^pa_/, '');
    }

    if (!taxonomy) {
      alert('Please set a valid source key first.');
      return;
    }

    $.post(HLMFiltersAdmin.ajaxUrl, {
      action: 'hlm_get_terms',
      nonce: HLMFiltersAdmin.nonce,
      taxonomy: taxonomy
    }).done(function (response) {
      if (!response || !response.success) {
        alert('Failed to load terms.');
        return;
      }

      var $modal = $('<div class=\"hlm-admin-modal\" role=\"dialog\" aria-modal=\"true\"></div>');
      var $content = $('<div class=\"hlm-admin-modal-content\"></div>');
      var $header = $('<div class=\"hlm-admin-modal-header\"></div>');
      $header.append('<h3>Swatch Editor</h3>');
      var $close = $('<button type=\"button\" class=\"button\">Close</button>');
      $header.append($close);

      var $list = $('<div class=\"hlm-admin-modal-list\"></div>');
      response.data.terms.forEach(function (term) {
        var value = map[term.id] || term.meta.color || term.meta.swatch_color || '';
        var $row = $('<div class=\"hlm-admin-modal-row\"></div>');

        var $label = $('<div class=\"hlm-admin-modal-row-label\"></div>');
        var $preview = $('<div class=\"hlm-swatch-preview\"></div>');

        if (swatchType === 'color' && value) {
          $preview.addClass('is-color').css('background-color', value);
        } else if (swatchType === 'image' && value) {
          $preview.append('<img src=\"' + value + '\" alt=\"\">');
        } else if (swatchType === 'text') {
          $preview.text(value || '?');
        }

        $label.append($preview);
        $label.append('<span>' + term.name + ' <small>(#' + term.id + ')</small></span>');
        $row.append($label);

        var inputType = swatchType === 'color' ? 'color' : 'text';
        var placeholder = swatchType === 'color' ? '#000000' : (swatchType === 'image' ? 'https://...' : 'Text label');
        var $input = $('<input type=\"' + inputType + '\" data-term-id=\"' + term.id + '\" value=\"' + value + '\" placeholder=\"' + placeholder + '\">');

        $input.on('input', function () {
          var newValue = $(this).val();
          if (swatchType === 'color') {
            $preview.css('background-color', newValue);
          } else if (swatchType === 'image') {
            $preview.find('img').remove();
            if (newValue) {
              $preview.append('<img src=\"' + newValue + '\" alt=\"\">');
            }
          } else if (swatchType === 'text') {
            $preview.text(newValue || '?');
          }
        });

        $row.append($input);
        $list.append($row);
      });

      var $actions = $('<div class=\"hlm-admin-modal-actions\"></div>');
      var $save = $('<button type=\"button\" class=\"button button-primary\">Save</button>');
      $actions.append($save);

      $content.append($header).append($list).append($actions);
      $modal.append($content);
      $('body').append($modal);

      $close.on('click', function () {
        $modal.remove();
      });

      $save.on('click', function () {
        var nextMap = {};
        $list.find('input').each(function () {
          var id = $(this).data('term-id');
          var val = $(this).val();
          nextMap[id] = val;
        });
        $textarea.val(serializeSwatchMap(nextMap));
        $modal.remove();
      });
    });
  }

  function validateField($field) {
    var $input = $field.find('input[data-required], select[data-required]');
    if (!$input.length) {
      return true;
    }
    var value = $input.val();
    var isValid = value && value.trim() !== '';
    $field.toggleClass('is-invalid', !isValid);
    return isValid;
  }

  function validateRow($row) {
    var isValid = true;
    $row.find('.hlm-filter-field').each(function () {
      if (!validateField($(this))) {
        isValid = false;
      }
    });
    return isValid;
  }

  function validateAllFilters() {
    var isValid = true;
    $('#hlm-filters-list .hlm-filter-row').each(function () {
      if (!validateRow($(this))) {
        isValid = false;
      }
    });
    return isValid;
  }

  function updateVisibilityMode($row, type) {
    var $container = $row.find(type === 'category' ? '.hlm-category-select' : '.hlm-tag-select');
    var mode = $row.find('[name*="[' + type + '_mode]"]:checked').val() || 'all';
    $container.attr('data-mode', mode);
  }

  function addFilter() {
    var template = $('#hlm-filter-template').html();
    var index = $('#hlm-filters-list .hlm-filter-row').length;
    var html = template.replace(/__INDEX__/g, index);
    $('#hlm-filters-list').append(html);
    var $row = $('#hlm-filters-list .hlm-filter-row').last();
    applyAutoValues($row);
    updateTypeVisibility($row);
    renderPreview();
  }

  $(function () {
    var $list = $('#hlm-filters-list');
    if ($list.length) {
      $list.sortable({
        handle: '.hlm-filter-handle',
        update: function () {
          updateIndices($list);
          renderPreview();
        }
      });
    }

    $('#hlm-add-filter').on('click', function (event) {
      event.preventDefault();
      addFilter();
    });

    $(document).on('click', '.hlm-remove-filter', function (event) {
      event.preventDefault();
      $(this).closest('.hlm-filter-row').remove();
      updateIndices($list);
      renderPreview();
    });

    $(document).on('change', '.hlm-data-source', function () {
      applyAutoValues($(this).closest('.hlm-filter-row'));
      updateSourcePickerFromFields($(this).closest('.hlm-filter-row'));
      renderPreview();
    });

    $(document).on('blur', '[name*=\"[label]\"]', function () {
      applyAutoValues($(this).closest('.hlm-filter-row'));
      renderPreview();
    });

    $(document).on('click', '.hlm-edit-swatch', function (event) {
      event.preventDefault();
      openSwatchModal($(this).closest('.hlm-filter-row'));
    });

    $(document).on('click', '.hlm-toggle-advanced', function (event) {
      event.preventDefault();
      var $row = $(this).closest('.hlm-filter-row');
      var $advanced = $row.find('.hlm-filter-advanced');
      var isHidden = $advanced.hasClass('is-hidden');
      $advanced.toggleClass('is-hidden', !isHidden);
      $(this).attr('aria-expanded', isHidden ? 'true' : 'false');
      $(this).html('<span class=\"dashicons dashicons-admin-generic\"></span>' + (isHidden ? 'Hide advanced' : 'Advanced'));
    });

    $('#hlm-expand-all').on('click', function (event) {
      event.preventDefault();
      expandAllFilters();
    });

    $('#hlm-collapse-all').on('click', function (event) {
      event.preventDefault();
      collapseAllFilters();
    });

    $(document).on('input', '[name*=\"[label]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-filter-title-text').text($(this).val() || 'New Filter');
    });

    $(document).on('change', '[name*=\"[type]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      var type = $(this).val();
      $row.find('.hlm-badge-type').text(type);
      updateTypeVisibility($row);
      renderPreview();
    });

    $(document).on('change', '.hlm-data-source', function () {
      var $row = $(this).closest('.hlm-filter-row');
      var source = $(this).val();
      $row.find('.hlm-badge-source').text(source);
    });

    $(document).on('change', '.hlm-source-picker', function () {
      updateSourceFromPicker($(this).closest('.hlm-filter-row'));
      renderPreview();
    });

    $(document).on('input', '[name*=\"[custom_source]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-source-key').val($(this).val());
      $row.find('.hlm-data-source').val('taxonomy');
      updateSourcePickerFromFields($row);
    });

    $(document).on('change', '.hlm-source-key', function () {
      updateSourcePickerFromFields($(this).closest('.hlm-filter-row'));
    });

    $(document).on('click', '.hlm-select-all', function (event) {
      event.preventDefault();
      var target = $(this).data('target');
      var $select = $('#' + target);
      if ($select.length) {
        $select.find('option').prop('selected', true);
        $select.trigger('change');
      }
    });

    $(document).on('click', '.hlm-clear-all', function (event) {
      event.preventDefault();
      var target = $(this).data('target');
      var $select = $('#' + target);
      if ($select.length) {
        $select.find('option').prop('selected', false);
        $select.trigger('change');
      }
    });

    $(document).on('change', '[name*="[category_mode]"]', function () {
      updateVisibilityMode($(this).closest('.hlm-filter-row'), 'category');
    });

    $(document).on('change', '[name*="[tag_mode]"]', function () {
      updateVisibilityMode($(this).closest('.hlm-filter-row'), 'tag');
    });

    $(document).on('change', '[name*="[ui][swatch_type]"]', function () {
      renderPreview();
    });

    $(document).on('blur', 'input[data-required], select[data-required]', function () {
      validateField($(this).closest('.hlm-filter-field'));
    });

    $(document).on('input change', 'input[data-required], select[data-required]', function () {
      var $field = $(this).closest('.hlm-filter-field');
      if ($field.hasClass('is-invalid')) {
        validateField($field);
      }
    });

    $('form').on('submit', function (event) {
      if (!validateAllFilters()) {
        event.preventDefault();
        alert('Please fill in all required fields before saving.');
        var $firstInvalid = $('.hlm-filter-field.is-invalid').first();
        if ($firstInvalid.length) {
          $firstInvalid.find('input, select').focus();
          $firstInvalid.closest('.hlm-filter-card').prop('open', true);
        }
        return false;
      }
    });

    $('#hlm-filters-list .hlm-filter-row').each(function () {
      var $row = $(this);
      updateTypeVisibility($row);
      updateSourcePickerFromFields($row);
    });

    renderPreview();

    // Import file selection
    $(document).on('change', '.hlm-import-input', function () {
      var $label = $(this).closest('.hlm-import-label');
      var $submit = $(this).closest('.hlm-import-form').find('.hlm-import-submit');
      var $text = $label.find('.hlm-import-text');

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

    // Confirm before import
    $(document).on('submit', '.hlm-import-form', function () {
      return confirm('This will replace your current filters. Continue?');
    });
  });
})(jQuery);
