<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createApplicantQueueActivity(): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'Warrior',
        'shorthand' => 'WAR',
        'role' => 'tank',
    ]);
    $phantomJob = PhantomJob::create([
        'name' => 'Phantom Warrior',
        'max_level' => 20,
    ]);

    $activityType = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $activityType->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Character Class'],
                'type' => 'single_select',
                'source' => 'character_classes',
            ],
        ],
        'application_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Can Play'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'character_classes',
            ],
        ],
        'progress_schema' => ['milestones' => []],
        'prog_points' => [],
    ]);

    $activityType->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
    ]);

    return compact('owner', 'group', 'activity', 'characterClass', 'phantomJob');
}

function createQueueApplication(Activity $activity, CharacterClass $characterClass, array $overrides = []): ActivityApplication
{
    $userId = $overrides['user_id'] ?? null;
    $selectedCharacterId = $overrides['selected_character_id'] ?? null;

    if ($userId === null && $selectedCharacterId === null) {
        return ActivityApplication::factory()->guest()->create(array_merge([
            'activity_id' => $activity->id,
        ], $overrides));
    }

    $user = User::query()->findOrFail($userId);
    $character = Character::query()->find($selectedCharacterId);

    if (! $character) {
        $character = Character::factory()->primary()->create([
            'user_id' => $user->id,
        ]);
    }

    if (! $character->classes()->exists()) {
        $character->classes()->attach($characterClass->id, [
            'level' => 100,
            'is_preferred' => true,
        ]);
    }

    return ActivityApplication::factory()->create(array_merge([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ], $overrides));
}

