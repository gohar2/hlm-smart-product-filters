<?php

namespace HLM\Filters;

use HLM\Filters\Admin\SettingsPage;
use HLM\Filters\Admin\FiltersBuilderPage;
use HLM\Filters\Admin\ImportExportPage;
use HLM\Filters\Admin\AdminAjax;
use HLM\Filters\Ajax\FilterAjax;
use HLM\Filters\Cache\CacheInvalidator;
use HLM\Filters\Frontend\Assets;
use HLM\Filters\Frontend\AutoInjector;
use HLM\Filters\Rendering\Shortcode;
use HLM\Filters\Support\Config;

final class Plugin
{
    private static ?self $instance = null;
    private ?Config $config = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        do_action('hlm_filters_activated');
    }

    public static function deactivate(): void
    {
        do_action('hlm_filters_deactivated');
    }

    public function boot(): void
    {
        $this->config = new Config();

        if (is_admin()) {
            (new SettingsPage($this->config))->register();
            (new FiltersBuilderPage($this->config))->register();
            (new ImportExportPage($this->config))->register();
            (new AdminAjax())->register();
        }

        (new Shortcode($this->config))->register();
        (new FilterAjax($this->config))->register();
        (new Assets($this->config))->register();
        (new AutoInjector($this->config))->register();
        (new CacheInvalidator())->register();

        do_action('hlm_filters_boot');
    }

    private function __construct()
    {
    }
}
