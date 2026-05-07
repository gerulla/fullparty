<?php

namespace App\Services\Groups;

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivitySlotAssignment;

class ActivityManagementRealtimeService
{
    /**
     * @param  array<string, mixed>  $patch
     */
    public function broadcastPatch(Activity $activity, array $patch): void
    {
        if (!$activity->group_id) {
            return;
        }

        event(new ActivityManagementUpdated(
            groupId: (int) $activity->group_id,
            activityId: (int) $activity->id,
            patch: $patch,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMissingAssignment(ActivitySlotAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'slot_id' => $assignment->slot?->id,
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
