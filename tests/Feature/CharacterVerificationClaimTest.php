<?php

use App\DTOs\LodestoneCharacterData;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCharacterClaimActivity(): Activity
{
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'application_schema' => [
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ]);
}

it('claims matching guest applications and auto-refreshes on xivauth import', function () {
    $user = User::factory()->create();
    $character = Character::factory()->provisional()->create([
        'lodestone_id' => '47431834',
        'name' => 'Guest Applicant',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => 'https://example.com/old-avatar.png',
    ]);

    $firstActivity = createCharacterClaimActivity();
    $secondActivity = createCharacterClaimActivity();

    $firstApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $firstActivity->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Guest Applicant',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
        'guest_access_token' => 'guest-token-one',
    ]);

    $secondApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $secondActivity->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Guest Applicant',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
        'guest_access_token' => 'guest-token-two',
    ]);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper
        ->shouldReceive('scrape')
        ->once()
        ->with('47431834', true, true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '47431834',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/class_job/',
            name: 'Claimed Applicant',
            world: 'Lich',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/new-avatar.png',
            bio: '',
            extraData: [
                'progression.occult.knowledge_level' => 4,
            ],
        ));

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter) => $refreshedCharacter->is($character))
        ->andReturn([
            'clears' => 2,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 1, 'progress' => 100],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 42],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ]);

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);

    $this->actingAs($user);

    $response = $this->post(route('characters.xivauth.import'), [
        'lodestone_id' => '47431834',
        'name' => 'Imported Applicant',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => 'https://example.com/imported-avatar.png',
    ]);

    $response
        ->assertRedirect()
        ->assertSessionHas('flash_data.xivauth_character_import.character.id', $character->id);

    $character->refresh();
    $firstApplication->refresh();
    $secondApplication->refresh();

    expect($character->user_id)->toBe($user->id)
        ->and($character->verified_at)->not->toBeNull()
        ->and($character->name)->toBe('Claimed Applicant')
        ->and($character->world)->toBe('Lich')
        ->and($character->avatar_url)->toBe('https://example.com/new-avatar.png')
        ->and($character->add_method)->toBe('xivauth');

    expect($firstApplication->user_id)->toBe($user->id)
        ->and($firstApplication->selected_character_id)->toBe($character->id)
        ->and($firstApplication->guest_access_token)->toBeNull()
        ->and($secondApplication->user_id)->toBe($user->id)
        ->and($secondApplication->selected_character_id)->toBe($character->id)
        ->and($secondApplication->guest_access_token)->toBeNull();
});

it('claims matching guest applications and auto-refreshes on manual verification', function () {
    $user = User::factory()->create();
    $character = Character::factory()->provisional()->create([
        'lodestone_id' => '12345678',
        'name' => 'Token Applicant',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => 'https://example.com/token-old-avatar.png',
        'token' => 'FP-VERIFYME',
        'expires_at' => now()->addDay(),
    ]);

    $activity = createCharacterClaimActivity();
    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => '12345678',
        'applicant_character_name' => 'Token Applicant',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
        'guest_access_token' => 'guest-token-verify',
    ]);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper
        ->shouldReceive('scrapeProfile')
        ->once()
        ->with('12345678', true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '12345678',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/class_job/',
            name: 'Token Applicant',
            world: 'Twintania',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/token-profile-avatar.png',
            bio: 'Hello FP-VERIFYME world',
            extraData: [],
        ));
    $lodestoneScraper
        ->shouldReceive('scrape')
        ->once()
        ->with('12345678', true, true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '12345678',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/class_job/',
            name: 'Verified Applicant',
            world: 'Lich',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/token-new-avatar.png',
            bio: '',
            extraData: [
                'progression.occult.knowledge_level' => 9,
            ],
        ));

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter) => $refreshedCharacter->is($character))
        ->andReturn([
            'clears' => 0,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 15],
            ],
        ]);

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);

    $this->actingAs($user);

    $response = $this->post(route('characters.verify'), [
        'character_id' => $character->id,
        'token' => 'FP-VERIFYME',
    ]);

    $response
        ->assertRedirect()
        ->assertSessionHas('flash_data.character_verification.success', true);

    $character->refresh();
    $application->refresh();

    expect($character->user_id)->toBe($user->id)
        ->and($character->verified_at)->not->toBeNull()
        ->and($character->token)->toBeNull()
        ->and($character->name)->toBe('Verified Applicant')
        ->and($character->world)->toBe('Lich')
        ->and($character->avatar_url)->toBe('https://example.com/token-new-avatar.png')
        ->and($character->add_method)->toBe('manual');

    expect($application->user_id)->toBe($user->id)
        ->and($application->selected_character_id)->toBe($character->id)
        ->and($application->guest_access_token)->toBeNull();
});

