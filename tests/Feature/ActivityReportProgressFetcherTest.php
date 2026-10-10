<?php

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Services\FFLogs\ActivityReportProgressFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::flush();
    Cache::put('fflogs:client_credentials_token', 'mock-token');
    Http::preventStrayRequests();

    $this->phaseMilestones = collect(range(1, 6))->map(fn (int $phase) => [
        'key' => "phase-{$phase}",
        'label' => ['en' => "Phase {$phase}"],
        'fflogs_matcher' => ['type' => 'phase', 'encounter_id' => 1077, 'phase_id' => $phase],
    ])->all();

    $this->previewReport = function (array $fights, ?array $milestones = null, array $phases = []) {
        $milestones ??= $this->phaseMilestones;
        Http::fake(['https://fflogs.test/graphql' => Http::response(['data' => ['reportData' => ['report' => [
            'title' => 'Mock progression report',
            'fights' => $fights,
            'phases' => $phases,
        ]]]])]);

        $activity = (new Activity)->setRelation('activityTypeVersion', new ActivityTypeVersion([
            'prog_points' => collect($milestones)->map(fn (array $milestone) => ['key' => $milestone['key']])->all(),
            'progress_schema' => ['milestones' => $milestones],
        ]));

        return app(ActivityReportProgressFetcher::class)->preview($activity, 'MockReport123');
    };
});

it('imports phase clears and phase-specific damage from a report with 85 progression pulls', function () {
    $fights = array_fill(0, 84, [
        'encounterID' => 1077, 'kill' => false, 'lastPhase' => 1,
        'bossPercentage' => 50.0, 'fightPercentage' => 95.0,
    ]);
    $fights[0]['bossPercentage'] = 5.63;
    $fights[] = [
        'encounterID' => 1077, 'kill' => false, 'lastPhase' => 2,
        'bossPercentage' => 78.58, 'fightPercentage' => 87.85,
    ];
    $fights[] = ['encounterID' => 0, 'kill' => true, 'lastPhase' => 6, 'bossPercentage' => 0];

    $result = ($this->previewReport)($fights);

    expect(array_column($result['milestones'], 'kills'))->toBe([1, 0, 0, 0, 0, 0])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe([100.0, 21.42, 0.0, 0.0, 0.0, 0.0])
        ->and($result['suggested_furthest_progress_key'])->toBe('phase-2')
        ->and($result['report_code'])->toBe('MockReport123')
        ->and($result['report_title'])->toBe('Mock progression report')
        ->and($result['milestones'][0])->not->toHaveKey('reached');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['variables']['code'] === 'MockReport123'
        && str_contains($request['query'], 'lastPhaseIsIntermission')
        && str_contains($request['query'], 'lastPhaseAsAbsoluteIndex'));
});

it('keeps the best damage only from wipes in the matching phase', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 1, 'bossPercentage' => 1.0],
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 2, 'bossPercentage' => 80.0],
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 2, 'bossPercentage' => 70.12],
    ]);

    expect(array_column($result['milestones'], 'kills'))->toBe([2, 0, 0, 0, 0, 0])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe([100.0, 29.88, 0.0, 0.0, 0.0, 0.0]);
});

it('counts full kills once per phase and never counts a final phase wipe as a kill', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 2, 'bossPercentage' => 70],
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 6, 'bossPercentage' => 5],
        ['encounterID' => 1077, 'kill' => true, 'lastPhase' => 6, 'bossPercentage' => 0],
        ['encounterID' => 1077, 'kill' => true, 'lastPhase' => null, 'bossPercentage' => null],
    ]);

    expect(array_column($result['milestones'], 'kills'))->toBe([4, 3, 3, 3, 3, 2])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe(array_fill(0, 6, 100.0))
        ->and($result['suggested_furthest_progress_key'])->toBe('phase-6');
});

it('suggests a reached phase even with full health or missing phase health', function (mixed $health) {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 2, 'bossPercentage' => $health, 'fightPercentage' => 87.85],
    ]);

    expect($result['milestones'][1]['kills'])->toBe(0)
        ->and($result['milestones'][1]['best_progress_percent'])->toBe(0.0)
        ->and($result['suggested_furthest_progress_key'])->toBe('phase-2');
})->with([100, null, 'unknown']);

