<?php

use App\DTOs\LodestoneCharacterData;
use App\Models\Character;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Characters\CharacterProfileRefreshService;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\Services\Lodestone\ForkedTowerBloodAchievementProgressFetcher;
use App\Services\Lodestone\LodestoneScraper;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function characterRefreshEmptyLodestoneAchievementProgress(): array
{
    return [
        'clears' => 0,
        'data_source' => 'lodestone_achievement',
        'bosses' => [
            ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
            ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
            ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
            ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
        ],
    ];
}

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
        ->withArgs(fn (Character $refreshedCharacter, bool $ignoreCache) => $refreshedCharacter->is($character)
            && $refreshedCharacter->name === 'New Name'
            && $refreshedCharacter->world === 'Twintania'
            && $refreshedCharacter->datacenter === 'Light'
            && $ignoreCache)
        ->andThrow(new RuntimeException('FF Logs could not resolve character.'));

    $lodestoneAchievementFetcher = Mockery::mock(ForkedTowerBloodAchievementProgressFetcher::class);
    $lodestoneAchievementFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter, bool $ignoreCache) => $refreshedCharacter->is($character)
            && $ignoreCache)
        ->andReturn(characterRefreshEmptyLodestoneAchievementProgress());

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);
    app()->instance(ForkedTowerBloodAchievementProgressFetcher::class, $lodestoneAchievementFetcher);

    $response = $this->post(route('characters.refresh', $character));

    $response
        ->assertRedirect()
        ->assertSessionHas('success', 'character_data_refreshed')
        ->assertSessionHas('flash_data.character_refresh_debug.fflogs_error.source', 'fflogs')
        ->assertSessionHas('flash_data.character_refresh_debug.fflogs_error.message', 'FF Logs could not resolve character.')
        ->assertSessionMissing('errors');

    expect($character->fresh())
        ->name->toBe('New Name')
        ->world->toBe('Twintania')
        ->avatar_url->toBe('https://example.com/new-avatar.png')
        ->lodestone_refreshed_at->not->toBeNull();

    expect($character->fresh()->occultProgress)->not->toBeNull();
    expect($character->fresh()->occultProgress->knowledge_level)->toBe(7);
    expect($character->fresh()->occultProgress->forkedTowerBloodProgress())
        ->toMatchArray([
            'clears' => 0,
            'data_source' => 'fflogs',
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ]);

    $event = NotificationEvent::query()->where('type', 'characters.refreshed')->sole();

    expect($event->category)->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
        ->and($event->message_params['character'])->toBe('New Name')
        ->and($event->message_params['world'])->toBe('Twintania');

    expect(UserNotification::query()->where('notification_event_id', $event->id)->sole()->user_id)
        ->toBe($user->id);
});

it('falls back to lodestone achievements when ff logs has no forked tower progress', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create([
        'name' => 'Old Name',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'lodestone_id' => '47431834',
    ]);

    $this->actingAs($user);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper
        ->shouldReceive('scrape')
        ->once()
        ->with('47431834', true, true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '47431834',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/class_job/',
            name: 'Giki Chomusuke',
            world: 'Lich',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/avatar.png',
            bio: '',
            extraData: [
                'progression.occult.knowledge_level' => 20,
            ],
        ));

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->andReturn([
            'clears' => 0,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ]);

    $lodestoneAchievementFetcher = Mockery::mock(ForkedTowerBloodAchievementProgressFetcher::class);
    $lodestoneAchievementFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter, bool $ignoreCache) => $refreshedCharacter->is($character)
            && $ignoreCache)
        ->andReturn([
            'clears' => 50,
            'data_source' => 'lodestone_achievement',
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 50, 'progress' => 100],
                ['key' => 'dead_stars', 'kills' => 50, 'progress' => 100],
                ['key' => 'marble_dragon', 'kills' => 50, 'progress' => 100],
                ['key' => 'magitaur', 'kills' => 50, 'progress' => 100],
            ],
        ]);

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);
    app()->instance(ForkedTowerBloodAchievementProgressFetcher::class, $lodestoneAchievementFetcher);

    $response = $this->post(route('characters.refresh', $character));

    $response
        ->assertRedirect()
        ->assertSessionHas('success', 'character_data_refreshed')
        ->assertSessionMissing('errors');

    $progress = $character->fresh()->occultProgress;

    expect($progress)->not->toBeNull()
        ->and($progress->data_source)->toBe('lodestone_achievement')
        ->and($progress->knowledge_level)->toBe(20)
        ->and($progress->demon_tablet_kills)->toBe(50)
        ->and($progress->demon_tablet_progress)->toBe(100)
        ->and($progress->magitaur_kills)->toBe(50)
        ->and($progress->magitaur_progress)->toBe(100);
});

it('skips profile refreshes while the lodestone cooldown is active', function () {
    $character = Character::factory()->create([
        'lodestone_refreshed_at' => now()->subMinutes(30),
    ]);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper->shouldNotReceive('scrape');

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher->shouldNotReceive('fetchForCharacter');

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);

    $result = app(CharacterProfileRefreshService::class)
        ->refreshIfOlderThan($character, 3600);

    expect($result['refreshed'])->toBeFalse()
        ->and($result['available_at']?->greaterThan(now()))->toBeTrue()
        ->and($result['fflogs_error'])->toBeNull();
});
