<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRosterAssignmentSetup(): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
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
        'status' => Activity::STATUS_PLANNED,
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
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ]);

    $response->assertOk();

    $mainSlot->refresh()->load('fieldValues');
    $application->refresh();

    expect($mainSlot->assigned_character_id)->toBe($character->id);
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
