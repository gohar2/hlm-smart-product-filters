<?php

namespace HLM\Filters\Ajax;

use HLM\Filters\Query\FilterProcessor;
use HLM\Filters\Rendering\ProductRenderer;
use HLM\Filters\Rendering\Shortcode;
use HLM\Filters\Support\Config;
use HLM\Filters\Cache\CacheStore;
use WP_Query;

final class FilterAjax
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('wp_ajax_hlm_apply_filters', [$this, 'apply']);
        add_action('wp_ajax_nopriv_hlm_apply_filters', [$this, 'apply']);
    }

    public function apply(): void
    {
        check_ajax_referer('hlm_filters_nonce', 'nonce');

        if (!apply_filters('hlm_filters_ajax_permission', true)) {
            wp_send_json_error(['message' => __('Permission denied.', 'hlm-smart-product-filters')], 403);
        }

        $config = $this->config->get();
        $request = $this->parse_request();

        $cached = $this->get_cached_response($config, $request);
        if ($cached !== null) {
            wp_send_json_success($cached);
        }

        $processor = new FilterProcessor();
        $args = $processor->build_args($config, $request);

        $query = new WP_Query($args);
        $renderer = new ProductRenderer();
        $render_context = $request['render_context'] ?? 'shortcode';
        $filters_html = (new Shortcode($this->config))->render_with_request($request, $render_context);

        $response = [
            'html' => $renderer->render_products($query, $config),
            'pagination' => $renderer->render_pagination($query, $request),
            'filters' => $filters_html,
            'result_count' => $renderer->render_result_count($query),
            'total' => (int) $query->found_posts,
        ];

        $this->set_cached_response($config, $request, $response);
        wp_send_json_success($response);
    }

    private function parse_request(): array
    {
        $data = [];
        if (!empty($_POST['form'])) {
            parse_str((string) wp_unslash($_POST['form']), $data);
        }

        $filters = $data['hlm_filters'] ?? [];
        $context = $data['hlm_context'] ?? [];
        $render_context = $data['hlm_render_context'] ?? 'shortcode';

        return [
            'filters' => is_array($filters) ? $filters : [],
            'search' => $data['s'] ?? '',
            'sort' => $data['orderby'] ?? '',
            'page' => isset($data['paged']) ? (int) $data['paged'] : 1,
            'context' => is_array($context) ? $context : [],
            'render_context' => sanitize_key((string) $render_context),
        ];
    }

    private function get_cached_response(array $config, array $request): ?array
    {
        if (empty($config['global']['enable_cache'])) {
            return null;
        }

        $cache = new CacheStore();
        $key = $this->cache_key($config, $request);
        $cached = $cache->get($key);
        return is_array($cached) ? $cached : null;
    }

    private function set_cached_response(array $config, array $request, array $response): void
    {
        if (empty($config['global']['enable_cache'])) {
            return;
        }

        $ttl = (int) ($config['global']['cache_ttl_seconds'] ?? 300);
        if ($ttl <= 0) {
            return;
        }

        $cache = new CacheStore();
        $cache->set($this->cache_key($config, $request), $response, $ttl);
    }

    private function cache_key(array $config, array $request): string
    {
        $version = (int) get_option('hlm_filters_cache_version', 1);
        $payload = [
            'schema' => $config['schema_version'] ?? 1,
            'version' => $version,
            'filters' => $request['filters'] ?? [],
            'search' => $request['search'] ?? '',
            'sort' => $request['sort'] ?? '',
            'page' => $request['page'] ?? 1,
            'context' => $request['context'] ?? [],
            'render' => $request['render_context'] ?? 'shortcode',
        ];
        return 'ajax_' . md5(wp_json_encode($payload));
    }
}
