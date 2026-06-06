<?php

use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('bypasses and replaces cached ff logs zone rankings when requested', function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');

    Cache::forget('fflogs:client_credentials_token');

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
});
