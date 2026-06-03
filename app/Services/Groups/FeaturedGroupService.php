<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\FeaturedGroup;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FeaturedGroupService
{
    private const CACHE_KEY = 'groups:featured:v2';

    private const FEATURED_LIMIT = 8;

    private const FALLBACK_CANDIDATE_LIMIT = 64;

    /**
     * @return Collection<int, Group>
     */
    public function groups(): Collection
    {
        $ids = $this->featuredGroupIds();

        if ($ids === []) {
            return collect();
        }

        return Group::query()
            ->whereIn('id', $ids)
            ->withCount('memberships')
            ->get()
            ->sortBy(fn (Group $group): int => array_search($group->id, $ids, true))
            ->values();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isFeatureable(Group $group): bool
    {
        if (! $group->is_visible) {
            return false;
        }

        if (blank($group->description) || blank($group->banner_image_url)) {
            return false;
        }

        return $group->updated_at === null
            || $group->updated_at->greaterThanOrEqualTo(now()->subMonths(6))
            || $group->activities()
                ->where(function (Builder $query): void {
                    $query
                        ->where('updated_at', '>=', now()->subMonths(6))
                        ->orWhere('starts_at', '>=', now()->subMonths(6))
                        ->orWhere('completed_at', '>=', now()->subMonths(6));
                })
                ->exists();
    }

    /**
     * @return Builder<Group>
     */
    public function eligibleGroupsQuery(): Builder
    {
        return Group::query()
            ->visible()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereNotNull('banner_image_url')
            ->where('banner_image_url', '!=', '')
            ->where(function (Builder $query): void {
                $query
                    ->where('groups.updated_at', '>=', now()->subMonths(6))
                    ->orWhereHas('activities', fn (Builder $activityQuery) => $activityQuery
                        ->where(function (Builder $recentActivityQuery): void {
                            $recentActivityQuery
                                ->where('updated_at', '>=', now()->subMonths(6))
                                ->orWhere('starts_at', '>=', now()->subMonths(6))
                                ->orWhere('completed_at', '>=', now()->subMonths(6));
                        }));
            });
    }

    /**
     * @return array<int, int>
     */
    private function featuredGroupIds(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            $curatedGroups = $this->curatedGroups();
            $fallbackGroups = $this->fallbackGroups($curatedGroups->pluck('id')->all());

            return $curatedGroups
                ->concat($this->applyFallbackDiversity($fallbackGroups, self::FEATURED_LIMIT - $curatedGroups->count()))
                ->take(self::FEATURED_LIMIT)
                ->pluck('id')
                ->values()
                ->all();
        });
    }

    /**
     * @return Collection<int, Group>
     */
    private function curatedGroups(): Collection
    {
        $groupIds = FeaturedGroup::query()
            ->active()
            ->whereHas('group', fn (Builder $query) => $this->applyEligibility($query))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit(self::FEATURED_LIMIT)
            ->pluck('group_id')
            ->all();

        if ($groupIds === []) {
            return collect();
        }

        return Group::query()
            ->whereIn('id', $groupIds)
            ->withCount([
                'memberships',
                'activities as upcoming_public_activity_count' => fn (Builder $query) => $this->upcomingPublicActivityFilter($query),
                'activities as recent_public_activity_count' => fn (Builder $query) => $this->recentPublicActivityFilter($query),
            ])
            ->get()
            ->sortBy(fn (Group $group): int => array_search($group->id, $groupIds, true))
            ->values();
    }

    /**
     * @param  array<int, int>  $excludedGroupIds
     * @return Collection<int, Group>
     */
    private function fallbackGroups(array $excludedGroupIds): Collection
    {
        return $this->eligibleGroupsQuery()
            ->when($excludedGroupIds !== [], fn (Builder $query) => $query->whereNotIn('groups.id', $excludedGroupIds))
            ->withCount([
                'memberships',
                'activities as upcoming_public_activity_count' => fn (Builder $query) => $this->upcomingPublicActivityFilter($query),
                'activities as recent_public_activity_count' => fn (Builder $query) => $this->recentPublicActivityFilter($query),
            ])
            ->withMax([
                'activities as latest_public_activity_at',
            ], 'updated_at')
            ->orderByDesc('groups.updated_at')
            ->limit(self::FALLBACK_CANDIDATE_LIMIT)
            ->get()
            ->sortByDesc(fn (Group $group): int => $this->fallbackScore($group))
            ->values();
    }

    private function applyEligibility(Builder $query): void
    {
        $eligibleQuery = $this->eligibleGroupsQuery();

        $query->whereIn('groups.id', $eligibleQuery->select('groups.id'));
    }

    private function upcomingPublicActivityFilter(Builder $query): void
    {
        $query
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', [
                Activity::STATUS_DRAFT,
                Activity::STATUS_CANCELLED,
                Activity::STATUS_COMPLETE,
            ]);
    }

    private function recentPublicActivityFilter(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->where('starts_at', '>=', now()->subDays(60))
                    ->orWhere('completed_at', '>=', now()->subDays(60))
                    ->orWhere('updated_at', '>=', now()->subDays(60));
            });
    }

    private function fallbackScore(Group $group): int
    {
        $latestActivityAt = $group->latest_public_activity_at
            ? strtotime((string) $group->latest_public_activity_at)
            : null;
        $recencyBonus = $latestActivityAt !== null && $latestActivityAt >= now()->subDays(30)->getTimestamp()
            ? 20
            : 0;

        return ((int) ($group->upcoming_public_activity_count ?? 0) * 100)
            + ((int) ($group->recent_public_activity_count ?? 0) * 25)
            + min((int) ($group->memberships_count ?? 0), 50)
            + $recencyBonus;
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, Group>
     */
    private function applyFallbackDiversity(Collection $groups, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        $selected = collect();
        $datacenterCounts = [];
        $languageCounts = [];
        $focusCounts = [];

        foreach ($groups as $group) {
            if ($selected->count() >= $limit) {
                break;
            }

            $datacenter = (string) ($group->datacenter ?? '');
            $languages = array_values(array_filter($group->preferred_languages ?? []));
            $focuses = array_values(array_filter($group->primary_focuses ?? []));

            $wouldOverRepeat =
                ($datacenter !== '' && ($datacenterCounts[$datacenter] ?? 0) >= 3)
                || collect($languages)->contains(fn (string $language): bool => ($languageCounts[$language] ?? 0) >= 3)
                || collect($focuses)->contains(fn (string $focus): bool => ($focusCounts[$focus] ?? 0) >= 3);

            if ($wouldOverRepeat) {
                continue;
            }

            $selected->push($group);

            if ($datacenter !== '') {
                $datacenterCounts[$datacenter] = ($datacenterCounts[$datacenter] ?? 0) + 1;
            }

            foreach ($languages as $language) {
                $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
            }

            foreach ($focuses as $focus) {
                $focusCounts[$focus] = ($focusCounts[$focus] ?? 0) + 1;
            }
        }

        if ($selected->count() >= $limit) {
            return $selected;
        }

        return $selected
            ->concat($groups->reject(fn (Group $group): bool => $selected->contains('id', $group->id)))
            ->take($limit)
            ->values();
    }
}
