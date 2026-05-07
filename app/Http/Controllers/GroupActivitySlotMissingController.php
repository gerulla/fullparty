<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\GroupActivityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotMissingController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAttendanceService $attendanceService,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivitySlotDesignationService $slotDesignationService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot be updated for attendance.',
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
        $validated = $request->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);
        $characterName = $slot->assignedCharacter?->name;
        $missingAssignment = $attendanceService->markMissing($slot, (int) auth()->id());
        $slotDesignationService->clearInvalidDesignations([$slot], auth()->user());
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logAttendanceEvent(
            'marked_missing',
            $slot,
            auth()->user(),
            [
                'character_name' => $characterName,
                'marked_missing_at' => $missingAssignment?->marked_missing_at?->toIso8601String(),
            ],
            \App\Support\Audit\AuditSeverity::SEVERE_CHANGE,
        );

        $serializedSlot = $slotSerializer->serialize($slot);
        $serializedMissingAssignment = $missingAssignment
            ? $activityManagementRealtimeService->serializeMissingAssignment($missingAssignment)
            : null;

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
            'upsert_missing_assignments' => $serializedMissingAssignment ? [$serializedMissingAssignment] : [],
            'remove_missing_assignment_ids' => [],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
            'missing_assignment' => $serializedMissingAssignment,
        ]);
    }

    public function undo(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotAssignment $assignment,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotBench $slotBench,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot be updated for attendance.',
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

        $validated = $request->validate([
            'expected_slot_state_token' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($assignment->slot) {
            $assignment->slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
            $slotStateTokenService->assertMatches($assignment->slot, $validated['expected_slot_state_token'] ?? null);
        }

        $result = $attendanceService->undoMissing($assignment, (int) auth()->id(), $slotBench);
        /** @var ActivitySlot $restoredSlot */
        $restoredSlot = $result['slots'][0];

        $activityAuditService->logAttendanceEvent(
            'missing_reverted',
            $restoredSlot,
            auth()->user(),
            [
                'character_name' => $result['assignment']->character?->name,
                'restored_destination' => $slotBench->isBench($restoredSlot) ? 'bench' : 'original_slot',
            ],
        );

        $serializedSlots = collect($result['slots'])
            ->map(fn (ActivitySlot $slot) => $slotSerializer->serialize($slot))
            ->values()
            ->all();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $serializedSlots,
            'upsert_missing_assignments' => [],
            'remove_missing_assignment_ids' => [(int) $result['assignment']->id],
        ]);

        return response()->json([
            'slots' => $serializedSlots,
            'assignment' => $activityManagementRealtimeService->serializeMissingAssignment($result['assignment']),
        ]);
    }
}
