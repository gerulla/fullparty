<?php

namespace App\Support\Input;

use Normalizer;

final class TextInputSanitizer
{
    public function sanitizeSingleLine(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = $this->normalizeUnicode($value);
        $sanitized = str_replace(["\r\n", "\r"], "\n", $sanitized);
        $sanitized = preg_replace('/\p{Cf}+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]+/u', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\p{Z}+/u', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\s+/u', ' ', $sanitized) ?? $sanitized;

        return trim($sanitized);
    }

    public function sanitizeMultiline(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = $this->normalizeUnicode($value);
        $sanitized = str_replace(["\r\n", "\r"], "\n", $sanitized);
        $sanitized = preg_replace('/\p{Cf}+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\p{Z}+/u', ' ', $sanitized) ?? $sanitized;

        $lines = explode("\n", $sanitized);
        $lines = array_map(function (string $line): string {
            $line = preg_replace('/[^\S\n]+/u', ' ', $line) ?? $line;

            return trim($line);
        }, $lines);

        return trim(implode("\n", $lines));
    }

    public function sanitizeMarkdown(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = $this->normalizeUnicode($value);
        $sanitized = str_replace(["\r\n", "\r"], "\n", $sanitized);
        $sanitized = html_entity_decode($sanitized, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $sanitized = preg_replace('/\p{Cf}+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t|v\s*b\s*s\s*c\s*r\s*i\s*p\s*t|d\s*a\s*t\s*a)\s*:/iu', '#blocked-', $sanitized) ?? $sanitized;

        return trim(str_replace(['<', '>'], ['&lt;', '&gt;'], $sanitized));
    }

    private function normalizeUnicode(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }
}
