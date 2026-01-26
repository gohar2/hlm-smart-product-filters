<?php

namespace HLM\Filters\Data;

final class TaxonomyRepository
{
    public function list_categories(): array
    {
        return $this->list_terms('product_cat', true);
    }

    public function list_tags(): array
    {
        return $this->list_terms('product_tag', false);
    }

    public function list_terms(string $taxonomy, bool $hierarchical): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $result = [];
        foreach ($terms as $term) {
            $result[] = [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $hierarchical ? (int) $term->parent : 0,
                'count' => (int) $term->count,
            ];
        }

        return $result;
    }
}
