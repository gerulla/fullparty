<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\GroupActivityAuditService;
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
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
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
                'slot' => 'Only filled slots can be checked in.',
            ]);
        }

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $validated = request()->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);
        $attendanceService->checkInSlot($slot, (int) auth()->id());
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logAttendanceEvent(
            'checked_in',
            $slot,
            auth()->user(),
            [
                'checked_in_at' => now()->toIso8601String(),
            ],
        );

        $serializedSlot = $slotSerializer->serialize($slot);
        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
        ]);
    }

    public function storeLate(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAttendanceService $attendanceService,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
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
                'slot' => 'Only filled slots can be marked late.',
            ]);
        }

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $validated = request()->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);
        $attendanceService->markLateSlot($slot, (int) auth()->id());
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logAttendanceEvent(
            'marked_late',
            $slot,
            auth()->user(),
            [
                'checked_in_at' => now()->toIso8601String(),
            ],
        );

        $serializedSlot = $slotSerializer->serialize($slot);
        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
        ]);
    }

    public function undo(
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAttendanceService $attendanceService,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
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
                'slot' => 'Only filled slots can undo check-in.',
            ]);
        }

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $validated = request()->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);
        $assignment = $attendanceService->undoCheckInSlot($slot);

        if (!$assignment) {
            throw ValidationException::withMessages([
                'slot' => 'Only checked-in or late slots can undo check-in.',
            ]);
        }

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logAttendanceEvent(
            'check_in_reverted',
            $slot,
            auth()->user(),
        );

        $serializedSlot = $slotSerializer->serialize($slot);
        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
        ]);

        return response()->json([
            'slot' => $serializedSlot,
        ]);
    }

    public function storeGroup(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotAttendanceService $attendanceService,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot be updated for attendance.',
            ]);
        }

        $validated = $request->validate([
            'group_key' => ['required', 'string'],
            'expected_slot_state_tokens' => ['required', 'array'],
        ]);

        $groupSlots = $activity->slots()
            ->with(['activity', 'assignedCharacter', 'fieldValues', 'assignments'])
            ->where('group_key', (string) $validated['group_key'])
            ->whereNotNull('assigned_character_id')
            ->get();

        foreach ($groupSlots as $slot) {
            $slotStateTokenService->assertMatches(
                $slot,
                data_get($validated['expected_slot_state_tokens'], (string) $slot->id),
            );
        }

        $slots = $attendanceService->checkInGroup(
            $activity,
            (string) $validated['group_key'],
            (int) auth()->id(),
        );

        if ($slots->isNotEmpty()) {
            $activityAuditService->logGroupAttendanceEvent(
                'group_checked_in',
                $activity,
                (string) (($slots->first()->group_label['en'] ?? $validated['group_key'])),
                auth()->user(),
                [
                    'checked_in_count' => $slots->count(),
                ],
            );
        }

        $serializedSlots = $slots
            ->map(fn (ActivitySlot $slot) => $slotSerializer->serialize($slot))
            ->values()
            ->all();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $serializedSlots,
        ]);

        return response()->json([
            'slots' => $serializedSlots,
        ]);
    }
}
