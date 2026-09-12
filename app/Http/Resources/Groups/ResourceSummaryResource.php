<?php

namespace App\Http\Resources\Groups;

use App\Services\Groups\Resources\ResourceHolsterContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = app(ResourceHolsterContent::class)->inherit($this->resource, $this->publishedRevision?->snapshot ?? []);

        return [
            'id' => $this->id, 'slug' => $this->uuid, 'collection_id' => $this->collection_id,
            'is_home' => $this->is_home,
            ...($this->holster_id ? ['source_type' => 'holster', 'holster_id' => $this->holster_id] : []),
            'title' => $snapshot['title'] ?? '', 'description' => $snapshot['description'] ?? '',
            'tags' => $snapshot['tags'] ?? [], 'activity_type_ids' => $snapshot['activity_type_ids'] ?? [],
            'metadata_image_id' => $snapshot['metadata_image_id'] ?? null,
            'author' => $snapshot['author'] ?? null, 'access_level' => $this->access_level,
            'sort_order' => $this->sort_order, 'is_pinned' => $this->is_pinned,
            'created_at' => $this->created_at?->toIso8601String(), 'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
