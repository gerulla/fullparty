<?php

namespace App\Services\Groups;

use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ActivitySlotStateTokenService
{
    public function resolveActiveAssignment(ActivitySlot $slot): ?ActivitySlotAssignment
    {
        if (!$slot->assigned_character_id) {
            return null;
        }

        if ($slot->relationLoaded('activity') && $slot->activity && $slot->activity->relationLoaded('slotAssignments')) {
            $assignment = $slot->activity->slotAssignments
                ->filter(fn ($assignment) => $assignment->ended_at === null
                    && (int) $assignment->activity_slot_id === (int) $slot->id
                    && (int) $assignment->character_id === (int) $slot->assigned_character_id)
                ->sortByDesc('assigned_at')
                ->first();

            if ($assignment) {
                return $assignment;
            }
        }

        if ($slot->relationLoaded('assignments')) {
            return $slot->assignments
                ->filter(fn ($assignment) => $assignment->ended_at === null
                    && (int) $assignment->character_id === (int) $slot->assigned_character_id)
                ->sortByDesc('assigned_at')
                ->first();
        }

        return $slot->assignments()
            ->whereNull('ended_at')
            ->where('character_id', $slot->assigned_character_id)
            ->latest('assigned_at')
            ->first();
    }

    public function generate(ActivitySlot $slot): string
    {
        $slot->loadMissing('fieldValues');
        $assignment = $this->resolveActiveAssignment($slot);

        $payload = [
            'slot_id' => (int) $slot->id,
            'assigned_character_id' => $slot->assigned_character_id !== null ? (int) $slot->assigned_character_id : null,
            'is_host' => (bool) $slot->is_host,
            'is_raid_leader' => (bool) $slot->is_raid_leader,
            'field_values' => $slot->fieldValues
                ->sortBy('field_key')
                ->mapWithKeys(fn ($fieldValue) => [$fieldValue->field_key => $fieldValue->value])
                ->all(),
            'assignment' => $assignment ? [
                'id' => (int) $assignment->id,
                'application_id' => $assignment->application_id !== null ? (int) $assignment->application_id : null,
                'assignment_source' => $assignment->assignment_source,
                'attendance_status' => $assignment->attendance_status,
                'checked_in_at' => $assignment->checked_in_at?->toIso8601String(),
                'marked_missing_at' => $assignment->marked_missing_at?->toIso8601String(),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ] : null,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function assertMatches(ActivitySlot $slot, ?string $expectedToken): void
    {
        if (!is_string($expectedToken) || $expectedToken === '') {
            throw new ConflictHttpException('This slot changed while you were editing it. Refresh and try again.');
        }

        if (!hash_equals($this->generate($slot), $expectedToken)) {
            throw new ConflictHttpException('This slot changed while you were editing it. Refresh and try again.');
        }
    }
}
