<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ApplicantMilestoneResolver
{
    public function __construct(
        private readonly CharacterZoneProgressFetcher $zoneProgressFetcher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serialize(?Character $character, ?ActivityTypeVersion $activityTypeVersion): array
    {
        if (!$character || !$activityTypeVersion) {
            return [];
        }

        return collect($activityTypeVersion->progress_schema['milestones'] ?? [])
            ->map(function (array $milestone) use ($character, $activityTypeVersion) {
                $key = (string) ($milestone['key'] ?? '');

                if ($key === '') {
                    return null;
                }

                $state = $this->resolveMilestoneState($character, $activityTypeVersion, $milestone);

                return [
                    'key' => $key,
                    'label' => is_array($milestone['label'] ?? null)
                        ? $milestone['label']
                        : ['en' => $key],
                    ...$state,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $milestone
     * @return array<string, mixed>
     */
    private function resolveMilestoneState(Character $character, ActivityTypeVersion $activityTypeVersion, array $milestone): array
    {
        $matcher = is_array($milestone['fflogs_matcher'] ?? null)
            ? $milestone['fflogs_matcher']
            : [];
        $encounterId = (int) ($matcher['encounter_id'] ?? 0);
        $matcherType = (string) ($matcher['type'] ?? 'encounter');

        $websiteProgress = $this->resolveWebsiteMilestoneProgress($character, $encounterId);

        if ($websiteProgress !== null) {
            return [
                'reached' => $websiteProgress['kills'] > 0 || $websiteProgress['progress_percent'] > 0,
                'source' => 'website',
                'kills' => $websiteProgress['kills'],
                'progress_percent' => $websiteProgress['progress_percent'],
            ];
        }

        $fflogsProgress = $this->resolveFflogsMilestoneProgress(
            $character,
            (int) ($activityTypeVersion->fflogs_zone_id ?? 0),
            $encounterId,
        );

        return [
            'reached' => $fflogsProgress['kills'] > 0 || $fflogsProgress['progress_percent'] > 0,
            'source' => $matcherType === 'phase' ? 'fflogs_phase_estimate' : 'fflogs',
            'kills' => $fflogsProgress['kills'],
            'progress_percent' => $fflogsProgress['progress_percent'],
        ];
    }

    /**
     * @return array{kills:int, progress_percent:int}|null
     */
    private function resolveWebsiteMilestoneProgress(Character $character, int $encounterId): ?array
    {
        $bossKey = match ($encounterId) {
            2062 => 'demon_tablet',
            2063 => 'dead_stars',
            2065 => 'marble_dragon',
            2066 => 'magitaur',
            default => null,
        };

        if ($bossKey === null) {
            return null;
        }

        $bosses = collect($character->occultProgress?->forkedTowerBloodProgress()['bosses'] ?? [])
            ->keyBy('key');
        $boss = $bosses->get($bossKey);

        if (!is_array($boss)) {
            return [
                'kills' => 0,
                'progress_percent' => 0,
            ];
        }

        return [
            'kills' => (int) ($boss['kills'] ?? 0),
            'progress_percent' => (int) ($boss['progress'] ?? 0),
        ];
    }

    /**
     * @return array{kills:int, progress_percent:int}
     */
    private function resolveFflogsMilestoneProgress(Character $character, int $zoneId, int $encounterId): array
    {
        if ($zoneId <= 0 || $encounterId <= 0) {
            return [
                'kills' => 0,
                'progress_percent' => 0,
            ];
        }

        $rankings = $this->fetchEncounterRankings($character, $zoneId);
        $match = $rankings->first(function (array $ranking) use ($encounterId) {
            return (int) data_get($ranking, 'encounter.id', 0) === $encounterId;
        });

        if (!is_array($match)) {
            return [
                'kills' => 0,
                'progress_percent' => 0,
            ];
        }

        return [
            'kills' => $this->resolveEncounterKills($match),
            'progress_percent' => $this->resolveEncounterProgress($match),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchEncounterRankings(Character $character, int $zoneId): Collection
    {
        static $cache = [];

        $cacheKey = sprintf('%d:%d', $zoneId, $character->id);

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $rawZoneRankings = $this->zoneProgressFetcher
                ->fetchRawZoneRankingsForCharacter($character, $zoneId);
        } catch (\Throwable $exception) {
            Log::warning('Unable to load applicant FF Logs milestone data.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'zone_id' => $zoneId,
                'exception' => $exception->getMessage(),
            ]);

            return $cache[$cacheKey] = collect();
        }

        return $cache[$cacheKey] = $this->extractEncounterRankings($rawZoneRankings);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function extractEncounterRankings(array $zoneRankings): Collection
    {
        foreach (['rankings', 'encounterRankings'] as $path) {
            $value = data_get($zoneRankings, $path);

            if (is_array($value) && array_is_list($value)) {
                return collect(array_values(array_filter($value, 'is_array')));
            }
        }

        if (array_is_list($zoneRankings)) {
            return collect(array_values(array_filter($zoneRankings, 'is_array')));
        }

        return collect();
    }

    private function resolveEncounterKills(array $ranking): int
    {
        foreach (['totalKills', 'kills'] as $key) {
            $value = $ranking[$key] ?? null;

            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return 0;
    }

    private function resolveEncounterProgress(array $ranking): int
    {
        if ($this->resolveEncounterKills($ranking) > 0) {
            return 100;
        }

        $percentage = $ranking['progress']
            ?? $ranking['bestProgress']
            ?? null;

        if ($percentage === null) {
            return 0;
        }

        return (int) max(0, min(100, round((float) $percentage)));
    }
}
