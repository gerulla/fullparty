<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Activity;
use App\Models\Group;
use Illuminate\Http\Request;

trait InteractsWithGroupActivityAttendees
{
    private function ensureActivityBelongsToGroup(Group $group, Activity $activity): void
    {
        if ($activity->group_id !== $group->id) {
            abort(404);
        }
    }

    private function canAccessOverview(Request $request, Group $group, Activity $activity, ?string $secretKey): bool
    {
        if ($activity->is_public) {
            if ($group->is_public) {
                return true;
            }

            return $group->hasMember($request->user()?->id);
        }

        return filled($secretKey)
            && filled($activity->secret_key)
            && hash_equals((string) $activity->secret_key, (string) $secretKey);
    }

    /**
     * @return array<int, string>
     */
    private function attendeeActivityRelations(): array
    {
        return [
            'organizer',
            'organizerCharacter',
            'activityType',
            'activityTypeVersion',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePublicGroup(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'is_public' => $group->is_public,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttendeeActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => [
                'id' => $activity->activityType?->id,
                'slug' => $activity->activityType?->slug,
                'draft_name' => $activity->activityType?->draft_name,
            ],
            'activity_type_version_id' => $activity->activity_type_version_id,
            'title' => $activity->title,
            'description' => $activity->description,
            'notes' => $activity->notes,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'needs_application' => $activity->needs_application,
            'slot_count' => (int) ($activity->slots_count ?? 0),
            'assigned_slot_count' => (int) ($activity->assigned_slot_count ?? 0),
            'pending_application_count' => (int) ($activity->pending_application_count ?? 0),
            'organized_by' => $activity->organizer ? [
                'id' => $activity->organizer->id,
                'name' => $activity->organizer->name,
                'avatar_url' => $activity->organizer->avatar_url,
            ] : null,
            'organized_by_character' => $activity->organizerCharacter ? [
                'id' => $activity->organizerCharacter->id,
                'user_id' => $activity->organizerCharacter->user_id,
                'name' => $activity->organizerCharacter->name,
                'avatar_url' => $activity->organizerCharacter->avatar_url,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityAttendeeRouteParameters(Group $group, Activity $activity, ?string $secretKey): array
    {
        $parameters = [
            'group' => $group,
            'activity' => $activity,
        ];

        if (filled($secretKey)) {
            $parameters['secretKey'] = $secretKey;
        }

        return $parameters;
    }
}
