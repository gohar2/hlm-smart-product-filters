<?php

namespace HLM\Filters\Support;

/**
 * Dynamically detects and groups WooCommerce attribute taxonomy terms that share
 * the same display name but have different slugs and term IDs.
 *
 * Only one entry appears in the filter sidebar per unique name, but selecting it
 * queries ALL products across every underlying term in that group.
 *
 * NO database writes — only reads taxonomy data and writes WordPress transients.
 * Taxonomies without duplicate-name terms are completely unaffected (all methods
 * are no-ops and return the input unchanged).
 */
final class TermGrouper
{
    /** In-request memory cache, keyed by taxonomy. Persists for the current PHP request. */
    private static array $cache = [];

    /** WordPress transient TTL in seconds (1 hour). */
    private const TRANSIENT_TTL = 3600;

    /**
     * Returns the full grouping data for a taxonomy.
     * Checks in-request static cache first, then WordPress transient, then builds from scratch.
     *
     * Returned array shape:
     * [
     *   'primary_to_secondaries'     => array<int, int[]>    — primary_id   => [secondary_id, ...]
     *   'secondary_to_primary_id'    => array<int, int>      — secondary_id => primary_id
     *   'secondary_slug_to_primary_slug' => array<string, string> — secondary_slug => primary_slug
     *   'slug_to_group_slugs'        => array<string, string[]>   — any slug => all slugs in group
     *   'id_to_group_ids'            => array<int, int[]>         — any id   => all ids in group
     * ]
     */
    public static function get_group_data(string $taxonomy): array
    {
        if (isset(self::$cache[$taxonomy])) {
            return self::$cache[$taxonomy];
        }

        $transient_key = 'hlm_term_groups_' . $taxonomy;
        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            self::$cache[$taxonomy] = $cached;
            return $cached;
        }

        $data = self::build_group_data($taxonomy);
        self::$cache[$taxonomy] = $data;
        set_transient($transient_key, $data, self::TRANSIENT_TTL);

