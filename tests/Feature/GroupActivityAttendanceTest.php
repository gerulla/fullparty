<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAttendanceTestSetup(int $partySize = 2, bool $withBench = true): array
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
                    'size' => $partySize,
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
        'bench_size' => $withBench ? 1 : 0,
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

    $benchSlot = null;

    if ($withBench) {
        $benchSlot = $activity->slots()->create([
            'group_key' => 'bench',
            'group_label' => ['en' => 'Bench'],
            'slot_key' => 'bench-slot-1',
            'slot_label' => ['en' => 'Bench 1'],
            'position_in_group' => 1,
            'sort_order' => 99,
        ]);
    }

    $mainSlots = $activity->slots()
        ->where('group_key', '!=', 'bench')
        ->orderBy('sort_order')
        ->get();

    return compact('owner', 'group', 'activity', 'mainSlots', 'benchSlot');
}

function createAttendanceApplicant(Activity $activity, User $reviewer, array $applicationOverrides = []): array
{
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->approved($reviewer)->create(array_merge([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ], $applicationOverrides));

    return compact('user', 'character', 'application');
}

it('checks in a filled slot and records an attendance audit event', function () {
    extract(createAttendanceTestSetup());
    extract(createAttendanceApplicant($activity, $owner));

    /** @var ActivitySlot $slot */
    $slot = $mainSlots->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-checkins.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot),
    ]);

    $response->assertOk();

    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->sole();

    expect($assignment->attendance_status)->toBe(ActivitySlotAssignment::STATUS_CHECKED_IN)
        ->and($assignment->checked_in_by_user_id)->toBe($owner->id)
        ->and($assignment->checked_in_at)->not->toBeNull();

    $auditLog = AuditLog::query()->where('action', 'group.activity.attendance.checked_in')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->metadata['character_name'])->toBe($character->name)
        ->and($auditLog->metadata['slot_label'])->toBe('Party A 1');
});

it('undoes a check in and restores the assignment to assigned status', function () {
    extract(createAttendanceTestSetup());
    extract(createAttendanceApplicant($activity, $owner));

    /** @var ActivitySlot $slot */
    $slot = $mainSlots->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-checkins.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot),
    ])->assertOk();

    $response = $this->postJson(route('groups.dashboard.activities.slot-checkins.undo', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh(['activity.slotAssignments', 'fieldValues', 'assignments'])),
    ]);

    $response->assertOk();

    $assignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->sole();

    expect($assignment->attendance_status)->toBe(ActivitySlotAssignment::STATUS_ASSIGNED)
        ->and($assignment->checked_in_at)->toBeNull()
        ->and($assignment->checked_in_by_user_id)->toBeNull();

    expect(AuditLog::query()->where('action', 'group.activity.attendance.check_in_reverted')->exists())
        ->toBeTrue();
});

