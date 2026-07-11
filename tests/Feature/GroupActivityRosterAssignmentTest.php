<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\User;
use App\Support\ActivityCompositionPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createRosterAssignmentSetup(): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $tankClass = CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
    ]);
    $healerClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);
    $phantomKnight = PhantomJob::create([
        'name' => 'Phantom Knight',
        'max_level' => 20,
    ]);
    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
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
                    'composition_hint_key' => 'mixed-test',
                    'composition_hints' => [
                        [
                            'position' => 1,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'tank'],
                                ['type' => 'class', 'key' => 'PLD'],
                            ],
                        ],
                    ],
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
        'application_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Can Play'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'phantom_jobs',
            ],
        ],
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
        'status' => Activity::STATUS_DRAFT,
    ]);

    $benchSlot = $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 99,
    ]);

    $mainSlot = $activity->slots()
        ->where('group_key', '!=', 'bench')
        ->firstOrFail();

    return compact(
        'owner',
        'group',
        'activity',
        'mainSlot',
        'benchSlot',
        'tankClass',
        'healerClass',
        'phantomKnight',
        'phantomBard',
    );
}

function createApplicantForAssignment(
    Activity $activity,
    CharacterClass $characterClass,
    PhantomJob $phantomJob,
    array $applicationOverrides = [],
): array {
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $character->classes()->attach($characterClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomJob->id, [
        'current_level' => $phantomJob->max_level,
        'is_preferred' => true,
    ]);

    $application = ActivityApplication::factory()->create(array_merge([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ], $applicationOverrides));

    $application->load('answers');

    return compact('user', 'character', 'application');
}

function createGroupMemberCharacterForManualAssignment(Group $group): array
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

it('assigns a pending application to a roster slot and creates an active assignment snapshot', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh()->load('fieldValues', 'compositionHints.characterClass');
    $application->refresh();

    expect($mainSlot->assigned_character_id)->toBe($character->id);
    expect($mainSlot->compositionHints)->toHaveCount(2)
        ->and($mainSlot->compositionHints->pluck('hint_key')->all())->toBe(['tank', 'PLD']);
    expect($application->status)->toBe(ActivityApplication::STATUS_APPROVED);
    expect($application->reviewed_by_user_id)->toBe($owner->id);

    expect($mainSlot->fieldValues->firstWhere('field_key', 'character_class')?->value)
        ->toMatchArray([
            'id' => $tankClass->id,
            'name' => 'Paladin',
            'role' => 'tank',
            'shorthand' => 'PLD',
        ]);
    expect($mainSlot->fieldValues->firstWhere('field_key', 'phantom_job')?->value)
        ->toMatchArray([
            'id' => $phantomKnight->id,
            'name' => 'Phantom Knight',
        ]);

    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment?->application_id)->toBe($application->id);
    expect($assignment?->attendance_status)->toBe(ActivitySlotAssignment::STATUS_ASSIGNED);

    $auditLog = AuditLog::query()->where('action', 'group.activity.roster.assigned')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->metadata['selected_character_name'])->toBe($character->name)
        ->and($auditLog->metadata['application_status'])->toBe(ActivityApplication::STATUS_APPROVED);
});

it('lets moderators swap a party composition preset without changing assignments', function () {
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
                    'size' => 4,
                    'composition_hint_key' => 'thdd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('thdd'),
                ],
            ],
        ],
        'slot_schema' => [],
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
        'status' => Activity::STATUS_DRAFT,
    ]);
    $assignedCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    $firstSlot = $activity->slots()->where('position_in_group', 1)->firstOrFail();
    $firstSlot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-group-composition-presets.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'group_key' => 'party-a',
            'composition_preset_key' => 'dddd',
        ])
        ->assertOk()
        ->assertJsonCount(4, 'slots')
        ->assertJsonPath('slots.0.assigned_character_id', $assignedCharacter->id);

    Event::assertDispatched(ActivityManagementUpdated::class, fn (ActivityManagementUpdated $event): bool => (
        ! isset($event->patch['updated_slots'])
        && count($event->patch['updated_slot_composition_hints'] ?? []) === 4
    ));

    $slots = $activity->slots()
        ->where('group_key', 'party-a')
        ->with('compositionHints')
        ->orderBy('position_in_group')
        ->get();

    expect($slots->pluck('compositionHints.0.role_key')->all())->toBe(['dps', 'dps', 'dps', 'dps'])
        ->and($firstSlot->fresh()->assigned_character_id)->toBe($assignedCharacter->id);
});