it('generates a fresh verification token for provisional characters during manual lookup', function () {
    $user = User::factory()->create();
    $character = Character::factory()->provisional()->create([
        'lodestone_id' => '87654321',
        'name' => 'Lookup Applicant',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'token' => null,
        'expires_at' => null,
    ]);

    $this->actingAs($user);

    $response = $this->from(route('account.characters'))->post(route('characters.exists'), [
        'lodestone_id' => '87654321',
    ]);

    $response
        ->assertRedirect(route('account.characters'))
        ->assertSessionHas('flash_data.manual_character_lookup.character.id', $character->id);

    $character->refresh();

    expect($character->token)->not->toBeNull()
        ->and($character->token)->toStartWith('FP-')
        ->and($character->expires_at)->not->toBeNull();
});

it('does not auto claim guest applications when the user already has an application for the same activity', function () {
    $user = User::factory()->create();
    $verifiedCharacter = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '11112222',
    ]);
    $provisionalCharacter = Character::factory()->provisional()->create([
        'lodestone_id' => '47431834',
        'name' => 'Guest Applicant',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);

    $activity = createCharacterClaimActivity();

    $existingApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $verifiedCharacter->id,
    ]);

    $guestApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'selected_character_id' => $provisionalCharacter->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Guest Applicant',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
        'guest_access_token' => 'guest-conflict-token',
    ]);

    $lodestoneScraper = Mockery::mock(LodestoneScraper::class);
    $lodestoneScraper
        ->shouldReceive('scrape')
        ->once()
        ->with('47431834', true, true)
        ->andReturn(new LodestoneCharacterData(
            lodestoneId: '47431834',
            profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/',
            classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/47431834/class_job/',
            name: 'Claimed Guest',
            world: 'Lich',
            dataCenter: 'Light',
            avatarUrl: 'https://example.com/conflict-avatar.png',
            bio: '',
            extraData: [],
        ));

    $ffLogsFetcher = Mockery::mock(ForkedTowerBloodProgressFetcher::class);
    $ffLogsFetcher
        ->shouldReceive('fetchForCharacter')
        ->once()
        ->withArgs(fn (Character $refreshedCharacter) => $refreshedCharacter->is($provisionalCharacter))
        ->andReturn([
            'clears' => 0,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ]);

    app()->instance(LodestoneScraper::class, $lodestoneScraper);
    app()->instance(ForkedTowerBloodProgressFetcher::class, $ffLogsFetcher);

    $this->actingAs($user);

    $response = $this->post(route('characters.xivauth.import'), [
        'lodestone_id' => '47431834',
        'name' => 'Claimed Guest',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => 'https://example.com/imported-avatar.png',
    ]);

    $response->assertRedirect();

    $existingApplication->refresh();
    $guestApplication->refresh();
    $provisionalCharacter->refresh();

    expect($provisionalCharacter->user_id)->toBe($user->id);
    expect($existingApplication->user_id)->toBe($user->id);
    expect($existingApplication->selected_character_id)->toBe($verifiedCharacter->id);
    expect($guestApplication->user_id)->toBeNull();
    expect($guestApplication->selected_character_id)->toBe($provisionalCharacter->id);
    expect($guestApplication->guest_access_token)->toBe('guest-conflict-token');
});
