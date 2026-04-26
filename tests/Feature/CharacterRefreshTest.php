<?php

use App\Models\Character;
use App\Models\User;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\DTOs\LodestoneCharacterData;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refreshes character data even when ff logs progress lookup fails', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create([
        'name' => 'Old Name',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'lodestone_id' => '12345678',
        'avatar_url' => 'https://example.com/old-avatar.png',
    ]);

    $this->actingAs($user);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper
        ->shouldReceive('scrape')
        ->once()
        ->with('12345678', true, true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '12345678',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/class_job/',
            name: 'New Name',
            world: 'Twintania',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/new-avatar.png',
            bio: '',
            extraData: [
                'progression.occult.knowledge_level' => 7,
            ],
        ));

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter) => $refreshedCharacter->is($character))
        ->andThrow(new \RuntimeException('FF Logs could not resolve character.'));

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);

    $response = $this->post(route('characters.refresh', $character));

    $response
        ->assertRedirect()
        ->assertSessionHas('success', 'character_data_refreshed')
        ->assertSessionMissing('errors');

    expect($character->fresh())
        ->name->toBe('New Name')
        ->world->toBe('Twintania')
        ->avatar_url->toBe('https://example.com/new-avatar.png');

    expect($character->fresh()->occultProgress)->not->toBeNull();
    expect($character->fresh()->occultProgress->knowledge_level)->toBe(7);
    expect($character->fresh()->occultProgress->forkedTowerBloodProgress())
        ->toMatchArray([
            'clears' => 0,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ]);
});
