<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Support\Collection;

final class GroupMemberActivitySummaryService
{
    /**
     * @return array{last_group_run: array<string, mixed>|null, last_run: array<string, mixed>|null, recent_runs: array<int, array<string, mixed>>}
     */
    public function forMember(Group $group, User $user): array
    {
        $characterIds = $user->characters()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recentRuns = $this->recentParticipations($characterIds, $group->id, $group, 30);
        $latestGroupRun = $recentRuns[0] ?? null;

        return [
            'last_group_run' => $latestGroupRun,
            'last_run' => $latestGroupRun,
            'recent_runs' => $recentRuns,
        ];
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return array<int, array<string, mixed>>
     */
    private function recentParticipations(array $characterIds, ?int $groupId, Group $contextGroup, int $limit): array
    {
        if ($characterIds === []) {
            return [];
        }

        return $this->participationRecords($characterIds, $groupId)
            ->groupBy('activity_id')
            ->map(fn (Collection $records) => $records
                ->sort(fn (array $left, array $right) => $this->compareParticipationRecords($left, $right))
                ->first())
            ->filter()
            ->sort(fn (array $left, array $right) => $this->compareParticipationRecords($left, $right))
            ->take($limit)
            ->map(fn (array $record) => $this->serializeRun(
                $record['activity'],
                $record['character'],
                $record['snapshot'],
                $contextGroup,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return Collection<int, array{activity_id: int, activity: Activity|null, character: Character|null, snapshot: array<string, mixed>, timestamp: int, source_priority: int, record_id: int}>
     */
    private function participationRecords(array $characterIds, ?int $groupId): Collection
    {
        return $this->assignmentParticipationRecords($characterIds, $groupId)
            ->merge($this->currentSlotParticipationRecords($characterIds, $groupId));
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return Collection<int, array{activity_id: int, activity: Activity|null, character: Character|null, snapshot: array<string, mixed>, timestamp: int, source_priority: int, record_id: int}>
     */
    private function assignmentParticipationRecords(array $characterIds, ?int $groupId): Collection
    {
        return ActivitySlotAssignment::query()
            ->select('activity_slot_assignments.*')
            ->join('activities', 'activities.id', '=', 'activity_slot_assignments.activity_id')
            ->join('activity_slots', 'activity_slots.id', '=', 'activity_slot_assignments.activity_slot_id')
            ->with([
                'activity.group:id,name,slug,is_visible',
                'activity.activityTypeVersion:id,name,small_image_url,banner_image_url',
                'character:id,user_id,name,world,datacenter,avatar_url',
                'slot.fieldValues',
            ])
            ->whereIn('activity_slot_assignments.character_id', $characterIds)
            ->whereIn('activity_slot_assignments.attendance_status', [
                ActivitySlotAssignment::STATUS_ASSIGNED,
                ActivitySlotAssignment::STATUS_CHECKED_IN,
                ActivitySlotAssignment::STATUS_LATE,
            ])
            ->where('activity_slots.group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->where(function ($query) {
                $query
                    ->where('activities.status', Activity::STATUS_COMPLETE)
                    ->orWhere('activities.is_completed', true);
            })
            ->when($groupId !== null, fn ($query) => $query->where('activities.group_id', $groupId))
            ->orderByDesc('activities.completed_at')
            ->orderByDesc('activities.starts_at')
            ->orderByDesc('activity_slot_assignments.assigned_at')
            ->orderByDesc('activity_slot_assignments.id')
            ->get()
            ->toBase()
            ->map(function (ActivitySlotAssignment $assignment) {
                $snapshot = is_array($assignment->field_values_snapshot)
                    ? $assignment->field_values_snapshot
                    : $this->slotFieldValueSnapshot($assignment->slot);

                return [
                    'activity_id' => (int) $assignment->activity_id,
                    'activity' => $assignment->activity,
                    'character' => $assignment->character,
                    'snapshot' => $snapshot,
                    'timestamp' => $this->activityTimestamp($assignment->activity),
                    'source_priority' => 0,
                    'record_id' => (int) $assignment->id,
                ];
            });
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return Collection<int, array{activity_id: int, activity: Activity|null, character: Character|null, snapshot: array<string, mixed>, timestamp: int, source_priority: int, record_id: int}>
     */
    private function currentSlotParticipationRecords(array $characterIds, ?int $groupId): Collection
    {
        return ActivitySlot::query()
            ->select('activity_slots.*')
            ->join('activities', 'activities.id', '=', 'activity_slots.activity_id')
            ->with([
                'activity.group:id,name,slug,is_visible',
                'activity.activityTypeVersion:id,name,small_image_url,banner_image_url',
                'assignedCharacter:id,user_id,name,world,datacenter,avatar_url',
                'fieldValues',
            ])
            ->whereIn('activity_slots.assigned_character_id', $characterIds)
            ->where('activity_slots.group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->where(function ($query) {
                $query
                    ->where('activities.status', Activity::STATUS_COMPLETE)
                    ->orWhere('activities.is_completed', true);
            })
            ->when($groupId !== null, fn ($query) => $query->where('activities.group_id', $groupId))
            ->orderByDesc('activities.completed_at')
            ->orderByDesc('activities.starts_at')
            ->orderByDesc('activity_slots.updated_at')
            ->orderByDesc('activity_slots.id')
            ->get()
            ->toBase()
            ->map(fn (ActivitySlot $slot) => [
                'activity_id' => (int) $slot->activity_id,
                'activity' => $slot->activity,
                'character' => $slot->assignedCharacter,
                'snapshot' => $this->slotFieldValueSnapshot($slot),
                'timestamp' => $this->activityTimestamp($slot->activity),
                'source_priority' => 1,
                'record_id' => (int) $slot->id,
            ]);
    }

    /**
     * @param  array{timestamp: int, source_priority: int, record_id: int}  $left
     * @param  array{timestamp: int, source_priority: int, record_id: int}  $right
     */
    private function compareParticipationRecords(array $left, array $right): int
    {
        $timestampComparison = $right['timestamp'] <=> $left['timestamp'];

        if ($timestampComparison !== 0) {
            return $timestampComparison;
        }

        $priorityComparison = $right['source_priority'] <=> $left['source_priority'];

        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }

        return $right['record_id'] <=> $left['record_id'];
    }

    private function activityTimestamp(?Activity $activity): int
    {
        return ($activity?->completed_at ?? $activity?->starts_at)?->getTimestamp() ?? 0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function serializeRun(?Activity $activity, ?Character $character, array $snapshot, Group $contextGroup): ?array
    {
        if (! $activity || ! $character) {
            return null;
        }

        $activityGroup = $activity->group;

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'activity_type_name' => $this->resolveLocalizedText($activity->activityTypeVersion?->name),
            'activity_icon_url' => $activity->activityTypeVersion?->small_image_url
                ?: $activity->activityTypeVersion?->banner_image_url,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'character' => [
                'id' => $character->id,
                'name' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'avatar_url' => $character->avatar_url,
            ],
            'character_class' => $this->serializeCharacterClass($this->firstSnapshotItem($snapshot, 'class')),
            'phantom_job' => $this->serializePhantomJob($this->firstSnapshotItem($snapshot, 'phantom_job')),
            'group' => $activityGroup?->is_visible ? [
                'id' => $activityGroup->id,
                'name' => $activityGroup->name,
                'slug' => $activityGroup->slug,
                'is_current_group' => (int) $activityGroup->id === (int) $contextGroup->id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slotFieldValueSnapshot(?ActivitySlot $slot): array
    {
        if (! $slot) {
            return [];
        }

        $slot->loadMissing('fieldValues');

        return $slot->fieldValues
            ->mapWithKeys(fn ($fieldValue) => [$fieldValue->field_key => $fieldValue->value])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function firstSnapshotItem(array $snapshot, string $kind): ?array
    {
        foreach ($snapshot as $fieldKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            $values = array_is_list($value) && isset($value[0]) && is_array($value[0])
                ? $value
                : [$value];

            foreach ($values as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if ($kind === 'class' && $this->isClassSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }

                if ($kind === 'phantom_job' && $this->isPhantomJobSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isClassSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'class')
            || array_key_exists('role', $entry)
            || array_key_exists('shorthand', $entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isPhantomJobSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'phantom')
            || (str_contains($fieldKey, 'job') && ! $this->isClassSnapshotEntry($fieldKey, $entry));
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{id: int|null, name: string|null, shorthand: string|null, role: string|null, icon_url: string|null, flaticon_url: string|null}|null
     */
    private function serializeCharacterClass(?array $item): ?array
    {
        if (! $item) {
            return null;
        }

        $characterClass = filled($item['id'] ?? null)
            ? CharacterClass::query()->select(['id', 'name', 'shorthand', 'role', 'icon_url', 'flaticon_url'])->find((int) $item['id'])
            : null;

        return [
            'id' => $characterClass?->id ?? (isset($item['id']) ? (int) $item['id'] : null),
            'name' => $characterClass?->name ?? ($item['name'] ?? $item['label'] ?? null),
            'shorthand' => $characterClass?->shorthand ?? ($item['shorthand'] ?? null),
            'role' => $characterClass?->role ?? ($item['role'] ?? null),
            'icon_url' => $characterClass?->icon_url ?? ($item['icon_url'] ?? null),
            'flaticon_url' => $characterClass?->flaticon_url ?? ($item['flaticon_url'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{id: int|null, name: string|null, icon_url: string|null, transparent_icon_url: string|null}|null
     */
    private function serializePhantomJob(?array $item): ?array
    {
        if (! $item) {
            return null;
        }

        $phantomJob = filled($item['id'] ?? null)
            ? PhantomJob::query()->select(['id', 'name', 'icon_url', 'transparent_icon_url'])->find((int) $item['id'])
            : null;

        return [
            'id' => $phantomJob?->id ?? (isset($item['id']) ? (int) $item['id'] : null),
            'name' => $phantomJob?->name ?? ($item['name'] ?? $item['label'] ?? null),
            'icon_url' => $phantomJob?->icon_url ?? ($item['icon_url'] ?? null),
            'transparent_icon_url' => $phantomJob?->transparent_icon_url ?? ($item['transparent_icon_url'] ?? null),
        ];
    }

    private function resolveLocalizedText(mixed $value): ?string
    {
        if (is_string($value)) {
            return filled($value) ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ([app()->getLocale(), config('app.fallback_locale'), 'en', 'de', 'fr', 'ja'] as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            $candidate = $value[$locale] ?? null;

            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
