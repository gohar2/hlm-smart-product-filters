<?php

namespace HLM\Filters\Admin;

use HLM\Filters\Support\Config;

final class FiltersBuilderPage
{
    private Config $config;
    private string $page_slug = 'hlm-product-filters-builder';
    private array $attributes = [];
    private array $categories = [];
    private array $tags = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_hlm_save_filters', [$this, 'handle_save']);
        add_action('admin_post_hlm_load_sample_filters', [$this, 'handle_load_samples']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'hlm-product-filters',
            __('Filter Builder', 'hlm-smart-product-filters'),
            __('Filter Builder', 'hlm-smart-product-filters'),
            'manage_woocommerce',
            $this->page_slug,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'hlm-product-filters_page_' . $this->page_slug) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style(
            'hlm-filters-admin',
            HLM_FILTERS_URL . 'assets/css/admin-filters.css',
            [],
            HLM_FILTERS_VERSION
        );
        wp_enqueue_script(
            'hlm-filters-admin',
            HLM_FILTERS_URL . 'assets/js/admin-filters.js',
            ['jquery', 'jquery-ui-sortable'],
            HLM_FILTERS_VERSION,
            true
        );

        $this->load_data();
        wp_localize_script('hlm-filters-admin', 'HLMFiltersAdmin', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('hlm_filters_admin_nonce'),
            'categories' => $this->categories,
            'tags'       => $this->tags,
            'i18n'       => [
                'confirmRemove'  => __('Remove this filter?', 'hlm-smart-product-filters'),
                'confirmReplace' => __('This will replace your current filters. Continue?', 'hlm-smart-product-filters'),
                'validationFail' => __('Please fill in all required fields before saving.', 'hlm-smart-product-filters'),
                'noSource'       => __('Please set a valid source key first.', 'hlm-smart-product-filters'),
                'termLoadFail'   => __('Failed to load terms.', 'hlm-smart-product-filters'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     * Save Handler
     * ----------------------------------------------------------------*/
    public function handle_save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to manage filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_save_filters');

        $filters = $_POST['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $sanitized = [];
        foreach ($filters as $filter) {
            $sanitized[] = $this->sanitize_filter($filter);
        }

        $current = $this->config->get();
        $data = [
            'global'  => $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $sanitized,
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg(['page' => $this->page_slug, 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    private function sanitize_filter(array $filter): array
    {
        $clean = [
            'id'          => sanitize_key($filter['id'] ?? ''),
            'label'       => sanitize_text_field($filter['label'] ?? ''),
            'key'         => sanitize_key($filter['key'] ?? ''),
            'type'        => sanitize_key($filter['type'] ?? 'checkbox'),
            'data_source' => sanitize_key($filter['data_source'] ?? 'taxonomy'),
            'source_key'  => sanitize_text_field($filter['source_key'] ?? ''),
            'render_mode' => sanitize_key($filter['render_mode'] ?? 'both'),
            'behavior'    => [
                'multi_select' => !empty($filter['behavior']['multi_select']),
                'operator'     => in_array(($filter['behavior']['operator'] ?? 'OR'), ['OR', 'AND'], true)
                    ? $filter['behavior']['operator'] : 'OR',
            ],
            'visibility'  => $this->sanitize_visibility($filter['visibility'] ?? []),
            'ui'          => $this->sanitize_ui($filter['ui'] ?? [], $filter['type'] ?? 'checkbox'),
        ];

        // Auto-generate id/key from label if missing
        if (!$clean['id'] && $clean['label']) {
            $clean['id'] = sanitize_key(str_replace(' ', '_', strtolower($clean['label'])));
        }
        if (!$clean['key'] && $clean['id']) {
            $clean['key'] = $clean['id'];
        }

        // Parse swatch_map from textarea
        if (!empty($filter['swatch_map'])) {
            $clean['ui']['swatch_map'] = $this->parse_swatch_map_text($filter['swatch_map']);
        }

        return $clean;
    }

    private function sanitize_visibility(array $vis): array
    {
        $category_mode = $vis['category_mode'] ?? 'all';
        $tag_mode      = $vis['tag_mode'] ?? 'all';

        $clean = [
            'hide_empty'          => !empty($vis['hide_empty']),
            'include_children'    => !empty($vis['include_children']),
            'include_tag_children' => !empty($vis['include_tag_children']),
            'show_on_categories'  => [],
            'hide_on_categories'  => [],
            'show_on_tags'        => [],
            'hide_on_tags'        => [],
        ];

        if ($category_mode === 'include') {
            $clean['show_on_categories'] = array_map('absint', (array) ($vis['show_on_categories'] ?? []));
        } elseif ($category_mode === 'exclude') {
            $clean['hide_on_categories'] = array_map('absint', (array) ($vis['hide_on_categories'] ?? []));
        }

        if ($tag_mode === 'include') {
            $clean['show_on_tags'] = array_map('absint', (array) ($vis['show_on_tags'] ?? []));
        } elseif ($tag_mode === 'exclude') {
            $clean['hide_on_tags'] = array_map('absint', (array) ($vis['hide_on_tags'] ?? []));
        }

        return $clean;
    }

    private function sanitize_ui(array $ui, string $type): array
    {
        $clean = [
            'layout'              => in_array(($ui['layout'] ?? 'inherit'), ['inherit', 'stacked', 'inline'], true)
                ? $ui['layout'] : 'inherit',
            'swatch_type'         => in_array(($ui['swatch_type'] ?? 'color'), ['color', 'image', 'text'], true)
                ? $ui['swatch_type'] : 'color',
            'show_more_threshold' => absint($ui['show_more_threshold'] ?? 0),
            'range_step'          => sanitize_text_field($ui['range_step'] ?? '1'),
            'range_prefix'        => sanitize_text_field($ui['range_prefix'] ?? ''),
            'range_suffix'        => sanitize_text_field($ui['range_suffix'] ?? ''),
        ];

        return $clean;
    }

    private function parse_swatch_map_text(string $text): array
    {
        $map = [];
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $id = trim($parts[0]);
                $value = trim($parts[1]);
                if ($id !== '' && $value !== '') {
                    $map[(int) $id] = sanitize_text_field($value);
                }
            }
        }
        return $map;
    }

    /* ------------------------------------------------------------------
     * Template Loader
     * ----------------------------------------------------------------*/
    public function handle_load_samples(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to manage filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_load_sample_filters');

        $template  = isset($_GET['template']) ? sanitize_key($_GET['template']) : '';
        $templates = $this->get_filter_templates();

        if (!isset($templates[$template])) {
            $template = array_key_first($templates);
        }

        $current = $this->config->get();
        $data = [
            'global'  => $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $templates[$template]['filters'],
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg([
            'page'           => $this->page_slug,
            'samples_loaded' => 'true',
            'template_name'  => rawurlencode($templates[$template]['name']),
        ], admin_url('admin.php')));
        exit;
    }

    /* ------------------------------------------------------------------
     * Page Render
     * ----------------------------------------------------------------*/
    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $this->load_data();
        $config  = $this->config->get();
        $filters = $config['filters'] ?? [];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HLM Filters Builder', 'hlm-smart-product-filters') . '</h1>';

        $this->render_notices();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hlm_save_filters">';
        wp_nonce_field('hlm_save_filters');

        echo '<p>' . esc_html__('Create filters, reorder them, and set visibility rules.', 'hlm-smart-product-filters') . '</p>';

        $this->render_template_selector();

        echo '<div class="hlm-admin-preview">';
        echo '<h2>' . esc_html__('Live Preview', 'hlm-smart-product-filters') . '</h2>';
        echo '<div id="hlm-filters-preview"></div>';
        echo '</div>';

        if (empty($filters)) {
            echo '<div class="hlm-empty-state">';
            echo '<span class="dashicons dashicons-filter"></span>';
            echo '<h3>' . esc_html__('No filters configured yet', 'hlm-smart-product-filters') . '</h3>';
            echo '<p>' . esc_html__('Get started by selecting a template above or adding your first filter below.', 'hlm-smart-product-filters') . '</p>';
            echo '</div>';
        }

        echo '<div class="hlm-bulk-actions' . (empty($filters) ? ' is-hidden' : '') . '">';
        echo '<button type="button" class="button" id="hlm-expand-all">' . esc_html__('Expand All', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button" id="hlm-collapse-all">' . esc_html__('Collapse All', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';

        echo '<ul id="hlm-filters-list">';
        foreach ($filters as $index => $filter) {
            $this->render_filter_row($index, $filter);
        }
        echo '</ul>';

        echo '<button type="button" class="button button-primary" id="hlm-add-filter">';
        echo '<span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__('Add Filter', 'hlm-smart-product-filters');
        echo '</button>';
        echo '<p class="submit">';
        submit_button(__('Save Filters', 'hlm-smart-product-filters'), 'primary', 'submit', false);
        echo '</p>';

        echo '<script type="text/template" id="hlm-filter-template">';
        $this->render_filter_row('__INDEX__', []);
        echo '</script>';

        echo '</form>';
        echo '</div>';
    }

    private function render_notices(): void
    {
        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Filters saved successfully.', 'hlm-smart-product-filters');
            echo '</p></div>';
        }

        if (isset($_GET['samples_loaded']) && $_GET['samples_loaded'] === 'true') {
            $template_name = isset($_GET['template_name']) ? sanitize_text_field(rawurldecode($_GET['template_name'])) : '';
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($template_name) {
                printf(esc_html__('"%s" template loaded successfully.', 'hlm-smart-product-filters'), esc_html($template_name));
            } else {
                echo esc_html__('Sample filters loaded successfully.', 'hlm-smart-product-filters');
            }
            echo '</p></div>';
        }

    }

    /* ------------------------------------------------------------------
     * Filter Row
     * ----------------------------------------------------------------*/
    private function render_filter_row($index, array $filter): void
    {
        $label       = esc_attr($filter['label'] ?? '');
        $key         = esc_attr($filter['key'] ?? '');
        $id          = esc_attr($filter['id'] ?? $key);
        $type        = $filter['type'] ?? 'checkbox';
        $data_source = $filter['data_source'] ?? 'taxonomy';
        $source_key  = esc_attr($filter['source_key'] ?? '');
        $render_mode = $filter['render_mode'] ?? 'both';

        // Behavior
        $multi_select = !empty($filter['behavior']['multi_select']);
        $operator     = $filter['behavior']['operator'] ?? 'OR';

        // Visibility
        $hide_empty           = !empty($filter['visibility']['hide_empty']);
        $include_children     = !empty($filter['visibility']['include_children']);
        $include_tag_children = !empty($filter['visibility']['include_tag_children']);
        $show_on_categories   = $filter['visibility']['show_on_categories'] ?? [];
        $hide_on_categories   = $filter['visibility']['hide_on_categories'] ?? [];
        $show_on_tags         = $filter['visibility']['show_on_tags'] ?? [];
        $hide_on_tags         = $filter['visibility']['hide_on_tags'] ?? [];
        $exclude_terms        = $filter['visibility']['exclude_terms'] ?? [];

        // UI
        $swatch_type          = $filter['ui']['swatch_type'] ?? 'color';
        $layout               = $filter['ui']['layout'] ?? 'inherit';
        $show_more_threshold  = (string) ($filter['ui']['show_more_threshold'] ?? '');
        $swatch_map           = $this->swatch_lines($filter['ui']['swatch_map'] ?? []);
        $range_step           = (string) ($filter['ui']['range_step'] ?? '1');
        $range_prefix         = (string) ($filter['ui']['range_prefix'] ?? '');
        $range_suffix         = (string) ($filter['ui']['range_suffix'] ?? '');

        // Resolve source picker value
        $source_picker = '';
        $custom_source = '';
        $meta_source   = '';
        if ($data_source === 'product_cat' || $data_source === 'product_tag') {
            $source_picker = $data_source;
        } elseif ($data_source === 'attribute' && $source_key !== '') {
            $source_picker = $source_key;
        } elseif ($data_source === 'meta') {
            $source_picker = 'meta';
            $meta_source   = $source_key;
        } elseif ($data_source === 'taxonomy' && $source_key !== '') {
            $source_picker = 'custom';
            $custom_source = $source_key;
        }

        // Type-based visibility flags
        $is_list   = ($type === 'checkbox');
        $is_swatch = ($type === 'swatch');
        $is_range  = ($type === 'range' || $type === 'slider');
        $is_slider = ($type === 'slider');
        $show_more = ($type === 'checkbox' || $type === 'swatch');

        // Visibility modes
        $category_mode = 'all';
        if (!empty($show_on_categories)) {
            $category_mode = 'include';
        } elseif (!empty($hide_on_categories)) {
            $category_mode = 'exclude';
        }

        $tag_mode = 'all';
        if (!empty($show_on_tags)) {
            $tag_mode = 'include';
        } elseif (!empty($hide_on_tags)) {
            $tag_mode = 'exclude';
        }

        echo '<li class="hlm-filter-row">';
        echo '<div class="hlm-filter-handle" title="' . esc_attr__('Drag to reorder', 'hlm-smart-product-filters') . '"><span class="dashicons dashicons-menu"></span></div>';
        echo '<div class="hlm-filter-card">';

        // --- Header ---
        echo '<div class="hlm-filter-header">';
        echo '<div class="hlm-filter-title">';
        echo '<strong class="hlm-filter-title-text">' . esc_html($label ?: __('New Filter', 'hlm-smart-product-filters')) . '</strong>';
        echo '<span class="hlm-filter-badges">';
        echo '<span class="hlm-filter-badge hlm-badge-type">' . esc_html($type) . '</span>';
        echo '<span class="hlm-filter-badge hlm-badge-source">' . esc_html($data_source) . '</span>';
        echo '</span>';
        echo '</div>';
        echo '<div class="hlm-filter-actions">';
        echo '<button type="button" class="button hlm-edit-swatch hlm-swatch-only' . (!$is_swatch ? ' is-hidden' : '') . '" data-index="' . esc_attr($index) . '"><span class="dashicons dashicons-art"></span>' . esc_html__('Swatches', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button-link-delete hlm-remove-filter">' . esc_html__('Remove', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';
        echo '</div>';

        // --- Tabs ---
        echo '<div class="hlm-filter-tabs">';
        echo '<div class="hlm-tab-nav" role="tablist">';
        echo '<button type="button" class="hlm-tab-button active" role="tab" aria-selected="true" data-tab="general">' . esc_html__('General', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="hlm-tab-button" role="tab" aria-selected="false" data-tab="behavior">' . esc_html__('Behavior', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="hlm-tab-button" role="tab" aria-selected="false" data-tab="ui">' . esc_html__('UI', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="hlm-tab-button" role="tab" aria-selected="false" data-tab="visibility">' . esc_html__('Visibility', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="hlm-tab-button" role="tab" aria-selected="false" data-tab="advanced">' . esc_html__('Advanced', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';

        echo '<div class="hlm-tab-panels">';

        // === General Tab ===
        echo '<div class="hlm-tab-panel active" role="tabpanel" data-tab="general">';
        echo '<div class="hlm-filter-section">';

        $this->text_field($index, 'label', __('Label', 'hlm-smart-product-filters'), $label, [
            'data-help'       => __('Shown to shoppers.', 'hlm-smart-product-filters'),
            'required'        => true,
            'data-validation' => __('Label is required', 'hlm-smart-product-filters'),
        ]);

        // Source picker
        echo '<label class="hlm-filter-field hlm-source-field">' . esc_html__('Source', 'hlm-smart-product-filters');
        echo '<select class="hlm-source-picker" data-required="true">';
        echo '<option value="">' . esc_html__('Select source', 'hlm-smart-product-filters') . '</option>';
        echo '<optgroup label="' . esc_attr__('Built-in', 'hlm-smart-product-filters') . '">';
        printf('<option value="product_cat" %s>%s</option>', selected($source_picker, 'product_cat', false), esc_html__('Product categories', 'hlm-smart-product-filters'));
        printf('<option value="product_tag" %s>%s</option>', selected($source_picker, 'product_tag', false), esc_html__('Product tags', 'hlm-smart-product-filters'));
        echo '</optgroup>';
        echo '<optgroup label="' . esc_attr__('Attributes', 'hlm-smart-product-filters') . '">';
        foreach ($this->attributes as $attribute) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($attribute['slug']),
                selected($source_picker, $attribute['slug'], false),
                esc_html($attribute['label'])
            );
        }
        echo '</optgroup>';
        echo '<optgroup label="' . esc_attr__('Meta', 'hlm-smart-product-filters') . '">';
        printf('<option value="meta" %s>%s</option>', selected($source_picker, 'meta', false), esc_html__('Meta Field (e.g. Price)', 'hlm-smart-product-filters'));
        echo '</optgroup>';
        echo '<optgroup label="' . esc_attr__('Custom', 'hlm-smart-product-filters') . '">';
        printf('<option value="custom" %s>%s</option>', selected($source_picker, 'custom', false), esc_html__('Custom taxonomy', 'hlm-smart-product-filters'));
        echo '</optgroup>';
        echo '</select>';
        echo '<span class="hlm-validation-error">' . esc_html__('Please select a data source', 'hlm-smart-product-filters') . '</span>';
        echo '</label>';

        $this->text_field($index, 'custom_source', __('Custom taxonomy slug', 'hlm-smart-product-filters'), $custom_source, [
            'data-help'     => __('Only used when Custom taxonomy is selected.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-custom-source' . ($source_picker !== 'custom' ? ' is-hidden' : ''),
        ]);

        $this->text_field($index, 'meta_source', __('Meta key', 'hlm-smart-product-filters'), $meta_source, [
            'data-help'     => __('The post meta key to filter by (e.g. _price).', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-meta-source' . ($data_source !== 'meta' ? ' is-hidden' : ''),
            'placeholder'   => '_price',
        ]);

        printf('<input type="hidden" name="filters[%s][data_source]" value="%s" class="hlm-data-source">', esc_attr($index), esc_attr($data_source));
        printf('<input type="hidden" name="filters[%s][source_key]" value="%s" class="hlm-source-key">', esc_attr($index), esc_attr($source_key));

        echo '</div>'; // .hlm-filter-section
        echo '</div>'; // general tab

        // === Behavior Tab ===
        echo '<div class="hlm-tab-panel" role="tabpanel" data-tab="behavior">';
        echo '<div class="hlm-filter-section">';

        echo '<label class="hlm-filter-checkbox">';
        printf('<input type="hidden" name="filters[%s][behavior][multi_select]" value="0">', esc_attr($index));
        printf(
            '<input type="checkbox" name="filters[%s][behavior][multi_select]" value="1" %s> %s',
            esc_attr($index),
            checked($multi_select, true, false),
            esc_html__('Allow multi-select', 'hlm-smart-product-filters')
        );
        echo '</label>';

        $this->select_field($index, 'behavior][operator', __('Operator', 'hlm-smart-product-filters'), $operator, [
            'OR'  => __('OR', 'hlm-smart-product-filters'),
            'AND' => __('AND', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How multi-select combines values.', 'hlm-smart-product-filters')]);

        echo '</div>';
        echo '</div>';

        // === UI Tab ===
        echo '<div class="hlm-tab-panel" role="tabpanel" data-tab="ui">';
        echo '<div class="hlm-filter-section">';

        $this->select_field($index, 'type', __('Display type', 'hlm-smart-product-filters'), $type, [
            'checkbox' => __('List (checkboxes)', 'hlm-smart-product-filters'),
            'dropdown' => __('Dropdown', 'hlm-smart-product-filters'),
            'swatch'   => __('Swatch', 'hlm-smart-product-filters'),
            'range'    => __('Range (min / max)', 'hlm-smart-product-filters'),
            'slider'   => __('Slider', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How shoppers select options.', 'hlm-smart-product-filters')]);

        $this->select_field($index, 'ui][layout', __('List layout', 'hlm-smart-product-filters'), $layout, [
            'inherit'  => __('Use default (settings)', 'hlm-smart-product-filters'),
            'stacked'  => __('Stacked (new lines)', 'hlm-smart-product-filters'),
            'inline'   => __('Inline (same line)', 'hlm-smart-product-filters'),
        ], [
            'data-help'     => __('Only for list filters.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-list-only' . (!$is_list ? ' is-hidden' : ''),
        ]);

        $this->select_field($index, 'ui][swatch_type', __('Swatch type', 'hlm-smart-product-filters'), $swatch_type, [
            'color' => __('Color', 'hlm-smart-product-filters'),
            'image' => __('Image URL', 'hlm-smart-product-filters'),
            'text'  => __('Text', 'hlm-smart-product-filters'),
        ], [
            'data-help'     => __('How swatches are rendered.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-swatch-only' . (!$is_swatch ? ' is-hidden' : ''),
        ]);

        $this->text_field($index, 'ui][show_more_threshold', __('Show more threshold', 'hlm-smart-product-filters'), $show_more_threshold, [
            'data-help'     => __('Hide options after N items.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-show-more-only' . (!$show_more ? ' is-hidden' : ''),
        ]);

        $this->text_field($index, 'ui][range_step', __('Slider step', 'hlm-smart-product-filters'), $range_step, [
            'data-help'     => __('Step increment (e.g. 1, 0.01).', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-slider-only' . (!$is_slider ? ' is-hidden' : ''),
            'placeholder'   => '1',
        ]);
        $this->text_field($index, 'ui][range_prefix', __('Range prefix', 'hlm-smart-product-filters'), $range_prefix, [
            'data-help'     => __('Shown before the value (e.g. $).', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-range-only' . (!$is_range ? ' is-hidden' : ''),
            'placeholder'   => '$',
        ]);
        $this->text_field($index, 'ui][range_suffix', __('Range suffix', 'hlm-smart-product-filters'), $range_suffix, [
            'data-help'     => __('Shown after the value (e.g. kg).', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-range-only' . (!$is_range ? ' is-hidden' : ''),
        ]);

        $this->textarea_field($index, 'swatch_map', __('Swatch map (term_id: value per line)', 'hlm-smart-product-filters'), $swatch_map, [
            'wrapper_class' => 'hlm-swatch-only' . (!$is_swatch ? ' is-hidden' : ''),
        ]);

        echo '</div>';
        echo '</div>';

        // === Visibility Tab ===
        echo '<div class="hlm-tab-panel" role="tabpanel" data-tab="visibility">';
        echo '<div class="hlm-filter-section">';

        echo '<label class="hlm-filter-checkbox">';
        printf('<input type="hidden" name="filters[%s][visibility][hide_empty]" value="0">', esc_attr($index));
        printf(
            '<input type="checkbox" name="filters[%s][visibility][hide_empty]" value="1" %s> %s',
            esc_attr($index),
            checked($hide_empty, true, false),
            esc_html__('Hide empty terms', 'hlm-smart-product-filters')
        );
        echo '</label>';

        // Term exclusion
        echo '<div class="hlm-visibility-group hlm-exclude-terms-group">';
        echo '<label class="hlm-filter-field-label">' . esc_html__('Exclude specific terms', 'hlm-smart-product-filters') . '</label>';
        echo '<p class="description">' . esc_html__('Select terms to exclude from this filter.', 'hlm-smart-product-filters') . '</p>';
        echo '<div class="hlm-exclude-terms-container">';
        printf(
            '<select multiple name="filters[%s][visibility][exclude_terms][]" class="hlm-exclude-terms-select" data-filter-index="%s" size="6">',
            esc_attr($index),
            esc_attr($index)
        );
        // Terms will be populated via JavaScript when source changes
        // But we need to render currently selected terms so they persist
        foreach ($exclude_terms as $term_id) {
            $term = get_term((int) $term_id);
            if ($term && !is_wp_error($term)) {
                printf(
                    '<option value="%d" selected>%s</option>',
                    (int) $term_id,
                    esc_html($term->name)
                );
            }
        }
        echo '</select>';
        echo '<div class="hlm-exclude-terms-loader" style="display:none;">';
        echo '<span class="spinner is-active"></span>';
        echo '<span>' . esc_html__('Loading terms...', 'hlm-smart-product-filters') . '</span>';
        echo '</div>';
        echo '<div class="hlm-exclude-terms-actions">';
        printf('<button type="button" class="button hlm-select-all-terms" data-target="filters-%s-exclude-terms">%s</button>', esc_attr($index), esc_html__('Select all', 'hlm-smart-product-filters'));
        printf('<button type="button" class="button hlm-clear-all-terms" data-target="filters-%s-exclude-terms">%s</button>', esc_attr($index), esc_html__('Clear', 'hlm-smart-product-filters'));
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Category visibility
        echo '<div class="hlm-visibility-group">';
        echo '<label class="hlm-filter-field-label">' . esc_html__('Category visibility', 'hlm-smart-product-filters') . '</label>';
        echo '<div class="hlm-visibility-modes">';
        foreach (['all' => __('All', 'hlm-smart-product-filters'), 'include' => __('Include', 'hlm-smart-product-filters'), 'exclude' => __('Exclude', 'hlm-smart-product-filters')] as $mode_val => $mode_label) {
            printf(
                '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][category_mode]" value="%s" %s> %s</label>',
                esc_attr($index), esc_attr($mode_val), checked($category_mode, $mode_val, false), esc_html($mode_label)
            );
        }
        echo '</div>';

        echo '<div class="hlm-visibility-select hlm-category-select" data-mode="' . esc_attr($category_mode) . '">';
        echo '<div class="hlm-visibility-include"' . ($category_mode !== 'include' ? ' style="display:none;"' : '') . '>';
        $this->multi_select_field($index, 'visibility][show_on_categories', __('Select categories', 'hlm-smart-product-filters'), $this->categories, $show_on_categories);
        echo '</div>';
        echo '<div class="hlm-visibility-exclude"' . ($category_mode !== 'exclude' ? ' style="display:none;"' : '') . '>';
        $this->multi_select_field($index, 'visibility][hide_on_categories', __('Select categories to exclude', 'hlm-smart-product-filters'), $this->categories, $hide_on_categories);
        echo '</div>';
        echo '</div>';

        echo '<label class="hlm-filter-checkbox">';
        printf('<input type="hidden" name="filters[%s][visibility][include_children]" value="0">', esc_attr($index));
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_children]" value="1" %s> %s',
            esc_attr($index), checked($include_children, true, false), esc_html__('Include child categories', 'hlm-smart-product-filters')
        );
        echo '</label>';
        echo '</div>';

        // Tag visibility
        echo '<div class="hlm-visibility-group">';
        echo '<label class="hlm-filter-field-label">' . esc_html__('Tag visibility', 'hlm-smart-product-filters') . '</label>';
        echo '<div class="hlm-visibility-modes">';
        foreach (['all' => __('All', 'hlm-smart-product-filters'), 'include' => __('Include', 'hlm-smart-product-filters'), 'exclude' => __('Exclude', 'hlm-smart-product-filters')] as $mode_val => $mode_label) {
            printf(
                '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][tag_mode]" value="%s" %s> %s</label>',
                esc_attr($index), esc_attr($mode_val), checked($tag_mode, $mode_val, false), esc_html($mode_label)
            );
        }
        echo '</div>';

        echo '<div class="hlm-visibility-select hlm-tag-select" data-mode="' . esc_attr($tag_mode) . '">';
        echo '<div class="hlm-visibility-include"' . ($tag_mode !== 'include' ? ' style="display:none;"' : '') . '>';
        $this->multi_select_field($index, 'visibility][show_on_tags', __('Select tags', 'hlm-smart-product-filters'), $this->tags, $show_on_tags);
        echo '</div>';
        echo '<div class="hlm-visibility-exclude"' . ($tag_mode !== 'exclude' ? ' style="display:none;"' : '') . '>';
        $this->multi_select_field($index, 'visibility][hide_on_tags', __('Select tags to exclude', 'hlm-smart-product-filters'), $this->tags, $hide_on_tags);
        echo '</div>';
        echo '</div>';

        echo '<label class="hlm-filter-checkbox">';
        printf('<input type="hidden" name="filters[%s][visibility][include_tag_children]" value="0">', esc_attr($index));
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_tag_children]" value="1" %s> %s',
            esc_attr($index), checked($include_tag_children, true, false), esc_html__('Include child tags', 'hlm-smart-product-filters')
        );
        echo '</label>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // === Advanced Tab ===
        echo '<div class="hlm-tab-panel" role="tabpanel" data-tab="advanced">';
        echo '<div class="hlm-filter-section">';

        $this->text_field($index, 'id', __('ID', 'hlm-smart-product-filters'), $id, [
            'data-help' => __('Internal unique ID (no spaces).', 'hlm-smart-product-filters'),
        ]);
        $this->text_field($index, 'key', __('Key (query string)', 'hlm-smart-product-filters'), $key, [
            'data-help' => __('Used in URL, keep short.', 'hlm-smart-product-filters'),
        ]);
        $this->select_field($index, 'render_mode', __('Render mode', 'hlm-smart-product-filters'), $render_mode, [
            'both'      => __('Shortcode + auto', 'hlm-smart-product-filters'),
            'shortcode' => __('Shortcode only', 'hlm-smart-product-filters'),
            'auto'      => __('Auto inject only', 'hlm-smart-product-filters'),
        ], ['data-help' => __('Where this filter shows.', 'hlm-smart-product-filters')]);

        echo '</div>';
        echo '</div>';

        echo '</div>'; // .hlm-tab-panels
        echo '</div>'; // .hlm-filter-tabs
        echo '</div>'; // .hlm-filter-card
        echo '</li>';
    }

    /* ------------------------------------------------------------------
     * Field Helpers
     * ----------------------------------------------------------------*/
    private function text_field($index, string $name, string $label, string $value, array $attrs = []): void
    {
        $help           = $attrs['data-help'] ?? '';
        $required       = $attrs['required'] ?? false;
        $validation_msg = $attrs['data-validation'] ?? '';
        $wrapper_class  = 'hlm-filter-field';
        $placeholder    = $attrs['placeholder'] ?? '';

        if (!empty($attrs['wrapper_class'])) {
            $wrapper_class .= ' ' . $attrs['wrapper_class'];
        }

        $extra_attrs = '';
        if ($required) {
            $extra_attrs .= ' data-required="true"';
        }
        if ($placeholder) {
            $extra_attrs .= ' placeholder="' . esc_attr($placeholder) . '"';
        }

        printf(
            '<label class="%s">%s%s<input type="text" name="filters[%s][%s]" value="%s" class="regular-text"%s>%s</label>',
            esc_attr($wrapper_class),
            esc_html($label),
            $help ? '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>' : '',
            esc_attr($index),
            esc_attr($name),
            esc_attr($value),
            $extra_attrs,
            $validation_msg ? '<span class="hlm-validation-error">' . esc_html($validation_msg) . '</span>' : ''
        );
    }

    private function textarea_field($index, string $name, string $label, string $value, array $attrs = []): void
    {
        $wrapper_class = 'hlm-filter-field';
        if (!empty($attrs['wrapper_class'])) {
            $wrapper_class .= ' ' . $attrs['wrapper_class'];
        }
        printf(
            '<label class="%s">%s<textarea name="filters[%s][%s]" rows="4" class="large-text">%s</textarea></label>',
            esc_attr($wrapper_class),
            esc_html($label),
            esc_attr($index),
            esc_attr($name),
            esc_textarea($value)
        );
    }

    private function select_field($index, string $name, string $label, string $value, array $options, array $attrs = []): void
    {
        $help          = $attrs['data-help'] ?? '';
        $wrapper_class = 'hlm-filter-field';

        if (!empty($attrs['wrapper_class'])) {
            $wrapper_class .= ' ' . $attrs['wrapper_class'];
        }

        echo '<label class="' . esc_attr($wrapper_class) . '">';
        echo esc_html($label);
        if ($help) {
            echo '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>';
        }
        echo '<select name="filters[' . esc_attr($index) . '][' . esc_attr($name) . ']">';
        foreach ($options as $option_value => $option_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($option_value),
                selected($value, $option_value, false),
                esc_html($option_label)
            );
        }
        echo '</select></label>';
    }

    private function multi_select_field($index, string $name, string $label, array $options, array $selected_values): void
    {
        $safe_id = 'hlm-multi-' . esc_attr($index) . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);

        echo '<label class="hlm-filter-field">' . esc_html($label);
        echo '<select id="' . esc_attr($safe_id) . '" name="filters[' . esc_attr($index) . '][' . esc_attr($name) . '][]" multiple size="6">';
        if (empty($options)) {
            echo '<option value="" disabled>' . esc_html__('No options available', 'hlm-smart-product-filters') . '</option>';
        } else {
            foreach ($options as $opt_id => $title) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr((string) $opt_id),
                    selected(in_array((int) $opt_id, $selected_values, true), true, false),
                    esc_html($title)
                );
            }
        }
        echo '</select>';
        echo '<span class="hlm-multi-actions">';
        echo '<button type="button" class="button-link hlm-select-all" data-target="' . esc_attr($safe_id) . '">' . esc_html__('Select all', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button-link hlm-clear-all" data-target="' . esc_attr($safe_id) . '">' . esc_html__('Clear', 'hlm-smart-product-filters') . '</button>';
        echo '</span>';
        echo '</label>';
    }

    /* ------------------------------------------------------------------
     * Data Helpers
     * ----------------------------------------------------------------*/
    private function swatch_lines(array $map): string
    {
        $lines = [];
        foreach ($map as $term_id => $value) {
            $lines[] = (int) $term_id . ': ' . $value;
        }
        return implode("\n", $lines);
    }

    private function load_data(): void
    {
        $this->attributes = [];
        if (function_exists('wc_get_attribute_taxonomies')) {
            $taxonomies = wc_get_attribute_taxonomies();
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    if (empty($taxonomy->attribute_name)) {
                        continue;
                    }
                    $this->attributes[] = [
                        'slug'  => $taxonomy->attribute_name,
                        'label' => $taxonomy->attribute_label ?: $taxonomy->attribute_name,
                    ];
                }
            }
        }

        $this->categories = $this->term_options('product_cat');
        $this->tags       = $this->term_options('product_tag');
    }

    private function term_options(string $taxonomy): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 0,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $options = [];
        foreach ($terms as $term) {
            if (isset($term->term_id, $term->name)) {
                $options[(int) $term->term_id] = $term->name;
            }
        }
        return $options;
    }

    private function render_template_selector(): void
    {
        $templates = $this->get_filter_templates();
        $base_url  = wp_nonce_url(admin_url('admin-post.php?action=hlm_load_sample_filters'), 'hlm_load_sample_filters');

        echo '<div class="hlm-template-selector">';
        echo '<h3>' . esc_html__('Quick Start Templates', 'hlm-smart-product-filters') . '</h3>';
        echo '<p class="description">' . esc_html__('Choose a template to get started quickly. This will replace your current filters.', 'hlm-smart-product-filters') . '</p>';
        echo '<div class="hlm-template-grid">';

        foreach ($templates as $key => $template) {
            $url = add_query_arg('template', $key, $base_url);
            echo '<a href="' . esc_url($url) . '" class="hlm-template-card" onclick="return confirm(\'' . esc_js(__('This will replace your current filters. Continue?', 'hlm-smart-product-filters')) . '\');">';
            echo '<span class="hlm-template-icon dashicons ' . esc_attr($template['icon']) . '"></span>';
            echo '<span class="hlm-template-name">' . esc_html($template['name']) . '</span>';
            echo '<span class="hlm-template-desc">' . esc_html($template['description']) . '</span>';
            echo '<span class="hlm-template-count">' . sprintf(
                esc_html(_n('%d filter', '%d filters', count($template['filters']), 'hlm-smart-product-filters')),
                count($template['filters'])
            ) . '</span>';
            echo '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     * Templates
     * ----------------------------------------------------------------*/
    private function get_filter_templates(): array
    {
        return [
            'comprehensive' => [
                'name'        => __('Comprehensive Shop', 'hlm-smart-product-filters'),
                'description' => __('Categories, tags, color, size, brand, and price.', 'hlm-smart-product-filters'),
                'icon'        => 'dashicons-grid-view',
                'filters'     => [
                    ['id' => 'category', 'label' => 'Product Categories', 'key' => 'category', 'type' => 'checkbox', 'data_source' => 'product_cat', 'source_key' => 'product_cat', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true, 'include_children' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'tags', 'label' => 'Product Tags', 'key' => 'tags', 'type' => 'checkbox', 'data_source' => 'product_tag', 'source_key' => 'product_tag', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'color', 'label' => 'Color', 'key' => 'color', 'type' => 'swatch', 'data_source' => 'attribute', 'source_key' => 'color', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['swatch_type' => 'color', 'show_more_threshold' => 8]],
                    ['id' => 'size', 'label' => 'Size', 'key' => 'size', 'type' => 'swatch', 'data_source' => 'attribute', 'source_key' => 'size', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['swatch_type' => 'text', 'show_more_threshold' => 10]],
                    ['id' => 'brand', 'label' => 'Brand', 'key' => 'brand', 'type' => 'checkbox', 'data_source' => 'attribute', 'source_key' => 'brand', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'price', 'label' => 'Price Range', 'key' => 'price', 'type' => 'range', 'data_source' => 'meta', 'source_key' => '_price', 'behavior' => ['multi_select' => false], 'visibility' => [], 'ui' => []],
                ],
            ],
            'pet_focused' => [
                'name'        => __('Pet & Dog Products', 'hlm-smart-product-filters'),
                'description' => __('Breeds, themes, colors, sizes, and price.', 'hlm-smart-product-filters'),
                'icon'        => 'dashicons-heart',
                'filters'     => [
                    ['id' => 'category', 'label' => 'Product Categories', 'key' => 'category', 'type' => 'checkbox', 'data_source' => 'product_cat', 'source_key' => 'product_cat', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true, 'include_children' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'breeds', 'label' => 'Breeds', 'key' => 'breeds', 'type' => 'checkbox', 'data_source' => 'taxonomy', 'source_key' => 'breeds', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'theme', 'label' => 'Themes', 'key' => 'theme', 'type' => 'checkbox', 'data_source' => 'taxonomy', 'source_key' => 'theme', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['show_more_threshold' => 5]],
                    ['id' => 'color', 'label' => 'Color', 'key' => 'color', 'type' => 'swatch', 'data_source' => 'attribute', 'source_key' => 'color', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['swatch_type' => 'color', 'show_more_threshold' => 8]],
                    ['id' => 'size', 'label' => 'Size', 'key' => 'size', 'type' => 'swatch', 'data_source' => 'attribute', 'source_key' => 'size', 'behavior' => ['multi_select' => true, 'operator' => 'OR'], 'visibility' => ['hide_empty' => true], 'ui' => ['swatch_type' => 'text', 'show_more_threshold' => 10]],
                    ['id' => 'price', 'label' => 'Price Range', 'key' => 'price', 'type' => 'range', 'data_source' => 'meta', 'source_key' => '_price', 'behavior' => ['multi_select' => false], 'visibility' => [], 'ui' => []],
                ],
            ],
        ];
    }
}