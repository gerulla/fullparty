<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Services\Groups\ActivityFillInSlotService;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\AssignmentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotUnassignmentController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        GroupActivityAuditService $activityAuditService,
        ActivityFillInSlotService $fillInSlotService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivitySlotDesignationService $slotDesignationService,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
        AssignmentNotificationService $assignmentNotificationService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot have roster assignments removed.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (! $slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only filled roster slots can be unassigned.',
            ]);
        }

        $validated = $request->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);
        $isFillInSlot = $slot->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN;

        $activeAssignment = ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('activity_slot_id', $slot->id)
            ->where('character_id', $slot->assigned_character_id)
            ->whereNull('ended_at')
            ->latest('assigned_at')
            ->first();

        $isManualAssignment = $activeAssignment && $activeAssignment->application_id === null;

        if ($isManualAssignment) {
            $slotCharacterId = (int) $slot->assigned_character_id;
            $removedCharacter = $slot->assignedCharacter?->loadMissing('user');
            $slotCharacterName = $removedCharacter?->name;

            DB::transaction(function () use ($slot, $activity, $attendanceService, $slotCharacterId) {
                $slot->update([
                    'assigned_character_id' => null,
                    'assigned_by_user_id' => null,
                ]);

                foreach ($slot->fieldValues as $fieldValue) {
                    $fieldValue->update([
                        'value' => null,
                    ]);
                }

                $attendanceService->endActiveAssignment($activity, $slotCharacterId);
            });

            $slotDesignationService->clearInvalidDesignations([$slot], auth()->user());
            $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

            $activityAuditService->logRosterEvent(
                'manual_removed',
                $slot,
                auth()->user(),
                [
                    'character_name' => $slotCharacterName,
                    'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                ],
            );

            if ($removedCharacter) {
                $assignmentNotificationService->notifyManualPlacementRemoved(
                    $activity->fresh(['group', 'activityTypeVersion', 'activityType']) ?? $activity,
                    $removedCharacter,
                    $slot,
                    auth()->user(),
                );
            }

            $pendingApplicationCount = $activity->applications()
                ->where('status', ActivityApplication::STATUS_PENDING)
                ->count();
            $serializedSlot = $isFillInSlot ? null : $slotSerializer->serialize($slot);
            $removedSlotIds = $isFillInSlot ? [(int) $slot->id] : [];

            if ($isFillInSlot) {
                $fillInSlotService->delete($activity, $slot);
            }

            $patch = [
                'pending_application_count' => $pendingApplicationCount,
            ];

            if ($serializedSlot) {
                $patch['updated_slots'] = [$serializedSlot];
            }

            if ($removedSlotIds !== []) {
                $patch['removed_slot_ids'] = $removedSlotIds;
            }

            $activityManagementRealtimeService->broadcastPatch($activity, $patch);

            return response()->json([
                'slot' => $serializedSlot,
                'removed_slot_ids' => $removedSlotIds,
                'application' => null,
                'pending_application_count' => $pendingApplicationCount,
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

        if (! $application) {
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
                'review_reason' => null,
            ]);

            if ($application->selected_character_id) {
                $attendanceService->endActiveAssignment(
                    $activity,
                    (int) $application->selected_character_id,
                );
            }
        });

        $slotDesignationService->clearInvalidDesignations([$slot], auth()->user());
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

        if ($activity->status === Activity::STATUS_ASSIGNED) {
            $assignmentNotificationService->notifyPlacementChanged(
                $application->fresh(['activity.group', 'user', 'selectedCharacter']),
                null,
                auth()->user(),
            );
        }

        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();
        $serializedApplication = $queuePayloadBuilder->serializeApplicationForModerator(
            $application,
            $activity->activityTypeVersion,
            $activity->group,
            (int) auth()->id(),
        );
        $serializedSlot = $isFillInSlot ? null : $slotSerializer->serialize($slot);
        $removedSlotIds = $isFillInSlot ? [(int) $slot->id] : [];

        if ($isFillInSlot) {
            $fillInSlotService->delete($activity, $slot);
        }

        $patch = [
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_sync_ids' => [(int) $application->id],
            'queue_application_remove_ids' => [],
        ];

        if ($serializedSlot) {
            $patch['updated_slots'] = [$serializedSlot];
        }

        if ($removedSlotIds !== []) {
            $patch['removed_slot_ids'] = $removedSlotIds;
        }

        $activityManagementRealtimeService->broadcastPatch($activity, $patch);

        return response()->json([
            'slot' => $serializedSlot,
            'removed_slot_ids' => $removedSlotIds,
            'application' => $serializedApplication,
            'pending_application_count' => $pendingApplicationCount,
        ]);
    }
}
