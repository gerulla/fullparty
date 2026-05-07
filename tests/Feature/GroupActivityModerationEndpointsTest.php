<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\FFLogs\ActivityReportProgressFetcher;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createModerationEndpointSetup(array $versionOverrides = [], array $activityOverrides = []): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);

    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $activityType = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create(array_merge([
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
        'slot_schema' => [],
        'application_schema' => [
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
        ],
        'progress_schema' => [
            'milestones' => [
                [
                    'key' => 'phase_3',
                    'label' => ['en' => 'Phase 3'],
                    'order' => 1,
                    'fflogs_matcher' => [
                        'encounter_id' => 9001,
                    ],
                ],
            ],
        ],
        'prog_points' => [],
        'fflogs_zone_id' => 77,
    ], $versionOverrides));

    $activityType->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create(array_merge([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'allow_guest_applications' => true,
    ], $activityOverrides));

    $slot = $activity->slots()->firstOrFail();

    return compact('owner', 'group', 'activity', 'activityType', 'version', 'slot');
}

it('returns management data with missing assignments and backfilled active assignments', function () {
    extract(createModerationEndpointSetup());

    $assignedApplicant = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);
    $assignedApplicant->load('selectedCharacter');

    $guestMissingApplicant = ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);
    $guestMissingApplicant->load('selectedCharacter');

    $slot->update([
        'assigned_character_id' => $assignedApplicant->selectedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $missingAssignment = ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $guestMissingApplicant->selectedCharacter->id,
        'application_id' => $guestMissingApplicant->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_MISSING,
        'assigned_at' => now()->subMinutes(10),
        'assigned_by_user_id' => $owner->id,
        'marked_missing_at' => now()->subMinute(),
        'marked_missing_by_user_id' => $owner->id,
        'ended_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('activity.allow_guest_applications', true)
        ->assertJsonPath('activity.application_count', 2)
        ->assertJsonPath('activity.pending_application_count', 0)
        ->assertJsonPath('activity.missing_assignments.0.id', $missingAssignment->id)
        ->assertJsonPath('activity.missing_assignments.0.character.name', $guestMissingApplicant->applicant_character_name);

    $activeAssignment = ActivitySlotAssignment::query()
        ->where('activity_id', $activity->id)
        ->where('character_id', $assignedApplicant->selectedCharacter->id)
        ->whereNull('ended_at')
        ->first();

    expect($activeAssignment)->not->toBeNull();
    expect($activeAssignment?->application_id)->toBe($assignedApplicant->id);
});

it('returns guest application ff logs progress using the applicant identity snapshot', function () {
    extract(createModerationEndpointSetup());

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'applicant_lodestone_id' => '47431834',
        'applicant_character_name' => 'Guest Raider',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);

    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    $fetcher
        ->shouldReceive('fetchEncounterProgressForIdentity')
        ->once()
        ->with('Guest Raider', 'Twintania', 'Light', '47431834', 77)
        ->andReturn([
            'total_kills' => 3,
            'bosses' => [
                ['name' => 'Boss One', 'kills' => 3, 'best_percent' => 100],
            ],
        ]);

    app()->instance(CharacterZoneProgressFetcher::class, $fetcher);

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.application-fflogs-progress', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('progress.title', 'FF Logs Progress')
        ->assertJsonPath('progress.total_kills', 3)
        ->assertJsonPath('progress.bosses.0.name', 'Boss One');
});

