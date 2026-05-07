<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createGuestApplicationActivity(array $activityOverrides = []): Activity
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

    return Activity::factory()->create(array_merge([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $activityOverrides));
}

it('allows guests to submit applications when enabled', function () {
    $activity = createGuestApplicationActivity();

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Twintania',
            'datacenter' => 'Light',
            'avatar_url' => 'https://example.com/avatar.png',
        ],
        'notes' => 'Can flex healer if needed.',
        'answers' => [
            'experience' => 'Cleared to enrage.',
        ],
    ]);

    $application = ActivityApplication::query()->sole();
    $application->load('selectedCharacter');

    $response
        ->assertRedirect(route('groups.activities.application.status', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
            'accessToken' => $application->guest_access_token,
        ]))
        ->assertSessionMissing("activities.{$activity->id}.application_confirmation");

    expect($application->user_id)->toBeNull()
        ->and($application->selected_character_id)->not->toBeNull()
        ->and($application->applicant_lodestone_id)->toBe('47431834')
        ->and($application->applicant_character_name)->toBe('Warrior Light')
        ->and($application->applicant_world)->toBe('Twintania')
        ->and($application->applicant_datacenter)->toBe('Light')
        ->and($application->applicant_avatar_url)->toBe('https://example.com/avatar.png')
        ->and($application->status)->toBe(ActivityApplication::STATUS_PENDING)
        ->and($application->selectedCharacter?->user_id)->toBeNull()
        ->and($application->selectedCharacter?->lodestone_id)->toBe('47431834')
        ->and($application->selectedCharacter?->add_method)->toBe('guest_application');

    expect($application->answers)->toHaveCount(1);
    expect($application->answers->first()->question_key)->toBe('experience');
});

it('rejects guest applications when they are disabled', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Twintania',
            'datacenter' => 'Light',
        ],
        'answers' => [
            'experience' => 'Cleared to enrage.',
        ],
    ]);

    $response->assertForbidden();
    expect(ActivityApplication::query()->count())->toBe(0);
});

it('prevents duplicate guest applications for the same activity and lodestone id', function () {
    $activity = createGuestApplicationActivity();

    ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Twintania',
            'datacenter' => 'Light',
        ],
        'answers' => [
            'experience' => 'Cleared to enrage.',
        ],
    ]);

    $response->assertStatus(422);
    expect(ActivityApplication::query()->count())->toBe(1);
});

it('still allows authenticated users to submit applications with their verified character', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Claimed Warrior',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'lodestone_id' => '98765432',
    ]);

    $this->actingAs($user);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'notes' => 'Bringing caster.',
        'answers' => [
            'experience' => 'Seen phase three.',
        ],
    ]);

    $response
        ->assertRedirect(route('groups.activities.application.confirmation', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]))
        ->assertSessionHas("activities.{$activity->id}.application_confirmation.mode", 'submitted');

    $application = ActivityApplication::query()->sole();

    expect($application->user_id)->toBe($user->id)
        ->and($application->selected_character_id)->toBe($character->id)
        ->and($application->applicant_lodestone_id)->toBe('98765432')
        ->and($application->applicant_character_name)->toBe('Claimed Warrior')
        ->and($application->applicant_world)->toBe('Lich')
        ->and($application->applicant_datacenter)->toBe('Light');
});

it('allows authenticated users to reapply after withdrawing a previous application', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '22224444',
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($user);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Back for another try.',
        ],
    ]);

    $response->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect(ActivityApplication::query()->where('activity_id', $activity->id)->count())->toBe(2);
    expect(ActivityApplication::query()->where('activity_id', $activity->id)->where('status', ActivityApplication::STATUS_PENDING)->count())->toBe(1);
});

it('allows guests to reapply after withdrawing a previous application', function () {
    $activity = createGuestApplicationActivity();

    ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Twintania',
            'datacenter' => 'Light',
            'avatar_url' => 'https://example.com/avatar.png',
        ],
        'answers' => [
            'experience' => 'Trying again after withdrawing.',
        ],
    ]);

    $newApplication = ActivityApplication::query()
        ->where('activity_id', $activity->id)
        ->where('status', ActivityApplication::STATUS_PENDING)
        ->latest('id')
        ->firstOrFail();

    $response->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $newApplication->guest_access_token,
    ]));

    expect(ActivityApplication::query()->where('activity_id', $activity->id)->count())->toBe(2);
});

