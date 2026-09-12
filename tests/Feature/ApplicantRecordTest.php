<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\ApplicantQueue\ApplicantRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function applicantRecordSetup(): array
{
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $version = ActivityTypeVersion::factory()->create([
        'fflogs_zone_id' => 60, 'difficulty' => 'savage', 'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 1]]],
        'progress_schema' => ['milestones' => [
            ['key' => 'first', 'label' => ['en' => 'First Boss', 'ja' => '最初のボス'], 'order' => 1, 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 101]],
            ['key' => 'second', 'label' => ['en' => 'Second Boss'], 'order' => 2, 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 102]],
        ]],
    ]);
    $activity = Activity::factory()->create(['group_id' => $group->id, 'activity_type_version_id' => $version->id, 'status' => Activity::STATUS_SCHEDULED]);
    $application = ActivityApplication::factory()->create(['activity_id' => $activity->id]);

    return [$owner, $group, $activity, $application];
}

function applicantRecordedRun(Activity $context, Character $character, array $overrides = [], int $kills = 2, float $progress = 73.25): Activity
{
    $run = Activity::factory()->create([
        'group_id' => $context->group_id, 'activity_type_id' => $context->activity_type_id,
        'activity_type_version_id' => $context->activity_type_version_id, 'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->subDays(2), ...$overrides,
    ]);
    $run->slots()->firstOrFail()->update(['assigned_character_id' => $character->id]);
    app(ActivityCompletionService::class)->complete($run, [
        'milestones' => ['first' => ['kills' => $kills], 'second' => ['kills' => 0, 'best_progress_percent' => $progress]],
    ], $context->group->owner_id);

    return $run;
}

function applicantRecordFetcher(array $encounters = []): void
{
    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->andReturn(['encounters' => $encounters]);
    app()->instance(CharacterZoneProgressFetcher::class, $fetcher);
}

it('compares completed onsite runs with FFLogs by encounter ID and counts each run once', function () {
    [$owner, $group, $activity, $application] = applicantRecordSetup();
    $run = applicantRecordedRun($activity, $application->selectedCharacter);
    applicantRecordedRun($activity, $application->selectedCharacter, ['group_id' => Group::factory()->create()->id], 3, 83.5);
    foreach ([ActivitySlotAssignment::STATUS_CHECKED_IN, ActivitySlotAssignment::STATUS_LATE] as $status) {
        ActivitySlotAssignment::create([
            'activity_id' => $run->id, 'group_id' => $run->group_id, 'activity_slot_id' => $run->slots()->first()->id,
            'character_id' => $application->selected_character_id, 'attendance_status' => $status, 'assigned_at' => $run->starts_at,
        ]);
    }
    applicantRecordFetcher([
        ['encounter_id' => 102, 'name' => 'Different localized name', 'kills' => 0, 'progress' => 94],
        ['encounter_id' => 101, 'name' => 'First Boss', 'kills' => 12, 'progress' => 20],
        ['encounter_id' => 103, 'name' => 'Additional Boss', 'kills' => 1, 'progress' => 100],
    ]);

    $this->actingAs($owner)->getJson(route('groups.dashboard.activities.application-record', [$group, $activity, $application]))
        ->assertOk()->assertJsonCount(3, 'milestones')
        ->assertJsonPath('milestones.0.onsite', ['kills' => 5, 'progress_percent' => 100])
        ->assertJsonPath('milestones.0.fflogs', ['kills' => 12, 'progress_percent' => 100])
        ->assertJsonPath('milestones.1.onsite', ['kills' => 0, 'progress_percent' => 83.5])
        ->assertJsonPath('milestones.1.fflogs', ['kills' => 0, 'progress_percent' => 94])
        ->assertJsonPath('milestones.2.onsite', null);
});

