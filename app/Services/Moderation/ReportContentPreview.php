<?php

namespace App\Services\Moderation;

use App\Services\RichText\RichTextDocument;
use Illuminate\Validation\ValidationException;

class ReportContentPreview
{
    public function __construct(private readonly RichTextDocument $documents) {}

    public function build(array $snapshot, ?array $imageUrls = null): array
    {
        $content = $snapshot['content'] ?? [];
        $document = $content['body'] ?? $content['guide'] ?? null;
        $html = null;
        if (is_array($document)) {
            try {
                // Validate before rendering user content, including older retained snapshots.
                $document = $this->documents->validate($document, resourceBlocks: true);
                $walk = function (array $node) use (&$walk, $imageUrls): array {
                    if (in_array($node['type'] ?? '', ['image', 'inlineImage'], true)) {
                        $src = $node['attrs']['src'];
                        if ($imageUrls === null) {
                            $reference = ['type' => 'text', 'text' => __('reports.admin.image_reference').': '.$src];

                            return $node['type'] === 'inlineImage' ? $reference : ['type' => 'paragraph', 'content' => [$reference]];
                        }
                        $uuid = basename(parse_url($src, PHP_URL_PATH) ?? '');
                        $node['attrs']['src'] = $imageUrls[$uuid] ?? $src;
                    }
                    if (in_array($node['type'] ?? '', ['resourceLink', 'videoEmbed'], true)) {
                        $video = $node['type'] === 'videoEmbed';
                        $text = $video ? ($node['attrs']['title'] ?: $node['attrs']['url']) : __('reports.admin.linked_resource').' #'.$node['attrs']['resourceId'];

                        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text] + ($video ? ['marks' => [['type' => 'link', 'attrs' => ['href' => $node['attrs']['url']]]]] : [])]];
                    }
                    if (isset($node['content'])) {
                        $node['content'] = array_map($walk, $node['content']);
                    }

                    return $node;
                };
                $html = $this->documents->html($walk($document));
            } catch (ValidationException) {
                // Malformed legacy content must not prevent reviewing the retained evidence.
            }
        }
        $fields = [];
        foreach (['notes', 'severity', 'caption', 'alt_text', 'mime_type', 'role', 'type'] as $key) {
            if (filled($content[$key] ?? null)) {
                $fields[] = ['label' => __('reports.admin.fields.'.$key), 'value' => $this->text($content[$key])];
            }
        }
        foreach ($content['answers'] ?? [] as $key => $answer) {
            $definition = collect($content['form_snapshot'] ?? [])->firstWhere('id', $key);
            $label = $definition['name'] ?? (is_array($answer) ? ($answer['question_label'] ?? $answer['label'] ?? (string) $key) : (string) $key);
            $value = is_array($answer) ? ($answer['value'] ?? $answer) : $answer;
            if (($definition['type'] ?? null) === 'select') {
                $value = $this->localized(collect($definition['options'] ?? [])->firstWhere('id', $answer)['label'] ?? $answer);
            }
            $fields[] = ['label' => $this->localized($label), 'value' => $this->text($value)];
        }
        foreach ($content['addenda'] ?? [] as $entry) {
            $fields[] = ['label' => __('reports.admin.addendum').' · #'.($entry['author_user_id'] ?? ''), 'value' => $entry['body'] ?? ''];
        }

        return [
            'title' => $snapshot['title'] ?? '', 'description' => $html ? ($content['description'] ?? null) : null,
            'html' => $html, 'text' => $html ? null : ($snapshot['text'] ?? (is_string($document) ? $document : null)),
            'fields' => $fields, 'tags' => $content['tags'] ?? [],
        ];
    }

    private function text(mixed $value): string
    {
        if (is_bool($value)) {
            return __('reports.admin.'.($value ? 'yes' : 'no'));
        }

        return is_array($value) ? implode("\n", array_map(fn ($entry) => $this->text($entry), $value)) : (string) $value;
    }

    private function localized(mixed $value): string
    {
        return is_array($value) ? (string) ($value[app()->getLocale()] ?? $value['en'] ?? reset($value)) : (string) $value;
    }
}
