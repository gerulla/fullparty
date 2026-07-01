<?php

use App\DTOs\LodestoneCharacterSearchResult;
use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityApplicationAnswer;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use App\Models\User;
use App\Models\UserActivityApplicationDefault;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createGuestApplicationActivity(array $activityOverrides = []): Activity
{
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
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
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $activityOverrides));
}

it('allows guests to submit applications when enabled', function () {
    $activity = createGuestApplicationActivity();

    Event::fake([ActivityManagementUpdated::class]);

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

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => $event->groupId === $activity->group_id
            && $event->activityId === $activity->id
            && $event->patch['pending_application_count'] === 1
            && $event->patch['queue_invalidate'] === true
            && $event->patch['queue_change_reason'] === 'application_created'
            && $event->patch['queue_new_application_count'] === 1
            && $event->patch['queue_new_application_ids'] === [$application->id]
    );
});

it('allows authenticated users to submit applications after the roster is published but before the run starts', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->addHours(2),
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->post(route('groups.activities.application.store', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]), [
            'selected_character_id' => $character->id,
            'answers' => [
                'experience' => 'Late applicant, still available.',
            ],
        ]);

    $application = ActivityApplication::query()->sole();

    $response->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->user_id)->toBe($user->id)
        ->and($application->selected_character_id)->toBe($character->id)
        ->and($application->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('does not allow new applications after the run start time has passed', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->subMinute(),
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
            'experience' => 'Too late.',
        ],
    ]);

    $response->assertForbidden();

    expect(ActivityApplication::query()->count())->toBe(0);
});

it('sanitizes guest applicant free-text fields, notes, and textarea answers', function () {
    $activity = createGuestApplicationActivity();
    $sanitizer = app(TextInputSanitizer::class);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'guest_applicant' => [
            'lodestone_id' => '47431834',
            'name' => "  War\u{200B}rior   Light  ",
            'world' => "  Twin\tania  ",
            'datacenter' => "  Li\u{200B}ght  ",
            'avatar_url' => 'https://example.com/avatar.png',
        ],
        'notes' => " Can\t flex \r\n healer ",
        'answers' => [
            'experience' => " Cleared\u{200B}\r\nto enrage ",
        ],
    ]);

    $application = ActivityApplication::query()->sole();
    $application->load('answers', 'selectedCharacter');

    $response->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    expect($application->applicant_character_name)->toBe($sanitizer->sanitizeSingleLine("  War\u{200B}rior   Light  "))
        ->and($application->applicant_world)->toBe($sanitizer->sanitizeSingleLine("  Twin\tania  "))
        ->and($application->applicant_datacenter)->toBe($sanitizer->sanitizeSingleLine("  Li\u{200B}ght  "))
        ->and($application->notes)->toBe($sanitizer->sanitizeMultiline(" Can\t flex \r\n healer "))
        ->and($application->answers->sole()->value)->toBe($sanitizer->sanitizeMultiline(" Cleared\u{200B}\r\nto enrage "))
        ->and($application->selectedCharacter?->name)->toBe($sanitizer->sanitizeSingleLine("  War\u{200B}rior   Light  "));
});

