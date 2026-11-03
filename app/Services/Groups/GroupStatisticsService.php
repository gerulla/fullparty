<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GroupStatisticsService
{
    private int $activityBatchSize = 25;

    private int $applicationBatchSize = 500;

    private const APPLICATION_BUCKETS = ['pending', 'approved', 'declined', 'cancelled', 'withdrawn'];

    /** Configure how many whole activity rosters are processed together. */
    public function batchSize(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The statistics batch size must be positive.');
        }

        $service = clone $this;
        $service->activityBatchSize = $size;

        return $service;
    }

    public function applicationBatchSize(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The application batch size must be positive.');
        }

        $service = clone $this;
        $service->applicationBatchSize = $size;

        return $service;
    }

    /** @return array<string, mixed> */
    public function calculate(Group $group): array
    {
        $now = CarbonImmutable::now();
        $activeSince = $now->subMonth()->startOfDay();
        $trendStart = $now->subDays(29)->startOfDay();
        $monthSeriesStart = $now->startOfMonth()->subMonths(11);
        $months = $this->monthKeys($monthSeriesStart, $now);
        $totalRuns = 0;
        $totalParticipants = 0;
        $runsWithParticipants = 0;
        $uniqueCharacters = [];
        $activeCharacters = [];
        $trend = [];
        $classes = $this->emptyLoadoutCounts($months);
        $phantomJobs = $this->emptyLoadoutCounts($months);

        Activity::query()
            ->where('group_id', $group->id)
            ->select(['id', 'starts_at'])
            ->chunkById($this->activityBatchSize, function (Collection $activities) use (
                $group, $now, $activeSince, $trendStart,
                &$totalRuns, &$totalParticipants, &$runsWithParticipants,
                &$uniqueCharacters, &$activeCharacters, &$trend, &$classes, &$phantomJobs,
            ) {
                $records = $this->deduplicatedParticipantRecords($group, $activities->pluck('id')->all());
                $participantsByRun = $records->countBy('activity_id');
                $totalRuns += $activities->count();
                $totalParticipants += $records->count();
                $runsWithParticipants += $participantsByRun->count();

                foreach ($activities as $activity) {
                    $date = $this->toImmutable($activity->starts_at);
                    if ($date?->betweenIncluded($trendStart, $now)) {
                        $day = $date->toDateString();
                        $trend[$day] ??= ['date' => $day, 'run_count' => 0, 'participant_count' => 0];
                        $trend[$day]['run_count']++;
                        $trend[$day]['participant_count'] += $participantsByRun->get($activity->id, 0);
                    }
                }

                foreach ($records as $record) {
                    $uniqueCharacters[$record['character_id']] = true;
                    if ($record['activity_date']?->betweenIncluded($activeSince, $now)) {
                        $activeCharacters[$record['character_id']] = true;
                    }
                    $this->accumulateLoadout($classes, $record, 'class');
                    $this->accumulateLoadout($phantomJobs, $record, 'phantom_job');
                }
            });

        ksort($trend);

        return [
            'generated_at' => $now->toIso8601String(),
            'summary' => [
                'total_runs' => $totalRuns,
                'runs_with_participants' => $runsWithParticipants,
                'total_participants' => $totalParticipants,
                'unique_participants' => count($uniqueCharacters),
                'average_participants_per_raid' => $totalRuns > 0 ? round($totalParticipants / $totalRuns, 1) : 0.0,
                'active_players_past_month' => count($activeCharacters),
            ],
            'participation_trend' => array_values($trend),
            'applications' => $this->buildApplicationStatistics($group, $now->subDays(6)->startOfDay(), $monthSeriesStart, $now),
            'classes' => $this->buildLoadoutStatistics($classes, 'class', $months),
            'phantom_jobs' => $this->buildLoadoutStatistics($phantomJobs, 'phantom_job', $months),
        ];
    }

    /**
     * @param  list<int>  $activityIds
     * @return Collection<int, array{
     *     activity_id: int,
     *     character_id: int,
     *     field_values_snapshot: array<string, mixed>,
     *     activity_date: CarbonImmutable|null,
     *     assigned_at: CarbonImmutable|null,
     *     source_priority: int,
     *     source_id: int,
     *     record_order: array{int, int}
     * }>
     */
    private function deduplicatedParticipantRecords(Group $group, array $activityIds): Collection
    {
        $assignmentRecords = ActivitySlotAssignment::query()
            ->where('activity_slot_assignments.group_id', $group->id)
            ->whereIn('activity_id', $activityIds)
            ->whereHas('slot', fn ($query) => $query->where('group_key', '!=', ActivitySlotBench::GROUP_KEY))
            ->with([
                'activity:id,group_id,starts_at,status',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'activity_id',
                'group_id',
                'activity_slot_id',
                'character_id',
                'field_values_snapshot',
                'attendance_status',
                'assigned_at',
                'ended_at',
            ])
            ->toBase()
            ->filter(fn (ActivitySlotAssignment $assignment) => $assignment->character_id !== null)
            ->map(fn (ActivitySlotAssignment $assignment) => [
                'activity_id' => (int) $assignment->activity_id,
                'character_id' => (int) $assignment->character_id,
                'field_values_snapshot' => is_array($assignment->field_values_snapshot)
                    ? $assignment->field_values_snapshot
                    : [],
                'activity_date' => $this->assignmentActivityDate($assignment),
                'assigned_at' => $this->toImmutable($assignment->assigned_at),
                'source_priority' => 0,
                'source_id' => (int) $assignment->id,
            ]);

        $currentSlotRecords = ActivitySlot::query()
            ->whereIn('activity_id', $activityIds)
            ->whereHas('activity', fn ($query) => $query->where('group_id', $group->id))
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->whereNotNull('assigned_character_id')
            ->with([
                'activity:id,group_id,starts_at,status',
                'fieldValues:id,activity_slot_id,field_key,value',
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
                'field_values_snapshot' => $this->slotFieldValueSnapshot($slot),
                'activity_date' => $this->toImmutable($slot->activity?->starts_at),
                'assigned_at' => $this->toImmutable($slot->updated_at),
                'source_priority' => 1,
                'source_id' => (int) $slot->id,
            ]);

        return $assignmentRecords
            ->merge($currentSlotRecords)
            ->groupBy(fn (array $record) => "{$record['activity_id']}:{$record['character_id']}")
            ->map(function (Collection $records) {
                $first = $records->first();
                $selected = $records->sort(function (array $left, array $right) {
                    return ($right['source_priority'] <=> $left['source_priority'])
                        ?: (($right['assigned_at']?->getTimestamp() ?? 0)
                            <=> ($left['assigned_at']?->getTimestamp() ?? 0));
                })->first();

                // Preserve the original history-first order across activity batches.
                $selected['record_order'] = [$first['source_priority'], $first['source_id']];

                return $selected;
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function buildApplicationStatistics(Group $group, CarbonImmutable $daySeriesStart, CarbonImmutable $monthSeriesStart, CarbonImmutable $now): array
    {
        $total = 0;
        $distributionCounts = array_fill_keys(self::APPLICATION_BUCKETS, 0);
        $applicationsByDay = [];
        $applicationsByMonth = [];
        $months = $this->monthKeys($monthSeriesStart, $now);

        $applications = ActivityApplication::query()
            ->whereHas('activity', fn ($query) => $query->where('group_id', $group->id))
            ->select(['id', 'status', 'submitted_at', 'created_at'])
            ->lazyById($this->applicationBatchSize);

        foreach ($applications as $application) {
            $bucket = $this->applicationBucket($application->status);
            $date = $this->applicationDate($application);
            $total++;
            $distributionCounts[$bucket]++;

            if ($date?->betweenIncluded($daySeriesStart, $now)) {
                $day = $date->toDateString();
                $applicationsByDay[$day] = ($applicationsByDay[$day] ?? 0) + 1;
            }
            if ($date?->betweenIncluded($monthSeriesStart, $now)) {
                $month = $date->format('Y-m');
                $applicationsByMonth[$month][$bucket] = ($applicationsByMonth[$month][$bucket] ?? 0) + 1;
            }
        }

        return [
            'total' => $total,
            'distribution' => collect(self::APPLICATION_BUCKETS)->map(fn (string $bucket) => [
                'key' => $bucket,
                'count' => $distributionCounts[$bucket],
                'percent' => $total > 0 ? round($distributionCounts[$bucket] / $total * 100, 1) : 0.0,
            ])->all(),
            'daily_submissions' => collect(range(0, 6))->map(function (int $offset) use ($applicationsByDay, $daySeriesStart) {
                $date = $daySeriesStart->addDays($offset)->toDateString();

                return ['date' => $date, 'count' => $applicationsByDay[$date] ?? 0];
            })->all(),
            'volume_by_month' => $months->map(function (CarbonImmutable $month) use ($applicationsByMonth) {
                $counts = array_replace(array_fill_keys(self::APPLICATION_BUCKETS, 0), $applicationsByMonth[$month->format('Y-m')] ?? []);

                return ['month' => $month->toDateString(), 'total' => array_sum($counts), 'statuses' => $counts];
            })->all(),
        ];
    }

    /** @param Collection<int, CarbonImmutable> $months */
    private function emptyLoadoutCounts(Collection $months): array
    {
        return [
            'counts' => [], 'metadata' => [], 'metadata_order' => [], 'first_order' => [],
            'monthly' => array_fill_keys($months->map(fn ($month) => $month->format('Y-m'))->all(), []),
        ];
    }

    private function accumulateLoadout(array &$state, array $record, string $kind): void
    {
        $month = $record['activity_date']?->format('Y-m');
        foreach ($this->extractSnapshotItems($record['field_values_snapshot'], $kind) as $index => $item) {
            $key = $this->statItemKey($item);
            $order = [...$record['record_order'], $index];
            $state['counts'][$key] = ($state['counts'][$key] ?? 0) + 1;
            if (! isset($state['first_order'][$key]) || $order < $state['first_order'][$key]) {
                $state['first_order'][$key] = $order;
            }

            // Batches may visit records out of the original history/slot order.
            // Keep the same last-present value for each fallback metadata field.
            foreach ($item as $field => $value) {
                if (! isset($state['metadata_order'][$key][$field]) || $order > $state['metadata_order'][$key][$field]) {
                    $state['metadata'][$key][$field] = $value;
                    $state['metadata_order'][$key][$field] = $order;
                }
            }
            if ($month && array_key_exists($month, $state['monthly'])) {
                $state['monthly'][$month][$key] = ($state['monthly'][$month][$key] ?? 0) + 1;
            }
        }
    }

    /** @param Collection<int, CarbonImmutable> $months */
    private function buildLoadoutStatistics(array $state, string $kind, Collection $months): array
    {
        $total = array_sum($state['counts']);
        $keys = array_keys($state['counts']);
        usort($keys, fn (string $left, string $right) => ($state['counts'][$right] <=> $state['counts'][$left])
            ?: ($state['first_order'][$left] <=> $state['first_order'][$right]));
        $distribution = collect($keys)->map(fn (string $key) => array_merge(
            $this->enrichStatItem($state['metadata'][$key], $kind),
            ['key' => $key, 'count' => $state['counts'][$key], 'percent' => $total > 0 ? round($state['counts'][$key] / $total * 100, 1) : 0.0],
        ))->all();

        return [
            'total' => $total,
            'distribution' => $distribution,
            'monthly_trend' => [
                'months' => $months->map(fn (CarbonImmutable $month) => $month->toDateString())->all(),
                'series' => collect($distribution)->map(fn (array $item) => [
                    'key' => $item['key'], 'label' => $item['label'], 'icon_url' => $item['icon_url'],
                    'points' => $months->map(fn (CarbonImmutable $month) => $state['monthly'][$month->format('Y-m')][$item['key']] ?? 0)->all(),
                ])->all(),
            ],
        ];
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function monthKeys(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $months = collect();
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();

        while ($cursor->lte($last)) {
            $months->push($cursor);
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function applicationBucket(string $status): string
    {
        return match ($status) {
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH => 'approved',
            ActivityApplication::STATUS_DECLINED => 'declined',
            ActivityApplication::STATUS_CANCELLED => 'cancelled',
            ActivityApplication::STATUS_WITHDRAWN => 'withdrawn',
            default => 'pending',
        };
    }

    private function applicationDate(ActivityApplication $application): ?CarbonImmutable
    {
        return $this->toImmutable($application->submitted_at ?? $application->created_at);
    }

    private function assignmentActivityDate(ActivitySlotAssignment $assignment): ?CarbonImmutable
    {
        return $this->toImmutable($assignment->activity?->starts_at ?? $assignment->assigned_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function slotFieldValueSnapshot(ActivitySlot $slot): array
    {
        return $slot->fieldValues
            ->mapWithKeys(fn ($fieldValue) => [
                $fieldValue->field_key => $fieldValue->value,
            ])
            ->all();
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractSnapshotItems(?array $snapshot, string $kind): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $items = [];

        foreach ($snapshot as $fieldKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            $values = array_is_list($value) && isset($value[0]) && is_array($value[0])
                ? $value
                : [$value];

            foreach ($values as $entry) {
                if (! is_array($entry) || ! filled($entry['name'] ?? $entry['label'] ?? null)) {
                    continue;
                }

                if ($kind === 'class' && $this->isClassSnapshotEntry((string) $fieldKey, $entry)) {
                    $items[] = $entry;
                }

                if ($kind === 'phantom_job' && $this->isPhantomJobSnapshotEntry((string) $fieldKey, $entry)) {
                    $items[] = $entry;
                }
            }
        }

        return $items;
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
     * @param  array<string, mixed>  $item
     */
    private function statItemKey(array $item): string
    {
        if (filled($item['id'] ?? null)) {
            return 'id:'.(int) $item['id'];
        }

        return 'label:'.Str::slug((string) ($item['name'] ?? $item['label'] ?? 'unknown'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, short_label: string|null, role: string|null, icon_url: string|null}
     */
    private function enrichStatItem(array $item, string $kind): array
    {
        if ($kind === 'class' && filled($item['id'] ?? null)) {
            $class = CharacterClass::query()->find((int) $item['id']);

            if ($class) {
                return [
                    'label' => $class->name,
                    'short_label' => $class->shorthand,
                    'role' => $class->role,
                    'icon_url' => $class->icon_url,
                ];
            }
        }

        if ($kind === 'phantom_job' && filled($item['id'] ?? null)) {
            $phantomJob = PhantomJob::query()->find((int) $item['id']);

            if ($phantomJob) {
                return [
                    'label' => $phantomJob->name,
                    'short_label' => null,
                    'role' => null,
                    'icon_url' => $phantomJob->transparent_icon_url ?: $phantomJob->icon_url,
                ];
            }
        }

        return [
            'label' => (string) ($item['name'] ?? $item['label'] ?? 'Unknown'),
            'short_label' => filled($item['shorthand'] ?? null) ? (string) $item['shorthand'] : null,
            'role' => filled($item['role'] ?? null) ? (string) $item['role'] : null,
            'icon_url' => $item['icon_url'] ?? $item['transparent_icon_url'] ?? null,
        ];
    }
}
