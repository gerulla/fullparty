<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;

class GroupActivityManagementDataController extends Controller
{
    public function show(
        Group $group,
        Activity $activity,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
    ): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        $activity->load([
            'organizer',
            'organizerCharacter',
            'activityType',
            'activityTypeVersion',
            'slots.assignedCharacter',
            'slots.fieldValues',
            'progressMilestones',
            'applications',
        ]);

        return response()->json([
            'activity' => [
                'id' => $activity->id,
                'activity_type' => [
                    'id' => $activity->activityType?->id,
                    'slug' => $activity->activityType?->slug,
                    'draft_name' => $activity->activityType?->draft_name,
                ],
                'activity_type_version_id' => $activity->activity_type_version_id,
                'fflogs_zone_id' => $activity->activityTypeVersion?->fflogs_zone_id,
                'title' => $activity->title,
                'description' => $activity->description,
                'notes' => $activity->notes,
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'duration_hours' => $activity->duration_hours,
                'target_prog_point_key' => $activity->target_prog_point_key,
                'furthest_progress_key' => $activity->furthest_progress_key,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'secret_key' => $activity->secret_key,
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
                'slot_count' => $activity->slots->count(),
                'application_count' => $activity->applications->count(),
                'pending_application_count' => $activity->applications
                    ->where('status', 'pending')
                    ->count(),
                'progress_milestone_count' => $activity->progressMilestones->count(),
                'slot_field_definitions' => $fieldDefinitionBuilder->build($activity->activityTypeVersion),
                'slots' => $activity->slots->map(fn ($slot) => $slotSerializer->serialize($slot))->values(),
                'progress_milestones' => $activity->progressMilestones->map(fn ($milestone) => [
                    'id' => $milestone->id,
                    'milestone_key' => $milestone->milestone_key,
                    'milestone_label' => $milestone->milestone_label,
                    'sort_order' => $milestone->sort_order,
                    'kills' => $milestone->kills,
                    'best_progress_percent' => $milestone->best_progress_percent,
                    'source' => $milestone->source,
                    'notes' => $milestone->notes,
                ])->values(),
            ],
        ]);
    }
}
