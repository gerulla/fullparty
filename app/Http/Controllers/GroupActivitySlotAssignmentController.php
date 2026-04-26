<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivitySlotAssignmentController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        ActivitySlotAssignmentService $slotAssignmentService,
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'application_id' => ['required', 'integer'],
            'field_values' => ['sometimes', 'array'],
            'source_slot_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $sourceSlot = null;

        if (!empty($validated['source_slot_id'])) {
            $sourceSlot = $activity->slots()
                ->with(['assignedCharacter', 'fieldValues', 'activity', 'assignments'])
                ->find((int) $validated['source_slot_id']);

            if (!$sourceSlot) {
                abort(404);
            }
        }

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with(['answers', 'selectedCharacter'])
            ->find((int) $validated['application_id']);

        if (!$application) {
            abort(404);
        }

        $isAllowedStatus = $application->status === ActivityApplication::STATUS_PENDING
            || (
                $application->status === ActivityApplication::STATUS_APPROVED
                && (int) $application->selected_character_id === (int) $slot->assigned_character_id
            )
            || (
                $application->status === ActivityApplication::STATUS_ON_BENCH
                && $sourceSlot !== null
            );

        if (!$isAllowedStatus) {
            abort(404);
        }

        $fieldDefinitions = collect($fieldDefinitionBuilder->build($activity->activityTypeVersion))
            ->filter(fn (array $definition) => filled($definition['application_key'] ?? null))
            ->keyBy(fn (array $definition) => (string) $definition['key'])
            ->all();

        $slot->load(['assignedCharacter', 'fieldValues', 'activity', 'assignments']);

        $slotAssignmentService->assignFromApplication(
            $slot,
            $application,
            $validated['field_values'] ?? [],
            $fieldDefinitions,
            (int) $request->user()->id,
            $sourceSlot,
        );

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);
        $updatedSlots = [$slotSerializer->serialize($slot)];

        if ($sourceSlot) {
            $sourceSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);
            $updatedSlots[] = $slotSerializer->serialize($sourceSlot);
        }

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
            'slots' => $updatedSlots,
        ]);
    }
}
