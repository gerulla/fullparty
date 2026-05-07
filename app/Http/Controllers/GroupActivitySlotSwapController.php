<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\AssignmentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotSwapController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotBench $slotBench,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        AssignmentNotificationService $assignmentNotificationService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            abort(403);
        }

        $validated = $request->validate([
            'source_slot_id' => ['required', 'integer'],
            'target_slot_id' => ['required', 'integer', 'different:source_slot_id'],
            'expected_source_slot_state_token' => ['required', 'string'],
            'expected_target_slot_state_token' => ['required', 'string'],
        ]);

        $slots = $activity->slots()
            ->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->whereIn('id', [
                (int) $validated['source_slot_id'],
                (int) $validated['target_slot_id'],
            ])
            ->get()
            ->keyBy('id');

        /** @var ActivitySlot|null $sourceSlot */
        $sourceSlot = $slots->get((int) $validated['source_slot_id']);
        /** @var ActivitySlot|null $targetSlot */
        $targetSlot = $slots->get((int) $validated['target_slot_id']);

        if (!$sourceSlot || !$targetSlot) {
            abort(404);
        }

        $slotStateTokenService->assertMatches($sourceSlot, $validated['expected_source_slot_state_token']);
        $slotStateTokenService->assertMatches($targetSlot, $validated['expected_target_slot_state_token']);

        if (!$sourceSlot->assigned_character_id) {
            throw ValidationException::withMessages([
                'source_slot_id' => 'Only filled slots can be dragged.',
            ]);
        }

        $sourceIsBench = $slotBench->isBench($sourceSlot);
        $targetIsBench = $slotBench->isBench($targetSlot);
        $sourceCharacterName = $sourceSlot->assignedCharacter?->name;
        $targetCharacterName = $targetSlot->assignedCharacter?->name;
        $involvedCharacterIds = array_values(array_filter([
            $sourceSlot->assigned_character_id,
            $targetSlot->assigned_character_id,
        ]));

        if ($sourceIsBench && !$targetIsBench) {
            throw ValidationException::withMessages([
                'target_slot_id' => 'Moving a bench player onto the main roster requires reassignment so slot fields can be chosen.',
            ]);
        }

        if (!$sourceIsBench && $targetIsBench && $targetSlot->assigned_character_id) {
            throw ValidationException::withMessages([
                'target_slot_id' => 'Replacing a benched player from the main roster requires reassignment so the promoted player can be configured.',
            ]);
        }

        DB::transaction(function () use ($sourceSlot, $targetSlot, $sourceIsBench, $targetIsBench, $attendanceService) {
            $sourceAssignment = [
                'assigned_character_id' => $sourceSlot->assigned_character_id,
                'assigned_by_user_id' => $sourceSlot->assigned_by_user_id,
            ];
            $targetAssignment = [
                'assigned_character_id' => $targetSlot->assigned_character_id,
                'assigned_by_user_id' => $targetSlot->assigned_by_user_id,
            ];

            $sourceFieldValues = $sourceSlot->fieldValues
                ->mapWithKeys(fn ($fieldValue) => [$fieldValue->field_key => $fieldValue->value])
                ->all();
            $targetFieldValues = $targetSlot->fieldValues
                ->mapWithKeys(fn ($fieldValue) => [$fieldValue->field_key => $fieldValue->value])
                ->all();

            $sourceSlot->update($targetAssignment);
            $targetSlot->update($sourceAssignment);

            $this->syncFieldValues($sourceSlot, $targetFieldValues, $sourceIsBench, $targetIsBench);
            $this->syncFieldValues($targetSlot, $sourceFieldValues, $targetIsBench, $sourceIsBench);
            $this->syncApplicationStatuses($sourceSlot, $targetSlot, $sourceAssignment['assigned_character_id'], $targetAssignment['assigned_character_id'], $sourceIsBench, $targetIsBench);
            $applicationsByCharacter = $sourceSlot->activity?->applications()
                ->whereIn('selected_character_id', array_filter([
                    $sourceAssignment['assigned_character_id'],
                    $targetAssignment['assigned_character_id'],
                ]))
                ->get()
                ->keyBy('selected_character_id') ?? collect();

            $attendanceService->syncSwappedAssignments(
                $sourceSlot,
                $targetSlot,
                $sourceAssignment['assigned_character_id'],
                $targetAssignment['assigned_character_id'],
                $applicationsByCharacter,
            );
        });

        $sourceSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);
        $targetSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logRosterEvent(
            'swapped',
            $targetSlot,
            $request->user(),
            [
                'source_slot_label' => $sourceSlot->slot_label['en'] ?? $sourceSlot->slot_key,
                'source_group_label' => $sourceSlot->group_label['en'] ?? $sourceSlot->group_key,
                'source_character_name' => $sourceCharacterName,
                'target_slot_label' => $targetSlot->slot_label['en'] ?? $targetSlot->slot_key,
                'target_group_label' => $targetSlot->group_label['en'] ?? $targetSlot->group_key,
                'target_character_name' => $targetCharacterName,
            ],
        );

        if ($activity->status === Activity::STATUS_ASSIGNED && $involvedCharacterIds !== []) {
            $applicationsByCharacter = $activity->applications()
                ->with(['activity.group', 'user', 'selectedCharacter'])
                ->whereIn('selected_character_id', $involvedCharacterIds)
                ->whereIn('status', [
                    ActivityApplication::STATUS_APPROVED,
                    ActivityApplication::STATUS_ON_BENCH,
                ])
                ->get()
                ->keyBy('selected_character_id');

            foreach ([$sourceSlot, $targetSlot] as $slot) {
                if (!$slot->assigned_character_id) {
                    continue;
                }

                $application = $applicationsByCharacter->get($slot->assigned_character_id);

                if ($application) {
                    $assignmentNotificationService->notifyPlacementChanged(
                        $application,
                        $slot,
                        $request->user(),
                    );

                    continue;
                }

                if ($slot->assignedCharacter) {
                    $assignmentNotificationService->notifyManualPlacementChanged(
                        $activity,
                        $slot->assignedCharacter,
                        $slot,
                        $request->user(),
                    );
                }
            }
        }

        $serializedSlots = [
            $slotSerializer->serialize($sourceSlot),
            $slotSerializer->serialize($targetSlot),
        ];

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $serializedSlots,
        ]);

        return response()->json([
            'slots' => $serializedSlots,
        ]);
    }

    /**
     * @param  array<string, mixed>  $incomingValues
     */
    private function syncFieldValues(ActivitySlot $slot, array $incomingValues, bool $slotIsBench, bool $incomingIsBench): void
    {
        foreach ($slot->fieldValues as $fieldValue) {
            $fieldValue->update([
                'value' => $slotIsBench || $incomingIsBench
                    ? null
                    : ($incomingValues[$fieldValue->field_key] ?? null),
            ]);
        }
    }

    private function syncApplicationStatuses(
        ActivitySlot $sourceSlot,
        ActivitySlot $targetSlot,
        ?int $sourceCharacterId,
        ?int $targetCharacterId,
        bool $sourceIsBench,
        bool $targetIsBench,
    ): void {
        $activity = $sourceSlot->activity;

        if (!$activity) {
            return;
        }

        $applicationsByCharacter = $activity->applications()
            ->whereIn('selected_character_id', array_filter([$sourceCharacterId, $targetCharacterId]))
            ->whereIn('status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->get()
            ->groupBy('selected_character_id')
            ->map(fn ($applications) => $applications->sortByDesc('reviewed_at')->first());

        if ($sourceCharacterId) {
            $sourceApplication = $applicationsByCharacter->get($sourceCharacterId);

            if ($sourceApplication) {
                $sourceApplication->update([
                    'status' => $targetIsBench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED,
                    'reviewed_at' => now(),
                ]);
            }
        }

        if ($targetCharacterId) {
            $targetApplication = $applicationsByCharacter->get($targetCharacterId);

            if ($targetApplication) {
                $targetApplication->update([
                    'status' => $sourceIsBench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED,
                    'reviewed_at' => now(),
                ]);
            }
        }
    }
}