it('does not invent phase progress when the ending phase is unknown', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => null, 'bossPercentage' => 5, 'fightPercentage' => 30],
    ]);

    expect(array_column($result['milestones'], 'kills'))->toBe(array_fill(0, 6, 0))
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe(array_fill(0, 6, 0.0))
        ->and($result['suggested_furthest_progress_key'])->toBeNull();
});

it('keeps encounter progress isolated and uses overall fight progress for encounter milestones', function () {
    $milestones = collect([2001, 2002, 2003])->map(fn (int $id) => [
        'key' => "boss-{$id}", 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => $id],
    ])->all();
    $result = ($this->previewReport)([
        ['encounterID' => 2001, 'kill' => true, 'bossPercentage' => 0],
        ['encounterID' => 2001, 'kill' => true, 'bossPercentage' => 0],
        ['encounterID' => 2002, 'kill' => false, 'bossPercentage' => 5.63, 'fightPercentage' => 87.85],
        ['encounterID' => 2002, 'kill' => false, 'bossPercentage' => 2, 'fightPercentage' => 95],
        ['encounterID' => 9999, 'kill' => true, 'bossPercentage' => 0],
    ], $milestones);

    expect(array_column($result['milestones'], 'kills'))->toBe([2, 0, 0])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe([100.0, 12.15, 0.0])
        ->and($result['suggested_furthest_progress_key'])->toBe('boss-2002');
});

it('falls back to boss health for encounter milestones without overall fight progress', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 2001, 'kill' => false, 'bossPercentage' => 43.21, 'fightPercentage' => null],
    ], [['key' => 'boss', 'fflogs_matcher' => ['type' => 'encounter', 'encounter_id' => 2001]]]);

    expect($result['milestones'][0]['best_progress_percent'])->toBe(56.79);
});

it('ignores unconfigured matchers and trash fights', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 0, 'kill' => true, 'bossPercentage' => 0],
        ['encounterID' => 1077, 'kill' => true, 'lastPhase' => 6, 'bossPercentage' => 0],
    ], [
        ['key' => 'manual'],
        ['key' => 'missing-phase', 'fflogs_matcher' => ['type' => 'phase', 'encounter_id' => 1077]],
        ['key' => 'invalid-phase', 'fflogs_matcher' => ['type' => 'phase', 'encounter_id' => 1077, 'phase_id' => 0]],
    ]);

    expect(array_column($result['milestones'], 'kills'))->toBe([0, 0, 0])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe([0.0, 0.0, 0.0])
        ->and($result['suggested_furthest_progress_key'])->toBeNull();
});

it('counts phases completed before an intermission without treating its health as normal phase damage', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 1, 'lastPhaseIsIntermission' => true,
            'lastPhaseAsAbsoluteIndex' => 2, 'bossPercentage' => 40.0],
    ], phases: [['encounterID' => 1077, 'phases' => [
        ['id' => 1, 'isIntermission' => false],
        ['id' => 2, 'isIntermission' => false],
        ['id' => 3, 'isIntermission' => true],
        ['id' => 4, 'isIntermission' => false],
    ]]]);

    expect(array_column($result['milestones'], 'kills'))->toBe([1, 1, 0, 0, 0, 0])
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe([100.0, 100.0, 0.0, 0.0, 0.0, 0.0])
        ->and($result['suggested_furthest_progress_key'])->toBe('phase-2');
});

it('does not guess normal phase progress from an intermission with missing metadata', function () {
    $result = ($this->previewReport)([
        ['encounterID' => 1077, 'kill' => false, 'lastPhase' => 2, 'lastPhaseIsIntermission' => true,
            'lastPhaseAsAbsoluteIndex' => 4, 'bossPercentage' => 5.63],
    ]);

    expect(array_column($result['milestones'], 'kills'))->toBe(array_fill(0, 6, 0))
        ->and(array_column($result['milestones'], 'best_progress_percent'))->toBe(array_fill(0, 6, 0.0))
        ->and($result['suggested_furthest_progress_key'])->toBeNull();
});
