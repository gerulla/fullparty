<?php

namespace App\Services\Groups\Resources;

use App\Models\ActivityType;
use App\Models\GroupResource;
use App\Services\RichText\RichTextDocument;

class ResourceHolsterContent
{
    private ?array $activityIds = null;

    public function __construct(private readonly RichTextDocument $documents) {}

    public function activityIds(): array
    {
        return $this->activityIds ??= ActivityType::where('slug', 'delubrum-reginae-savage')->pluck('id')->all();
    }

    public function title(GroupResource $resource, ?string $savedTitle): ?string
    {
        if (! $resource->holster_id || ! $resource->holster) {
            return $savedTitle;
        }

        return $resource->holster->localizedName() ?? __('resource_library.untitled_holster');
    }

    public function inherit(GroupResource $resource, ?array $snapshot): ?array
    {
        if ($snapshot === null || ! $resource->holster_id || ! $resource->holster) {
            return $snapshot;
        }
        $holster = $resource->holster;
        $body = is_array($holster->guide) ? $holster->guide : RichTextDocument::empty();

        return array_replace($snapshot, [
            'source_type' => 'holster',
            'title' => $this->title($resource, $snapshot['title'] ?? null),
            'description' => $holster->notes ?? '',
            'body' => $body,
            'body_text' => is_string($holster->guide) ? $holster->guide : $this->documents->text($body),
            'activity_type_ids' => $this->activityIds(),
        ]);
    }
}
