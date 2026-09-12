<?php

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\AllianceProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function allianceProgressSetup(): array
{
    Http::preventStrayRequests();
    $group = Group::factory()->open()->create(['is_visible' => true]);
    $version = ActivityTypeVersion::factory()->create([
        'fflogs_zone_id' => 60, 'difficulty' => 'savage', 'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 4]]],
        'progress_schema' => ['milestones' => [
            ['key' => 'first', 'label' => ['en' => 'First'], 'order' => 1, 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 101]],
            ['key' => 'second', 'label' => ['en' => 'Second'], 'order' => 2, 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 102]],
            ['key' => 'last', 'label' => ['en' => 'Last'], 'order' => 3, 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 103]],
        ]],
        'prog_points' => collect(['first', 'bridges', 'second', 'last'])->map(fn ($key) => ['key' => $key, 'label' => ['en' => $key]])->all(),
    ]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED, 'target_prog_point_key' => 'second',
    ]);
    $characters = Character::factory()->count(4)->create();
    foreach ($activity->slots as $index => $slot) {
        $slot->update(['assigned_character_id' => $characters[$index]->id]);
    }
    $fetcher = Mockery::mock(CharacterZoneProgressFetcher::class);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->andReturn(['encounters' => []])->byDefault();
    app()->instance(CharacterZoneProgressFetcher::class, $fetcher);

    return [$group, $activity, $characters, $fetcher];
}

function allianceRecordedRun(Activity $context, Character $character, array $milestones, ?string $furthest = null): Activity
{
    $run = Activity::factory()->create([
        'group_id' => Group::factory()->create()->id, 'activity_type_id' => $context->activity_type_id,
        'activity_type_version_id' => $context->activity_type_version_id, 'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->subDays(2),
    ]);
    $run->slots()->firstOrFail()->update(['assigned_character_id' => $character->id]);
    app(ActivityCompletionService::class)->complete($run, ['milestones' => $milestones, 'furthest_progress_key' => $furthest], $context->group->owner_id);

    return $run;
}

it('returns all four progression colors using completed runs across groups', function () {
    [$group, $activity, $characters] = allianceProgressSetup();
    allianceRecordedRun($activity, $characters[0], ['last' => ['kills' => 1]]);
    allianceRecordedRun($activity, $characters[1], ['second' => ['best_progress_percent' => 25]]);
    allianceRecordedRun($activity, $characters[2], ['first' => ['kills' => 1]]);
    $response = $this->getJson(route('groups.activities.alliance-progress', [$group, $activity]).'?'.http_build_query(['character_ids' => $characters->modelKeys()]));
    $response->assertOk()->assertJsonCount(4, 'characters');
    foreach (['cleared', 'at_target', 'below_target', 'no_progress'] as $index => $status) {
        $response->assertJsonPath('characters.'.$characters[$index]->id.'.status', $status);
    }
    expect(array_keys($response->json('characters.'.$characters[0]->id)))->toBe(['status', 'fflogs_unavailable']);
});