it('lets moderators apply a party composition to other compatible parties without changing assignments', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
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
                    'size' => 4,
                    'composition_hint_key' => 'custom-source',
                    'composition_hints' => [
                        [
                            'position' => 1,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'tank'],
                                ['type' => 'class', 'key' => 'PLD'],
                            ],
                        ],
                        [
                            'position' => 2,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'healer'],
                            ],
                        ],
                        [
                            'position' => 3,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'dps'],
                            ],
                        ],
                        [
                            'position' => 4,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'dps'],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => 'party-b',
                    'label' => ['en' => 'Party B'],
                    'size' => 4,
                    'composition_hint_key' => 'dddd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('dddd'),
                ],
                [
                    'key' => 'party-c',
                    'label' => ['en' => 'Party C'],
                    'size' => 4,
                    'composition_hint_key' => 'tddd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('tddd'),
                ],
                [
                    'key' => 'party-d',
                    'label' => ['en' => 'Party D'],
                    'size' => 8,
                    'composition_hint_key' => 'tthhdddd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('tthhdddd'),
                ],
            ],
        ],
        'slot_schema' => [],
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
        'status' => Activity::STATUS_DRAFT,
    ]);
    $assignedCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    $assignedSlot = $activity->slots()
        ->where('group_key', 'party-b')
        ->where('position_in_group', 1)
        ->firstOrFail();
    $assignedSlot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-group-composition-presets.apply-to-all', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'source_group_key' => 'party-a',
        ])
        ->assertOk()
        ->assertJsonCount(8, 'slots')
        ->assertJsonPath('slots.0.assigned_character_id', $assignedCharacter->id);

    Event::assertDispatched(ActivityManagementUpdated::class, fn (ActivityManagementUpdated $event): bool => (
        ! isset($event->patch['updated_slots'])
        && count($event->patch['updated_slot_composition_hints'] ?? []) === 8
    ));

    $sourceHintsByPosition = $activity->slots()
        ->where('group_key', 'party-a')
        ->with('compositionHints')
        ->orderBy('position_in_group')
        ->get()
        ->mapWithKeys(fn ($slot): array => [
            $slot->position_in_group => $slot->compositionHints
                ->map(fn ($hint): array => $hint->only(['hint_type', 'hint_key', 'role_key', 'character_class_id', 'sort_order']))
                ->values()
                ->all(),
        ]);

    $activity->slots()
        ->whereIn('group_key', ['party-b', 'party-c'])
        ->with('compositionHints')
        ->orderBy('group_key')
        ->orderBy('position_in_group')
        ->get()
        ->each(function ($slot) use ($sourceHintsByPosition): void {
            expect($slot->compositionHints
                ->map(fn ($hint): array => $hint->only(['hint_type', 'hint_key', 'role_key', 'character_class_id', 'sort_order']))
                ->values()
                ->all())->toBe($sourceHintsByPosition->get($slot->position_in_group));
        });

    $fullPartyFirstSlot = $activity->slots()
        ->where('group_key', 'party-d')
        ->where('position_in_group', 1)
        ->with('compositionHints')
        ->firstOrFail();

    expect($fullPartyFirstSlot->compositionHints->pluck('role_key')->all())->toBe(['tank'])
        ->and($assignedSlot->fresh()->assigned_character_id)->toBe($assignedCharacter->id);
});

