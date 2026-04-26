<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotUnassignmentController extends Controller
{
    public function store(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotAttendanceService $attendanceService,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->status === Activity::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'activity' => 'Completed activities cannot be moved back into the applicant queue.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (!$slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only filled roster slots can be returned to the queue.',
            ]);
        }

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with(['answers', 'selectedCharacter.occultProgress', 'selectedCharacter.phantomJobs', 'user'])
            ->where('selected_character_id', $slot->assigned_character_id)
            ->whereIn('status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->latest('reviewed_at')
            ->first();

        if (!$application) {
            throw ValidationException::withMessages([
                'slot' => 'No assigned application could be found for this roster assignment.',
            ]);
        }

        $slotCharacterName = $slot->assignedCharacter?->name ?? $application->selectedCharacter?->name;

        DB::transaction(function () use ($slot, $application, $activity, $attendanceService) {
            $slot->update([
                'assigned_character_id' => null,
                'assigned_by_user_id' => null,
            ]);

            foreach ($slot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => null,
                ]);
            }

            $application->update([
                'status' => ActivityApplication::STATUS_PENDING,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);

            if ($application->selected_character_id) {
                $attendanceService->endActiveAssignment(
                    $activity,
                    (int) $application->selected_character_id,
                );
            }
        });

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logRosterEvent(
            'returned_to_queue',
            $slot,
            auth()->user(),
            [
                'character_name' => $slotCharacterName,
                'application_status' => $application->status,
            ],
        );

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
            'application' => $queuePayloadBuilder->serializeApplication($application, $activity->activityTypeVersion),
        ]);
    }
}
