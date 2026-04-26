<?php

namespace App\Services\FFLogs;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CharacterZoneProgressFetcher
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    private const ZONE_PROGRESS_CACHE_TTL_HOURS = 24;

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

    public function fetchEncounterProgressForCharacter(Character $character, int $zoneId): array
    {
        $zoneRankings = $this->fetchRawZoneRankingsForCharacter($character, $zoneId);
        $encounters = $this->extractEncounterRankings($zoneRankings)
            ->map(fn (array $ranking) => [
                'name' => $this->extractRankingBossName($ranking) ?? 'Unknown Encounter',
                'kills' => $this->resolveEncounterKills($ranking),
                'progress' => $this->resolveEncounterProgress($ranking),
            ])
            ->sortBy([
                ['kills', 'desc'],
                ['progress', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        return [
            'zone_id' => $zoneId,
            'encounters' => $encounters->all(),
            'encounter_count' => $encounters->count(),
            'total_kills' => $encounters->sum('kills'),
        ];
    }

    public function fetchRawZoneRankingsForCharacter(Character $character, int $zoneId): array
    {
        if ($zoneId <= 0) {
            throw new RuntimeException('FF Logs zone ID must be a positive integer.');
        }

        return Cache::remember(
            $this->zoneProgressCacheKey($character, $zoneId),
            now()->addHours(self::ZONE_PROGRESS_CACHE_TTL_HOURS),
            function () use ($character, $zoneId) {
                $response = $this->queryCharacterZoneRankings(
                    name: $character->name,
                    serverSlug: $this->resolveServerSlug($character->world),
                    serverRegion: $this->resolveServerRegion($character->datacenter),
                    zoneId: $zoneId,
                );

                return $this->extractZoneRankings($character, $response);
            }
        );
    }

    private function queryCharacterZoneRankings(string $name, string $serverSlug, string $serverRegion, int $zoneId): array
    {
        $query = <<<'GRAPHQL'
query CharacterZoneRankings(
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

    private function resolveServerRegion(?string $datacenter): string
    {
        $normalizedDatacenter = Str::of((string) $datacenter)->lower()->trim()->value();

        if (isset(self::DATACENTER_REGION_MAP[$normalizedDatacenter])) {
            return self::DATACENTER_REGION_MAP[$normalizedDatacenter];
        }

        throw new RuntimeException("Unable to resolve FF Logs server region for datacenter [{$datacenter}].");
    }

    private function zoneProgressCacheKey(Character $character, int $zoneId): string
    {
        return sprintf(
            'fflogs:zone-progress:character:%s:%s:%s:%s:zone:%d',
            $character->lodestone_id ?: 'unknown',
            Str::slug($character->name),
            Str::slug((string) $character->world),
            Str::slug((string) $character->datacenter),
            $zoneId,
        );
    }
}