it('lets moderators customize empty slot composition hints without changing assignments', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
    ]);
    CharacterClass::create([
        'name' => 'Samurai',
        'shorthand' => 'SAM',
        'role' => 'melee dps',
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
                    'size' => 4,
                    'composition_hint_key' => 'thdd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('thdd'),
                ],
            ],
        ],
        'slot_schema' => [],
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
        'status' => Activity::STATUS_DRAFT,
    ]);
    $slot = $activity->slots()
        ->where('group_key', 'party-a')
        ->where('position_in_group', 1)
        ->firstOrFail();

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-composition-hints.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'composition_hints' => [
                ['type' => 'role', 'key' => 'tank'],
                ['type' => 'role', 'key' => 'healer'],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'slots')
        ->assertJsonPath('slots.0.composition_hints.0.key', 'tank')
        ->assertJsonPath('slots.0.composition_hints.1.key', 'healer');

    Event::assertDispatched(ActivityManagementUpdated::class, fn (ActivityManagementUpdated $event): bool => (
        ! isset($event->patch['updated_slots'])
        && count($event->patch['updated_slot_composition_hints'] ?? []) === 1
    ));

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-composition-hints.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'composition_hints' => [
                ['type' => 'class', 'key' => 'PLD'],
                ['type' => 'class', 'key' => 'SAM'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('slots.0.composition_hints.0.type', 'class')
        ->assertJsonPath('slots.0.composition_hints.0.key', 'PLD')
        ->assertJsonPath('slots.0.composition_hints.1.key', 'SAM');

    $slot->refresh()->load('compositionHints.characterClass');

    expect($slot->compositionHints->pluck('hint_type')->all())->toBe(['class', 'class'])
        ->and($slot->compositionHints->pluck('hint_key')->all())->toBe(['PLD', 'SAM'])
        ->and($slot->compositionHints->pluck('role_key')->all())->toBe(['tank', 'dps'])
        ->and($slot->compositionHints->pluck('characterClass.shorthand')->all())->toBe(['PLD', 'SAM'])
        ->and($slot->assigned_character_id)->toBeNull();
});

it('rejects composition hint changes on assigned slots', function () {
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
                    'size' => 4,
                    'composition_hint_key' => 'thdd',
                    'composition_hints' => ActivityCompositionPresets::compositionHintsForKey('thdd'),
                ],
            ],
        ],
        'slot_schema' => [],
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
        'status' => Activity::STATUS_DRAFT,
    ]);
    $assignedCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    $slot = $activity->slots()
        ->where('group_key', 'party-a')
        ->where('position_in_group', 1)
        ->firstOrFail();
    $slot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-composition-hints.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $slot->id,
        ]), [
            'composition_hints' => [
                ['type' => 'role', 'key' => 'dps'],
            ],
        ])
        ->assertUnprocessable();

    $slot->refresh()->load('compositionHints');

    expect($slot->compositionHints->pluck('role_key')->all())->toBe(['tank'])
        ->and($slot->assigned_character_id)->toBe($assignedCharacter->id);
});

it('rejects stale slot assignment writes when another moderator changed the slot first', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $staleToken = activity_slot_state_token($mainSlot->fresh());
    $replacement = createApplicantForAssignment($activity, $healerClass, $phantomBard);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => $staleToken,
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $replacement['application']->id,
        'expected_slot_state_token' => $staleToken,
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ]);

    $response
        ->assertStatus(409)
        ->assertJsonPath('message', 'This slot changed while you were editing it. Refresh and try again.');

    expect($mainSlot->fresh()->assigned_character_id)->toBe($character->id)
        ->and($replacement['application']->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('broadcasts a management patch when a pending applicant is assigned from the queue', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.slot-assignments.store', [
            'group' => $group->slug,
            'activity' => $activity->id,
            'slot' => $mainSlot->id,
        ]), [
            'application_id' => $application->id,
            'expected_slot_state_token' => activity_slot_state_token($mainSlot),
            'field_values' => [
                'character_class' => (string) $tankClass->id,
                'phantom_job' => (string) $phantomKnight->id,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('pending_application_count', 0)
        ->assertJsonPath('queue_application_remove_ids.0', $application->id);

    Event::assertDispatched(ActivityManagementUpdated::class, function (ActivityManagementUpdated $event) use ($activity, $group, $application) {
        return $event->activityId === $activity->id
            && $event->groupId === $group->id
            && ($event->patch['pending_application_count'] ?? null) === 0
            && ($event->patch['queue_application_remove_ids'] ?? []) === [$application->id]
            && count($event->patch['updated_slots'] ?? []) === 1;
    });
});

