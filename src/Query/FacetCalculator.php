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

            // Get all terms in the taxonomy (not filtered by object_ids yet)
            // We need all terms to properly calculate counts with children
            $all_terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);

            if (is_wp_error($all_terms)) {
                $result[$key] = [];
                if ($enable_cache) {
                    $this->cache->set($cache_key, $result[$key], $cache_ttl);
                }
                continue;
            }

            $include_children = !empty($filter['visibility']['include_children']);
            $is_hierarchical = ($taxonomy === 'product_cat' || is_taxonomy_hierarchical($taxonomy));

            $counts = [];
            foreach ($all_terms as $term) {
                $term_id = (int) $term->term_id;
                
                // Create a request that includes this term in the filter
                // This simulates what happens when the user selects this term
                $test_request = $request_without;
                $test_request['filters'] = $test_request['filters'] ?? [];
                $test_request['filters'][$key] = [$term->slug]; // Use slug to match how filters are normalized
                
                // Build query args with this term included - this uses the exact same logic as the actual filter
                $test_args = $this->processor->build_args($config, $test_request);
                $test_args['fields'] = 'ids';
                $test_args['posts_per_page'] = -1;
                $test_args['no_found_rows'] = true;
                $test_args['update_post_meta_cache'] = false;
                $test_args['update_post_term_cache'] = false;
                
                $test_query = new WP_Query($test_args);
                $count = count($test_query->posts);

                // Only include terms that have products
                if ($count > 0) {
                    $counts[$term_id] = $count;
                }
            }

            $result[$key] = $counts;
            if ($enable_cache) {
                $this->cache->set($cache_key, $result[$key], $cache_ttl);
            }
        }

        return apply_filters('hlm_filters_facet_counts', $result, $config, $request);
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
