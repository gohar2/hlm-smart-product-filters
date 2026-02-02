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
                <?php if ($style === 'dropdown') : ?>
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
                    <ul class="hlm-swatch-list" id="<?php echo esc_attr($list_id); ?>">
                        <?php foreach ($filter['terms'] as $index => $term) : ?>
                            <?php
                            $count = $filter['counts'][$term->term_id] ?? null;
                            $swatch = $filter['swatch_map'][$term->term_id] ?? '';
                            $swatch_type = $filter['swatch_type'] ?? 'color';
                            if ($swatch === '') {
                                $swatch = (string) get_term_meta($term->term_id, 'color', true);
                            }
                            if ($swatch === '') {
                                $swatch = (string) get_term_meta($term->term_id, 'swatch_color', true);
                            }
                            $hidden = $threshold > 0 && $index >= $threshold ? ' data-hlm-hidden="true"' : '';
                            ?>
                            <li<?php echo $hidden; ?>>
                                <label class="hlm-swatch">
                                    <input type="checkbox" name="hlm_filters[<?php echo esc_attr($filter['key']); ?>][]" value="<?php echo esc_attr($term->slug); ?>" aria-label="<?php echo esc_attr($term->name); ?>" <?php checked(in_array($term->slug, $filter['selected'], true)); ?>>
                                    <?php if ($swatch_type === 'image' && $swatch !== '') : ?>
                                        <span class="hlm-swatch-chip hlm-swatch-image" style="background-image:url('<?php echo esc_url($swatch); ?>');"></span>
                                    <?php elseif ($swatch_type === 'text' && $swatch !== '') : ?>
                                        <span class="hlm-swatch-chip hlm-swatch-text"><?php echo esc_html($swatch); ?></span>
                                    <?php else : ?>
                                        <span class="hlm-swatch-chip" style="background: <?php echo esc_attr($swatch ? $swatch : '#ccc'); ?>"></span>
                                    <?php endif; ?>
                                    <span class="hlm-swatch-label"><?php echo esc_html($term->name); ?></span>
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