it('rejects guest application notes that exceed the configured limit', function () {
    $activity = createGuestApplicationActivity();

    $this->post(route('groups.activities.application.store', [
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
        'notes' => str_repeat('n', ActivityApplication::NOTES_MAX_LENGTH + 1),
        'answers' => [
            'experience' => 'Cleared to enrage.',
        ],
    ])->assertSessionHasErrors(['notes']);

    expect(ActivityApplication::query()->count())->toBe(0);
});

it('rejects guest application textarea answers that exceed the configured limit', function () {
    $activity = createGuestApplicationActivity();

    $this->post(route('groups.activities.application.store', [
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
            'experience' => str_repeat('e', ActivityApplicationAnswer::TEXTAREA_VALUE_MAX_LENGTH + 1),
        ],
    ])->assertSessionHasErrors(['answers.experience']);

    expect(ActivityApplication::query()->count())->toBe(0);
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

it('sends preferred class and phantom job ids for authenticated application characters', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $activity->activityTypeVersion->update([
        'application_schema' => [
            [
                'key' => 'jobs',
                'label' => ['en' => 'Jobs'],
                'type' => 'multi_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_jobs',
                'label' => ['en' => 'Phantom Jobs'],
                'type' => 'multi_select',
                'source' => 'phantom_jobs',
            ],
        ],
    ]);

    $whiteMage = CharacterClass::query()->create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'icon_url' => 'https://example.com/whm.png',
        'flaticon_url' => 'https://example.com/whm-flat.png',
        'role' => 'healer',
    ]);
    $samurai = CharacterClass::query()->create([
        'name' => 'Samurai',
        'shorthand' => 'SAM',
        'icon_url' => 'https://example.com/sam.png',
        'flaticon_url' => 'https://example.com/sam-flat.png',
        'role' => 'melee dps',
    ]);
    $phantomBard = PhantomJob::query()->create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
        'icon_url' => 'https://example.com/phantom-bard.png',
    ]);
    $phantomThief = PhantomJob::query()->create([
        'name' => 'Phantom Thief',
        'max_level' => 20,
        'icon_url' => 'https://example.com/phantom-thief.png',
    ]);

    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Favourite Tester',
    ]);
    $character->classes()->attach($whiteMage->id, ['level' => 100, 'is_preferred' => true]);
    $character->classes()->attach($samurai->id, ['level' => 100, 'is_preferred' => false]);
    $character->phantomJobs()->attach($phantomBard->id, ['current_level' => 20, 'is_preferred' => true]);
    $character->phantomJobs()->attach($phantomThief->id, ['current_level' => 20, 'is_preferred' => false]);

    $this->actingAs($user);

    $this->get(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('characters.0.id', $character->id)
            ->where('characters.0.preferred_character_class_ids', [(string) $whiteMage->id])
            ->where('characters.0.preferred_phantom_job_ids', [(string) $phantomBard->id])
        );
});

it('adds schema-defined any choices to application question options', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $characterClass = CharacterClass::query()->create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);
    $activity->activityTypeVersion->update([
        'application_schema' => [
            [
                'key' => 'preferred_classes',
                'label' => ['en' => 'Preferred Classes'],
                'type' => 'multi_select',
                'source' => 'character_classes',
                'required' => true,
                'accepts_any' => true,
                'any_label' => ['en' => 'Put Me Anywhere Coach'],
            ],
        ],
    ]);
    $user = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    $this->get(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('applicationSchema.0.key', 'preferred_classes')
            ->where('applicationSchema.0.accepts_any', true)
            ->where('applicationSchema.0.options.0.key', (string) $characterClass->id)
            ->where('applicationSchema.0.options.1.key', 'any')
            ->where('applicationSchema.0.options.1.label.en', 'Put Me Anywhere Coach')
        );
});

