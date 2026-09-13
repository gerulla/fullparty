<?php

namespace App\Services\Moderation;

use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Services\Groups\Resources\ResourceImageService;
use App\Services\Groups\Resources\ResourceLibraryService;
use Illuminate\Database\Eloquent\Model;

class PublicReportAccess
{
    public const TYPES = ['resource', 'holster', 'upload'];

    public function __construct(private readonly ResourceLibraryService $libraries, private readonly ResourceImageService $images) {}

    public function canReport(Model $target, Group $group): bool
    {
        if ((int) $target->group_id !== $group->id || $target->moderation_hidden_at || ! $this->libraries->isPublic($group)) {
            return false;
        }

        return match (true) {
            $target instanceof GroupResource => $this->libraries->publicUrl($target) !== null
                && GroupResource::whereKey($target->id)->withAvailableSource()->exists(),
            $target instanceof BozjaHolster => $target->is_active && GroupResource::where('group_id', $group->id)
                ->where('holster_id', $target->id)->withAvailableSource()->where('status', 'published')
                ->where('access_level', 'everyone')->whereNotNull('published_revision_id')->exists(),
            $target instanceof GroupResourceImage => $this->images->canRead($target, null, true),
            default => false,
        };
    }
}
