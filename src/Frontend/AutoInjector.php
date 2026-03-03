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

        $queried = get_queried_object();
        if ($queried && isset($queried->term_id, $queried->taxonomy)) {
            if ($queried->taxonomy === 'product_cat' && !empty($exclusions['categories']) && is_array($exclusions['categories'])) {
                if (in_array((int) $queried->term_id, array_map('intval', $exclusions['categories']), true)) {
                    return true;
                }
            }
            if ($queried->taxonomy === 'product_tag' && !empty($exclusions['tags']) && is_array($exclusions['tags'])) {
                if (in_array((int) $queried->term_id, array_map('intval', $exclusions['tags']), true)) {
                    return true;
                }
            }
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
