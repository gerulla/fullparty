<?php

namespace App\Http\Controllers;

use App\Http\Resources\Groups\ApplicantQueueResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;

class GroupActivitySlotAssignmentContextController extends Controller
{
    public function show(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
        ActivitySlotStateTokenService $slotStateTokenService,
    ): ApplicantQueueResource {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (! $slot->assigned_character_id) {
            abort(404);
        }

        $activeAssignment = $slotStateTokenService->resolveActiveAssignment($slot);
        $applicationQuery = $activity->applications()
            ->with(['answers', 'selectedCharacter.occultProgress', 'selectedCharacter.classes', 'selectedCharacter.phantomJobs', 'user'])
            ->whereIn('status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
                ActivityApplication::STATUS_PENDING,
            ]);

        /** @var ActivityApplication|null $application */
        $application = $activeAssignment?->application_id
            ? $applicationQuery->whereKey($activeAssignment->application_id)->first()
            : $applicationQuery
                ->where('selected_character_id', $slot->assigned_character_id)
                ->latest('reviewed_at')
                ->latest('submitted_at')
                ->first();

        if (! $application) {
            abort(404);
        }

        return new ApplicantQueueResource([
            'application' => $queuePayloadBuilder->serializeApplicationForModerator(
                $application,
                $activity->activityTypeVersion,
                $activity->group,
                (int) auth()->id(),
            ),
        ]);
    }
}
