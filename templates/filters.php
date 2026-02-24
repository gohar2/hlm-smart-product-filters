<?php
if (!defined('ABSPATH')) {
    exit;
}

$search = isset($search) ? sanitize_text_field((string) $search) : (isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '');
$orderby = isset($orderby) ? sanitize_key((string) $orderby) : (isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : '');
$category_id = (int) ($context['category_id'] ?? 0);
$tag_id = (int) ($context['tag_id'] ?? 0);
$custom_taxonomy = (string) ($context['custom_taxonomy'] ?? '');
$custom_term_id = (int) ($context['custom_term_id'] ?? 0);
$render_context = isset($render_context) ? (string) $render_context : 'shortcode';
$ui_density = isset($ui_density) ? (string) $ui_density : 'comfy';
$ui_header_style = isset($ui_header_style) ? (string) $ui_header_style : 'pill';
?>
<div class="hlm-filters-wrap" role="region" aria-label="<?php echo esc_attr__('Product filters', 'hlm-smart-product-filters'); ?>" data-density="<?php echo esc_attr($ui_density); ?>" data-header-style="<?php echo esc_attr($ui_header_style); ?>">
<div class="hlm-filters-loading" role="status" aria-live="polite" aria-hidden="true">
    <div class="hlm-filters-loading-inner" role="alert" aria-busy="true">
        <svg class="hlm-loader" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="hlm-loader-gradient" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#0f766e"/>
                    <stop offset="100%" stop-color="#14b8a6"/>
                </linearGradient>
            </defs>
            <circle class="hlm-loader-track" cx="60" cy="60" r="44" />
            <circle class="hlm-loader-ring" cx="60" cy="60" r="44" />
        </svg>
        <div class="hlm-loader-text">
            <strong><?php echo esc_html__('Updating results', 'hlm-smart-product-filters'); ?></strong>
            <span><?php echo esc_html__('Applying filters…', 'hlm-smart-product-filters'); ?></span>
        </div>
    </div>
