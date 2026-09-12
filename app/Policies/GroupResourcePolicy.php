<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class GroupResourcePolicy
{
    public function library(?User $user, Group $group): bool
    {
        return $user && $group->featureEnabled('resource_hub_enabled') && $group->hasMember($user->id) && ! $group->isBanned($user->id);
    }

    public function manageLibrary(User $user, Group $group): bool
    {
        return $this->library($user, $group) && $group->hasModeratorAccess($user->id);
    }

    public function configure(User $user, Group $group): bool
    {
        return $this->library($user, $group) && $group->hasAdminAccess($user->id);
    }

    public function levels(?User $user, Group $group): array
    {
        if (! $this->library($user, $group)) {
            return [];
        }

        return $group->hasAdminAccess($user->id) ? GroupResource::ACCESS_LEVELS : ($group->hasModeratorAccess($user->id) ? ['everyone', 'moderator'] : ['everyone']);
    }

    public function view(User $user, GroupResource $resource): bool
    {
        return $resource->status === 'published' && $resource->published_revision_id !== null && in_array($resource->access_level, $this->levels($user, $resource->group), true);
    }

    public function manage(User $user, GroupResource $resource): bool
    {
        return $this->manageLibrary($user, $resource->group) && in_array($resource->management_access_level, $this->levels($user, $resource->group), true);
    }

    public function useImage(User $user, GroupResourceImage $image, GroupResource $resource): bool
    {
        return (int) $image->group_id === (int) $resource->group_id && $this->manage($user, $resource)
            && $this->manageableImages($user, $resource->group)->whereKey($image->id)->exists();
    }

    public function manageableImages(User $user, Group $group): Builder
    {
        $query = GroupResourceImage::where('group_id', $group->id);
        if (! $this->manageLibrary($user, $group)) {
            return $query->whereRaw('1 = 0');
        }
        $levels = $this->levels($user, $group);
        if (in_array('admin', $levels, true)) {
            return $query;
        }
        $resources = GroupResource::where('group_id', $group->id)->whereIn('management_access_level', $levels)->get(['id', 'working_copy->image_ids as usable_image_ids']);
        $ids = $resources->modelKeys();
        $imageIds = $resources->flatMap(fn ($resource) => json_decode($resource->usable_image_ids ?? '[]', true) ?? []);
        // A resource may have been downgraded from Admin access. Only accessible revisions can grant image access.
        $revisions = GroupResourceRevision::whereIn('resource_id', $ids)->whereIn('snapshot->access_level', $levels)->get(['snapshot->image_ids as usable_image_ids']);
        $imageIds = $imageIds->merge($revisions->flatMap(fn ($revision) => json_decode($revision->usable_image_ids ?? '[]', true) ?? []))->unique()->values()->all();

        return $query->where(function (Builder $query) use ($levels, $ids, $imageIds, $user) {
            $query->where(fn (Builder $query) => $query->whereNull('resource_id')->where('library_upload', true)->whereIn('access_level', $levels))
                ->orWhere(fn (Builder $query) => $query->whereIn('resource_id', $ids)->where('uploader_user_id', $user->id)->whereIn('access_level', $levels))
                ->orWhereIn('uuid', $imageIds);
        });
    }
}
