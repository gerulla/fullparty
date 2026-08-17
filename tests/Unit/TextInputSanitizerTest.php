<?php

use App\Support\Input\TextInputSanitizer;

it('sanitizes single line text while preserving valid language characters', function () {
    $sanitizer = new TextInputSanitizer;
    $raw = "E\u{0301}quipe\u{00A0}\u{200B}Raid\x07";

    $expected = class_exists(Normalizer::class)
        ? 'Équipe Raid'
        : "E\u{0301}quipe Raid";

    expect($sanitizer->sanitizeSingleLine($raw))->toBe($expected);
});

it('sanitizes multiline text while preserving line breaks', function () {
    $sanitizer = new TextInputSanitizer;
    $raw = "Line\u{00A0}one\u{200B}\r\nSecond\u{202E} line\t";

    expect($sanitizer->sanitizeMultiline($raw))->toBe("Line one\nSecond line");
});

it('sanitizes markdown without stripping markdown formatting', function () {
    $sanitizer = new TextInputSanitizer;
    $raw = "## Usage\r\n\n<script>alert('x')</script>\n[bad](javascript:alert(1))\n![bad](data:text/html,<svg onload=alert(1)>)\n    keep indentation";

    expect($sanitizer->sanitizeMarkdown($raw))->toBe("## Usage\n\n&lt;script&gt;alert('x')&lt;/script&gt;\n[bad](#blocked-alert(1))\n![bad](#blocked-text/html,&lt;svg onload=alert(1)&gt;)\n    keep indentation");
});

it('sanitizes obfuscated markdown link schemes', function () {
    $sanitizer = new TextInputSanitizer;
    $raw = "[encoded](java&#x200B;script:alert(1))\n[spaced](j a v a s c r i p t:alert(1))\n[vb](vbscript:msgbox(1))";

    expect($sanitizer->sanitizeMarkdown($raw))->toBe("[encoded](#blocked-alert(1))\n[spaced](#blocked-alert(1))\n[vb](#blocked-msgbox(1))");
});
