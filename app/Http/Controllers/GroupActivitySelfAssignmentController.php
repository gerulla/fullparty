<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\GroupActivityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivitySelfAssignmentController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotAssignmentService $slotAssignmentService,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
        ?string $secretKey = null,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user, 403);

        $this->ensureActivityBelongsToGroup($group, $activity);

        if (! $this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        $this->ensureCanSelfAssign($group, $user);

        if ($activity->needs_application) {
            abort(404);
        }

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot accept self-assigned roster changes.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'character_id' => ['required', 'integer'],
            'field_values' => ['sometimes', 'array'],
            'expected_slot_state_token' => ['required', 'string'],
        ]);

        $slot->load(['assignedCharacter', 'fieldValues', 'activity', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        if ($slot->assigned_character_id !== null) {
            throw ValidationException::withMessages([
                'slot' => 'Only free slots can be self-assigned.',
            ]);
        }

        $existingUserAssignment = $activity->slots()
            ->whereHas('assignedCharacter', fn ($query) => $query->where('user_id', $user->id))
            ->exists();

        if ($existingUserAssignment) {
            throw ValidationException::withMessages([
                'slot' => 'You are already assigned to this run.',
            ]);
        }

        /** @var Character|null $character */
        $character = Character::query()
            ->with(['user', 'classes', 'phantomJobs'])
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->find((int) $validated['character_id']);

        if (! $character) {
            throw ValidationException::withMessages([
                'character_id' => 'Please choose one of your verified characters.',
            ]);
        }

        $fieldDefinitions = collect($fieldDefinitionBuilder->build($activity->activityTypeVersion, $group->id))
            ->keyBy(fn (array $definition) => (string) $definition['key'])
            ->all();

        $slotAssignmentService->assignManualCharacter(
            $slot,
            $character,
            $validated['field_values'] ?? [],
            $fieldDefinitions,
            (int) $user->id,
        );

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);
        $serializedSlot = $slotSerializer->serialize($slot);
        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
            'pending_application_count' => $pendingApplicationCount,
        ]);

        return response()->json([
            'slot' => $serializedSlot,
        ]);
    }

    public function destroy(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivitySlotDesignationService $slotDesignationService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
        ?string $secretKey = null,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user, 403);

        $this->ensureActivityBelongsToGroup($group, $activity);

        if (! $this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        $this->ensureCanSelfAssign($group, $user);

        if ($activity->needs_application) {
            abort(404);
        }

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot accept self-assigned roster changes.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        if (! $slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only filled roster slots can be removed.',
            ]);
        }

        if ((int) ($slot->assignedCharacter?->user_id ?? 0) !== (int) $user->id) {
            abort(403);
        }

        $activeAssignment = ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('activity_slot_id', $slot->id)
            ->where('character_id', $slot->assigned_character_id)
            ->whereNull('ended_at')
            ->latest('assigned_at')
            ->first();

        if (! $activeAssignment || $activeAssignment->application_id !== null) {
            throw ValidationException::withMessages([
                'slot' => 'Only self-assigned roster slots can be removed from this page.',
            ]);
        }

        $slotCharacterId = (int) $slot->assigned_character_id;
        $slotCharacterName = $slot->assignedCharacter?->name;

        DB::transaction(function () use ($slot, $activity, $attendanceService, $slotCharacterId) {
            $slot->update([
                'assigned_character_id' => null,
                'assigned_by_user_id' => null,
                'application_review_required_application_id' => null,
                'application_review_required_at' => null,
            ]);

            foreach ($slot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => null,
                ]);
            }

            $attendanceService->endActiveAssignment($activity, $slotCharacterId);
        });

        $slotDesignationService->clearInvalidDesignations([$slot], $user);
        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

        $activityAuditService->logRosterEvent(
            'manual_removed',
            $slot,
            $user,
            [
                'character_name' => $slotCharacterName,
                'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
            ],
        );

        $serializedSlot = $slotSerializer->serialize($slot);
        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
            'pending_application_count' => $pendingApplicationCount,
        ]);

        return response()->json([
            'slot' => $serializedSlot,
        ]);
    }

    private function ensureCanSelfAssign(Group $group, User $user): void
    {
        if ($group->isBanned($user->id)) {
            abort(404);
        }

        if (! $group->isOwnedBy($user->id) && ! $group->hasMember($user->id)) {
            abort(404);
        }
    }
}
