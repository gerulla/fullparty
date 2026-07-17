<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivitySlotKind;
use App\Services\Groups\GroupCompletedParticipationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createFillInActivitySetup(array $slotSchema = []): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    Character::factory()->primary()->create([
        'user_id' => $owner->id,
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
                    'composition_hints' => [],
                ],
                [
                    'key' => 'party-b',
                    'label' => ['en' => 'Party B'],
                    'size' => 2,
                    'composition_hints' => [],
                ],
            ],
        ],
        'slot_schema' => $slotSchema,
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
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
    ]);

    return compact('owner', 'group', 'activity', 'version');
}

function createFillInSlotThroughEndpoint(object $testCase, User $owner, Group $group, Activity $activity): ActivitySlot
{
    $testCase->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.fill-ins.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk();

    return $activity->slots()
        ->where('slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)
        ->firstOrFail();
}

function createFillInGroupMemberCharacter(Group $group): array
{
    $user = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $user->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'verified_at' => now(),
    ]);

    return compact('user', 'character');
}

it('creates fill-in slots without changing main roster capacity', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup([
        [
            'key' => 'support_note',
            'label' => ['en' => 'Support Note'],
            'type' => 'text',
        ],
    ]));

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.fill-ins.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'filled_group_key' => 'party-b',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('slot.slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)
        ->assertJsonPath('slot.is_fill_in', true)
        ->assertJsonPath('slot.is_bench', false)
        ->assertJsonPath('slot.filled_group_key', 'party-b')
        ->assertJsonPath('slot.filled_group_label.en', 'Party B')
        ->assertJsonPath('slot.slot_label.en', 'Fill in 1 Party B');

    $fillInSlot = $activity->slots()
        ->where('slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)
        ->firstOrFail();

    expect($fillInSlot->group_key)->toBe(ActivitySlotKind::FILL_IN_GROUP_KEY)
        ->and($fillInSlot->fieldValues()->pluck('field_key')->all())->toBe(['support_note']);

    $managementData = $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $managementData
        ->assertOk()
        ->assertJsonPath('activity.slot_count', 4)
        ->assertJsonPath('activity.fill_in_slot_count', 1);

    Event::assertDispatched(ActivityManagementUpdated::class);
});

it('updates the party a fill-in covered and rejects non-roster parties', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup());
    $fillInSlot = createFillInSlotThroughEndpoint($this, $owner, $group, $activity);

    $this->actingAs($owner)
        ->patchJson(route('groups.dashboard.activities.fill-ins.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'filled_group_key' => 'party-a',
        ])
        ->assertOk()
        ->assertJsonPath('slot.filled_group_key', 'party-a')
        ->assertJsonPath('slot.filled_group_label.en', 'Party A')
        ->assertJsonPath('slot.slot_label.en', 'Fill in 1 Party A');

    expect($fillInSlot->refresh()->filled_group_key)->toBe('party-a');

    $this->actingAs($owner)
        ->patchJson(route('groups.dashboard.activities.fill-ins.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'filled_group_key' => ActivitySlotKind::FILL_IN_GROUP_KEY,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filled_group_key');
});

it('assigns an existing application to a fill-in slot', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup());
    $fillInSlot = createFillInSlotThroughEndpoint($this, $owner, $group, $activity);
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
    ]);
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'application_id' => $application->id,
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filled_group_key');

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'application_id' => $application->id,
            'filled_group_key' => 'not-a-party',
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filled_group_key');

    expect($fillInSlot->refresh()->assigned_character_id)->toBeNull()
        ->and($fillInSlot->filled_group_key)->toBeNull()
        ->and($application->refresh()->status)->toBe(ActivityApplication::STATUS_PENDING);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'application_id' => $application->id,
            'filled_group_key' => 'party-b',
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertOk()
        ->assertJsonPath('slot.slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)
        ->assertJsonPath('slot.assigned_character_id', $character->id)
        ->assertJsonPath('slot.filled_group_key', 'party-b')
        ->assertJsonPath('slot.slot_label.en', 'Fill in 1 Party B');

    expect($fillInSlot->refresh()->assigned_character_id)->toBe($character->id)
        ->and($fillInSlot->filled_group_key)->toBe('party-b')
        ->and($application->refresh()->status)->toBe(ActivityApplication::STATUS_APPROVED);

    $this->assertDatabaseHas('activity_slot_assignments', [
        'activity_id' => $activity->id,
        'activity_slot_id' => $fillInSlot->id,
        'character_id' => $character->id,
        'application_id' => $application->id,
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
    ]);
});

