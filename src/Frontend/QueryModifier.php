<?php

namespace HLM\Filters\Frontend;

use HLM\Filters\Query\FilterProcessor;
use HLM\Filters\Support\Config;

final class QueryModifier
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'modify_query']);
    }

    public function modify_query(\WP_Query $query): void
    {
        // Only modify main product queries
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Only on WooCommerce product archive pages
        if (!is_shop() && !is_product_category() && !is_product_tag() && !is_tax()) {
            return;
        }

        // Only modify product queries
        if ($query->get('post_type') !== 'product') {
            return;
        }

        $config = $this->config->get();
        $enable_ajax = (bool) ($config['global']['enable_ajax'] ?? true);

        // Only modify query if AJAX is disabled
        if ($enable_ajax) {
            return;
        }

        // Parse filter parameters from URL
        $request = $this->parse_request();

        // Build context from current page
        $context = $this->build_context();
        $request['context'] = $context;

        // If no filters are applied, don't modify the query
        if (empty($request['filters'])) {
            return;
        }

        $processor = new FilterProcessor();
        $args = $processor->build_args($config, $request);

        // Apply the filter arguments to the query
        foreach ($args as $key => $value) {
            if ($key === 'tax_query' && !empty($value)) {
                $query->set('tax_query', $value);
            } elseif ($key === 'post__in' && !empty($value)) {
                $query->set('post__in', $value);
            } elseif ($key === 'meta_query' && !empty($value)) {
                $query->set('meta_query', $value);
            } elseif ($key === 'meta_key' && !empty($value)) {
                $query->set('meta_key', $value);
            } elseif ($key === 'orderby' && !empty($value)) {
                $query->set('orderby', $value);
            } elseif ($key === 'order' && !empty($value)) {
                $query->set('order', $value);
            } elseif ($key === 'posts_per_page' && !empty($value)) {
                $query->set('posts_per_page', $value);
            } elseif ($key === 'paged' && !empty($value)) {
                $query->set('paged', $value);
            } elseif ($key === 's' && !empty($value)) {
                $query->set('s', $value);
            }
        }
    }

    private function parse_request(): array
    {
        $filters = [];
        $context = [];
        $render_context = 'auto';

        // Parse hlm_filters from query string
        if (!empty($_GET['hlm_filters']) && is_array($_GET['hlm_filters'])) {
            $filters = $_GET['hlm_filters'];
        }

        // Parse context
        if (!empty($_GET['hlm_context']) && is_array($_GET['hlm_context'])) {
            $context = $_GET['hlm_context'];
        }

        // Parse render context
        if (!empty($_GET['hlm_render_context'])) {
            $render_context = sanitize_key((string) $_GET['hlm_render_context']);
        }

        // Parse sort
        $sort = '';
        if (!empty($_GET['orderby'])) {
            $sort = sanitize_key((string) $_GET['orderby']);
        }

        // Parse search
        $search = '';
        if (!empty($_GET['s'])) {
            $search = sanitize_text_field((string) $_GET['s']);
        }

        // Parse page
        $page = 1;
        if (!empty($_GET['paged'])) {
            $page = max(1, (int) $_GET['paged']);
        }

        return [
            'filters' => $filters,
            'context' => $context,
            'render_context' => $render_context,
            'sort' => $sort,
            'search' => $search,
            'page' => $page,
        ];
    }

    private function build_context(): array
    {
        $context = [
            'category_id' => 0,
            'tag_id' => 0,
            'custom_taxonomy' => '',
            'custom_term_id' => 0,
        ];

        $queried = get_queried_object();
        if ($queried && isset($queried->term_id, $queried->taxonomy)) {
            if ($queried->taxonomy === 'product_cat') {
                $context['category_id'] = (int) $queried->term_id;
            } elseif ($queried->taxonomy === 'product_tag') {
                $context['tag_id'] = (int) $queried->term_id;
            } elseif (taxonomy_exists($queried->taxonomy)) {
                // Custom taxonomy
                $context['custom_taxonomy'] = $queried->taxonomy;
                $context['custom_term_id'] = (int) $queried->term_id;
            }
        }

        return $context;
    }
}
