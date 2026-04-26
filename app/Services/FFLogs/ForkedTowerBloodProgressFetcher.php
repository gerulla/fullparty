<?php

namespace App\Services\FFLogs;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ForkedTowerBloodProgressFetcher
{
    private const BOSS_ORDER = [
        'Demon Tablet' => 'demon_tablet',
        'Dead Stars' => 'dead_stars',
        'Marble Dragon' => 'marble_dragon',
        'Magitaur' => 'magitaur',
    ];

    private const BOSS_ALIASES = [
        'demon_tablet' => ['demon tablet'],
        'dead_stars' => ['dead stars'],
        'marble_dragon' => ['marble dragon'],
        'magitaur' => ['magitaur'],
    ];

    public function __construct(
        private readonly CharacterZoneProgressFetcher $zoneProgressFetcher,
    ) {}

    public function fetchForCharacter(Character $character): array
    {
        return $this->buildProgressPayload(
            $this->zoneProgressFetcher->fetchRawZoneRankingsForCharacter($character, $this->forkedTowerZoneId())
        );
    }

    public function fetchDebugPayloadForCharacter(Character $character): array
    {
        $zoneRankings = $this->zoneProgressFetcher->fetchRawZoneRankingsForCharacter($character, $this->forkedTowerZoneId());

        return [
            'progress' => $this->buildProgressPayload($zoneRankings),
            'zone_rankings' => $zoneRankings,
        ];
    }

    private function buildProgressPayload(array $zoneRankings): array
    {
        $emptyBosses = collect(self::BOSS_ORDER)
            ->map(fn (string $key, string $name) => [
                'key' => $key,
                'name' => $name,
                'kills' => 0,
                'progress' => 0,
            ])
            ->keyBy('name')
            ->all();

        $bosses = $emptyBosses;

        foreach ($this->extractEncounterRankings($zoneRankings) as $ranking) {
            $bossName = $this->resolveBossName($this->extractRankingBossName($ranking));

            if (!$bossName || !isset($bosses[$bossName])) {
                continue;
            }

            $bosses[$bossName]['kills'] = max(
                $bosses[$bossName]['kills'],
                $this->resolveEncounterKills($ranking)
            );

            $bosses[$bossName]['progress'] = max(
                $bosses[$bossName]['progress'],
                $this->resolveEncounterProgress($ranking)
            );
        }

        $orderedBosses = array_values($bosses);
        $clearCount = collect($orderedBosses)
            ->firstWhere('key', 'magitaur')['kills'] ?? 0;

        return [
            'clears' => $clearCount,
            'bosses' => $orderedBosses,
        ];
    }

    private function extractRankingBossName(array $ranking): ?string
    {
        $candidates = [
            data_get($ranking, 'encounter.name'),
            $ranking['name'] ?? null,
            $ranking['encounterName'] ?? null,
            $ranking['boss'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function extractEncounterRankings(array $zoneRankings): Collection
    {
        $candidatePaths = [
            'rankings',
            'encounterRankings',
        ];

        foreach ($candidatePaths as $path) {
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

    private function resolveBossName(?string $fightName): ?string
    {
        if (!$fightName) {
            return null;
        }

        $normalizedFightName = $this->normalizeName($fightName);

        foreach (self::BOSS_ORDER as $bossName => $key) {
            foreach (self::BOSS_ALIASES[$key] as $alias) {
                if (str_contains($normalizedFightName, $this->normalizeName($alias))) {
                    return $bossName;
                }
            }
        }

        return null;
    }

    private function resolveEncounterProgress(array $ranking): int
    {
        if (($this->resolveEncounterKills($ranking)) > 0) {
            return 100;
        }

        // Zone rankings primarily expose completed clears. If FF Logs includes a
        // percentage-like field in the future, we can still surface it.
        $percentage = $ranking['progress']
            ?? $ranking['bestProgress']
            ?? null;

        if ($percentage === null) {
            return 0;
        }

        $progress = (float) $percentage;

        return (int) max(0, min(100, round($progress)));
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

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function forkedTowerZoneId(): int
    {
        $zoneId = (int) config('services.ff_logs.forked_tower_blood_zone_id');

        if ($zoneId <= 0) {
            throw new RuntimeException('FF Logs Forked Tower of Blood zone ID is not configured.');
        }

        return $zoneId;
    }
}
