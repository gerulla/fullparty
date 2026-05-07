<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivitySlotAttendanceService
{
    /**
     * @return array<string, mixed>
     */
    public function buildFieldValueSnapshot(ActivitySlot $slot): array
    {
        return $slot->fieldValues
            ->mapWithKeys(fn ($fieldValue) => [
                $fieldValue->field_key => $fieldValue->value,
            ])
            ->all();
    }

    public function ensureActiveAssignments(Activity $activity): void
    {
        $activity->loadMissing([
            'slots',
            'applications',
            'slotAssignments',
        ]);

        $applicationsByCharacter = $activity->applications
            ->filter(fn ($application) => $application->selected_character_id !== null)
            ->keyBy('selected_character_id');

        foreach ($activity->slots as $slot) {
            if (!$slot->assigned_character_id) {
                continue;
            }

            $existingAssignment = $activity->slotAssignments
                ->first(fn (ActivitySlotAssignment $assignment) => $assignment->ended_at === null
                    && (int) $assignment->character_id === (int) $slot->assigned_character_id);

            if ($existingAssignment) {
                if ((int) $existingAssignment->activity_slot_id !== (int) $slot->id) {
                    $existingAssignment->update([
                        'activity_slot_id' => $slot->id,
                    ]);
                }

                continue;
            }

            $application = $applicationsByCharacter->get($slot->assigned_character_id);

            ActivitySlotAssignment::query()->create([
                'activity_id' => $activity->id,
                'group_id' => $activity->group_id,
                'activity_slot_id' => $slot->id,
                'character_id' => $slot->assigned_character_id,
                'application_id' => $application?->id,
                'assignment_source' => $application?->id
                    ? ActivitySlotAssignment::SOURCE_APPLICATION
                    : ActivitySlotAssignment::SOURCE_MANUAL,
                'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                'assigned_at' => $slot->updated_at ?? now(),
                'assigned_by_user_id' => $slot->assigned_by_user_id,
            ]);
        }

        $activity->load('slotAssignments');
    }

    public function moveOrCreateActiveAssignment(
        ActivitySlot $slot,
        int $characterId,
        ?int $applicationId,
        ?int $assignedByUserId,
        ?array $fieldValueSnapshot = null,
    ): void {
        $activity = $slot->activity;

        if (!$activity) {
            return;
        }

        $assignment = ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('character_id', $characterId)
            ->whereNull('ended_at')
            ->latest('assigned_at')
            ->first();

        if ($assignment) {
            $assignment->update([
                'activity_slot_id' => $slot->id,
                'application_id' => $applicationId,
                'assignment_source' => $applicationId
                    ? ActivitySlotAssignment::SOURCE_APPLICATION
                    : ActivitySlotAssignment::SOURCE_MANUAL,
                'field_values_snapshot' => $fieldValueSnapshot ?? $assignment->field_values_snapshot,
                'assigned_by_user_id' => $assignedByUserId,
            ]);

            return;
        }

        ActivitySlotAssignment::query()->create([
            'activity_id' => $activity->id,
            'group_id' => $activity->group_id,
            'activity_slot_id' => $slot->id,
            'character_id' => $characterId,
            'application_id' => $applicationId,
            'assignment_source' => $applicationId
                ? ActivitySlotAssignment::SOURCE_APPLICATION
                : ActivitySlotAssignment::SOURCE_MANUAL,
            'field_values_snapshot' => $fieldValueSnapshot,
            'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
            'assigned_at' => now(),
            'assigned_by_user_id' => $assignedByUserId,
        ]);
    }

    public function endActiveAssignment(Activity $activity, int $characterId): void
    {
        ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('character_id', $characterId)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
            ]);
    }

    public function syncSwappedAssignments(
        ActivitySlot $sourceSlot,
        ActivitySlot $targetSlot,
        ?int $sourceCharacterId,
        ?int $targetCharacterId,
        Collection $applicationsByCharacter,
    ): void {
        if ($sourceCharacterId) {
            $this->moveOrCreateActiveAssignment(
                $targetSlot,
                $sourceCharacterId,
                $applicationsByCharacter->get($sourceCharacterId)?->id,
                $targetSlot->assigned_by_user_id,
                $this->buildFieldValueSnapshot($targetSlot),
            );
        }

        if ($targetCharacterId) {
            $this->moveOrCreateActiveAssignment(
                $sourceSlot,
                $targetCharacterId,
                $applicationsByCharacter->get($targetCharacterId)?->id,
                $sourceSlot->assigned_by_user_id,
                $this->buildFieldValueSnapshot($sourceSlot),
            );
        }
    }

    public function markMissing(ActivitySlot $slot, int $markedByUserId): ?ActivitySlotAssignment
    {
        $activity = $slot->activity;

        if (!$activity || !$slot->assigned_character_id) {
            return null;
        }

        return DB::transaction(function () use ($activity, $slot, $markedByUserId) {
            $assignment = ActivitySlotAssignment::query()
                ->where('activity_id', $activity->id)
                ->where('character_id', $slot->assigned_character_id)
                ->whereNull('ended_at')
                ->latest('assigned_at')
                ->first();

            if (!$assignment) {
                $assignment = ActivitySlotAssignment::query()->create([
                    'activity_id' => $activity->id,
                    'group_id' => $activity->group_id,
                    'activity_slot_id' => $slot->id,
                    'character_id' => $slot->assigned_character_id,
                    'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                    'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                    'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $slot->assigned_by_user_id,
                ]);
            }

            $assignment->update([
                'attendance_status' => ActivitySlotAssignment::STATUS_MISSING,
                'marked_missing_at' => now(),
                'marked_missing_by_user_id' => $markedByUserId,
                'ended_at' => now(),
            ]);

            $slot->update([
                'assigned_character_id' => null,
                'assigned_by_user_id' => null,
            ]);

            foreach ($slot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => null,
                ]);
            }

            return $assignment->fresh(['character', 'slot']);
        });
    }

    public function checkInSlot(ActivitySlot $slot, int $checkedInByUserId): ?ActivitySlotAssignment
    {
        return $this->markAttendance($slot, $checkedInByUserId, ActivitySlotAssignment::STATUS_CHECKED_IN);
    }

    public function markLateSlot(ActivitySlot $slot, int $checkedInByUserId): ?ActivitySlotAssignment
    {
        return $this->markAttendance($slot, $checkedInByUserId, ActivitySlotAssignment::STATUS_LATE);
    }

    public function undoCheckInSlot(ActivitySlot $slot): ?ActivitySlotAssignment
    {
        $activity = $slot->activity;

        if (!$activity || !$slot->assigned_character_id) {
            return null;
        }

        return DB::transaction(function () use ($activity, $slot) {
            $assignment = ActivitySlotAssignment::query()
                ->where('activity_id', $activity->id)
                ->where('character_id', $slot->assigned_character_id)
                ->whereNull('ended_at')
                ->latest('assigned_at')
                ->first();

            if (!$assignment) {
                $assignment = ActivitySlotAssignment::query()->create([
                    'activity_id' => $activity->id,
                    'group_id' => $activity->group_id,
                    'activity_slot_id' => $slot->id,
                    'character_id' => $slot->assigned_character_id,
                    'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                    'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                    'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $slot->assigned_by_user_id,
                ]);
            }

            if (!in_array($assignment->attendance_status, [
                ActivitySlotAssignment::STATUS_CHECKED_IN,
                ActivitySlotAssignment::STATUS_LATE,
            ], true)) {
                return null;
            }

            $assignment->update([
                'activity_slot_id' => $slot->id,
                'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                'checked_in_at' => null,
                'checked_in_by_user_id' => null,
            ]);

            return $assignment->fresh();
        });
    }

    private function markAttendance(ActivitySlot $slot, int $checkedInByUserId, string $attendanceStatus): ?ActivitySlotAssignment
    {
        $activity = $slot->activity;

        if (!$activity || !$slot->assigned_character_id) {
            return null;
        }

        return DB::transaction(function () use ($activity, $slot, $checkedInByUserId, $attendanceStatus) {
            $assignment = ActivitySlotAssignment::query()
                ->where('activity_id', $activity->id)
                ->where('character_id', $slot->assigned_character_id)
                ->whereNull('ended_at')
                ->latest('assigned_at')
                ->first();

            if (!$assignment) {
                $assignment = ActivitySlotAssignment::query()->create([
                    'activity_id' => $activity->id,
                    'group_id' => $activity->group_id,
                    'activity_slot_id' => $slot->id,
                    'character_id' => $slot->assigned_character_id,
                    'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                    'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                    'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $slot->assigned_by_user_id,
                ]);
            }

            $assignment->update([
                'activity_slot_id' => $slot->id,
                'field_values_snapshot' => $this->buildFieldValueSnapshot($slot),
                'attendance_status' => $attendanceStatus,
                'checked_in_at' => now(),
                'checked_in_by_user_id' => $checkedInByUserId,
            ]);

            return $assignment->fresh();
        });
    }

    /**
     * @return Collection<int, ActivitySlot>
     */
    public function checkInGroup(Activity $activity, string $groupKey, int $checkedInByUserId): Collection
    {
        $slots = $activity->slots()
            ->with(['activity', 'assignedCharacter', 'fieldValues', 'assignments'])
            ->where('group_key', $groupKey)
            ->whereNotNull('assigned_character_id')
            ->orderBy('sort_order')
            ->get();

        if ($slots->isEmpty()) {
            return collect();
        }

        DB::transaction(function () use ($slots, $checkedInByUserId) {
            foreach ($slots as $slot) {
                $this->checkInSlot($slot, $checkedInByUserId);
            }
        });

        return $slots->each->load(['assignedCharacter', 'fieldValues', 'assignments']);
    }

    /**
     * @return array{slots: array<int, ActivitySlot>, assignment: ActivitySlotAssignment}
     */
    public function undoMissing(ActivitySlotAssignment $assignment, int $userId, ActivitySlotBench $slotBench): array
    {
        $assignment->loadMissing(['activity.slots.fieldValues', 'application', 'slot.fieldValues']);

        $activity = $assignment->activity;
        $originalSlot = $assignment->slot;

        if (!$activity || !$originalSlot) {
            throw ValidationException::withMessages([
                'assignment' => 'The original slot for this missing assignment could not be found.',
            ]);
        }

        return DB::transaction(function () use ($assignment, $activity, $originalSlot, $userId, $slotBench) {
            $activity->load(['slots.fieldValues', 'slots.assignedCharacter']);
            $fieldValueSnapshot = is_array($assignment->field_values_snapshot)
                ? $assignment->field_values_snapshot
                : [];

            $targetSlot = $activity->slots
                ->first(fn (ActivitySlot $slot) => (int) $slot->id === (int) $originalSlot->id && $slot->assigned_character_id === null);

            $isRestoredToBench = false;

            if (!$targetSlot) {
                $targetSlot = $activity->slots
                    ->first(fn (ActivitySlot $slot) => $slotBench->isBench($slot) && $slot->assigned_character_id === null);

                $isRestoredToBench = $targetSlot !== null;
            }

            if (!$targetSlot) {
                throw ValidationException::withMessages([
                    'assignment' => 'No space is available to undo this missing assignment. Free a bench slot first.',
                ]);
            }

            $targetSlot->update([
                'assigned_character_id' => $assignment->character_id,
                'assigned_by_user_id' => $userId,
            ]);

            if ($slotBench->isBench($targetSlot)) {
                foreach ($targetSlot->fieldValues as $fieldValue) {
                    $fieldValue->update(['value' => null]);
                }
            } else {
                foreach ($targetSlot->fieldValues as $fieldValue) {
                    $fieldValue->update([
                        'value' => $fieldValueSnapshot[$fieldValue->field_key] ?? null,
                    ]);
                }
            }

            $assignment->update([
                'activity_slot_id' => $targetSlot->id,
                'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                'marked_missing_at' => null,
                'marked_missing_by_user_id' => null,
                'ended_at' => null,
                'assigned_by_user_id' => $userId,
            ]);

            if ($assignment->application) {
                $assignment->application->update([
                    'status' => $slotBench->isBench($targetSlot)
                        ? ActivityApplication::STATUS_ON_BENCH
                        : ActivityApplication::STATUS_APPROVED,
                    'reviewed_by_user_id' => $userId,
                    'reviewed_at' => now(),
                ]);
            }

            $targetSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);

            return [
                'slots' => [$targetSlot],
                'assignment' => $assignment->fresh(['character', 'slot']),
            ];
        });
    }
}
