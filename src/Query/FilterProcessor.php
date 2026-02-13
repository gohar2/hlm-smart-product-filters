<?php

namespace HLM\Filters\Query;

final class FilterProcessor
{
    private FilterValidator $validator;

    public function __construct(?FilterValidator $validator = null)
    {
        $this->validator = $validator ?: new FilterValidator();
    }

    public function build_args(array $config, array $request): array
    {
        $defaults = [
            'post_type' => 'product',
            'post_status' => 'publish',
        ];

        $request_filters = $request['filters'] ?? [];
        $normalized = $this->validator->normalize(is_array($request_filters) ? $request_filters : []);

        $tax_query = [
            'relation' => 'AND',
        ];
        $post_in = null;

        // Track which taxonomies are actively filtered by the user.
        // When a user selects terms from a filter, their selection OVERRIDES the page
        // context for that taxonomy — e.g. selecting "Electronics" on a "Clothing" category
        // page should show Electronics products, not intersect Clothing AND Electronics.
        $user_filtered_taxonomies = [];

        $attribute_filters = [];
        foreach (($config['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $key = $filter['key'] ?? '';
            if ($key === '' || !isset($normalized[$key])) {
                continue;
            }
            $values = $normalized[$key];
            if (!$values) {
                continue;
            }

            $data_source = $filter['data_source'] ?? 'taxonomy';
            $source_key = $filter['source_key'] ?? '';

            // Handle meta-based range/slider filters (e.g. price)
            $filter_type = $filter['type'] ?? '';
            if ($data_source === 'meta' && $source_key !== '' && ($filter_type === 'range' || $filter_type === 'slider')) {
                $range = is_array($values) ? $values : [];
                $min = isset($range['min']) && $range['min'] !== '' ? (float) $range['min'] : null;
                $max = isset($range['max']) && $range['max'] !== '' ? (float) $range['max'] : null;

                if ($min !== null && $max !== null) {
                    $defaults['meta_query'] = $defaults['meta_query'] ?? ['relation' => 'AND'];
                    $defaults['meta_query'][] = [
                        'key' => $source_key,
                        'value' => [$min, $max],
                        'type' => 'NUMERIC',
                        'compare' => 'BETWEEN',
                    ];
                } elseif ($min !== null) {
                    $defaults['meta_query'] = $defaults['meta_query'] ?? ['relation' => 'AND'];
                    $defaults['meta_query'][] = [
                        'key' => $source_key,
                        'value' => $min,
                        'type' => 'NUMERIC',
                        'compare' => '>=',
                    ];
                } elseif ($max !== null) {
                    $defaults['meta_query'] = $defaults['meta_query'] ?? ['relation' => 'AND'];
                    $defaults['meta_query'][] = [
                        'key' => $source_key,
                        'value' => $max,
                        'type' => 'NUMERIC',
                        'compare' => '<=',
                    ];
                }
                continue;
            }

            $type = $data_source;

            // Resolve taxonomy based on data source type
            if ($type === 'product_cat') {
                $taxonomy = 'product_cat';
            } elseif ($type === 'product_tag') {
                $taxonomy = 'product_tag';
            } elseif ($type === 'attribute' && $source_key !== '') {
                if (strpos($source_key, 'pa_') === 0) {
                    $taxonomy = $source_key;
                } else {
                    $taxonomy = wc_attribute_taxonomy_name($source_key);
                }
            } elseif ($type === 'custom' && $source_key !== '') {
                $taxonomy = $source_key;
            } elseif ($source_key !== '') {
                $taxonomy = $source_key;
            } else {
                $taxonomy = '';
            }

            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $term_ids = $this->validator->filter_taxonomy_terms($taxonomy, $values);
            if (!$term_ids) {
                continue;
            }

            // Mark this taxonomy as user-filtered so context doesn't duplicate it
            $user_filtered_taxonomies[$taxonomy] = true;

            $operator = ($filter['behavior']['operator'] ?? 'OR') === 'AND' ? 'AND' : 'IN';

            if ($this->is_attribute_filter($filter, $taxonomy) && $this->lookup_table_exists()) {
                $attribute_filters[] = [
                    'taxonomy' => $taxonomy,
                    'term_ids' => $term_ids,
                    'operator' => $operator,
                ];
                continue;
            }

            $tax_clause = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_ids,
                'operator' => $operator,
            ];

            if (($taxonomy === 'product_cat' || is_taxonomy_hierarchical($taxonomy)) &&
                !empty($filter['visibility']['include_children'])) {
                $tax_clause['include_children'] = true;
            } else {
                $tax_clause['include_children'] = false;
            }

            $tax_query[] = $tax_clause;
        }

        // Add context (page category/tag) ONLY for taxonomies not already filtered by the user.
        // This allows the user's filter selection to override the page context for that taxonomy.
        $context = $request['context'] ?? [];
        $context_tax_query = $this->context_tax_query($context);
        foreach ($context_tax_query as $clause) {
            $ctx_taxonomy = $clause['taxonomy'] ?? '';
            if ($ctx_taxonomy !== '' && !isset($user_filtered_taxonomies[$ctx_taxonomy])) {
                $tax_query[] = $clause;
            }
        }

        // Apply attribute filters via lookup table
        if ($attribute_filters && $this->lookup_table_exists()) {
            $lookup_ids = $this->lookup_products_for_attributes($attribute_filters);
            $post_in = $lookup_ids;
        }

        if (is_array($post_in)) {
            $defaults['post__in'] = $post_in ? $post_in : [0];
        }

        // Only add tax_query if we have actual filter clauses (not just the relation)
        $has_filters = false;
        foreach ($tax_query as $clause) {
            if (is_array($clause) && isset($clause['taxonomy'])) {
                $has_filters = true;
                break;
            }
        }

        if ($has_filters) {
            $defaults['tax_query'] = $tax_query;
        }

        // Exclude hidden/out-of-stock products (WooCommerce visibility)
        $defaults['tax_query'] = $defaults['tax_query'] ?? ['relation' => 'AND'];
        $defaults['tax_query'][] = [
            'taxonomy' => 'product_visibility',
            'field' => 'name',
            'terms' => ['exclude-from-catalog'],
            'operator' => 'NOT IN',
        ];
        if ('yes' === get_option('woocommerce_hide_out_of_stock_items')) {
            $defaults['tax_query'][] = [
                'taxonomy' => 'product_visibility',
                'field' => 'name',
                'terms' => ['outofstock'],
                'operator' => 'NOT IN',
            ];
        }

        $defaults['posts_per_page'] = $this->sanitize_int(
            $config['global']['products_per_page'] ?? 12,
            1
        );

        $enable_sort = (bool) ($config['global']['enable_sort'] ?? true);
        $default_sort = (string) ($config['global']['default_sort'] ?? 'menu_order');
        $sort = $enable_sort ? ($request['sort'] ?? $default_sort) : $default_sort;
        $this->apply_sort($defaults, (string) $sort);

        if (!empty($request['page'])) {
            $defaults['paged'] = max(1, (int) $request['page']);
        }

        if (!empty($request['search'])) {
            $defaults['s'] = sanitize_text_field((string) $request['search']);
        }

        return apply_filters('hlm_filters_query_args', $defaults, $config, $request);
    }

    private function apply_sort(array &$args, string $sort): void
    {
        $sort = sanitize_key($sort);
        switch ($sort) {
            case 'popularity':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = 'total_sales';
                $args['order'] = 'DESC';
                break;
            case 'rating':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_wc_average_rating';
                $args['order'] = 'DESC';
                break;
            case 'price_asc':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'ASC';
                break;
            case 'price_desc':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'DESC';
                break;
            case 'date':
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
                break;
            case 'title':
                $args['orderby'] = 'title';
                $args['order'] = 'ASC';
                break;
            case 'menu_order':
                $args['orderby'] = 'menu_order title';
                $args['order'] = 'ASC';
                break;
            default:
                $args['orderby'] = 'menu_order title';
                $args['order'] = 'ASC';
                break;
        }
    }

    private function sanitize_int($value, int $min): int
    {
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        return $int;
    }

    private function is_attribute_filter(array $filter, string $taxonomy): bool
    {
        if (($filter['data_source'] ?? '') !== 'attribute') {
            return false;
        }

        $source_key = (string) ($filter['source_key'] ?? '');
        if ($source_key === '') {
            return false;
        }

        return strpos($taxonomy, 'pa_') === 0;
    }

    private function lookup_table_exists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists;
    }

