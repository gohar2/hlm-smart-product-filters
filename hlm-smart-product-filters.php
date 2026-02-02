<?php
/**
 * Plugin Name: HLM Smart Product Filters
 * Description: Smart, extensible product filters for WooCommerce.
 * Version: 0.2.2
 * Author: HLM
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Text Domain: hlm-smart-product-filters
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HLM_FILTERS_VERSION', '0.2.2'); 
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
