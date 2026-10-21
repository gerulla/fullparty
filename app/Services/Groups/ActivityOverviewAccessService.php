<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\Group;

final class ActivityOverviewAccessService
{
    public function allows(Group $group, Activity $activity, ?int $userId): bool
    {
        if ($group->isBanned($userId)) {
            return false;
        }
        $organizer = $userId !== null && (int) $activity->organized_by_user_id === $userId;
        if (Activity::isModeratorOnlyStatus($activity->status)) {
            return $group->hasModeratorAccess($userId) || $organizer;
        }

        return $group->is_visible || $group->hasMember($userId)
            || $group->hasModeratorAccess($userId) || $organizer;
    }
}