it('assigns applications to bench slots without requiring slot field selections', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $benchSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($benchSlot),
    ]);

    $response->assertOk();

    $benchSlot->refresh();
    $application->refresh();

    expect($benchSlot->assigned_character_id)->toBe($character->id);
    expect($application->status)->toBe(ActivityApplication::STATUS_ON_BENCH);

    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment?->field_values_snapshot)->toBe([]);
});

it('rejects slot field selections that are not present in the application answers', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['field_values.character_class']);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull();
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('lets moderators ignore class and phantom job application choices when the character has them available', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $character->classes()->attach($healerClass->id, [
        'level' => 100,
        'is_preferred' => false,
    ]);
    $character->phantomJobs()->attach($phantomBard->id, [
        'current_level' => 3,
        'is_preferred' => false,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'ignore_application_choices' => true,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ])->assertOk();

    $mainSlot->refresh()->load('fieldValues');

    expect($mainSlot->fieldValues->firstWhere('field_key', 'character_class')?->value)
        ->toMatchArray(['id' => $healerClass->id, 'name' => 'White Mage'])
        ->and($mainSlot->fieldValues->firstWhere('field_key', 'phantom_job')?->value)
        ->toMatchArray(['id' => $phantomBard->id, 'name' => 'Phantom Bard'])
        ->and(AuditLog::query()->where('action', 'group.activity.roster.assigned')->sole()->metadata['application_choices_ignored'])
        ->toBeTrue();
});

it('does not let ignored application choices assign jobs unavailable to the character', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $character->classes()->attach($healerClass->id, [
        'level' => 0,
        'is_preferred' => false,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'ignore_application_choices' => true,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['field_values.character_class']);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('treats an application any option as all concrete static slot options', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $slotRaidPositionOptions = [
        ['key' => 'mt', 'label' => ['en' => 'Main Tank']],
        ['key' => 'ot', 'label' => ['en' => 'Off Tank']],
    ];
    $version = ActivityTypeVersion::query()->findOrFail($activity->activity_type_version_id);

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

    $mainSlot->fieldValues()->create([
        'field_key' => 'raid_position',
        'field_label' => ['en' => 'Raid Position'],
        'field_type' => 'single_select',
        'source' => 'static_options',
        'value' => null,
    ]);
    $application->answers()->create([
        'question_key' => 'preferred_raid_positions',
        'question_label' => ['en' => 'Preferred Raid Positions'],
        'question_type' => 'multi_select',
        'source' => 'static_options',
        'value' => ['any'],
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['fieldValues', 'assignments'])),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
            'raid_position' => 'ot',
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh()->load('fieldValues');

    expect($mainSlot->fieldValues->firstWhere('field_key', 'raid_position')?->value)
        ->toMatchArray([
            'key' => 'ot',
            'label' => ['en' => 'Off Tank'],
        ]);
});

it('returns the displaced application to pending when replacing a filled roster slot', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));
    $occupant = compact('user', 'character', 'application');
    extract(createApplicantForAssignment($activity, $healerClass, $phantomBard));
    $replacement = compact('user', 'character', 'application');

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $occupant['application']->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $replacement['application']->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['activity.slotAssignments', 'fieldValues', 'assignments'])),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh();
    $occupant['application']->refresh();
    $replacement['application']->refresh();

    expect($mainSlot->assigned_character_id)->toBe($replacement['character']->id);
    expect($occupant['application']->status)->toBe(ActivityApplication::STATUS_PENDING);
    expect($occupant['application']->reviewed_by_user_id)->toBeNull();
    expect($replacement['application']->status)->toBe(ActivityApplication::STATUS_APPROVED);

    $endedAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $occupant['character']->id)
        ->whereNotNull('ended_at')
        ->first();
    $activeReplacementAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $replacement['character']->id)
        ->whereNull('ended_at')
        ->first();

    expect($endedAssignment)->not->toBeNull();
    expect($activeReplacementAssignment)->not->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'group.activity.roster.replaced')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->metadata['selected_character_name'])->toBe($replacement['character']->name);
    expect($auditLog?->metadata['displaced_character_name'])->toBe($occupant['character']->name);
});

