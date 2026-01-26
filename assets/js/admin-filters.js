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
      var style = $row.find('[name*="[ui][style]"]').val() || 'list';
      var card = $('<div class=\"hlm-admin-preview-card\"></div>');
      card.append('<h4>' + label + '</h4>');

      var items = $('<div class=\"hlm-admin-preview-items\"></div>');
      if (style === 'swatch') {
        items.append('<span class=\"hlm-admin-chip\">Swatch</span>');
        items.append('<span class=\"hlm-admin-chip\">Swatch</span>');
      } else if (style === 'dropdown') {
        items.append('<span class=\"hlm-admin-chip\">Dropdown</span>');
      } else {
        items.append('<span class=\"hlm-admin-chip\">Option A</span>');
        items.append('<span class=\"hlm-admin-chip\">Option B</span>');
        items.append('<span class=\"hlm-admin-chip\">Option C</span>');
      }
      card.append(items);
      $preview.append(card);
    });
  }

  function toggleCard($button) {
    var $card = $button.closest('.hlm-filter-card');
    var $fields = $card.find('.hlm-filter-fields');
    var isCollapsed = $card.hasClass('is-collapsed');
    if (isCollapsed) {
      $card.removeClass('is-collapsed');
      $fields.slideDown(150);
      $button.html('<span class=\"dashicons dashicons-arrow-up-alt2\"></span>Collapse').attr('aria-expanded', 'true');
      return;
    }
    $card.addClass('is-collapsed');
    $fields.slideUp(150);
    $button.html('<span class=\"dashicons dashicons-arrow-down-alt2\"></span>Expand').attr('aria-expanded', 'false');
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
        $row.append('<span>' + term.name + ' (#' + term.id + ')</span>');
        var inputType = swatchType === 'color' ? 'color' : 'text';
        var $input = $('<input type=\"' + inputType + '\" data-term-id=\"' + term.id + '\" value=\"' + value + '\">');
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

  function addFilter() {
    var template = $('#hlm-filter-template').html();
    var index = $('#hlm-filters-list .hlm-filter-row').length;
    var html = template.replace(/__INDEX__/g, index);
    $('#hlm-filters-list').append(html);
    applyAutoValues($('#hlm-filters-list .hlm-filter-row').last());
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

    $(document).on('change', '[name*=\"[data_source]\"]', function () {
      applyAutoValues($(this).closest('.hlm-filter-row'));
      renderPreview();
    });

    $(document).on('blur', '[name*=\"[label]\"]', function () {
      applyAutoValues($(this).closest('.hlm-filter-row'));
      renderPreview();
    });

    $(document).on('change', '[name*=\"[ui][style]\"]', function () {
      renderPreview();
    });

    $(document).on('click', '.hlm-edit-swatch', function (event) {
      event.preventDefault();
      openSwatchModal($(this).closest('.hlm-filter-row'));
    });

    $(document).on('click', '.hlm-toggle-filter', function (event) {
      event.preventDefault();
      toggleCard($(this));
    });

    $(document).on('input', '[name*=\"[label]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      $row.find('.hlm-filter-title-text').text($(this).val() || 'New Filter');
    });

    $(document).on('change', '[name*=\"[type]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      var type = $(this).val();
      var source = $row.find('[name*=\"[data_source]\"]').val();
      $row.find('.hlm-filter-meta').text(type + ' · ' + source);
    });

    $(document).on('change', '[name*=\"[data_source]\"]', function () {
      var $row = $(this).closest('.hlm-filter-row');
      var type = $row.find('[name*=\"[type]\"]').val();
      var source = $(this).val();
      $row.find('.hlm-filter-meta').text(type + ' · ' + source);
    });

    renderPreview();
  });
})(jQuery);
