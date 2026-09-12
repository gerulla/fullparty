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

    public function inherit(GroupResource $resource, ?array $snapshot): ?array
    {
        if ($snapshot === null || ! $resource->holster_id || ! $resource->holster) {
            return $snapshot;
        }
        $holster = $resource->holster;
        $body = is_array($holster->guide) ? $holster->guide : RichTextDocument::empty();

        return array_replace($snapshot, [
            'source_type' => 'holster',
            'title' => $holster->localizedName() ?? __('resource_library.untitled_holster'),
            'description' => $holster->notes ?? '',
            'body' => $body,
            'body_text' => is_string($holster->guide) ? $holster->guide : $this->documents->text($body),
            'activity_type_ids' => $this->activityIds(),
        ]);
    }
}