it('rejects source slot reassignments when the source slot does not contain the application character', function () {
    extract(createRosterAssignmentSetup());
    $benchOccupant = createApplicantForAssignment($activity, $tankClass, $phantomKnight);
    $candidate = createApplicantForAssignment($activity, $healerClass, $phantomBard, [
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);

    $benchSlot->update([
        'assigned_character_id' => $benchOccupant['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $candidate['application']->id,
        'source_slot_id' => $benchSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'expected_source_slot_state_token' => activity_slot_state_token($benchSlot),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_slot_id']);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull();
    expect($benchSlot->fresh()->assigned_character_id)->toBe($benchOccupant['character']->id);
});

it('manually assigns a group member character to an empty slot without creating an application link', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh()->load('fieldValues');

    expect($mainSlot->assigned_character_id)->toBe($character->id);

    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment?->application_id)->toBeNull()
        ->and($assignment?->assignment_source)->toBe(ActivitySlotAssignment::SOURCE_MANUAL);

    $auditLog = AuditLog::query()->where('action', 'group.activity.roster.manual_assigned')->sole();

    expect($auditLog->metadata['selected_character_name'])->toBe($character->name)
        ->and($auditLog->metadata['assignment_source'])->toBe(ActivitySlotAssignment::SOURCE_MANUAL);
});

it('does not allow manual assignment when the character already has an active application for the run', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $character->user_id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['character_id']);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull();
});

it('does not allow manually reassigning a character while they are still marked missing for the run', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-missing.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['fieldValues', 'assignments'])),
    ])->assertOk();

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['fieldValues', 'assignments'])),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['character_id']);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull()
        ->and(ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('character_id', $character->id)
            ->where('attendance_status', ActivitySlotAssignment::STATUS_MISSING)
            ->count())->toBe(1);
});

it('prevents restoring missing application assignments after the applicant withdraws', function () {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $mainSlot = $mainSlot->fresh(['fieldValues', 'assignments']);

    $this->postJson(route('groups.dashboard.activities.slot-missing.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
    ])->assertOk();

    $missingAssignment = ActivitySlotAssignment::query()
        ->where('application_id', $application->id)
        ->where('attendance_status', ActivitySlotAssignment::STATUS_MISSING)
        ->sole();

    $this->actingAs($user)
        ->delete(route('account.applications.destroy', $application))
        ->assertRedirect(route('account.applications'));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($mainSlot->fresh()->assigned_character_id)->toBeNull();

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.activities.management-data', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'activity.missing_assignments');

    $this->postJson(route('groups.dashboard.activities.slot-missing.undo', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'assignment' => $missingAssignment->id,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assignment'])
        ->assertJsonPath('message', 'This player cancelled their registration, so the missing entry was removed.')
        ->assertJsonPath('remove_missing_assignment_ids.0', $missingAssignment->id);

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);
});

it('does not allow assigning an application when the character is already in another slot', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $secondMainSlot = $activity->slots()->create([
        'group_key' => $mainSlot->group_key,
        'group_label' => $mainSlot->group_label,
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A Slot 2'],
        'position_in_group' => 2,
        'sort_order' => 2,
    ]);

    $mainSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $mainSlot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $owner->id,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $character->user_id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $secondMainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($secondMainSlot),
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['application_id']);

    expect($secondMainSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);
});

