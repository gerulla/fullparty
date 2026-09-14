<?php

use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Validation\ValidationException;

it('renders document formatting and escapes literal HTML', function () {
    $document = ['type' => 'doc', 'content' => [[
        'type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [[
            'type' => 'text', 'text' => '<script>alert(1)</script>', 'marks' => [
                ['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => '#ff0000', 'fontSize' => '24px', 'fontFamily' => 'Georgia', 'lineHeight' => '1.5']],
            ],
        ]],
    ]]];
    $service = app(RichTextDocument::class);
    $html = $service->html($document);
    expect($html)->toContain('&lt;script&gt;', 'text-align: center', 'font-size: 24px', 'line-height: 1.5', '<strong>')
        ->not->toContain('<script>');
    expect($service->text($document))->toContain('<script>alert(1)</script>');
});

it('rejects unsafe or unsupported document structures', function (array $document) {
    expect(fn () => app(RichTextDocument::class)->validate($document))->toThrow(ValidationException::class);
})->with([
    'script node' => [['type' => 'doc', 'content' => [['type' => 'script']]]],
    'unsafe link' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]]],
    'image data URL' => [['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'data:image/svg+xml,bad']]]]],
    'image handler' => [['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png', 'onerror' => 'alert(1)']]]]],
    'CSS injection' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => 'url(https://example.com)']]]]]]]]],
    'malformed spacing' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'textStyle', 'attrs' => ['lineHeight' => []]]]]]]]]],
    'font CSS injection' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Arial; background:url(https://example.com)']]]]]]]]],
    'unsafe link on line break' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'hardBreak', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]]],
    'block formatting marks' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'marks' => [['type' => 'bold']]]]]],
    'prototype attributes' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['__proto__' => ['onload' => 'alert(1)']]]]]],
    'misplaced row' => [['type' => 'doc', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'paragraph']]]]]],
]);

it('preserves supported formatting while ignoring foreign clipboard presentation styles', function () {
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
        'type' => 'text', 'text' => 'Demon Tablet', 'marks' => [
            ['type' => 'bold'],
            ['type' => 'textStyle', 'attrs' => ['fontFamily' => '"gg sans", "Noto Sans", Arial, sans-serif', 'fontSize' => '16px', 'lineHeight' => '22px', 'color' => 'rgb(219, 222, 225)', 'backgroundColor' => 'rgba(0, 0, 0, 0.2)']],
        ],
    ]]]]];
    $service = app(RichTextDocument::class);
    $normalized = $service->validate($document);
    expect($normalized['content'][0]['content'][0]['marks'][1]['attrs'])->toBe(['fontSize' => '16px', 'color' => 'rgb(219, 222, 225)'])
        ->and($service->text($normalized))->toBe('Demon Tablet')
        ->and($service->html($normalized))->toContain('<strong>', 'font-size: 16px')->not->toContain('gg sans', '22px', 'rgba');
});

it('accepts marks on pasted line breaks while keeping their formatting', function () {
    $bold = [['type' => 'bold']];
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'First line', 'marks' => $bold],
        ['type' => 'hardBreak', 'marks' => $bold],
        ['type' => 'text', 'text' => 'Second line', 'marks' => $bold],
    ]]]];
    expect(app(RichTextDocument::class)->html($document))->toBe('<p><strong>First line<br>Second line</strong></p>');
});

it('converts supported Markdown guides without losing their content', function (string $markdown) {
    $document = app(MarkdownGuideConverter::class)->convert($markdown);
    expect($document['type'])->toBe('doc');
    expect(app(RichTextDocument::class)->html($document))->not->toBeEmpty();
})->with([
    'basic formatting' => "# Opener\n\nUse **Burst** and *move*. ~~Wait~~. `code`\n\n> Hold here\n\n---\n",
    'lists' => "- First\n- Second\n  - Nested\n\n1. One\n2. Two\n",
    'images and links' => "Before ![Map](https://example.com/map.png \"Bridge\") after.\n\n[Read more](https://example.com/guide \"Guide\")",
    'table' => "| Party | Position |\n| --- | --- |\n| A | West |\n| B | East |",
    'tasks' => "- [x] Checked\n- [ ] Unchecked",
    'unicode and code' => "## Taktik 日本語\n\n```text\n  keep  spacing\nnext\n```",
    'literal html' => '<script>alert(1)</script>',
]);

it('limits document depth and text length', function () {
    $documents = app(RichTextDocument::class);
    $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '123456']]];
    expect(fn () => $documents->validate(['type' => 'doc', 'content' => [$node]], 'guide', 5))->toThrow(ValidationException::class);
    for ($i = 0; $i < 33; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }
    expect(fn () => $documents->validate(['type' => 'doc', 'content' => [$node]]))->toThrow(ValidationException::class);
});
