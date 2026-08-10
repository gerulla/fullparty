<?php

use App\Models\Character;
use App\Services\Lodestone\ForkedTowerMagicAchievementProgressFetcher;
use App\Services\Lodestone\LodestoneHttpClient;
use App\Services\Lodestone\Parsers\LodestoneAchievementParser;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

it('uses the highest obtained forked tower magic lodestone achievement as a clear floor', function () {
    $character = new Character([
        'lodestone_id' => '47431834',
    ]);

    $httpClient = Mockery::mock(LodestoneHttpClient::class);
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/achievement/detail/4013/'))
        ->andReturn('<html><body><div class="achievement">Not yet obtained</div></body></html>');
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/achievement/detail/4012/'))
        ->andReturn('<html><body><div class="achievement"><h1>Tour de Fork III</h1><p>08/10/2026</p></div></body></html>');

    $fetcher = new ForkedTowerMagicAchievementProgressFetcher(
        $httpClient,
        new LodestoneAchievementParser,
    );

    $progress = $fetcher->fetchForCharacter($character, ignoreCache: true);

    expect($progress['data_source'])->toBe('lodestone_achievement')
        ->and($progress['clears'])->toBe(20)
        ->and($progress['bosses'])->toHaveCount(4)
        ->and($progress['bosses'][0])->toMatchArray([
            'key' => 'two_headed_aevis',
            'kills' => 20,
            'progress' => 100,
        ])
        ->and($progress['bosses'][3])->toMatchArray([
            'key' => 'index',
            'kills' => 20,
            'progress' => 100,
        ]);
});
