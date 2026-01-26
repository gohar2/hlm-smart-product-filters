<?php

namespace HLM\Filters\Query;

final class FilterValidator
{
    public function normalize(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = $this->split($value);
                continue;
            }
            if (is_array($value)) {
                $normalized[$key] = array_values(array_filter(array_map('sanitize_text_field', $value)));
                continue;
            }
            $normalized[$key] = [];
        }

        return $normalized;
    }

    public function filter_taxonomy_terms(string $taxonomy, array $values): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $values = array_values(array_filter(array_map('sanitize_text_field', $values)));
        if (!$values) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'slug' => $values,
            'fields' => 'ids',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map('intval', $terms);
    }

    private function split(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static function ($part) {
            return $part !== '';
        });
        return array_values(array_map('sanitize_text_field', $parts));
    }
}
