<?php

use App\Models\Character;
use App\Services\Lodestone\ForkedTowerBloodAchievementProgressFetcher;
use App\Services\Lodestone\LodestoneHttpClient;
use App\Services\Lodestone\Parsers\LodestoneAchievementParser;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

it('uses the highest obtained forked tower lodestone achievement as a clear floor', function () {
    $character = new Character([
        'lodestone_id' => '47431834',
    ]);

    $httpClient = Mockery::mock(LodestoneHttpClient::class);
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/achievement/detail/3671/'))
        ->andReturn('<html><body><div class="achievement">Not yet obtained</div></body></html>');
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/achievement/detail/3670/'))
        ->andReturn('<html><body><div class="achievement"><h1>A Fork To Be Reckoned With III</h1><p>05/30/2026</p></div></body></html>');

    $fetcher = new ForkedTowerBloodAchievementProgressFetcher(
        $httpClient,
        new LodestoneAchievementParser,
    );

    $progress = $fetcher->fetchForCharacter($character, ignoreCache: true);

    expect($progress['data_source'])->toBe('lodestone_achievement')
        ->and($progress['clears'])->toBe(50)
        ->and($progress['bosses'])->toHaveCount(4)
        ->and($progress['bosses'][0])->toMatchArray([
            'key' => 'demon_tablet',
            'kills' => 50,
            'progress' => 100,
        ])
        ->and($progress['bosses'][3])->toMatchArray([
            'key' => 'magitaur',
            'kills' => 50,
            'progress' => 100,
        ]);
});
