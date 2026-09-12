<?php

namespace App\Services\RichText;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;
use Tiptap\Utils\InlineStyle;

class MarkdownGuideConverter
{
    public function __construct(private readonly RichTextDocument $documents) {}

    public function legacyHtml(string $markdown): string
    {
        return (string) (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 32,
        ]))->convert($markdown);
    }

    public function convert(?string $markdown, int $maxText = 50000): array
    {
        if (trim($markdown ?? '') === '') {
            return RichTextDocument::empty();
        }
        $html = $this->legacyHtml($markdown);
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $checked = [];
        foreach ($xpath->query('//td[@align] | //th[@align]') as $cell) {
            $paragraph = $dom->createElement('p');
            $paragraph->setAttribute('style', 'text-align: '.$cell->getAttribute('align'));
            while ($cell->firstChild) {
                $paragraph->appendChild($cell->firstChild);
            }
            $cell->appendChild($paragraph);
        }
        foreach ($xpath->query('//input[@type="checkbox"]') as $checkbox) {
            $item = $checkbox->parentNode;
            if ($item->nodeName === 'p') {
                $item = $item->parentNode;
            }
            if (! $item instanceof DOMElement || $item->nodeName !== 'li') {
                throw new RuntimeException('Unsupported checklist structure.');
            }
            $checked[] = $checkbox->hasAttribute('checked');
            $item->setAttribute('data-type', 'taskItem');
            $item->parentNode->setAttribute('data-type', 'taskList');
            $checkbox->parentNode->removeChild($checkbox);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $document = $this->documents->editor()->setContent($dom->saveHTML($body))->getDocument();
        $index = 0;
        $walk = function (array &$node) use (&$walk, &$index, $checked): void {
            if (($node['type'] ?? '') === 'taskItem') {
                $node['attrs']['checked'] = $checked[$index++] ?? false;
            }
            foreach ($node['content'] ?? [] as $key => $child) {
                $walk($node['content'][$key]);
            }
        };
        $walk($document);
        $document = $this->documents->validate($this->normalize($document), 'guide', $maxText);
        $rendered = $this->documents->html($document);
        if ($this->fingerprint($html) !== $this->fingerprint($rendered)) {
            throw new RuntimeException('Conversion would change document text, formatting, links, images, or tables.');
        }

        return $document;
    }

    private function normalize(array $node): array
    {
        if (! isset($node['content'])) {
            return $node;
        }
        $children = array_map($this->normalize(...), $node['content']);
        if (in_array($node['type'], ['doc', 'listItem', 'taskItem', 'tableCell', 'tableHeader', 'blockquote'])) {
            // The PHP HTML parser preserves tight-list/cell text without ProseMirror's required paragraphs.
            $blocks = [];
            $inline = [];
            $flush = function () use (&$blocks, &$inline): void {
                if ($inline) {
                    $blocks[] = ['type' => 'paragraph', 'content' => $inline];
                    $inline = [];
                }
            };
            foreach ($children as $child) {
                if (in_array($child['type'], ['text', 'hardBreak'])) {
                    $inline[] = $child;

                    continue;
                }
                $flush();
                if (in_array($child['type'], ['paragraph', 'heading']) && collect($child['content'] ?? [])->contains(fn ($item) => $item['type'] === 'image')) {
                    $part = [];
                    foreach ($child['content'] as $item) {
                        if ($item['type'] === 'image') {
                            if ($part) {
                                $blocks[] = array_replace($child, ['content' => $part]);
                                $part = [];
                            }
                            $blocks[] = $item;
                        } else {
                            $part[] = $item;
                        }
                    }
                    if ($part) {
                        $blocks[] = array_replace($child, ['content' => $part]);
                    }
                } else {
                    $blocks[] = $child;
                }
            }
            $flush();
            if (! $blocks || (in_array($node['type'], ['listItem', 'taskItem']) && $blocks[0]['type'] !== 'paragraph')) {
                array_unshift($blocks, ['type' => 'paragraph']);
            }
            $children = $blocks;
        }
        $node['content'] = $children;

        return $node;
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private function fingerprint(string $html): array
    {
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $result = ['text' => preg_replace('/\s+/u', '', $dom->getElementsByTagName('body')->item(0)->textContent)];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 's', 'blockquote', 'pre', 'code', 'hr', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th'] as $tag) {
            $result[$tag] = $dom->getElementsByTagName($tag)->length;
        }
        $result['s'] += $dom->getElementsByTagName('del')->length;
        $result['code_text'] = [];
        foreach ($dom->getElementsByTagName('code') as $code) {
            $result['code_text'][] = $code->textContent;
        }
        $result['ordered_starts'] = [];
        foreach ($dom->getElementsByTagName('ol') as $list) {
            $result['ordered_starts'][] = (int) ($list->getAttribute('start') ?: 1);
        }
        $result['cell_alignment'] = [];
        foreach ($xpath->query('//td | //th') as $cell) {
            $alignment = $cell->getAttribute('align');
            if (! $alignment && $cell->firstChild instanceof DOMElement) {
                $alignment = InlineStyle::getAttribute($cell->firstChild, 'text-align') ?? '';
            }
            $result['cell_alignment'][] = $alignment;
        }
        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attribute) {
            $result[$tag] = [];
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $result[$tag][] = [$node->getAttribute($attribute), $node->getAttribute('title'), $tag === 'img' ? $node->getAttribute('alt') : $node->textContent];
            }
        }
        $result['tasks'] = [];
        foreach ($xpath->query('//input[@type="checkbox"]') as $input) {
            $result['tasks'][] = $input->hasAttribute('checked');
        }

        return $result;
    }
}
