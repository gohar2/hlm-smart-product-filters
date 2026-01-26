<?php

namespace HLM\Filters\Data;

final class AttributeRepository
{
    public function list_attributes(): array
    {
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $taxonomies = wc_get_attribute_taxonomies();
        if (!is_array($taxonomies)) {
            return [];
        }

        $attributes = [];
        foreach ($taxonomies as $taxonomy) {
            if (empty($taxonomy->attribute_name)) {
                continue;
            }
            $attributes[] = [
                'name' => $taxonomy->attribute_label ?: $taxonomy->attribute_name,
                'slug' => $taxonomy->attribute_name,
                'taxonomy' => wc_attribute_taxonomy_name($taxonomy->attribute_name),
            ];
        }

        return $attributes;
    }

    public function get_taxonomy_for_attribute(string $attribute_slug): string
    {
        return wc_attribute_taxonomy_name($attribute_slug);
    }
}
