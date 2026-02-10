<?php

namespace HLM\Filters\Rendering;

use WP_Query;

final class ProductRenderer
{
    public function render_products(WP_Query $query, array $config = []): string
    {
        if (($config['global']['product_render_mode'] ?? 'woocommerce') === 'elementor' && class_exists('\\Elementor\\Plugin')) {
            $template_id = (int) ($config['global']['elementor_template_id'] ?? 0);
            if ($template_id > 0) {
                return $this->render_elementor_template($query, $template_id);
            }
        }

        if (!function_exists('wc_get_template_part')) {
            return '';
        }

        $html = '';
        $previous_query = $GLOBALS['wp_query'] ?? null;
        $GLOBALS['wp_query'] = $query;

        ob_start();
        if ($query->have_posts()) {
            wc_get_template('loop/loop-start.php');
            while ($query->have_posts()) {
                $query->the_post();
                wc_get_template_part('content', 'product');
            }
            wc_get_template('loop/loop-end.php');
        }
        $html = ob_get_clean();

        wp_reset_postdata();
        if ($previous_query instanceof WP_Query) {
            $GLOBALS['wp_query'] = $previous_query;
        }

        return $html;
    }

    public function render_result_count(WP_Query $query): string
    {
        $total = (int) $query->found_posts;
        $per_page = (int) $query->get('posts_per_page');
        $current_page = max(1, (int) $query->get('paged'));
        $first = $per_page > 0 ? (($current_page - 1) * $per_page) + 1 : 1;
        $last = $per_page > 0 ? min($current_page * $per_page, $total) : $total;

        if ($total === 0) {
            $text = __('No products found', 'hlm-smart-product-filters');
        } elseif ($total === 1) {
            $text = __('Showing the single result', 'hlm-smart-product-filters');
        } elseif ($per_page === 0 || $first === $last) {
            $text = sprintf(
                /* translators: %d: total number of products */
                __('Showing all %d results', 'hlm-smart-product-filters'),
                $total
            );
        } else {
            $text = sprintf(
                /* translators: 1: first product number, 2: last product number, 3: total products */
                __('Showing %1$d–%2$d of %3$d results', 'hlm-smart-product-filters'),
                $first,
                $last,
                $total
            );
        }

        return '<p class="woocommerce-result-count" aria-hidden="false">' . esc_html($text) . '</p>';
    }

    public function render_pagination(WP_Query $query, array $request = []): string
    {
        $total = (int) $query->max_num_pages;
        if ($total <= 1) {
            return '';
        }

        $current = max(1, (int) ($query->get('paged') ?: get_query_var('paged', 1)));
        $base = str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999)));
        $add_args = $this->build_pagination_args($request);
        $links = paginate_links([
            'base' => $base,
            'format' => '',
            'current' => $current,
            'total' => $total,
            'type' => 'list',
            'prev_text' => '&larr;',
            'next_text' => '&rarr;',
            'add_args' => $add_args,
        ]);

        if (!$links) {
            return '';
        }

        return '<nav class="woocommerce-pagination">' . $links . '</nav>';
    }

    private function build_pagination_args(array $request): array
    {
        $args = [];
        if (!empty($request['sort'])) {
            $args['orderby'] = $request['sort'];
        }
        if (!empty($request['search'])) {
            $args['s'] = $request['search'];
        }
        if (!empty($request['filters']) && is_array($request['filters'])) {
            $args['hlm_filters'] = $request['filters'];
        }
        if (!empty($request['context']) && is_array($request['context'])) {
            $args['hlm_context'] = $request['context'];
        }
        if (!empty($request['render_context'])) {
            $args['hlm_render_context'] = $request['render_context'];
        }
        return $args;
    }

    private function render_elementor_template(WP_Query $query, int $template_id): string
    {
        $previous_query = $GLOBALS['wp_query'] ?? null;
        $GLOBALS['wp_query'] = $query;

        ob_start();
        echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template_id);
        $html = ob_get_clean();

        wp_reset_postdata();
        if ($previous_query instanceof WP_Query) {
            $GLOBALS['wp_query'] = $previous_query;
        }

        return $html;
    }
}
