<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivitySlotSerializer;
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
        $userId = $request->user()?->id;

        if ($group->isBanned($userId)) {
            return false;
        }

        if (Activity::isModeratorOnlyStatus($activity->status)) {
            return $group->hasModeratorAccess($userId);
        }

        if ($group->is_visible) {
            return true;
        }

        return $group->hasMember($userId) || $group->hasModeratorAccess($userId);
    }

    private function canUseActivityParticipationFlow(Group $group, Activity $activity, ?int $userId): bool
    {
        if ($group->isBanned($userId)) {
            return false;
        }

        if (Activity::isModeratorOnlyStatus($activity->status)) {
            return false;
        }

        if ($activity->is_public) {
            return $group->is_visible
                || $group->hasMember($userId)
                || $group->hasModeratorAccess($userId);
        }

        return $userId !== null
            && ($group->hasMember($userId) || $group->hasModeratorAccess($userId));
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
            'progressMilestones',
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
            'is_visible' => $group->is_visible,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttendeeActivity(
        Activity $activity,
        ?ActivitySlotSerializer $slotSerializer = null,
        array $rosterSummaryPresets = [],
    ): array {
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
            'small_image_url' => $activity->activityTypeVersion?->small_image_url,
            'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            'notes' => $activity->notes,
            'status' => $activity->status,
            'cancellation_reason' => $activity->resolvedCancellationReason(),
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'datacenter' => $activity->datacenter,
            'intensity' => $activity->intensity,
            'min_item_level' => $activity->min_item_level,
            'beginner_friendly' => $activity->beginner_friendly,
            'run_style' => $activity->run_style,
            'difficulty' => $activity->activityTypeVersion?->difficulty,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => collect($activity->activityTypeVersion?->prog_points ?? [])
                ->firstWhere('key', $activity->target_prog_point_key)['label'] ?? null,
            'furthest_progress_key' => $activity->furthest_progress_key,
            'furthest_progress_percent' => $activity->furthest_progress_percent !== null
                ? (float) $activity->furthest_progress_percent
                : null,
            'needs_application' => $activity->needs_application,
            'allow_guest_applications' => $activity->allow_guest_applications,
            'progress_entry_mode' => $activity->progress_entry_mode,
            'progress_link_url' => $activity->progress_link_url,
            'progress_notes' => $activity->progress_notes,
            'completed_at' => $activity->completed_at?->toIso8601String(),
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
            'prog_points' => $activity->activityTypeVersion?->prog_points ?? [],
            'roster_summary_presets' => $rosterSummaryPresets,
            'progress_milestones' => $activity->relationLoaded('progressMilestones')
                ? $activity->progressMilestones
                    ->map(fn ($milestone) => [
                        'milestone_key' => $milestone->milestone_key,
                        'milestone_label' => $milestone->milestone_label,
                        'kills' => $milestone->kills,
                        'best_progress_percent' => $milestone->best_progress_percent !== null
                            ? (float) $milestone->best_progress_percent
                            : null,
                        'sort_order' => $milestone->sort_order,
                    ])
                    ->values()
                    ->all()
                : [],
            'slots' => $slotSerializer && $activity->relationLoaded('slots')
                ? $activity->slots->map(fn ($slot) => $slotSerializer->serialize($slot))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityAttendeeRouteParameters(Group $group, Activity $activity, ?string $secretKey): array
    {
        return [
            'group' => $group,
            'activity' => $activity,
        ];
    }
}