it('resolves shared raid position choices for application questions', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    RaidPosition::query()->create([
        'key' => 'mt',
        'name' => 'Main Tank',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    RaidPosition::query()->create([
        'key' => 'ot',
        'name' => 'Off Tank',
        'sort_order' => 20,
        'is_active' => true,
    ]);
    $activity->activityTypeVersion->update([
        'application_schema' => [
            [
                'key' => 'preferred_raid_positions',
                'label' => ['en' => 'Preferred Raid Positions'],
                'type' => 'multi_select',
                'source' => 'raid_positions',
                'accepts_any' => true,
                'any_label' => ['en' => 'Put Me Anywhere Coach'],
            ],
        ],
    ]);
    $user = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    $this->get(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('applicationSchema.0.key', 'preferred_raid_positions')
            ->where('applicationSchema.0.options.0.key', 'mt')
            ->where('applicationSchema.0.options.0.label.en', 'Main Tank')
            ->where('applicationSchema.0.options.1.key', 'ot')
            ->where('applicationSchema.0.options.2.key', 'any')
            ->where('applicationSchema.0.options.2.label.en', 'Put Me Anywhere Coach')
        );
});

it('stores remembered application defaults for authenticated users on create by default', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '55112233',
    ]);

    $this->actingAs($user);

    $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'notes' => 'Remember this exact note.',
        'answers' => [
            'experience' => 'Remembered experience text.',
        ],
        'remember_application_defaults' => true,
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    $defaults = UserActivityApplicationDefault::query()->sole();

    expect($defaults->user_id)->toBe($user->id)
        ->and($defaults->activity_type_id)->toBe($activity->activity_type_id)
        ->and($defaults->selected_character_id)->toBe($character->id)
        ->and($defaults->notes)->toBe('Remember this exact note.')
        ->and($defaults->answers)->toBe([
            'experience' => 'Remembered experience text.',
        ]);
});

it('does not store remembered application defaults when the user opts out', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '55112234',
    ]);

    $this->actingAs($user);

    $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'notes' => 'Do not remember this.',
        'answers' => [
            'experience' => 'Skip saving defaults for this one.',
        ],
        'remember_application_defaults' => false,
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect(UserActivityApplicationDefault::query()->count())->toBe(0);
});

it('prefills new authenticated applications from remembered defaults for the same activity type', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $secondActivity = Activity::factory()->create([
        'group_id' => $activity->group_id,
        'activity_type_id' => $activity->activity_type_id,
        'activity_type_version_id' => $activity->activity_type_version_id,
        'organized_by_user_id' => $activity->organized_by_user_id,
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'is_public' => true,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '55112235',
    ]);

    UserActivityApplicationDefault::query()->forceCreate([
        'user_id' => $user->id,
        'activity_type_id' => $activity->activity_type_id,
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Use this remembered answer.',
            'missing_question' => 'Should be ignored.',
        ],
        'notes' => 'Use these remembered notes.',
    ]);

    $this->actingAs($user);

    $this->get(route('groups.activities.application', [
        'group' => $secondActivity->group->slug,
        'activity' => $secondActivity->id,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('rememberedApplicationDefaults.selected_character_id', $character->id)
            ->where('rememberedApplicationDefaults.notes', 'Use these remembered notes.')
            ->where('rememberedApplicationDefaults.answers.experience', 'Use this remembered answer.')
            ->missing('rememberedApplicationDefaults.answers.missing_question')
        );
});

it('filters stale remembered application defaults before sending them to the new form', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
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
                'key' => 'jobs',
                'label' => ['en' => 'Jobs'],
                'type' => 'multi_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'is_public' => true,
    ]);

    $whiteMage = CharacterClass::query()->create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'icon_url' => 'https://example.com/whm.png',
        'flaticon_url' => 'https://example.com/whm-flat.png',
        'role' => 'healer',
    ]);

    $user = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '55112236',
    ]);
    $otherCharacter = Character::factory()->primary()->create([
        'user_id' => User::factory()->create()->id,
        'lodestone_id' => '55112237',
    ]);

    UserActivityApplicationDefault::query()->forceCreate([
        'user_id' => $user->id,
        'activity_type_id' => $type->id,
        'selected_character_id' => $otherCharacter->id,
        'answers' => [
            'jobs' => [(string) $whiteMage->id, '999999'],
            'experience' => str_repeat('x', ActivityApplicationAnswer::TEXTAREA_VALUE_MAX_LENGTH + 1),
        ],
        'notes' => str_repeat('n', ActivityApplication::NOTES_MAX_LENGTH + 1),
    ]);

    $this->actingAs($user);

    $this->get(route('groups.activities.application', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('rememberedApplicationDefaults.selected_character_id', null)
            ->where('rememberedApplicationDefaults.answers.jobs', [(string) $whiteMage->id])
            ->where('rememberedApplicationDefaults.notes', null)
            ->missing('rememberedApplicationDefaults.answers.experience')
        );
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

it('redirects authenticated duplicate submissions to the existing application confirmation', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '22446688',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $response = $this->actingAs($user)->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Accidental second tap.',
        ],
    ]);

    $response
        ->assertRedirect(route('groups.activities.application.confirmation', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]))
        ->assertSessionHas("activities.{$activity->id}.application_confirmation", [
            'application_id' => $application->id,
            'mode' => 'submitted',
        ]);

    expect(ActivityApplication::query()->where('activity_id', $activity->id)->count())->toBe(1);
});

