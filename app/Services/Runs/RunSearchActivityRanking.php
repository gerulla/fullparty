<?php

namespace App\Services\Runs;

use App\Models\Activity;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class RunSearchActivityRanking
{
    private const CACHE_KEY = 'runs:search:activity-popularity:v1';

    public function refreshPopularity(): void
    {
        $until = now()->toImmutable();
        $counts = Activity::query()
            ->where('is_public', true)
            ->where('status', Activity::STATUS_COMPLETE)
            ->whereBetween('starts_at', [$until->subDays(30), $until])
            ->selectRaw('activity_type_id, COUNT(*) as run_count')
            ->groupBy('activity_type_id')
            ->pluck('run_count', 'activity_type_id')
            ->all();

        // Keep the last successful snapshot available until the next daily refresh.
        Cache::forever(self::CACHE_KEY, $counts);
    }

    public function orderOptions(Collection $options): Collection
    {
        $current = $this->currentContent();
        $counts = Cache::get(self::CACHE_KEY, []);

        return $options->sortBy(fn (array $option): array => [
            isset($current[$option['value']]) ? 0 : 1,
            -($counts[$option['id']] ?? 0),
            mb_strtolower($option['label']),
            $option['value'],
        ])->values();
    }

    public function orderTrendingActivities(Collection $activities): Collection
    {
        $current = $this->currentContent();
        $counts = Cache::get(self::CACHE_KEY, []);

        return $activities->sortBy(fn (array $activity): array => [
            -($counts[$activity['id']] ?? 0),
            -$activity['run_count'],
            isset($current[$activity['value']]) ? 0 : 1,
            $activity['value'],
        ])->values();
    }

    private function currentContent(): array
    {
        return array_flip(Arr::flatten(config('run_discovery.current_content')));
    }
}