    private function lookup_products_for_attributes(array $attribute_filters): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_attributes_lookup';

        $product_sets = [];
        foreach ($attribute_filters as $filter) {
            $taxonomy = $filter['taxonomy'];
            $term_ids = $filter['term_ids'];
            $operator = $filter['operator'] ?? 'IN';
            if (!$term_ids) {
                $product_sets[] = [];
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
            if ($operator === 'AND' && count($term_ids) > 1) {
                $sql = $wpdb->prepare(
                    "SELECT product_id FROM {$table} WHERE taxonomy = %s AND term_id IN ({$placeholders}) GROUP BY product_id HAVING COUNT(DISTINCT term_id) = %d",
                    array_merge([$taxonomy], $term_ids, [count($term_ids)])
                );
                $product_ids = array_map('intval', $wpdb->get_col($sql));
            } else {
                $sql = $wpdb->prepare(
                    "SELECT DISTINCT product_id FROM {$table} WHERE taxonomy = %s AND term_id IN ({$placeholders})",
                    array_merge([$taxonomy], $term_ids)
                );
                $product_ids = array_map('intval', $wpdb->get_col($sql));
            }
            $product_sets[] = $product_ids;
        }

        if (!$product_sets) {
            return [];
        }

        $intersection = array_shift($product_sets);
        foreach ($product_sets as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
            if (!$intersection) {
                break;
            }
        }

        return $intersection;
    }

    private function context_tax_query($context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $clauses = [];
        $category_id = isset($context['category_id']) ? (int) $context['category_id'] : 0;
        $tag_id = isset($context['tag_id']) ? (int) $context['tag_id'] : 0;
        $custom_taxonomy = isset($context['custom_taxonomy']) ? (string) $context['custom_taxonomy'] : '';
        $custom_term_id = isset($context['custom_term_id']) ? (int) $context['custom_term_id'] : 0;

        if ($category_id > 0) {
            $clauses[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$category_id],
                'include_children' => true,
            ];
        }

        if ($tag_id > 0) {
            $clauses[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => [$tag_id],
                'include_children' => false,
            ];
        }

        if ($custom_taxonomy !== '' && $custom_term_id > 0 && taxonomy_exists($custom_taxonomy)) {
            $clauses[] = [
                'taxonomy' => $custom_taxonomy,
                'field' => 'term_id',
                'terms' => [$custom_term_id],
                'include_children' => is_taxonomy_hierarchical($custom_taxonomy),
            ];
        }

        return $clauses;
    }
}