it('removes application fill-in slots when their assignment is returned to the queue', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup());
    $fillInSlot = createFillInSlotThroughEndpoint($this, $owner, $group, $activity);
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
    ]);
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'application_id' => $application->id,
            'filled_group_key' => 'party-b',
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertOk();

    $assignedFillInSlot = $fillInSlot->fresh(['activity.slotAssignments', 'assignedCharacter', 'fieldValues', 'assignments']);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'expected_slot_state_token' => activity_slot_state_token($assignedFillInSlot),
        ])
        ->assertOk()
        ->assertJsonPath('slot', null)
        ->assertJsonPath('removed_slot_ids.0', $fillInSlot->id)
        ->assertJsonPath('application.id', $application->id);

    expect($application->refresh()->status)->toBe(ActivityApplication::STATUS_PENDING);

    $this->assertDatabaseMissing('activity_slots', [
        'id' => $fillInSlot->id,
    ]);

    $this->assertDatabaseMissing('activity_slot_assignments', [
        'activity_slot_id' => $fillInSlot->id,
    ]);

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => in_array($fillInSlot->id, $event->patch['removed_slot_ids'] ?? [], true)
    );
});

it('counts manually assigned fill-ins as completed participants', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup());
    $fillInSlot = createFillInSlotThroughEndpoint($this, $owner, $group, $activity);
    extract(createFillInGroupMemberCharacter($group));

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'character_id' => $character->id,
            'filled_group_key' => 'party-a',
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertOk()
        ->assertJsonPath('slot.slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)
        ->assertJsonPath('slot.assigned_character_id', $character->id)
        ->assertJsonPath('slot.filled_group_key', 'party-a')
        ->assertJsonPath('slot.slot_label.en', 'Fill in 1 Party A');

    $activity->update([
        'status' => Activity::STATUS_COMPLETE,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $counts = app(GroupCompletedParticipationService::class)->countsByUser($group);

    expect($counts->get($user->id))->toBe(1);
});

it('removes manual fill-in slots when their assignment is removed', function () {
    Event::fake([ActivityManagementUpdated::class]);
    extract(createFillInActivitySetup());
    $fillInSlot = createFillInSlotThroughEndpoint($this, $owner, $group, $activity);
    extract(createFillInGroupMemberCharacter($group));

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'character_id' => $character->id,
            'filled_group_key' => 'party-a',
            'field_values' => [],
            'expected_slot_state_token' => activity_slot_state_token($fillInSlot),
        ])
        ->assertOk();

    $assignedFillInSlot = $fillInSlot->fresh(['activity.slotAssignments', 'assignedCharacter', 'fieldValues', 'assignments']);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $fillInSlot->id,
        ]), [
            'expected_slot_state_token' => activity_slot_state_token($assignedFillInSlot),
        ])
        ->assertOk()
        ->assertJsonPath('slot', null)
        ->assertJsonPath('removed_slot_ids.0', $fillInSlot->id)
        ->assertJsonPath('application', null);

    $this->assertDatabaseMissing('activity_slots', [
        'id' => $fillInSlot->id,
    ]);

    $this->assertDatabaseMissing('activity_slot_assignments', [
        'activity_slot_id' => $fillInSlot->id,
    ]);

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => in_array($fillInSlot->id, $event->patch['removed_slot_ids'] ?? [], true)
    );
});