it('returns an assignment context payload for guest applicants on filled slots', function () {
    extract(createModerationEndpointSetup());

    $application = ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
        'applicant_character_name' => 'Guest Tank',
        'applicant_world' => 'Twintania',
        'applicant_datacenter' => 'Light',
    ]);
    $application->load('selectedCharacter');

    $slot->update([
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->getJson(route('groups.dashboard.activities.slot-assignments.context', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('application.id', $application->id)
        ->assertJsonPath('application.is_guest', true)
        ->assertJsonPath('application.applicant_character.name', 'Guest Tank');
});

it('includes assignment source metadata for filled slots in management data', function () {
    extract(createModerationEndpointSetup());

    $application = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);
    $application->load('selectedCharacter');

    $slot->update([
        'assigned_character_id' => $application->selectedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertJsonPath('activity.slots.0.assignment_source', 'application')
        ->assertJsonPath('activity.slots.0.assignment_application_id', $application->id)
        ->assertJsonPath('activity.slots.0.can_return_to_queue', true);
});

it('returns a completion preview for supported ff logs completion requests', function () {
    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $previewFetcher = Mockery::mock(ActivityReportProgressFetcher::class);
    $previewFetcher
        ->shouldReceive('preview')
        ->once()
        ->withArgs(fn (Activity $targetActivity, string $url) => $targetActivity->is($activity) && $url === 'https://www.fflogs.com/reports/test-report')
        ->andReturn([
            'progress_percent' => 87.5,
            'furthest_progress_key' => 'phase_3',
        ]);

    app()->instance(ActivityReportProgressFetcher::class, $previewFetcher);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.fflogs-completion-preview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'progress_link_url' => 'https://www.fflogs.com/reports/test-report',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('preview.progress_percent', 87.5)
        ->assertJsonPath('preview.furthest_progress_key', 'phase_3');
});

it('returns a friendly validation response when ff logs completion preview processing fails', function () {
    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $previewFetcher = Mockery::mock(ActivityReportProgressFetcher::class);
    $previewFetcher
        ->shouldReceive('preview')
        ->once()
        ->andThrow(new RuntimeException('Could not fetch report.'));

    app()->instance(ActivityReportProgressFetcher::class, $previewFetcher);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.fflogs-completion-preview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'progress_link_url' => 'https://www.fflogs.com/reports/bad-report',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unable to process this FF Logs report right now.');
});

it('allows moderators to decline pending applications with an optional reason', function () {
    extract(createModerationEndpointSetup());

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.application-declines.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]), [
        'reason' => 'Roster already locked for this run.',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('application.id', $application->id)
        ->assertJsonPath('application.status', ActivityApplication::STATUS_DECLINED)
        ->assertJsonPath('application.review_reason', 'Roster already locked for this run.');

    $application->refresh();

    expect($application->status)->toBe(ActivityApplication::STATUS_DECLINED)
        ->and($application->reviewed_by_user_id)->toBe($owner->id)
        ->and($application->reviewed_at)->not->toBeNull()
        ->and($application->review_reason)->toBe('Roster already locked for this run.');

    $auditLog = AuditLog::query()
        ->where('action', 'group.activity.application.declined')
        ->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->metadata['application_status'])->toBe(ActivityApplication::STATUS_DECLINED)
        ->and($auditLog->metadata['review_reason'])->toBe('Roster already locked for this run.');

    Event::assertDispatched(ActivityManagementUpdated::class, function (ActivityManagementUpdated $event) use ($activity, $group, $application) {
        return $event->activityId === $activity->id
            && $event->groupId === $group->id
            && ($event->patch['queue_application_remove_ids'] ?? []) === [$application->id]
            && ($event->patch['pending_application_count'] ?? null) === 0;
    });
});

it('does not allow moderators to decline applications that are no longer pending', function () {
    extract(createModerationEndpointSetup());

    $application = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $this->actingAs($owner);

    $response = $this->postJson(route('groups.dashboard.activities.application-declines.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]), [
        'reason' => 'Too late.',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['application']);

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_APPROVED);
});

it('marks host designations on published slots with audit, notifications, and live sync', function () {
    Queue::fake();

    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $secondSlot = $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A 2'],
        'position_in_group' => 2,
        'sort_order' => 2,
    ]);

    $firstUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $secondUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);

    $firstCharacter = Character::factory()->primary()->create([
        'user_id' => $firstUser->id,
        'name' => 'Host Candidate',
        'lodestone_id' => '40000001',
    ]);
    $secondCharacter = Character::factory()->primary()->create([
        'user_id' => $secondUser->id,
        'name' => 'Raid Candidate',
        'lodestone_id' => '40000002',
    ]);

    $slot->update([
        'assigned_character_id' => $firstCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $secondSlot->update([
        'assigned_character_id' => $secondCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $firstUser->id,
        'selected_character_id' => $firstCharacter->id,
        'applicant_lodestone_id' => $firstCharacter->lodestone_id,
        'applicant_character_name' => $firstCharacter->name,
        'applicant_world' => $firstCharacter->world,
        'applicant_datacenter' => $firstCharacter->datacenter,
    ]);
    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $secondUser->id,
        'selected_character_id' => $secondCharacter->id,
        'applicant_lodestone_id' => $secondCharacter->lodestone_id,
        'applicant_character_name' => $secondCharacter->name,
        'applicant_world' => $secondCharacter->world,
        'applicant_datacenter' => $secondCharacter->datacenter,
    ]);

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-designations.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'designation' => 'host',
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
        'expected_current_designation_slot_id' => null,
    ])
        ->assertOk()
        ->assertJsonPath('slot.is_host', true);

    expect($slot->fresh()->is_host)->toBeTrue()
        ->and($secondSlot->fresh()->is_host)->toBeFalse();

    Event::assertDispatched(ActivityManagementUpdated::class, function (ActivityManagementUpdated $event) use ($activity, $group, $slot) {
        return $event->activityId === $activity->id
            && $event->groupId === $group->id
            && count($event->patch['updated_slots'] ?? []) === 1
            && ($event->patch['updated_slots'][0]['id'] ?? null) === $slot->id
            && ($event->patch['updated_slots'][0]['is_host'] ?? false) === true;
    });

    expect(AuditLog::query()->where('action', 'group.activity.roster.host_marked')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'assignments.designation_assigned')->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $firstUser->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $firstUser->id)->where('channel', NotificationChannel::EMAIL)->count())->toBe(1);

    $this->postJson(route('groups.dashboard.activities.slot-designations.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $secondSlot->id,
    ]), [
        'designation' => 'host',
        'expected_slot_state_token' => activity_slot_state_token($secondSlot->fresh()),
        'expected_current_designation_slot_id' => $slot->id,
    ])
        ->assertOk()
        ->assertJsonCount(2, 'slots');

    expect($slot->fresh()->is_host)->toBeFalse()
        ->and($secondSlot->fresh()->is_host)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'group.activity.roster.host_marked')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'group.activity.roster.host_cleared')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'assignments.designation_assigned')->count())->toBe(2)
        ->and(NotificationEvent::query()->where('type', 'assignments.designation_removed')->count())->toBe(1);

    Event::assertDispatched(ActivityManagementUpdated::class, function (ActivityManagementUpdated $event) use ($activity, $group, $slot, $secondSlot) {
        $updatedSlots = collect($event->patch['updated_slots'] ?? []);

        return $event->activityId === $activity->id
            && $event->groupId === $group->id
            && $updatedSlots->count() === 2
            && $updatedSlots->contains(fn (array $entry) => ($entry['id'] ?? null) === $slot->id && ($entry['is_host'] ?? true) === false)
            && $updatedSlots->contains(fn (array $entry) => ($entry['id'] ?? null) === $secondSlot->id && ($entry['is_host'] ?? false) === true);
    });
});

