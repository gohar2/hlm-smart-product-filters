<?php

namespace HLM\Filters\Frontend;

use HLM\Filters\Support\Config;

final class AutoInjector
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('wp', [$this, 'maybe_hook']);
    }

    public function maybe_hook(): void
    {
        $config = $this->config->get();
        $mode = $config['global']['render_mode'] ?? 'shortcode';
        if ($mode === 'shortcode') {
            return;
        }

        $hook = $config['global']['auto_hook'] ?? 'woocommerce_before_shop_loop';
        if ($hook === '') {
            $hook = 'woocommerce_before_shop_loop';
        }

        add_action($hook, [$this, 'render']);
    }

    public function render(): void
    {
        $config = $this->config->get();
        $mode = $config['global']['render_mode'] ?? 'shortcode';
        if ($mode === 'shortcode') {
            return;
        }

        if (is_shop() && empty($config['global']['auto_on_shop'])) {
            return;
        }
        if (is_product_category() && empty($config['global']['auto_on_categories'])) {
            return;
        }
        if (is_product_tag() && empty($config['global']['auto_on_tags'])) {
            return;
        }

        echo (new \HLM\Filters\Rendering\Shortcode($this->config))->render_auto();
    }
}
