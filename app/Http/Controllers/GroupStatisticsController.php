<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\Groups\GroupStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GroupStatisticsController extends Controller
{
    private const CACHE_TTL_SECONDS = 86_400;

    private const CACHE_VERSION = 3;

    private const REFRESH_COOLDOWN_SECONDS = 300;

    public function __construct(private readonly GroupStatisticsService $statistics) {}

    public function __invoke(Group $group): Response
    {
        $group->loadMissing(['memberships', 'features']);

        $currentUserId = auth()->id();

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        abort_unless($group->featureEnabled('statistics_enabled'), 404);

        $loaded = false;
        $cacheEntry = null;
        $loadStatistics = function () use ($group, &$loaded, &$cacheEntry): ?array {
            if (! $loaded) {
                $loaded = true;
                try {
                    $cacheEntry = $this->statisticsCacheEntry($group);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            return $cacheEntry;
        };

        return Inertia::render('Dashboard/Groups/Statistics', [
            'group' => $this->serializeNavigationGroup($group, $currentUserId),
            'statistics' => Inertia::defer(fn () => $loadStatistics()['payload'] ?? null, 'group-statistics'),
            'statistics_cache' => Inertia::defer(function () use ($group, $loadStatistics) {
                $entry = $loadStatistics();

                return $entry ? $this->serializeCacheMeta($group, $entry) : null;
            }, 'group-statistics'),
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
            'payload' => $this->statistics->calculate($group),
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
        $cachedAt = CarbonImmutable::parse($cacheEntry['cached_at']);
        $cooldownSeconds = $this->refreshCooldownSeconds($group);
        $refreshAvailableAt = Cache::get($this->refreshCooldownKey($group));

        return [
            'cached_at' => $cachedAt->toIso8601String(),
            'expires_at' => $cachedAt->addSeconds(self::CACHE_TTL_SECONDS)->toIso8601String(),
            'refresh_cooldown_seconds' => $cooldownSeconds,
            'refresh_available_at' => $cooldownSeconds > 0 && is_string($refreshAvailableAt)
                ? CarbonImmutable::parse($refreshAvailableAt)->toIso8601String()
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

        $availableAt = CarbonImmutable::parse($refreshAvailableAt);

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
