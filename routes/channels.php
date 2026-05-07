<?php

use App\Models\Activity;
use App\Models\Group;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('groups.{groupId}.activities.{activityId}.management', function ($user, $groupId, $activityId) {
    $group = Group::query()->find($groupId);
    $activity = Activity::query()->find($activityId);

    if (!$group || !$activity || (int) $activity->group_id !== (int) $group->id) {
        return false;
    }

    return $group->hasModeratorAccess((int) $user->id);
});
