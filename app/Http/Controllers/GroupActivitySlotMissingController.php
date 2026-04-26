<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotMissingController extends Controller
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
                'slot' => 'Only filled slots can be marked missing.',
            ]);
        }

        $slot->load(['activity', 'fieldValues', 'assignments']);
        $missingAssignment = $attendanceService->markMissing($slot, (int) auth()->id());
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
            'missing_assignment' => $missingAssignment ? $this->serializeMissingAssignment($missingAssignment) : null,
        ]);
    }

    public function undo(
        Group $group,
        Activity $activity,
        ActivitySlotAssignment $assignment,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotBench $slotBench,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->status === Activity::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'activity' => 'Completed activities cannot be updated for attendance.',
            ]);
        }

        if ((int) $assignment->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if ($assignment->attendance_status !== ActivitySlotAssignment::STATUS_MISSING) {
            throw ValidationException::withMessages([
                'assignment' => 'Only missing assignments can be undone.',
            ]);
        }

        $result = $attendanceService->undoMissing($assignment, (int) auth()->id(), $slotBench);

        return response()->json([
            'slots' => collect($result['slots'])
                ->map(fn (ActivitySlot $slot) => $slotSerializer->serialize($slot))
                ->values(),
            'assignment' => $this->serializeMissingAssignment($result['assignment']),
        ]);
    }

    private function serializeMissingAssignment(ActivitySlotAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'character' => $assignment->character ? [
                'id' => $assignment->character->id,
                'name' => $assignment->character->name,
                'avatar_url' => $assignment->character->avatar_url,
                'world' => $assignment->character->world,
                'datacenter' => $assignment->character->datacenter,
            ] : null,
            'slot_label' => $assignment->slot?->slot_label,
            'group_label' => $assignment->slot?->group_label,
            'marked_missing_at' => $assignment->marked_missing_at?->toIso8601String(),
        ];
    }
}
