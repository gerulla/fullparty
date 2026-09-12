<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CharacterActivityHistoryService
{
    public function completedRuns(Activity $activity, int $characterId): Collection
    {
        $eligibleSlot = fn (Builder $query) => $query
            ->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_BENCH)
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->where(fn (Builder $query) => $query->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_FILL_IN)->orWhereNotNull('filled_group_key'));

        return Activity::query()
            ->where('activity_type_id', $activity->activity_type_id)
            ->where('status', Activity::STATUS_COMPLETE)
            ->whereHas('activityTypeVersion', fn (Builder $query) => $query->where('difficulty', $activity->activityTypeVersion?->difficulty))
            ->where(function (Builder $query) use ($characterId, $eligibleSlot) {
                $query->whereHas('slots', function (Builder $query) use ($characterId, $eligibleSlot) {
                    $eligibleSlot($query);
                    $query->where('assigned_character_id', $characterId)
                        ->whereDoesntHave('assignments', fn (Builder $query) => $query
                            ->where('character_id', $characterId)->where('attendance_status', ActivitySlotAssignment::STATUS_MISSING));
                })->orWhereHas('slotAssignments', function (Builder $query) use ($characterId, $eligibleSlot) {
                    $query->where('character_id', $characterId)
                        ->whereIn('attendance_status', [ActivitySlotAssignment::STATUS_ASSIGNED, ActivitySlotAssignment::STATUS_CHECKED_IN, ActivitySlotAssignment::STATUS_LATE])
                        ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhereColumn('ended_at', '>=', 'activities.starts_at'))
                        ->whereHas('slot', $eligibleSlot);
                });
            })
            ->with(['progressMilestones', 'activityTypeVersion:id,progress_schema'])
            ->get(['id', 'activity_type_version_id', 'furthest_progress_key']);
    }

    public function sameMilestone(array $current, array $historical): bool
    {
        $currentId = (int) data_get($current, 'fflogs_matcher.encounter_id', 0);
        $historicalId = (int) data_get($historical, 'fflogs_matcher.encounter_id', 0);
        if ($currentId > 0 && $historicalId > 0) {
            $type = data_get($current, 'fflogs_matcher.type', 'encounter');

            return $currentId === $historicalId
                && $type === data_get($historical, 'fflogs_matcher.type', 'encounter')
                && ($type !== 'phase' || data_get($current, 'fflogs_matcher.phase_id') === data_get($historical, 'fflogs_matcher.phase_id'));
        }

        return ($current['key'] ?? null) === ($historical['key'] ?? null);
    }
}
