<?php

namespace App\Services\RichText;

use Tiptap\Core\Extension;
use Tiptap\Utils\InlineStyle;

class DocumentStyles extends Extension
{
    public static $name = 'documentStyles';

    public function addGlobalAttributes()
    {
        $attributes = [];
        foreach (['fontSize' => 'font-size', 'lineHeight' => 'line-height', 'backgroundColor' => 'background-color'] as $name => $css) {
            $attributes[$name] = [
                'default' => null,
                'parseHTML' => fn ($element) => InlineStyle::getAttribute($element, $css),
                'renderHTML' => fn ($attrs) => isset($attrs->{$name}) ? ['style' => $css.': '.$attrs->{$name}] : null,
            ];
        }

        return [
            ['types' => ['textStyle'], 'attributes' => $attributes],
            ['types' => ['link'], 'attributes' => ['title' => [
                'default' => null,
                'parseHTML' => fn ($element) => $element->getAttribute('title') ?: null,
            ]]],
            ['types' => ['tableCell', 'tableHeader'], 'attributes' => ['align' => [
                'default' => null,
                'parseHTML' => fn ($element) => $element->getAttribute('align') ?: null,
                'renderHTML' => fn ($attrs) => isset($attrs->align) ? ['style' => 'text-align: '.$attrs->align] : null,
            ]]],
            ['types' => ['heading', 'paragraph'], 'attributes' => ['textAlign' => [
                'default' => null,
                'parseHTML' => fn ($element) => InlineStyle::getAttribute($element, 'text-align'),
                'renderHTML' => fn ($attrs) => isset($attrs->textAlign) ? ['style' => 'text-align: '.$attrs->textAlign] : null,
            ]]],
        ];
    }
}
