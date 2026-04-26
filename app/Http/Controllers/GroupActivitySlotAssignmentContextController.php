<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;

class GroupActivitySlotAssignmentContextController extends Controller
{
    public function show(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (!$slot->assigned_character_id) {
            abort(404);
        }

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with(['answers', 'selectedCharacter.occultProgress', 'selectedCharacter.phantomJobs', 'user'])
            ->where('selected_character_id', $slot->assigned_character_id)
            ->whereIn('status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_PENDING,
            ])
            ->latest('reviewed_at')
            ->latest('submitted_at')
            ->first();

        if (!$application) {
            abort(404);
        }

        return response()->json([
            'application' => $queuePayloadBuilder->serializeApplication($application, $activity->activityTypeVersion),
        ]);
    }
}