it('returns queue removal details when assigning a stale unavailable application', function (string $status) {
    extract(createRosterAssignmentSetup());
    extract(createApplicantForAssignment($activity, $tankClass, $phantomKnight));

    $application->update([
        'status' => $status,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['application_id'])
        ->assertJsonPath('message', 'This application was cancelled and has been removed from the queue.')
        ->assertJsonPath('queue_application_remove_ids.0', $application->id);

    expect($mainSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($application->fresh()->status)->toBe($status);
})->with([
    'cancelled application' => [ActivityApplication::STATUS_CANCELLED],
    'withdrawn application' => [ActivityApplication::STATUS_WITHDRAWN],
]);

it('moves raid leader designations with manual reassignments between roster slots', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $secondMainSlot = $activity->slots()->create([
        'group_key' => $mainSlot->group_key,
        'group_label' => $mainSlot->group_label,
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A Slot 2'],
        'position_in_group' => 2,
        'sort_order' => 2,
    ]);

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $mainSlot->update([
        'is_host' => false,
        'is_raid_leader' => true,
    ]);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $secondMainSlot->id,
    ]), [
        'character_id' => $character->id,
        'source_slot_id' => $mainSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($secondMainSlot),
        'expected_source_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    expect($mainSlot->fresh()->is_raid_leader)->toBeFalse()
        ->and($secondMainSlot->fresh()->is_raid_leader)->toBeTrue()
        ->and($secondMainSlot->fresh()->assigned_character_id)->toBe($character->id);
});

it('allows manually assigned slots to be removed from the roster', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    $mainSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $mainSlot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
    ])->assertOk();

    $mainSlot->refresh()->load(['fieldValues']);
    $activeAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('activity_slot_id', $mainSlot->id)
        ->where('character_id', $character->id)
        ->latest('assigned_at')
        ->first();
    $auditLog = AuditLog::query()->where('action', 'group.activity.roster.manual_removed')->sole();

    expect($response->json('application'))->toBeNull()
        ->and($response->json('pending_application_count'))->toBe(0)
        ->and($mainSlot->assigned_character_id)->toBeNull()
        ->and($mainSlot->assigned_by_user_id)->toBeNull()
        ->and($mainSlot->fieldValues->every(fn ($fieldValue) => $fieldValue->value === null))->toBeTrue()
        ->and($activeAssignment)->not->toBeNull()
        ->and($activeAssignment?->ended_at)->not->toBeNull()
        ->and($auditLog->metadata['character_name'] ?? null)->toBe($character->name);
});

it('allows manual assignments to use the full slot field option list', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh()->load('fieldValues');

    expect($mainSlot->fieldValues->firstWhere('field_key', 'character_class')?->value)
        ->toMatchArray([
            'id' => $healerClass->id,
            'name' => 'White Mage',
            'role' => 'healer',
            'shorthand' => 'WHM',
        ]);
    expect($mainSlot->fieldValues->firstWhere('field_key', 'phantom_job')?->value)
        ->toMatchArray([
            'id' => $phantomBard->id,
            'name' => 'Phantom Bard',
        ]);
});

it('keeps manually assigned bench moves manual even when the character has a withdrawn application history', function () {
    extract(createRosterAssignmentSetup());
    extract(createGroupMemberCharacterForManualAssignment($group));

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $character->user_id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'reviewed_at' => now(),
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $benchSlot->id,
    ]), [
        'character_id' => $character->id,
        'source_slot_id' => $mainSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($benchSlot->fresh(['assignments'])),
        'expected_source_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['assignments'])),
        'field_values' => [],
    ])->assertOk();

    $benchSlot->refresh()->load(['assignments']);

    $activeBenchAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->latest('assigned_at')
        ->first();

    expect($benchSlot->assigned_character_id)->toBe($character->id)
        ->and($activeBenchAssignment)->not->toBeNull()
        ->and($activeBenchAssignment?->application_id)->toBeNull()
        ->and($activeBenchAssignment?->assignment_source)->toBe(ActivitySlotAssignment::SOURCE_MANUAL);

    $this->getJson(route('groups.dashboard.activities.slot-manual-assignment-options.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ], false).'?source_slot_id='.$benchSlot->id)
        ->assertOk()
        ->assertJsonFragment([
            'id' => $character->id,
            'name' => $character->name,
        ]);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'source_slot_id' => $benchSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['assignments'])),
        'expected_source_slot_state_token' => activity_slot_state_token($benchSlot->fresh(['assignments'])),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    expect($mainSlot->fresh()->assigned_character_id)->toBe($character->id)
        ->and($benchSlot->fresh()->assigned_character_id)->toBeNull();
});

