<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Services\Notifications\ApplicationNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityApplicationWithdrawalService
{
    public function __construct(
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly ActivitySlotDesignationService $slotDesignationService,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly ApplicationNotificationService $applicationNotificationService,
        private readonly ActivitySlotSerializer $slotSerializer,
        private readonly ActivityManagementRealtimeService $activityManagementRealtimeService,
    ) {}

    /**
     * @return array{slot: array<string, mixed>|null, pending_application_count: int}
     */
    public function withdraw(ActivityApplication $application, mixed $actor): array
    {
        $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);

        $activity = $application->activity;

        if (! $activity instanceof Activity || ! $this->applicationCanBeWithdrawn($activity, $application)) {
            throw ValidationException::withMessages([
                'application' => 'This application cannot be withdrawn.',
            ]);
        }

        $assignedSlot = $this->findAssignedSlot($application);
        $previousStatus = $application->status;
        $characterId = $application->selected_character_id ? (int) $application->selected_character_id : null;

        DB::transaction(function () use ($application, $assignedSlot, $activity, $characterId): void {
            if ($assignedSlot) {
                $assignedSlot->update([
                    'assigned_character_id' => null,
                    'assigned_by_user_id' => null,
                ]);

                foreach ($assignedSlot->fieldValues as $fieldValue) {
                    $fieldValue->update([
                        'value' => null,
                    ]);
                }
            }

            $application->update([
                'status' => ActivityApplication::STATUS_WITHDRAWN,
                'guest_access_token' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => now(),
                'review_reason' => null,
            ]);

            if ($characterId !== null) {
                $this->attendanceService->endActiveAssignment($activity, $characterId);
            }
        });

        $serializedSlot = null;

        if ($assignedSlot) {
            $this->slotDesignationService->clearInvalidDesignations([$assignedSlot], $actor);
            $assignedSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);
            $serializedSlot = $this->slotSerializer->serialize($assignedSlot);
        }

        $this->activityAuditService->logApplicationWithdrawn(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            $actor,
        );

        $updatedApplication = $application->fresh(['activity.group', 'selectedCharacter', 'user']);

        if ($updatedApplication) {
            $this->applicationNotificationService->notifyWithdrawn($updatedApplication, $actor);
        }

        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();

        $patch = [
            'pending_application_count' => $pendingApplicationCount,
            'queue_change_reason' => 'application_withdrawn',
            'queue_application_sync_ids' => [],
            'queue_application_remove_ids' => $previousStatus === ActivityApplication::STATUS_PENDING
                ? [(int) $application->id]
                : [],
            'queue_withdrawn_application_names' => $previousStatus === ActivityApplication::STATUS_PENDING && $updatedApplication
                ? [$this->applicationDisplayName($updatedApplication)]
                : [],
        ];

        if ($serializedSlot) {
            $patch['updated_slots'] = [$serializedSlot];
        }

        $this->activityManagementRealtimeService->broadcastPatch($activity, $patch);

        return [
            'slot' => $serializedSlot,
            'pending_application_count' => $pendingApplicationCount,
        ];
    }

    public function applicationCanBeWithdrawn(Activity $activity, ActivityApplication $application): bool
    {
        return $activity->needs_application
            && ! Activity::isArchivedStatus($activity->status)
            && in_array($application->status, ActivityApplication::WITHDRAWABLE_STATUSES, true);
    }

    public function applicationIsRostered(ActivityApplication $application): bool
    {
        return $this->findAssignedSlot($application) instanceof ActivitySlot;
    }

    private function applicationDisplayName(ActivityApplication $application): string
    {
        return $application->applicant_character_name
            ?: $application->selectedCharacter?->name
            ?: $application->user?->name
            ?: 'Applicant';
    }

    private function findAssignedSlot(ActivityApplication $application): ?ActivitySlot
    {
        if (! $application->selected_character_id || ! $application->activity) {
            return null;
        }

        return $application->activity
            ->slots()
            ->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->where('assigned_character_id', $application->selected_character_id)
            ->first();
    }
}