it('uses whichever source is furthest and does not mistake final boss progress for a clear', function () {
    [, $activity, $characters, $fetcher] = allianceProgressSetup();
    allianceRecordedRun($activity, $characters[0], ['second' => ['best_progress_percent' => 80]]);
    allianceRecordedRun($activity, $characters[1], ['first' => ['kills' => 1]]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->withArgs(fn ($character) => $character->id === $characters[0]->id)
        ->andReturn(['encounters' => [['encounter_id' => 101, 'kills' => 1, 'progress' => 100]]]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->withArgs(fn ($character) => $character->id === $characters[1]->id)
        ->andReturn(['encounters' => [['encounter_id' => 103, 'kills' => 0, 'progress' => 100]]]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->withArgs(fn ($character) => $character->id === $characters[2]->id)
        ->andReturn(['encounters' => [['encounter_id' => 103, 'kills' => 1, 'progress' => 0]]]);
    $records = app(AllianceProgressService::class)->forCharacters($activity, $characters);
    expect($records[$characters[0]->id]['status'])->toBe('at_target')
        ->and($records[$characters[1]->id]['status'])->toBe('at_target')
        ->and($records[$characters[2]->id]['status'])->toBe('cleared');
});

it('recognizes explicit progression between bosses and targets without boss records', function () {
    [, $activity, $characters] = allianceProgressSetup();
    $activity->update(['target_prog_point_key' => 'bridges']);
    allianceRecordedRun($activity, $characters[0], [], 'bridges');
    allianceRecordedRun($activity, $characters[1], ['second' => ['best_progress_percent' => 1]]);
    $records = app(AllianceProgressService::class)->forCharacters($activity, $characters);
    expect($records[$characters[0]->id]['status'])->toBe('at_target')
        ->and($records[$characters[1]->id]['status'])->toBe('at_target')
        ->and($records[$characters[2]->id]['status'])->toBe('no_progress');
});

it('recognizes all bosses killed even when the last configured point is not a boss', function () {
    [, $activity, $characters] = allianceProgressSetup();
    $activity->activityTypeVersion->update(['prog_points' => [...$activity->activityTypeVersion->prog_points, ['key' => 'exit', 'label' => ['en' => 'Exit']]]]);
    allianceRecordedRun($activity, $characters[0], ['first' => ['kills' => 1], 'second' => ['kills' => 1], 'last' => ['kills' => 1]]);
    expect(app(AllianceProgressService::class)->forCharacters($activity, $characters)[$characters[0]->id]['status'])->toBe('cleared');
});

it('preserves onsite progress and reports FFLogs failures', function () {
    [, $activity, $characters, $fetcher] = allianceProgressSetup();
    allianceRecordedRun($activity, $characters[0], ['last' => ['kills' => 1]]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->andThrow(new RuntimeException('Unavailable'));
    $records = app(AllianceProgressService::class)->forCharacters($activity, $characters);
    expect($records[$characters[0]->id])->toBe(['status' => 'cleared', 'fflogs_unavailable' => true]);
});

it('does not treat incomplete, bench, missing, or other-difficulty runs as progress', function (string $scenario) {
    [, $activity, $characters] = allianceProgressSetup();
    $run = allianceRecordedRun($activity, $characters[0], ['last' => ['kills' => 1]]);
    $slot = $run->slots()->firstOrFail();
    if ($scenario === 'incomplete') {
        $run->update(['status' => Activity::STATUS_ONGOING]);
    }
    if ($scenario === 'bench') {
        $slot->update(['slot_kind' => ActivitySlot::SLOT_KIND_BENCH]);
    }
    if ($scenario === 'missing') {
        ActivitySlotAssignment::create([
            'activity_id' => $run->id, 'group_id' => $run->group_id, 'activity_slot_id' => $slot->id,
            'character_id' => $characters[0]->id, 'attendance_status' => ActivitySlotAssignment::STATUS_MISSING, 'assigned_at' => $run->starts_at,
        ]);
    }
    if ($scenario === 'difficulty') {
        $version = $activity->activityTypeVersion->replicate();
        $version->version = 2;
        $version->difficulty = 'normal';
        $version->save();
        $run->update(['activity_type_version_id' => $version->id]);
    }
    expect(app(AllianceProgressService::class)->forCharacters($activity, $characters)[$characters[0]->id]['status'])->toBe('no_progress');
})->with(['incomplete', 'bench', 'missing', 'difficulty']);

it('uses whole-fight kills for phase clears without estimating later phases from percentages', function () {
    [, $activity, $characters, $fetcher] = allianceProgressSetup();
    $activity->activityTypeVersion->update([
        'progress_schema' => ['milestones' => collect(['first', 'second', 'last'])->map(fn ($key, $index) => [
            'key' => $key, 'order' => $index, 'fflogs_matcher' => ['type' => 'phase', 'encounter_id' => 101, 'phase_id' => $index + 1],
        ])->all()],
    ]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->withArgs(fn ($character) => $character->id === $characters[0]->id)
        ->andReturn(['encounters' => [['encounter_id' => 101, 'kills' => 1, 'progress' => 100]]]);
    $fetcher->shouldReceive('fetchEncounterProgressForCharacter')->withArgs(fn ($character) => $character->id !== $characters[0]->id)
        ->andReturn(['encounters' => [['encounter_id' => 101, 'kills' => 0, 'progress' => 80]]]);
    $records = app(AllianceProgressService::class)->forCharacters($activity, $characters);
    expect($records[$characters[0]->id]['status'])->toBe('cleared')
        ->and($records[$characters[1]->id]['status'])->toBe('below_target');
});

it('limits progress lookups to the visible run and its currently assigned characters', function () {
    [$group, $activity, $characters, $fetcher] = allianceProgressSetup();
    $fetcher->shouldNotReceive('fetchEncounterProgressForCharacter');
    $url = route('groups.activities.alliance-progress', [$group, $activity]);
    $this->getJson($url.'?'.http_build_query(['character_ids' => [Character::factory()->create()->id]]))->assertNotFound();
    $this->getJson($url.'?'.http_build_query(['character_ids' => [...$characters->modelKeys(), 999999]]))->assertUnprocessable();
    $this->getJson(route('groups.activities.alliance-progress', [Group::factory()->create(), $activity]).'?'.http_build_query(['character_ids' => [$characters[0]->id]]))->assertNotFound();
    $group->update(['is_visible' => false]);
    $this->getJson($url.'?'.http_build_query(['character_ids' => [$characters[0]->id]]))->assertNotFound();
});

it('does not load FFLogs while opening a normal overview', function () {
    [$group, $activity, , $fetcher] = allianceProgressSetup();
    $fetcher->shouldNotReceive('fetchEncounterProgressForCharacter');
    $this->get(route('groups.activities.overview', [$group, $activity]))->assertOk();
});
