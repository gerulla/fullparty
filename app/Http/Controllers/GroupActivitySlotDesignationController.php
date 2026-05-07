<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivitySlotDesignationController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotDesignationService $slotDesignationService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            abort(403);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'designation' => ['required', 'string', 'in:host,raid_leader'],
            'expected_slot_state_token' => ['required', 'string'],
            'expected_current_designation_slot_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $slot->load(['activity.slotAssignments', 'assignedCharacter', 'fieldValues', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        $updatedSlots = $slotDesignationService->toggleDesignation(
            $slot,
            $validated['designation'],
            (int) $request->user()->id,
            isset($validated['expected_current_designation_slot_id'])
                ? (int) $validated['expected_current_designation_slot_id']
                : null,
        );

        $serializedSlots = array_map(
            fn (ActivitySlot $updatedSlot) => $slotSerializer->serialize($updatedSlot),
            $updatedSlots,
        );

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $serializedSlots,
        ]);

        return response()->json([
            'slot' => collect($serializedSlots)->firstWhere('id', $slot->id),
            'slots' => $serializedSlots,
        ]);
    }
}
