<?php

namespace App\Services\Groups;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\ActivitySlot;

class ActivitySlotSerializer
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
        private readonly ActivitySlotStateTokenService $slotStateTokenService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(ActivitySlot $slot): array
    {
        $attendanceAssignment = $this->slotStateTokenService->resolveActiveAssignment($slot);

        return [
            'id' => $slot->id,
            'group_key' => $slot->group_key,
            'group_label' => $slot->group_label,
            'slot_key' => $slot->slot_key,
            'slot_label' => $slot->slot_label,
            'position_in_group' => $slot->position_in_group,
            'sort_order' => $slot->sort_order,
            'is_bench' => $this->slotBench->isBench($slot),
            'is_host' => (bool) $slot->is_host,
            'is_raid_leader' => (bool) $slot->is_raid_leader,
            'assigned_character_id' => $slot->assigned_character_id,
            'assignment_source' => $attendanceAssignment?->assignment_source,
            'assignment_application_id' => $attendanceAssignment?->application_id,
            'can_return_to_queue' => $attendanceAssignment?->application_id !== null,
            'attendance_status' => $attendanceAssignment?->attendance_status ?? ($slot->assigned_character_id ? 'assigned' : null),
            'checked_in_at' => $attendanceAssignment?->checked_in_at?->toIso8601String(),
            'state_token' => $this->slotStateTokenService->generate($slot),
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
}
