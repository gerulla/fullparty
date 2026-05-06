<?php

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCrudActivityType(User $creator): ActivityType
{
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $creator->id,
        'is_active' => true,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $creator->id,
        'name' => ['en' => 'Savage Prog'],
        'description' => ['en' => 'Eight-player progression run.'],
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 2,
                ],
            ],
        ],
        'slot_schema' => [
            [
                'key' => 'raid_position',
                'label' => ['en' => 'Raid Position'],
                'type' => 'single_select',
                'source' => 'static_options',
                'options' => [
                    ['key' => 'mt', 'label' => ['en' => 'MT']],
                    ['key' => 'ot', 'label' => ['en' => 'OT']],
                ],
            ],
        ],
        'application_schema' => [
            [
                'key' => 'raid_position',
                'label' => ['en' => 'Preferred Position'],
                'type' => 'single_select',
                'required' => false,
                'source' => 'static_options',
                'options' => [
                    ['key' => 'mt', 'label' => ['en' => 'MT']],
                    ['key' => 'ot', 'label' => ['en' => 'OT']],
                ],
            ],
        ],
        'progress_schema' => [
            'milestones' => [
                ['key' => 'clear', 'label' => ['en' => 'Clear'], 'order' => 1],
                ['key' => 'enrage', 'label' => ['en' => 'Enrage'], 'order' => 2],
            ],
        ],
        'bench_size' => 1,
        'prog_points' => [
            ['key' => 'clear', 'label' => ['en' => 'Clear']],
            ['key' => 'enrage', 'label' => ['en' => 'Enrage']],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return $type->fresh('currentPublishedVersion');
}

it('allows moderators to create private application activities with guest applications enabled', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $organizerCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'organized_by_user_id' => $owner->id,
        'organized_by_character_id' => $organizerCharacter->id,
        'status' => Activity::STATUS_PLANNED,
        'title' => 'Tuesday Savage Prog',
        'notes' => 'Bring food and pots.',
        'starts_at' => '2026-06-15T20:30',
        'duration_hours' => 3,
        'target_prog_point_key' => 'enrage',
        'is_public' => false,
        'needs_application' => true,
        'allow_guest_applications' => true,
    ]);

    $response->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    /** @var Activity $activity */
    $activity = $group->activities()->latest('id')->firstOrFail();

    expect($activity->activity_type_id)->toBe($activityType->id)
        ->and($activity->organized_by_user_id)->toBe($owner->id)
        ->and($activity->organized_by_character_id)->toBe($organizerCharacter->id)
        ->and($activity->title)->toBe('Tuesday Savage Prog')
        ->and($activity->starts_at?->format('Y-m-d H:i'))->toBe('2026-06-15 20:30')
        ->and($activity->target_prog_point_key)->toBe('enrage')
        ->and($activity->is_public)->toBeFalse()
        ->and($activity->needs_application)->toBeTrue()
        ->and($activity->allow_guest_applications)->toBeTrue()
        ->and($activity->secret_key)->not->toBeNull();

    expect($activity->slots()->count())->toBe(3);
    expect($activity->slots()->where('group_key', 'bench')->count())->toBe(1);
    expect($activity->slots()->where('group_key', '!=', 'bench')->count())->toBe(2);
    expect($activity->progressMilestones()->count())->toBe(2);

    $auditLog = AuditLog::query()->where('action', 'group.activity.created')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->subject_type)->toBe(Activity::class)
        ->and($auditLog->subject_id)->toBe($activity->id)
        ->and($auditLog->metadata['activity_title'])->toBe('Tuesday Savage Prog')
        ->and($auditLog->metadata['needs_application'])->toBeTrue();
});

it('forbids non moderators from creating activities', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($member);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_PLANNED,
    ]);

    $response->assertForbidden();
    expect($group->activities()->count())->toBe(0);
});

it('rejects organizer characters that do not belong to the organizer user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $foreignCharacter = Character::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'organized_by_user_id' => $owner->id,
        'organized_by_character_id' => $foreignCharacter->id,
        'status' => Activity::STATUS_PLANNED,
    ]);

    $response->assertStatus(422);
    expect($group->activities()->count())->toBe(0);
});

it('updates mutable activity fields while keeping private access intact', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->private()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $activityType = createCrudActivityType($owner);
    $moderatorCharacter = Character::factory()->primary()->create([
        'user_id' => $moderator->id,
    ]);

    $activity = Activity::factory()->private()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'target_prog_point_key' => 'clear',
        'allow_guest_applications' => true,
    ]);

    $originalSecretKey = $activity->secret_key;

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'organized_by_user_id' => $moderator->id,
        'organized_by_character_id' => $moderatorCharacter->id,
        'title' => 'Updated Run',
        'notes' => 'Updated moderator notes.',
        'starts_at' => '2026-07-01T21:15',
        'duration_hours' => 4,
        'target_prog_point_key' => 'enrage',
        'allow_guest_applications' => false,
    ]);

    $response->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();

    expect($activity->organized_by_user_id)->toBe($moderator->id)
        ->and($activity->organized_by_character_id)->toBe($moderatorCharacter->id)
        ->and($activity->title)->toBe('Updated Run')
        ->and($activity->notes)->toBe('Updated moderator notes.')
        ->and($activity->starts_at?->format('Y-m-d H:i'))->toBe('2026-07-01 21:15')
        ->and($activity->duration_hours)->toBe(4)
        ->and($activity->target_prog_point_key)->toBe('enrage')
        ->and($activity->allow_guest_applications)->toBeFalse()
        ->and($activity->secret_key)->toBe($originalSecretKey);

    $auditLog = AuditLog::query()->where('action', 'group.activity.updated')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->subject_type)->toBe(Activity::class)
        ->and($auditLog->subject_id)->toBe($activity->id)
        ->and($auditLog->metadata['changes']['organized_by_user_id']['old'])->toBe($owner->id)
        ->and($auditLog->metadata['changes']['organized_by_user_id']['new'])->toBe($moderator->id)
        ->and($auditLog->metadata['changes']['allow_guest_applications']['new'])->toBeFalse();
});