it('marks a slot missing and restores the assignment to bench when the original slot is occupied', function () {
    extract(createAttendanceTestSetup(partySize: 1, withBench: true));
    extract(createAttendanceApplicant($activity, $owner));

    /** @var ActivitySlot $mainSlot */
    $mainSlot = $mainSlots->firstOrFail();
    $mainSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $mainSlot->id,
        'character_id' => $character->id,
        'application_id' => $application->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subMinutes(5),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $markResponse = $this->postJson(route('groups.dashboard.activities.slot-missing.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
    ]);

    $markResponse->assertOk();

    $missingAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->sole();

    $replacement = createAttendanceApplicant($activity, $owner, [
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);

    $mainSlot->update([
        'assigned_character_id' => $replacement['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $response = $this->postJson(route('groups.dashboard.activities.slot-missing.undo', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'assignment' => $missingAssignment->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['activity.slotAssignments', 'fieldValues', 'assignments'])),
    ]);

    $response->assertOk();

    $benchSlot->refresh();
    $application->refresh();
    $missingAssignment->refresh();

    expect($benchSlot->assigned_character_id)->toBe($character->id);
    expect($application->status)->toBe(ActivityApplication::STATUS_ON_BENCH);
    expect($missingAssignment->attendance_status)->toBe(ActivitySlotAssignment::STATUS_ASSIGNED)
        ->and($missingAssignment->ended_at)->toBeNull()
        ->and($missingAssignment->activity_slot_id)->toBe($benchSlot->id);

    $auditLog = AuditLog::query()->where('action', 'group.activity.attendance.missing_reverted')->latest('id')->sole();

    expect($auditLog->metadata['restored_destination'])->toBe('bench')
        ->and($auditLog->metadata['character_name'])->toBe($character->name);
});

it('checks in all filled slots in a slot group and records a group attendance audit event', function () {
    extract(createAttendanceTestSetup(partySize: 2, withBench: false));
    $firstApplicant = createAttendanceApplicant($activity, $owner);
    $secondApplicant = createAttendanceApplicant($activity, $owner);

    $mainSlots[0]->update([
        'assigned_character_id' => $firstApplicant['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $mainSlots[1]->update([
        'assigned_character_id' => $secondApplicant['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.slot-group-checkins.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'group_key' => 'party-a',
        'expected_slot_state_tokens' => [
            $mainSlots[0]->id => activity_slot_state_token($mainSlots[0]),
            $mainSlots[1]->id => activity_slot_state_token($mainSlots[1]),
        ],
    ]);

    $response->assertOk()->assertJsonCount(2, 'slots');

    expect(ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('attendance_status', ActivitySlotAssignment::STATUS_CHECKED_IN)
        ->count())->toBe(2);

    $auditLog = AuditLog::query()->where('action', 'group.activity.attendance.group_checked_in')->sole();

    expect($auditLog->metadata['group_label'])->toBe('Party A')
        ->and($auditLog->metadata['checked_in_count'])->toBe(2);
});

it('checks in the rest of a party without requiring or rewriting an already checked-in slot', function (bool $includeOldToken, bool $remainingIsLate) {
    extract(createAttendanceTestSetup(partySize: 3, withBench: false));
    foreach ($mainSlots->take(2) as $slot) {
        $applicant = createAttendanceApplicant($activity, $owner);
        $slot->update(['assigned_character_id' => $applicant['character']->id, 'assigned_by_user_id' => $owner->id]);
    }
    $this->actingAs($owner);
    $first = $mainSlots[0];
    $second = $mainSlots[1];
    $oldToken = activity_slot_state_token($first);
    $this->postJson(route('groups.dashboard.activities.slot-checkins.store', [
        'group' => $group->slug, 'activity' => $activity->id, 'slot' => $first->id,
    ]), ['expected_slot_state_token' => $oldToken])->assertOk();
    $originalAssignment = $first->assignments()->whereNull('ended_at')->sole();
    $originalCheckedInAt = $originalAssignment->checked_in_at->toIso8601String();
    $secondToken = activity_slot_state_token($second);
    if ($remainingIsLate) {
        $lateResponse = $this->postJson(route('groups.dashboard.activities.slot-checkins.late', [
            'group' => $group->slug, 'activity' => $activity->id, 'slot' => $second->id,
        ]), ['expected_slot_state_token' => $secondToken])->assertOk();
        $secondToken = $lateResponse->json('slot.state_token');
    }
    $this->travel(5)->minutes();
    $tokens = [$second->id => $secondToken];
    if ($includeOldToken) {
        $tokens[$first->id] = $oldToken;
    }

    $response = $this->postJson(route('groups.dashboard.activities.slot-group-checkins.store', [
        'group' => $group->slug, 'activity' => $activity->id,
    ]), ['group_key' => 'party-a', 'expected_slot_state_tokens' => $tokens]);
    $response->assertOk()->assertJsonCount(1, 'slots')->assertJsonPath('slots.0.id', $second->id)
        ->assertJsonPath('slots.0.attendance_status', ActivitySlotAssignment::STATUS_CHECKED_IN);
    expect($originalAssignment->fresh()->checked_in_at->toIso8601String())->toBe($originalCheckedInAt)
        ->and($originalAssignment->fresh()->checked_in_by_user_id)->toBe($owner->id)
        ->and($mainSlots[2]->assignments()->count())->toBe(0)
        ->and(AuditLog::where('action', 'group.activity.attendance.group_checked_in')->sole()->metadata['checked_in_count'])->toBe(1);

    $this->postJson(route('groups.dashboard.activities.slot-group-checkins.store', [
        'group' => $group->slug, 'activity' => $activity->id,
    ]), ['group_key' => 'party-a', 'expected_slot_state_tokens' => $tokens])->assertOk()->assertJsonCount(0, 'slots');
    expect(AuditLog::where('action', 'group.activity.attendance.group_checked_in')->count())->toBe(1);
})->with([false, true])->with([false, true]);

it('rejects a missing or stale token for an unchecked member before checking in anyone', function (bool $missingToken) {
    extract(createAttendanceTestSetup(partySize: 2, withBench: false));
    foreach ($mainSlots as $slot) {
        $applicant = createAttendanceApplicant($activity, $owner);
        $slot->update(['assigned_character_id' => $applicant['character']->id, 'assigned_by_user_id' => $owner->id]);
    }
    $tokens = [$mainSlots[0]->id => activity_slot_state_token($mainSlots[0])];
    if (! $missingToken) {
        $tokens[$mainSlots[1]->id] = activity_slot_state_token($mainSlots[1]);
        $mainSlots[1]->update(['is_raid_leader' => true]);
    }

    $this->actingAs($owner)->postJson(route('groups.dashboard.activities.slot-group-checkins.store', [
        'group' => $group->slug, 'activity' => $activity->id,
    ]), ['group_key' => 'party-a', 'expected_slot_state_tokens' => $tokens])->assertConflict();
    expect(ActivitySlotAssignment::where('activity_id', $activity->id)
        ->where('attendance_status', ActivitySlotAssignment::STATUS_CHECKED_IN)->count())->toBe(0)
        ->and(AuditLog::where('action', 'group.activity.attendance.group_checked_in')->count())->toBe(0);
})->with([false, true]);

it('returns a validation error when undoing a missing assignment without any open destination slot', function () {
    extract(createAttendanceTestSetup(partySize: 1, withBench: true));
    extract(createAttendanceApplicant($activity, $owner));

    /** @var ActivitySlot $mainSlot */
    $mainSlot = $mainSlots->firstOrFail();
    $mainSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $mainSlot->id,
        'character_id' => $character->id,
        'application_id' => $application->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subMinutes(5),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-missing.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot),
    ])->assertOk();

    $missingAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $character->id)
        ->sole();

    $replacementMain = createAttendanceApplicant($activity, $owner);
    $replacementBench = createAttendanceApplicant($activity, $owner, [
        'status' => ActivityApplication::STATUS_ON_BENCH,
    ]);

    $mainSlot->update([
        'assigned_character_id' => $replacementMain['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $benchSlot->update([
        'assigned_character_id' => $replacementBench['character']->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $response = $this->postJson(route('groups.dashboard.activities.slot-missing.undo', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'assignment' => $missingAssignment->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh(['activity.slotAssignments', 'fieldValues', 'assignments'])),
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assignment']);

    $missingAssignment->refresh();

    expect($missingAssignment->attendance_status)->toBe(ActivitySlotAssignment::STATUS_MISSING)
        ->and($missingAssignment->ended_at)->not->toBeNull();
});
