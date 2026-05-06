<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAccessControlActivity(array $groupOverrides = [], array $activityOverrides = []): array
{
    $owner = User::factory()->create();
    $groupFactory = ($groupOverrides['is_public'] ?? true) ? Group::factory()->public() : Group::factory()->private();
    $group = $groupFactory->create(array_merge([
        'owner_id' => $owner->id,
    ], $groupOverrides));

    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
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
        'slot_schema' => [],
        'application_schema' => [
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
        ],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
        'prog_points' => [],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activityFactory = ($activityOverrides['is_public'] ?? true)
        ? Activity::factory()
        : Activity::factory()->private();

    $activity = $activityFactory->create(array_merge([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $activityOverrides));

    return compact('owner', 'group', 'activity');
}

it('redirects non members away from visible group dashboards', function () {
    extract(createAccessControlActivity());

    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('groups.dashboard', [
        'group' => $group->slug,
    ]));

    $response->assertRedirect(route('groups.show', $group));
});

it('returns not found for non member writes to dashboard endpoints', function () {
    extract(createAccessControlActivity());

    $outsider = User::factory()->create();
    $slot = $activity->slots()->firstOrFail();
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
    ]);
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_APPROVED,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($outsider);

    $response = $this->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]));

    $response->assertNotFound();
    expect($slot->fresh()->assigned_character_id)->toBe($character->id);
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_APPROVED);
});

it('does not expose hidden group profiles to outsiders', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->hidden()->create([
        'owner_id' => $owner->id,
    ]);

    $response = $this->get(route('groups.show', $group));

    $response->assertNotFound();
});

it('requires membership to view public activities that belong to private groups', function () {
    extract(createAccessControlActivity([
        'is_public' => false,
    ], [
        'is_public' => true,
    ]));

    $response = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertNotFound();
});

it('requires the correct secret key to access private activity overview pages', function () {
    extract(createAccessControlActivity([], [
        'is_public' => false,
    ]));

    $withoutKey = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));
    $wrongKey = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'secretKey' => str_repeat('a', 40),
    ]));
    $correctKey = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'secretKey' => $activity->secret_key,
    ]));

    $withoutKey->assertNotFound();
    $wrongKey->assertNotFound();
    $correctKey->assertOk();
});

it('does not let guest application tokens bypass private activity secret access', function () {
    extract(createAccessControlActivity([], [
        'is_public' => false,
    ]));

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
    ]);

    $withoutKey = $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]));
    $wrongKey = $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
        'secretKey' => str_repeat('b', 40),
    ]));
    $correctKey = $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
        'secretKey' => $activity->secret_key,
    ]));

    $withoutKey->assertNotFound();
    $wrongKey->assertNotFound();
    $correctKey->assertOk();
});

it('does not allow guest application tokens to be reused on another activity', function () {
    extract(createAccessControlActivity());
    $secondSetup = createAccessControlActivity();

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
    ]);

    $response = $this->get(route('groups.activities.application.status', [
        'group' => $secondSetup['group']->slug,
        'activity' => $secondSetup['activity']->id,
        'accessToken' => $application->guest_access_token,
    ]));

    $response->assertNotFound();
});

it('returns forbidden when a regular member requests moderator management data', function () {
    extract(createAccessControlActivity());

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member);

    $response = $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertForbidden();
});

it('returns forbidden when a regular member tries to decline an application', function () {
    extract(createAccessControlActivity());

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($member);

    $response = $this->postJson(route('groups.dashboard.activities.application-declines.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]), [
        'reason' => 'No access.',
    ]);

    $response->assertForbidden();
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('returns not found for cross group dashboard activity access attempts', function () {
    $first = createAccessControlActivity();
    $second = createAccessControlActivity();

    $this->actingAs($first['owner']);

    $response = $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $first['group']->slug,
        'activity' => $second['activity']->id,
    ]));

    $response->assertNotFound();
});

it('does not expose fflogs progress for characters that are not part of the activity applications', function () {
    extract(createAccessControlActivity());

    $character = Character::factory()->create([
        'user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.fflogs-progress', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'character' => $character->id,
    ]));

    $response->assertNotFound();
});

it('does not allow users to withdraw another users application', function () {
    $activity = createAccessControlActivity()['activity'];
    $owner = $activity->group->owner;
    $applicant = User::factory()->create();
    $otherUser = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
    ]);

    $this->actingAs($otherUser);

    $response = $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]));

    $response->assertNotFound();
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('does not allow users to refresh or change primary status for another users character', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $this->actingAs($intruder);

    $refreshResponse = $this->post(route('characters.refresh', $character));
    $primaryResponse = $this->post(route('characters.make-primary', $character));

    $refreshResponse->assertForbidden();
    $primaryResponse->assertForbidden();
});
