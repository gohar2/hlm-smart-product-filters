<?php

namespace HLM\Filters\Query;

final class FilterProcessor
{
    private FilterValidator $validator;

    /** @var array Debug data from the last build_args() call (populated when debug_mode is on). */
    public static array $last_debug = [];

    public function __construct(?FilterValidator $validator = null)
    {
        $this->validator = $validator ?: new FilterValidator();
    }

    public function build_args(array $config, array $request): array
    {
        $debug_mode = !empty($config['global']['debug_mode']);
        $debug = [];

        $defaults = [
            'post_type' => 'product',
            'post_status' => 'publish',
        ];

        $request_filters = $request['filters'] ?? [];
        $normalized = $this->validator->normalize(is_array($request_filters) ? $request_filters : []);

        if ($debug_mode) {
            $debug['raw_filters'] = $request_filters;
            $debug['normalized'] = $normalized;
            $debug['filter_steps'] = [];
        }

        $tax_query = [
            'relation' => 'AND',
        ];

        // Track which taxonomies are actively filtered by the user.
        // When a user selects terms from a filter, their selection OVERRIDES the page
        // context for that taxonomy — e.g. selecting "Electronics" on a "Clothing" category
        // page should show Electronics products, not intersect Clothing AND Electronics.
        $user_filtered_taxonomies = [];
        // For attribute filters, collect product ID sets (resolved via SQL for resilience)
        $attribute_product_sets = [];

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

            $step = [];
            if ($debug_mode) {
                $step = [
                    'filter_key' => $key,
                    'data_source' => $data_source,
                    'source_key' => $source_key,
                    'resolved_taxonomy' => $taxonomy,
                    'taxonomy_exists' => $taxonomy !== '' && taxonomy_exists($taxonomy),
                    'values' => $values,
                ];
            }

            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                if ($debug_mode) {
                    $step['skipped_reason'] = 'taxonomy empty or does not exist';
                    $debug['filter_steps'][] = $step;
                }
                continue;
            }

            $term_ids = $this->validator->filter_taxonomy_terms($taxonomy, $values);

            if ($debug_mode) {
                $step['term_ids'] = $term_ids;
            }

            if (!$term_ids) {
                if ($debug_mode) {
                    $step['skipped_reason'] = 'no matching term IDs found for slugs';
                    $debug['filter_steps'][] = $step;
                }
                continue;
            }

            // Mark this taxonomy as user-filtered so context doesn't duplicate it
            $user_filtered_taxonomies[$taxonomy] = true;

            // For attribute filters: use resilient SQL that checks both
            // wp_term_relationships (parent products) AND variation postmeta.
            // Checks both attribute_pa_{key} and attribute_{key} meta keys to handle
            // both global taxonomy attributes and custom product attributes.
            if ($type === 'attribute') {
                $attr_ids = $this->find_attribute_products($taxonomy, $source_key, $term_ids, $values);
                if ($debug_mode) {
                    $step['method'] = 'attribute_sql';
                    $step['matched_product_ids'] = $attr_ids;
                    $debug['filter_steps'][] = $step;
                }
                $attribute_product_sets[] = !empty($attr_ids) ? $attr_ids : [0];
                continue;
            }

            // Non-attribute filters: use standard tax_query
            $operator = ($filter['behavior']['operator'] ?? 'OR') === 'AND' ? 'AND' : 'IN';

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

            if ($debug_mode) {
                $step['method'] = 'tax_query';
                $step['tax_clause'] = $tax_clause;
                $debug['filter_steps'][] = $step;
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

        // For attribute filters, intersect product ID sets and use post__in
        if (!empty($attribute_product_sets)) {
            $attr_intersection = $attribute_product_sets[0];
            for ($i = 1, $count = count($attribute_product_sets); $i < $count; $i++) {
                $attr_intersection = array_values(array_intersect($attr_intersection, $attribute_product_sets[$i]));
                if (empty($attr_intersection)) {
                    $attr_intersection = [0];
                    break;
                }
            }
            if (!empty($defaults['post__in'])) {
                $attr_intersection = array_values(array_intersect($defaults['post__in'], $attr_intersection));
                if (empty($attr_intersection)) {
                    $attr_intersection = [0];
                }
            }
            $defaults['post__in'] = $attr_intersection;
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

        if ($debug_mode) {
            $debug['final_args'] = $defaults;
            self::$last_debug = $debug;
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

    /**
     * Find parent product IDs matching attribute terms.
     * Checks wp_term_relationships (standard) AND variation postmeta with both
     * attribute_pa_{key} and attribute_{key} meta keys (handles both global
     * taxonomy attributes and custom product attributes).
     */
    private function find_attribute_products(string $taxonomy, string $source_key, array $term_ids, array $slugs): array
    {
        global $wpdb;

        if (!$term_ids && !$slugs) {
            return [];
        }

        $union_parts = [];
        $params = [];

        // Method 1: Parent products with term directly assigned via wp_term_relationships
        if ($term_ids) {
            $term_ids_int = array_map('intval', $term_ids);
            $tid_ph = implode(',', array_fill(0, count($term_ids_int), '%d'));
            $union_parts[] = "SELECT tr.object_id AS product_id
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s AND tt.term_id IN ({$tid_ph})
                  AND p.post_type = 'product' AND p.post_status = 'publish'";
            $params = array_merge($params, [$taxonomy], $term_ids_int);
        }

        // Method 2: Variation postmeta — check BOTH attribute_pa_{key} and attribute_{key}
        // WooCommerce uses attribute_pa_{key} for global taxonomy attributes and
        // attribute_{key} for custom (non-taxonomy) product attributes.
        if ($slugs) {
            $slug_ph = implode(',', array_fill(0, count($slugs), '%s'));

            // Check attribute_pa_{source_key} (taxonomy attribute meta key)
            $meta_key_taxonomy = 'attribute_' . $taxonomy;
            $union_parts[] = "SELECT v.post_parent AS product_id
                FROM {$wpdb->posts} v
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = v.ID
                INNER JOIN {$wpdb->posts} parent ON v.post_parent = parent.ID
                WHERE v.post_type = 'product_variation'
                  AND v.post_status = 'publish'
                  AND pm.meta_key = %s
                  AND pm.meta_value IN ({$slug_ph})
                  AND v.post_parent > 0
                  AND parent.post_type = 'product'
                  AND parent.post_status = 'publish'";
            $params = array_merge($params, [$meta_key_taxonomy], $slugs);

            // Check attribute_{source_key} (custom attribute meta key, without pa_ prefix)
            $meta_key_custom = 'attribute_' . $source_key;
            if ($meta_key_custom !== $meta_key_taxonomy) {
                $union_parts[] = "SELECT v.post_parent AS product_id
                    FROM {$wpdb->posts} v
                    INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = v.ID
                    INNER JOIN {$wpdb->posts} parent ON v.post_parent = parent.ID
                    WHERE v.post_type = 'product_variation'
                      AND v.post_status = 'publish'
                      AND pm.meta_key = %s
                      AND pm.meta_value IN ({$slug_ph})
                      AND v.post_parent > 0
                      AND parent.post_type = 'product'
                      AND parent.post_status = 'publish'";
                $params = array_merge($params, [$meta_key_custom], $slugs);
            }
        }

        if (!$union_parts) {
            return [];
        }

        $union = implode(' UNION ', $union_parts);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT product_id FROM ({$union}) AS matched",
            $params
        );

        return array_map('intval', $wpdb->get_col($sql));
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
