<?php

namespace App\Services\Groups;

use App\Http\Resources\ActivityManagementWarningResource;
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
        private readonly ActivityManagementWarningService $managementWarningService,
        private readonly ActivityRosterLock $rosterLock,
    ) {}

    /**
     * @return array{slot: array<string, mixed>|null, pending_application_count: int}
     */
    public function withdraw(ActivityApplication $application, mixed $actor): array
    {
        return $this->rosterLock->run((int) $application->activity_id, function () use ($application, $actor): array {
            $application->setRelations([]);
            $application->refresh();

            return $this->withdrawCurrentApplication($application, $actor);
        });
    }

    private function withdrawCurrentApplication(ActivityApplication $application, mixed $actor): array
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
        $characterId = $assignedSlot?->assigned_character_id
            ? (int) $assignedSlot->assigned_character_id
            : ($application->selected_character_id ? (int) $application->selected_character_id : null);
        $managementWarning = null;

        DB::transaction(function () use ($application, $assignedSlot, $activity, $characterId, &$managementWarning): void {
            if ($assignedSlot) {
                if ($assignedSlot->is_raid_leader) {
                    $managementWarning = $this->managementWarningService->createRaidLeaderWithdrawal($application, $assignedSlot);
                }

                $assignedSlot->update([
                    'assigned_character_id' => null,
                    'assigned_by_user_id' => null,
                    'application_review_required_application_id' => null,
                    'application_review_required_at' => null,
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

        if ($managementWarning) {
            $patch['upsert_management_warnings'] = [
                ActivityManagementWarningResource::make($managementWarning)->resolve(),
            ];
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
        if (! $application->activity) {
            return null;
        }

        $flaggedSlot = $application->activity
            ->slots()
            ->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->where('application_review_required_application_id', $application->id)
            ->first();

        if ($flaggedSlot || ! $application->selected_character_id) {
            return $flaggedSlot;
        }

        return $application->activity
            ->slots()
            ->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->where('assigned_character_id', $application->selected_character_id)
            ->first();
    }
}
