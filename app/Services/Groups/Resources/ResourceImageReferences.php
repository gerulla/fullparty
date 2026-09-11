<?php

namespace App\Services\Groups\Resources;

use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use Illuminate\Database\Eloquent\Builder;

class ResourceImageReferences
{
    public function resources(GroupResourceImage $image): Builder
    {
        return GroupResource::where('group_id', $image->group_id)->where(function (Builder $query) use ($image) {
            $query->whereJsonContains('working_copy->image_ids', $image->uuid)
                ->orWhereHas('revisions', fn (Builder $revisions) => $revisions->whereJsonContains('snapshot->image_ids', $image->uuid));
        });
    }

    public function branding(GroupResourceImage $image, GroupResourceLibrary $library): bool
    {
        return in_array($image->uuid, array_intersect_key($library->customization ?? [], array_flip(['banner_image_id', 'logo_image_id', 'sharing_image_id'])), true);
    }

    public function inUse(GroupResourceImage $image, GroupResourceLibrary $library): bool
    {
        return $this->branding($image, $library) || $image->resource?->editing_expires_at?->isFuture()
            || $this->resources($image)->exists();
    }
}