it('does not allow archived activities to be updated', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'title' => 'Should Not Save',
    ]);

    $response->assertForbidden();
    expect($activity->fresh()->title)->not->toBe('Should Not Save');
});

it('rejects invalid target prog points during activity creation', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_PLANNED,
        'target_prog_point_key' => 'not-a-real-prog-point',
    ]);

    $response->assertStatus(422);
    expect($group->activities()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
});

it('rejects prohibited fields during activity updates', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'is_public' => true,
        'needs_application' => true,
    ]);

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'status' => Activity::STATUS_CANCELLED,
        'is_public' => false,
        'needs_application' => false,
        'activity_type_id' => $activityType->id,
    ]);

    expect($response->getStatusCode())->toBe(302);
    expect($response->baseResponse->getSession()->has('errors'))->toBeTrue();

    $activity->refresh();

    expect($activity->status)->toBe(Activity::STATUS_PLANNED)
        ->and($activity->is_public)->toBeTrue()
        ->and($activity->needs_application)->toBeTrue();
    expect(AuditLog::query()->count())->toBe(0);
});

it('cancels active applications, clears live slots, and keeps guest status pages read only', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ]);

    $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 999,
    ]);

    $rosterSlot = $activity->slots()->where('group_key', '!=', 'bench')->firstOrFail();
    $benchSlot = $activity->slots()->where('group_key', 'bench')->firstOrFail();

    $pendingUser = User::factory()->create();
    $pendingCharacter = Character::factory()->primary()->create([
        'user_id' => $pendingUser->id,
        'lodestone_id' => '10000001',
    ]);
    $pendingApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $approvedUser = User::factory()->create();
    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'lodestone_id' => '10000002',
    ]);
    $approvedApplication = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
    ]);

    $benchApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);
    $benchApplication->load('selectedCharacter');

    $declinedApplication = ActivityApplication::factory()->guest()->declined($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $withdrawnApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'reviewed_at' => now(),
    ]);

    $rosterSlot->update([
        'assigned_character_id' => $approvedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $rosterSlot->fieldValues()->firstOrFail()->update([
        'value' => [
            'key' => 'mt',
            'label' => ['en' => 'MT'],
        ],
    ]);

    $benchSlot->update([
        'assigned_character_id' => $benchApplication->selected_character_id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $rosterSlot->id,
        'character_id' => $approvedCharacter->id,
        'application_id' => $approvedApplication->id,
        'field_values_snapshot' => [
            'raid_position' => [
                'key' => 'mt',
                'label' => ['en' => 'MT'],
            ],
        ],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $benchSlot->id,
        'character_id' => $benchApplication->selected_character_id,
        'application_id' => $benchApplication->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subMinutes(30),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.cancel', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();
    $rosterSlot->refresh();
    $benchSlot->refresh();
    $pendingApplication->refresh();
    $approvedApplication->refresh();
    $benchApplication->refresh();
    $declinedApplication->refresh();
    $withdrawnApplication->refresh();

    expect($activity->status)->toBe(Activity::STATUS_CANCELLED);

    expect($pendingApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($pendingApplication->review_reason)->toBe('Run cancelled.')
        ->and($approvedApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($approvedApplication->review_reason)->toBe('Run cancelled.')
        ->and($benchApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($benchApplication->review_reason)->toBe('Run cancelled.')
        ->and($declinedApplication->status)->toBe(ActivityApplication::STATUS_DECLINED)
        ->and($withdrawnApplication->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);

    expect($rosterSlot->assigned_character_id)->toBeNull()
        ->and($rosterSlot->assigned_by_user_id)->toBeNull()
        ->and($benchSlot->assigned_character_id)->toBeNull()
        ->and($benchSlot->assigned_by_user_id)->toBeNull()
        ->and($rosterSlot->fieldValues()->firstOrFail()->fresh()->value)->toBeNull();

    expect(ActivitySlotAssignment::query()->where('activity_id', $activity->id)->count())->toBe(2);
    expect(ActivitySlotAssignment::query()->where('activity_id', $activity->id)->whereNull('ended_at')->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'group.activity.updated')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'group.activity.application.cancelled')->count())->toBe(3);

    $statusResponse = $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $benchApplication->guest_access_token,
    ]));

    $statusResponse
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Groups/Activities/ApplicationConfirmation')
            ->where('confirmation.view', 'status')
            ->where('confirmation.can_edit', false)
            ->where('application.status', ActivityApplication::STATUS_CANCELLED)
            ->where('application.review_reason', 'Run cancelled.'));

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $benchApplication->guest_access_token,
    ]));

    $editResponse->assertRedirect(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $benchApplication->guest_access_token,
    ]));
});