</div>
<form class="hlm-filters" method="get" action="<?php echo esc_url($current_url); ?>" data-results=".products" data-pagination=".woocommerce-pagination" data-result-count=".woocommerce-result-count" aria-live="polite">
    <?php if ($search !== '') : ?>
        <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
    <?php endif; ?>
    <input type="hidden" name="hlm_context[category_id]" value="<?php echo esc_attr((string) $category_id); ?>">
    <input type="hidden" name="hlm_context[tag_id]" value="<?php echo esc_attr((string) $tag_id); ?>">
    <?php if ($custom_taxonomy !== '' && $custom_term_id > 0) : ?>
        <input type="hidden" name="hlm_context[custom_taxonomy]" value="<?php echo esc_attr($custom_taxonomy); ?>">
        <input type="hidden" name="hlm_context[custom_term_id]" value="<?php echo esc_attr((string) $custom_term_id); ?>">
    <?php endif; ?>
    <input type="hidden" name="hlm_render_context" value="<?php echo esc_attr($render_context); ?>">

    <?php if (!empty($enable_sort)) : ?>
    <div class="hlm-filter-sort">
        <label>
            <?php echo esc_html__('Sort by', 'hlm-smart-product-filters'); ?>
            <select name="orderby">
                <option value="menu_order" <?php selected($orderby, 'menu_order'); ?>><?php echo esc_html__('Default', 'hlm-smart-product-filters'); ?></option>
                <option value="popularity" <?php selected($orderby, 'popularity'); ?>><?php echo esc_html__('Popularity', 'hlm-smart-product-filters'); ?></option>
                <option value="rating" <?php selected($orderby, 'rating'); ?>><?php echo esc_html__('Rating', 'hlm-smart-product-filters'); ?></option>
                <option value="date" <?php selected($orderby, 'date'); ?>><?php echo esc_html__('Newest', 'hlm-smart-product-filters'); ?></option>
                <option value="price_asc" <?php selected($orderby, 'price_asc'); ?>><?php echo esc_html__('Price (low to high)', 'hlm-smart-product-filters'); ?></option>
                <option value="price_desc" <?php selected($orderby, 'price_desc'); ?>><?php echo esc_html__('Price (high to low)', 'hlm-smart-product-filters'); ?></option>
                <option value="title" <?php selected($orderby, 'title'); ?>><?php echo esc_html__('Title', 'hlm-smart-product-filters'); ?></option>
            </select>
        </label>
    </div>
    <?php endif; ?>

    <?php if (!$filters) : ?>
        <p><?php echo esc_html__('No filters configured.', 'hlm-smart-product-filters'); ?></p>
    <?php else : ?>
        <?php foreach ($filters as $filter) : ?>
            <?php
            $style = $filter['style'] ?? $filter['type'];
            $threshold = (int) ($filter['show_more_threshold'] ?? 0);
            $term_count = is_array($filter['terms']) ? count($filter['terms']) : 0;
            $layout = $filter['layout'] ?? 'stacked';
            ?>
            <?php $list_id = 'hlm-filter-' . esc_attr($filter['key']); ?>
            <fieldset class="hlm-filter" aria-labelledby="<?php echo esc_attr($list_id . '-label'); ?>" data-density="<?php echo esc_attr($ui_density); ?>" data-header-style="<?php echo esc_attr($ui_header_style); ?>">
                <legend id="<?php echo esc_attr($list_id . '-label'); ?>"><?php echo esc_html($filter['label']); ?></legend>
                <?php if ($style === 'range') : ?>
                    <?php
                    $range_min = $filter['range_min'] ?? 0;
                    $range_max = $filter['range_max'] ?? 0;
                    $sel_min = $filter['selected_min'] ?? null;
                    $sel_max = $filter['selected_max'] ?? null;
                    $step = $filter['range_step'] ?? 1;
                    $prefix = $filter['range_prefix'] ?? '';
                    $suffix = $filter['range_suffix'] ?? '';
                    ?>
                    <?php if ($range_min < $range_max) : ?>
                    <div class="hlm-range-filter">
                        <div class="hlm-range-inputs">
                            <label class="hlm-range-label">
                                <?php if ($prefix !== '') : ?>
                                    <span class="hlm-range-prefix"><?php echo esc_html($prefix); ?></span>
                                <?php endif; ?>
                                <input type="number"
                                    name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][min]"
                                    value="<?php echo esc_attr($sel_min !== null ? $sel_min : ''); ?>"
                                    min="<?php echo esc_attr(max(0, $range_min)); ?>"
                                    max="<?php echo esc_attr($range_max); ?>"
                                    step="<?php echo esc_attr($step); ?>"
                                    placeholder="<?php echo esc_attr($range_min); ?>"
                                    aria-label="<?php echo esc_attr(sprintf(__('Minimum %s', 'hlm-smart-product-filters'), $filter['label'])); ?>">
                                <?php if ($suffix !== '') : ?>
                                    <span class="hlm-range-suffix"><?php echo esc_html($suffix); ?></span>
                                <?php endif; ?>
                            </label>
                            <span class="hlm-range-separator">&mdash;</span>
                            <label class="hlm-range-label">
                                <?php if ($prefix !== '') : ?>
                                    <span class="hlm-range-prefix"><?php echo esc_html($prefix); ?></span>
                                <?php endif; ?>
                                <input type="number"
                                    name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][max]"
                                    value="<?php echo esc_attr($sel_max !== null ? $sel_max : ''); ?>"
                                    min="<?php echo esc_attr(max(0, $range_min)); ?>"
                                    max="<?php echo esc_attr($range_max); ?>"
                                    step="<?php echo esc_attr($step); ?>"
                                    placeholder="<?php echo esc_attr($range_max); ?>"
                                    aria-label="<?php echo esc_attr(sprintf(__('Maximum %s', 'hlm-smart-product-filters'), $filter['label'])); ?>">
                                <?php if ($suffix !== '') : ?>
                                    <span class="hlm-range-suffix"><?php echo esc_html($suffix); ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                    <?php else : ?>
                        <p class="hlm-empty"><?php echo esc_html__('No price data available.', 'hlm-smart-product-filters'); ?></p>
                    <?php endif; ?>
                <?php elseif ($style === 'slider') : ?>
                    <?php
                    $range_min = $filter['range_min'] ?? 0;
                    $range_max = $filter['range_max'] ?? 0;
                    $sel_min = $filter['selected_min'] ?? null;
                    $sel_max = $filter['selected_max'] ?? null;
                    $step = $filter['range_step'] ?? 1;
                    $prefix = $filter['range_prefix'] ?? '';
                    $suffix = $filter['range_suffix'] ?? '';
                    $cur_min = $sel_min !== null ? $sel_min : $range_min;
                    $cur_max = $sel_max !== null ? $sel_max : $range_max;
                    ?>
                    <?php if ($range_min < $range_max) : ?>
                    <div class="hlm-slider-filter" data-min="<?php echo esc_attr($range_min); ?>" data-max="<?php echo esc_attr($range_max); ?>" data-step="<?php echo esc_attr($step); ?>">
                        <div class="hlm-slider-values">
                            <span class="hlm-slider-value-min">
                                <?php if ($prefix !== '') : ?><span class="hlm-range-prefix"><?php echo esc_html($prefix); ?></span><?php endif; ?>
                                <span class="hlm-slider-display-min"><?php echo esc_html($cur_min); ?></span>
                                <?php if ($suffix !== '') : ?><span class="hlm-range-suffix"><?php echo esc_html($suffix); ?></span><?php endif; ?>
                            </span>
                            <span class="hlm-range-separator">&mdash;</span>
                            <span class="hlm-slider-value-max">
                                <?php if ($prefix !== '') : ?><span class="hlm-range-prefix"><?php echo esc_html($prefix); ?></span><?php endif; ?>
                                <span class="hlm-slider-display-max"><?php echo esc_html($cur_max); ?></span>
                                <?php if ($suffix !== '') : ?><span class="hlm-range-suffix"><?php echo esc_html($suffix); ?></span><?php endif; ?>
                            </span>
                        </div>
                        <div class="hlm-slider-track">
                            <input type="range" class="hlm-slider-input hlm-slider-min"
                                min="<?php echo esc_attr(max(0, $range_min)); ?>"
                                max="<?php echo esc_attr($range_max); ?>"
                                step="<?php echo esc_attr($step); ?>"
                                value="<?php echo esc_attr($cur_min); ?>"
                                aria-label="<?php echo esc_attr(sprintf(__('Minimum %s', 'hlm-smart-product-filters'), $filter['label'])); ?>">
                            <input type="range" class="hlm-slider-input hlm-slider-max"
                                min="<?php echo esc_attr(max(0, $range_min)); ?>"
                                max="<?php echo esc_attr($range_max); ?>"
                                step="<?php echo esc_attr($step); ?>"
                                value="<?php echo esc_attr($cur_max); ?>"
                                aria-label="<?php echo esc_attr(sprintf(__('Maximum %s', 'hlm-smart-product-filters'), $filter['label'])); ?>">
                        </div>
                        <input type="hidden" name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][min]" value="<?php echo esc_attr($sel_min !== null ? $sel_min : ''); ?>" class="hlm-slider-hidden-min">
                        <input type="hidden" name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][max]" value="<?php echo esc_attr($sel_max !== null ? $sel_max : ''); ?>" class="hlm-slider-hidden-max">
                    </div>
                    <?php else : ?>
                        <p class="hlm-empty"><?php echo esc_html__('No price data available.', 'hlm-smart-product-filters'); ?></p>
                    <?php endif; ?>
                <?php elseif ($style === 'dropdown') : ?>
                    <select name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][]" aria-label="<?php echo esc_attr($filter['label']); ?>" <?php echo !empty($filter['multi_select']) ? 'multiple' : ''; ?>>
                        <option value=""><?php echo esc_html__('Any', 'hlm-smart-product-filters'); ?></option>
                        <?php foreach ($filter['terms'] as $term) : ?>
                            <?php $count = $filter['counts'][$term->term_id] ?? null; ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected(in_array($term->slug, $filter['selected'], true)); ?>>
                                <?php echo esc_html($term->name); ?>
                                <?php if (!empty($enable_counts) && $count !== null) : ?>
                                    (<?php echo esc_html($count); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$filter['terms']) : ?>
                        <p class="hlm-empty"><?php echo esc_html__('No options available.', 'hlm-smart-product-filters'); ?></p>
                    <?php endif; ?>
                <?php elseif ($style === 'swatch') : ?>
                    <?php $swatch_type = $filter['swatch_type'] ?? 'color'; ?>
                    <div class="hlm-swatch-list hlm-swatch-list--<?php echo esc_attr($swatch_type); ?>" id="<?php echo esc_attr($list_id); ?>">
                        <?php foreach ($filter['terms'] as $index => $term) : ?>
                            <?php
                            $count  = $filter['counts'][$term->term_id] ?? null;
                            $swatch = $filter['swatch_map'][$term->term_id] ?? '';
                            if ($swatch_type !== 'text') {
                                if ($swatch === '') {
                                    $swatch = (string) get_term_meta($term->term_id, 'color', true);
                                }
                                if ($swatch === '') {
                                    $swatch = (string) get_term_meta($term->term_id, 'swatch_color', true);
                                }
                            }
                            $hidden = $threshold > 0 && $index >= $threshold ? ' data-hlm-hidden="true"' : '';
                            $is_checked = in_array($term->slug, $filter['selected'], true);
                            ?>
                            <label class="hlm-swatch hlm-swatch--<?php echo esc_attr($swatch_type); ?><?php echo $is_checked ? ' is-active' : ''; ?>"<?php echo $hidden; ?> title="<?php echo esc_attr($term->name); ?>">
                                <input type="checkbox" name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][]" value="<?php echo esc_attr($term->slug); ?>" aria-label="<?php echo esc_attr($term->name); ?>" <?php checked($is_checked); ?>>
                                <?php if ($swatch_type === 'image' && $swatch !== '') : ?>
                                    <span class="hlm-swatch-chip hlm-swatch-image" style="background-image:url('<?php echo esc_url($swatch); ?>');"></span>
                                <?php elseif ($swatch_type === 'text') : ?>
                                    <span class="hlm-swatch-chip hlm-swatch-text"><?php echo esc_html($term->name); ?></span>
                                <?php else : ?>
                                    <span class="hlm-swatch-chip" style="background: <?php echo esc_attr($swatch ? $swatch : '#ccc'); ?>"></span>
                                <?php endif; ?>
                                <?php if ($swatch_type === 'color' || $swatch_type === 'image') : ?>
                                    <span class="hlm-swatch-label"><?php echo esc_html($term->name); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($enable_counts) && $count !== null) : ?>
                                    <span class="hlm-count" aria-hidden="true">(<?php echo esc_html($count); ?>)</span>
                                    <span class="hlm-sr-only"><?php echo esc_html(sprintf(__('(%d items)', 'hlm-smart-product-filters'), $count)); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$filter['terms']) : ?>
                        <p class="hlm-empty"><?php echo esc_html__('No options available.', 'hlm-smart-product-filters'); ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <ul id="<?php echo esc_attr($list_id); ?>" class="hlm-filter-list<?php echo $layout === 'inline' ? ' is-inline' : ''; ?>">
                        <?php foreach ($filter['terms'] as $index => $term) : ?>
                            <?php $count = $filter['counts'][$term->term_id] ?? null; ?>
                            <?php $hidden = $threshold > 0 && $index >= $threshold ? ' data-hlm-hidden="true"' : ''; ?>
                            <li<?php echo $hidden; ?>>
                                <label>
                                    <input type="checkbox" name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][]" value="<?php echo esc_attr($term->slug); ?>" aria-label="<?php echo esc_attr($term->name); ?>" <?php checked(in_array($term->slug, $filter['selected'], true)); ?>>
                                    <?php echo esc_html($term->name); ?>
                                    <?php if (!empty($enable_counts) && $count !== null) : ?>
                                        <span class="hlm-count" aria-hidden="true">(<?php echo esc_html($count); ?>)</span>
                                        <span class="hlm-sr-only"><?php echo esc_html(sprintf(__('(%d items)', 'hlm-smart-product-filters'), $count)); ?></span>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!$filter['terms']) : ?>
                        <p class="hlm-empty"><?php echo esc_html__('No options available.', 'hlm-smart-product-filters'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($threshold > 0 && $term_count > $threshold) : ?>
                    <button type="button" class="hlm-show-more" data-threshold="<?php echo esc_attr((string) $threshold); ?>" data-target="<?php echo esc_attr($filter['key']); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($list_id); ?>">
                        <?php echo esc_html__('Show more', 'hlm-smart-product-filters'); ?>
                    </button>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="hlm-filter-actions">
        <?php if (!empty($enable_apply_button)) : ?>
            <button type="submit"><?php echo esc_html__('Apply Filters', 'hlm-smart-product-filters'); ?></button>
        <?php endif; ?>
        <a href="<?php echo esc_url($clear_url); ?>"><?php echo esc_html__('Clear All', 'hlm-smart-product-filters'); ?></a>
    </div>
</form>
</div>
