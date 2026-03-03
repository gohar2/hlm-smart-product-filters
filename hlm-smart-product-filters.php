<?php
/**
 * Plugin Name: HLM Smart Product Filters
 * Description: Smart, extensible product filters for WooCommerce.
 * Version: 0.4.14
 * Author: HLM
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Text Domain: hlm-smart-product-filters
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HLM_FILTERS_VERSION', '0.4.14');
define('HLM_FILTERS_PATH', plugin_dir_path(__FILE__));
define('HLM_FILTERS_URL', plugin_dir_url(__FILE__));
define('HLM_FILTERS_FILE', __FILE__);

require_once HLM_FILTERS_PATH . 'src/Support/Autoloader.php';
HLM\Filters\Support\Autoloader::register();

if (file_exists(HLM_FILTERS_PATH . 'vendor/autoload.php')) {
    require_once HLM_FILTERS_PATH . 'vendor/autoload.php';
}

register_activation_hook(HLM_FILTERS_FILE, ['HLM\\Filters\\Plugin', 'activate']);
register_deactivation_hook(HLM_FILTERS_FILE, ['HLM\\Filters\\Plugin', 'deactivate']);

/**
 * Check if the current page is globally excluded from showing filters.
 * Safe to call anywhere on the frontend after the 'wp' action has fired.
 *
 * @return bool True if filters should be hidden on the current page.
 */
function hlm_spf_is_globally_excluded(): bool
{
    $config     = get_option('hlm_filters_config', []);
    $exclusions = $config['global']['global_exclusions'] ?? [];

    if (!empty($exclusions['shop']) && function_exists('is_shop') && is_shop()) {
        return true;
    }

    if (!empty($exclusions['categories']) && is_array($exclusions['categories'])
        && function_exists('is_product_category') && is_product_category($exclusions['categories'])) {
        return true;
    }

    if (!empty($exclusions['tags']) && is_array($exclusions['tags'])
        && function_exists('is_product_tag') && is_product_tag($exclusions['tags'])) {
        return true;
    }

    return false;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('HLM Smart Product Filters requires WooCommerce to be active.', 'hlm-smart-product-filters');
            echo '</p></div>';
        });
        return;
    }

    HLM\Filters\Plugin::instance()->boot();
});
