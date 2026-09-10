<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\User;

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
        if ((int) $image->resource_id !== (int) $resource->id || ! $this->manage($user, $resource)) {
            return false;
        }
        $levels = $this->levels($user, $resource->group);
        if (in_array('admin', $levels, true) || ((int) $image->uploader_user_id === (int) $user->id && in_array($image->access_level, $levels, true))) {
            return true;
        }
        if (in_array($image->uuid, $resource->working_copy['image_ids'] ?? [], true)) {
            return true;
        }

        return $resource->revisions()->get(['snapshot'])->contains(fn ($revision) => in_array($revision->snapshot['access_level'], $levels, true) && in_array($image->uuid, $revision->snapshot['image_ids'] ?? [], true));
    }
}