it('returns only pending applications in the applicant queue payload and includes guest applicant data', function () {
    extract(createApplicantQueueActivity());

    $member = User::factory()->create();
    createQueueApplication($activity, $characterClass, [
        'user_id' => $member->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    createQueueApplication($activity, $characterClass, [
        'user_id' => User::factory()->create()->id,
        'status' => ActivityApplication::STATUS_APPROVED,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);

    $guestApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_character_name' => 'Guest Tank',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('pending_application_count', 2)
        ->assertJsonCount(2, 'applications');

    $guestQueueItem = collect($response->json('applications'))
        ->firstWhere('id', $guestApplication->id);

    expect($guestQueueItem)->not->toBeNull();
    expect($guestQueueItem['is_guest'])->toBeTrue();
    expect($guestQueueItem['user'])->toBeNull();
    expect($guestQueueItem['applicant_character']['name'])->toBe('Guest Tank');
    expect($guestQueueItem['applicant_character']['is_claimed'])->toBeFalse();
});

it('keeps the original queue position and exposes the application edit time', function () {
    extract(createApplicantQueueActivity());

    $submittedAt = now()->subHours(2)->startOfSecond();
    $editedAt = now()->subHour()->startOfSecond();
    $application = createQueueApplication($activity, $characterClass, [
        'created_at' => $submittedAt,
        'updated_at' => $editedAt,
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $queueItem = collect($response->json('applications'))->firstWhere('id', $application->id);

    expect($queueItem['submitted_at'])->toBe($submittedAt->toIso8601String())
        ->and($queueItem['edited_at'])->toBe($editedAt->toIso8601String());
});

it('does not show an edit time when submission and update are within the same minute', function () {
    extract(createApplicantQueueActivity());

    $submittedAt = now()->startOfMinute()->addSeconds(5);
    $application = createQueueApplication($activity, $characterClass, [
        'created_at' => $submittedAt,
        'updated_at' => $submittedAt->copy()->addSeconds(20),
    ]);

    $response = $this->actingAs($owner)->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $queueItem = collect($response->json('applications'))->firstWhere('id', $application->id);

    expect($queueItem['edited_at'])->toBeNull();
});

it('includes selected character preferred class and phantom job ids in applicant queue payloads', function () {
    extract(createApplicantQueueActivity());

    $member = User::factory()->create();
    $otherClass = CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
    ]);
    $otherPhantomJob = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
    ]);
    $character->classes()->attach($characterClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->classes()->attach($otherClass->id, [
        'level' => 100,
        'is_preferred' => false,
    ]);
    $character->phantomJobs()->attach($phantomJob->id, [
        'current_level' => 20,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($otherPhantomJob->id, [
        'current_level' => 20,
        'is_preferred' => false,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $member->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $response = $this->actingAs($owner)->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertOk();

    $queueItem = collect($response->json('applications'))
        ->firstWhere('id', $application->id);

    expect($queueItem)->not->toBeNull()
        ->and($queueItem['selected_character']['preferred_character_class_ids'])->toBe([(string) $characterClass->id])
        ->and($queueItem['selected_character']['preferred_phantom_job_ids'])->toBe([(string) $phantomJob->id])
        ->and($queueItem['selected_character']['available_character_classes'])->toContain(
            ['id' => (string) $characterClass->id, 'level' => 100],
            ['id' => (string) $otherClass->id, 'level' => 100],
        )
        ->and($queueItem['selected_character']['available_phantom_jobs'])->toContain(
            [
                'id' => (string) $phantomJob->id,
                'current_level' => 20,
                'max_level' => 20,
                'is_maxed' => true,
            ],
            [
                'id' => (string) $otherPhantomJob->id,
                'current_level' => 20,
                'max_level' => 20,
                'is_maxed' => true,
            ],
        );
});

it('forbids non moderators from loading the applicant queue payload', function () {
    extract(createApplicantQueueActivity());

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member);

    $response = $this->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertForbidden();
});

it('includes application-only any options in queue filters without making them slot options', function () {
    extract(createApplicantQueueActivity());

    $version = ActivityTypeVersion::query()->findOrFail($activity->activity_type_version_id);
    $slotRaidPositionOptions = [
        ['key' => 'mt', 'label' => ['en' => 'Main Tank']],
        ['key' => 'ot', 'label' => ['en' => 'Off Tank']],
    ];

    $version->update([
        'slot_schema' => [
            ...$version->slot_schema,
            [
                'key' => 'raid_position',
                'label' => ['en' => 'Raid Position'],
                'type' => 'single_select',
                'source' => 'static_options',
                'options' => $slotRaidPositionOptions,
            ],
        ],
        'application_schema' => [
            ...$version->application_schema,
            [
                'key' => 'preferred_raid_positions',
                'label' => ['en' => 'Preferred Raid Positions'],
                'type' => 'multi_select',
                'source' => 'static_options',
                'options' => $slotRaidPositionOptions,
                'accepts_any' => true,
                'any_label' => ['en' => 'Put Me Anywhere Coach'],
            ],
        ],
    ]);

    $anyApplication = createQueueApplication($activity, $characterClass, [
        'status' => ActivityApplication::STATUS_PENDING,
    ]);
    $anyApplication->answers()->updateOrCreate(
        ['question_key' => 'preferred_raid_positions'],
        [
            'question_label' => ['en' => 'Preferred Raid Positions'],
            'question_type' => 'multi_select',
            'source' => 'static_options',
            'value' => ['any'],
        ],
    );

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.applicant-queue', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertOk();

    $raidPositionFilter = collect($response->json('queue_filters.slot_fields'))
        ->firstWhere('key', 'raid_position');

    expect($raidPositionFilter)->not->toBeNull()
        ->and(collect($raidPositionFilter['options'])->pluck('key')->all())->toBe(['mt', 'ot'])
        ->and(collect($raidPositionFilter['filter_options'])->pluck('key')->all())->toBe(['mt', 'ot', 'any']);
});

it('refreshes an application character for moderators and returns the updated queue item', function () {
    extract(createApplicantQueueActivity());

    $member = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Old Applicant',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'lodestone_refreshed_at' => now()->subMinutes(10),
    ]);
    $application = createQueueApplication($activity, $characterClass, [
        'user_id' => $member->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $availableAt = now()->addMinutes(5);
    $refreshService = Mockery::mock(ActivityApplicationCharacterRefreshService::class);
    $refreshService
        ->shouldReceive('refreshSelectedCharacterIfDue')
        ->once()
        ->withArgs(fn (ActivityApplication $refreshedApplication, int $cooldownSeconds): bool => $refreshedApplication->is($application)
            && $cooldownSeconds === 300)
        ->andReturnUsing(function (ActivityApplication $refreshedApplication) use ($character, $availableAt): array {
            $character->update([
                'name' => 'Fresh Applicant',
                'world' => 'Twintania',
                'datacenter' => 'Light',
                'lodestone_refreshed_at' => now(),
            ]);
            $refreshedApplication->update([
                'applicant_character_name' => 'Fresh Applicant',
                'applicant_world' => 'Twintania',
                'applicant_datacenter' => 'Light',
            ]);

            return [
                'refreshed' => true,
                'available_at' => $availableAt,
                'character' => $character->fresh(),
                'fflogs_error' => null,
            ];
        });

    app()->instance(ActivityApplicationCharacterRefreshService::class, $refreshService);

    $response = $this->actingAs($owner)->postJson(route('groups.dashboard.activities.applicant-queue.application-character-refresh', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('application.id', $application->id)
        ->assertJsonPath('application.selected_character.name', 'Fresh Applicant')
        ->assertJsonPath('application.selected_character.world', 'Twintania')
        ->assertJsonPath('refresh_available_at', $availableAt->toIso8601String());

    expect($application->fresh()->applicant_character_name)->toBe('Fresh Applicant');
});

it('prevents moderator application character refreshes during the cooldown window', function () {
    extract(createApplicantQueueActivity());

    $member = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'lodestone_refreshed_at' => now(),
    ]);
    $application = createQueueApplication($activity, $characterClass, [
        'user_id' => $member->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $availableAt = now()->addMinutes(4);
    $refreshService = Mockery::mock(ActivityApplicationCharacterRefreshService::class);
    $refreshService
        ->shouldReceive('refreshSelectedCharacterIfDue')
        ->once()
        ->withArgs(fn (ActivityApplication $refreshedApplication, int $cooldownSeconds): bool => $refreshedApplication->is($application)
            && $cooldownSeconds === 300)
        ->andReturn([
            'refreshed' => false,
            'available_at' => $availableAt,
            'character' => $character,
            'fflogs_error' => null,
        ]);

    app()->instance(ActivityApplicationCharacterRefreshService::class, $refreshService);

    $this->actingAs($owner)->postJson(route('groups.dashboard.activities.applicant-queue.application-character-refresh', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]))
        ->assertStatus(429)
        ->assertJsonPath('refresh_available_at', $availableAt->toIso8601String());
});
