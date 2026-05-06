<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivityBenchSlotBackfillService;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;

class GroupActivityManagementDataController extends Controller
{
    public function show(
        Group $group,
        Activity $activity,
        ActivityBenchSlotBackfillService $benchSlotBackfillService,
        ActivityCompletionService $completionService,
        ActivitySlotAttendanceService $attendanceService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        ActivitySlotBench $slotBench,
    ): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        $activity->load([
            'organizer',
            'organizerCharacter',
            'activityType',
            'activityTypeVersion',
            'slots.assignedCharacter',
            'slots.assignments',
            'slots.fieldValues',
            'progressMilestones',
            'applications',
            'slotAssignments.character',
            'slotAssignments.slot',
        ]);

        $benchSlotBackfillService->ensureBenchSlots($activity);
        $attendanceService->ensureActiveAssignments($activity);
        $activity->load(['slotAssignments.character', 'slotAssignments.slot']);

        $mainSlots = $activity->slots->filter(fn ($slot) => !$slotBench->isBench($slot))->values();
        $benchSlots = $activity->slots->filter(fn ($slot) => $slotBench->isBench($slot))->values();

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
                'furthest_progress_percent' => $activity->furthest_progress_percent,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'allow_guest_applications' => $activity->allow_guest_applications,
                'secret_key' => $activity->secret_key,
                'progress_entry_mode' => $activity->progress_entry_mode,
                'progress_link_url' => $activity->progress_link_url,
                'progress_notes' => $activity->progress_notes,
                'completed_at' => $activity->completed_at?->toIso8601String(),
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
                'slot_count' => $mainSlots->count(),
                'bench_slot_count' => $benchSlots->count(),
                'application_count' => $activity->applications->count(),
                'pending_application_count' => $activity->applications
                    ->where('status', ActivityApplication::STATUS_PENDING)
                    ->count(),
                'progress_milestone_count' => $activity->progressMilestones->count(),
                'prog_points' => collect($activity->activityTypeVersion?->prog_points ?? [])
                    ->map(fn (array $progPoint) => [
                        'key' => (string) ($progPoint['key'] ?? ''),
                        'label' => is_array($progPoint['label'] ?? null)
                            ? $progPoint['label']
                            : ['en' => (string) ($progPoint['key'] ?? '')],
                    ])
                    ->filter(fn (array $progPoint) => $progPoint['key'] !== '')
                    ->values()
                    ->all(),
                'can_use_fflogs_completion' => $completionService->supportsFflogsCompletion($activity->activityTypeVersion),
                'slot_field_definitions' => $fieldDefinitionBuilder->build($activity->activityTypeVersion),
                'slots' => $activity->slots->map(fn ($slot) => $slotSerializer->serialize($slot))->values(),
                'missing_assignments' => $activity->slotAssignments
                    ->where('attendance_status', \App\Models\ActivitySlotAssignment::STATUS_MISSING)
                    ->sortByDesc('marked_missing_at')
                    ->values()
                    ->map(fn ($assignment) => [
                        'id' => $assignment->id,
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
                    ]),
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
