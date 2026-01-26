<?php

namespace HLM\Filters\Rendering;

final class TemplateLoader
{
    private string $slug = 'hlm-smart-product-filters';
    private string $base_path;

    public function __construct(string $base_path)
    {
        $this->base_path = rtrim($base_path, '/');
    }

    public function render(string $template, array $data = []): void
    {
        $path = $this->locate($template);
        if (!$path) {
            return;
        }

        extract($data, EXTR_SKIP);
        include $path;
    }

    private function locate(string $template): ?string
    {
        $template = ltrim($template, '/');
        $theme_path = locate_template($this->slug . '/' . $template);
        if ($theme_path) {
            return $theme_path;
        }

        $plugin_path = $this->base_path . '/templates/' . $template;
        if (is_readable($plugin_path)) {
            return $plugin_path;
        }

        return null;
    }
}
