<?php

namespace HLM\Filters\Admin;

use HLM\Filters\Support\Config;

final class ImportExportPage
{
    private Config $config;
    private string $page_slug = 'hlm-product-filters-import-export';

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_hlm_import_filters', [$this, 'handle_import']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'hlm-product-filters',
            __('Import/Export', 'hlm-smart-product-filters'),
            __('Import/Export', 'hlm-smart-product-filters'),
            'manage_woocommerce',
            $this->page_slug,
            [$this, 'render_page']
        );
    }

    public function handle_import(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to import filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_import_filters');

        $payload = wp_unslash($_POST['hlm_import_payload'] ?? '');
        $decoded = json_decode((string) $payload, true);
        if (!is_array($decoded)) {
            wp_safe_redirect(add_query_arg(['page' => $this->page_slug, 'imported' => 'false'], admin_url('admin.php')));
            exit;
        }

        $sanitized = $this->config->sanitize($decoded);
        update_option(Config::OPTION_KEY, $sanitized);

        wp_safe_redirect(add_query_arg(['page' => $this->page_slug, 'imported' => 'true'], admin_url('admin.php')));
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $config = $this->config->get();
        $export = wp_json_encode($config, JSON_PRETTY_PRINT);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HLM Filters Import/Export', 'hlm-smart-product-filters') . '</h1>';

        echo '<h2>' . esc_html__('Export', 'hlm-smart-product-filters') . '</h2>';
        echo '<textarea class="large-text" rows="12" readonly>' . esc_textarea($export) . '</textarea>';

        echo '<h2>' . esc_html__('Import', 'hlm-smart-product-filters') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hlm_import_filters">';
        wp_nonce_field('hlm_import_filters');
        echo '<textarea class="large-text" rows="12" name="hlm_import_payload"></textarea>';
        submit_button(__('Import Config', 'hlm-smart-product-filters'));
        echo '</form>';

        echo '</div>';
    }
}
