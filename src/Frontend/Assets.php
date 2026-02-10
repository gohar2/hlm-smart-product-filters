<?php

namespace HLM\Filters\Frontend;

use HLM\Filters\Support\Config;

final class Assets
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        // Load on WooCommerce pages and any page that might have the shortcode
        $should_load = is_product_category() || is_product_tag() || is_shop() || is_page() || is_singular('product') || is_tax();

        if (!$should_load) {
            return;
        }

        wp_register_style(
            'hlm-filters',
            HLM_FILTERS_URL . 'assets/css/filters.css',
            [],
            HLM_FILTERS_VERSION
        );

        wp_register_script(
            'hlm-filters',
            HLM_FILTERS_URL . 'assets/js/filters.js',
            ['jquery'],
            HLM_FILTERS_VERSION,
            true
        );

        $config = $this->config->get();
        $enable_ajax = (bool) ($config['global']['enable_ajax'] ?? true);
        $enable_apply_button = (bool) ($config['global']['enable_apply_button'] ?? false);

        wp_localize_script('hlm-filters', 'HLMFilters', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hlm_filters_nonce'),
            'enableAjax' => $enable_ajax,
            'enableApply' => $enable_apply_button,
        ]);

        $this->enqueue_styles($config);
        wp_enqueue_script('hlm-filters');
    }

    private function enqueue_styles(array $config): void
    {
        wp_enqueue_style('hlm-filters');

        $ui = $config['global']['ui'] ?? [];
        $vars = [
            '--hlm-accent' => $ui['accent_color'] ?? '#0f766e',
            '--hlm-bg' => $ui['background_color'] ?? '#ffffff',
            '--hlm-text' => $ui['text_color'] ?? '#0f172a',
            '--hlm-border' => $ui['border_color'] ?? '#e2e8f0',
            '--hlm-muted' => $ui['muted_text_color'] ?? '#64748b',
            '--hlm-panel-gradient' => $ui['panel_gradient'] ?? 'linear-gradient(135deg, rgba(15, 118, 110, 0.12), rgba(255, 255, 255, 0))',
            '--hlm-radius' => isset($ui['radius']) ? ((int) $ui['radius']) . 'px' : '10px',
            '--hlm-spacing' => isset($ui['spacing']) ? ((int) $ui['spacing']) . 'px' : '12px',
            '--hlm-density' => $ui['density'] ?? 'comfy',
            '--hlm-header-style' => $ui['header_style'] ?? 'pill',
            '--hlm-font-scale' => isset($ui['font_scale']) ? ((int) $ui['font_scale']) . '%' : '100%',
            '--hlm-font' => $ui['font_family'] ?? 'inherit',
        ];

        $declarations = '';
        foreach ($vars as $name => $value) {
            if ($value === '') {
                continue;
            }
            $declarations .= $name . ':' . $value . ';';
        }

        if ($declarations !== '') {
            wp_add_inline_style('hlm-filters', '.hlm-filters-wrap{' . $declarations . '}');
        }
    }
}