        return $data;
    }

    /**
     * Given an array of slugs, expand each to include all sibling slugs from its group.
     * Non-grouped slugs pass through unchanged. Returns a deduplicated array.
     *
     * Example: expand_slugs('pa_color', ['black']) => ['black', 'blackdark-grey', 'vintage-black']
     */
    public static function expand_slugs(string $taxonomy, array $slugs): array
    {
        if (empty($slugs)) {
            return $slugs;
        }

        $data = self::get_group_data($taxonomy);
        if (empty($data['slug_to_group_slugs'])) {
            return $slugs;
        }

        $expanded = [];
        foreach ($slugs as $slug) {
            if (isset($data['slug_to_group_slugs'][$slug])) {
                foreach ($data['slug_to_group_slugs'][$slug] as $group_slug) {
                    $expanded[$group_slug] = true;
                }
            } else {
                $expanded[$slug] = true;
            }
        }

        return array_keys($expanded);
    }

    /**
     * Given an array of term IDs, expand each to include all sibling IDs from its group.
     * Non-grouped IDs pass through unchanged. Returns a deduplicated array.
     *
     * Example: expand_term_ids('pa_color', [158]) => [158, 6835, 6657]
     */
    public static function expand_term_ids(string $taxonomy, array $term_ids): array
    {
        if (empty($term_ids)) {
            return $term_ids;
        }

        $data = self::get_group_data($taxonomy);
        if (empty($data['id_to_group_ids'])) {
            return $term_ids;
        }

        $expanded = [];
        foreach ($term_ids as $id) {
            $id = (int) $id;
            if (isset($data['id_to_group_ids'][$id])) {
                foreach ($data['id_to_group_ids'][$id] as $group_id) {
                    $expanded[$group_id] = true;
                }
            } else {
                $expanded[$id] = true;
            }
        }

        return array_keys($expanded);
    }

    /**
     * Given a term_id => count array, sum secondary term counts into their primary.
     * Secondary keys are removed from the result. Ungrouped terms are untouched.
     *
     * Example: {158: 500, 6835: 100, 6657: 200, 63909: 5} => {158: 800, 63909: 5}
     */
    public static function merge_counts(string $taxonomy, array $counts): array
    {
        if (empty($counts)) {
            return $counts;
        }

        $data = self::get_group_data($taxonomy);
        if (empty($data['secondary_to_primary_id'])) {
            return $counts;
        }

        $merged = [];
        foreach ($counts as $term_id => $count) {
            $term_id = (int) $term_id;
            if (isset($data['secondary_to_primary_id'][$term_id])) {
                // Secondary term — accumulate its count into the primary
                $primary_id = $data['secondary_to_primary_id'][$term_id];
                $merged[$primary_id] = ($merged[$primary_id] ?? 0) + (int) $count;
            } else {
                // Primary or ungrouped term — keep as-is, but still accumulate in case
                // it was already added via a secondary in an earlier iteration
                $merged[$term_id] = ($merged[$term_id] ?? 0) + (int) $count;
            }
        }

        return $merged;
    }

    /**
     * Given an array of WP_Term objects, remove secondary term objects and keep only primaries.
     * Preserves the order of remaining terms. Re-indexes the array.
     * Non-WP_Term elements are kept unchanged.
     */
    public static function dedupe_terms(string $taxonomy, array $terms): array
    {
        if (empty($terms)) {
            return $terms;
        }

        $data = self::get_group_data($taxonomy);
        if (empty($data['secondary_to_primary_id'])) {
            return $terms;
        }

        $filtered = [];
        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) {
                $filtered[] = $term;
                continue;
            }
            // Keep only if this term is NOT a secondary (i.e. it's primary or ungrouped)
            if (!isset($data['secondary_to_primary_id'][(int) $term->term_id])) {
                $filtered[] = $term;
            }
        }

        return array_values($filtered);
    }

    /**
     * Quick check: does this taxonomy have any duplicate-name term groups?
     * Use this to skip grouping logic entirely for clean taxonomies.
     */
    public static function is_grouped_taxonomy(string $taxonomy): bool
    {
        $data = self::get_group_data($taxonomy);
        return !empty($data['primary_to_secondaries']);
    }

    /**
     * Invalidate the cached grouping data for a specific taxonomy.
     * Called when terms are created, edited, or deleted in that taxonomy.
     */
    public static function invalidate(string $taxonomy): void
    {
        unset(self::$cache[$taxonomy]);
        delete_transient('hlm_term_groups_' . $taxonomy);
    }

    // -------------------------------------------------------------------------
    // Private: build grouping maps by scanning taxonomy terms at runtime
    // -------------------------------------------------------------------------

    /**
     * Scan a taxonomy for terms sharing the same display name (case-insensitive, trimmed).
     * For each duplicate group, elect the term with the highest object count as primary.
     * Returns empty maps if no duplicates exist — zero cost for clean taxonomies.
     */
    private static function build_group_data(string $taxonomy): array
    {
        $empty = [
            'primary_to_secondaries'         => [],
            'secondary_to_primary_id'        => [],
            'secondary_slug_to_primary_slug' => [],
            'slug_to_group_slugs'            => [],
            'id_to_group_ids'                => [],
        ];

        if (!taxonomy_exists($taxonomy)) {
            return $empty;
        }

        $all_terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($all_terms) || empty($all_terms)) {
            return $empty;
        }

        // 1. Group terms by normalised display name
        $name_groups = [];
        foreach ($all_terms as $term) {
            $key = strtolower(trim($term->name));
            $name_groups[$key][] = $term;
        }

        // 2. Keep only groups with more than one term
        $duplicate_groups = array_filter(
            $name_groups,
            static function (array $group): bool {
                return count($group) > 1;
            }
        );

        if (empty($duplicate_groups)) {
            return $empty;
        }

        // 3. Build lookup maps
        $primary_to_secondaries         = [];
        $secondary_to_primary_id        = [];
        $secondary_slug_to_primary_slug = [];
        $slug_to_group_slugs            = [];
        $id_to_group_ids                = [];

        foreach ($duplicate_groups as $group_terms) {
            // Elect primary: term with highest assigned-object count
            $best_term  = null;
            $best_count = -1;

            foreach ($group_terms as $term) {
                $objects = get_objects_in_term((int) $term->term_id, $taxonomy);
                $count   = is_array($objects) ? count($objects) : 0;
                if ($count > $best_count) {
                    $best_count = $count;
                    $best_term  = $term;
                }
            }

            if ($best_term === null) {
                continue;
            }

            $primary_id   = (int) $best_term->term_id;
            $primary_slug = $best_term->slug;

            // Collect all IDs and slugs in this group
            $all_ids   = [];
            $all_slugs = [];
            foreach ($group_terms as $term) {
                $all_ids[]   = (int) $term->term_id;
                $all_slugs[] = $term->slug;
            }

            // Every member maps to the full group (used by expand_slugs/expand_term_ids)
            foreach ($all_slugs as $slug) {
                $slug_to_group_slugs[$slug] = $all_slugs;
            }
            foreach ($all_ids as $id) {
                $id_to_group_ids[$id] = $all_ids;
            }

            // Register secondary relationships
            foreach ($group_terms as $term) {
                $tid = (int) $term->term_id;
                if ($tid === $primary_id) {
                    continue;
                }
                $primary_to_secondaries[$primary_id][]        = $tid;
                $secondary_to_primary_id[$tid]                = $primary_id;
                $secondary_slug_to_primary_slug[$term->slug]  = $primary_slug;
            }
        }

        return [
            'primary_to_secondaries'         => $primary_to_secondaries,
            'secondary_to_primary_id'        => $secondary_to_primary_id,
            'secondary_slug_to_primary_slug' => $secondary_slug_to_primary_slug,
            'slug_to_group_slugs'            => $slug_to_group_slugs,
            'id_to_group_ids'                => $id_to_group_ids,
        ];
    }
}
