<?php

use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Support\FFLogsDifficulty;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function cachedFflogsSetup(): Character
{
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::flush();
    Http::preventStrayRequests();

    return Character::factory()->create(['name' => 'Example Player', 'world' => 'Lich', 'datacenter' => 'Light']);
}

function cachedFflogsRankingsResponse(): array
{
    return ['data' => ['characterData' => ['character' => ['zoneRankings' => ['rankings' => [
        ['encounter' => ['id' => 101, 'name' => 'Boss'], 'totalKills' => 1],
    ]]]]]];
}

it('shares the 24 hour cache between roster, applicant, and guest identity lookups', function () {
    $character = cachedFflogsSetup();
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::response(cachedFflogsRankingsResponse()),
    ]);
    $fetcher = app(CharacterZoneProgressFetcher::class);
    $first = $fetcher->fetchEncounterProgressForCharacter($character, 60, 101);
    $again = app(CharacterZoneProgressFetcher::class)->fetchEncounterProgressForCharacter($character->fresh(), 60, 101);
    $guest = $fetcher->fetchEncounterProgressForIdentity($character->name, $character->world, $character->datacenter, null, 60, 101);
    $resolved = $fetcher->fetchRawZoneRankingsForResolvedIdentity($character->name, $character->world, 'EU', 60, 101);
    expect($again)->toBe($first)->and($guest)->toBe($first);
    expect(data_get($resolved, 'rankings.0.totalKills'))->toBe(1);
    $this->travel(23)->hours();
    $fetcher->fetchEncounterProgressForCharacter($character, 60, 101);
    Http::assertSentCount(2);
    $this->travel(2)->hours();
    $fetcher->fetchEncounterProgressForCharacter($character, 60, 101);
    Http::assertSentCount(4);
});

it('backs off failed lookups and pauses other uncached lookups during API outages', function () {
    $character = cachedFflogsSetup();
    $other = Character::factory()->create(['world' => 'Lich', 'datacenter' => 'Light']);
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::sequence()->pushStatus(503)->push(cachedFflogsRankingsResponse()),
    ]);
    $fetcher = app(CharacterZoneProgressFetcher::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($character, 60))->toThrow(RequestException::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($character, 60))->toThrow(RuntimeException::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($other, 60))->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
    $this->travel(121)->seconds();
    expect($fetcher->fetchEncounterProgressForCharacter($character, 60)['total_kills'])->toBe(1);
    Http::assertSentCount(3);
});

it('does not issue duplicate API requests while the same identity is already being fetched', function () {
    $character = cachedFflogsSetup();
    $fetcher = app(CharacterZoneProgressFetcher::class);
    $duplicateBlocked = false;
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => function () use ($fetcher, $character, &$duplicateBlocked) {
            try {
                $fetcher->fetchEncounterProgressForIdentity($character->name, $character->world, $character->datacenter, null, 60);
            } catch (LockTimeoutException) {
                $duplicateBlocked = true;
            }

            return Http::response(cachedFflogsRankingsResponse());
        },
    ]);
    $fetcher->fetchEncounterProgressForCharacter($character, 60);
    $fetcher->fetchEncounterProgressForCharacter($character, 60);
    expect($duplicateBlocked)->toBeTrue();
    Http::assertSentCount(2);
});

it('backs off authentication failures across different characters', function () {
    $character = cachedFflogsSetup();
    $other = Character::factory()->create(['world' => 'Lich', 'datacenter' => 'Light']);
    Http::fake(['https://fflogs.test/oauth/token' => Http::response([], 401)]);
    $fetcher = app(CharacterZoneProgressFetcher::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($character, 60))->toThrow(RequestException::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($other, 60))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('bypasses and replaces cached ff logs zone rankings when requested', function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');

    Cache::flush();

    $character = Character::factory()->create([
        'name' => 'Giki Chomusuke',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'lodestone_id' => '12345678',
    ]);

    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response([
            'access_token' => 'token',
            'expires_in' => 3600,
        ]),
        'https://fflogs.test/graphql' => Http::sequence()
            ->push([
                'data' => [
                    'characterData' => [
                        'character' => [
                            'zoneRankings' => [
                                'rankings' => [],
                            ],
                        ],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'characterData' => [
                        'character' => [
                            'zoneRankings' => [
                                'rankings' => [
                                    [
                                        'encounter' => ['name' => 'Magitaur'],
                                        'totalKills' => 1,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
    ]);

    $fetcher = app(CharacterZoneProgressFetcher::class);

    $initialRankings = $fetcher->fetchRawZoneRankingsForCharacter($character, 69);
    $cachedRankings = $fetcher->fetchRawZoneRankingsForCharacter($character, 69);
    $freshRankings = $fetcher->fetchRawZoneRankingsForCharacter($character, 69, ignoreCache: true);
    $updatedCachedRankings = $fetcher->fetchRawZoneRankingsForCharacter($character, 69);

    expect(data_get($initialRankings, 'rankings'))->toBe([])
        ->and(data_get($cachedRankings, 'rankings'))->toBe([])
        ->and(data_get($freshRankings, 'rankings.0.encounter.name'))->toBe('Magitaur')
        ->and(data_get($updatedCachedRankings, 'rankings.0.encounter.name'))->toBe('Magitaur');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/oauth/token');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/graphql');
    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://fflogs.test/graphql') {
            return false;
        }

        return ! array_key_exists('difficulty', $request->data()['variables'] ?? []);
    });
});

it('passes explicit ff logs difficulty and caches each difficulty separately', function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');

    Cache::flush();

    $character = Character::factory()->create([
        'name' => 'Giki Chomusuke',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'lodestone_id' => '87654321',
    ]);

    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response([
            'access_token' => 'token',
            'expires_in' => 3600,
        ]),
        'https://fflogs.test/graphql' => Http::sequence()
            ->push([
                'data' => [
                    'characterData' => [
                        'character' => [
                            'zoneRankings' => [
                                'rankings' => [
                                    [
                                        'encounter' => ['name' => 'Index'],
                                        'totalKills' => 2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'characterData' => [
                        'character' => [
                            'zoneRankings' => [
                                'rankings' => [
                                    [
                                        'encounter' => ['name' => 'Index'],
                                        'totalKills' => 9,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
    ]);

    $fetcher = app(CharacterZoneProgressFetcher::class);

    $extremeRankings = $fetcher->fetchRawZoneRankingsForCharacter(
        $character,
        77,
        difficulty: FFLogsDifficulty::FORKED_TOWER_MAGIC_EXTREME,
    );
    $cachedExtremeRankings = $fetcher->fetchRawZoneRankingsForCharacter(
        $character,
        77,
        difficulty: FFLogsDifficulty::FORKED_TOWER_MAGIC_EXTREME,
    );
    $normalRankings = $fetcher->fetchRawZoneRankingsForCharacter($character, 77, difficulty: 100);

    expect(data_get($extremeRankings, 'rankings.0.totalKills'))->toBe(2)
        ->and(data_get($cachedExtremeRankings, 'rankings.0.totalKills'))->toBe(2)
        ->and(data_get($normalRankings, 'rankings.0.totalKills'))->toBe(9);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/oauth/token');
    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://fflogs.test/graphql') {
            return false;
        }

        return data_get($request->data(), 'variables.difficulty') === FFLogsDifficulty::FORKED_TOWER_MAGIC_EXTREME
            && str_contains((string) ($request->data()['query'] ?? ''), 'difficulty: $difficulty');
    });
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/graphql'
        && data_get($request->data(), 'variables.difficulty') === 100);
});
