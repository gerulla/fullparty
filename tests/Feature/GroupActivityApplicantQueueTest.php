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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createApplicantQueueActivity(): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
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
        'status' => Activity::STATUS_PLANNED,
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

    if (!$character) {
        $character = Character::factory()->primary()->create([
            'user_id' => $user->id,
        ]);
    }

    if (!$character->classes()->exists()) {
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