it('rejects filled roster and bench swaps while allowing empty-target moves without duplicating assignees', function () {
    extract(createRosterAssignmentSetup());

    $rosterApplicant = createApplicantForAssignment($activity, $tankClass, $phantomKnight);
    $benchApplicant = createApplicantForAssignment($activity, $healerClass, $phantomBard);
    $secondBenchApplicant = createApplicantForAssignment($activity, $tankClass, $phantomBard);
    $secondBenchSlot = $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-2',
        'slot_label' => ['en' => 'Bench 2'],
        'position_in_group' => 2,
        'sort_order' => 100,
    ]);
    $thirdBenchSlot = $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-3',
        'slot_label' => ['en' => 'Bench 3'],
        'position_in_group' => 3,
        'sort_order' => 101,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $rosterApplicant['application']->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $benchSlot->id,
    ]), [
        'application_id' => $benchApplicant['application']->id,
        'expected_slot_state_token' => activity_slot_state_token($benchSlot),
        'field_values' => [],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $secondBenchSlot->id,
    ]), [
        'application_id' => $secondBenchApplicant['application']->id,
        'expected_slot_state_token' => activity_slot_state_token($secondBenchSlot),
        'field_values' => [],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-swaps.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'source_slot_id' => $mainSlot->id,
        'target_slot_id' => $benchSlot->id,
        'expected_source_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['assignments'])),
        'expected_target_slot_state_token' => activity_slot_state_token($benchSlot->fresh(['assignments'])),
    ])->assertStatus(422);

    $this->postJson(route('groups.dashboard.activities.slot-swaps.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'source_slot_id' => $mainSlot->id,
        'target_slot_id' => $thirdBenchSlot->id,
        'expected_source_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['assignments'])),
        'expected_target_slot_state_token' => activity_slot_state_token($thirdBenchSlot->fresh(['assignments'])),
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $benchApplicant['application']->id,
        'source_slot_id' => $benchSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['assignments'])),
        'expected_source_slot_state_token' => activity_slot_state_token($benchSlot->fresh(['assignments'])),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ])->assertOk();

    $this->postJson(route('groups.dashboard.activities.slot-swaps.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'source_slot_id' => $thirdBenchSlot->id,
        'target_slot_id' => $secondBenchSlot->id,
        'expected_source_slot_state_token' => activity_slot_state_token($thirdBenchSlot->fresh(['assignments'])),
        'expected_target_slot_state_token' => activity_slot_state_token($secondBenchSlot->fresh(['assignments'])),
    ])->assertOk();

    $mainSlot->refresh();
    $benchSlot->refresh();
    $secondBenchSlot->refresh();
    $thirdBenchSlot->refresh();
    $assignedCharacterIds = $activity->slots()
        ->whereNotNull('assigned_character_id')
        ->pluck('assigned_character_id')
        ->all();

    expect($mainSlot->assigned_character_id)->toBe($benchApplicant['character']->id)
        ->and($benchSlot->assigned_character_id)->toBeNull()
        ->and($secondBenchSlot->assigned_character_id)->toBe($rosterApplicant['character']->id)
        ->and($thirdBenchSlot->assigned_character_id)->toBe($secondBenchApplicant['character']->id)
        ->and($rosterApplicant['application']->fresh()->status)->toBe(ActivityApplication::STATUS_ON_BENCH)
        ->and($benchApplicant['application']->fresh()->status)->toBe(ActivityApplication::STATUS_APPROVED)
        ->and($secondBenchApplicant['application']->fresh()->status)->toBe(ActivityApplication::STATUS_ON_BENCH)
        ->and($assignedCharacterIds)->toHaveCount(count(array_unique($assignedCharacterIds)));
});
