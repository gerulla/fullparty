<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotCheckInController extends Controller
{
    public function store(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->status === Activity::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'activity' => 'Completed activities cannot be updated for attendance.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (!$slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only filled slots can be checked in.',
            ]);
        }

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $attendanceService->checkInSlot($slot, (int) auth()->id());
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
        ]);
    }

    public function undo(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->status === Activity::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'activity' => 'Completed activities cannot be updated for attendance.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (!$slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only filled slots can undo check-in.',
            ]);
        }

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $assignment = $attendanceService->undoCheckInSlot($slot);

        if (!$assignment) {
            throw ValidationException::withMessages([
                'slot' => 'Only checked-in slots can undo check-in.',
            ]);
        }

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
        ]);
    }

    public function storeGroup(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->status === Activity::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'activity' => 'Completed activities cannot be updated for attendance.',
            ]);
        }

        $validated = $request->validate([
            'group_key' => ['required', 'string'],
        ]);

        $slots = $attendanceService->checkInGroup(
            $activity,
            (string) $validated['group_key'],
            (int) auth()->id(),
        );

        return response()->json([
            'slots' => $slots
                ->map(fn (ActivitySlot $slot) => $slotSerializer->serialize($slot))
                ->values(),
        ]);
    }
}
