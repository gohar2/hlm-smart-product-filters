<?php

namespace HLM\Filters\Admin;
use HLM\Filters\Support\Config;

class AssignTaxonomy
{
    private Config $config;
    private string $page_slug = 'theme-tag-assigner';
    private string $hook_suffix = '';

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'tpta_add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'tpta_enqueue_scripts']);
        add_action('wp_ajax_tpta_preview', [$this, 'tpta_ajax_preview']);
        add_action('wp_ajax_tpta_assign', [$this, 'tpta_ajax_assign']);
    }


    public function tpta_add_admin_menu() {
        $this->hook_suffix = (string) add_submenu_page(
            'hlm-product-filters',
            __('Theme Tag Assigner', 'hlm-smart-product-filters'),
            __('Theme Tag Assigner', 'hlm-smart-product-filters'),
            'manage_woocommerce',
            $this->page_slug,
            [$this, 'tpta_admin_page']
        );
    }

    public function tpta_admin_page() {
        $themes = get_terms(array('taxonomy' => 'theme', 'hide_empty' => false));
        $product_tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
        
        echo '<div class="wrap">';
        echo '<h1>Theme Product Tag Assigner</h1>';
        echo '<div style="max-width: 600px;">';
        
        echo '<p><a href="' . admin_url('edit-tags.php?taxonomy=theme&post_type=product') . '" class="button button-secondary" target="_blank">Create Theme Terms</a></p>';
        echo '<hr>';
        
        echo '<input type="hidden" id="tpta-nonce" value="' . wp_create_nonce('tpta_nonce_action') . '">';
        
        echo '<p><label><strong>Select Theme:</strong></label><br>';
        echo '<select id="tpta-theme" style="width: 100%; padding: 5px;">';
        echo '<option value="">-- Select Theme --</option>';
        foreach ($themes as $theme) {
            echo '<option value="' . $theme->term_id . '">' . esc_html($theme->name) . ' (' . $theme->count . ')</option>';
        }
        echo '</select></p>';
        
        echo '<p><label><strong>Select Product Tag:</strong></label><br>';
        echo '<select id="tpta-tag" style="width: 100%; padding: 5px;">';
        echo '<option value="">-- Select Product Tag --</option>';
        foreach ($product_tags as $tag) {
            if ( ($tag->count) > 0) echo '<option value="' . $tag->term_id . '">' . esc_html($tag->name) . ' (' . $tag->count . ')</option>';
        }
        echo '</select></p>';
        
        echo '<p><button type="button" id="tpta-preview" class="button button-primary">Preview Stats</button></p>';
        echo '<div id="tpta-result" style="margin-top: 20px;"></div>';
        
        echo '</div></div>';
    }

    public function tpta_enqueue_scripts($hook) {
        if ($this->hook_suffix === '' || $hook !== $this->hook_suffix) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        $script = "
        jQuery(document).ready(function($) {
            $('#tpta-preview').on('click', function() {
                var themeId = $('#tpta-theme').val();
                var tagId = $('#tpta-tag').val();
                var nonce = $('#tpta-nonce').val();
                
                if (!themeId || !tagId) {
                    alert('Please select both Theme and Product Tag');
                    return;
                }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Loading...');
                $('#tpta-result').html('<p><span class=\"spinner is-active\" style=\"float:none;margin:0 10px 0 0;\"></span>Loading stats...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tpta_preview',
                        theme_id: themeId,
                        tag_id: tagId,
                        nonce: nonce
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Preview Stats');
                        
                        if (response.success) {
                            var d = response.data;
                            var html = '<div class=\"notice notice-info\" style=\"padding:15px;\">';
                            html += '<h3 style=\"margin-top:0;\">Preview Stats</h3>';
                            html += '<p><strong>Theme:</strong> ' + d.theme_name + '</p>';
                            html += '<p><strong>Product Tag:</strong> ' + d.tag_name + '</p>';
                            html += '<p><strong>Total Products:</strong> ' + d.total_products + '</p>';
                            html += '<p><strong>Already Have Theme:</strong> ' + d.already_assigned + '</p>';
                            html += '<p><strong>Will Be Assigned:</strong> ' + d.to_assign + '</p>';
                            
                            if (d.to_assign > 0) {
                                html += '<p style=\"margin-top:15px;\"><button type=\"button\" id=\"tpta-confirm\" class=\"button button-primary button-large\">Confirm & Assign Theme</button></p>';
                            } else if (d.total_products === 0) {
                                html += '<p style=\"color:#666;margin-top:10px;\">No products found with this tag.</p>';
                            } else {
                                html += '<p style=\"color:#666;margin-top:10px;\">All products already have this theme assigned.</p>';
                            }
                            html += '</div>';
                            $('#tpta-result').html(html);
                        } else {
                            $('#tpta-result').html('<div class=\"notice notice-error\" style=\"padding:10px;\"><p>Error: ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Preview Stats');
                        $('#tpta-result').html('<div class=\"notice notice-error\" style=\"padding:10px;\"><p>Ajax request failed</p></div>');
                    }
                });
            });
            
            $(document).on('click', '#tpta-confirm', function() {
                var themeId = $('#tpta-theme').val();
                var tagId = $('#tpta-tag').val();
                var nonce = $('#tpta-nonce').val();
                
                if (!confirm('Are you sure you want to assign this theme to the products?')) {
                    return;
                }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Processing...');
                $('#tpta-result').html('<p><span class=\"spinner is-active\" style=\"float:none;margin:0 10px 0 0;\"></span>Assigning theme to products, please wait...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tpta_assign',
                        theme_id: themeId,
                        tag_id: tagId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var d = response.data;
                            var html = '<div class=\"notice notice-success\" style=\"padding:15px;\">';
                            html += '<h3 style=\"margin-top:0;\">✓ Assignment Complete</h3>';
                            html += '<p><strong>Products Updated:</strong> ' + d.assigned_count + '</p>';
                            html += '<p><strong>Products Skipped (already had theme):</strong> ' + d.skipped_count + '</p>';
                            html += '</div>';
                            $('#tpta-result').html(html);
                        } else {
                            $('#tpta-result').html('<div class=\"notice notice-error\" style=\"padding:10px;\"><p>Error: ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#tpta-result').html('<div class=\"notice notice-error\" style=\"padding:10px;\"><p>Ajax request failed</p></div>');
                    }
                });
            });
        });
        ";
        
        wp_add_inline_script('jquery', $script);
    }

    public function tpta_ajax_preview() {
        check_ajax_referer('tpta_nonce_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $theme_id = isset($_POST['theme_id']) ? intval($_POST['theme_id']) : 0;
        $tag_id = isset($_POST['tag_id']) ? intval($_POST['tag_id']) : 0;
        
        if (!$theme_id || !$tag_id) {
            wp_send_json_error('Invalid theme or tag ID');
        }
        
        $theme = get_term($theme_id, 'theme');
        $tag = get_term($tag_id, 'product_tag');
        
        if (is_wp_error($theme) || is_wp_error($tag)) {
            wp_send_json_error('Invalid theme or tag');
        }
        
        $products = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_tag',
                    'field' => 'term_id',
                    'terms' => $tag_id
                )
            ),
            'fields' => 'ids'
        ));
        
        $total = count($products);
        $already_assigned = 0;
        
        if ($total > 0) {
            foreach ($products as $product_id) {
                $existing = wp_get_post_terms($product_id, 'theme', array('fields' => 'ids'));
                if (in_array($theme_id, $existing)) {
                    $already_assigned++;
                }
            }
        }
        
        wp_send_json_success(array(
            'theme_name' => $theme->name,
            'tag_name' => $tag->name,
            'total_products' => $total,
            'already_assigned' => $already_assigned,
            'to_assign' => $total - $already_assigned
        ));
    }

    public function tpta_ajax_assign() {
        check_ajax_referer('tpta_nonce_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $theme_id = isset($_POST['theme_id']) ? intval($_POST['theme_id']) : 0;
        $tag_id = isset($_POST['tag_id']) ? intval($_POST['tag_id']) : 0;
        
        if (!$theme_id || !$tag_id) {
            wp_send_json_error('Invalid theme or tag ID');
        }
        
        $products = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_tag',
                    'field' => 'term_id',
                    'terms' => $tag_id
                )
            ),
            'fields' => 'ids'
        ));
        
        $assigned = 0;
        $skipped = 0;
        
        foreach ($products as $product_id) {
            $existing = wp_get_post_terms($product_id, 'theme', array('fields' => 'ids'));
            
            if (in_array($theme_id, $existing)) {
                $skipped++;
            } else {
                $result = wp_set_object_terms($product_id, $theme_id, 'theme', true);
                if (!is_wp_error($result)) {
                    $assigned++;
                }
            }
        }
        
        wp_send_json_success(array(
            'assigned_count' => $assigned,
            'skipped_count' => $skipped
        ));
    }

}   