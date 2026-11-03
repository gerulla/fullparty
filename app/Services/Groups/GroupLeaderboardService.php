<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GroupLeaderboardService
{
    private int $activityBatchSize = 25;

    private const HOST_SUCCESS_AUTO_WEIGHT = 0.5;

    private const HOST_SUCCESS_PRIOR_BASELINE = 0.6;

    private const HOST_SUCCESS_PRIOR_WEIGHT = 10;

    private const HOST_SUCCESS_MIN_COMPLETED_RUNS = 2;

    public function __construct(private readonly GroupCompletedParticipationService $participation) {}

    /** Configure how many complete activity rosters are processed together. */
    public function batchSize(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The leaderboard batch size must be positive.');
        }

        $service = clone $this;
        $service->activityBatchSize = $size;

        return $service;
    }

    /** @return array<string, mixed> */
    public function calculate(Group $group): array
    {
        $now = CarbonImmutable::now();
        $counts = ['overall' => [], 'raid_leaders' => [], 'hosts' => []];
        $outcomes = [];

        $this->eachActivityBatch($group, function (array $ids) use ($group, &$counts, &$outcomes) {
            foreach ($this->participation->records($group, $ids) as $record) {
                $this->accumulateCount($counts['overall'], $record);
            }
            foreach ($this->designatedSlotRecords($group, 'is_raid_leader', $ids) as $record) {
                $this->accumulateCount($counts['raid_leaders'], $record);
            }
            foreach ($this->designatedSlotRecords($group, 'is_host', $ids) as $record) {
                $this->accumulateCount($counts['hosts'], $record);
                $outcome = $this->activityHostSuccessOutcome($record['activity']);
                $id = $record['character_id'];
                $outcomes[$id][$outcome] = ($outcomes[$id][$outcome] ?? 0) + 1;
            }
        });

        $hostCount = array_sum(array_column($counts['hosts'], 'count'));

        return [
            'generated_at' => $now->toIso8601String(),
            'summary' => [
                'total_participations' => array_sum(array_column($counts['overall'], 'count')),
                'ranked_participants' => count($counts['overall']),
                'raid_leader_participations' => array_sum(array_column($counts['raid_leaders'], 'count')),
                'host_participations' => $hostCount,
                'completed_hosted_runs' => $hostCount,
            ],
            'rankings' => [
                'overall' => $this->countRanking($counts['overall']),
                'raid_leaders' => $this->countRanking($counts['raid_leaders']),
                'hosts' => $this->countRanking($counts['hosts']),
                'host_success' => $this->hostSuccessRanking($counts['hosts'], $outcomes),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function ranking(Group $group, string $category, string $period = 'all_time', string $limit = '10'): array
    {
        if (! in_array($category, ['overall', 'raid_leaders', 'hosts'], true)
            || ! in_array($period, ['all_time', 'past_6_months', 'past_30_days'], true)
            || ! in_array($limit, ['10', '100', 'all'], true)) {
            throw new InvalidArgumentException('Invalid leaderboard ranking options.');
        }

        $cutoff = match ($period) {
            'past_6_months' => CarbonImmutable::now()->subMonths(6),
            'past_30_days' => CarbonImmutable::now()->subDays(30),
            default => null,
        };
        $counts = [];
        $this->eachActivityBatch($group, function (array $ids) use ($group, $category, $cutoff, &$counts) {
            $records = match ($category) {
                'overall' => $this->participation->records($group, $ids),
                'raid_leaders' => $this->designatedSlotRecords($group, 'is_raid_leader', $ids),
                'hosts' => $this->designatedSlotRecords($group, 'is_host', $ids),
            };
            foreach ($records as $record) {
                if ($cutoff === null || $record['activity_date']?->greaterThanOrEqualTo($cutoff)) {
                    $this->accumulateCount($counts, $record);
                }
            }
        });

        return $this->countRanking($counts, $limit === 'all' ? null : (int) $limit);
    }

    private function eachActivityBatch(Group $group, callable $callback): void
    {
        Activity::query()
            ->where('group_id', $group->id)
            ->where(fn ($query) => $query->where('status', Activity::STATUS_COMPLETE)->orWhere('is_completed', true))
            ->select('id')
            ->chunkById($this->activityBatchSize, fn (Collection $activities) => $callback($activities->pluck('id')->all()));
    }

    /** Keep only per-character totals, never the full history or Eloquent models. */
    private function accumulateCount(array &$counts, array $record): void
    {
        $id = $record['character_id'];
        $counts[$id] ??= [
            'character' => $this->serializeCharacter($record['character']),
            'count' => 0,
            'latest_activity_at' => null,
            'first_order' => $record['record_order'],
        ];
        $counts[$id]['count']++;
        $date = $record['activity_date']?->toIso8601String();
        if ($date !== null && ($counts[$id]['latest_activity_at'] === null
            || $record['activity_date']->greaterThan(CarbonImmutable::parse($counts[$id]['latest_activity_at'])))) {
            $counts[$id]['latest_activity_at'] = $date;
        }
        if ($record['record_order'] < $counts[$id]['first_order']) {
            $counts[$id]['first_order'] = $record['record_order'];
            $counts[$id]['character'] = $this->serializeCharacter($record['character']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function countRanking(array $counts, ?int $limit = 10): array
    {
        $ranking = collect($counts)->sort(fn ($left, $right) => $this->compareCountEntries($left, $right)
            ?: ($left['first_order'] <=> $right['first_order']))->values();
        if ($limit !== null) {
            $ranking = $ranking->take($limit);
        }

        return $ranking->map(function (array $entry, int $index) {
            unset($entry['first_order']);

            return array_merge(['rank' => $index + 1], $entry);
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function hostSuccessRanking(array $hosts, array $outcomes): array
    {
        $totalRuns = array_sum(array_column($hosts, 'count'));
        $weightedTotal = array_sum(array_column($outcomes, 'documented_success'))
            + array_sum(array_column($outcomes, 'auto_success')) * self::HOST_SUCCESS_AUTO_WEIGHT;
        $baseline = $totalRuns > 0 ? $weightedTotal / $totalRuns : self::HOST_SUCCESS_PRIOR_BASELINE;
        $priorWeightedSuccesses = $baseline * self::HOST_SUCCESS_PRIOR_WEIGHT;

        return collect($hosts)->map(function (array $host, int $id) use ($outcomes, $priorWeightedSuccesses) {
            $documented = $outcomes[$id]['documented_success'] ?? 0;
            $auto = $outcomes[$id]['auto_success'] ?? 0;
            $successful = $documented + $auto;
            $weighted = $documented + $auto * self::HOST_SUCCESS_AUTO_WEIGHT;
            $runs = $host['count'];

            return [
                'character' => $host['character'],
                'hosted_runs' => $runs,
                'successful_runs' => $successful,
                'documented_successes' => $documented,
                'auto_successes' => $auto,
                'failed_runs' => $outcomes[$id]['failure'] ?? 0,
                'weighted_successes' => (float) round($weighted, 1),
                'success_rate' => (float) round($successful / $runs * 100, 1),
                'weighted_success_rate' => (float) round($weighted / $runs * 100, 1),
                'performance_score' => (float) round(($weighted + $priorWeightedSuccesses) / ($runs + self::HOST_SUCCESS_PRIOR_WEIGHT) * 100, 1),
                'latest_activity_at' => $host['latest_activity_at'],
                'first_order' => $host['first_order'],
            ];
        })
            ->filter(fn ($entry) => $entry['hosted_runs'] >= self::HOST_SUCCESS_MIN_COMPLETED_RUNS)
            ->sort(fn ($left, $right) => $this->compareSuccessEntries($left, $right)
                ?: ($left['first_order'] <=> $right['first_order']))
            ->values()->take(10)
            ->map(function (array $entry, int $index) {
                unset($entry['first_order']);

                return array_merge(['rank' => $index + 1], $entry);
            })->all();
    }

    /**
     * @param  list<int>  $activityIds
     * @return Collection<int, array{record_order: array{int, int}, activity_id: int, character_id: int, character: Character|null, activity: Activity|null, activity_date: CarbonImmutable|null}>
     */
    private function designatedSlotRecords(Group $group, string $column, array $activityIds): Collection
    {
        return ActivitySlot::query()
            ->whereIn('activity_id', $activityIds)
            ->whereHas('activity', fn ($query) => $query
                ->where('group_id', $group->id)
                ->where(function ($query) {
                    $query
                        ->where('status', Activity::STATUS_COMPLETE)
                        ->orWhere('is_completed', true);
                }))
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->where($column, true)
            ->whereNotNull('assigned_character_id')
            ->with([
                'activity:id,group_id,activity_type_version_id,status,is_completed,starts_at,target_prog_point_key,furthest_progress_key',
                'activity.activityTypeVersion:id,prog_points',
                'assignedCharacter:id,name,world,datacenter,avatar_url',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'activity_id',
                'assigned_character_id',
                'updated_at',
                $column,
            ])
            ->toBase()
            ->map(fn (ActivitySlot $slot) => [
                'record_order' => [1, (int) $slot->id],
                'activity_id' => (int) $slot->activity_id,
                'character_id' => (int) $slot->assigned_character_id,
                'character' => $slot->assignedCharacter,
                'activity' => $slot->activity,
                'activity_date' => $this->toImmutable($slot->activity?->starts_at ?? $slot->updated_at),
            ])
            ->groupBy(fn (array $record) => "{$record['activity_id']}:{$record['character_id']}")
            ->map(fn (Collection $records) => $records->first())
            ->filter()
            ->values();
    }

    private function compareCountEntries(array $left, array $right): int
    {
        $countComparison = $right['count'] <=> $left['count'];

        if ($countComparison !== 0) {
            return $countComparison;
        }

        return strcmp($left['character']['name'] ?? '', $right['character']['name'] ?? '');
    }

    private function compareSuccessEntries(array $left, array $right): int
    {
        $scoreComparison = $right['performance_score'] <=> $left['performance_score'];

        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        $weightedRateComparison = $right['weighted_success_rate'] <=> $left['weighted_success_rate'];

        if ($weightedRateComparison !== 0) {
            return $weightedRateComparison;
        }

        $rateComparison = $right['success_rate'] <=> $left['success_rate'];

        if ($rateComparison !== 0) {
            return $rateComparison;
        }

        $documentedComparison = $right['documented_successes'] <=> $left['documented_successes'];

        if ($documentedComparison !== 0) {
            return $documentedComparison;
        }

        $successComparison = $right['successful_runs'] <=> $left['successful_runs'];

        if ($successComparison !== 0) {
            return $successComparison;
        }

        $hostedComparison = $right['hosted_runs'] <=> $left['hosted_runs'];

        if ($hostedComparison !== 0) {
            return $hostedComparison;
        }

        return strcmp($left['character']['name'] ?? '', $right['character']['name'] ?? '');
    }

    private function activityHostSuccessOutcome(Activity $activity): string
    {
        if (blank($activity->target_prog_point_key)) {
            return 'auto_success';
        }

        return $this->activityReachedTarget($activity)
            ? 'documented_success'
            : 'failure';
    }

    private function activityReachedTarget(Activity $activity): bool
    {
        if (blank($activity->target_prog_point_key)) {
            return true;
        }

        if (blank($activity->furthest_progress_key)) {
            return false;
        }

        if ($activity->target_prog_point_key === $activity->furthest_progress_key) {
            return true;
        }

        $progPoints = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->sortBy(fn (array $point, int $index) => (int) ($point['order'] ?? $index + 1))
            ->values();
        $targetIndex = $progPoints->search(fn (array $point) => ($point['key'] ?? null) === $activity->target_prog_point_key);
        $furthestIndex = $progPoints->search(fn (array $point) => ($point['key'] ?? null) === $activity->furthest_progress_key);

        if ($targetIndex === false || $furthestIndex === false) {
            return false;
        }

        return $furthestIndex >= $targetIndex;
    }

    /**
     * @return array{id: int|null, name: string, world: string|null, datacenter: string|null, avatar_url: string|null}
     */
    private function serializeCharacter(?Character $character): array
    {
        return [
            'id' => $character?->id,
            'name' => $character?->name ?? __('ui.unknown_character'),
            'world' => $character?->world,
            'datacenter' => $character?->datacenter,
            'avatar_url' => $character?->avatar_url,
        ];
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (filled($value)) {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
