<?php

namespace HLM\Filters\Support;

final class Config
{
    public const OPTION_KEY = 'hlm_filters_config';
    public const SCHEMA_VERSION = 1;

    public function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $config = $this->sanitize(is_array($stored) ? $stored : []);
        return apply_filters('hlm_filters_config', $config);
    }

    public function update(array $input): array
    {
        $sanitized = $this->sanitize($input);
        update_option(self::OPTION_KEY, $sanitized);
        return $sanitized;
    }

    public function defaults(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'global' => [
                'enable_ajax' => true,
                'enable_cache' => true,
                'cache_ttl_seconds' => 300,
                'products_per_page' => 12,
                'default_sort' => 'menu_order',
                'debug_mode' => false,
                'enable_apply_button' => false,
                'enable_counts' => true,
                'render_mode' => 'shortcode',
                'auto_hook' => 'woocommerce_before_shop_loop',
                'auto_on_shop' => true,
                'auto_on_categories' => true,
                'auto_on_tags' => true,
                'product_render_mode' => 'woocommerce',
                'elementor_template_id' => 0,
                'ui' => [
                    'accent_color' => '#0f766e',
                    'background_color' => '#ffffff',
                    'text_color' => '#0f172a',
                    'border_color' => '#e2e8f0',
                    'muted_text_color' => '#64748b',
                    'panel_gradient' => '#f8fafc',
                    'radius' => 10,
                    'spacing' => 12,
                    'density' => 'comfy',
                    'header_style' => 'pill',
                    'list_layout' => 'stacked',
                    'layout_orientation' => 'vertical',
                    'font_scale' => 100,
                    'font_family' => 'inherit',
                ],
            ],
            'filters' => [],
        ];
    }

    public function sanitize(array $input): array
    {
        $defaults = $this->defaults();
        $stored = get_option(self::OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        
        // Ensure we have a global array - WordPress Settings API might merge with existing values
        $global = [];
        if (isset($input['global']) && is_array($input['global'])) {
            $global = $input['global'];
        }
        
        $filters_input = $input['filters'] ?? ($stored['filters'] ?? []);

        $sanitized = [
            'schema_version' => self::SCHEMA_VERSION,
            'global' => [
                // For checkboxes: if key exists in input (even if "0"), use it; otherwise use default
                'enable_ajax' => array_key_exists('enable_ajax', $global) ? $this->to_bool($global['enable_ajax']) : $defaults['global']['enable_ajax'],
                'enable_cache' => array_key_exists('enable_cache', $global) ? $this->to_bool($global['enable_cache']) : $defaults['global']['enable_cache'],
                'cache_ttl_seconds' => $this->to_int($global['cache_ttl_seconds'] ?? $defaults['global']['cache_ttl_seconds'], 0),
                'products_per_page' => $this->to_int($global['products_per_page'] ?? $defaults['global']['products_per_page'], 1),
                'default_sort' => $this->sanitize_key($global['default_sort'] ?? $defaults['global']['default_sort']),
                'debug_mode' => array_key_exists('debug_mode', $global) ? $this->to_bool($global['debug_mode']) : $defaults['global']['debug_mode'],
                'enable_apply_button' => array_key_exists('enable_apply_button', $global) ? $this->to_bool($global['enable_apply_button']) : $defaults['global']['enable_apply_button'],
                'enable_counts' => array_key_exists('enable_counts', $global) ? $this->to_bool($global['enable_counts']) : $defaults['global']['enable_counts'],
                'render_mode' => $this->sanitize_enum($global['render_mode'] ?? $defaults['global']['render_mode'], ['shortcode', 'auto', 'both']),
                'auto_hook' => sanitize_text_field((string) ($global['auto_hook'] ?? $defaults['global']['auto_hook'])),
                'auto_on_shop' => array_key_exists('auto_on_shop', $global) ? $this->to_bool($global['auto_on_shop']) : $defaults['global']['auto_on_shop'],
                'auto_on_categories' => array_key_exists('auto_on_categories', $global) ? $this->to_bool($global['auto_on_categories']) : $defaults['global']['auto_on_categories'],
                'auto_on_tags' => array_key_exists('auto_on_tags', $global) ? $this->to_bool($global['auto_on_tags']) : $defaults['global']['auto_on_tags'],
                'product_render_mode' => $this->sanitize_enum($global['product_render_mode'] ?? $defaults['global']['product_render_mode'], ['woocommerce', 'elementor']),
                'elementor_template_id' => $this->to_int($global['elementor_template_id'] ?? $defaults['global']['elementor_template_id'], 0),
                'ui' => [
                    'accent_color' => $this->sanitize_color($global['ui']['accent_color'] ?? $defaults['global']['ui']['accent_color']),
                    'background_color' => $this->sanitize_color($global['ui']['background_color'] ?? $defaults['global']['ui']['background_color']),
                    'text_color' => $this->sanitize_color($global['ui']['text_color'] ?? $defaults['global']['ui']['text_color']),
                    'border_color' => $this->sanitize_color($global['ui']['border_color'] ?? $defaults['global']['ui']['border_color']),
                    'muted_text_color' => $this->sanitize_color($global['ui']['muted_text_color'] ?? $defaults['global']['ui']['muted_text_color']),
                    'panel_gradient' => $this->sanitize_color($global['ui']['panel_gradient'] ?? $defaults['global']['ui']['panel_gradient']),
                    'radius' => $this->to_int($global['ui']['radius'] ?? $defaults['global']['ui']['radius'], 0),
                    'spacing' => $this->to_int($global['ui']['spacing'] ?? $defaults['global']['ui']['spacing'], 0),
                    'density' => $this->sanitize_enum($global['ui']['density'] ?? $defaults['global']['ui']['density'], ['compact', 'comfy', 'airy']),
                    'header_style' => $this->sanitize_enum($global['ui']['header_style'] ?? $defaults['global']['ui']['header_style'], ['pill', 'underline', 'plain']),
                    'list_layout' => $this->sanitize_enum($global['ui']['list_layout'] ?? $defaults['global']['ui']['list_layout'], ['stacked', 'inline']),
                    'layout_orientation' => $this->sanitize_enum($global['ui']['layout_orientation'] ?? $defaults['global']['ui']['layout_orientation'], ['vertical', 'horizontal']),
                    'font_scale' => $this->to_int_range($global['ui']['font_scale'] ?? $defaults['global']['ui']['font_scale'], 80, 140),
                    'font_family' => sanitize_text_field((string) ($global['ui']['font_family'] ?? $defaults['global']['ui']['font_family'])),
                ],
            ],
            'filters' => $this->sanitize_filters(is_array($filters_input) ? $filters_input : []),
        ];

        return $sanitized;
    }

    private function to_bool($value): bool
    {
        return (bool) $value;
    }

    private function to_int($value, int $min): int
    {
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        return $int;
    }

    private function to_int_range($value, int $min, int $max): int
    {
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }
        return $int;
    }

    private function sanitize_key($value): string
    {
        return sanitize_key((string) $value);
    }

    private function sanitize_color($value): string
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, '#') === 0) {
            $hex = substr($value, 1);
            if (strlen($hex) === 3 || strlen($hex) === 6) {
                return '#' . strtolower($hex);
            }
        }
        return $value;
    }

    private function sanitize_filters(array $filters): array
    {
        $sanitized = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $key = $this->sanitize_key($filter['key'] ?? '');
            $id = $this->sanitize_key($filter['id'] ?? $key);
            if ($id === '' && $key !== '') {
                $id = $key;
            }
            if ($id === '') {
                continue;
            }

            $type_raw = $filter['type'] ?? '';
            $data_source = $this->sanitize_enum($filter['data_source'] ?? 'taxonomy', ['taxonomy', 'attribute', 'product_cat', 'product_tag', 'meta']);
            $source_key_raw = (string) ($filter['source_key'] ?? '');
            if ($data_source === 'meta') {
                // Meta keys like _price contain underscores — sanitize_key strips leading underscores
                $source_key = sanitize_text_field($source_key_raw);
            } else {
                $source_key = $this->sanitize_key($source_key_raw);
                if ($data_source === 'attribute' && strpos($source_key, 'pa_') === 0) {
                    $source_key = substr($source_key, 3);
                }
            }
            $ui = is_array($filter['ui'] ?? null) ? $filter['ui'] : [];

            $behavior = is_array($filter['behavior'] ?? null) ? $filter['behavior'] : [];
            $visibility = is_array($filter['visibility'] ?? null) ? $filter['visibility'] : [];
            $ui_style_raw = $ui['style'] ?? ($filter['style'] ?? '');
            if ($ui_style_raw !== '' && $ui_style_raw !== 'range' && $ui_style_raw !== 'slider') {
                $ui_style_raw = $this->sanitize_enum($ui_style_raw, ['list', 'swatch', 'dropdown']);
                $type_raw = $ui_style_raw === 'list' ? 'checkbox' : $ui_style_raw;
            }
            $type = $this->sanitize_enum($type_raw ?: 'checkbox', ['checkbox', 'dropdown', 'swatch', 'range', 'slider']);
            if ($type === 'range' || $type === 'slider') {
                $ui_style = $type;
            } else {
                $ui_style = $this->sanitize_enum($ui_style_raw ?: ($type === 'checkbox' ? 'list' : $type), ['list', 'swatch', 'dropdown']);
            }
            $ui_layout = $this->sanitize_enum($ui['layout'] ?? ($filter['layout'] ?? 'inherit'), ['inherit', 'stacked', 'inline']);

            $sanitized[] = [
                'id' => $id,
                'label' => sanitize_text_field((string) ($filter['label'] ?? $key)),
                'key' => $key,
                'type' => $type,
                'data_source' => $data_source,
                'source_key' => $source_key,
                'render_mode' => $this->sanitize_enum($filter['render_mode'] ?? 'both', ['shortcode', 'auto', 'both']),
                'behavior' => [
                    'multi_select' => $this->to_bool($behavior['multi_select'] ?? true),
                    'operator' => ($behavior['operator'] ?? 'OR') === 'AND' ? 'AND' : 'OR',
                ],
                'visibility' => [
                    'show_on_categories' => $this->sanitize_id_list($visibility['show_on_categories'] ?? []),
                    'hide_on_categories' => $this->sanitize_id_list($visibility['hide_on_categories'] ?? []),
                    'include_children' => $this->to_bool($visibility['include_children'] ?? true),
                    'show_on_tags' => $this->sanitize_id_list($visibility['show_on_tags'] ?? []),
                    'hide_on_tags' => $this->sanitize_id_list($visibility['hide_on_tags'] ?? []),
                    'include_tag_children' => $this->to_bool($visibility['include_tag_children'] ?? true),
                    'hide_empty' => $this->to_bool($visibility['hide_empty'] ?? false),
                ],
                'ui' => [
                    'style' => $ui_style,
                    'swatch_type' => $this->sanitize_enum($ui['swatch_type'] ?? ($filter['swatch_type'] ?? 'color'), ['color', 'image', 'text']),
                    'swatch_map' => $this->sanitize_swatch_map($ui['swatch_map'] ?? ($filter['swatch_map'] ?? [])),
                    'show_more_threshold' => $this->to_int($ui['show_more_threshold'] ?? ($filter['show_more_threshold'] ?? 5), 0),
                    'layout' => $ui_layout,
                    'range_step' => max(0.01, (float) ($ui['range_step'] ?? ($filter['range_step'] ?? 1))),
                    'range_prefix' => sanitize_text_field((string) ($ui['range_prefix'] ?? ($filter['range_prefix'] ?? ''))),
                    'range_suffix' => sanitize_text_field((string) ($ui['range_suffix'] ?? ($filter['range_suffix'] ?? ''))),
                ],
            ];
        }

        return array_values($sanitized);
    }

    private function sanitize_enum($value, array $allowed): string
    {
        $value = $this->sanitize_key($value);
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    private function sanitize_id_list($value): array
    {
        if (is_string($value)) {
            $parts = array_map('trim', explode(',', $value));
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            $parts = [];
        }
        $parts = array_filter($parts, static function ($part) {
            return $part !== '' && is_numeric($part);
        });
        return array_values(array_map('intval', $parts));
    }

    private function sanitize_swatch_map($value): array
    {
        if (is_array($value)) {
            $map = [];
            foreach ($value as $term_id => $swatch) {
                if (!is_numeric($term_id)) {
                    continue;
                }
                $map[(int) $term_id] = sanitize_text_field((string) $swatch);
            }
            return $map;
        }

        if (is_string($value)) {
            $lines = preg_split('/\\r\\n|\\r|\\n/', $value);
            $map = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    continue;
                }
                [$term_id, $swatch] = array_map('trim', explode(':', $line, 2));
                if ($term_id === '' || !is_numeric($term_id)) {
                    continue;
                }
                $map[(int) $term_id] = sanitize_text_field($swatch);
            }
            return $map;
        }

        return [];
    }
}
