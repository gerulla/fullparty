<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\FFLogs\ActivityReportProgressFetcher;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
