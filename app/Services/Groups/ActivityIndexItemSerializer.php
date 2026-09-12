<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;

final class ActivityIndexItemSerializer
{
    public function __construct(
        private readonly ActivitySlotKind $slotKind,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(Activity $activity, ?int $viewerUserId, bool $canManageActivities): array
    {
        $mainSlots = $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot));
        $hasExistingApplication = $viewerUserId !== null
            && $activity->applications
                ->where('user_id', $viewerUserId)
                ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)
                ->isNotEmpty();
        $canOpenApplication = $hasExistingApplication || (
            $activity->needs_application
            && $activity->acceptsApplications()
            && $mainSlots->contains(fn (ActivitySlot $slot): bool => $slot->assigned_character_id === null)
        );

        return [
            'id' => $activity->id,
            'group' => [
                'id' => $activity->group->id,
                'name' => $activity->group->name,
                'slug' => $activity->group->slug,
                'profile_picture_url' => $activity->group->profile_picture_url,
                'discord_invite_url' => $activity->group->discord_invite_url,
                'group_type' => $activity->group->group_type,
                'voice_expectation' => $activity->group->voice_expectation,
                'can_manage_activities' => $canManageActivities,
            ],
            'activity_type' => [
                'id' => $activity->activityType?->id,
                'slug' => $activity->activityType?->slug,
                'draft_name' => $activity->activityType?->draft_name,
            ],
            'activity_type_version_id' => $activity->activity_type_version_id,
            'title' => $activity->title,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'small_image_url' => $activity->activityTypeVersion?->small_image_url,
            'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => $this->resolveTargetProgPointLabel($activity),
            'notes' => $activity->notes,
            'furthest_progress_key' => $activity->furthest_progress_key,
            'datacenter' => $activity->datacenter,
            'intensity' => $activity->intensity,
            'min_item_level' => $activity->min_item_level,
            'beginner_friendly' => $activity->beginner_friendly,
            'run_style' => $activity->run_style,
            'is_public' => $activity->is_public,
            'secret_key' => null,
            'needs_application' => $activity->needs_application,
            'allow_guest_applications' => $activity->allow_guest_applications,
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
                'world' => $activity->organizerCharacter->world,
                'datacenter' => $activity->organizerCharacter->datacenter,
            ] : null,
            'slot_count' => $mainSlots->count(),
            'assigned_slot_count' => $mainSlots
                ->filter(fn (ActivitySlot $slot): bool => $slot->assigned_character_id !== null)
                ->count(),
            'application_count' => $activity->applications
                ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
                ->count(),
            'has_existing_application' => $hasExistingApplication,
            'links' => [
                'view' => route('groups.activities.overview', [
                    'locale' => app()->getLocale(),
                    'group' => $activity->group->slug,
                    'activity' => $activity->id,
                ]),
                'apply' => $canOpenApplication ? route('groups.activities.application', [
                    'locale' => app()->getLocale(),
                    'group' => $activity->group->slug,
                    'activity' => $activity->id,
                ]) : null,
            ],
            'progress_milestone_count' => $activity->progressMilestones->count(),
            'created_at' => $activity->created_at?->toIso8601String(),
            'updated_at' => $activity->updated_at?->toIso8601String(),
        ];
    }

    private function resolveTargetProgPointLabel(Activity $activity): ?array
    {
        if (blank($activity->target_prog_point_key)) {
            return null;
        }

        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $activity->target_prog_point_key);
        $label = is_array($progPoint) ? ($progPoint['label'] ?? null) : null;

        return is_array($label) ? $label : null;
    }
}
