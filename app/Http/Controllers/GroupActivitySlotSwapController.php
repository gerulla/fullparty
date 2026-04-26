<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
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
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        $validated = $request->validate([
            'source_slot_id' => ['required', 'integer'],
            'target_slot_id' => ['required', 'integer', 'different:source_slot_id'],
        ]);

        $slots = $activity->slots()
            ->with(['assignedCharacter', 'fieldValues'])
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

        DB::transaction(function () use ($sourceSlot, $targetSlot) {
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

            foreach ($sourceSlot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => $targetFieldValues[$fieldValue->field_key] ?? null,
                ]);
            }

            foreach ($targetSlot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => $sourceFieldValues[$fieldValue->field_key] ?? null,
                ]);
            }
        });

        $sourceSlot->load(['assignedCharacter', 'fieldValues']);
        $targetSlot->load(['assignedCharacter', 'fieldValues']);

        return response()->json([
            'slots' => [
                $slotSerializer->serialize($sourceSlot),
                $slotSerializer->serialize($targetSlot),
            ],
        ]);
    }
}
