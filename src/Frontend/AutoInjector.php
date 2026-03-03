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

        $should_render =
            (is_shop() && !empty($config['global']['auto_on_shop'])) ||
            (is_product_category() && !empty($config['global']['auto_on_categories'])) ||
            (is_product_tag() && !empty($config['global']['auto_on_tags']));

        if (!$should_render) {
            return;
        }

        // Check global page exclusions before hooking
        $exclusions = $config['global']['global_exclusions'] ?? [];
        if ($this->is_globally_excluded($exclusions)) {
            return;
        }

        $hook = $config['global']['auto_hook'] ?? 'woocommerce_before_shop_loop';
        if ($hook === '') {
            $hook = 'woocommerce_before_shop_loop';
        }

        add_action($hook, [$this, 'render']);
    }

    private function is_globally_excluded(array $exclusions): bool
    {
        if (!empty($exclusions['shop']) && function_exists('is_shop') && is_shop()) {
            return true;
        }

        if (!empty($exclusions['categories']) && is_array($exclusions['categories'])
            && is_product_category($exclusions['categories'])) {
            return true;
        }

        if (!empty($exclusions['tags']) && is_array($exclusions['tags'])
            && is_product_tag($exclusions['tags'])) {
            return true;
        }

        return false;
    }

    public function render(): void
    {
        $config = $this->config->get();
        $mode = $config['global']['render_mode'] ?? 'shortcode';
        if ($mode === 'shortcode') {
            return;
        }

        echo (new \HLM\Filters\Rendering\Shortcode($this->config))->render_auto();
    }
}
