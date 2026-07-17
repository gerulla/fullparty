<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityFillInSlotService;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityFillInSlotController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivityFillInSlotService $fillInSlotService,
        ActivitySlotSerializer $slotSerializer,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);
        $this->assertCanMutate($group, $activity);

        $validated = $request->validate([
            'filled_group_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $slot = $fillInSlotService->create(
            $activity,
            $validated['filled_group_key'] ?? null,
        );
        $serializedSlot = $slotSerializer->serialize($slot);

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
            'slots' => [$serializedSlot],
        ]);
    }

    public function update(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivityFillInSlotService $fillInSlotService,
        ActivitySlotSerializer $slotSerializer,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);
        $this->assertCanMutate($group, $activity);

        $validated = $request->validate([
            'filled_group_key' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $slot = $fillInSlotService->updateFilledGroup(
            $activity,
            $slot,
            $validated['filled_group_key'] ?? null,
        );
        $serializedSlot = $slotSerializer->serialize($slot);

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
            'slots' => [$serializedSlot],
        ]);
    }

    private function assertCanMutate(Group $group, Activity $activity): void
    {
        if ((int) $activity->group_id !== (int) $group->id) {
            abort(404);
        }

        if ($activity->isArchived()) {
            abort(403);
        }
    }
}
