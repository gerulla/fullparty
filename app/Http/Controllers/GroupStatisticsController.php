<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Services\Groups\ActivitySlotBench;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GroupStatisticsController extends Controller
{
    private const CACHE_TTL_SECONDS = 86_400;

    private const CACHE_VERSION = 3;

    private const REFRESH_COOLDOWN_SECONDS = 300;

    private const APPLICATION_BUCKETS = [
        'pending',
        'approved',
        'declined',
        'cancelled',
        'withdrawn',
    ];

    public function __invoke(Group $group): Response
    {
        $group->loadMissing(['memberships', 'features']);

        $currentUserId = auth()->id();

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        abort_unless($group->featureEnabled('statistics_enabled'), 404);

        $cacheEntry = $this->statisticsCacheEntry($group);

        return Inertia::render('Dashboard/Groups/Statistics', [
            'group' => $this->serializeNavigationGroup($group, $currentUserId),
            'statistics' => $cacheEntry['payload'],
            'statistics_cache' => $this->serializeCacheMeta($group, $cacheEntry),
        ]);
    }

    public function refresh(Group $group): RedirectResponse
    {
        $group->loadMissing(['memberships', 'features']);

        $currentUserId = auth()->id();

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        abort_unless($group->featureEnabled('statistics_enabled'), 404);

        if ($this->refreshCooldownSeconds($group) > 0) {
            return redirect()
                ->route('groups.dashboard.statistics', $group)
                ->with('error', 'group_statistics_refresh_cooldown');
        }

        $cacheEntry = $this->freshStatisticsCacheEntry($group);

        Cache::put($this->statisticsCacheKey($group), $cacheEntry, $this->cacheExpiresAt());
        Cache::put(
            $this->refreshCooldownKey($group),
            CarbonImmutable::now()->addSeconds(self::REFRESH_COOLDOWN_SECONDS)->toIso8601String(),
            CarbonImmutable::now()->addSeconds(self::REFRESH_COOLDOWN_SECONDS),
        );

        return redirect()
            ->route('groups.dashboard.statistics', $group)
            ->with('success', 'group_statistics_refreshed');
    }

    /**
     * @return array{payload: array<string, mixed>, cached_at: string}
     */
    private function statisticsCacheEntry(Group $group): array
    {
        $cacheKey = $this->statisticsCacheKey($group);
        $cacheEntry = Cache::get($cacheKey);

        if ($this->isValidCacheEntry($cacheEntry)) {
            return $cacheEntry;
        }

        $cacheEntry = $this->freshStatisticsCacheEntry($group);

        Cache::put($cacheKey, $cacheEntry, $this->cacheExpiresAt());

        return $cacheEntry;
    }

    /**
     * @return array{payload: array<string, mixed>, cached_at: string}
     */
    private function freshStatisticsCacheEntry(Group $group): array
    {
        return [
            'payload' => $this->buildStatisticsPayload($group),
            'cached_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    private function isValidCacheEntry(mixed $cacheEntry): bool
    {
        return is_array($cacheEntry)
            && isset($cacheEntry['payload'], $cacheEntry['cached_at'])
            && is_array($cacheEntry['payload'])
            && is_string($cacheEntry['cached_at']);
    }

    /**
     * @param  array{payload: array<string, mixed>, cached_at: string}  $cacheEntry
     * @return array<string, mixed>
     */
    private function serializeCacheMeta(Group $group, array $cacheEntry): array
    {
        $cachedAt = $this->toImmutable($cacheEntry['cached_at']);
        $cooldownSeconds = $this->refreshCooldownSeconds($group);
        $refreshAvailableAt = Cache::get($this->refreshCooldownKey($group));

        return [
            'cached_at' => $cachedAt?->toIso8601String(),
            'expires_at' => $cachedAt?->addSeconds(self::CACHE_TTL_SECONDS)->toIso8601String(),
            'refresh_cooldown_seconds' => $cooldownSeconds,
            'refresh_available_at' => $cooldownSeconds > 0 && is_string($refreshAvailableAt)
                ? $this->toImmutable($refreshAvailableAt)?->toIso8601String()
                : null,
            'can_refresh' => $cooldownSeconds === 0,
        ];
    }

    private function refreshCooldownSeconds(Group $group): int
    {
        $refreshAvailableAt = Cache::get($this->refreshCooldownKey($group));

        if (! is_string($refreshAvailableAt)) {
            return 0;
        }

        $availableAt = $this->toImmutable($refreshAvailableAt);

        if (! $availableAt) {
            return 0;
        }

        return max(0, $availableAt->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    private function statisticsCacheKey(Group $group): string
    {
        return sprintf('groups:%d:statistics:v%d', $group->id, self::CACHE_VERSION);
    }

    private function refreshCooldownKey(Group $group): string
    {
        return sprintf('groups:%d:statistics-refresh-cooldown:v%d', $group->id, self::CACHE_VERSION);
    }

    private function cacheExpiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatisticsPayload(Group $group): array
    {
        $now = CarbonImmutable::now();
        $activeSince = $now->subMonth()->startOfDay();
        $trendStart = $now->subDays(29)->startOfDay();
        $applicationDaySeriesStart = $now->subDays(6)->startOfDay();
        $monthSeriesStart = $now->startOfMonth()->subMonths(11);
        $activities = Activity::query()
            ->where('group_id', $group->id)
            ->get([
                'id',
                'group_id',
                'status',
                'starts_at',
                'created_at',
                'updated_at',
            ]);
        $participantRecords = $this->deduplicatedParticipantRecords($group);
        $participantsByRun = $participantRecords
            ->groupBy('activity_id')
            ->map(fn (Collection $assignments) => $assignments->count());

        $totalParticipants = $participantRecords->count();
        $totalRuns = $activities->count();
        $runsWithParticipants = $participantsByRun->count();
        $uniqueParticipants = $participantRecords
            ->pluck('character_id')
            ->unique()
            ->count();
        $activePlayers = $participantRecords
            ->filter(fn (array $record) => $record['activity_date']?->betweenIncluded($activeSince, $now) ?? false)
            ->pluck('character_id')
            ->unique()
            ->count();

        return [
            'generated_at' => $now->toIso8601String(),
            'summary' => [
                'total_runs' => $totalRuns,
                'runs_with_participants' => $runsWithParticipants,
                'total_participants' => $totalParticipants,
                'unique_participants' => $uniqueParticipants,
                'average_participants_per_raid' => $totalRuns > 0 ? round($totalParticipants / $totalRuns, 1) : 0.0,
                'active_players_past_month' => $activePlayers,
            ],
            'participation_trend' => $this->buildParticipationTrend($activities, $participantsByRun, $trendStart, $now),
            'applications' => $this->buildApplicationStatistics(
                $group,
                $applicationDaySeriesStart,
                $monthSeriesStart,
                $now,
            ),
            'classes' => $this->buildLoadoutStatistics($participantRecords, 'class', $monthSeriesStart, $now),
            'phantom_jobs' => $this->buildLoadoutStatistics($participantRecords, 'phantom_job', $monthSeriesStart, $now),
        ];
    }

    /**
     * @return Collection<int, array{
     *     activity_id: int,
     *     character_id: int,
     *     field_values_snapshot: array<string, mixed>,
     *     activity_date: CarbonImmutable|null,
     *     assigned_at: CarbonImmutable|null,
     *     source_priority: int
     * }>
     */
    private function deduplicatedParticipantRecords(Group $group): Collection
    {
        $assignmentRecords = ActivitySlotAssignment::query()
            ->where('activity_slot_assignments.group_id', $group->id)
            ->whereHas('slot', fn ($query) => $query->where('group_key', '!=', ActivitySlotBench::GROUP_KEY))
            ->with([
                'activity:id,group_id,starts_at,status',
            ])
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
            ]);

        $currentSlotRecords = ActivitySlot::query()
            ->whereHas('activity', fn ($query) => $query->where('group_id', $group->id))
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->whereNotNull('assigned_character_id')
            ->with([
                'activity:id,group_id,starts_at,status',
                'fieldValues',
            ])
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
            ]);

        return $assignmentRecords
            ->merge($currentSlotRecords)
            ->groupBy(fn (array $record) => "{$record['activity_id']}:{$record['character_id']}")
            ->map(fn (Collection $records) => $records
                ->sort(function (array $left, array $right) {
                    $priorityComparison = $right['source_priority'] <=> $left['source_priority'];

                    if ($priorityComparison !== 0) {
                        return $priorityComparison;
                    }

                    return ($right['assigned_at']?->getTimestamp() ?? 0)
                        <=> ($left['assigned_at']?->getTimestamp() ?? 0);
                })
                ->first())
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int|string, int>  $participantsByRun
     * @return array<int, array{date: string, run_count: int, participant_count: int}>
     */
    private function buildParticipationTrend(Collection $activities, Collection $participantsByRun, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $activities
            ->filter(fn (Activity $activity) => $activity->starts_at !== null)
            ->filter(function (Activity $activity) use ($start, $end) {
                $startsAt = $this->toImmutable($activity->starts_at);

                return $startsAt?->betweenIncluded($start, $end) ?? false;
            })
            ->groupBy(fn (Activity $activity) => $this->toImmutable($activity->starts_at)?->toDateString() ?? '')
            ->filter(fn (Collection $runs, string $date) => $date !== '')
            ->sortKeys()
            ->map(fn (Collection $runs, string $date) => [
                'date' => $date,
                'run_count' => $runs->count(),
                'participant_count' => $runs->sum(fn (Activity $activity) => (int) ($participantsByRun->get($activity->id) ?? 0)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{total: int, distribution: array<int, array{key: string, count: int, percent: float}>, daily_submissions: array<int, array{date: string, count: int}>, volume_by_month: array<int, array{month: string, total: int, statuses: array<string, int>}>}
     */
    private function buildApplicationStatistics(
        Group $group,
        CarbonImmutable $daySeriesStart,
        CarbonImmutable $monthSeriesStart,
        CarbonImmutable $now,
    ): array {
        $applications = ActivityApplication::query()
            ->whereHas('activity', fn ($query) => $query->where('group_id', $group->id))
            ->get([
                'id',
                'activity_id',
                'status',
                'submitted_at',
                'created_at',
            ]);
        $total = $applications->count();
        $distributionCounts = $applications
            ->map(fn (ActivityApplication $application) => $this->applicationBucket($application->status))
            ->countBy();
        $months = $this->monthKeys($monthSeriesStart, $now);
        $applicationsByDay = $applications
            ->filter(function (ActivityApplication $application) use ($daySeriesStart, $now) {
                $date = $this->applicationDate($application);

                return $date?->betweenIncluded($daySeriesStart, $now) ?? false;
            })
            ->countBy(fn (ActivityApplication $application) => $this->applicationDate($application)?->toDateString() ?? '');
        $applicationsByMonth = $applications
            ->filter(function (ActivityApplication $application) use ($monthSeriesStart, $now) {
                $date = $this->applicationDate($application);

                return $date?->betweenIncluded($monthSeriesStart, $now) ?? false;
            })
            ->groupBy(fn (ActivityApplication $application) => $this->applicationDate($application)?->format('Y-m') ?? '');

        return [
            'total' => $total,
            'distribution' => collect(self::APPLICATION_BUCKETS)
                ->map(fn (string $bucket) => [
                    'key' => $bucket,
                    'count' => (int) ($distributionCounts->get($bucket) ?? 0),
                    'percent' => $total > 0 ? round(((int) ($distributionCounts->get($bucket) ?? 0) / $total) * 100, 1) : 0.0,
                ])
                ->all(),
            'daily_submissions' => collect(range(0, 6))
                ->map(function (int $offset) use ($applicationsByDay, $daySeriesStart) {
                    $date = $daySeriesStart->addDays($offset)->toDateString();

                    return [
                        'date' => $date,
                        'count' => (int) ($applicationsByDay->get($date) ?? 0),
                    ];
                })
                ->all(),
            'volume_by_month' => $months
                ->map(function (CarbonImmutable $month) use ($applicationsByMonth) {
                    $monthKey = $month->format('Y-m');
                    /** @var Collection<int, ActivityApplication> $monthApplications */
                    $monthApplications = $applicationsByMonth->get($monthKey, collect());
                    $counts = $monthApplications
                        ->map(fn (ActivityApplication $application) => $this->applicationBucket($application->status))
                        ->countBy();

                    return [
                        'month' => $month->toDateString(),
                        'total' => $monthApplications->count(),
                        'statuses' => collect(self::APPLICATION_BUCKETS)
                            ->mapWithKeys(fn (string $bucket) => [$bucket => (int) ($counts->get($bucket) ?? 0)])
                            ->all(),
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $participantRecords
     * @return array{total: int, distribution: array<int, array<string, mixed>>, monthly_trend: array{months: array<int, string>, series: array<int, array{key: string, label: string, icon_url: string|null, points: array<int, int>}>}}
     */
    private function buildLoadoutStatistics(Collection $participantRecords, string $kind, CarbonImmutable $monthSeriesStart, CarbonImmutable $now): array
    {
        $months = $this->monthKeys($monthSeriesStart, $now);
        $counts = collect();
        $metadata = collect();
        $monthlyCounts = $months
            ->mapWithKeys(fn (CarbonImmutable $month) => [$month->format('Y-m') => collect()]);

        foreach ($participantRecords as $record) {
            $monthKey = $record['activity_date']?->format('Y-m');
            $items = $this->extractSnapshotItems($record['field_values_snapshot'] ?? [], $kind);

            foreach ($items as $item) {
                $key = $this->statItemKey($item);
                $counts[$key] = (int) ($counts->get($key) ?? 0) + 1;
                $metadata[$key] = array_merge($metadata->get($key, []), $item);

                if ($monthKey && $monthlyCounts->has($monthKey)) {
                    $monthCounts = $monthlyCounts->get($monthKey);
                    $monthCounts[$key] = (int) ($monthCounts->get($key) ?? 0) + 1;
                }
            }
        }

        $total = (int) $counts->sum();
        $distribution = $counts
            ->sortDesc()
            ->map(fn (int $count, string $key) => array_merge(
                $this->enrichStatItem($metadata->get($key, []), $kind),
                [
                    'key' => $key,
                    'count' => $count,
                    'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                ],
            ))
            ->values()
            ->all();
        $trendSeries = collect($distribution)
            ->map(fn (array $item) => [
                'key' => (string) $item['key'],
                'label' => (string) $item['label'],
                'icon_url' => $item['icon_url'] ?? null,
                'points' => $months
                    ->map(fn (CarbonImmutable $month) => (int) ($monthlyCounts->get($month->format('Y-m'))?->get($item['key']) ?? 0))
                    ->all(),
            ])
            ->all();

        return [
            'total' => $total,
            'distribution' => $distribution,
            'monthly_trend' => [
                'months' => $months->map(fn (CarbonImmutable $month) => $month->toDateString())->all(),
                'series' => $trendSeries,
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

    /**
     * @return array<string, mixed>
     */
    private function serializeNavigationGroup(Group $group, ?int $currentUserId): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_activities' => $group->hasModeratorAccess($currentUserId),
                'can_view_members' => true,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
            ],
        ];
    }
}
