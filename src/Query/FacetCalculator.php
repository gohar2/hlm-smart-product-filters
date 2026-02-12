<?php

namespace HLM\Filters\Query;

use HLM\Filters\Cache\CacheStore;
use WP_Query;

final class FacetCalculator
{
    private FilterProcessor $processor;
    private ?bool $lookup_exists = null;
    private CacheStore $cache;

    public function __construct(?FilterProcessor $processor = null)
    {
        $this->processor = $processor ?: new FilterProcessor();
        $this->cache = new CacheStore();
    }

    public function calculate(array $config, array $request): array
    {
        $filters = $config['filters'] ?? [];
        if (!is_array($filters)) {
            return [];
        }

        $enable_cache = (bool) ($config['global']['enable_cache'] ?? true);
        $cache_ttl = (int) ($config['global']['cache_ttl_seconds'] ?? 300);

        $result = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $key = (string) ($filter['key'] ?? '');
            if ($key === '') {
                continue;
            }

            // Handle range/meta filters (e.g. price) — no taxonomy involved
            $data_source = $filter['data_source'] ?? 'taxonomy';
            $filter_type = $filter['type'] ?? '';
            if ($data_source === 'meta' && ($filter_type === 'range' || $filter_type === 'slider')) {
                $meta_key = (string) ($filter['source_key'] ?? '');
                if ($meta_key === '') {
                    continue;
                }
                $cache_key = $this->cache_key($config, $request, $key);
                if ($enable_cache) {
                    $cached = $this->cache->get($cache_key);
                    if (is_array($cached)) {
                        $result[$key] = $cached;
                        continue;
                    }
                }
                // Build query WITHOUT this filter to get base product set
                $request_without = $request;
                if (isset($request_without['filters'][$key])) {
                    $filters_copy = $request_without['filters'];
                    unset($filters_copy[$key]);
                    $request_without['filters'] = $filters_copy;
                }
                $args = $this->processor->build_args($config, $request_without);
                $args['fields'] = 'ids';
                $args['posts_per_page'] = -1;
                $args['no_found_rows'] = true;
                $range_query = new WP_Query($args);
                $range_ids = $range_query->posts;

                $bounds = $this->range_bounds($meta_key, $range_ids);
                $result[$key] = $bounds;
                if ($enable_cache) {
                    $this->cache->set($cache_key, $bounds, $cache_ttl);
                }
                continue;
            }

            $taxonomy = $this->resolve_taxonomy($filter);
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $cache_key = $this->cache_key($config, $request, $key);
            if ($enable_cache) {
                $cached = $this->cache->get($cache_key);
                if (is_array($cached)) {
                    $result[$key] = $cached;
                    continue;
                }
            }

            $request_without = $request;
            if (isset($request_without['filters'][$key])) {
                $filters_copy = $request_without['filters'];
                unset($filters_copy[$key]);
                $request_without['filters'] = $filters_copy;
            }

            $args = $this->processor->build_args($config, $request_without);
            $args['fields'] = 'ids';
            $args['posts_per_page'] = -1;
            $args['no_found_rows'] = true;
            $args['update_post_meta_cache'] = false;
            $args['update_post_term_cache'] = false;

            $query = new WP_Query($args);
            $object_ids = $query->posts;

            if (!$object_ids) {
                $result[$key] = [];
                if ($enable_cache) {
                    $this->cache->set($cache_key, $result[$key], $cache_ttl);
                }
                continue;
            }

            if ($this->is_attribute_filter($filter, $taxonomy) && $this->lookup_table_exists()) {
                $result[$key] = $this->lookup_counts($taxonomy, $object_ids);
                if ($enable_cache) {
                    $this->cache->set($cache_key, $result[$key], $cache_ttl);
                }
                continue;
            }

            // Efficient SQL GROUP BY for taxonomy filters (categories, tags, custom taxonomies)
            $include_children = !empty($filter['visibility']['include_children']);
            $is_hierarchical = is_taxonomy_hierarchical($taxonomy);

            $counts = $this->taxonomy_counts(
                $taxonomy,
                $object_ids,
                $include_children && $is_hierarchical
            );

            $result[$key] = $counts;
            if ($enable_cache) {
                $this->cache->set($cache_key, $result[$key], $cache_ttl);
            }
        }

