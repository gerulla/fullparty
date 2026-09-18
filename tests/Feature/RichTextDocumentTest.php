<?php

use App\Services\Moderation\ReportContentPreview;
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

it('preserves controlled image layouts and inline images through HTML conversion', function () {
    $service = app(RichTextDocument::class);
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => '/map.png', 'width' => 320, 'layout' => 'wrap-left', 'align' => 'center']],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Position '],
            ['type' => 'inlineImage', 'attrs' => ['src' => '/marker.png', 'width' => 24, 'alt' => 'A']],
            ['type' => 'text', 'text' => ' here.'],
        ]],
    ]];
    $html = $service->html($document);
    expect($html)->toContain('data-image-layout="wrap-left"', 'data-image-align="center"', 'width="320"', 'data-inline-image="true"', 'width="24"');
    $roundTrip = $service->validate($service->editor()->setContent($html)->getDocument());
    expect($roundTrip['content'][0]['attrs']['layout'])->toBe('wrap-left')
        ->and($roundTrip['content'][1]['content'][1]['type'])->toBe('inlineImage')
        ->and($service->imageUrls($roundTrip))->toBe(['/map.png', '/marker.png']);
});

it('preserves aligned image groups, individual sizes and asset references through HTML conversion', function () {
    $service = app(RichTextDocument::class);
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'imageGroup', 'attrs' => ['align' => 'center'], 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/first.png', 'width' => 240, 'alt' => 'First position']],
            ['type' => 'image', 'attrs' => ['src' => '/second.png', 'width' => 320, 'alt' => 'Second position']],
        ]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After the images.']]],
    ]];
    $html = $service->html($document);
    expect($html)->toContain('data-image-group="true"', 'data-image-group-align="center"', 'width="240"', 'width="320"');
    $roundTrip = $service->validate($service->editor()->setContent($html)->getDocument());
    expect($roundTrip['content'][0]['type'])->toBe('imageGroup')
        ->and($roundTrip['content'][0]['attrs']['align'])->toBe('center')
        ->and($roundTrip['content'][0]['content'])->toHaveCount(2)
        ->and($roundTrip['content'][0]['content'][1]['attrs']['width'])->toBe(320)
        ->and($service->imageUrls($roundTrip))->toBe(['/first.png', '/second.png'])
        ->and($service->text($roundTrip))->toContain('After the images.');
});

it('rejects empty, malformed and unsafe image groups', function (array $group) {
    expect(fn () => app(RichTextDocument::class)->validate(['type' => 'doc', 'content' => [$group]]))->toThrow(ValidationException::class);
})->with([
    'empty' => [['type' => 'imageGroup', 'content' => []]],
    'text child' => [['type' => 'imageGroup', 'content' => [['type' => 'paragraph']]]],
    'inline child' => [['type' => 'imageGroup', 'content' => [['type' => 'inlineImage', 'attrs' => ['src' => '/map.png']]]]],
    'nested group' => [['type' => 'imageGroup', 'content' => [['type' => 'imageGroup', 'content' => [['type' => 'image', 'attrs' => ['src' => '/map.png']]]]]]],
    'unsafe source' => [['type' => 'imageGroup', 'content' => [['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']]]]],
    'unsafe attribute' => [['type' => 'imageGroup', 'attrs' => ['onclick' => 'alert(1)'], 'content' => [['type' => 'image', 'attrs' => ['src' => '/map.png']]]]],
    'unsupported alignment' => [['type' => 'imageGroup', 'attrs' => ['align' => 'justify'], 'content' => [['type' => 'image', 'attrs' => ['src' => '/map.png']]]]],
]);

it('keeps grouped image evidence in moderation previews and uses authorized asset URLs', function () {
    $uuid = '11111111-1111-1111-1111-111111111111';
    $snapshot = ['content' => ['body' => ['type' => 'doc', 'content' => [[
        'type' => 'imageGroup', 'attrs' => ['align' => 'right'], 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/resource-assets/'.$uuid, 'width' => 240]],
            ['type' => 'image', 'attrs' => ['src' => '/another.png', 'width' => 200]],
        ],
    ]]]]];
    $preview = app(ReportContentPreview::class);
    expect($preview->build($snapshot)['html'])->toContain('/resource-assets/'.$uuid, '/another.png')->not->toContain('<img');
    expect($preview->build($snapshot, [$uuid => '/admin/reports/1/asset/1'])['html'])->toContain('src="/admin/reports/1/asset/1"', 'data-image-group-align="right"', 'width="240"');
});

it('rejects unsafe or misplaced inline images and image layout attributes', function (array $node) {
    expect(fn () => app(RichTextDocument::class)->validate(['type' => 'doc', 'content' => [$node]]))->toThrow(ValidationException::class);
})->with([
    'inline at root' => [['type' => 'inlineImage', 'attrs' => ['src' => '/map.png']]],
    'unsafe source' => [['type' => 'paragraph', 'content' => [['type' => 'inlineImage', 'attrs' => ['src' => 'javascript:alert(1)']]]]],
    'unsafe attribute' => [['type' => 'paragraph', 'content' => [['type' => 'inlineImage', 'attrs' => ['src' => '/map.png', 'onerror' => 'alert(1)']]]]],
    'missing source' => [['type' => 'paragraph', 'content' => [['type' => 'inlineImage', 'attrs' => ['width' => 24]]]]],
    'layout injection' => [['type' => 'image', 'attrs' => ['src' => '/map.png', 'layout' => 'position:fixed']]],
    'unsupported alignment' => [['type' => 'image', 'attrs' => ['src' => '/map.png', 'align' => 'justify']]],
]);

it('retains inline image evidence in moderation previews without loading unauthorized assets', function () {
    $image = ['type' => 'inlineImage', 'attrs' => ['src' => '/resource-assets/11111111-1111-1111-1111-111111111111', 'width' => 24]];
    $snapshot = ['content' => ['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$image]]]]]];
    $preview = app(ReportContentPreview::class);
    expect($preview->build($snapshot)['html'])->toContain('/resource-assets/11111111-1111-1111-1111-111111111111')->not->toContain('<img');
    expect($preview->build($snapshot, ['11111111-1111-1111-1111-111111111111' => '/admin/reports/1/asset/1'])['html'])->toContain('src="/admin/reports/1/asset/1"', 'data-inline-image');
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
