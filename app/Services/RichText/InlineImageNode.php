<?php

namespace App\Services\RichText;

use Tiptap\Nodes\Image;
use Tiptap\Utils\HTML;

class InlineImageNode extends Image
{
    public static $name = 'inlineImage';

    public function parseHTML(): array
    {
        return [['tag' => 'img[data-inline-image]']];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['img', HTML::mergeAttributes($HTMLAttributes, ['data-inline-image' => 'true']), 0];
    }
}
