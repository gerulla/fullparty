<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\GroupUserNoteVisibilityService;
use Illuminate\Support\Collection;

class ApplicantQueuePayloadBuilder
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ApplicantMilestoneResolver $milestoneResolver,
        private readonly ApplicationAnswerPresenter $answerPresenter,
        private readonly ActivitySlotFieldDefinitionBuilder $slotFieldDefinitionBuilder,
        private readonly GroupUserNoteVisibilityService $noteVisibilityService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Activity $activity, int $currentUserId): array
    {
        $group = $activity->group;
        $visibleNotes = $this->visibleNotesForApplications(
            $activity->applications,
            $group,
            $currentUserId,
        );

        return [
            'fflogs_zone_id' => $activity->activityTypeVersion?->fflogs_zone_id,
            'pending_application_count' => $activity->applications->count(),
            'queue_filters' => [
                'slot_fields' => $this->serializeQueueSlotFields($activity->activityTypeVersion),
                'milestones' => $this->serializeQueueMilestones($activity->activityTypeVersion),
            ],
            'applications' => $activity->applications
                ->map(fn ($application) => $this->serializeApplication(
                    $application,
                    $activity->activityTypeVersion,
                    $group,
                    $currentUserId,
                    $visibleNotes['group_notes_by_user_id'],
                    $visibleNotes['shared_notes_by_user_id'],
                ))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeApplicationForModerator(
        $application,
        ?ActivityTypeVersion $activityTypeVersion,
        $group,
        int $currentUserId,
    ): array {
        $visibleNotes = $this->visibleNotesForApplications(
            collect([$application]),
            $group,
            $currentUserId,
        );

        return $this->serializeApplication(
            $application,
            $activityTypeVersion,
            $group,
            $currentUserId,
            $visibleNotes['group_notes_by_user_id'],
            $visibleNotes['shared_notes_by_user_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeApplication(
        $application,
        ?ActivityTypeVersion $activityTypeVersion,
        $group,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId,
    ): array
    {
        return [
            'id' => $application->id,
            'is_guest' => $application->user_id === null,
            'user' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'avatar_url' => $application->user->avatar_url,
                'notes' => $group
                    ? $this->noteVisibilityService->serializeVisibleNotesForUser(
                        $group,
                        $application->user,
                        $currentUserId,
                        $groupNotesByUserId,
                        $sharedNotesByUserId,
                    )
                    : [
                        'can_view' => false,
                        'can_add' => false,
                        'current_group_count' => 0,
                        'shared_count' => 0,
                        'current_group' => [],
                        'shared' => [],
                    ],
            ] : null,
            'applicant_character' => $application->applicant_lodestone_id ? [
                'lodestone_id' => $application->applicant_lodestone_id,
                'name' => $application->applicant_character_name,
                'avatar_url' => $application->applicant_avatar_url,
                'world' => $application->applicant_world,
                'datacenter' => $application->applicant_datacenter,
                'is_claimed' => $application->selectedCharacter?->user_id !== null,
            ] : null,
            'selected_character' => $application->selectedCharacter ? [
                'id' => $application->selectedCharacter->id,
                'name' => $application->selectedCharacter->name,
                'avatar_url' => $application->selectedCharacter->avatar_url,
                'world' => $application->selectedCharacter->world,
                'datacenter' => $application->selectedCharacter->datacenter,
                'occult_level' => $application->selectedCharacter->occultProgress?->knowledge_level,
                'blood_progress' => $application->selectedCharacter->occultProgress?->forkedTowerBloodProgress(),
                'phantom_mastery' => $application->selectedCharacter->phantomJobs
                    ->filter(fn ($phantomJob) => (int) ($phantomJob->pivot?->current_level ?? 0) >= (int) $phantomJob->max_level)
                    ->count(),
            ] : null,
            'status' => $application->status,
            'notes' => $application->notes,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'answers' => $application->answers
                ->map(fn ($answer) => $this->answerPresenter->present($answer, $activityTypeVersion))
                ->filter()
                ->values(),
            'progress_milestones' => $this->milestoneResolver->serialize(
                $application->selectedCharacter,
                $activityTypeVersion,
            ),
            'user_stats' => $this->serializeApplicantUserStats($application->user_id, $application->activity?->group_id),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeQueueSlotFields(?ActivityTypeVersion $activityTypeVersion): array
    {
        return collect($this->slotFieldDefinitionBuilder->build($activityTypeVersion))
            ->filter(fn (array $field) => $field['key'] !== '' && $field['application_key'] !== '' && count($field['options']) > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeQueueMilestones(?ActivityTypeVersion $activityTypeVersion): array
    {
        return collect($activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->map(fn (array $milestone) => [
                'key' => (string) ($milestone['key'] ?? ''),
                'label' => is_array($milestone['label'] ?? null)
                    ? $milestone['label']
                    : ['en' => (string) ($milestone['key'] ?? '')],
                'matcher_type' => $milestone['fflogs_matcher']['type'] ?? 'encounter',
                'encounter_id' => isset($milestone['fflogs_matcher']['encounter_id'])
                    ? (int) $milestone['fflogs_matcher']['encounter_id']
                    : null,
                'phase_id' => isset($milestone['fflogs_matcher']['phase_id'])
                    ? (int) $milestone['fflogs_matcher']['phase_id']
                    : null,
            ])
            ->filter(fn (array $milestone) => $milestone['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeApplicantUserStats(?int $userId, ?int $groupId): ?array
    {
        if (!$userId) {
            return null;
        }

        static $slotHistoryCache = [];

        if (!array_key_exists($userId, $slotHistoryCache)) {
            $slotHistoryCache[$userId] = ActivitySlot::query()
                ->with(['fieldValues', 'activity'])
                ->whereNotNull('assigned_character_id')
                ->whereHas('assignedCharacter', fn ($query) => $query->where('user_id', $userId))
                ->whereHas('activity', function ($query) {
                    $query->whereNotIn('status', array_diff(Activity::ARCHIVED_STATUSES, [Activity::STATUS_COMPLETE]))
                        ->where(function ($nestedQuery) {
                            $nestedQuery->where('status', Activity::STATUS_COMPLETE)
                                ->orWhere(function ($dateQuery) {
                                    $dateQuery->whereNotNull('starts_at')
                                        ->where('starts_at', '<=', now());
                                });
                        });
                })
                ->get();
        }

        /** @var Collection<int, ActivitySlot> $allSlots */
        $allSlots = collect($slotHistoryCache[$userId]);
        $groupSlots = $groupId
            ? $allSlots->filter(fn (ActivitySlot $slot) => (int) $slot->activity?->group_id === (int) $groupId)->values()
            : collect();

        return [
            'class' => [
                'group' => $this->topClassStats($groupSlots),
                'overall' => $this->topClassStats($allSlots),
            ],
            'phantom_job' => [
                'group' => $this->topPhantomJobStats($groupSlots),
                'overall' => $this->topPhantomJobStats($allSlots),
            ],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $applications
     * @return array{group_notes_by_user_id: Collection, shared_notes_by_user_id: Collection}
     */
    private function visibleNotesForApplications(Collection $applications, $group, int $currentUserId): array
    {
        $targetUserIds = $applications
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        return $group
            ? $this->noteVisibilityService->loadVisibleNotesForTargets($group, $currentUserId, $targetUserIds)
            : [
                'group_notes_by_user_id' => collect(),
                'shared_notes_by_user_id' => collect(),
            ];
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function topClassStats(Collection $slots): array
    {
        $classStats = $slots
            ->map(function (ActivitySlot $slot) {
                $fieldValue = $slot->fieldValues->firstWhere('field_key', 'character_class');
                $meta = $this->resolveSlotFieldDisplayMeta($fieldValue);

                if (!$fieldValue || !$meta || blank($meta['name'] ?? null)) {
                    return null;
                }

                return [
                    'key' => (string) ($meta['shorthand'] ?? $meta['name']),
                    'label' => (string) $meta['name'],
                    'role' => $meta['role'] ?? null,
                    'icon_url' => $meta['icon_url'] ?? null,
                    'flat_icon_url' => $meta['flaticon_url'] ?? null,
                ];
            })
            ->filter();

        if ($classStats->isEmpty()) {
            return [];
        }

        return $classStats->groupBy('key')
            ->map(function (Collection $entries) {
                $first = $entries->first();

                return [
                    ...$first,
                    'count' => $entries->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function topPhantomJobStats(Collection $slots): array
    {
        $phantomStats = $slots
            ->map(function (ActivitySlot $slot) {
                $fieldValue = $slot->fieldValues->firstWhere('field_key', 'phantom_job');
                $meta = $this->resolveSlotFieldDisplayMeta($fieldValue);

                if (!$fieldValue || !$meta || blank($meta['name'] ?? null)) {
                    return null;
                }

                return [
                    'key' => (string) ($meta['name'] ?? ''),
                    'label' => (string) $meta['name'],
                    'icon_url' => $meta['icon_url'] ?? null,
                    'transparent_icon_url' => $meta['transparent_icon_url'] ?? null,
                ];
            })
            ->filter();

        if ($phantomStats->isEmpty()) {
            return [];
        }

        return $phantomStats->groupBy('key')
            ->map(function (Collection $entries) {
                $first = $entries->first();

                return [
                    ...$first,
                    'count' => $entries->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(3)
            ->values()
            ->all();
    }
}
