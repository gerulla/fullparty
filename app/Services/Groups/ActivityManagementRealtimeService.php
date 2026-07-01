<?php

namespace App\Services\Groups;

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use Illuminate\Support\Facades\Log;

class ActivityManagementRealtimeService
{
    private const MAX_BROADCAST_PAYLOAD_BYTES = 9000;

    /**
     * @param  array<string, mixed>  $patch
     */
    public function broadcastPatch(Activity $activity, array $patch): void
    {
        if (! $activity->group_id) {
            return;
        }

        event(new ActivityManagementUpdated(
            groupId: (int) $activity->group_id,
            activityId: (int) $activity->id,
            patch: $this->safePatchForBroadcast($activity, $patch),
        ));
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function safePatchForBroadcast(Activity $activity, array $patch): array
    {
        $payload = [
            'group_id' => (int) $activity->group_id,
            'activity_id' => (int) $activity->id,
            'patch' => $patch,
        ];
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadBytes = is_string($encodedPayload) ? strlen($encodedPayload) : null;

        if (is_int($payloadBytes) && $payloadBytes <= self::MAX_BROADCAST_PAYLOAD_BYTES) {
            return $patch;
        }

        Log::warning('Activity management realtime patch exceeded broadcast payload limit; broadcasting reload patch instead.', [
            'group_id' => (int) $activity->group_id,
            'activity_id' => (int) $activity->id,
            'payload_bytes' => $payloadBytes,
            'patch_keys' => array_keys($patch),
        ]);

        return [
            'type' => 'reload',
            'reason' => is_int($payloadBytes) ? 'payload_too_large' : 'payload_encoding_failed',
        ];
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
