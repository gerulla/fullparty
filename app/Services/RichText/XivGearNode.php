<?php

namespace App\Services\RichText;

use Tiptap\Core\Node;

class XivGearNode extends Node
{
    public static $name = 'xivGear';

    public function addOptions(): array
    {
        return ['group' => 'block', 'atom' => true];
    }

    public function addAttributes(): array
    {
        return ['display' => ['default' => 'expanded'], 'snapshots' => ['default' => []]];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['content' => view('resources.gearset-fallback', ['snapshots' => $node->attrs->snapshots])->render()];
    }
}
