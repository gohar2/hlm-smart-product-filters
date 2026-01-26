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

        $current = $this->config->get();
        $data = [
            'global' => $current['global'] ?? $this->config->defaults()['global'],
            'filters' => $this->sample_filters(),
        ];

        $this->config->update($data);

        wp_safe_redirect(add_query_arg(['page' => $this->page_slug, 'samples_loaded' => 'true'], admin_url('admin.php')));
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
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hlm_save_filters">';
        wp_nonce_field('hlm_save_filters');

        echo '<p>' . esc_html__('Create filters, reorder them, and set visibility rules.', 'hlm-smart-product-filters') . '</p>';

        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=hlm_load_sample_filters'), 'hlm_load_sample_filters')) . '">';
        echo esc_html__('Load Sample Filters', 'hlm-smart-product-filters');
        echo '</a></p>';

        echo '<div class="hlm-admin-preview">';
        echo '<h2>' . esc_html__('Live Preview', 'hlm-smart-product-filters') . '</h2>';
        echo '<div id="hlm-filters-preview"></div>';
        echo '</div>';

        echo '<datalist id="hlm-source-options">';
        foreach ($this->attributes as $attribute) {
            echo '<option value="' . esc_attr($attribute['slug']) . '">';
        }
        echo '<option value="product_cat">';
        echo '<option value="product_tag">';
        echo '</datalist>';

        echo '<ul id="hlm-filters-list">';
        foreach ($filters as $index => $filter) {
            $this->render_filter_row($index, $filter);
        }
        echo '</ul>';

        echo '<button type="button" class="button" id="hlm-add-filter">' . esc_html__('Add Filter', 'hlm-smart-product-filters') . '</button>';
        echo '<p class="submit">';
        submit_button(__('Save Filters', 'hlm-smart-product-filters'), 'primary', 'submit', false);
        echo '</p>';

        echo '<script type="text/template" id="hlm-filter-template">';
        $this->render_filter_row('__INDEX__', []);
        echo '</script>';

        echo '</form>';
        echo '</div>';
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
        $style = $filter['ui']['style'] ?? 'list';
        $swatch_type = $filter['ui']['swatch_type'] ?? 'color';
        $show_more_threshold = (string) ($filter['ui']['show_more_threshold'] ?? '');
        $swatch_map = $this->swatch_lines($filter['ui']['swatch_map'] ?? []);

        echo '<li class="hlm-filter-row">';
        echo '<div class="hlm-filter-handle" title="' . esc_attr__('Drag to reorder', 'hlm-smart-product-filters') . '"><span class="dashicons dashicons-menu"></span></div>';
        echo '<div class="hlm-filter-card">';
        echo '<div class="hlm-filter-card-header">';
        echo '<div class="hlm-filter-title">';
        echo '<strong class="hlm-filter-title-text">' . esc_html($label ?: __('New Filter', 'hlm-smart-product-filters')) . '</strong>';
        echo '<span class="hlm-filter-meta">' . esc_html($type) . ' · ' . esc_html($data_source) . '</span>';
        echo '</div>';
        echo '<div class="hlm-filter-actions">';
        echo '<button type="button" class="button hlm-edit-swatch" data-index="' . esc_attr($index) . '"><span class="dashicons dashicons-art"></span>' . esc_html__('Swatches', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="button hlm-toggle-filter" aria-expanded="true"><span class="dashicons dashicons-arrow-up-alt2"></span>' . esc_html__('Collapse', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="hlm-filter-fields">';
        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Basics', 'hlm-smart-product-filters') . '</h3>';

        $this->text_field($index, 'id', __('ID', 'hlm-smart-product-filters'), $id, ['data-help' => __('Internal unique ID (no spaces).', 'hlm-smart-product-filters')]);
        $this->text_field($index, 'label', __('Label', 'hlm-smart-product-filters'), $label, ['data-help' => __('Shown to shoppers.', 'hlm-smart-product-filters')]);
        $this->text_field($index, 'key', __('Key (query string)', 'hlm-smart-product-filters'), $key, ['data-help' => __('Used in URL, keep short.', 'hlm-smart-product-filters')]);

        $this->select_field($index, 'type', __('Type', 'hlm-smart-product-filters'), $type, [
            'checkbox' => __('Checkbox', 'hlm-smart-product-filters'),
            'dropdown' => __('Dropdown', 'hlm-smart-product-filters'),
            'swatch' => __('Swatch', 'hlm-smart-product-filters'),
            'range' => __('Range (future)', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How shoppers select options.', 'hlm-smart-product-filters')]);
        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Data Source', 'hlm-smart-product-filters') . '</h3>';
        $this->select_field($index, 'data_source', __('Data source', 'hlm-smart-product-filters'), $data_source, [
            'taxonomy' => __('Custom taxonomy', 'hlm-smart-product-filters'),
            'attribute' => __('Attribute (pa_*)', 'hlm-smart-product-filters'),
            'product_cat' => __('Product category', 'hlm-smart-product-filters'),
            'product_tag' => __('Product tag', 'hlm-smart-product-filters'),
        ], ['data-help' => __('Where options come from.', 'hlm-smart-product-filters')]);

        $this->text_field($index, 'source_key', __('Source key (taxonomy/attribute slug)', 'hlm-smart-product-filters'), $source_key, [
            'list' => 'hlm-source-options',
            'data-help' => __('Example: color or product_cat.', 'hlm-smart-product-filters'),
        ]);

        $this->select_field($index, 'render_mode', __('Render mode', 'hlm-smart-product-filters'), $render_mode, [
            'both' => __('Shortcode + auto', 'hlm-smart-product-filters'),
            'shortcode' => __('Shortcode only', 'hlm-smart-product-filters'),
            'auto' => __('Auto inject only', 'hlm-smart-product-filters'),
        ], ['data-help' => __('Where this filter shows.', 'hlm-smart-product-filters')]);

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
        $this->select_field($index, 'ui][style', __('Display style', 'hlm-smart-product-filters'), $style, [
            'list' => __('List', 'hlm-smart-product-filters'),
            'swatch' => __('Swatch', 'hlm-smart-product-filters'),
            'dropdown' => __('Dropdown', 'hlm-smart-product-filters'),
        ], ['data-help' => __('UI layout for options.', 'hlm-smart-product-filters')]);

        $this->select_field($index, 'ui][swatch_type', __('Swatch type', 'hlm-smart-product-filters'), $swatch_type, [
            'color' => __('Color', 'hlm-smart-product-filters'),
            'image' => __('Image URL', 'hlm-smart-product-filters'),
            'text' => __('Text', 'hlm-smart-product-filters'),
        ], ['data-help' => __('How swatches are rendered.', 'hlm-smart-product-filters')]);

        $this->text_field($index, 'ui][show_more_threshold', __('Show more threshold', 'hlm-smart-product-filters'), $show_more_threshold, ['data-help' => __('Hide options after N.', 'hlm-smart-product-filters')]);

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
        $this->textarea_field($index, 'swatch_map', __('Swatch map (term_id: value per line)', 'hlm-smart-product-filters'), $swatch_map);
        echo '</div>';

        echo '<div class="hlm-filter-section"><h3>' . esc_html__('Visibility', 'hlm-smart-product-filters') . '</h3>';
        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][visibility][include_children]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_children]" value="1" %s> %s',
            esc_attr($index),
            checked($include_children, true, false),
            esc_html__('Include category children', 'hlm-smart-product-filters')
        );
        echo '</label>';

        echo '<label class="hlm-filter-checkbox">';
        printf(
            '<input type="hidden" name="filters[%s][visibility][include_tag_children]" value="0">',
            esc_attr($index)
        );
        printf(
            '<input type="checkbox" name="filters[%s][visibility][include_tag_children]" value="1" %s> %s',
            esc_attr($index),
            checked($include_tag_children, true, false),
            esc_html__('Include tag children', 'hlm-smart-product-filters')
        );
        echo '</label>';

        $this->multi_select_field($index, 'visibility][show_on_categories', __('Show on categories', 'hlm-smart-product-filters'), $this->categories, $show_on_categories);
        $this->multi_select_field($index, 'visibility][hide_on_categories', __('Hide on categories', 'hlm-smart-product-filters'), $this->categories, $hide_on_categories);
        $this->multi_select_field($index, 'visibility][show_on_tags', __('Show on tags', 'hlm-smart-product-filters'), $this->tags, $show_on_tags);
        $this->multi_select_field($index, 'visibility][hide_on_tags', __('Hide on tags', 'hlm-smart-product-filters'), $this->tags, $hide_on_tags);
        echo '</div>';

        echo '</div>';
        echo '<button type="button" class="button-link-delete hlm-remove-filter">' . esc_html__('Remove', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';
        echo '</li>';
    }

    private function text_field($index, string $name, string $label, string $value, array $attrs = []): void
    {
        $attr_html = '';
        $help = $attrs['data-help'] ?? '';
        if ($help) {
            unset($attrs['data-help']);
        }
        foreach ($attrs as $attr => $attr_value) {
            $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($attr_value) . '"';
        }
        printf(
            '<label class="hlm-filter-field">%s%s<input type="text" name="filters[%s][%s]" value="%s" class="regular-text"%s></label>',
            esc_html($label),
            $help ? '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>' : '',
            esc_attr($index),
            esc_attr($name),
            esc_attr($value),
            $attr_html
        );
    }

    private function textarea_field($index, string $name, string $label, string $value): void
    {
        printf(
            '<label class="hlm-filter-field">%s<textarea name="filters[%s][%s]" rows="4" class="large-text">%s</textarea></label>',
            esc_html($label),
            esc_attr($index),
            esc_attr($name),
            esc_textarea($value)
        );
    }

    private function select_field($index, string $name, string $label, string $value, array $options, array $attrs = []): void
    {
        $help = $attrs['data-help'] ?? '';
        if ($help) {
            unset($attrs['data-help']);
        }
        $attr_html = '';
        foreach ($attrs as $attr => $attr_value) {
            $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($attr_value) . '"';
        }
        echo '<label class="hlm-filter-field">' . esc_html($label) . ($help ? '<span class="hlm-help" title="' . esc_attr($help) . '">?</span>' : '') . '<select name="filters[' . esc_attr($index) . '][' . esc_attr($name) . ']"' . $attr_html . '>';
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

    private function multi_select_field($index, string $name, string $label, array $options, array $selected): void
    {
        echo '<label class="hlm-filter-field">' . esc_html($label);
        echo '<select name="filters[' . esc_attr($index) . '][' . esc_attr($name) . '][]" multiple size="6">';
        foreach ($options as $id => $title) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $id),
                selected(in_array((int) $id, $selected, true), true, false),
                esc_html($title)
            );
        }
        echo '</select></label>';
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
        return [
            [
                'id' => 'color',
                'label' => 'Color',
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
                    'show_more_threshold' => 0,
                ],
            ],
            [
                'id' => 'breeds',
                'label' => 'Breeds',
                'key' => 'breeds',
                'type' => 'checkbox',
                'data_source' => 'attribute',
                'source_key' => 'breeds',
                'behavior' => [
                    'multi_select' => true,
                    'operator' => 'OR',
                ],
                'visibility' => [
                    'hide_empty' => true,
                ],
                'ui' => [
                    'style' => 'list',
                    'show_more_threshold' => 8,
                ],
            ],
            [
                'id' => 'size',
                'label' => 'Size',
                'key' => 'size',
                'type' => 'checkbox',
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
                    'style' => 'list',
                    'show_more_threshold' => 8,
                ],
            ],
            [
                'id' => 'gender',
                'label' => 'Gender',
                'key' => 'gender',
                'type' => 'checkbox',
                'data_source' => 'attribute',
                'source_key' => 'gender',
                'behavior' => [
                    'multi_select' => true,
                    'operator' => 'OR',
                ],
                'visibility' => [
                    'hide_empty' => true,
                ],
                'ui' => [
                    'style' => 'list',
                    'show_more_threshold' => 6,
                ],
            ],
            [
                'id' => 'category',
                'label' => 'Category',
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
                ],
                'ui' => [
                    'style' => 'list',
                    'show_more_threshold' => 8,
                ],
            ],
            [
                'id' => 'tags',
                'label' => 'Tags',
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
                    'show_more_threshold' => 8,
                ],
            ],
        ];
    }
}