it('returns guest search results through the reusable lodestone search service endpoint', function () {
    $activity = createGuestApplicationActivity();

    $searchService = Mockery::mock(LodestoneCharacterSearchService::class);
    $searchService
        ->shouldReceive('availableWorlds')
        ->once()
        ->andReturn(['Twintania', 'Lich']);
    $searchService
        ->shouldReceive('search')
        ->once()
        ->with('Sara', 'Twintania')
        ->andReturn([
            new \App\DTOs\LodestoneCharacterSearchResult(
                lodestoneId: '41337960',
                name: 'Sara Kiki',
                world: 'Twintania',
                dataCenter: 'Light',
                avatarUrl: 'https://example.com/avatar.png',
                profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/41337960/',
            ),
        ]);

    app()->instance(LodestoneCharacterSearchService::class, $searchService);

    $response = $this->getJson(route('groups.activities.application.search-characters', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'name' => 'Sara',
        'world' => 'Twintania',
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('data.0.lodestone_id', '41337960')
        ->assertJsonPath('data.0.name', 'Sara Kiki')
        ->assertJsonPath('data.0.world', 'Twintania')
        ->assertJsonPath('data.0.datacenter', 'Light');
});

it('filters verified characters out of guest search results', function () {
    $activity = createGuestApplicationActivity();

    Character::factory()->create([
        'lodestone_id' => '41337960',
        'name' => 'Sara Kiki',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);

    $searchService = Mockery::mock(LodestoneCharacterSearchService::class);
    $searchService
        ->shouldReceive('availableWorlds')
        ->once()
        ->andReturn(['Twintania', 'Lich']);
    $searchService
        ->shouldReceive('search')
        ->once()
        ->with('Sara', 'Twintania')
        ->andReturn([
            new \App\DTOs\LodestoneCharacterSearchResult(
                lodestoneId: '41337960',
                name: 'Sara Kiki',
                world: 'Twintania',
                dataCenter: 'Light',
                avatarUrl: 'https://example.com/avatar.png',
                profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/41337960/',
            ),
        ]);

    app()->instance(LodestoneCharacterSearchService::class, $searchService);

    $response = $this->getJson(route('groups.activities.application.search-characters', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'name' => 'Sara',
        'world' => 'Twintania',
    ]));

    $response
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('shows the guest application status page from its access token', function () {
    $activity = createGuestApplicationActivity();

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $application->answers()->delete();
    $application->answers()->create([
        'question_key' => 'experience',
        'question_label' => ['en' => 'Experience'],
        'question_type' => 'textarea',
        'source' => null,
        'value' => 'Cleared to enrage.',
    ]);

    $response = $this->get(route('groups.activities.application.status', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
            'accessToken' => $application->guest_access_token,
        ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/ApplicationConfirmation')
            ->where('confirmation.view', 'status')
            ->where('confirmation.mode', 'submitted')
            ->where('confirmation.can_edit', true)
            ->where('application.applicant_character.name', 'Warrior Light')
            ->where('application.answers.experience', 'Cleared to enrage.'));
});

it('shows the moderator decline reason on the guest status page', function () {
    $activity = createGuestApplicationActivity();

    $application = ActivityApplication::factory()->guest()->declined($activity->group->owner)->create([
        'activity_id' => $activity->id,
        'review_reason' => 'Roster is already full for this run.',
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $response = $this->get(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/ApplicationConfirmation')
            ->where('confirmation.view', 'status')
            ->where('confirmation.can_edit', false)
            ->where('application.status', ActivityApplication::STATUS_DECLINED)
            ->where('application.review_reason', 'Roster is already full for this run.'));
});

it('loads the guest application form for editing from its access token', function () {
    $activity = createGuestApplicationActivity();

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $application->answers()->delete();
    $application->answers()->create([
        'question_key' => 'experience',
        'question_label' => ['en' => 'Experience'],
        'question_type' => 'textarea',
        'source' => null,
        'value' => 'Cleared to enrage.',
    ]);

    $response = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('guestAccessToken', $application->guest_access_token)
            ->where('permissions.can_apply', false)
            ->where('permissions.can_apply_as_guest', true)
            ->where('application.applicant_character.name', 'Warrior Light')
            ->where('application.answers.experience', 'Cleared to enrage.'));
});

it('allows guests to update their application from the access token route', function () {
    $activity = createGuestApplicationActivity();

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Warrior Light',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $response = $this->put(route('groups.activities.application.update-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Lich',
            'datacenter' => 'Light',
            'avatar_url' => 'https://example.com/updated-avatar.png',
        ],
        'notes' => 'Updated notes.',
        'answers' => [
            'experience' => 'Reached clear.',
        ],
    ]);

    $response->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $application->refresh();
    $application->load('answers', 'selectedCharacter');

    expect($application->applicant_world)->toBe('Lich')
        ->and($application->applicant_avatar_url)->toBe('https://example.com/updated-avatar.png')
        ->and($application->notes)->toBe('Updated notes.')
        ->and($application->status)->toBe(ActivityApplication::STATUS_PENDING)
        ->and($application->selectedCharacter?->user_id)->toBeNull()
        ->and($application->selectedCharacter?->world)->toBe('Lich')
        ->and($application->answers->sole()->value)->toBe('Reached clear.');
});

it('does not allow guests to submit applications for verified characters', function () {
    $activity = createGuestApplicationActivity();

    Character::factory()->create([
        'lodestone_id' => '47431834',
        'name' => 'Warrior Light',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => 'Warrior Light',
            'world' => 'Twintania',
            'datacenter' => 'Light',
        ],
        'answers' => [
            'experience' => 'Cleared to enrage.',
        ],
    ]);

    $response
        ->assertSessionHasErrors(['guest_applicant.lodestone_id']);

    expect(ActivityApplication::query()->count())->toBe(0);
});

it('does not allow guests to edit applications once they are no longer pending', function () {
    $activity = createGuestApplicationActivity();

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $editResponse->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $updateResponse = $this->put(route('groups.activities.application.update-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => $application->applicant_lodestone_id,
            'name' => $application->applicant_character_name,
            'world' => $application->applicant_world,
            'datacenter' => $application->applicant_datacenter,
            'avatar_url' => $application->applicant_avatar_url,
        ],
        'answers' => [
            'experience' => 'Should not save.',
        ],
    ]);

    $updateResponse->assertForbidden();
});

it('does not allow authenticated users to update applications once they are no longer pending', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);

    $this->actingAs($user);

    $response = $this->put(route('groups.activities.application.update', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Should not save.',
        ],
    ]);

    $response->assertForbidden();
});

it('returns assigned guest applications back to the queue', function () {
    $activity = createGuestApplicationActivity();
    $owner = $activity->group->owner;

    $application = ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);
    $application->load('selectedCharacter');

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('application.id', $application->id)
        ->assertJsonPath('application.is_guest', true)
        ->assertJsonPath('application.status', ActivityApplication::STATUS_PENDING)
        ->assertJsonPath('application.applicant_character.name', $application->applicant_character_name);

    expect($slot->fresh()->assigned_character_id)->toBeNull();
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});
