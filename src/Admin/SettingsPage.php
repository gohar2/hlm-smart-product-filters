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
        wp_enqueue_style(
            'hlm-filters-admin',
            HLM_FILTERS_URL . 'assets/css/admin-settings.css',
            [],
            HLM_FILTERS_VERSION
        );
        wp_enqueue_script(
            'hlm-filters-admin-settings',
            HLM_FILTERS_URL . 'assets/js/admin-settings.js',
            ['wp-color-picker', 'jquery'],
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
        echo '<form method="post" action="options.php" id="hlm-settings-form">';
        settings_fields('hlm_filters_settings');

        echo '<div class="hlm-settings-tabs">';

        // Tab nav
        echo '<div class="hlm-tab-nav" role="tablist">';
        echo '<button type="button" class="hlm-tab-button active" role="tab" aria-selected="true" data-tab="general">' . esc_html__('General', 'hlm-smart-product-filters') . '</button>';
        echo '<button type="button" class="hlm-tab-button" role="tab" aria-selected="false" data-tab="ui">' . esc_html__('Appearance', 'hlm-smart-product-filters') . '</button>';
        echo '</div>';

        // Panels
        echo '<div class="hlm-tab-panels">';

        echo '<div class="hlm-tab-panel active" role="tabpanel" data-tab="general">';
        $this->render_general_settings();
        echo '</div>';

        echo '<div class="hlm-tab-panel" role="tabpanel" data-tab="ui">';
        $this->render_ui_settings();
        echo '</div>';

        echo '</div>'; // .hlm-tab-panels

        // Submit inside the card
        submit_button();

        echo '</div>'; // .hlm-settings-tabs
        echo '</form>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     * General Tab
     * ----------------------------------------------------------------*/
    private function render_general_settings(): void
    {
        echo '<div class="hlm-columns">';

        // Left column
        echo '<div class="hlm-column">';
        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Performance & Behavior', 'hlm-smart-product-filters') . '</h2>';
        // $this->render_checkbox('enable_ajax', __('Enable AJAX filtering', 'hlm-smart-product-filters'));
        $this->render_checkbox('enable_cache', __('Enable caching', 'hlm-smart-product-filters'));
        $this->render_text('cache_ttl_seconds', __('Cache TTL (seconds)', 'hlm-smart-product-filters'));

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Product Display', 'hlm-smart-product-filters') . '</h2>';
        $this->render_text('products_per_page', __('Products per page', 'hlm-smart-product-filters'));
        $this->render_text('default_sort', __('Default sort', 'hlm-smart-product-filters'));
        $this->render_checkbox('enable_sort', __('Enable Sort By', 'hlm-smart-product-filters'));
        $this->render_select('product_render_mode', __('Product render mode', 'hlm-smart-product-filters'), [
            'woocommerce' => __('WooCommerce loop', 'hlm-smart-product-filters'),
            'elementor'   => __('Elementor template', 'hlm-smart-product-filters'),
        ]);
        $this->render_elementor_template_field();

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Debug', 'hlm-smart-product-filters') . '</h2>';
        $this->render_checkbox('debug_mode', __('Debug mode', 'hlm-smart-product-filters'));

        echo '</div>';

        // Right column
        echo '<div class="hlm-column">';

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Filter Behavior', 'hlm-smart-product-filters') . '</h2>';
        $this->render_checkbox('enable_apply_button', __('Enable Apply button', 'hlm-smart-product-filters'));
        $this->render_checkbox('enable_counts', __('Enable counts', 'hlm-smart-product-filters'));

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Auto Injection', 'hlm-smart-product-filters') . '</h2>';
        $this->render_select('render_mode', __('Render mode', 'hlm-smart-product-filters'), [
            'shortcode' => __('Shortcode only', 'hlm-smart-product-filters'),
            'auto'      => __('Auto inject only', 'hlm-smart-product-filters'),
            'both'      => __('Shortcode + auto inject', 'hlm-smart-product-filters'),
        ]);
        $this->render_text('auto_hook', __('Auto inject hook', 'hlm-smart-product-filters'));
        $this->render_checkbox('auto_on_shop', __('Auto inject on shop', 'hlm-smart-product-filters'));
        $this->render_checkbox('auto_on_categories', __('Auto inject on category archives', 'hlm-smart-product-filters'));
        $this->render_checkbox('auto_on_tags', __('Auto inject on tag archives', 'hlm-smart-product-filters'));
       
        echo '</div>';

        echo '</div>'; // .hlm-columns
    }

    /* ------------------------------------------------------------------
     * Appearance Tab
     * ----------------------------------------------------------------*/
    private function render_ui_settings(): void
    {
        echo '<div class="hlm-columns">';

        // Left column
        echo '<div class="hlm-column">';
        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Layout & Spacing', 'hlm-smart-product-filters') . '</h2>';
        $this->render_text('ui][radius', __('Corner radius', 'hlm-smart-product-filters'), __('e.g. 10px', 'hlm-smart-product-filters'));
        $this->render_text('ui][spacing', __('Vertical spacing', 'hlm-smart-product-filters'), __('e.g. 12px', 'hlm-smart-product-filters'));
        $this->render_select('ui][density', __('Density', 'hlm-smart-product-filters'), [
            'compact' => __('Compact', 'hlm-smart-product-filters'),
            'comfy'   => __('Comfy (recommended)', 'hlm-smart-product-filters'),
            'airy'    => __('Airy', 'hlm-smart-product-filters'),
        ], __('Controls spacing density of filter elements', 'hlm-smart-product-filters'));

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Style', 'hlm-smart-product-filters') . '</h2>';
        $this->render_select('ui][header_style', __('Panel header style', 'hlm-smart-product-filters'), [
            'pill'      => __('Pill', 'hlm-smart-product-filters'),
            'underline' => __('Underline', 'hlm-smart-product-filters'),
            'plain'     => __('Plain', 'hlm-smart-product-filters'),
        ]);
        $this->render_select('ui][list_layout', __('Default list layout', 'hlm-smart-product-filters'), [
            'stacked' => __('Stacked', 'hlm-smart-product-filters'),
            'inline'  => __('Inline', 'hlm-smart-product-filters'),
        ]);

        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Typography', 'hlm-smart-product-filters') . '</h2>';
        $this->render_text('ui][font_scale', __('Font scale (%)', 'hlm-smart-product-filters'), __('100 = normal, 110 = 10% larger', 'hlm-smart-product-filters'));
        $this->render_text('ui][font_family', __('Font family', 'hlm-smart-product-filters'), __('CSS font-family value or "inherit"', 'hlm-smart-product-filters'));
        echo '</div>';

        // Right column
        echo '<div class="hlm-column">';
        echo '<h2 class="hlm-settings-group-title">' . esc_html__('Colors', 'hlm-smart-product-filters') . '</h2>';
        $this->render_color('ui][accent_color', __('Accent color', 'hlm-smart-product-filters'), __('Primary color for buttons, links, and accents', 'hlm-smart-product-filters'));
        $this->render_color('ui][background_color', __('Background color', 'hlm-smart-product-filters'), __('Filter panel background', 'hlm-smart-product-filters'));
        $this->render_color('ui][text_color', __('Text color', 'hlm-smart-product-filters'), __('Main text color for labels and content', 'hlm-smart-product-filters'));
        $this->render_color('ui][border_color', __('Border color', 'hlm-smart-product-filters'), __('Borders around panels and elements', 'hlm-smart-product-filters'));
        $this->render_color('ui][muted_text_color', __('Muted text color', 'hlm-smart-product-filters'), __('Secondary text like counts and hints', 'hlm-smart-product-filters'));
        $this->render_color('ui][panel_gradient', __('Panel gradient', 'hlm-smart-product-filters'), __('CSS gradient for panel backgrounds', 'hlm-smart-product-filters'));
        echo '</div>';

        echo '</div>'; // .hlm-columns
    }

    /* ------------------------------------------------------------------
     * Field Renderers
     * ----------------------------------------------------------------*/
    private function render_checkbox(string $key, string $label): void
    {
        $config = $this->config->get();
        $value  = $this->get_nested_value($config['global'], $key);
        $value  = $value === '' ? false : (bool) $value;
        $name   = Config::OPTION_KEY . '[global][' . esc_attr($key) . ']';

        echo '<div class="hlm-settings-field">';
        echo '<span class="hlm-settings-label">' . esc_html($label) . '</span>';
        echo '<div>';
        printf('<input type="hidden" name="%s" value="0">', esc_attr($name));
        echo '<label class="hlm-checkbox-wrapper">';
        printf(
            '<input type="checkbox" name="%s" value="1" %s> %s',
            esc_attr($name),
            checked($value, true, false),
            esc_html__('Enabled', 'hlm-smart-product-filters')
        );
        echo '</label>';
        echo '</div>';
        echo '</div>';
    }

    private function render_text(string $key, string $label, string $description = ''): void
    {
        $config = $this->config->get();
        $value  = $this->get_nested_value($config['global'], $key);
        $name   = Config::OPTION_KEY . '[global][' . esc_attr($key) . ']';

        echo '<div class="hlm-settings-field">';
        echo '<label class="hlm-settings-label" for="hlm-f-' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<div>';
        printf(
            '<input type="text" id="hlm-f-%s" class="regular-text" name="%s" value="%s">',
            esc_attr($key),
            esc_attr($name),
            esc_attr((string) $value)
        );
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_select(string $key, string $label, array $options, string $description = ''): void
    {
        $config = $this->config->get();
        $value  = $this->get_nested_value($config['global'], $key);
        $name   = Config::OPTION_KEY . '[global][' . esc_attr($key) . ']';

        echo '<div class="hlm-settings-field">';
        echo '<label class="hlm-settings-label" for="hlm-f-' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<div>';
        echo '<select id="hlm-f-' . esc_attr($key) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $opt_val => $opt_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($opt_val),
                selected($value, $opt_val, false),
                esc_html($opt_label)
            );
        }
        echo '</select>';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_color(string $key, string $label, string $description = ''): void
    {
        $config = $this->config->get();
        $value  = $this->get_nested_value($config['global'], $key);
        $name   = Config::OPTION_KEY . '[global][' . esc_attr($key) . ']';

        echo '<div class="hlm-settings-field">';
        echo '<label class="hlm-settings-label">' . esc_html($label) . '</label>';
        echo '<div>';
        printf(
            '<input type="text" class="hlm-color-field" name="%s" value="%s" data-default-color="">',
            esc_attr($name),
            esc_attr((string) $value)
        );
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_elementor_template_field(): void
    {
        $config = $this->config->get();
        $value  = $this->get_nested_value($config['global'], 'elementor_template_id');
        $name   = Config::OPTION_KEY . '[global][elementor_template_id]';

        echo '<div class="hlm-settings-field">';
        echo '<label class="hlm-settings-label">' . esc_html__('Elementor template', 'hlm-smart-product-filters') . '</label>';
        echo '<div>';

        if (class_exists('\\Elementor\\Plugin')) {
            $templates = get_posts([
                'post_type'   => 'elementor_library',
                'post_status' => 'publish',
                'numberposts' => -1,
            ]);

            echo '<select name="' . esc_attr($name) . '">';
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
        } else {
            printf(
                '<input type="text" class="regular-text" name="%s" value="%s" placeholder="Template ID">',
                esc_attr($name),
                esc_attr((string) $value)
            );
        }

        echo '</div>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/
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