it('excludes nonparticipation and incomplete or unrelated runs', function (string $scenario) {
    [, , $activity, $application] = applicantRecordSetup();
    $run = applicantRecordedRun($activity, $application->selectedCharacter);
    $slot = $run->slots()->first();
    match ($scenario) {
        'bench' => $slot->update(['slot_kind' => ActivitySlot::SLOT_KIND_BENCH]),
        'legacy_bench' => $slot->update(['group_key' => 'bench']),
        'unfilled' => $slot->update(['slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN, 'filled_group_key' => null]),
        'incomplete' => $run->update(['status' => Activity::STATUS_SCHEDULED]),
        'other_type' => $run->update(['activity_type_id' => ActivityTypeVersion::factory()->create()->activity_type_id]),
        'other_character' => $slot->update(['assigned_character_id' => Character::factory()->create()->id]),
        default => null,
    };
    if (in_array($scenario, ['missing', 'removed_before_run'])) {
        ActivitySlotAssignment::create([
            'activity_id' => $run->id, 'group_id' => $run->group_id, 'activity_slot_id' => $slot->id,
            'character_id' => $application->selected_character_id,
            'attendance_status' => $scenario === 'missing' ? ActivitySlotAssignment::STATUS_MISSING : ActivitySlotAssignment::STATUS_ASSIGNED,
            'assigned_at' => $run->starts_at->copy()->subHour(), 'ended_at' => $run->starts_at->copy()->subMinute(),
        ]);
        if ($scenario === 'removed_before_run') {
            $slot->update(['assigned_character_id' => null]);
        }
    }
    applicantRecordFetcher();
    $result = app(ApplicantRecordService::class)->forApplication($activity, $application);
    expect($result['milestones'][0]['onsite']['kills'])->toBe(0)
        ->and($result['milestones'][1]['onsite']['progress_percent'])->toEqual(0);
})->with(['bench', 'legacy_bench', 'unfilled', 'incomplete', 'other_type', 'other_character', 'missing', 'removed_before_run']);

it('keeps historical participation and renamed milestones across activity versions', function () {
    [, , $activity, $application] = applicantRecordSetup();
    $run = applicantRecordedRun($activity, $application->selectedCharacter);
    $slot = $run->slots()->first();
    ActivitySlotAssignment::create([
        'activity_id' => $run->id, 'group_id' => $run->group_id, 'activity_slot_id' => $slot->id,
        'character_id' => $application->selected_character_id, 'attendance_status' => ActivitySlotAssignment::STATUS_LATE,
        'assigned_at' => $run->starts_at, 'ended_at' => $run->starts_at->copy()->addHour(),
    ]);
    $slot->update(['assigned_character_id' => null]);
    $oldVersion = $run->activityTypeVersion;
    $newVersion = $oldVersion->replicate();
    $newVersion->version = 2;
    $schema = $newVersion->progress_schema;
    $schema['milestones'][0]['key'] = 'renamed-first';
    $newVersion->progress_schema = $schema;
    $newVersion->save();
    $activity->update(['activity_type_version_id' => $newVersion->id]);
    $activity->unsetRelation('activityTypeVersion');
    applicantRecordFetcher();
    $result = app(ApplicantRecordService::class)->forApplication($activity, $application);
    expect($result['milestones'][0]['key'])->toBe('renamed-first')
        ->and($result['milestones'][0]['onsite']['kills'])->toBe(2);
});

it('preserves onsite records when FFLogs is unavailable', function (string $scenario) {
    [, , $activity, $application] = applicantRecordSetup();
    applicantRecordedRun($activity, $application->selectedCharacter);
    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    if ($scenario === 'error') {
        $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->once()->andThrow(new RuntimeException('Unavailable'));
    } else {
        $activity->activityTypeVersion->update(['fflogs_zone_id' => null]);
        $fetcher->shouldNotReceive('fetchEncounterProgressForCharacter');
    }
    app()->instance(CharacterZoneProgressFetcher::class, $fetcher);
    $result = app(ApplicantRecordService::class)->forApplication($activity, $application);
    expect($result['fflogs_status'])->toBe($scenario)
        ->and($result['milestones'][0]['onsite']['kills'])->toBe(2)
        ->and($result['milestones'][0]['fflogs'])->toBeNull();
})->with(['error', 'not_configured']);

it('supports guest identity lookups and onsite records from their linked character', function () {
    [$owner, $group, $activity] = applicantRecordSetup();
    $guest = ActivityApplication::factory()->guest()->create(['activity_id' => $activity->id]);
    applicantRecordedRun($activity, $guest->selectedCharacter);
    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    $fetcher->shouldReceive('fetchEncounterProgressForIdentity')->once()->andReturn(['encounters' => []]);
    app()->instance(CharacterZoneProgressFetcher::class, $fetcher);
    $this->actingAs($owner)->getJson(route('groups.dashboard.activities.application-record', [$group, $activity, $guest]))
        ->assertOk()->assertJsonPath('onsite_available', true)->assertJsonPath('milestones.0.onsite.kills', 2);
});

it('restricts applicant records to the matching activity and moderators', function () {
    [$owner, $group, $activity, $application] = applicantRecordSetup();
    $url = route('groups.dashboard.activities.application-record', [$group, $activity, $application]);
    $this->actingAs($application->user)->getJson($url)->assertForbidden();
    $otherApplication = ActivityApplication::factory()->create();
    $this->actingAs($owner)->getJson(route('groups.dashboard.activities.application-record', [$group, $activity, $otherApplication]))->assertNotFound();
});

it('does not mix changed encounter mappings or phase records with whole boss FFLogs results', function () {
    [, , $activity, $application] = applicantRecordSetup();
    applicantRecordedRun($activity, $application->selectedCharacter);
    $version = $activity->activityTypeVersion->replicate();
    $version->version = 2;
    $schema = $version->progress_schema;
    $schema['milestones'][0]['fflogs_matcher']['encounter_id'] = 999;
    $schema['milestones'][1]['fflogs_matcher'] = ['type' => 'phase', 'encounter_id' => 102, 'phase_id' => 2];
    $version->progress_schema = $schema;
    $version->save();
    $activity->update(['activity_type_version_id' => $version->id]);
    $activity->unsetRelation('activityTypeVersion');
    applicantRecordFetcher([['encounter_id' => 102, 'name' => 'Second Boss', 'kills' => 5, 'progress' => 100]]);
    $result = app(ApplicantRecordService::class)->forApplication($activity, $application);
    expect($result['milestones'][0]['onsite']['kills'])->toBe(0)
        ->and($result['milestones'][1]['onsite']['progress_percent'])->toEqual(0)
        ->and($result['milestones'][1]['fflogs'])->toBeNull()
        ->and($result['milestones'][2]['fflogs']['kills'])->toBe(5);
});

it('shows unavailable source states for an application without a resolvable character', function () {
    [, , $activity, $application] = applicantRecordSetup();
    $application->forceFill(['selected_character_id' => null, 'applicant_character_name' => null, 'applicant_world' => null])->save();
    $application->unsetRelation('selectedCharacter');
    app()->instance(CharacterZoneProgressFetcher::class, Mockery::mock(CharacterZoneProgressFetcher::class));
    $result = app(ApplicantRecordService::class)->forApplication($activity, $application);
    expect($result['onsite_available'])->toBeFalse()
        ->and($result['fflogs_status'])->toBe('identity_unavailable')
        ->and($result['milestones'][0]['onsite'])->toBeNull();
});

it('retains encounter IDs in FFLogs progress for both account and guest lookups', function (bool $guest) {
    $rankings = ['rankings' => [['encounter' => ['id' => 101, 'name' => 'First Boss'], 'totalKills' => 4]]];
    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class)->makePartial();
    if ($guest) {
        $fetcher->shouldReceive('fetchRawZoneRankingsForIdentity')->once()->andReturn($rankings);
        $result = $fetcher->fetchEncounterProgressForIdentity('Character', 'Lich', 'Light', '123', 60, 101);
    } else {
        $fetcher->shouldReceive('fetchRawZoneRankingsForCharacter')->once()->andReturn($rankings);
        $result = $fetcher->fetchEncounterProgressForCharacter(Character::factory()->make(), 60, 101);
    }
    expect($result['encounters'][0]['encounter_id'])->toBe(101);
})->with([false, true]);
