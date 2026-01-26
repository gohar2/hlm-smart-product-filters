<?php

namespace HLM\Filters\Rendering;

use HLM\Filters\Query\FilterValidator;
use HLM\Filters\Query\FacetCalculator;
use HLM\Filters\Support\Config;

final class Shortcode
{
    private Config $config;
    private FilterValidator $validator;
    private FacetCalculator $facets;
    private TemplateLoader $templates;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->validator = new FilterValidator();
        $this->facets = new FacetCalculator();
        $this->templates = new TemplateLoader(HLM_FILTERS_PATH);
    }

    public function register(): void
    {
        add_shortcode('hlm_smart_product_filters', [$this, 'render']);
    }

    public function render(array $atts = []): string
    {
        $request = [
            'filters' => $_GET['hlm_filters'] ?? [],
            'search' => $_GET['s'] ?? '',
            'sort' => $_GET['orderby'] ?? '',
            'context' => $this->current_context(),
        ];

        $config = $this->config->get();
        $mode = $config['global']['render_mode'] ?? 'shortcode';
        if ($mode === 'auto') {
            return '';
        }

        return $this->render_with_request($request, 'shortcode');
    }

    public function render_auto(): string
    {
        $request = [
            'filters' => $_GET['hlm_filters'] ?? [],
            'search' => $_GET['s'] ?? '',
            'sort' => $_GET['orderby'] ?? '',
            'context' => $this->current_context(),
        ];

        return $this->render_with_request($request, 'auto');
    }

    public function render_with_request(array $request, string $render_context = 'shortcode'): string
    {
        $config = $this->config->get();
        $filters = is_array($config['filters'] ?? null) ? $config['filters'] : [];

        do_action('hlm_filters_before_render', $config, $filters);

        $request_filters = $request['filters'] ?? [];
        $sort_value = (string) ($request['sort'] ?? '');
        if ($sort_value === '') {
            $sort_value = (string) ($config['global']['default_sort'] ?? 'menu_order');
        }
        $selected = $this->validator->normalize(is_array($request_filters) ? $request_filters : []);
        $enable_counts = (bool) ($config['global']['enable_counts'] ?? true);
        $context = is_array($request['context'] ?? null) ? $request['context'] : $this->current_context();
        $request = [
            'filters' => $request_filters,
            'search' => $request['search'] ?? '',
            'sort' => $sort_value,
            'context' => $context,
        ];
        $counts_by_filter = $enable_counts ? $this->facets->calculate($config, $request) : [];

        $filter_items = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $key = (string) ($filter['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!$this->should_render_filter($filter, $context, $render_context)) {
                continue;
            }
            $taxonomy = $this->resolve_taxonomy($filter);
            if ($taxonomy === '') {
                continue;
            }
            $hide_empty = (bool) ($filter['visibility']['hide_empty'] ?? false);
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
            ]);
            if (is_wp_error($terms)) {
                $terms = [];
            }
            $counts = $counts_by_filter[$key] ?? [];
            if ($hide_empty && $counts) {
                $terms = array_values(array_filter($terms, static function ($term) use ($counts) {
                    $term_id = (int) $term->term_id;
                    return isset($counts[$term_id]) && $counts[$term_id] > 0;
                }));
            }

            $filter_items[] = [
                'key' => $key,
                'label' => (string) ($filter['label'] ?? $key),
                'type' => (string) ($filter['type'] ?? 'checkbox'),
                'style' => (string) ($filter['ui']['style'] ?? $filter['type'] ?? 'list'),
                'terms' => $terms,
                'selected' => $selected[$key] ?? [],
                'multi_select' => (bool) ($filter['behavior']['multi_select'] ?? true),
                'counts' => $counts,
                'swatch_map' => $filter['ui']['swatch_map'] ?? [],
                'swatch_type' => (string) ($filter['ui']['swatch_type'] ?? 'color'),
                'show_more_threshold' => (int) ($filter['ui']['show_more_threshold'] ?? 0),
            ];

            $filter_items[count($filter_items) - 1] = apply_filters(
                'hlm_filters_render_item',
                $filter_items[count($filter_items) - 1],
                $filter
            );
        }

        ob_start();
        $this->templates->render('filters.php', [
            'filters' => $filter_items,
            'current_url' => $this->current_url(),
            'clear_url' => $this->clear_url(),
            'enable_counts' => $enable_counts,
            'context' => $context,
            'enable_apply_button' => (bool) ($config['global']['enable_apply_button'] ?? false),
            'render_context' => $render_context,
            'ui_density' => $config['global']['ui']['density'] ?? 'comfy',
            'ui_header_style' => $config['global']['ui']['header_style'] ?? 'pill',
            'orderby' => $sort_value,
            'search' => (string) ($request['search'] ?? ''),
        ]);
        $html = ob_get_clean();

        do_action('hlm_filters_after_render', $config, $filters, $html);

        return $html;
    }

    private function resolve_taxonomy(array $filter): string
    {
        $type = $filter['data_source'] ?? 'taxonomy';
        $source = (string) ($filter['source_key'] ?? '');
        if ($type === 'product_cat') {
            return 'product_cat';
        }
        if ($type === 'product_tag') {
            return 'product_tag';
        }
        if ($type === 'attribute') {
            if ($source === '') {
                return '';
            }
            if (strpos($source, 'pa_') === 0) {
                return $source;
            }
            return wc_attribute_taxonomy_name($source);
        }
        return $source;
    }

    private function current_context(): array
    {
        $category_id = 0;
        $tag_id = 0;

        $queried = get_queried_object();
        if ($queried && isset($queried->term_id, $queried->taxonomy)) {
            if ($queried->taxonomy === 'product_cat') {
                $category_id = (int) $queried->term_id;
            }
            if ($queried->taxonomy === 'product_tag') {
                $tag_id = (int) $queried->term_id;
            }
        }

        return [
            'category_id' => $category_id,
            'tag_id' => $tag_id,
        ];
    }

    private function should_render_filter(array $filter, array $context, string $render_context): bool
    {
        $visibility = $filter['visibility'] ?? [];
        $category_id = (int) ($context['category_id'] ?? 0);
        $tag_id = (int) ($context['tag_id'] ?? 0);
        $render_mode = $filter['render_mode'] ?? 'both';

        if ($render_mode !== 'both' && $render_mode !== $render_context) {
            return false;
        }

        $show_cats = $visibility['show_on_categories'] ?? [];
        $hide_cats = $visibility['hide_on_categories'] ?? [];
        $include_children = !empty($visibility['include_children']);

        if ($category_id > 0) {
            if ($this->matches_term($category_id, $hide_cats, 'product_cat', $include_children)) {
                return false;
            }
            if (!empty($show_cats) && !$this->matches_term($category_id, $show_cats, 'product_cat', $include_children)) {
                return false;
            }
        } elseif (!empty($show_cats)) {
            return false;
        }

        $show_tags = $visibility['show_on_tags'] ?? [];
        $hide_tags = $visibility['hide_on_tags'] ?? [];
        $include_tag_children = !empty($visibility['include_tag_children']);

        if ($tag_id > 0) {
            if ($this->matches_term($tag_id, $hide_tags, 'product_tag', $include_tag_children)) {
                return false;
            }
            if (!empty($show_tags) && !$this->matches_term($tag_id, $show_tags, 'product_tag', $include_tag_children)) {
                return false;
            }
        } elseif (!empty($show_tags)) {
            return false;
        }

        return true;
    }

    private function matches_term(int $term_id, array $allowed, string $taxonomy, bool $include_children): bool
    {
        if (in_array($term_id, $allowed, true)) {
            return true;
        }

        if (!$include_children || !$allowed) {
            return false;
        }

        foreach ($allowed as $parent_id) {
            if (term_is_ancestor_of((int) $parent_id, $term_id, $taxonomy)) {
                return true;
            }
        }

        return false;
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return esc_url_raw($scheme . '://' . $host . $uri);
    }

    private function clear_url(): string
    {
        $params = $_GET;
        unset($params['hlm_filters'], $params['paged']);
        $url = $this->current_url();
        $base = strtok($url, '?');
        if (!$params) {
            return esc_url_raw($base);
        }
        return esc_url_raw($base . '?' . http_build_query($params));
    }
}
