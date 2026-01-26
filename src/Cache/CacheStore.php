<?php

namespace HLM\Filters\Cache;

final class CacheStore
{
    private string $group = 'hlm_filters';

    public function get(string $key)
    {
        $cache_key = $this->key($key);
        $value = wp_cache_get($cache_key, $this->group);
        if ($value !== false) {
            return $value;
        }

        return get_transient($cache_key);
    }

    public function set(string $key, $value, int $ttl): void
    {
        $cache_key = $this->key($key);
        wp_cache_set($cache_key, $value, $this->group, $ttl);
        set_transient($cache_key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $cache_key = $this->key($key);
        wp_cache_delete($cache_key, $this->group);
        delete_transient($cache_key);
    }

    private function key(string $key): string
    {
        return $this->group . '_' . $key;
    }
}
