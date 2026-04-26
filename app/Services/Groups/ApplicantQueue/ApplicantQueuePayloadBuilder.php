<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use Illuminate\Support\Collection;

class ApplicantQueuePayloadBuilder
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ApplicantMilestoneResolver $milestoneResolver,
        private readonly ApplicationAnswerPresenter $answerPresenter,
        private readonly ActivitySlotFieldDefinitionBuilder $slotFieldDefinitionBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Activity $activity): array
    {
        return [
            'fflogs_zone_id' => $activity->activityTypeVersion?->fflogs_zone_id,
            'pending_application_count' => $activity->applications->count(),
            'queue_filters' => [
                'slot_fields' => $this->serializeQueueSlotFields($activity->activityTypeVersion),
                'milestones' => $this->serializeQueueMilestones($activity->activityTypeVersion),
            ],
            'applications' => $activity->applications
                ->map(fn ($application) => $this->serializeApplication($application, $activity->activityTypeVersion))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeApplication($application, ?ActivityTypeVersion $activityTypeVersion): array
    {
        return [
            'id' => $application->id,
            'user' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'avatar_url' => $application->user->avatar_url,
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
                    $query->where('status', '!=', Activity::STATUS_CANCELLED)
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