it('checks a signed-in applicants selected character through the lodestone cooldown refresh path', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_refreshed_at' => now()->subHours(2),
    ]);

    $refreshService = Mockery::mock(ActivityApplicationCharacterRefreshService::class);
    $refreshService
        ->shouldReceive('refreshSelectedCharacterIfDue')
        ->once()
        ->withArgs(fn (ActivityApplication $application, int $cooldownSeconds): bool => (int) $application->selected_character_id === (int) $character->id
            && $cooldownSeconds === 3600)
        ->andReturn([
            'refreshed' => true,
            'available_at' => now()->addHour(),
            'character' => $character,
            'fflogs_error' => null,
        ]);

    app()->instance(ActivityApplicationCharacterRefreshService::class, $refreshService);

    $this->actingAs($user)->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Ready to go.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));
});

it('does not overwrite remembered application defaults when an authenticated user edits an existing application', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'lodestone_id' => '55112238',
    ]);

    UserActivityApplicationDefault::query()->forceCreate([
        'user_id' => $user->id,
        'activity_type_id' => $activity->activity_type_id,
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Original remembered value.',
        ],
        'notes' => 'Original remembered notes.',
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($user);

    $this->put(route('groups.activities.application.update', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'notes' => 'Edited application notes.',
        'answers' => [
            'experience' => 'Edited application answer.',
        ],
        'remember_application_defaults' => true,
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    $defaults = UserActivityApplicationDefault::query()->sole();

    expect($defaults->notes)->toBe('Original remembered notes.')
        ->and($defaults->answers)->toBe([
            'experience' => 'Original remembered value.',
        ]);
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
            new LodestoneCharacterSearchResult(
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
            new LodestoneCharacterSearchResult(
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

it('does not expose declined guest applications from their old access token', function () {
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

    $response->assertNotFound();
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

    Event::fake([ActivityManagementUpdated::class]);

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

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => $event->groupId === $activity->group_id
            && $event->activityId === $activity->id
            && $event->patch['queue_change_reason'] === 'application_updated'
            && $event->patch['queue_updated_application_names'] === ['Warrior Light']
    );
});

it('allows guests to edit approved applications when they are not assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_SCHEDULED,
    ]);

    $application = ActivityApplication::factory()->guest()->approved($activity->group->owner)->create([
        'activity_id' => $activity->id,
    ]);

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $editResponse->assertOk();

    $statusResponse = $this->get(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $statusResponse
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/ApplicationConfirmation')
            ->where('confirmation.can_edit', true)
            ->where('confirmation.can_withdraw', true)
        );

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
            'experience' => 'Updated before publish.',
        ],
    ]);

    $updateResponse->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('broadcasts pending guest application withdrawals to the roster queue', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_SCHEDULED,
    ]);

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_character_name' => 'Warrior Light',
    ]);
    $accessToken = $application->guest_access_token;

    Event::fake([ActivityManagementUpdated::class]);

    $response = $this->delete(route('groups.activities.application.destroy-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $accessToken,
    ]));

    $response->assertRedirect(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => $event->groupId === $activity->group_id
            && $event->activityId === $activity->id
            && $event->patch['queue_change_reason'] === 'application_withdrawn'
            && $event->patch['queue_application_remove_ids'] === [$application->id]
            && $event->patch['queue_withdrawn_application_names'] === ['Warrior Light']
    );
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

