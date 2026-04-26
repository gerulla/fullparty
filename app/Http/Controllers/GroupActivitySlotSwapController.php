<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
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
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        $validated = $request->validate([
            'source_slot_id' => ['required', 'integer'],
            'target_slot_id' => ['required', 'integer', 'different:source_slot_id'],
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

        if (!$sourceSlot->assigned_character_id) {
            throw ValidationException::withMessages([
                'source_slot_id' => 'Only filled slots can be dragged.',
            ]);
        }

        $sourceIsBench = $slotBench->isBench($sourceSlot);
        $targetIsBench = $slotBench->isBench($targetSlot);

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

        return response()->json([
            'slots' => [
                $slotSerializer->serialize($sourceSlot),
                $slotSerializer->serialize($targetSlot),
            ],
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
