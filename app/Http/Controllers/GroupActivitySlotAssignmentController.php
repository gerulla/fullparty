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
            'field_values' => ['required', 'array'],
        ]);

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with(['answers', 'selectedCharacter'])
            ->where(function ($query) use ($slot) {
                $query->where('status', ActivityApplication::STATUS_PENDING)
                    ->orWhere(function ($approvedQuery) use ($slot) {
                        $approvedQuery->where('status', ActivityApplication::STATUS_APPROVED)
                            ->where('selected_character_id', $slot->assigned_character_id);
                    });
            })
            ->find((int) $validated['application_id']);

        if (!$application) {
            abort(404);
        }

        $fieldDefinitions = collect($fieldDefinitionBuilder->build($activity->activityTypeVersion))
            ->filter(fn (array $definition) => filled($definition['application_key'] ?? null))
            ->keyBy(fn (array $definition) => (string) $definition['key'])
            ->all();

        $slot->load(['assignedCharacter', 'fieldValues']);

        $slotAssignmentService->assignFromApplication(
            $slot,
            $application,
            $validated['field_values'],
            $fieldDefinitions,
            (int) $request->user()->id,
        );

        $slot->load(['assignedCharacter', 'fieldValues']);

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
        ]);
    }
}
