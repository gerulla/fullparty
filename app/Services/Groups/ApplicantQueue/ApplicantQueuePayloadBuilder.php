<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivityApplicationAnswer;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\GroupUserNoteVisibilityService;
use Illuminate\Support\Collection;

class ApplicantQueuePayloadBuilder
{
    use InteractsWithActivitySlotFieldDisplay;

    /**
     * @var array<int, Collection<int, ActivitySlot>>
     */
    private array $slotHistoryCache = [];

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
                'slot_fields' => $this->serializeQueueSlotFields($activity->activityTypeVersion, $activity->group_id),
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
    ): array {
        $selectedCharacter = $application->selectedCharacter;

        return [
            'id' => $application->id,
            'is_guest' => $application->user_id === null,
            'user' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'avatar_url' => $application->user->avatar_url,
                'note_summary' => $group
                    ? $this->noteVisibilityService->serializeVisibleNoteSummaryForUser(
                        $group,
                        $application->user,
                        $currentUserId,
                        $groupNotesByUserId,
                        $sharedNotesByUserId,
                    )
                    : $this->noteVisibilityService->emptyVisibleNoteSummary(),
            ] : null,
            'applicant_character' => $application->applicant_lodestone_id ? [
                'lodestone_id' => $application->applicant_lodestone_id,
                'name' => $application->applicant_character_name,
                'avatar_url' => $application->applicant_avatar_url,
                'world' => $application->applicant_world,
                'datacenter' => $application->applicant_datacenter,
                'is_claimed' => $selectedCharacter?->user_id !== null,
            ] : null,
            'selected_character' => $selectedCharacter ? [
                'id' => $selectedCharacter->id,
                'name' => $selectedCharacter->name,
                'avatar_url' => $selectedCharacter->avatar_url,
                'world' => $selectedCharacter->world,
                'datacenter' => $selectedCharacter->datacenter,
                'lodestone_refreshed_at' => $selectedCharacter->lodestone_refreshed_at?->toIso8601String(),
                'lodestone_last_checked_at' => ($selectedCharacter->lodestone_refreshed_at ?? $selectedCharacter->updated_at)?->toIso8601String(),
                'occult_level' => $selectedCharacter->occultProgress?->knowledge_level,
                'blood_progress' => $selectedCharacter->occultProgress?->forkedTowerBloodProgress(),
                'phantom_mastery' => $selectedCharacter->phantomJobs
                    ->filter(fn ($phantomJob) => (int) ($phantomJob->pivot?->current_level ?? 0) >= (int) $phantomJob->max_level)
                    ->count(),
                'preferred_character_class_ids' => $selectedCharacter->classes
                    ->filter(fn ($characterClass) => (bool) $characterClass->pivot?->is_preferred)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
                'preferred_phantom_job_ids' => $selectedCharacter->phantomJobs
                    ->filter(fn ($phantomJob) => (bool) $phantomJob->pivot?->is_preferred)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
                'available_character_classes' => $selectedCharacter->classes
                    ->filter(fn ($characterClass) => (int) ($characterClass->pivot?->level ?? 0) > 0)
                    ->map(fn ($characterClass) => [
                        'id' => (string) $characterClass->id,
                        'level' => (int) $characterClass->pivot->level,
                    ])
                    ->values()
                    ->all(),
                'available_phantom_jobs' => $selectedCharacter->phantomJobs
                    ->filter(fn ($phantomJob) => (int) ($phantomJob->pivot?->current_level ?? 0) > 0)
                    ->map(fn ($phantomJob) => [
                        'id' => (string) $phantomJob->id,
                        'current_level' => (int) $phantomJob->pivot->current_level,
                        'max_level' => (int) $phantomJob->max_level,
                        'is_maxed' => (int) $phantomJob->pivot->current_level >= (int) $phantomJob->max_level,
                    ])
                    ->values()
                    ->all(),
            ] : null,
            'status' => $application->status,
            'notes' => $application->notes,
            'submitted_at' => $application->created_at?->toIso8601String(),
            'edited_at' => $application->edited_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'answers' => $this->orderedAnswers($application->answers, $activityTypeVersion)
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
     * @param  Collection<int, ActivityApplicationAnswer>  $answers
     * @return Collection<int, ActivityApplicationAnswer>
     */
    private function orderedAnswers(Collection $answers, ?ActivityTypeVersion $activityTypeVersion): Collection
    {
        $schemaOrder = collect($activityTypeVersion?->application_schema ?? [])
            ->pluck('key')
            ->filter()
            ->values()
            ->flip()
            ->all();

        return $answers
            ->sort(function (ActivityApplicationAnswer $first, ActivityApplicationAnswer $second) use ($schemaOrder): int {
                $firstIndex = $schemaOrder[$first->question_key] ?? PHP_INT_MAX;
                $secondIndex = $schemaOrder[$second->question_key] ?? PHP_INT_MAX;

                return [$firstIndex, $first->id] <=> [$secondIndex, $second->id];
            })
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeQueueSlotFields(?ActivityTypeVersion $activityTypeVersion, int $groupId): array
    {
        return collect($this->slotFieldDefinitionBuilder->build($activityTypeVersion, $groupId))
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
        if (! $userId) {
            return null;
        }

        if (! array_key_exists($userId, $this->slotHistoryCache)) {
            $this->slotHistoryCache[$userId] = ActivitySlot::query()
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
        $allSlots = collect($this->slotHistoryCache[$userId]);
        $groupSlots = $groupId
            ? $allSlots->filter(fn (ActivitySlot $slot) => (int) $slot->activity?->group_id === (int) $groupId)->values()
            : collect();

        return [
            'group_run_count' => $groupSlots->pluck('activity_id')->unique()->count(),
            'overall_run_count' => $allSlots->pluck('activity_id')->unique()->count(),
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

                if (! $fieldValue || ! $meta || blank($meta['name'] ?? null)) {
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

                if (! $fieldValue || ! $meta || blank($meta['name'] ?? null)) {
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
