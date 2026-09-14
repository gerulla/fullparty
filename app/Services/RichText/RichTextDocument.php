<?php

namespace App\Services\RichText;

use App\Support\VideoEmbedUrl;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tiptap\Editor;
use Tiptap\Extensions;
use Tiptap\Marks;
use Tiptap\Nodes;

class RichTextDocument
{
    public const FORMAT = 'tiptap';

    private const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'image', 'table'];

    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    public function editor(): Editor
    {
        return new Editor(['extensions' => [
            new Extensions\StarterKit, new ImageNode, new InlineImageNode,
            new Nodes\Table, new Nodes\TableRow, new Nodes\TableCell, new Nodes\TableHeader,
            new Nodes\TaskList, new Nodes\TaskItem,
            new Marks\Link(['HTMLAttributes' => ['rel' => 'nofollow noopener noreferrer']]),
            new Marks\Underline, new Marks\Subscript, new Marks\Superscript,
            new Marks\Highlight(['multicolor' => true]), new Marks\TextStyle,
            new Extensions\Color, new Extensions\FontFamily,
            new DocumentStyles,
            new ResourceLinkNode, new VideoEmbedNode,
        ]]);
    }

    public function validate(mixed $document, string $field = 'body', int $maxText = 200000, bool $resourceBlocks = false): array
    {
        $fail = fn () => throw ValidationException::withMessages([$field => __('rich_text.invalid')]);
        if (! is_array($document) || ($document['type'] ?? null) !== 'doc' || strlen(json_encode($document)) > 2000000) {
            $fail();
        }
        $count = 0;
        $length = 0;
        $blocks = [...self::BLOCKS, ...($resourceBlocks ? ['resourceLink', 'videoEmbed'] : [])];
        $walk = function (mixed $node, int $depth = 0) use (&$walk, &$count, &$length, $maxText, $fail, $blocks): array {
            if (! is_array($node) || array_is_list($node) || ++$count > 20000 || $depth > 32 || array_diff(array_keys($node), ['type', 'attrs', 'content', 'marks', 'text'])) {
                $fail();
            }
            $type = $node['type'] ?? '';
            if (! in_array($type, [...$blocks, 'doc', 'text', 'hardBreak', 'inlineImage', 'listItem', 'taskItem', 'tableRow', 'tableCell', 'tableHeader'], true)) {
                $fail();
            }
            if ($type === 'doc' && $depth !== 0) {
                $fail();
            }
            $result = ['type' => $type];
            if ($type === 'text') {
                if (! is_string($node['text'] ?? null) || $node['text'] === '' || isset($node['content'])) {
                    $fail();
                }
                $length += mb_strlen($node['text']);
                if ($length > $maxText) {
                    $fail();
                }
                $result['text'] = $node['text'];
            } elseif (isset($node['text'])) {
                $fail();
            }
            $attrs = $this->attributes($type, $node['attrs'] ?? [], $fail);
            if ($type === 'videoEmbed') {
                $length += mb_strlen($attrs['title'] ?? '');
                if ($length > $maxText) {
                    $fail();
                }
            }
            if ($attrs !== []) {
                $result['attrs'] = $attrs;
            }
            if (isset($node['marks'])) {
                if (! in_array($type, ['text', 'hardBreak', 'inlineImage'], true) || ! is_array($node['marks']) || ! array_is_list($node['marks']) || count($node['marks']) > 12) {
                    $fail();
                }
                $seen = [];
                foreach ($node['marks'] as $mark) {
                    if (! is_array($mark) || ! is_string($mark['type'] ?? null)) {
                        $fail();
                    }
                    $name = $mark['type'];
                    if (array_diff(array_keys($mark), ['type', 'attrs']) || isset($seen[$name]) || ! in_array($name, ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'subscript', 'superscript', 'highlight', 'textStyle'], true)) {
                        $fail();
                    }
                    $seen[$name] = true;
                    $markAttrs = $this->attributes($name, $mark['attrs'] ?? [], $fail);
                    $result['marks'][] = ['type' => $name] + ($markAttrs ? ['attrs' => $markAttrs] : []);
                }
            }
            $children = $node['content'] ?? [];
            if (! is_array($children) || ! array_is_list($children)) {
                $fail();
            }
            $allowed = match ($type) {
                'doc', 'blockquote', 'listItem', 'taskItem', 'tableCell', 'tableHeader' => $blocks,
                'paragraph', 'heading' => ['text', 'hardBreak', 'inlineImage'],
                'codeBlock' => ['text'],
                'bulletList', 'orderedList' => ['listItem'],
                'taskList' => ['taskItem'], 'table' => ['tableRow'], 'tableRow' => ['tableCell', 'tableHeader'],
                default => [],
            };
            foreach ($children as $child) {
                if (! is_array($child) || ! in_array($child['type'] ?? '', $allowed, true) || ($type === 'codeBlock' && ! empty($child['marks']))) {
                    $fail();
                }
                $result['content'][] = $walk($child, $depth + 1);
            }
            if (in_array($type, ['doc', 'blockquote', 'listItem', 'taskItem', 'table', 'tableCell', 'tableHeader', 'bulletList', 'orderedList', 'taskList']) && ! $children) {
                $fail();
            }
            if (in_array($type, ['listItem', 'taskItem']) && ($children[0]['type'] ?? null) !== 'paragraph') {
                $fail();
            }
            if ($type === 'table') {
                $this->validateTable($result['content'], $fail);
            }

            return $result;
        };

        return $walk($document);
    }

    private function attributes(string $type, mixed $attributes, \Closure $fail): array
    {
        $allowed = match ($type) {
            'paragraph' => ['textAlign'], 'heading' => ['level', 'textAlign'],
            'orderedList' => ['start', 'type'], 'taskItem' => ['checked'], 'codeBlock' => ['language'],
            'image' => ['src', 'alt', 'title', 'width', 'height', 'layout', 'align'],
            'inlineImage' => ['src', 'alt', 'title', 'width', 'height'],
            'tableCell', 'tableHeader' => ['colspan', 'rowspan', 'colwidth', 'align'],
            'link' => ['href', 'target', 'rel', 'class', 'title'],
            'textStyle' => ['color', 'backgroundColor', 'fontSize', 'fontFamily', 'lineHeight'],
            'highlight' => ['color'],
            'resourceLink' => ['resourceId'], 'videoEmbed' => ['url', 'title'],
            default => [],
        };
        if (! is_array($attributes) || array_diff(array_keys($attributes), $allowed)) {
            $fail();
        }
        $attributes = array_filter($attributes, fn ($value) => $value !== null);
        if (in_array($type, ['textStyle', 'highlight'], true)) {
            // Clipboard HTML carries app-specific fonts, spacing and colors. Keep
            // supported styles and discard benign presentation values we cannot render.
            foreach ($attributes as $key => $value) {
                if (! $this->validStyleValue($key, $value) && $this->ignorablePastedStyle($key, $value)) {
                    unset($attributes[$key]);
                }
            }
        }
        foreach ($attributes as $key => &$value) {
            $valid = match ($key) {
                'level' => is_int($value) && $value >= 1 && $value <= 6,
                'start' => is_int($value) && $value >= 1 && $value <= 100000,
                'type' => in_array($value, ['1', 'a', 'A', 'i', 'I'], true),
                'checked' => is_bool($value),
                'textAlign', 'align' => in_array($value, $type === 'image' ? ['left', 'center', 'right'] : ['left', 'center', 'right', 'justify'], true),
                'layout' => in_array($value, ['block', 'wrap-left', 'wrap-right'], true),
                'width', 'height', 'colspan', 'rowspan' => filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= 1 && $value <= (in_array($key, ['width', 'height']) ? 4096 : 100),
                'colwidth' => is_array($value) && array_is_list($value) && count($value) <= 100 && ! array_filter($value, fn ($width) => ! is_int($width) || $width < 0 || $width > 4096),
                'src' => is_string($value) && $this->safeUrl($value, true),
                'href' => is_string($value) && $this->safeUrl($value),
                'alt', 'title' => is_string($value) && mb_strlen($value) <= 1000,
                'resourceId' => is_string($value) && Str::isUuid($value),
                'url' => is_string($value) && VideoEmbedUrl::normalize($value) !== null,
                'language' => is_string($value) && preg_match('/^[\w+#.-]{0,50}$/D', $value),
                'color', 'backgroundColor', 'fontSize', 'lineHeight', 'fontFamily' => $this->validStyleValue($key, $value),
                'target' => in_array($value, ['_blank', '_self'], true),
                'rel', 'class' => is_string($value),
                default => false,
            };
            if (! $valid) {
                $fail();
            }
            if (in_array($key, ['width', 'height', 'colspan', 'rowspan'])) {
                $value = (int) $value;
            }
        }
        unset($value);
        if ((in_array($type, ['image', 'inlineImage'], true) && empty($attributes['src'])) || ($type === 'link' && empty($attributes['href']))) {
            $fail();
        }
        if ($type === 'link') {
            unset($attributes['class']);
            $attributes['rel'] = 'nofollow noopener noreferrer';
        }
        if ($type === 'resourceLink' && empty($attributes['resourceId'])) {
            $fail();
        }
        if ($type === 'videoEmbed') {
            if (empty($attributes['url'])) {
                $fail();
            }
            $attributes['url'] = VideoEmbedUrl::normalize($attributes['url']);
        }

        return $attributes;
    }

    private function validStyleValue(string $key, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return match ($key) {
            'color', 'backgroundColor' => (bool) (preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/iD', $value) || preg_match('/^rgb\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}\s*\)$/D', $value)),
            'fontSize' => (bool) preg_match('/^(?:[89]|[1-6][0-9]|7[0-2])px$/D', $value),
            'lineHeight' => in_array($value, ['1', '1.25', '1.5', '1.75', '2'], true),
            'fontFamily' => in_array($value, ['Arial', 'Georgia', 'Verdana', 'monospace'], true),
            default => false,
        };
    }

    private function ignorablePastedStyle(string $key, mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 1000) {
            return false;
        }
        if ($value === '' || in_array($value, ['normal', 'inherit', 'initial', 'unset', 'revert'], true)) {
            return true;
        }

        return (bool) match ($key) {
            'fontFamily' => preg_match('/^[\p{L}\p{N}\s,\'"-]+$/uD', $value),
            'fontSize', 'lineHeight' => preg_match('/^\d+(?:\.\d+)?(?:px|pt|em|rem|%)?$/D', $value),
            'color', 'backgroundColor' => preg_match('/^(?:[a-z]+|#[a-f0-9]{4}|#[a-f0-9]{8}|(?:rgba?|hsla?|oklab|oklch|lab|lch)\([\d\s.,%+\/-]+\)|var\(--[a-z0-9_-]+\))$/iD', $value),
            default => false,
        };
    }

    private function validateTable(array $rows, \Closure $fail): void
    {
        if (count($rows) > 200) {
            $fail();
        }
        $grid = [];
        $width = null;
        foreach ($rows as $rowIndex => $row) {
            $column = 0;
            foreach ($row['content'] ?? [] as $cell) {
                while (isset($grid[$rowIndex][$column])) {
                    $column++;
                }
                $colspan = $cell['attrs']['colspan'] ?? 1;
                $rowspan = $cell['attrs']['rowspan'] ?? 1;
                if ($column + $colspan > 100 || $rowIndex + $rowspan > count($rows) || (isset($cell['attrs']['colwidth']) && count($cell['attrs']['colwidth']) !== $colspan)) {
                    $fail();
                }
                for ($r = $rowIndex; $r < $rowIndex + $rowspan; $r++) {
                    for ($c = $column; $c < $column + $colspan; $c++) {
                        if (isset($grid[$r][$c])) {
                            $fail();
                        }
                        $grid[$r][$c] = true;
                    }
                }
                $column += $colspan;
            }
            $rowWidth = count($grid[$rowIndex] ?? []);
            $width ??= $rowWidth;
            if ($rowWidth === 0 || $rowWidth !== $width) {
                $fail();
            }
        }
    }

    public function safeUrl(string $url, bool $image = false): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        if (preg_match('~^/(?!/)~', $url) || (! $image && str_starts_with($url, '#'))) {
            return true;
        }

        return (bool) filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), $image ? ['https', 'http'] : ['https', 'http', 'mailto'], true);
    }

    public function html(array $document, bool $resourceBlocks = false): string
    {
        // Only normalized allowlisted nodes/attributes reach the renderer.
        return $this->editor()->setContent($this->validate($document, resourceBlocks: $resourceBlocks))->getHTML();
    }

    public function text(array $document): string
    {
        $walk = function (array $node) use (&$walk): string {
            if (($node['type'] ?? '') === 'text') {
                return $node['text'];
            }
            if (($node['type'] ?? '') === 'hardBreak') {
                return "\n";
            }
            if (($node['type'] ?? '') === 'videoEmbed') {
                return $node['attrs']['title'] ?? '';
            }
            $separator = in_array($node['type'], ['paragraph', 'heading', 'codeBlock']) ? '' : "\n";

            return implode($separator, array_map($walk, $node['content'] ?? []));
        };

        return $walk($document);
    }

    public function imageUrls(array $document): array
    {
        $urls = [];
        $walk = function (array $node) use (&$walk, &$urls): void {
            if (in_array($node['type'] ?? '', ['image', 'inlineImage'], true)) {
                $urls[] = $node['attrs']['src'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_values(array_unique($urls));
    }
}
