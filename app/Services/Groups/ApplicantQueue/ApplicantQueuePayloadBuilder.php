<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ApplicantQueuePayloadBuilder
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ApplicantMilestoneResolver $milestoneResolver,
        private readonly ApplicationAnswerPresenter $answerPresenter,
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
                ->map(fn ($application) => $this->serializeActivityApplication($application, $activity->activityTypeVersion))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivityApplication($application, ?ActivityTypeVersion $activityTypeVersion): array
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
        return collect($activityTypeVersion?->slot_schema ?? [])
            ->map(fn (array $field) => [
                'key' => (string) ($field['key'] ?? ''),
                'application_key' => $this->resolveSlotFieldApplicationKey($field, $activityTypeVersion),
                'label' => is_array($field['label'] ?? null)
                    ? $field['label']
                    : ['en' => (string) ($field['key'] ?? '')],
                'type' => (string) ($field['type'] ?? 'text'),
                'source' => $field['source'] ?? null,
                'options' => $this->resolveSchemaFieldOptions($field),
            ])
            ->filter(fn (array $field) => $field['key'] !== '' && $field['application_key'] !== '' && count($field['options']) > 0)
            ->values()
            ->all();
    }

    private function resolveSlotFieldApplicationKey(array $slotField, ?ActivityTypeVersion $activityTypeVersion): string
    {
        $slotKey = (string) ($slotField['key'] ?? '');
        $slotSource = $slotField['source'] ?? null;
        $applicationSchema = collect($activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null))
            ->values();

        if ($slotKey === '' || $applicationSchema->isEmpty()) {
            return '';
        }

        $exactMatch = $applicationSchema
            ->first(fn (array $question) => (string) ($question['key'] ?? '') === $slotKey);

        if (is_array($exactMatch)) {
            return (string) $exactMatch['key'];
        }

        $sourceAwareMatch = $applicationSchema->first(function (array $question) use ($slotKey, $slotSource) {
            if (($question['source'] ?? null) !== $slotSource) {
                return false;
            }

            return Str::contains((string) ($question['key'] ?? ''), $slotKey);
        });

        if (is_array($sourceAwareMatch)) {
            return (string) $sourceAwareMatch['key'];
        }

        $fallbackMatch = $applicationSchema
            ->first(fn (array $question) => ($question['source'] ?? null) === $slotSource);

        return is_array($fallbackMatch)
            ? (string) ($fallbackMatch['key'] ?? '')
            : '';
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
     * @return array<int, array<string, mixed>>
     */
    private function resolveSchemaFieldOptions(array $field): array
    {
        return match ($field['source'] ?? null) {
            'character_classes' => CharacterClass::query()
                ->orderBy('name')
                ->get()
                ->map(fn (CharacterClass $characterClass) => [
                    'key' => (string) $characterClass->id,
                    'label' => ['en' => $characterClass->name],
                    'meta' => [
                        'icon_url' => $characterClass->icon_url,
                        'role' => $characterClass->role,
                        'shorthand' => $characterClass->shorthand,
                    ],
                ])
                ->values()
                ->all(),
            'phantom_jobs' => PhantomJob::query()
                ->orderBy('name')
                ->get()
                ->map(fn (PhantomJob $phantomJob) => [
                    'key' => (string) $phantomJob->id,
                    'label' => ['en' => $phantomJob->name],
                    'meta' => [
                        'icon_url' => $phantomJob->icon_url,
                    ],
                ])
                ->values()
                ->all(),
            'static_options' => collect($field['options'] ?? [])
                ->map(fn (array $option) => [
                    'key' => (string) ($option['key'] ?? $option['value'] ?? ''),
                    'label' => is_array($option['label'] ?? null)
                        ? $option['label']
                        : ['en' => (string) ($option['key'] ?? $option['value'] ?? '')],
                    'meta' => is_array($option['meta'] ?? null) ? $option['meta'] : null,
                ])
                ->filter(fn (array $option) => $option['key'] !== '')
                ->values()
                ->all(),
            default => [],
        };
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
