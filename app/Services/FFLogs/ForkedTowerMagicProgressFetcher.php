<?php

namespace App\Services\FFLogs;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ForkedTowerMagicProgressFetcher
{
    private const ENCOUNTERS = [
        2075 => [
            'key' => 'two_headed_aevis',
            'name' => 'Two-headed Aevis',
            'aliases' => ['two headed aevis'],
        ],
        2076 => [
            'key' => 'sword_dancer',
            'name' => 'Sword Dancer',
            'aliases' => ['sword dancer'],
        ],
        2077 => [
            'key' => 'necrophobia',
            'name' => 'Necrophobia',
            'aliases' => ['necrophobia'],
        ],
        2078 => [
            'key' => 'index',
            'name' => 'Index',
            'aliases' => ['index'],
        ],
    ];

    public function __construct(
        private readonly CharacterZoneProgressFetcher $zoneProgressFetcher,
    ) {}

    public function fetchForCharacter(Character $character, bool $ignoreCache = false): array
    {
        return $this->buildProgressPayload(
            $this->zoneProgressFetcher->fetchRawZoneRankingsForCharacter(
                $character,
                $this->forkedTowerMagicZoneId(),
                $ignoreCache,
            )
        );
    }

    private function buildProgressPayload(array $zoneRankings): array
    {
        $bosses = collect(self::ENCOUNTERS)
            ->mapWithKeys(fn (array $encounter) => [
                $encounter['key'] => [
                    'key' => $encounter['key'],
                    'name' => $encounter['name'],
                    'kills' => 0,
                    'progress' => 0,
                ],
            ])
            ->all();

        foreach ($this->extractEncounterRankings($zoneRankings) as $ranking) {
            $key = $this->resolveEncounterKey($ranking);

            if (! $key || ! isset($bosses[$key])) {
                continue;
            }

            $bosses[$key]['kills'] = max($bosses[$key]['kills'], $this->resolveEncounterKills($ranking));
            $bosses[$key]['progress'] = max($bosses[$key]['progress'], $this->resolveEncounterProgress($ranking));
        }

        $orderedBosses = array_values($bosses);

        return [
            'clears' => $bosses['index']['kills'],
            'bosses' => $orderedBosses,
        ];
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

    private function resolveEncounterKey(array $ranking): ?string
    {
        $encounterId = data_get($ranking, 'encounter.id')
            ?? $ranking['encounterID']
            ?? $ranking['encounterId']
            ?? null;

        if (is_numeric($encounterId) && isset(self::ENCOUNTERS[(int) $encounterId])) {
            return self::ENCOUNTERS[(int) $encounterId]['key'];
        }

        $fightName = data_get($ranking, 'encounter.name')
            ?? $ranking['name']
            ?? $ranking['encounterName']
            ?? $ranking['boss']
            ?? null;

        if (! is_string($fightName) || trim($fightName) === '') {
            return null;
        }

        $normalizedFightName = $this->normalizeName($fightName);

        foreach (self::ENCOUNTERS as $encounter) {
            foreach ($encounter['aliases'] as $alias) {
                if (str_contains($normalizedFightName, $this->normalizeName($alias))) {
                    return $encounter['key'];
                }
            }
        }

        return null;
    }

    private function resolveEncounterProgress(array $ranking): int
    {
        if ($this->resolveEncounterKills($ranking) > 0) {
            return 100;
        }

        $percentage = $ranking['progress'] ?? $ranking['bestProgress'] ?? null;

        return $percentage === null
            ? 0
            : (int) max(0, min(100, round((float) $percentage)));
    }

    private function resolveEncounterKills(array $ranking): int
    {
        foreach (['totalKills', 'kills'] as $key) {
            if (is_numeric($ranking[$key] ?? null)) {
                return max(0, (int) $ranking[$key]);
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

    private function forkedTowerMagicZoneId(): int
    {
        $zoneId = (int) config('services.ff_logs.forked_tower_magic_zone_id');

        if ($zoneId <= 0) {
            throw new RuntimeException('FF Logs Forked Tower: Magic (Extreme) zone ID is not configured.');
        }

        return $zoneId;
    }
}
