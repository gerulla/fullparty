<?php

namespace App\Services\Groups;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\ActivitySlot;

class ActivitySlotSerializer
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(ActivitySlot $slot): array
    {
        $attendanceAssignment = $this->resolveAttendanceAssignment($slot);

        return [
            'id' => $slot->id,
            'group_key' => $slot->group_key,
            'group_label' => $slot->group_label,
            'slot_key' => $slot->slot_key,
            'slot_label' => $slot->slot_label,
            'position_in_group' => $slot->position_in_group,
            'sort_order' => $slot->sort_order,
            'is_bench' => $this->slotBench->isBench($slot),
            'assigned_character_id' => $slot->assigned_character_id,
            'attendance_status' => $attendanceAssignment?->attendance_status ?? ($slot->assigned_character_id ? 'assigned' : null),
            'checked_in_at' => $attendanceAssignment?->checked_in_at?->toIso8601String(),
            'assigned_character' => $slot->assignedCharacter ? [
                'id' => $slot->assignedCharacter->id,
                'name' => $slot->assignedCharacter->name,
                'avatar_url' => $slot->assignedCharacter->avatar_url,
                'world' => $slot->assignedCharacter->world,
                'datacenter' => $slot->assignedCharacter->datacenter,
            ] : null,
            'field_values' => $slot->fieldValues->map(fn ($fieldValue) => [
                'id' => $fieldValue->id,
                'field_key' => $fieldValue->field_key,
                'field_label' => $fieldValue->field_label,
                'field_type' => $fieldValue->field_type,
                'source' => $fieldValue->source,
                'value' => $fieldValue->value,
                'display_value' => $this->resolveSlotFieldDisplayValue($fieldValue),
                'display_meta' => $this->resolveSlotFieldDisplayMeta($fieldValue),
            ])->values(),
        ];
    }

    private function resolveAttendanceAssignment(ActivitySlot $slot): mixed
    {
        if (!$slot->assigned_character_id) {
            return null;
        }

        if ($slot->relationLoaded('assignments')) {
            return $slot->assignments
                ->filter(fn ($assignment) => $assignment->ended_at === null
                    && (int) $assignment->character_id === (int) $slot->assigned_character_id)
                ->sortByDesc('assigned_at')
                ->first();
        }

        if ($slot->relationLoaded('activity') && $slot->activity && $slot->activity->relationLoaded('slotAssignments')) {
            return $slot->activity->slotAssignments
                ->filter(fn ($assignment) => $assignment->ended_at === null
                    && (int) $assignment->activity_slot_id === (int) $slot->id
                    && (int) $assignment->character_id === (int) $slot->assigned_character_id)
                ->sortByDesc('assigned_at')
                ->first();
        }

        return null;
    }
}
