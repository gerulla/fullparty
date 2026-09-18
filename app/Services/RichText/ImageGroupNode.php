<?php

namespace App\Services\RichText;

use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

class ImageGroupNode extends Node
{
    public static $name = 'imageGroup';

    public function addAttributes(): array
    {
        return ['align' => [
            'default' => 'center',
            'parseHTML' => fn ($element) => $element->getAttribute('data-image-group-align') ?: 'center',
            'renderHTML' => fn ($attrs) => ['data-image-group-align' => $attrs->align ?? 'center'],
        ]];
    }

    public function parseHTML(): array
    {
        return [['tag' => 'div[data-image-group]']];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['div', HTML::mergeAttributes($HTMLAttributes, ['data-image-group' => 'true']), 0];
    }
}
