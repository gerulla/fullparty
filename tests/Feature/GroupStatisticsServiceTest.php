<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Services\Groups\GroupStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('rejects nonpositive batch sizes', function (string $modifier, int $size) {
    expect(fn () => app(GroupStatisticsService::class)->{$modifier}($size))
        ->toThrow(InvalidArgumentException::class);
})->with(['batchSize', 'applicationBatchSize'])->with([0, -1]);

it('preserves deduplication, dates, loadouts and applications across batch boundaries', function () {
    CarbonImmutable::setTestNow('2026-05-26 12:00:00');
    $group = Group::factory()->create();
    $character = Character::factory()->create();
    $otherCharacter = Character::factory()->create();
    $runs = collect(['2026-05-24 12:00:00', '2026-05-25 12:00:00', '2026-03-01 12:00:00'])
        ->map(fn ($date) => Activity::factory()->create(['group_id' => $group->id, 'starts_at' => $date]));
    $slots = $runs->map(fn ($run) => ActivitySlot::factory()->create([
        'activity_id' => $run->id, 'slot_key' => 'statistics-slot',
    ]));
    $snapshot = fn (string $label) => ['character_class' => [
        'id' => in_array($label, ['Old selection', 'Newest history'], true) ? 900003 : 900001,
        'name' => $label, 'role' => 'healer',
    ]];

    $assign = function (int $runIndex, string $date, string $label) use ($group, $runs, $slots, $character, $snapshot): void {
        ActivitySlotAssignment::query()->create([
            'group_id' => $group->id, 'activity_id' => $runs[$runIndex]->id,
            'activity_slot_id' => $slots[$runIndex]->id, 'character_id' => $character->id,
            'field_values_snapshot' => $snapshot($label), 'assigned_at' => $date,
            'ended_at' => $date, 'attendance_status' => ActivitySlotAssignment::STATUS_MISSING,
        ]);
    };
    // Insert history in a different order from the run IDs.
    $assign(1, '2026-05-25 12:00:00', 'Second run');
    $assign(0, '2026-05-24 12:00:00', 'Old selection');
    $assign(0, '2026-05-24 13:00:00', 'Newest history');
    $assign(2, '2026-03-01 12:00:00', 'Historical fallback');

    // Current slot must win even when its timestamp is older than history.
    $slots[0]->forceFill(['assigned_character_id' => $character->id, 'updated_at' => '2026-05-24 11:00:00'])->save();
    $slots[0]->fieldValues()->create([
        'field_key' => 'character_class', 'field_label' => ['en' => 'Class'], 'field_type' => 'select', 'value' => $snapshot('Current selection')['character_class'],
    ]);
    $slots[1]->update(['assigned_character_id' => $otherCharacter->id]);
    $slots[1]->fieldValues()->create([
        'field_key' => 'character_class', 'field_label' => ['en' => 'Class'], 'field_type' => 'select',
        'value' => ['id' => 900002, 'name' => 'Another class', 'role' => 'tank'],
    ]);

    $bench = ActivitySlot::factory()->assignedTo($otherCharacter)->create([
        'activity_id' => $runs[0]->id, 'group_key' => 'bench', 'slot_kind' => 'bench', 'slot_key' => 'statistics-bench',
    ]);
    ActivitySlotAssignment::query()->create([
        'group_id' => $group->id, 'activity_id' => $runs[0]->id, 'activity_slot_id' => $bench->id,
        'character_id' => $otherCharacter->id, 'assigned_at' => '2026-05-24 12:00:00',
        'field_values_snapshot' => $snapshot('Bench'),
    ]);
    $nullDateRun = Activity::factory()->create(['group_id' => $group->id]);
    $nullDateRun->update(['starts_at' => null]);
    Activity::factory()->create(['group_id' => $group->id, 'starts_at' => '2026-05-27 12:00:00']);
    Activity::factory()->create(); // Another group never contributes.

    foreach (['pending', 'on_bench', 'declined', 'withdrawn', 'cancelled'] as $index => $status) {
        ActivityApplication::factory()->create([
            'activity_id' => $runs[0]->id, 'status' => $status,
            'submitted_at' => $index === 0 ? null : '2026-05-25 11:00:00',
            'created_at' => '2026-05-24 11:00:00',
        ]);
    }

    $service = app(GroupStatisticsService::class);
    $expected = $service->batchSize(100)->applicationBatchSize(100)->calculate($group);
    foreach ([1, 2, 25] as $size) {
        expect($service->batchSize($size)->applicationBatchSize($size)->calculate($group))->toBe($expected);
    }
    expect($expected['summary'])->toBe([
        'total_runs' => 5, 'runs_with_participants' => 3, 'total_participants' => 4,
        'unique_participants' => 2, 'average_participants_per_raid' => 0.8, 'active_players_past_month' => 2,
    ]);
    expect($expected['participation_trend'])->toBe([
        ['date' => '2026-05-24', 'run_count' => 1, 'participant_count' => 1],
        ['date' => '2026-05-25', 'run_count' => 1, 'participant_count' => 2],
    ]);
    expect($expected['classes']['distribution'][0])->toMatchArray([
        'label' => 'Historical fallback', 'count' => 3, 'percent' => 75.0,
    ]);
    expect($expected['applications']['total'])->toBe(5)
        ->and(array_column($expected['applications']['distribution'], 'count'))->toBe([1, 1, 1, 1, 1])
        ->and($expected['applications']['daily_submissions'][4]['count'])->toBe(1)
        ->and($expected['applications']['daily_submissions'][5]['count'])->toBe(4)
        ->and($expected['applications']['volume_by_month'][11]['total'])->toBe(5);
});

it('keeps configured batch sizes local to each service instance', function () {
    $group = Group::factory()->create();
    Activity::factory()->count(3)->create(['group_id' => $group->id]);
    $service = app(GroupStatisticsService::class);
    $smallBatches = $service->batchSize(1)->applicationBatchSize(2);

    DB::enableQueryLog();
    try {
        $smallBatches->calculate($group);
        $configuredQueries = collect(DB::getQueryLog())->pluck('query');
        DB::flushQueryLog();
        $service->calculate($group);
        $defaultQueries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($configuredQueries->contains(fn ($sql) => str_contains($sql, 'from "activities"') && str_ends_with($sql, 'limit 1')))->toBeTrue()
        ->and($configuredQueries->contains(fn ($sql) => str_contains($sql, 'from "activity_applications"') && str_ends_with($sql, 'limit 2')))->toBeTrue()
        ->and($defaultQueries->contains(fn ($sql) => str_contains($sql, 'from "activities"') && str_ends_with($sql, 'limit 25')))->toBeTrue()
        ->and($defaultQueries->contains(fn ($sql) => str_contains($sql, 'from "activity_applications"') && str_ends_with($sql, 'limit 500')))->toBeTrue();
});
