<?php

namespace App\Services\FFLogs;

use App\Models\Character;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    private const FAILURE_CACHE_TTL_SECONDS = 120;

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

    public function fetchEncounterProgressForCharacter(Character $character, int $zoneId, ?int $difficulty = null): array
    {
        $zoneRankings = $this->fetchRawZoneRankingsForCharacter($character, $zoneId, difficulty: $difficulty);
        $encounters = $this->extractEncounterRankings($zoneRankings)
            ->map(fn (array $ranking) => [
                'encounter_id' => (int) data_get($ranking, 'encounter.id', 0),
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

    public function fetchEncounterProgressForIdentity(
        string $name,
        string $world,
        ?string $datacenter,
        ?string $lodestoneId,
        int $zoneId,
        ?int $difficulty = null,
    ): array {
        $zoneRankings = $this->fetchRawZoneRankingsForIdentity(
            $name,
            $world,
            $datacenter,
            $lodestoneId,
            $zoneId,
            $difficulty,
        );
        $encounters = $this->extractEncounterRankings($zoneRankings)
            ->map(fn (array $ranking) => [
                'encounter_id' => (int) data_get($ranking, 'encounter.id', 0),
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

    public function fetchRawZoneRankingsForCharacter(
        Character $character,
        int $zoneId,
        bool $ignoreCache = false,
        ?int $difficulty = null,
    ): array {
        $this->validateZoneRankingQuery($zoneId, $difficulty);

        if ($ignoreCache) {
            return $this->refreshRawZoneRankingsForCharacter($character, $zoneId, $difficulty);
        }

        return $this->rememberRankings(
            $this->zoneProgressCacheKey($character, $zoneId, $difficulty),
            fn () => $this->queryRawZoneRankingsForCharacter($character, $zoneId, $difficulty)
        );
    }

    public function fetchRawZoneRankingsForIdentity(
        string $name,
        string $world,
        ?string $datacenter,
        ?string $lodestoneId,
        int $zoneId,
        ?int $difficulty = null,
    ): array {
        $this->validateZoneRankingQuery($zoneId, $difficulty);

        $normalizedName = trim($name);
        $normalizedWorld = trim($world);

        if ($normalizedName === '' || $normalizedWorld === '') {
            throw new RuntimeException('FF Logs character identity is incomplete.');
        }

        return $this->rememberRankings(
            $this->zoneProgressIdentityCacheKey(
                $normalizedName,
                $normalizedWorld,
                $datacenter,
                $zoneId,
                $difficulty,
            ),
            function () use ($normalizedName, $normalizedWorld, $datacenter, $zoneId, $difficulty) {
                $response = $this->queryCharacterZoneRankings(
                    name: $normalizedName,
                    serverSlug: $this->resolveServerSlug($normalizedWorld),
                    serverRegion: $this->resolveServerRegion($datacenter),
                    zoneId: $zoneId,
                    difficulty: $difficulty,
                );

                return $this->extractZoneRankingsForIdentity($normalizedName, $normalizedWorld, $datacenter, $response);
            }
        );
    }

    public function fetchRawZoneRankingsForResolvedIdentity(
        string $name,
        string $serverSlug,
        string $serverRegion,
        int $zoneId,
        ?int $difficulty = null,
    ): array {
        $this->validateZoneRankingQuery($zoneId, $difficulty);

        $normalizedName = trim($name);
        $normalizedServerSlug = trim($serverSlug);
        $normalizedServerRegion = strtoupper(trim($serverRegion));

        if ($normalizedName === '' || $normalizedServerSlug === '' || $normalizedServerRegion === '') {
            throw new RuntimeException('FF Logs character identity is incomplete.');
        }

        return $this->rememberRankings(
            $this->zoneProgressIdentityCacheKey($normalizedName, $normalizedServerSlug, $normalizedServerRegion, $zoneId, $difficulty),
            function () use ($normalizedName, $normalizedServerSlug, $normalizedServerRegion, $zoneId, $difficulty) {
                $response = $this->queryCharacterZoneRankings(
                    name: $normalizedName,
                    serverSlug: $this->resolveServerSlug($normalizedServerSlug),
                    serverRegion: $normalizedServerRegion,
                    zoneId: $zoneId,
                    difficulty: $difficulty,
                );

                return $this->extractZoneRankingsForIdentity($normalizedName, $normalizedServerSlug, $normalizedServerRegion, $response);
            },
        );
    }

    private function refreshRawZoneRankingsForCharacter(Character $character, int $zoneId, ?int $difficulty): array
    {
        return $this->rememberRankings(
            $this->zoneProgressCacheKey($character, $zoneId, $difficulty),
            fn () => $this->queryRawZoneRankingsForCharacter($character, $zoneId, $difficulty),
            refresh: true,
        );
    }

    private function rememberRankings(string $key, Closure $fetch, bool $refresh = false): array
    {
        if (! $refresh && ($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        // Recheck after acquiring the lock: another viewer may have just filled this cache.
        return Cache::lock($key.':fetching', 45)->block(5, function () use ($key, $fetch, $refresh) {
            if (! $refresh && ($cached = Cache::get($key)) !== null) {
                return $cached;
            }
            if (Cache::has($key.':failed')) {
                throw new RuntimeException('FF Logs progress is temporarily unavailable; retry shortly.');
            }
            try {
                $rankings = $fetch();
                Cache::put($key, $rankings, now()->addHours(self::ZONE_PROGRESS_CACHE_TTL_HOURS));

                return $rankings;
            } catch (\Throwable $exception) {
                Cache::put($key.':failed', true, self::FAILURE_CACHE_TTL_SECONDS);
                throw $exception;
            }
        });
    }

    private function queryRawZoneRankingsForCharacter(Character $character, int $zoneId, ?int $difficulty): array
    {
        $response = $this->queryCharacterZoneRankings(
            name: $character->name,
            serverSlug: $this->resolveServerSlug($character->world),
            serverRegion: $this->resolveServerRegion($character->datacenter),
            zoneId: $zoneId,
            difficulty: $difficulty,
        );

        return $this->extractZoneRankings($character, $response);
    }

    private function queryCharacterZoneRankings(
        string $name,
        string $serverSlug,
        string $serverRegion,
        int $zoneId,
        ?int $difficulty,
    ): array {
        $query = $difficulty === null ? <<<'GRAPHQL'
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
GRAPHQL
            : <<<'GRAPHQL'
query CharacterZoneRankings(
  $name: String!,
  $serverSlug: String!,
  $serverRegion: String!,
  $zoneId: Int!,
  $difficulty: Int!
) {
  characterData {
    character(name: $name, serverSlug: $serverSlug, serverRegion: $serverRegion) {
      zoneRankings(zoneID: $zoneId, difficulty: $difficulty)
    }
  }
}
GRAPHQL;

        $variables = [
            'name' => $name,
            'serverSlug' => $serverSlug,
            'serverRegion' => $serverRegion,
            'zoneId' => $zoneId,
        ];

        if ($difficulty !== null) {
            $variables['difficulty'] = $difficulty;
        }

        if (Cache::has('fflogs:zone-requests:cooldown')) {
            throw new RuntimeException('FF Logs requests are temporarily paused after a service failure.');
        }
        try {
            $response = Http::withToken($this->getAccessToken())
                ->connectTimeout(5)->timeout(15)
                ->acceptJson()
                ->post(config('services.ff_logs.graphql_url'), [
                    'query' => $query,
                    'variables' => $variables,
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $exception) {
            if ($exception instanceof ConnectionException || $exception->response->status() === 429 || $exception->response->serverError()) {
                Cache::put('fflogs:zone-requests:cooldown', true, 60);
            }
            throw $exception;
        }

        if (! empty($response['errors'])) {
            throw new RuntimeException('FF Logs GraphQL query failed: '.json_encode($response['errors']));
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

    private function extractZoneRankingsForIdentity(
        string $name,
        string $world,
        ?string $datacenter,
        array $response,
    ): array {
        if (data_get($response, 'data.characterData.character') === null) {
            throw new RuntimeException(sprintf(
                'FF Logs could not resolve character [%s] on server [%s] in region [%s].',
                $name,
                $world,
                $this->resolveServerRegion($datacenter),
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

    private function validateZoneRankingQuery(int $zoneId, ?int $difficulty): void
    {
        if ($zoneId <= 0) {
            throw new RuntimeException('FF Logs zone ID must be a positive integer.');
        }

        if ($difficulty !== null && $difficulty <= 0) {
            throw new RuntimeException('FF Logs difficulty must be a positive integer.');
        }
    }

    private function getAccessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cachedToken) {
            return $cachedToken;
        }

        return Cache::lock(self::TOKEN_CACHE_KEY.':fetching', 20)->block(5, function () {
            if ($cachedToken = Cache::get(self::TOKEN_CACHE_KEY)) {
                return $cachedToken;
            }
            if (Cache::has(self::TOKEN_CACHE_KEY.':failed')) {
                throw new RuntimeException('FF Logs authentication is temporarily unavailable.');
            }
            try {
                return $this->requestAccessToken();
            } catch (\Throwable $exception) {
                Cache::put(self::TOKEN_CACHE_KEY.':failed', true, self::FAILURE_CACHE_TTL_SECONDS);
                throw $exception;
            }
        });
    }

    private function requestAccessToken(): string
    {
        $clientId = config('services.ff_logs.client_id');
        $clientSecret = config('services.ff_logs.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('FF Logs credentials are not configured.');
        }

        $response = Http::asForm()
            ->connectTimeout(5)->timeout(10)
            ->withBasicAuth($clientId, $clientSecret)
            ->post(config('services.ff_logs.token_url'), [
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        $token = $response['access_token'] ?? null;

        if (! $token) {
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

    private function zoneProgressCacheKey(Character $character, int $zoneId, ?int $difficulty): string
    {
        return $this->zoneProgressIdentityCacheKey(
            $character->name,
            (string) $character->world,
            $character->datacenter,
            $zoneId,
            $difficulty,
        );
    }

    private function zoneProgressIdentityCacheKey(
        string $name,
        string $world,
        ?string $datacenter,
        int $zoneId,
        ?int $difficulty,
    ): string {
        return sprintf(
            'fflogs:zone-progress:v2:%s:%s:%s:zone:%d:difficulty:%s',
            Str::slug($name),
            Str::slug($world),
            Str::slug(self::DATACENTER_REGION_MAP[strtolower(trim((string) $datacenter))] ?? (string) $datacenter),
            $zoneId,
            $this->difficultyCacheSegment($difficulty),
        );
    }

    private function difficultyCacheSegment(?int $difficulty): string
    {
        return $difficulty === null ? 'default' : (string) $difficulty;
    }
}
