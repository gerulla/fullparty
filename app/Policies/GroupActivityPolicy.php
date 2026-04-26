<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GroupActivityPolicy
{
    public function manageDashboard(User $user, Activity $activity, Group $group): Response
    {
        if ((int) $activity->group_id !== (int) $group->id) {
            return Response::denyAsNotFound();
        }

        if (!$group->hasModeratorAccess($user->id)) {
            return Response::deny();
        }

        return Response::allow();
    }

    public function viewCharacterFflogs(User $user, Activity $activity, Group $group, Character $character): Response
    {
        $managementAccess = $this->manageDashboard($user, $activity, $group);

        if ($managementAccess->denied()) {
            return $managementAccess;
        }

        $belongsToApplication = $activity->applications()
            ->where('selected_character_id', $character->id)
            ->exists();

        if (!$belongsToApplication) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
