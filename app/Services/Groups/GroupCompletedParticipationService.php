<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GroupCompletedParticipationService
{
    /**
     * @param  list<int>|null  $activityIds
     * @return Collection<int, array{
     *     activity_id: int,
     *     character_id: int,
     *     user_id: int|null,
     *     character: Character|null,
     *     activity: Activity|null,
     *     activity_date: CarbonImmutable|null,
     *     source_priority: int,
     *     source_id: int,
     *     record_order: array{int, int}
     * }>
     */
    public function records(Group $group, ?array $activityIds = null): Collection
    {
        $currentSlotRecords = $this->currentSlotRecords($group, $activityIds);
        $currentSlotActivityIds = $currentSlotRecords
            ->pluck('activity_id')
            ->unique()
            ->flip();

        $assignmentRecords = $this->assignmentRecords($group, $activityIds)
            ->reject(fn (array $record) => $currentSlotActivityIds->has($record['activity_id']));

        return $assignmentRecords
            ->merge($currentSlotRecords)
            ->groupBy(fn (array $record) => "{$record['activity_id']}:{$record['character_id']}")
            ->map(function (Collection $records) {
                $first = $records->first();
                $selected = $records->sort(function (array $left, array $right) {
                    $priorityComparison = $right['source_priority'] <=> $left['source_priority'];

                    if ($priorityComparison !== 0) {
                        return $priorityComparison;
                    }

                    return ($right['activity_date']?->getTimestamp() ?? 0)
                        <=> ($left['activity_date']?->getTimestamp() ?? 0);
                })
                    ->first();
                $selected['record_order'] = [$first['source_priority'], $first['source_id']];

                return $selected;
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function countsByUser(Group $group): Collection
    {
        return $this->records($group)
            ->filter(fn (array $record) => $record['user_id'] !== null)
            ->groupBy('user_id')
            ->map(fn (Collection $records) => $records->count());
    }

    /**
     * @param  list<int>|null  $activityIds
     * @return Collection<int, array{
     *     activity_id: int,
     *     character_id: int,
     *     user_id: int|null,
     *     character: Character|null,
     *     activity: Activity|null,
     *     activity_date: CarbonImmutable|null,
     *     source_priority: int,
     *     source_id: int
     * }>
     */
    private function assignmentRecords(Group $group, ?array $activityIds): Collection
    {
        return ActivitySlotAssignment::query()
            ->where('activity_slot_assignments.group_id', $group->id)
            ->when($activityIds !== null, fn ($query) => $query->whereIn('activity_id', $activityIds))
            ->whereIn('activity_slot_assignments.attendance_status', [
                ActivitySlotAssignment::STATUS_ASSIGNED,
                ActivitySlotAssignment::STATUS_CHECKED_IN,
                ActivitySlotAssignment::STATUS_LATE,
            ])
            ->whereHas('activity', fn ($query) => $this->scopeCompletedGroupActivities($query, $group))
            ->whereHas('slot', fn ($query) => $query->where('group_key', '!=', ActivitySlotBench::GROUP_KEY))
            ->with([
                'activity:id,group_id,starts_at,status,is_completed',
                'character:id,user_id,name,world,datacenter,avatar_url',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'activity_id',
                'group_id',
                'activity_slot_id',
                'character_id',
                'attendance_status',
                'assigned_at',
                'ended_at',
            ])
            ->toBase()
            ->filter(fn (ActivitySlotAssignment $assignment) => $assignment->character_id !== null)
            ->map(fn (ActivitySlotAssignment $assignment) => [
                'activity_id' => (int) $assignment->activity_id,
                'character_id' => (int) $assignment->character_id,
                'user_id' => $assignment->character?->user_id ? (int) $assignment->character->user_id : null,
                'character' => $assignment->character,
                'activity' => $assignment->activity,
                'activity_date' => $this->toImmutable($assignment->activity?->starts_at ?? $assignment->assigned_at),
                'source_priority' => 0,
                'source_id' => (int) $assignment->id,
            ]);
    }

    /**
     * @param  list<int>|null  $activityIds
     * @return Collection<int, array{
     *     activity_id: int,
     *     character_id: int,
     *     user_id: int|null,
     *     character: Character|null,
     *     activity: Activity|null,
     *     activity_date: CarbonImmutable|null,
     *     source_priority: int,
     *     source_id: int
     * }>
     */
    private function currentSlotRecords(Group $group, ?array $activityIds): Collection
    {
        return ActivitySlot::query()
            ->when($activityIds !== null, fn ($query) => $query->whereIn('activity_id', $activityIds))
            ->whereHas('activity', fn ($query) => $this->scopeCompletedGroupActivities($query, $group))
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->whereNotNull('assigned_character_id')
            ->with([
                'activity:id,group_id,starts_at,status,is_completed',
                'assignedCharacter:id,user_id,name,world,datacenter,avatar_url',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'activity_id',
                'assigned_character_id',
                'updated_at',
            ])
            ->toBase()
            ->map(fn (ActivitySlot $slot) => [
                'activity_id' => (int) $slot->activity_id,
                'character_id' => (int) $slot->assigned_character_id,
                'user_id' => $slot->assignedCharacter?->user_id ? (int) $slot->assignedCharacter->user_id : null,
                'character' => $slot->assignedCharacter,
                'activity' => $slot->activity,
                'activity_date' => $this->toImmutable($slot->activity?->starts_at ?? $slot->updated_at),
                'source_priority' => 1,
                'source_id' => (int) $slot->id,
            ]);
    }

    private function scopeCompletedGroupActivities($query, Group $group): void
    {
        $query
            ->where('group_id', $group->id)
            ->where(function ($query) {
                $query
                    ->where('status', Activity::STATUS_COMPLETE)
                    ->orWhere('is_completed', true);
            });
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
