<?php

namespace App\Services\RichText;

use Tiptap\Core\Node;

class GameIconNode extends Node
{
    public static $name = 'gameIcon';

    public function addOptions(): array
    {
        return ['group' => 'inline', 'inline' => true, 'atom' => true];
    }

    public function addAttributes(): array
    {
        $attributes = [];
        foreach (['key', 'shortcode', 'src'] as $name) {
            $attributes[$name] = [
                'default' => null,
                'parseHTML' => fn ($element) => $element->getAttribute($name === 'src' ? 'src' : 'data-game-icon-'.$name),
            ];
        }

        return $attributes;
    }

    public function parseHTML(): array
    {
        return [['tag' => 'img[data-game-icon-key]']];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['img', [
            'data-game-icon-key' => $node->attrs->key,
            'data-game-icon-shortcode' => $node->attrs->shortcode,
            'src' => $node->attrs->src,
            'alt' => ':'.$node->attrs->shortcode.':',
            'title' => ':'.$node->attrs->shortcode.':',
            'draggable' => 'false',
            'width' => '24', 'height' => '24',
        ]];
    }
}
