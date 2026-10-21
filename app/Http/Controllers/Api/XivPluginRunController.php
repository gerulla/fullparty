<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginRunResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Services\Runs\RunAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginRunController extends Controller
{
    public function show(Request $request, Activity $activity, RunAccessService $access): JsonResponse
    {
        $user = $request->user();

        $activity->loadMissing([
            'group.memberships',
            'activityTypeVersion',
            'slots.assignedCharacter.user',
            'slots.fieldValues',
            'slots.assignments' => fn ($query) => $query
                ->whereNull('ended_at')
                ->latest('assigned_at'),
        ]);

        $group = $activity->group;

        abort_unless($access->canView($group, $activity, $user->id), 404);
        abort_unless($group->hasMember($user->id), 404);

        $canModerate = $group->hasModeratorAccess($user->id);

        abort_if($activity->status === Activity::STATUS_DRAFT && ! $canModerate, 404);
        abort_if($activity->isArchived(), 404);
        abort_if(
            $activity->status !== Activity::STATUS_DRAFT
            && ($activity->starts_at === null || $activity->starts_at->lt(now()->subHours(6))),
            404
        );

        $activity->loadCount([
            'applications as active_application_count' => fn ($query) => $query->whereIn('status', ActivityApplication::ACTIVE_STATUSES),
        ]);

        return (new XivPluginRunResource($activity, $canModerate, includeRoster: true))->response();
    }
}
