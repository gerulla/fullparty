<?php

use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\FFLogs\ForkedTowerMagicProgressFetcher;

it('maps the forked tower magic extreme encounters from ff logs zone 77', function () {
    config()->set('services.ff_logs.forked_tower_magic_zone_id', 77);

    $character = new Character;
    $zoneProgressFetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    $zoneProgressFetcher
        ->shouldReceive('fetchRawZoneRankingsForCharacter')
        ->once()
        ->with($character, 77, true)
        ->andReturn([
            'rankings' => [
                ['encounter' => ['id' => 2075, 'name' => 'Two-headed Aevis'], 'totalKills' => 9],
                ['name' => 'Sword Dancer', 'kills' => 7],
                ['encounterName' => 'Necrophobia', 'bestProgress' => 64.6],
                ['encounter' => ['id' => 2078, 'name' => 'Index'], 'totalKills' => 2],
            ],
        ]);

    $progress = (new ForkedTowerMagicProgressFetcher($zoneProgressFetcher))
        ->fetchForCharacter($character, ignoreCache: true);

    expect($progress)
        ->toMatchArray([
            'clears' => 2,
            'bosses' => [
                ['key' => 'two_headed_aevis', 'name' => 'Two-headed Aevis', 'kills' => 9, 'progress' => 100],
                ['key' => 'sword_dancer', 'name' => 'Sword Dancer', 'kills' => 7, 'progress' => 100],
                ['key' => 'necrophobia', 'name' => 'Necrophobia', 'kills' => 0, 'progress' => 65],
                ['key' => 'index', 'name' => 'Index', 'kills' => 2, 'progress' => 100],
            ],
        ]);
});
