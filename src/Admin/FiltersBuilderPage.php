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
        if ($hook !== 'toplevel_page_hlm-product-filters' && $hook !== 'hlm-product-filters_page_' . $this->page_slug) {
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

        wp_localize_script('hlm-filters-admin', 'HLMFiltersAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hlm_filters_admin_nonce'),
        ]);
    }

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

        foreach ($filters as &$filter) {
            if (isset($filter['visibility'])) {
                $category_mode = $filter['visibility']['category_mode'] ?? 'all';
                $tag_mode = $filter['visibility']['tag_mode'] ?? 'all';

                if ($category_mode === 'all') {
                    $filter['visibility']['show_on_categories'] = [];
                    $filter['visibility']['hide_on_categories'] = [];
                } elseif ($category_mode === 'exclude') {
                    $filter['visibility']['hide_on_categories'] = $filter['visibility']['show_on_categories'] ?? [];
                    $filter['visibility']['show_on_categories'] = [];
                } else {
                    $filter['visibility']['hide_on_categories'] = [];
                }

                if ($tag_mode === 'all') {
                    $filter['visibility']['show_on_tags'] = [];
                    $filter['visibility']['hide_on_tags'] = [];
                } elseif ($tag_mode === 'exclude') {
                    $filter['visibility']['hide_on_tags'] = $filter['visibility']['show_on_tags'] ?? [];
                    $filter['visibility']['show_on_tags'] = [];
                } else {
                    $filter['visibility']['hide_on_tags'] = [];
                }

                unset($filter['visibility']['category_mode']);
                unset($filter['visibility']['tag_mode']);
            }
        }

        $current = $this->config->get();
        $data = [
            'global' => $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $filters,
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg(['page' => $this->page_slug, 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    public function handle_load_samples(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to manage filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_load_sample_filters');

        $template = isset($_GET['template']) ? sanitize_key($_GET['template']) : 'standard';
        $templates = $this->get_filter_templates();

        if (!isset($templates[$template])) {
            $template = 'standard';
        }

        $current = $this->config->get();
        $data = [
            'global' => $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $this->get_template($template),
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg([
            'page' => $this->page_slug,
            'samples_loaded' => 'true',
            'template_name' => rawurlencode($templates[$template]['name']),
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_import(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to manage filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_import_filters');

        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(add_query_arg([
                'page' => $this->page_slug,
                'import_error' => 'no_file',
            ], admin_url('admin.php')));
            exit;
        }

        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($import_data)) {
            wp_safe_redirect(add_query_arg([
                'page' => $this->page_slug,
                'import_error' => 'invalid_json',
            ], admin_url('admin.php')));
            exit;
        }

        if (empty($import_data['filters']) || !is_array($import_data['filters'])) {
            wp_safe_redirect(add_query_arg([
                'page' => $this->page_slug,
                'import_error' => 'no_filters',
            ], admin_url('admin.php')));
            exit;
        }

        $current = $this->config->get();
        $data = [
            'global' => $import_data['global'] ?? $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $import_data['filters'],
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg([
            'page' => $this->page_slug,
            'imported' => 'true',
            'count' => count($import_data['filters']),
        ], admin_url('admin.php')));
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $this->load_data();
        $config = $this->config->get();
        $filters = $config['filters'] ?? [];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HLM Filters Builder', 'hlm-smart-product-filters') . '</h1>';

        // Show success notification
        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Filters saved successfully.', 'hlm-smart-product-filters');
            echo '</p></div>';
        }

        // Show samples loaded notification
        if (isset($_GET['samples_loaded']) && $_GET['samples_loaded'] === 'true') {
            $template_name = isset($_GET['template_name']) ? sanitize_text_field(rawurldecode($_GET['template_name'])) : '';
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($template_name) {
                printf(
                    /* translators: %s: template name */
                    esc_html__('"%s" template loaded successfully.', 'hlm-smart-product-filters'),
                    esc_html($template_name)
                );
            } else {
                echo esc_html__('Sample filters loaded successfully.', 'hlm-smart-product-filters');
            }
            echo '</p></div>';
        }

        // Show import success notification
        if (isset($_GET['imported']) && $_GET['imported'] === 'true') {
            $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                /* translators: %d: number of filters imported */
                esc_html(_n('Successfully imported %d filter.', 'Successfully imported %d filters.', $count, 'hlm-smart-product-filters')),
                $count
            );
            echo '</p></div>';
        }

        // Show import error notifications
        if (isset($_GET['import_error'])) {
            $error_messages = [
                'no_file' => __('No file was uploaded. Please select a file to import.', 'hlm-smart-product-filters'),
                'invalid_json' => __('Invalid JSON file. Please upload a valid export file.', 'hlm-smart-product-filters'),
                'no_filters' => __('No filters found in the import file.', 'hlm-smart-product-filters'),
            ];
            $error_key = sanitize_key($_GET['import_error']);
            $error_message = $error_messages[$error_key] ?? __('An error occurred during import.', 'hlm-smart-product-filters');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
        }

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

        echo '<button type="button" class="button button-primary" id="hlm-add-filter"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__('Add Filter', 'hlm-smart-product-filters') . '</button>';
        echo '<p class="submit">';
        submit_button(__('Save Filters', 'hlm-smart-product-filters'), 'primary', 'submit', false);
        echo '</p>';

        echo '<script type="text/template" id="hlm-filter-template">';
        $this->render_filter_row('__INDEX__', []);
        echo '</script>';

        echo '</form>';
        echo '</div>';
    }


    private function render_debug_section(): void
    {
        $config = $this->config->get();

        echo '<details class="hlm-debug-section">';
        echo '<summary class="hlm-debug-toggle">';
        echo '<span class="dashicons dashicons-info-outline"></span> ';
        echo esc_html__('Debug Information', 'hlm-smart-product-filters');
        echo '</summary>';

        echo '<div class="hlm-debug-content">';

        // System info
        echo '<div class="hlm-debug-panel">';
        echo '<h4>' . esc_html__('System Information', 'hlm-smart-product-filters') . '</h4>';
        echo '<table class="hlm-debug-table">';
        echo '<tr><td>' . esc_html__('PHP Version', 'hlm-smart-product-filters') . '</td><td>' . esc_html(PHP_VERSION) . '</td></tr>';
        echo '<tr><td>' . esc_html__('WordPress Version', 'hlm-smart-product-filters') . '</td><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '<tr><td>' . esc_html__('WooCommerce Version', 'hlm-smart-product-filters') . '</td><td>' . esc_html(defined('WC_VERSION') ? WC_VERSION : 'N/A') . '</td></tr>';
        echo '<tr><td>' . esc_html__('Active Theme', 'hlm-smart-product-filters') . '</td><td>' . esc_html(wp_get_theme()->get('Name')) . '</td></tr>';
        echo '<tr><td>' . esc_html__('Memory Limit', 'hlm-smart-product-filters') . '</td><td>' . esc_html(WP_MEMORY_LIMIT) . '</td></tr>';
        echo '</table>';
        echo '</div>';

        // Filter stats
        $filter_count = count($config['filters'] ?? []);
        $filter_types = [];
        foreach ($config['filters'] ?? [] as $filter) {
            $type = $filter['type'] ?? 'unknown';
            $filter_types[$type] = ($filter_types[$type] ?? 0) + 1;
        }

        echo '<div class="hlm-debug-panel">';
        echo '<h4>' . esc_html__('Filter Statistics', 'hlm-smart-product-filters') . '</h4>';
        echo '<table class="hlm-debug-table">';
        echo '<tr><td>' . esc_html__('Total Filters', 'hlm-smart-product-filters') . '</td><td>' . esc_html($filter_count) . '</td></tr>';
        foreach ($filter_types as $type => $count) {
            echo '<tr><td>' . esc_html(ucfirst($type)) . '</td><td>' . esc_html($count) . '</td></tr>';
        }
        echo '</table>';
        echo '</div>';

        // Shortcode info
        echo '<div class="hlm-debug-panel">';
        echo '<h4>' . esc_html__('Shortcode Usage', 'hlm-smart-product-filters') . '</h4>';
        echo '<code class="hlm-shortcode-example">[hlm_filters]</code>';
        echo '<p class="description">' . esc_html__('Place this shortcode on your shop page or in a sidebar widget.', 'hlm-smart-product-filters') . '</p>';
        echo '</div>';

        // Raw config (collapsed)
        echo '<details class="hlm-debug-raw">';
        echo '<summary>' . esc_html__('Raw Configuration (JSON)', 'hlm-smart-product-filters') . '</summary>';
        echo '<pre class="hlm-debug-json">' . esc_html(wp_json_encode($config, JSON_PRETTY_PRINT)) . '</pre>';
        echo '</details>';

        echo '</div>';
        echo '</details>';
    }

    private function render_filter_row($index, array $filter): void
    {
        $label = esc_attr($filter['label'] ?? '');
        $key = esc_attr($filter['key'] ?? '');
        $id = esc_attr($filter['id'] ?? $key);
        $type = $filter['type'] ?? 'checkbox';
        $data_source = $filter['data_source'] ?? 'taxonomy';
        $source_key = esc_attr($filter['source_key'] ?? '');
        $render_mode = $filter['render_mode'] ?? 'both';
        $multi_select = !empty($filter['behavior']['multi_select']);
        $operator = $filter['behavior']['operator'] ?? 'OR';
        $hide_empty = !empty($filter['visibility']['hide_empty']);
        $include_children = !empty($filter['visibility']['include_children']);
        $include_tag_children = !empty($filter['visibility']['include_tag_children']);
        $show_on_categories = $filter['visibility']['show_on_categories'] ?? [];
        $hide_on_categories = $filter['visibility']['hide_on_categories'] ?? [];
        $show_on_tags = $filter['visibility']['show_on_tags'] ?? [];
        $hide_on_tags = $filter['visibility']['hide_on_tags'] ?? [];
        $swatch_type = $filter['ui']['swatch_type'] ?? 'color';
        $layout = $filter['ui']['layout'] ?? 'inherit';
        $show_more_threshold = (string) ($filter['ui']['show_more_threshold'] ?? '');
        $swatch_map = $this->swatch_lines($filter['ui']['swatch_map'] ?? []);
        $source_picker = '';
        $custom_source = '';
        if ($data_source === 'product_cat' || $data_source === 'product_tag') {
            $source_picker = $data_source;
        } elseif ($data_source === 'attribute' && $source_key !== '') {
            $source_picker = $source_key;
        } elseif ($data_source === 'taxonomy' && $source_key !== '') {
            $source_picker = 'custom';
            $custom_source = $source_key;
        }

        echo '<li class="hlm-filter-row">';
        echo '<div class="hlm-filter-handle" title="' . esc_attr__('Drag to reorder', 'hlm-smart-product-filters') . '"><span class="dashicons dashicons-menu"></span></div>';
        echo '<details class="hlm-filter-card" open>';
        echo '<summary class="hlm-filter-summary">';
        echo '<div class="hlm-filter-title">';
        echo '<strong class="hlm-filter-title-text">' . esc_html($label ?: __('New Filter', 'hlm-smart-product-filters')) . '</strong>';
        echo '<span class="hlm-filter-badges">';
        echo '<span class="hlm-filter-badge hlm-badge-type">' . esc_html($type) . '</span>';
        echo '<span class="hlm-filter-badge hlm-badge-source">' . esc_html($data_source) . '</span>';
        echo '</span>';
        echo '</div>';
        echo '<div class="hlm-filter-actions">';
        echo '<button type="button" class="button hlm-edit-swatch hlm-swatch-only" data-index="' . esc_attr($index) . '"><span class="dashicons dashicons-art"></span>' . esc_html__('Swatches', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button hlm-toggle-advanced" aria-expanded="false"><span class="dashicons dashicons-admin-generic"></span>' . esc_html__('Advanced', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';
        echo '</summary>';
        echo '<div class="hlm-filter-content">';
        echo '<div class="hlm-filter-fields">';
        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Basics', 'hlm-smart-product-filters') . '</h3>';

        $this->text_field($index, 'label', __('Label', 'hlm-smart-product-filters'), $label, [
            'data-help' => __('Shown to shoppers.', 'hlm-smart-product-filters'),
            'required' => true,
            'data-validation' => __('Label is required', 'hlm-smart-product-filters'),
        ]);
        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Data Source', 'hlm-smart-product-filters') . '</h3>';
        echo '<label class="hlm-filter-field hlm-source-field">' . esc_html__('Source', 'hlm-smart-product-filters');
        echo '<select class="hlm-source-picker" name="filters[' . esc_attr($index) . '][source_picker]" data-required="true">';
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
        echo '<optgroup label="' . esc_attr__('Custom', 'hlm-smart-product-filters') . '">';
        printf('<option value="custom" %s>%s</option>', selected($source_picker, 'custom', false), esc_html__('Custom taxonomy', 'hlm-smart-product-filters'));
        echo '</optgroup>';
        echo '</select>';
        echo '<span class="hlm-validation-error">' . esc_html__('Please select a data source', 'hlm-smart-product-filters') . '</span>';
        echo '</label>';

        $this->text_field($index, 'custom_source', __('Custom taxonomy slug', 'hlm-smart-product-filters'), $custom_source, [
            'data-help' => __('Only used when Custom taxonomy is selected.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-custom-source',
        ]);

        printf(
            '<input type="hidden" name="filters[%s][data_source]" value="%s" class="hlm-data-source">',
            esc_attr($index),
            esc_attr($data_source)
        );
        printf(
            '<input type="hidden" name="filters[%s][source_key]" value="%s" class="hlm-source-key">',
            esc_attr($index),
            esc_attr($source_key)
        );

        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Behavior', 'hlm-smart-product-filters') . '</h3>';
        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][behavior][multi_select]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][behavior][multi_select]" value="1" %s> %s',
            esc_attr($index),
            checked($multi_select, true, false),
            esc_html__('Allow multi-select', 'hlm-smart-product-filters')
        );
        echo '</label>';

        $this->select_field($index, 'behavior][operator', __('Operator', 'hlm-smart-product-filters'), $operator, [
            'OR' => __('OR', 'hlm-smart-product-filters'),
            'AND' => __('AND', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How multi-select combines values.', 'hlm-smart-product-filters')]);
        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('UI', 'hlm-smart-product-filters') . '</h3>';
        $this->select_field($index, 'type', __('Display type', 'hlm-smart-product-filters'), $type, [
            'checkbox' => __('List (checkboxes)', 'hlm-smart-product-filters'),
            'dropdown' => __('Dropdown', 'hlm-smart-product-filters'),
            'swatch' => __('Swatch', 'hlm-smart-product-filters'),
            'range' => __('Range (coming soon)', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How shoppers select options. Swatches show visual chips (color, image, or text).', 'hlm-smart-product-filters')]);

        $this->select_field($index, 'ui][layout', __('List layout', 'hlm-smart-product-filters'), $layout, [
            'inherit' => __('Use default (settings)', 'hlm-smart-product-filters'),
            'stacked' => __('Stacked (new lines)', 'hlm-smart-product-filters'),
            'inline' => __('Inline (same line)', 'hlm-smart-product-filters'),
        ], [
            'data-help' => __('Only for list filters.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-list-only',
        ]);

        $this->select_field($index, 'ui][swatch_type', __('Swatch type', 'hlm-smart-product-filters'), $swatch_type, [
            'color' => __('Color', 'hlm-smart-product-filters'),
            'image' => __('Image URL', 'hlm-smart-product-filters'),
            'text' => __('Text', 'hlm-smart-product-filters'),
        ], [
            'data-help' => __('How swatches are rendered.', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-swatch-only',
        ]);

        $this->text_field($index, 'ui][show_more_threshold', __('Show more threshold', 'hlm-smart-product-filters'), $show_more_threshold, [
            'data-help' => __('Hide options after N (list + swatch).', 'hlm-smart-product-filters'),
            'wrapper_class' => 'hlm-show-more-only',
        ]);

        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][visibility][hide_empty]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][visibility][hide_empty]" value="1" %s> %s',
            esc_attr($index),
            checked($hide_empty, true, false),
            esc_html__('Hide empty terms', 'hlm-smart-product-filters')
        );
        echo '</label>';
        $this->textarea_field($index, 'swatch_map', __('Swatch map (term_id: value per line)', 'hlm-smart-product-filters'), $swatch_map, [
            'wrapper_class' => 'hlm-swatch-only',
        ]);
        echo '</div>';

        echo '<div class="hlm-filter-section hlm-filter-advanced is-hidden">';
        echo '<h3>' . esc_html__('Advanced', 'hlm-smart-product-filters') . '</h3>';
        $this->text_field($index, 'id', __('ID', 'hlm-smart-product-filters'), $id, [
            'data-help' => __('Internal unique ID (no spaces).', 'hlm-smart-product-filters'),
        ]);
        $this->text_field($index, 'key', __('Key (query string)', 'hlm-smart-product-filters'), $key, [
            'data-help' => __('Used in URL, keep short.', 'hlm-smart-product-filters'),
        ]);
        $this->select_field($index, 'render_mode', __('Render mode', 'hlm-smart-product-filters'), $render_mode, [
            'both' => __('Shortcode + auto', 'hlm-smart-product-filters'),
            'shortcode' => __('Shortcode only', 'hlm-smart-product-filters'),
            'auto' => __('Auto inject only', 'hlm-smart-product-filters'),
        ], ['data-help' => __('Where this filter shows.', 'hlm-smart-product-filters')]);
        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Visibility', 'hlm-smart-product-filters') . '</h3>';

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

        echo '<div class="hlm-visibility-group">';
        echo '<label class="hlm-filter-field-label">' . esc_html__('Category visibility', 'hlm-smart-product-filters') . '</label>';
        echo '<div class="hlm-visibility-modes">';
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][category_mode]" value="all" %s> %s</label>',
            esc_attr($index),
            checked($category_mode, 'all', false),
            esc_html__('All', 'hlm-smart-product-filters')
        );
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][category_mode]" value="include" %s> %s</label>',
            esc_attr($index),
            checked($category_mode, 'include', false),
            esc_html__('Include', 'hlm-smart-product-filters')
        );
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][category_mode]" value="exclude" %s> %s</label>',
            esc_attr($index),
            checked($category_mode, 'exclude', false),
            esc_html__('Exclude', 'hlm-smart-product-filters')
        );
        echo '</div>';

        echo '<div class="hlm-visibility-select hlm-category-select" data-mode="' . esc_attr($category_mode) . '">';
        $this->multi_select_field($index, 'visibility][show_on_categories', __('Select categories', 'hlm-smart-product-filters'), $this->categories, $show_on_categories);
        printf(
            '<input type="hidden" name="filters[%s][visibility][hide_on_categories][]" value="" class="hlm-hidden-categories">',
            esc_attr($index)
        );
        echo '</div>';

        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][visibility][include_children]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_children]" value="1" %s> %s',
            esc_attr($index),
            checked($include_children, true, false),
            esc_html__('Include child categories', 'hlm-smart-product-filters')
        );
        echo '</label>';
        echo '</div>';

        echo '<div class="hlm-visibility-group">';
        echo '<label class="hlm-filter-field-label">' . esc_html__('Tag visibility', 'hlm-smart-product-filters') . '</label>';
        echo '<div class="hlm-visibility-modes">';
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][tag_mode]" value="all" %s> %s</label>',
            esc_attr($index),
            checked($tag_mode, 'all', false),
            esc_html__('All', 'hlm-smart-product-filters')
        );
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][tag_mode]" value="include" %s> %s</label>',
            esc_attr($index),
            checked($tag_mode, 'include', false),
            esc_html__('Include', 'hlm-smart-product-filters')
        );
        printf(
            '<label class="hlm-mode-option"><input type="radio" name="filters[%s][visibility][tag_mode]" value="exclude" %s> %s</label>',
            esc_attr($index),
            checked($tag_mode, 'exclude', false),
            esc_html__('Exclude', 'hlm-smart-product-filters')
        );
        echo '</div>';

        echo '<div class="hlm-visibility-select hlm-tag-select" data-mode="' . esc_attr($tag_mode) . '">';
        $this->multi_select_field($index, 'visibility][show_on_tags', __('Select tags', 'hlm-smart-product-filters'), $this->tags, $show_on_tags);
        printf(
            '<input type="hidden" name="filters[%s][visibility][hide_on_tags][]" value="" class="hlm-hidden-tags">',
            esc_attr($index)
        );
        echo '</div>';

        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][visibility][include_tag_children]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_tag_children]" value="1" %s> %s',
            esc_attr($index),
            checked($include_tag_children, true, false),
            esc_html__('Include child tags', 'hlm-smart-product-filters')
        );
        echo '</label>';
        echo '</div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="button-link-delete hlm-remove-filter">' . esc_html__('Remove', 'hlm-smart-product-filters') . '</button>';
        echo '</details>';
        echo '</li>';
    }

    private function text_field($index, string $name, string $label, string $value, array $attrs = []): void
    {
        $attr_html = '';
        $wrapper_class = 'hlm-filter-field';
        $help = $attrs['data-help'] ?? '';
        $required = $attrs['required'] ?? false;
        $validation_msg = $attrs['data-validation'] ?? '';

        if ($help) {
            unset($attrs['data-help']);
        }
        if (!empty($attrs['wrapper_class'])) {
            $wrapper_class .= ' ' . $attrs['wrapper_class'];
            unset($attrs['wrapper_class']);
        }
        if (isset($attrs['required'])) {
            unset($attrs['required']);
        }
        if (isset($attrs['data-validation'])) {
            unset($attrs['data-validation']);
        }

        foreach ($attrs as $attr => $attr_value) {
            $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($attr_value) . '"';
        }

        if ($required) {
            $attr_html .= ' data-required="true"';
        }

        printf(
            '<label class="%s">%s%s<input type="text" name="filters[%s][%s]" value="%s" class="regular-text"%s>%s</label>',
            esc_attr($wrapper_class),
            esc_html($label),
            $help ? '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>' : '',
            esc_attr($index),
            esc_attr($name),
            esc_attr($value),
            $attr_html,
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
        $help = $attrs['data-help'] ?? '';
        $required = $attrs['required'] ?? false;
        $validation_msg = $attrs['data-validation'] ?? '';

        if ($help) {
            unset($attrs['data-help']);
        }
        if (isset($attrs['required'])) {
            unset($attrs['required']);
        }
        if (isset($attrs['data-validation'])) {
            unset($attrs['data-validation']);
        }

        $wrapper_class = 'hlm-filter-field';
        if (!empty($attrs['wrapper_class'])) {
            $wrapper_class .= ' ' . $attrs['wrapper_class'];
            unset($attrs['wrapper_class']);
        }

        $attr_html = '';
        foreach ($attrs as $attr => $attr_value) {
            $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($attr_value) . '"';
        }

        if ($required) {
            $attr_html .= ' data-required="true"';
        }

        echo '<label class="' . esc_attr($wrapper_class) . '">' . esc_html($label) . ($help ? '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>' : '') . '<select name="filters[' . esc_attr($index) . '][' . esc_attr($name) . ']"' . $attr_html . '>';
        foreach ($options as $option_value => $option_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($option_value),
                selected($value, $option_value, false),
                esc_html($option_label)
            );
        }
        echo '</select>' . ($validation_msg ? '<span class="hlm-validation-error">' . esc_html($validation_msg) . '</span>' : '') . '</label>';
    }

    private function multi_select_field($index, string $name, string $label, array $options, array $selected): void
    {
        $safe_id = 'hlm-multi-' . esc_attr($index) . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
        echo '<label class="hlm-filter-field">' . esc_html($label);
        echo '<select id="' . esc_attr($safe_id) . '" name="filters[' . esc_attr($index) . '][' . esc_attr($name) . '][]" multiple size="6">';
        foreach ($options as $id => $title) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $id),
                selected(in_array((int) $id, $selected, true), true, false),
                esc_html($title)
            );
        }
        echo '</select>';
        echo '<span class="hlm-multi-actions">';
        echo '<button type="button" class="button-link hlm-select-all" data-target="' . esc_attr($safe_id) . '">' . esc_html__('Select all', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button-link hlm-clear-all" data-target="' . esc_attr($safe_id) . '">' . esc_html__('Clear', 'hlm-smart-product-filters') . '</button>';
        echo '</span>';
        echo '</label>';
    }

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
                        'slug' => $taxonomy->attribute_name,
                        'label' => $taxonomy->attribute_label ?: $taxonomy->attribute_name,
                    ];
                }
            }
        }

        $this->categories = $this->term_options('product_cat');
        $this->tags = $this->term_options('product_tag');
    }

    private function render_template_selector(): void
    {
        $templates = $this->get_filter_templates();
        $base_url = wp_nonce_url(admin_url('admin-post.php?action=hlm_load_sample_filters'), 'hlm_load_sample_filters');

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
                /* translators: %d: number of filters */
                esc_html(_n('%d filter', '%d filters', count($template['filters']), 'hlm-smart-product-filters')),
                count($template['filters'])
            ) . '</span>';
            echo '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function term_options(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }
        $options = [];
        foreach ($terms as $term) {
            $options[(int) $term->term_id] = $term->name;
        }
        return $options;
    }

    private function sample_filters(): array
    {
        return $this->get_template('standard');
    }

    private function get_template(string $template_key): array
    {
        $templates = $this->get_filter_templates();
        return $templates[$template_key]['filters'] ?? [];
    }

    private function get_filter_templates(): array
    {
        return [
            'standard' => [
                'name' => __('Standard Shop', 'hlm-smart-product-filters'),
                'description' => __('Category, color swatches, size, and price range - perfect for most stores.', 'hlm-smart-product-filters'),
                'icon' => 'dashicons-store',
                'filters' => [
                    [
                        'id' => 'category',
                        'label' => __('Category', 'hlm-smart-product-filters'),
                        'key' => 'category',
                        'type' => 'checkbox',
                        'data_source' => 'product_cat',
                        'source_key' => 'product_cat',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                            'include_children' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 5,
                        ],
                    ],
                    [
                        'id' => 'color',
                        'label' => __('Color', 'hlm-smart-product-filters'),
                        'key' => 'color',
                        'type' => 'swatch',
                        'data_source' => 'attribute',
                        'source_key' => 'color',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'swatch',
                            'swatch_type' => 'color',
                            'show_more_threshold' => 8,
                        ],
                    ],
                    [
                        'id' => 'size',
                        'label' => __('Size', 'hlm-smart-product-filters'),
                        'key' => 'size',
                        'type' => 'swatch',
                        'data_source' => 'attribute',
                        'source_key' => 'size',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'swatch',
                            'swatch_type' => 'text',
                            'show_more_threshold' => 10,
                        ],
                    ],
                    [
                        'id' => 'price',
                        'label' => __('Price', 'hlm-smart-product-filters'),
                        'key' => 'price',
                        'type' => 'range',
                        'data_source' => 'meta',
                        'source_key' => '_price',
                        'behavior' => [
                            'multi_select' => false,
                        ],
                        'visibility' => [],
                        'ui' => [
                            'style' => 'range',
                        ],
                    ],
                ],
            ],
            'fashion' => [
                'name' => __('Fashion & Apparel', 'hlm-smart-product-filters'),
                'description' => __('Optimized for clothing stores with size, color, brand, and material filters.', 'hlm-smart-product-filters'),
                'icon' => 'dashicons-tag',
                'filters' => [
                    [
                        'id' => 'category',
                        'label' => __('Category', 'hlm-smart-product-filters'),
                        'key' => 'category',
                        'type' => 'checkbox',
                        'data_source' => 'product_cat',
                        'source_key' => 'product_cat',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                            'include_children' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 5,
                        ],
                    ],
                    [
                        'id' => 'size',
                        'label' => __('Size', 'hlm-smart-product-filters'),
                        'key' => 'size',
                        'type' => 'swatch',
                        'data_source' => 'attribute',
                        'source_key' => 'size',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'swatch',
                            'swatch_type' => 'text',
                            'show_more_threshold' => 10,
                        ],
                    ],
                    [
                        'id' => 'color',
                        'label' => __('Color', 'hlm-smart-product-filters'),
                        'key' => 'color',
                        'type' => 'swatch',
                        'data_source' => 'attribute',
                        'source_key' => 'color',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'swatch',
                            'swatch_type' => 'color',
                            'show_more_threshold' => 8,
                        ],
                    ],
                    [
                        'id' => 'brand',
                        'label' => __('Brand', 'hlm-smart-product-filters'),
                        'key' => 'brand',
                        'type' => 'checkbox',
                        'data_source' => 'attribute',
                        'source_key' => 'brand',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 5,
                        ],
                    ],
                    [
                        'id' => 'price',
                        'label' => __('Price', 'hlm-smart-product-filters'),
                        'key' => 'price',
                        'type' => 'range',
                        'data_source' => 'meta',
                        'source_key' => '_price',
                        'behavior' => [
                            'multi_select' => false,
                        ],
                        'visibility' => [],
                        'ui' => [
                            'style' => 'range',
                        ],
                    ],
                ],
            ],
            'electronics' => [
                'name' => __('Electronics & Tech', 'hlm-smart-product-filters'),
                'description' => __('Brand, category, price, and tags for tech products.', 'hlm-smart-product-filters'),
                'icon' => 'dashicons-laptop',
                'filters' => [
                    [
                        'id' => 'category',
                        'label' => __('Category', 'hlm-smart-product-filters'),
                        'key' => 'category',
                        'type' => 'dropdown',
                        'data_source' => 'product_cat',
                        'source_key' => 'product_cat',
                        'behavior' => [
                            'multi_select' => false,
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                            'include_children' => true,
                        ],
                        'ui' => [
                            'style' => 'dropdown',
                        ],
                    ],
                    [
                        'id' => 'brand',
                        'label' => __('Brand', 'hlm-smart-product-filters'),
                        'key' => 'brand',
                        'type' => 'checkbox',
                        'data_source' => 'attribute',
                        'source_key' => 'brand',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 5,
                        ],
                    ],
                    [
                        'id' => 'price',
                        'label' => __('Price', 'hlm-smart-product-filters'),
                        'key' => 'price',
                        'type' => 'range',
                        'data_source' => 'meta',
                        'source_key' => '_price',
                        'behavior' => [
                            'multi_select' => false,
                        ],
                        'visibility' => [],
                        'ui' => [
                            'style' => 'range',
                        ],
                    ],
                    [
                        'id' => 'tags',
                        'label' => __('Features', 'hlm-smart-product-filters'),
                        'key' => 'tags',
                        'type' => 'checkbox',
                        'data_source' => 'product_tag',
                        'source_key' => 'product_tag',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 5,
                        ],
                    ],
                ],
            ],
            'minimal' => [
                'name' => __('Minimal', 'hlm-smart-product-filters'),
                'description' => __('Just categories and price - simple and clean.', 'hlm-smart-product-filters'),
                'icon' => 'dashicons-minus',
                'filters' => [
                    [
                        'id' => 'category',
                        'label' => __('Category', 'hlm-smart-product-filters'),
                        'key' => 'category',
                        'type' => 'checkbox',
                        'data_source' => 'product_cat',
                        'source_key' => 'product_cat',
                        'behavior' => [
                            'multi_select' => true,
                            'operator' => 'OR',
                        ],
                        'visibility' => [
                            'hide_empty' => true,
                            'include_children' => true,
                        ],
                        'ui' => [
                            'style' => 'list',
                            'show_more_threshold' => 8,
                        ],
                    ],
                    [
                        'id' => 'price',
                        'label' => __('Price', 'hlm-smart-product-filters'),
                        'key' => 'price',
                        'type' => 'range',
                        'data_source' => 'meta',
                        'source_key' => '_price',
                        'behavior' => [
                            'multi_select' => false,
                        ],
                        'visibility' => [],
                        'ui' => [
                            'style' => 'range',
                        ],
                    ],
                ],
            ],
        ];
    }
}
