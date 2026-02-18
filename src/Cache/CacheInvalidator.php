<?php

namespace HLM\Filters\Cache;

use HLM\Filters\Support\TermGrouper;

final class CacheInvalidator
{
    public function register(): void
    {
        add_action('save_post_product', [$this, 'clear_all']);
        add_action('deleted_post', [$this, 'clear_all']);
        add_action('created_term', [$this, 'clear_all']);
        add_action('edited_term', [$this, 'clear_all']);
        add_action('delete_term', [$this, 'clear_all']);
        add_action('update_option_hlm_filters_config', [$this, 'clear_all']);

        // Invalidate TermGrouper transients when term structure changes.
        // Priority 20 so it runs after clear_all() has already fired at default priority.
        add_action('created_term', [$this, 'invalidate_term_groups'], 20, 3);
        add_action('edited_term', [$this, 'invalidate_term_groups'], 20, 3);
        add_action('delete_term', [$this, 'invalidate_term_groups'], 20, 3);
    }

    public function clear_all(): void
    {
        update_option('hlm_filters_cache_version', time());
        do_action('hlm_filters_cache_cleared');
    }

    public function invalidate_term_groups(int $term_id, int $tt_id, string $taxonomy): void
    {
        TermGrouper::invalidate($taxonomy);
    }
}
