<?php

namespace App\Services\RichText;

use Tiptap\Core\Node;

class ResourceLinkNode extends Node
{
    public static $name = 'resourceLink';

    public function addOptions(): array
    {
        return ['group' => 'block', 'atom' => true];
    }

    public function addAttributes(): array
    {
        return ['resourceId' => ['default' => null]];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['div', ['data-resource-link' => $node->attrs->resourceId], 0];
    }

    public function renderText($node): string
    {
        return e(__('rich_text.resource_link'));
    }
}
