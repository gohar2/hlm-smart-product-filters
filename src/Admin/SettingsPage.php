<?php

namespace HLM\Filters\Admin;

use HLM\Filters\Support\Config;

final class SettingsPage
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('HLM Product Filters', 'hlm-smart-product-filters'),
            __('HLM Product Filters', 'hlm-smart-product-filters'),
            'manage_woocommerce',
            'hlm-product-filters',
            [$this, 'render_page'],
            'dashicons-filter',
            56
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_hlm-product-filters') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'hlm-filters-admin-settings',
            HLM_FILTERS_URL . 'assets/js/admin-settings.js',
            ['wp-color-picker'],
            HLM_FILTERS_VERSION,
            true
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'hlm_filters_settings',
            Config::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->config->defaults(),
            ]
        );

        add_settings_section(
            'hlm_filters_global',
            __('Global Settings', 'hlm-smart-product-filters'),
            '__return_false',
            'hlm_filters_settings'
        );

        $this->add_checkbox_field('enable_ajax', __('Enable AJAX filtering', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('enable_cache', __('Enable caching', 'hlm-smart-product-filters'));
        $this->add_text_field('cache_ttl_seconds', __('Cache TTL (seconds)', 'hlm-smart-product-filters'));
        $this->add_text_field('products_per_page', __('Products per page', 'hlm-smart-product-filters'));
        $this->add_text_field('default_sort', __('Default sort', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('debug_mode', __('Debug mode', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('enable_apply_button', __('Enable Apply button', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('enable_counts', __('Enable counts', 'hlm-smart-product-filters'));
        $this->add_select_field('render_mode', __('Render mode', 'hlm-smart-product-filters'), [
            'shortcode' => __('Shortcode only', 'hlm-smart-product-filters'),
            'auto' => __('Auto inject only', 'hlm-smart-product-filters'),
            'both' => __('Shortcode + auto inject', 'hlm-smart-product-filters'),
        ]);
        $this->add_text_field('auto_hook', __('Auto inject hook', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('auto_on_shop', __('Auto inject on shop', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('auto_on_categories', __('Auto inject on category archives', 'hlm-smart-product-filters'));
        $this->add_checkbox_field('auto_on_tags', __('Auto inject on tag archives', 'hlm-smart-product-filters'));
        $this->add_select_field('product_render_mode', __('Product render mode', 'hlm-smart-product-filters'), [
            'woocommerce' => __('WooCommerce loop', 'hlm-smart-product-filters'),
            'elementor' => __('Elementor template', 'hlm-smart-product-filters'),
        ]);
        $this->add_elementor_template_field();

        add_settings_section(
            'hlm_filters_ui',
            __('UI Settings', 'hlm-smart-product-filters'),
            '__return_false',
            'hlm_filters_settings'
        );
        $this->add_color_field('ui][accent_color', __('Accent color', 'hlm-smart-product-filters'), __('Primary color for buttons, links, and accents', 'hlm-smart-product-filters'));
        $this->add_color_field('ui][background_color', __('Background color', 'hlm-smart-product-filters'), __('Main background color of the filter panel', 'hlm-smart-product-filters'));
        $this->add_color_field('ui][text_color', __('Text color', 'hlm-smart-product-filters'), __('Main text color for filter labels and content', 'hlm-smart-product-filters'));
        $this->add_color_field('ui][border_color', __('Border color', 'hlm-smart-product-filters'), __('Color for borders around filter panels and elements', 'hlm-smart-product-filters'));
        $this->add_color_field('ui][muted_text_color', __('Muted text color', 'hlm-smart-product-filters'), __('Color for secondary text like counts and hints', 'hlm-smart-product-filters'));
        $this->add_color_field('ui][panel_gradient', __('Panel background gradient', 'hlm-smart-product-filters'), __('CSS gradient for filter panel backgrounds (e.g., linear-gradient(...))', 'hlm-smart-product-filters'));
        $this->add_text_field('ui][radius', __('Corner radius (px)', 'hlm-smart-product-filters'), __('Border radius for rounded corners (default: 10px)', 'hlm-smart-product-filters'));
        $this->add_text_field('ui][spacing', __('Vertical spacing (px)', 'hlm-smart-product-filters'), __('Base spacing unit used throughout filters (default: 12px)', 'hlm-smart-product-filters'));
        $this->add_select_field('ui][density', __('Density', 'hlm-smart-product-filters'), [
            'compact' => __('Compact - Tighter spacing for more filters', 'hlm-smart-product-filters'),
            'comfy' => __('Comfy - Balanced spacing (recommended)', 'hlm-smart-product-filters'),
            'airy' => __('Airy - More spacious layout', 'hlm-smart-product-filters'),
        ], __('Controls the spacing density of filter elements', 'hlm-smart-product-filters'));
        $this->add_select_field('ui][header_style', __('Panel header style', 'hlm-smart-product-filters'), [
            'pill' => __('Pill - Rounded background badge', 'hlm-smart-product-filters'),
            'underline' => __('Underline - Accent line below label', 'hlm-smart-product-filters'),
            'plain' => __('Plain - No special styling', 'hlm-smart-product-filters'),
        ], __('Visual style for filter panel headers/labels', 'hlm-smart-product-filters'));
        $this->add_select_field('ui][list_layout', __('Default list layout', 'hlm-smart-product-filters'), [
            'stacked' => __('Stacked - Each item on a new line', 'hlm-smart-product-filters'),
            'inline' => __('Inline - Items flow horizontally', 'hlm-smart-product-filters'),
        ], __('Default layout for checkbox list filters (can be overridden per filter)', 'hlm-smart-product-filters'));
        $this->add_text_field('ui][font_scale', __('Font scale (%)', 'hlm-smart-product-filters'), __('Font size multiplier (e.g., 100 = normal, 110 = 10% larger)', 'hlm-smart-product-filters'));
        $this->add_text_field('ui][font_family', __('Font family', 'hlm-smart-product-filters'), __('CSS font-family value (e.g., "Arial, sans-serif" or "inherit")', 'hlm-smart-product-filters'));
    }

    public function sanitize_settings($input): array
    {
        return $this->config->sanitize(is_array($input) ? $input : []);
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HLM Product Filters', 'hlm-smart-product-filters') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('hlm_filters_settings');
        do_settings_sections('hlm_filters_settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    private function add_checkbox_field(string $key, string $label): void
    {
        add_settings_field(
            'hlm_filters_' . $key,
            $label,
            function () use ($key) {
                $config = $this->config->get();
                $value = $config['global'][$key] ?? false;
                $field_name = Config::OPTION_KEY . '[global][' . esc_attr($key) . ']';
                // Hidden field ensures unchecked values are saved as 0
                printf('<input type="hidden" name="%s" value="0">', esc_attr($field_name));
                printf(
                    '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
                    esc_attr($field_name),
                    checked($value, true, false),
                    esc_html__('Enabled', 'hlm-smart-product-filters')
                );
            },
            'hlm_filters_settings',
            'hlm_filters_global'
        );
    }

    private function add_text_field(string $key, string $label, string $description = ''): void
    {
        add_settings_field(
            'hlm_filters_' . $key,
            $label,
            function () use ($key, $description) {
                $config = $this->config->get();
                $value = $this->get_nested_value($config['global'], $key);
                printf(
                    '<input type="text" class="regular-text" name="%s[global][%s]" value="%s">',
                    esc_attr(Config::OPTION_KEY),
                    esc_attr($key),
                    esc_attr((string) $value)
                );
                if ($description) {
                    printf('<p class="description">%s</p>', esc_html($description));
                }
            },
            'hlm_filters_settings',
            $this->field_section($key)
        );
    }

    private function add_select_field(string $key, string $label, array $options, string $description = ''): void
    {
        add_settings_field(
            'hlm_filters_' . $key,
            $label,
            function () use ($key, $options, $description) {
                $config = $this->config->get();
                $value = $this->get_nested_value($config['global'], $key);
                echo '<select name="' . esc_attr(Config::OPTION_KEY) . '[global][' . esc_attr($key) . ']">';
                foreach ($options as $option_value => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_value),
                        selected($value, $option_value, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                if ($description) {
                    printf('<p class="description">%s</p>', esc_html($description));
                }
            },
            'hlm_filters_settings',
            $this->field_section($key)
        );
    }

    private function add_color_field(string $key, string $label, string $description = ''): void
    {
        add_settings_field(
            'hlm_filters_' . $key,
            $label,
            function () use ($key, $description) {
                $config = $this->config->get();
                $value = $this->get_nested_value($config['global'], $key);
                printf(
                    '<input type="text" class="regular-text hlm-color-field" name="%s[global][%s]" value="%s" placeholder="#0f766e">',
                    esc_attr(Config::OPTION_KEY),
                    esc_attr($key),
                    esc_attr((string) $value)
                );
                if ($description) {
                    printf('<p class="description">%s</p>', esc_html($description));
                }
            },
            'hlm_filters_settings',
            'hlm_filters_ui'
        );
    }

    private function add_elementor_template_field(): void
    {
        add_settings_field(
            'hlm_filters_elementor_template_id',
            __('Elementor template', 'hlm-smart-product-filters'),
            function () {
                $config = $this->config->get();
                $value = $this->get_nested_value($config['global'], 'elementor_template_id');

                if (class_exists('\\Elementor\\Plugin')) {
                    $templates = get_posts([
                        'post_type' => 'elementor_library',
                        'post_status' => 'publish',
                        'numberposts' => -1,
                    ]);

                    echo '<select name="' . esc_attr(Config::OPTION_KEY) . '[global][elementor_template_id]">';
                    echo '<option value="0">' . esc_html__('Select template', 'hlm-smart-product-filters') . '</option>';
                    foreach ($templates as $template) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr((string) $template->ID),
                            selected((int) $value, (int) $template->ID, false),
                            esc_html($template->post_title)
                        );
                    }
                    echo '</select>';
                    return;
                }

                printf(
                    '<input type="text" class="regular-text" name="%s[global][elementor_template_id]" value="%s" placeholder="123">',
                    esc_attr(Config::OPTION_KEY),
                    esc_attr((string) $value)
                );
            },
            'hlm_filters_settings',
            'hlm_filters_global'
        );
    }

    private function field_section(string $key): string
    {
        if (strpos($key, 'ui]') === 0) {
            return 'hlm_filters_ui';
        }
        return 'hlm_filters_global';
    }

    private function get_nested_value(array $data, string $key)
    {
        $parts = preg_split('/\\]\\[|\\[|\\]/', $key, -1, PREG_SPLIT_NO_EMPTY);
        $value = $data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }
            $value = $value[$part];
        }
        return $value;
    }
}
