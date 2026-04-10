<?php

namespace App\Services\FFLogs;

use App\Models\Character;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ForkedTowerBloodProgressFetcher
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

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

    private const DATACENTER_REGION_MAP = [
        'aether' => 'NA',
        'crystal' => 'NA',
        'dynamis' => 'NA',
        'primal' => 'NA',
        'chaos' => 'EU',
        'light' => 'EU',
        'elemental' => 'JP',
        'gaia' => 'JP',
        'mana' => 'JP',
        'meteor' => 'JP',
        'materia' => 'OC',
    ];

    public function fetchForCharacter(Character $character): array
    {
        $response = $this->queryCharacterZoneRankings(
            name: $character->name,
            serverSlug: $this->resolveServerSlug($character->world),
            serverRegion: $this->resolveServerRegion($character->datacenter),
        );

        $zoneRankings = $this->extractZoneRankings($character, $response);

        return $this->buildProgressPayload($zoneRankings);
    }

    public function fetchDebugPayloadForCharacter(Character $character): array
    {
        $response = $this->queryCharacterZoneRankings(
            name: $character->name,
            serverSlug: $this->resolveServerSlug($character->world),
            serverRegion: $this->resolveServerRegion($character->datacenter),
        );

        $zoneRankings = $this->extractZoneRankings($character, $response);

        return [
            'progress' => $this->buildProgressPayload($zoneRankings),
            'zone_rankings' => $zoneRankings,
        ];
    }

    private function queryCharacterZoneRankings(string $name, string $serverSlug, string $serverRegion): array
    {
        $zoneId = (int) config('services.ff_logs.forked_tower_blood_zone_id');

        if ($zoneId <= 0) {
            throw new RuntimeException('FF Logs Forked Tower of Blood zone ID is not configured.');
        }

        $query = <<<'GRAPHQL'
query CharacterForkedTowerZoneRankings(
  $name: String!,
  $serverSlug: String!,
  $serverRegion: String!,
  $zoneId: Int!
) {
  characterData {
    character(name: $name, serverSlug: $serverSlug, serverRegion: $serverRegion) {
      zoneRankings(zoneID: $zoneId)
    }
  }
}
GRAPHQL;

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->post(config('services.ff_logs.graphql_url'), [
                'query' => $query,
                'variables' => [
                    'name' => $name,
                    'serverSlug' => $serverSlug,
                    'serverRegion' => $serverRegion,
                    'zoneId' => $zoneId,
                ],
            ])
            ->throw()
            ->json();

        if (!empty($response['errors'])) {
            throw new RuntimeException('FF Logs GraphQL query failed: ' . json_encode($response['errors']));
        }

        return $response;
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

    private function extractZoneRankings(Character $character, array $response): array
    {
        if (data_get($response, 'data.characterData.character') === null) {
            throw new RuntimeException(sprintf(
                'FF Logs could not resolve character [%s] on server [%s] in region [%s].',
                $character->name,
                $character->world,
                $this->resolveServerRegion($character->datacenter),
            ));
        }

        $zoneRankings = data_get($response, 'data.characterData.character.zoneRankings');

        if (is_string($zoneRankings)) {
            $decoded = json_decode($zoneRankings, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return is_array($zoneRankings) ? $zoneRankings : [];
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

    private function extractEncounterRankings(array $zoneRankings): array
    {
        $candidatePaths = [
            'rankings',
            'encounterRankings',
        ];

        foreach ($candidatePaths as $path) {
            $value = data_get($zoneRankings, $path);

            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        if (array_is_list($zoneRankings)) {
            return array_values(array_filter($zoneRankings, 'is_array'));
        }

        return [];
    }

    private function getAccessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cachedToken) {
            return $cachedToken;
        }

        $clientId = config('services.ff_logs.client_id');
        $clientSecret = config('services.ff_logs.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('FF Logs credentials are not configured.');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post(config('services.ff_logs.token_url'), [
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        $token = $response['access_token'] ?? null;

        if (!$token) {
            throw new RuntimeException('FF Logs access token was not returned.');
        }

        $expiresIn = max(0, ((int) ($response['expires_in'] ?? 3600)) - self::TOKEN_CACHE_TTL_BUFFER);

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function resolveServerSlug(string $world): string
    {
        return Str::of($world)
            ->trim()
            ->replace("'", '')
            ->value();
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function resolveServerRegion(?string $datacenter): string
    {
        $normalizedDatacenter = Str::of((string) $datacenter)->lower()->trim()->value();

        if (isset(self::DATACENTER_REGION_MAP[$normalizedDatacenter])) {
            return self::DATACENTER_REGION_MAP[$normalizedDatacenter];
        }

        throw new RuntimeException("Unable to resolve FF Logs server region for datacenter [{$datacenter}].");
    }
}
