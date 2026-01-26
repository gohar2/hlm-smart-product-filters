<?php

namespace HLM\Filters\Query;

use WP_Query;

final class QueryMerger
{
    public function merge(WP_Query $query, array $args): void
    {
        foreach ($args as $key => $value) {
            if ($key === 'post__in' && empty($value)) {
                continue;
            }
            $query->set($key, $value);
        }
    }
}