it('replaces an existing host designation when the same slot is marked as raid leader', function () {
    Queue::fake();

    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $user = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Dual Marker Candidate',
        'lodestone_id' => '40000009',
    ]);

    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
        'is_raid_leader' => false,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    Event::fake([ActivityManagementUpdated::class]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-designations.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'designation' => 'raid_leader',
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
        'expected_current_designation_slot_id' => null,
    ])
        ->assertOk()
        ->assertJsonPath('slot.is_host', false)
        ->assertJsonPath('slot.is_raid_leader', true);

    expect($slot->fresh()->is_host)->toBeFalse()
        ->and($slot->fresh()->is_raid_leader)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'group.activity.roster.host_cleared')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'group.activity.roster.raid_leader_marked')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'assignments.designation_removed')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'assignments.designation_assigned')->count())->toBe(1);

    Event::assertDispatched(ActivityManagementUpdated::class, function (ActivityManagementUpdated $event) use ($activity, $group, $slot) {
        $updatedSlots = collect($event->patch['updated_slots'] ?? []);

        return $event->activityId === $activity->id
            && $event->groupId === $group->id
            && $updatedSlots->count() === 1
            && $updatedSlots->contains(fn (array $entry) => ($entry['id'] ?? null) === $slot->id
                && ($entry['is_host'] ?? true) === false
                && ($entry['is_raid_leader'] ?? false) === true);
    });
});

it('clears host designations when a designated slot is returned to the queue', function () {
    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $applicant = User::factory()->create([
        'assignment_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Queue Return',
        'lodestone_id' => '50000001',
    ]);

    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
    ])->assertOk();

    expect($slot->fresh()->is_host)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'group.activity.roster.host_cleared')->count())->toBe(1);
});

it('moves host designations with the assignee when roster slots are swapped', function () {
    extract(createModerationEndpointSetup([], [
        'status' => Activity::STATUS_ASSIGNED,
    ]));

    $secondSlot = $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A 2'],
        'position_in_group' => 2,
        'sort_order' => 2,
    ]);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $firstCharacter = Character::factory()->primary()->create([
        'user_id' => $firstUser->id,
        'name' => 'Travelling Host',
        'lodestone_id' => '60000001',
    ]);
    $secondCharacter = Character::factory()->primary()->create([
        'user_id' => $secondUser->id,
        'name' => 'Static Support',
        'lodestone_id' => '60000002',
    ]);

    $slot->update([
        'assigned_character_id' => $firstCharacter->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
    ]);
    $secondSlot->update([
        'assigned_character_id' => $secondCharacter->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => false,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-swaps.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'source_slot_id' => $slot->id,
        'target_slot_id' => $secondSlot->id,
        'expected_source_slot_state_token' => activity_slot_state_token($slot->fresh()),
        'expected_target_slot_state_token' => activity_slot_state_token($secondSlot->fresh()),
    ])->assertOk();

    expect($slot->fresh()->is_host)->toBeFalse()
        ->and($secondSlot->fresh()->is_host)->toBeTrue()
        ->and($secondSlot->fresh()->assigned_character_id)->toBe($firstCharacter->id);
});