it('allows guests to edit pending applications after the roster is published when they are not assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $editResponse->assertOk();

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
            'experience' => 'Updated after publish.',
        ],
    ]);

    $updateResponse->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    expect($application->fresh()->answers()->where('question_key', 'experience')->value('value'))->toBe('Updated after publish.');
});

it('does not allow guests to edit applications assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);
    $application->load('selectedCharacter');

    $activity->slots()->firstOrFail()->update([
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $activity->group->owner_id,
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

it('allows guests to edit applications assigned to the bench', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
    ]);
    $application->load('selectedCharacter');

    ActivitySlot::factory()->create([
        'activity_id' => $activity->id,
        'group_key' => ActivitySlotBench::GROUP_KEY,
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 999,
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $editResponse->assertOk();

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
            'experience' => 'Bench edit still allowed.',
        ],
    ]);

    $updateResponse->assertRedirect(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));

    expect($application->fresh()->answers()->where('question_key', 'experience')->value('value'))->toBe('Bench edit still allowed.');
});

it('allows authenticated users to update applications after the roster is published when they are not assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
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
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($user);

    $response = $this->put(route('groups.activities.application.update', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Updated after publish.',
        ],
    ]);

    $response->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->fresh()->answers()->where('question_key', 'experience')->value('value'))->toBe('Updated after publish.');
});

it('allows authenticated users to edit approved applications when they are not assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_SCHEDULED,
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->approved($activity->group->owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($user);

    $pageResponse = $this->get(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    $pageResponse
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_edit_application', true)
            ->where('permissions.can_withdraw_application', true)
        );

    $response = $this->put(route('groups.activities.application.update', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Approved application updated.',
        ],
    ]);

    $response->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING)
        ->and($application->fresh()->answers()->where('question_key', 'experience')->value('value'))->toBe('Approved application updated.');
});

it('does not allow authenticated users to update applications assigned to the main roster', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $activity->slots()->firstOrFail()->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $activity->group->owner_id,
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

it('allows authenticated users to update applications assigned to the bench', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_ASSIGNED,
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
        'status' => ActivityApplication::STATUS_ON_BENCH,
    ]);

    ActivitySlot::factory()->create([
        'activity_id' => $activity->id,
        'group_key' => ActivitySlotBench::GROUP_KEY,
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 999,
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $this->actingAs($user);

    $response = $this->put(route('groups.activities.application.update', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Bench edit still allowed.',
        ],
    ]);

    $response->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->fresh()->answers()->where('question_key', 'experience')->value('value'))->toBe('Bench edit still allowed.');
});

it('does not allow authenticated users to apply with a character already assigned to the run', function () {
    $activity = createGuestApplicationActivity([
        'allow_guest_applications' => false,
    ]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $activity->slots()->firstOrFail()->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $this->actingAs($user);

    $response = $this->post(route('groups.activities.application.store', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Trying to apply while already assigned.',
        ],
    ]);

    $response->assertSessionHasErrors(['selected_character_id']);
    expect(ActivityApplication::query()->count())->toBe(0);
});

it('allows guests to withdraw approved applications and clears their assigned slot', function () {
    $activity = createGuestApplicationActivity([
        'status' => Activity::STATUS_SCHEDULED,
    ]);

    $application = ActivityApplication::factory()->guest()->approved($activity->group->owner)->create([
        'activity_id' => $activity->id,
    ]);
    $application->load('selectedCharacter');

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $accessToken = $application->guest_access_token;

    $response = $this->delete(route('groups.activities.application.destroy-guest', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $accessToken,
    ]));

    $response->assertRedirect(route('groups.activities.application', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($application->fresh()->guest_access_token)->toBeNull()
        ->and($slot->fresh()->assigned_character_id)->toBeNull();

    $this->get(route('groups.activities.application.status', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
        'accessToken' => $accessToken,
    ]))->assertNotFound();
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
