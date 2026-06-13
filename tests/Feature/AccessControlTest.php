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
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createAccessControlActivity(array $groupOverrides = [], array $activityOverrides = []): array
{
    $owner = User::factory()->create();
    $groupFactory = match ($groupOverrides['join_mode'] ?? Group::JOIN_MODE_OPEN) {
        Group::JOIN_MODE_APPLICATION => Group::factory()->applicationBased(),
        Group::JOIN_MODE_INVITE_ONLY => Group::factory()->inviteOnly(),
        default => Group::factory()->open(),
    };
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
        'status' => Activity::STATUS_SCHEDULED,
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

    $response->assertRedirect(route('groups.index'));
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

it('requires membership to view public activities that belong to hidden groups', function () {
    extract(createAccessControlActivity([
        'is_visible' => false,
    ], [
        'is_public' => true,
    ]));

    $response = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertNotFound();
});

it('only exposes draft activity overviews to moderators', function () {
    extract(createAccessControlActivity([], [
        'status' => Activity::STATUS_DRAFT,
        'is_public' => true,
    ]));

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $guestResponse = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $memberResponse = $this
        ->actingAs($member)
        ->get(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $ownerResponse = $this
        ->actingAs($owner)
        ->get(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $guestResponse->assertNotFound();
    $memberResponse->assertNotFound();
    $ownerResponse->assertOk();
});

it('keeps complete and cancelled public activity overviews visible', function (string $status) {
    extract(createAccessControlActivity([], [
        'status' => $status,
        'is_public' => true,
    ]));

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertOk();

    $this->actingAs($member)
        ->get(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))->assertOk();
})->with([
    'complete' => [Activity::STATUS_COMPLETE],
    'cancelled' => [Activity::STATUS_CANCELLED],
]);

it('lets non members view members-only application pages without submitting', function () {
    extract(createAccessControlActivity([], [
        'is_public' => false,
    ]));

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertOk();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', false)
            ->where('permissions.requires_group_membership', true)
            ->where('permissions.can_join_group', true)
        );

    $this->actingAs($outsider)
        ->post(route('groups.activities.application.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertNotFound();

    $this->actingAs($member)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk();
});

it('lets group owners apply to members-only runs even without a membership row', function () {
    extract(createAccessControlActivity([], [
        'is_public' => false,
    ]));

    $group->memberships()->where('user_id', $owner->id)->delete();
    $group->unsetRelation('memberships');

    expect($group->memberships()->where('user_id', $owner->id)->exists())->toBeFalse();

    $this->actingAs($owner)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', true)
            ->where('permissions.requires_group_membership', false)
            ->where('permissions.can_join_group', false)
        );
});

it('lets owners apply to public application runs in hidden invite-only static groups', function () {
    extract(createAccessControlActivity([
        'group_type' => Group::TYPE_STATIC,
        'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
        'is_visible' => false,
    ], [
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'status' => Activity::STATUS_SCHEDULED,
    ]));

    $group->memberships()->where('user_id', $owner->id)->delete();
    $group->unsetRelation('memberships');

    $this->actingAs($owner)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', true)
            ->where('permissions.requires_group_membership', false)
            ->where('permissions.can_join_group', false)
        );
});

it('lets run organizers apply to public application runs in hidden invite-only static groups', function () {
    extract(createAccessControlActivity([
        'group_type' => Group::TYPE_STATIC,
        'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
        'is_visible' => false,
    ], [
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'status' => Activity::STATUS_SCHEDULED,
    ]));

    $organizer = User::factory()->create();
    $organizerCharacter = Character::factory()->primary()->create([
        'user_id' => $organizer->id,
    ]);
    $activity->forceFill([
        'organized_by_user_id' => $organizer->id,
        'organized_by_character_id' => $organizerCharacter->id,
    ])->save();
    $group->unsetRelation('memberships');

    $this->actingAs($organizer)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', true)
            ->where('permissions.requires_group_membership', false)
            ->where('permissions.can_join_group', false)
        );
});

it('lets owners apply to draft application runs in hidden invite-only static groups', function () {
    extract(createAccessControlActivity([
        'group_type' => Group::TYPE_STATIC,
        'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
        'is_visible' => false,
    ], [
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'status' => Activity::STATUS_DRAFT,
    ]));

    $this->actingAs($owner)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', true)
            ->where('permissions.requires_group_membership', false)
            ->where('permissions.can_join_group', false)
        );
});

it('points non members toward group applications for application based groups', function () {
    extract(createAccessControlActivity([
        'join_mode' => Group::JOIN_MODE_APPLICATION,
    ], [
        'is_public' => false,
    ]));

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('groups.activities.application', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Application')
            ->where('permissions.can_apply', false)
            ->where('permissions.requires_group_membership', true)
            ->where('permissions.can_join_group', false)
            ->where('permissions.can_request_group_membership', true)
        );
});

it('does not let guest application tokens bypass members-only activity membership requirements', function () {
    extract(createAccessControlActivity([], [
        'is_public' => false,
    ]));

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
    ]);

    $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
    ]))->assertNotFound();

    $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $application->guest_access_token,
        'secretKey' => $activity->secret_key,
    ]))->assertNotFound();
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
