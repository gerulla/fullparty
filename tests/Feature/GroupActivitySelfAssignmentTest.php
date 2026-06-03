<?php

use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createNonApplicationSelfAssignmentSetup(): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $group->memberships()->create([
        'user_id' => $user->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $tankClass = CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
    ]);
    $phantomKnight = PhantomJob::create([
        'name' => 'Phantom Knight',
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
                    'size' => 2,
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
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
            ],
        ],
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 1,
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
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => false,
        'allow_guest_applications' => false,
        'is_public' => true,
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'verified_at' => now(),
    ]);
    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $mainSlots = $activity->slots()
        ->where('group_key', '!=', 'bench')
        ->orderBy('sort_order')
        ->get();

    return compact(
        'owner',
        'user',
        'group',
        'activity',
        'character',
        'tankClass',
        'phantomKnight',
        'mainSlots',
    );
}

it('renders the dedicated non-application attendee overview with self-assignment data', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $this->actingAs($user)
        ->get(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/NonApplicationOverview')
            ->where('permissions.can_self_assign', true)
            ->where('selfAssignmentCharacters.0.id', $character->id)
            ->where('slotFieldDefinitions.0.key', 'character_class')
            ->where('slotFieldDefinitions.1.key', 'phantom_job'));
});

it('allows a signed-in user to self-assign one of their verified characters to a free slot', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $slot = $mainSlots->first();

    $response = $this->actingAs($user)
        ->postJson(route('groups.activities.self-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'character_id' => $character->id,
            'expected_slot_state_token' => activity_slot_state_token($slot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('slot.assigned_character_id', $character->id)
        ->assertJsonPath('slot.assignment_source', ActivitySlotAssignment::SOURCE_MANUAL);

    $slot->refresh();
    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('activity_slot_id', $slot->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->first();
    $auditLog = AuditLog::query()->where('action', 'group.activity.roster.manual_assigned')->latest('id')->first();

    expect($slot->assigned_character_id)->toBe($character->id)
        ->and($assignment)->not->toBeNull()
        ->and($assignment?->application_id)->toBeNull()
        ->and($assignment?->assignment_source)->toBe(ActivitySlotAssignment::SOURCE_MANUAL)
        ->and($auditLog)->not->toBeNull()
        ->and($auditLog?->actor_user_id)->toBe($user->id);
});

it('does not allow banned group members to view the attendee overview', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $group->bans()->create([
        'user_id' => $user->id,
        'banned_by_user_id' => $owner->id,
        'reason' => 'Removed from the group.',
    ]);

    $this->actingAs($user)
        ->get(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertNotFound();
});

it('does not allow banned group members to self-assign roster slots', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $slot = $mainSlots->first();

    $group->bans()->create([
        'user_id' => $user->id,
        'banned_by_user_id' => $owner->id,
        'reason' => 'Removed from the group.',
    ]);

    $this->actingAs($user)
        ->postJson(route('groups.activities.self-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'character_id' => $character->id,
            'expected_slot_state_token' => activity_slot_state_token($slot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ])
        ->assertNotFound();

    expect($slot->fresh()->assigned_character_id)->toBeNull();
});

it('does not allow non-members with old secret links to self-assign members-only roster slots', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $slot = $mainSlots->first();
    $outsider = User::factory()->create();
    $outsiderCharacter = Character::factory()->create([
        'user_id' => $outsider->id,
        'verified_at' => now(),
    ]);

    $activity->update([
        'is_public' => false,
        'secret_key' => str_repeat('s', 40),
    ]);

    $this->actingAs($outsider)
        ->postJson(route('groups.activities.self-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
            'secretKey' => $activity->secret_key,
        ]), [
            'character_id' => $outsiderCharacter->id,
            'expected_slot_state_token' => activity_slot_state_token($slot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ])
        ->assertNotFound();

    expect($slot->fresh()->assigned_character_id)->toBeNull();
});

it('does not allow a user to self-assign more than one slot in the same run', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $firstSlot = $mainSlots->get(0);
    $secondSlot = $mainSlots->get(1);
    $secondCharacter = Character::factory()->create([
        'user_id' => $user->id,
        'verified_at' => now(),
    ]);
    $secondCharacter->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $secondCharacter->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $this->actingAs($user)
        ->postJson(route('groups.activities.self-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $firstSlot->id,
        ]), [
            'character_id' => $character->id,
            'expected_slot_state_token' => activity_slot_state_token($firstSlot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson(route('groups.activities.self-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $secondSlot->id,
        ]), [
            'character_id' => $secondCharacter->id,
            'expected_slot_state_token' => activity_slot_state_token($secondSlot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slot']);

    expect($secondSlot->fresh()->assigned_character_id)->toBeNull();
});

it('allows a user to remove themselves from a non-application run slot', function () {
    extract(createNonApplicationSelfAssignmentSetup());

    $slot = $mainSlots->first();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $user->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson(route('groups.activities.self-assignments.destroy', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'expected_slot_state_token' => activity_slot_state_token($slot),
        ]);

    $response->assertOk()
        ->assertJsonPath('slot.assigned_character_id', null);

    $slot->refresh();
    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('activity_slot_id', $slot->id)
        ->where('character_id', $character->id)
        ->latest('assigned_at')
        ->first();
    $auditLog = AuditLog::query()->where('action', 'group.activity.roster.manual_removed')->latest('id')->first();

    expect($slot->assigned_character_id)->toBeNull()
        ->and($slot->assigned_by_user_id)->toBeNull()
        ->and($assignment)->not->toBeNull()
        ->and($assignment?->ended_at)->not->toBeNull()
        ->and($auditLog)->not->toBeNull()
        ->and($auditLog?->actor_user_id)->toBe($user->id);
});
