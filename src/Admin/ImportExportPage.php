<?php

namespace HLM\Filters\Admin;

use HLM\Filters\Support\Config;

final class ImportExportPage
{
    private Config $config;
    private string $page_slug = 'hlm-product-filters-import-export';

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_hlm_import_filters', [$this, 'handle_import']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'hlm-product-filters',
            __('Import/Export', 'hlm-smart-product-filters'),
            __('Import/Export', 'hlm-smart-product-filters'),
            'manage_woocommerce',
            $this->page_slug,
            [$this, 'render_page']
        );
    }

    public function handle_import(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to import filters.', 'hlm-smart-product-filters'));
        }

        check_admin_referer('hlm_import_filters');

        $redirect_args = ['page' => $this->page_slug];

        // Try file upload first, then fall back to textarea paste
        if (!empty($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
            $decoded = json_decode($file_content, true);
        } else {
            $payload = wp_unslash($_POST['hlm_import_payload'] ?? '');
            $decoded = json_decode((string) $payload, true);
        }

        if (!is_array($decoded)) {
            $redirect_args['import_result'] = 'invalid_json';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        if (empty($decoded['filters']) && empty($decoded['global'])) {
            $redirect_args['import_result'] = 'no_data';
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $sanitized = $this->config->sanitize($decoded);
        update_option(Config::OPTION_KEY, $sanitized);

        $filter_count = count($sanitized['filters'] ?? []);
        $redirect_args['import_result'] = 'success';
        $redirect_args['count'] = $filter_count;
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $config = $this->config->get();
        $export_json = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $sample_json = wp_json_encode($this->get_sample_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HLM Filters Import / Export', 'hlm-smart-product-filters') . '</h1>';

        $this->render_notices();

        // --- Export Section ---
        echo '<div class="card" style="max-width:800px;margin-top:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Export Configuration', 'hlm-smart-product-filters') . '</h2>';
        echo '<p class="description">' . esc_html__('Copy or download your current filter configuration. You can use this to back up your settings or transfer them to another site.', 'hlm-smart-product-filters') . '</p>';
        echo '<textarea id="hlm-export-json" class="large-text code" rows="12" readonly>' . esc_textarea($export_json) . '</textarea>';
        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button button-primary" id="hlm-download-export">';
        echo '<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>';
        echo esc_html__('Download as JSON', 'hlm-smart-product-filters');
        echo '</button> ';
        echo '<button type="button" class="button" id="hlm-copy-export">';
        echo '<span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-right:4px;"></span>';
        echo esc_html__('Copy to Clipboard', 'hlm-smart-product-filters');
        echo '</button>';
        echo '<span id="hlm-copy-feedback" style="margin-left:10px;color:#00a32a;display:none;">' . esc_html__('Copied!', 'hlm-smart-product-filters') . '</span>';
        echo '</p>';
        echo '</div>';

        // --- Import Section ---
        echo '<div class="card" style="max-width:800px;margin-top:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Import Configuration', 'hlm-smart-product-filters') . '</h2>';
        echo '<p class="description">' . esc_html__('Upload a JSON file or paste the configuration below to import. This will replace all current settings and filters.', 'hlm-smart-product-filters') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="hlm_import_filters">';
        wp_nonce_field('hlm_import_filters');

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="hlm-import-file">' . esc_html__('Upload JSON file', 'hlm-smart-product-filters') . '</label></th>';
        echo '<td><input type="file" id="hlm-import-file" name="import_file" accept=".json,application/json"></td></tr>';

        echo '<tr><th scope="row"><label for="hlm-import-paste">' . esc_html__('Or paste JSON', 'hlm-smart-product-filters') . '</label></th>';
        echo '<td><textarea id="hlm-import-paste" class="large-text code" rows="10" name="hlm_import_payload" placeholder="' . esc_attr__('Paste your exported JSON here...', 'hlm-smart-product-filters') . '"></textarea></td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit" style="padding-top:0;">';
        echo '<button type="submit" class="button button-primary" id="hlm-import-btn" onclick="return confirm(\'' . esc_js(__('This will replace all current settings and filters. Continue?', 'hlm-smart-product-filters')) . '\');">';
        echo esc_html__('Import Configuration', 'hlm-smart-product-filters');
        echo '</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';

        // --- Sample File Section ---
        echo '<div class="card" style="max-width:800px;margin-top:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Sample Configuration', 'hlm-smart-product-filters') . '</h2>';
        echo '<p class="description">' . esc_html__('Download a sample configuration file to see the expected format. You can edit this file and import it above.', 'hlm-smart-product-filters') . '</p>';
        echo '<textarea id="hlm-sample-json" class="large-text code" rows="8" readonly>' . esc_textarea($sample_json) . '</textarea>';
        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button" id="hlm-download-sample">';
        echo '<span class="dashicons dashicons-media-code" style="vertical-align:middle;margin-right:4px;"></span>';
        echo esc_html__('Download Sample JSON', 'hlm-smart-product-filters');
        echo '</button>';
        echo '</p>';
        echo '</div>';

        echo '</div>'; // .wrap

        $this->render_inline_script();
    }

    private function render_notices(): void
    {
        if (!isset($_GET['import_result'])) {
            return;
        }

        $result = sanitize_key($_GET['import_result']);

        if ($result === 'success') {
            $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html(_n(
                    'Configuration imported successfully with %d filter.',
                    'Configuration imported successfully with %d filters.',
                    $count,
                    'hlm-smart-product-filters'
                )),
                $count
            );
            echo '</p></div>';
        } elseif ($result === 'invalid_json') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('Import failed: invalid JSON. Please check the file or pasted content and try again.', 'hlm-smart-product-filters');
            echo '</p></div>';
        } elseif ($result === 'no_data') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('Import failed: no filters or settings found in the provided data.', 'hlm-smart-product-filters');
            echo '</p></div>';
        }
    }

    private function render_inline_script(): void
    {
        ?>
        <script>
        (function() {
            function downloadJSON(textareaId, filename) {
                var text = document.getElementById(textareaId).value;
                var blob = new Blob([text], {type: 'application/json'});
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
            }

            var dlExport = document.getElementById('hlm-download-export');
            if (dlExport) {
                dlExport.addEventListener('click', function() {
                    downloadJSON('hlm-export-json', 'hlm-filters-config.json');
                });
            }

            var dlSample = document.getElementById('hlm-download-sample');
            if (dlSample) {
                dlSample.addEventListener('click', function() {
                    downloadJSON('hlm-sample-json', 'hlm-filters-sample.json');
                });
            }

            var copyBtn = document.getElementById('hlm-copy-export');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var textarea = document.getElementById('hlm-export-json');
                    textarea.select();
                    textarea.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(textarea.value).then(function() {
                        var fb = document.getElementById('hlm-copy-feedback');
                        fb.style.display = 'inline';
                        setTimeout(function() { fb.style.display = 'none'; }, 2000);
                    });
                });
            }
        })();
        </script>
        <?php
    }

    private function get_sample_config(): array
    {
        return [
            'schema_version' => Config::SCHEMA_VERSION,
            'global' => $this->config->defaults()['global'],
            'filters' => [
                [
                    'id' => 'category',
                    'label' => 'Product Categories',
                    'key' => 'category',
                    'type' => 'checkbox',
                    'data_source' => 'product_cat',
                    'source_key' => 'product_cat',
                    'behavior' => ['multi_select' => true, 'operator' => 'OR'],
                    'visibility' => ['hide_empty' => true, 'include_children' => true],
                    'ui' => ['show_more_threshold' => 5],
                ],
                [
                    'id' => 'color',
                    'label' => 'Color',
                    'key' => 'color',
                    'type' => 'swatch',
                    'data_source' => 'attribute',
                    'source_key' => 'color',
                    'behavior' => ['multi_select' => true, 'operator' => 'OR'],
                    'visibility' => ['hide_empty' => true],
                    'ui' => ['swatch_type' => 'color'],
                ],
                [
                    'id' => 'size',
                    'label' => 'Size',
                    'key' => 'size',
                    'type' => 'swatch',
                    'data_source' => 'attribute',
                    'source_key' => 'size',
                    'behavior' => ['multi_select' => true, 'operator' => 'OR'],
                    'visibility' => ['hide_empty' => true],
                    'ui' => ['swatch_type' => 'text'],
                ],
                [
                    'id' => 'price',
                    'label' => 'Price Range',
                    'key' => 'price_ranges',
                    'type' => 'range',
                    'data_source' => 'meta',
                    'source_key' => '_price',
                    'behavior' => ['multi_select' => false],
                    'visibility' => [],
                    'ui' => ['range_prefix' => '$'],
                ],
            ],
        ];
    }
}
