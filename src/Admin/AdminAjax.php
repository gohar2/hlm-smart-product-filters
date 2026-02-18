<?php

namespace HLM\Filters\Admin;

final class AdminAjax
{
    public function register(): void
    {
        add_action('wp_ajax_hlm_get_terms', [$this, 'get_terms']);
        add_action('wp_ajax_hlm_get_color_codes', [$this, 'get_color_codes']);
    }

    public function get_terms(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hlm-smart-product-filters')], 403);
        }

        check_ajax_referer('hlm_filters_admin_nonce', 'nonce');

        $taxonomy = sanitize_key((string) ($_POST['taxonomy'] ?? ''));
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            wp_send_json_error(['message' => __('Invalid taxonomy.', 'hlm-smart-product-filters')], 400);
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            wp_send_json_error(['message' => __('Failed to load terms.', 'hlm-smart-product-filters')], 500);
        }

        $payload = [];
        foreach ($terms as $term) {
            $payload[] = [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'meta' => [
                    'color'        => (string) get_term_meta($term->term_id, 'color', true),
                    'swatch_color' => (string) get_term_meta($term->term_id, 'swatch_color', true),
                ],
            ];
        }

        wp_send_json_success(['terms' => $payload]);
    }

    public function get_color_codes(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hlm-smart-product-filters')], 403);
        }

        check_ajax_referer('hlm_filters_admin_nonce', 'nonce');

        $file = HLM_FILTERS_PATH . 'assets/color_coldes.json';
        if (!file_exists($file)) {
            wp_send_json_error(['message' => __('Color codes file not found.', 'hlm-smart-product-filters')], 404);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = file_get_contents($file);
        if ($json === false) {
            wp_send_json_error(['message' => __('Failed to read color codes.', 'hlm-smart-product-filters')], 500);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            wp_send_json_error(['message' => __('Invalid color codes data.', 'hlm-smart-product-filters')], 500);
        }

        wp_send_json_success($data);
    }
}
