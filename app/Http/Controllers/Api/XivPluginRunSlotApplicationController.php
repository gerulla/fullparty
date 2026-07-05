<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginRunApplicationResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginRunSlotApplicationController extends Controller
{
    public function show(
        Request $request,
        Activity $activity,
        ActivitySlot $slot,
        ApplicantQueuePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($slot->activity_id === $activity->id, 404);

        $activity->loadMissing(['group.memberships', 'activityTypeVersion']);
        $group = $activity->group;

        abort_unless($group->hasModeratorAccess($user->id), 404);
        abort_if($activity->isArchived(), 404);
        abort_if(
            $activity->status !== Activity::STATUS_DRAFT
            && ($activity->starts_at === null || $activity->starts_at->lt(now()->subHours(6))),
            404
        );

        $assignment = $slot->assignments()
            ->whereNull('ended_at')
            ->whereNotNull('application_id')
            ->latest('assigned_at')
            ->firstOrFail();

        $application = ActivityApplication::query()
            ->with([
                'answers',
                'selectedCharacter.occultProgress',
                'selectedCharacter.classes',
                'selectedCharacter.phantomJobs',
                'user',
            ])
            ->whereKey($assignment->application_id)
            ->where('activity_id', $activity->id)
            ->firstOrFail();

        $application->setRelation('activity', $activity);

        $details = $payloadBuilder->serializeApplicationForModerator(
            $application,
            $activity->activityTypeVersion,
            $group,
            $user->id,
        );

        return (new XivPluginRunApplicationResource($application, $slot, $details))->response();
    }
}