        return apply_filters('hlm_filters_facet_counts', $result, $config, $request);
    }

    /**
     * Count products per term using a single SQL GROUP BY query.
     * For hierarchical taxonomies, rolls up counts to ancestor terms.
     */
    private function taxonomy_counts(string $taxonomy, array $product_ids, bool $rollup_hierarchy): array
    {
        global $wpdb;

        if (!$product_ids) {
            return [];
        }

        $product_ids = array_map('intval', $product_ids);
        $id_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        // Single query: count products per term
        $sql = $wpdb->prepare(
            "SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS cnt
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.taxonomy = %s
               AND tr.object_id IN ({$id_placeholders})
             GROUP BY tt.term_id",
            array_merge([$taxonomy], $product_ids)
        );

        $rows = $wpdb->get_results($sql);
        $direct_counts = [];
        foreach ($rows as $row) {
            $direct_counts[(int) $row->term_id] = (int) $row->cnt;
        }

        if (!$rollup_hierarchy || empty($direct_counts)) {
            return $direct_counts;
        }

        return $this->rollup_hierarchical_counts($taxonomy, $product_ids, $direct_counts);
    }

    /**
     * For hierarchical taxonomies, propagate product counts up to ancestor terms.
     * A parent's count = number of unique products assigned to it OR any descendant.
     * Uses a single SQL query + PHP computation instead of per-term queries.
     */
    private function rollup_hierarchical_counts(string $taxonomy, array $product_ids, array $direct_counts): array
    {
        global $wpdb;

        $product_ids = array_map('intval', $product_ids);
        $id_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        // Fetch all (product_id, term_id) pairs in one query
        $sql = $wpdb->prepare(
            "SELECT tr.object_id AS product_id, tt.term_id
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.taxonomy = %s
               AND tr.object_id IN ({$id_placeholders})",
            array_merge([$taxonomy], $product_ids)
        );
        $pairs = $wpdb->get_results($sql);

        if (!$pairs) {
            return $direct_counts;
        }

        // Build term_id => set of product_ids (using product_id as key for fast union)
        $term_products = [];
        foreach ($pairs as $pair) {
            $tid = (int) $pair->term_id;
            $pid = (int) $pair->product_id;
            $term_products[$tid][$pid] = true;
        }

        // Get parent map: term_id => parent_id
        $all_terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($all_terms) || empty($all_terms)) {
            return $direct_counts;
        }

        $parent_map = [];
        foreach ($all_terms as $term) {
            $parent_map[(int) $term->term_id] = (int) $term->parent;
        }

        // Propagate each term's products up to all its ancestors
        // This correctly handles multi-level hierarchies and avoids double-counting
        foreach ($term_products as $term_id => $products) {
            $current = (int) $term_id;
            while (isset($parent_map[$current]) && $parent_map[$current] > 0) {
                $parent = $parent_map[$current];
                if (!isset($term_products[$parent])) {
                    $term_products[$parent] = [];
                }
                // Array union: existing keys preserved, new keys added (set union)
                $term_products[$parent] += $products;
                $current = $parent;
            }
        }

        // Convert to counts
        $counts = [];
        foreach ($term_products as $term_id => $products) {
            $c = count($products);
            if ($c > 0) {
                $counts[(int) $term_id] = $c;
            }
        }

        return $counts;
    }

    /**
     * Get min/max values from post meta for range filters (e.g. price).
     */
    private function range_bounds(string $meta_key, array $product_ids): array
    {
        global $wpdb;

        if (!$product_ids) {
            return ['min' => 0, 'max' => 0];
        }

        $product_ids = array_map('intval', $product_ids);
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $sql = $wpdb->prepare(
            "SELECT MIN(CAST(pm.meta_value AS DECIMAL(10,2))) AS min_val,
                    MAX(CAST(pm.meta_value AS DECIMAL(10,2))) AS max_val
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = %s
               AND pm.post_id IN ({$placeholders})
               AND pm.meta_value != ''
               AND pm.meta_value REGEXP '^[0-9]'",
            array_merge([$meta_key], $product_ids)
        );

        $row = $wpdb->get_row($sql);
        return [
            'min' => $row && $row->min_val !== null ? (float) $row->min_val : 0,
            'max' => $row && $row->max_val !== null ? (float) $row->max_val : 0,
        ];
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
            return $source !== '' ? wc_attribute_taxonomy_name($source) : '';
        }
        return $source;
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
        if ($this->lookup_exists !== null) {
            return $this->lookup_exists;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $this->lookup_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $this->lookup_exists;
    }

    private function lookup_counts(string $taxonomy, array $object_ids): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_attributes_lookup';
        if (!$object_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($object_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT term_id, COUNT(DISTINCT product_id) as total FROM {$table} WHERE taxonomy = %s AND product_id IN ({$placeholders}) GROUP BY term_id",
            array_merge([$taxonomy], $object_ids)
        );
        $rows = $wpdb->get_results($sql);
        if (!$rows) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->term_id] = (int) $row->total;
        }

        return $counts;
    }

    private function cache_key(array $config, array $request, string $filter_key): string
    {
        $version = (int) get_option('hlm_filters_cache_version', 1);
        
        // Include filter-specific settings in cache key to ensure counts are recalculated when settings change
        $filter_config = null;
        foreach (($config['filters'] ?? []) as $filter) {
            if (is_array($filter) && ($filter['key'] ?? '') === $filter_key) {
                $filter_config = [
                    'include_children' => !empty($filter['visibility']['include_children'] ?? false),
                ];
                break;
            }
        }
        
        $payload = [
            'schema' => $config['schema_version'] ?? 1,
            'version' => $version,
            'filter' => $filter_key,
            'filter_config' => $filter_config,
            'filters' => $request['filters'] ?? [],
            'search' => $request['search'] ?? '',
            'sort' => $request['sort'] ?? '',
            'context' => $request['context'] ?? [],
        ];
        return md5(wp_json_encode($payload));
    }
}
