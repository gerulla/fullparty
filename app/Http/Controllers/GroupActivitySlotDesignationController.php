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
    public function markGroupStaffRaidLeaders(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotDesignationService $slotDesignationService,
        ActivitySlotSerializer $slotSerializer,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            abort(403);
        }

        $updatedSlots = $slotDesignationService->markGroupStaffAsRaidLeaders(
            $activity,
            $group,
            (int) $request->user()->id,
        );

        $serializedSlots = array_map(
            fn (ActivitySlot $updatedSlot) => $slotSerializer->serialize($updatedSlot),
            $updatedSlots,
        );

        if ($serializedSlots !== []) {
            $activityManagementRealtimeService->broadcastPatch($activity, [
                'updated_slots' => $serializedSlots,
            ]);
        }

        return response()->json([
            'marked_count' => count($serializedSlots),
            'slots' => $serializedSlots,
        ]);
    }

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
        ]);

        $slot->load(['activity.slotAssignments', 'assignedCharacter', 'fieldValues', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        $updatedSlots = $slotDesignationService->toggleDesignation(
            $slot,
            $validated['designation'],
            (int) $request->user()->id,
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
