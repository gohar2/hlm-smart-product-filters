<?php

namespace HLM\Filters\Data;

final class AttributeMapping
{
    public function defaults(): array
    {
        return [
            'color' => 'pa_color',
            'breeds' => 'pa_breeds',
            'size' => 'pa_size',
            'gender' => 'pa_gender',
            'category' => 'product_cat',
            'tags' => 'product_tag',
        ];
    }
}
