<?php

namespace HLM\Filters\Cache;

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
    }

    public function clear_all(): void
    {
        update_option('hlm_filters_cache_version', time());
        do_action('hlm_filters_cache_cleared');
    }
}
