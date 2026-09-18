<?php

namespace App\Services\RichText;

use Tiptap\Nodes\Image;

class ImageNode extends Image
{
    public function parseHTML(): array
    {
        return [['tag' => 'img[src]', 'getAttrs' => fn ($element) => $element->hasAttribute('data-inline-image') || $element->hasAttribute('data-game-icon-key') ? false : null]];
    }

    public function addAttributes(): array
    {
        $attributes = parent::addAttributes();
        foreach (['layout', 'align'] as $name) {
            $attributes[$name] = [
                'default' => null,
                'parseHTML' => fn ($element) => $element->getAttribute('data-image-'.$name) ?: null,
                'renderHTML' => fn ($attrs) => isset($attrs->{$name}) ? ['data-image-'.$name => $attrs->{$name}] : null,
            ];
        }

        return $attributes;
    }
}
