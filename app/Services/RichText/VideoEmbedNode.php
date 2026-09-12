<?php

namespace App\Services\RichText;

use Tiptap\Core\Node;

class VideoEmbedNode extends Node
{
    public static $name = 'videoEmbed';

    public function addOptions(): array
    {
        return ['group' => 'block', 'atom' => true];
    }

    public function addAttributes(): array
    {
        return ['url' => ['default' => null], 'title' => ['default' => '']];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['p', ['a', ['href' => $node->attrs->url, 'rel' => 'nofollow noopener noreferrer'], 0]];
    }

    public function renderText($node): string
    {
        return e($node->attrs->title ?: __('rich_text.video_embed'));
    }
}
